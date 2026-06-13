<?php
/**
 * TRIVIAX — Motor PHP de la modalidad "TRIVIAX Lotto" (lotto_oral).
 *
 * Lógica de negocio: guardado, máquina de estados, login de estudiantes,
 * temporizadores, sorteo, evaluación y reporte. Todas las operaciones
 * críticas usan transacciones y FOR UPDATE. El cliente nunca decide
 * identidad, sorteo ni evaluación.
 *
 * Reglas: docs/LOTTO.md. Estados de actividad:
 *   draft → published → login_open → study → response → oral → finished → archived
 *   (cualquier estado activo → cancelled)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lotto_validator.php';

const LOTTO_ACTIVE_STATES = ['login_open', 'study', 'response', 'oral'];

/** Conflicto de negocio con código propio (p. ej. STUDENT_ALREADY_LOGGED). */
class LottoConflictException extends RuntimeException {
    public string $codeName;
    public function __construct(string $message, string $codeName = 'CONFLICT') {
        parent::__construct($message);
        $this->codeName = $codeName;
    }
}
const LOTTO_VISIBLE_DRAWS = 5;
/** Segundos sin heartbeat tras los cuales un estudiante se muestra como desconectado. */
const LOTTO_DISCONNECT_SECONDS = 30;

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

function triviax_lotto_generate_code(PDO $pdo): string {
    // Mismo formato que sesiones: 6 caracteres alfanuméricos en mayúsculas.
    for ($i = 0; $i < 25; $i++) {
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $pdo->prepare('SELECT id FROM lotto_activities WHERE codigo = ?');
        $stmt->execute([$codigo]);
        if (!$stmt->fetch()) {
            return $codigo;
        }
    }
    throw new RuntimeException('No se pudo generar un código único de actividad.');
}

function triviax_lotto_log_event(PDO $pdo, int $activityId, string $eventType, ?int $studentId = null, ?int $actorUserId = null, array $details = []): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO lotto_events (activity_id, student_id, event_type, actor_user_id, ip_address, user_agent, details_json) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $activityId,
            $studentId,
            $eventType,
            $actorUserId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        // El log de eventos nunca interrumpe la operación principal.
    }
}

function triviax_lotto_load_activity(PDO $pdo, int $activityId, bool $lock = false): ?array {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT * FROM lotto_activities WHERE id = ?{$suffix}");
    $stmt->execute([$activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function triviax_lotto_load_activity_by_code(PDO $pdo, string $codigo): ?array {
    $stmt = $pdo->prepare('SELECT * FROM lotto_activities WHERE codigo = ?');
    $stmt->execute([strtoupper(trim($codigo))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Transiciones permitidas de la máquina de estados de la actividad. */
function triviax_lotto_can_transition(string $from, string $to): bool {
    $map = [
        'draft'      => ['published'],
        'published'  => ['login_open', 'archived'],
        'login_open' => ['study', 'cancelled'],
        'study'      => ['response', 'cancelled'],
        'response'   => ['oral', 'cancelled'],
        'oral'       => ['finished', 'cancelled'],
        'finished'   => ['archived'],
        'archived'   => [],
        'cancelled'  => ['archived'],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

/**
 * Calcula la fecha límite de la fase activa (estudio o respuesta) sumando
 * la duración base + extensiones registradas en lotto_timer_events.
 * Devuelve null si la fase actual no tiene temporizador.
 */
function triviax_lotto_phase_deadline(PDO $pdo, array $activity): ?string {
    $status = $activity['status'];
    if ($status === 'study' && $activity['study_started_at']) {
        $base = (int)$activity['study_minutes'] * 60;
        $startedAt = $activity['study_started_at'];
        $extType = 'study_extended';
    } elseif ($status === 'response' && $activity['response_started_at']) {
        $base = (int)$activity['response_minutes'] * 60;
        $startedAt = $activity['response_started_at'];
        $extType = 'response_extended';
    } else {
        return null;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(extra_seconds),0) FROM lotto_timer_events WHERE activity_id = ? AND event_type = ?');
    $stmt->execute([(int)$activity['id'], $extType]);
    $extra = (int)$stmt->fetchColumn();
    return date('Y-m-d H:i:s', strtotime($startedAt) + $base + $extra);
}

// ─────────────────────────────────────────────
// Guardado / publicación
// ─────────────────────────────────────────────

/**
 * Guarda una actividad Lotto (nueva o borrador existente) a partir del payload validado.
 * Solo se puede sobrescribir una actividad en estado draft.
 * @return array ['activity_id'=>int, 'codigo'=>string, 'students'=>int, 'sections'=>int]
 */
function triviax_lotto_save_activity(PDO $pdo, int $docenteId, array $payload, ?int $activityId = null): array {
    $validation = triviax_lotto_validate_payload($payload);
    if (!$validation['ok']) {
        throw new InvalidArgumentException('El contenido tiene errores: ' . implode(' | ', $validation['errors']));
    }

    $lotto = $payload['lotto'];
    $meta = $payload['metadata'];
    $settings = $lotto['settings'];

    $pdo->beginTransaction();
    try {
        if ($activityId !== null) {
            $activity = triviax_lotto_load_activity($pdo, $activityId, true);
            if (!$activity || (int)$activity['docente_id'] !== $docenteId) {
                throw new InvalidArgumentException('La actividad no existe o no te pertenece.');
            }
            if ($activity['status'] !== 'draft') {
                throw new InvalidArgumentException('Solo se puede editar una actividad en borrador.');
            }
            $codigo = $activity['codigo'];
            // Reemplazo total del contenido del borrador.
            $pdo->prepare('DELETE FROM lotto_assignments WHERE activity_id = ?')->execute([$activityId]);
            $pdo->prepare('DELETE FROM lotto_students WHERE activity_id = ?')->execute([$activityId]);
            $pdo->prepare('DELETE FROM lotto_sections WHERE activity_id = ?')->execute([$activityId]);
        } else {
            $codigo = triviax_lotto_generate_code($pdo);
        }

        $sourceText = isset($lotto['sourceText']) && is_string($lotto['sourceText'])
            ? mb_substr($lotto['sourceText'], 0, triviax_lotto_limits()['sourceTextMax'])
            : null;

        $fields = [
            'titulo'                => mb_substr(trim((string)$lotto['title']), 0, 200),
            'nivel'                 => mb_substr(trim((string)($meta['nivel'] ?? '')), 0, 80) ?: null,
            'grupo'                 => mb_substr(trim((string)($meta['grupo'] ?? '')), 0, 120) ?: null,
            'descripcion'           => trim((string)($lotto['description'] ?? '')) ?: null,
            'source_text'           => $sourceText,
            'source_hash'           => $sourceText !== null ? hash('sha256', $sourceText) : null,
            'sections_count'        => (int)$settings['sectionsCount'],
            'questions_per_student' => (int)($settings['questionsPerStudent'] ?? 3),
            'study_minutes'         => (int)$settings['studyMinutes'],
            'response_minutes'      => (int)$settings['responseMinutes'],
            'allow_time_extension'  => !empty($settings['allowTimeExtension']) ? 1 : 0,
            'draw_mode'             => in_array($settings['drawMode'] ?? '', triviax_lotto_draw_modes(), true) ? $settings['drawMode'] : 'random_no_repeat',
            'settings_json'         => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'rubric_json'           => json_encode($lotto['rubric'], JSON_UNESCAPED_UNICODE),
            'generated_json'        => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];

        if ($activityId !== null) {
            $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE lotto_activities SET {$set} WHERE id = ?");
            $stmt->execute([...array_values($fields), $activityId]);
        } else {
            $fields = array_merge(['docente_id' => $docenteId, 'codigo' => $codigo, 'status' => 'draft'], $fields);
            $cols = implode(', ', array_keys($fields));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO lotto_activities ({$cols}) VALUES ({$marks})");
            $stmt->execute(array_values($fields));
            $activityId = (int)$pdo->lastInsertId();
        }

        // Secciones
        $sectionIdByKey = [];
        $stmtSec = $pdo->prepare('INSERT INTO lotto_sections (activity_id, section_key, orden, titulo, summary_text, key_ideas_json, vocabulary_json, difficulty) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($lotto['sections'] as $i => $sec) {
            $stmtSec->execute([
                $activityId,
                mb_substr(trim((string)$sec['id']), 0, 40),
                (int)($sec['order'] ?? ($i + 1)),
                mb_substr(trim((string)$sec['title']), 0, 200),
                trim((string)$sec['summaryText']),
                json_encode($sec['keyIdeas'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($sec['vocabulary'] ?? [], JSON_UNESCAPED_UNICODE),
                in_array($sec['difficulty'] ?? '', triviax_lotto_difficulties(), true) ? $sec['difficulty'] : 'media',
            ]);
            $sectionIdByKey[trim((string)$sec['id'])] = (int)$pdo->lastInsertId();
        }

        // Estudiantes
        $studentIdByNumber = [];
        $stmtSt = $pdo->prepare('INSERT INTO lotto_students (activity_id, student_number, first_name, full_name, alias) VALUES (?,?,?,?,?)');
        foreach ($lotto['students'] as $st) {
            $stmtSt->execute([
                $activityId,
                (int)$st['number'],
                mb_substr(trim((string)$st['firstName']), 0, 80),
                mb_substr(trim((string)($st['fullName'] ?? '')), 0, 160) ?: null,
                mb_substr(trim((string)($st['alias'] ?? '')), 0, 80) ?: null,
            ]);
            $studentIdByNumber[(int)$st['number']] = (int)$pdo->lastInsertId();
        }

        // Asignaciones
        $stmtAs = $pdo->prepare('INSERT INTO lotto_assignments (activity_id, student_id, section_id, study_text, key_ideas_json, student_questions_json, teacher_expected_answers_json, oral_main_question, oral_followups_json, difficulty) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($lotto['assignments'] as $as) {
            $stmtAs->execute([
                $activityId,
                $studentIdByNumber[(int)$as['studentNumber']],
                $sectionIdByKey[trim((string)$as['sectionId'])],
                trim((string)$as['studyText']),
                json_encode($as['keyIdeas'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($as['studentQuestions'], JSON_UNESCAPED_UNICODE),
                json_encode($as['teacherExpectedAnswers'], JSON_UNESCAPED_UNICODE),
                trim((string)$as['oralMainQuestion']),
                json_encode($as['oralFollowups'] ?? [], JSON_UNESCAPED_UNICODE),
                in_array($as['difficulty'] ?? '', triviax_lotto_difficulties(), true) ? $as['difficulty'] : 'media',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    triviax_lotto_log_event($pdo, $activityId, 'activity_saved', null, $docenteId, ['students' => count($lotto['students'])]);
    return [
        'activity_id' => $activityId,
        'codigo'      => $codigo,
        'students'    => count($lotto['students']),
        'sections'    => count($lotto['sections']),
    ];
}

/**
 * Cambia el estado de la actividad respetando la máquina de estados.
 * Registra los timestamps y eventos de temporizador correspondientes.
 */
function triviax_lotto_set_status(PDO $pdo, int $activityId, int $docenteId, string $newStatus): array {
    $pdo->beginTransaction();
    try {
        $activity = triviax_lotto_load_activity($pdo, $activityId, true);
        if (!$activity) {
            throw new InvalidArgumentException('La actividad no existe.');
        }
        if (!triviax_lotto_can_transition($activity['status'], $newStatus)) {
            throw new InvalidArgumentException("No se puede pasar de \"{$activity['status']}\" a \"{$newStatus}\".");
        }

        $sets = ['status = ?'];
        $params = [$newStatus];
        $timerEvent = null;
        $timerDuration = null;

        switch ($newStatus) {
            case 'login_open':
                $sets[] = 'started_at = NOW()';
                $timerEvent = 'login_open';
                break;
            case 'study':
                $sets[] = 'study_started_at = NOW()';
                $timerEvent = 'study_started';
                $timerDuration = (int)$activity['study_minutes'] * 60;
                break;
            case 'response':
                $sets[] = 'response_started_at = NOW()';
                $timerEvent = 'response_started';
                $timerDuration = (int)$activity['response_minutes'] * 60;
                break;
            case 'oral':
                $sets[] = 'oral_started_at = NOW()';
                $timerEvent = 'oral_started';
                break;
            case 'finished':
                $sets[] = 'finished_at = NOW()';
                $timerEvent = 'finished';
                break;
            case 'cancelled':
                $sets[] = 'finished_at = NOW()';
                $timerEvent = 'cancelled';
                break;
        }

        $params[] = $activityId;
        $pdo->prepare('UPDATE lotto_activities SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        if ($timerEvent !== null) {
            $pdo->prepare('INSERT INTO lotto_timer_events (activity_id, event_type, duration_seconds, created_by) VALUES (?,?,?,?)')
                ->execute([$activityId, $timerEvent, $timerDuration, $docenteId]);
        }

        // Al iniciar el estudio, los estudiantes logueados pasan a "studying".
        if ($newStatus === 'study') {
            $pdo->prepare("UPDATE lotto_students SET status = 'studying' WHERE activity_id = ? AND status = 'logged'")
                ->execute([$activityId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    triviax_lotto_log_event($pdo, $activityId, 'status_changed', null, $docenteId, ['to' => $newStatus]);
    return ['status' => $newStatus];
}

/** Extiende el temporizador de la fase activa (study o response). */
function triviax_lotto_extend_timer(PDO $pdo, int $activityId, int $docenteId, int $extraSeconds): array {
    if ($extraSeconds < 10 || $extraSeconds > 1800) {
        throw new InvalidArgumentException('La extensión debe estar entre 10 segundos y 30 minutos.');
    }
    $pdo->beginTransaction();
    try {
        $activity = triviax_lotto_load_activity($pdo, $activityId, true);
        if (!$activity) {
            throw new InvalidArgumentException('La actividad no existe.');
        }
        if (!(int)$activity['allow_time_extension']) {
            throw new InvalidArgumentException('Esta actividad no permite extender los plazos.');
        }
        if ($activity['status'] === 'study') {
            $eventType = 'study_extended';
        } elseif ($activity['status'] === 'response') {
            $eventType = 'response_extended';
        } else {
            throw new InvalidArgumentException('Solo se puede extender el tiempo durante el estudio o la respuesta.');
        }
        $pdo->prepare('INSERT INTO lotto_timer_events (activity_id, event_type, extra_seconds, created_by) VALUES (?,?,?,?)')
            ->execute([$activityId, $eventType, $extraSeconds, $docenteId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $activity = triviax_lotto_load_activity($pdo, $activityId);
    return ['phase_deadline' => triviax_lotto_phase_deadline($pdo, $activity)];
}

// ─────────────────────────────────────────────
// Estudiantes: login, heartbeat, respuestas
// ─────────────────────────────────────────────

/**
 * Login de estudiante por código + número. Genera token de sesión lotto.
 * Rechaza si el número ya tiene una sesión activa (suplantación).
 */
function triviax_lotto_student_login(PDO $pdo, string $codigo, int $studentNumber): array {
    $activity = triviax_lotto_load_activity_by_code($pdo, $codigo);
    if (!$activity) {
        throw new InvalidArgumentException('No existe una actividad con ese código.');
    }
    if (!in_array($activity['status'], LOTTO_ACTIVE_STATES, true)) {
        throw new InvalidArgumentException('La actividad todavía no está abierta para ingresar.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM lotto_students WHERE activity_id = ? AND student_number = ? FOR UPDATE');
        $stmt->execute([(int)$activity['id'], $studentNumber]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            $pdo->rollBack();
            throw new InvalidArgumentException('Ese número no pertenece a esta actividad.');
        }
        if (in_array($student['status'], ['logged', 'studying', 'ready', 'called'], true)) {
            // Sesión activa existente: rechazar para evitar suplantación.
            $pdo->rollBack();
            throw new LottoConflictException('Ese número ya está siendo usado en otro dispositivo. Pedile al docente que libere tu número si es un error.', 'STUDENT_ALREADY_LOGGED');
        }
        if (in_array($student['status'], ['evaluated', 'absent'], true)) {
            $pdo->rollBack();
            throw new InvalidArgumentException('Ese número ya finalizó su participación en esta actividad.');
        }

        $tokenRaw = bin2hex(random_bytes(32));
        $newStatus = in_array($activity['status'], ['study'], true) ? 'studying' : 'logged';
        $pdo->prepare("UPDATE lotto_students SET status = ?, login_token_hash = ?, logged_at = COALESCE(logged_at, NOW()), last_seen_at = NOW() WHERE id = ?")
            ->execute([$newStatus, hash('sha256', $tokenRaw), (int)$student['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    triviax_lotto_log_event($pdo, (int)$activity['id'], 'student_logged_in', (int)$student['id']);
    return [
        'activity_id'    => (int)$activity['id'],
        'student_id'     => (int)$student['id'],
        'student_number' => (int)$student['student_number'],
        'first_name'     => $student['first_name'],
        'token'          => $tokenRaw,
    ];
}

/** Valida el token de un estudiante y devuelve su fila. */
function triviax_lotto_auth_student(PDO $pdo, int $activityId, int $studentId, string $token, bool $lock = false): array {
    if ($studentId <= 0 || strlen($token) < 32) {
        throw new RuntimeException('Credenciales de estudiante inválidas.');
    }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT * FROM lotto_students WHERE id = ? AND activity_id = ? AND login_token_hash = ?{$suffix}");
    $stmt->execute([$studentId, $activityId, hash('sha256', $token)]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        throw new RuntimeException('Sesión de estudiante no autorizada.');
    }
    return $student;
}

/**
 * Ficha del estudiante SIN datos del docente (respuestas esperadas, repreguntas).
 */
function triviax_lotto_student_assignment(PDO $pdo, int $activityId, int $studentId): ?array {
    $stmt = $pdo->prepare('SELECT a.*, s.titulo AS section_title, s.summary_text AS section_summary
                           FROM lotto_assignments a
                           JOIN lotto_sections s ON s.id = a.section_id
                           WHERE a.activity_id = ? AND a.student_id = ?');
    $stmt->execute([$activityId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'section_title'     => $row['section_title'],
        'study_text'        => $row['study_text'],
        'key_ideas'         => json_decode($row['key_ideas_json'] ?? '[]', true) ?: [],
        'student_questions' => json_decode($row['student_questions_json'] ?? '[]', true) ?: [],
        'oral_main_question'=> $row['oral_main_question'],
        'difficulty'        => $row['difficulty'],
        // NUNCA incluir: teacher_expected_answers_json, oral_followups_json.
    ];
}

/** Guarda (borrador) o envía la respuesta breve del estudiante. */
function triviax_lotto_save_student_response(PDO $pdo, int $activityId, int $studentId, string $text, bool $submit, ?string $idempotencyKey = null): array {
    $activity = triviax_lotto_load_activity($pdo, $activityId);
    if (!$activity || !in_array($activity['status'], ['study', 'response', 'oral'], true)) {
        throw new InvalidArgumentException('La actividad no está en una fase que permita guardar respuestas.');
    }
    $stmt = $pdo->prepare('SELECT id FROM lotto_assignments WHERE activity_id = ? AND student_id = ?');
    $stmt->execute([$activityId, $studentId]);
    $assignmentId = (int)$stmt->fetchColumn();
    if (!$assignmentId) {
        throw new InvalidArgumentException('No tienes una asignación en esta actividad.');
    }
    $text = mb_substr(trim($text), 0, 4000);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, submitted_at FROM lotto_student_responses WHERE activity_id = ? AND student_id = ? FOR UPDATE');
        $stmt->execute([$activityId, $studentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare('UPDATE lotto_student_responses SET response_text = ?, submitted_at = ' . ($submit ? 'COALESCE(submitted_at, NOW())' : 'submitted_at') . ' WHERE id = ?')
                ->execute([$text, (int)$existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO lotto_student_responses (activity_id, student_id, assignment_id, response_text, submitted_at, idempotency_key) VALUES (?,?,?,?,' . ($submit ? 'NOW()' : 'NULL') . ',?)')
                ->execute([$activityId, $studentId, $assignmentId, $text, $idempotencyKey]);
        }
        if ($submit) {
            $pdo->prepare("UPDATE lotto_students SET status = 'ready', ready_at = COALESCE(ready_at, NOW()) WHERE id = ? AND status IN ('logged','studying')")
                ->execute([$studentId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['submitted' => $submit];
}

// ─────────────────────────────────────────────
// Sorteo
// ─────────────────────────────────────────────

/** Pool de estudiantes sorteables que aún no están en el tablero de sorteo. */
function _lotto_draw_pool(PDO $pdo, int $activityId, string $drawMode): array {
    // Participan quienes ingresaron en algún momento (no pending) y no terminaron.
    $excluded = $drawMode === 'random_simple' ? ['absent'] : ['absent', 'evaluated'];
    $marks = implode(',', array_fill(0, count($excluded), '?'));
    $stmt = $pdo->prepare("SELECT st.id FROM lotto_students st
        WHERE st.activity_id = ?
          AND st.status NOT IN ('pending', {$marks})
          AND st.id NOT IN (SELECT student_id FROM lotto_draws WHERE activity_id = ? AND draw_state IN ('current','queued'))
        ");
    $stmt->execute([$activityId, ...$excluded, $activityId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function _lotto_next_draw_order(PDO $pdo, int $activityId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(draw_order),0) + 1 FROM lotto_draws WHERE activity_id = ?');
    $stmt->execute([$activityId]);
    return (int)$stmt->fetchColumn();
}

/** Sorteo inicial: hasta 5 visibles, uno current. */
function triviax_lotto_draw_initial(PDO $pdo, int $activityId, int $docenteId): array {
    $pdo->beginTransaction();
    try {
        $activity = triviax_lotto_load_activity($pdo, $activityId, true);
        if (!$activity || $activity['status'] !== 'oral') {
            throw new InvalidArgumentException('El sorteo solo puede iniciarse durante la ronda oral.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lotto_draws WHERE activity_id = ? AND draw_state IN ('current','queued')");
        $stmt->execute([$activityId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('El sorteo ya está en curso.');
        }
        $pool = _lotto_draw_pool($pdo, $activityId, $activity['draw_mode']);
        if (!$pool) {
            throw new InvalidArgumentException('No hay estudiantes disponibles para sortear.');
        }
        shuffle($pool);
        $picked = array_slice($pool, 0, LOTTO_VISIBLE_DRAWS);
        $order = _lotto_next_draw_order($pdo, $activityId);
        $stmtIns = $pdo->prepare('INSERT INTO lotto_draws (activity_id, student_id, draw_order, draw_state, displayed_at, called_at) VALUES (?,?,?,?,NOW(),?)');
        foreach ($picked as $i => $studentId) {
            $isCurrent = $i === 0;
            $stmtIns->execute([$activityId, $studentId, $order + $i, $isCurrent ? 'current' : 'queued', $isCurrent ? date('Y-m-d H:i:s') : null]);
            if ($isCurrent) {
                $pdo->prepare("UPDATE lotto_students SET status = 'called' WHERE id = ?")->execute([$studentId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    triviax_lotto_log_event($pdo, $activityId, 'draw_initial', null, $docenteId);
    return triviax_lotto_visible_draws($pdo, $activityId);
}

/** Sorteos visibles (current + queued) con datos del estudiante. */
function triviax_lotto_visible_draws(PDO $pdo, int $activityId): array {
    $stmt = $pdo->prepare("SELECT d.id AS draw_id, d.draw_state, d.draw_order, st.id AS student_id, st.student_number, st.first_name
        FROM lotto_draws d JOIN lotto_students st ON st.id = d.student_id
        WHERE d.activity_id = ? AND d.draw_state IN ('current','queued')
        ORDER BY d.draw_order");
    $stmt->execute([$activityId]);
    return ['draws' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Resuelve el draw current con un resultado (evaluated|postponed|absent|skipped),
 * promueve aleatoriamente uno de los queued a current y repone desde el pool.
 */
function triviax_lotto_advance_draw(PDO $pdo, int $activityId, int $docenteId, string $resolution): array {
    $validResolutions = ['evaluated', 'postponed', 'absent', 'skipped'];
    if (!in_array($resolution, $validResolutions, true)) {
        throw new InvalidArgumentException('Resolución de sorteo inválida.');
    }
    $pdo->beginTransaction();
    try {
        $activity = triviax_lotto_load_activity($pdo, $activityId, true);
        if (!$activity || $activity['status'] !== 'oral') {
            throw new InvalidArgumentException('El sorteo solo funciona durante la ronda oral.');
        }
        $stmt = $pdo->prepare("SELECT * FROM lotto_draws WHERE activity_id = ? AND draw_state = 'current' FOR UPDATE");
        $stmt->execute([$activityId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new InvalidArgumentException('No hay un estudiante actual en el sorteo.');
        }

        // Resolver el actual.
        $pdo->prepare('UPDATE lotto_draws SET draw_state = ?, evaluated_at = NOW() WHERE id = ?')
            ->execute([$resolution, (int)$current['id']]);

        $studentStatus = [
            'evaluated' => 'evaluated',
            'postponed' => 'postponed',
            'absent'    => 'absent',
            'skipped'   => 'ready',
        ][$resolution];
        $pdo->prepare('UPDATE lotto_students SET status = ? WHERE id = ?')
            ->execute([$studentStatus, (int)$current['student_id']]);

        // Promover uno de los queued al azar.
        $stmt = $pdo->prepare("SELECT id, student_id FROM lotto_draws WHERE activity_id = ? AND draw_state = 'queued' ORDER BY RAND() LIMIT 1");
        $stmt->execute([$activityId]);
        $promoted = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($promoted) {
            $pdo->prepare("UPDATE lotto_draws SET draw_state = 'current', called_at = NOW() WHERE id = ?")
                ->execute([(int)$promoted['id']]);
            $pdo->prepare("UPDATE lotto_students SET status = 'called' WHERE id = ?")
                ->execute([(int)$promoted['student_id']]);
        }

        // Reponer el espacio libre con un nuevo pendiente del pool.
        $pool = _lotto_draw_pool($pdo, $activityId, $activity['draw_mode']);
        if ($pool) {
            $newStudentId = $pool[array_rand($pool)];
            $order = _lotto_next_draw_order($pdo, $activityId);
            $isCurrent = !$promoted; // si no había queued, el repuesto pasa directo a current
            $pdo->prepare('INSERT INTO lotto_draws (activity_id, student_id, draw_order, draw_state, displayed_at, called_at) VALUES (?,?,?,?,NOW(),?)')
                ->execute([$activityId, $newStudentId, $order, $isCurrent ? 'current' : 'queued', $isCurrent ? date('Y-m-d H:i:s') : null]);
            if ($isCurrent) {
                $pdo->prepare("UPDATE lotto_students SET status = 'called' WHERE id = ?")->execute([$newStudentId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    triviax_lotto_log_event($pdo, $activityId, 'draw_advanced', (int)$current['student_id'], $docenteId, ['resolution' => $resolution]);
    return triviax_lotto_visible_draws($pdo, $activityId);
}

/** Registra/actualiza la evaluación oral de un estudiante. */
function triviax_lotto_evaluate_student(PDO $pdo, int $activityId, int $docenteId, int $studentId, array $evaluation): array {
    $quick = (string)($evaluation['quick_result'] ?? 'sin_evaluar');
    if (!in_array($quick, ['excelente', 'correcto', 'incompleto', 'no_responde', 'sin_evaluar'], true)) {
        throw new InvalidArgumentException('Resultado rápido inválido.');
    }
    $numeric = isset($evaluation['numeric_score']) && is_numeric($evaluation['numeric_score']) ? (float)$evaluation['numeric_score'] : null;
    $max = isset($evaluation['max_score']) && is_numeric($evaluation['max_score']) ? (float)$evaluation['max_score'] : null;
    $comment = mb_substr(trim((string)($evaluation['teacher_comment'] ?? '')), 0, 2000) ?: null;
    $rubricScores = is_array($evaluation['rubric_scores'] ?? null) ? json_encode($evaluation['rubric_scores'], JSON_UNESCAPED_UNICODE) : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM lotto_students WHERE id = ? AND activity_id = ? FOR UPDATE');
        $stmt->execute([$studentId, $activityId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException('El estudiante no pertenece a esta actividad.');
        }
        $stmt = $pdo->prepare("SELECT id FROM lotto_draws WHERE activity_id = ? AND student_id = ? AND draw_state = 'current'");
        $stmt->execute([$activityId, $studentId]);
        $drawId = (int)$stmt->fetchColumn() ?: null;

        $stmt = $pdo->prepare('SELECT id FROM lotto_evaluations WHERE activity_id = ? AND student_id = ? FOR UPDATE');
        $stmt->execute([$activityId, $studentId]);
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId) {
            $pdo->prepare('UPDATE lotto_evaluations SET docente_id = ?, draw_id = COALESCE(?, draw_id), rubric_scores_json = ?, quick_result = ?, numeric_score = ?, max_score = ?, teacher_comment = ? WHERE id = ?')
                ->execute([$docenteId, $drawId, $rubricScores, $quick, $numeric, $max, $comment, $existingId]);
        } else {
            $pdo->prepare('INSERT INTO lotto_evaluations (activity_id, student_id, draw_id, docente_id, rubric_scores_json, quick_result, numeric_score, max_score, teacher_comment) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$activityId, $studentId, $drawId, $docenteId, $rubricScores, $quick, $numeric, $max, $comment]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    triviax_lotto_log_event($pdo, $activityId, 'student_evaluated', $studentId, $docenteId, ['quick_result' => $quick]);
    return ['evaluated' => true];
}

// ─────────────────────────────────────────────
// Estado para host y estudiante
// ─────────────────────────────────────────────

/** Estado completo para la pantalla interactiva del salón. */
function triviax_lotto_host_state(PDO $pdo, array $activity): array {
    $activityId = (int)$activity['id'];
    $stmt = $pdo->prepare('SELECT st.id, st.student_number, st.first_name, st.status, st.logged_at, st.last_seen_at,
            (SELECT quick_result FROM lotto_evaluations e WHERE e.activity_id = st.activity_id AND e.student_id = st.id) AS quick_result
        FROM lotto_students st WHERE st.activity_id = ? ORDER BY st.student_number');
    $stmt->execute([$activityId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = time();
    foreach ($students as &$st) {
        $effective = $st['status'];
        if (in_array($effective, ['logged', 'studying', 'ready'], true) && $st['last_seen_at'] !== null
            && ($now - strtotime($st['last_seen_at'])) > LOTTO_DISCONNECT_SECONDS) {
            $effective = 'disconnected';
        }
        $st['effective_status'] = $effective;
        unset($st['last_seen_at']);
    }
    unset($st);

    return [
        'activity' => [
            'id'             => $activityId,
            'codigo'         => $activity['codigo'],
            'titulo'         => $activity['titulo'],
            'grupo'          => $activity['grupo'],
            'status'         => $activity['status'],
            'study_minutes'  => (int)$activity['study_minutes'],
            'response_minutes' => (int)$activity['response_minutes'],
            'allow_time_extension' => (bool)$activity['allow_time_extension'],
            'phase_deadline' => triviax_lotto_phase_deadline($pdo, $activity),
            'server_now'     => date('Y-m-d H:i:s'),
        ],
        'students' => $students,
        'draws'    => triviax_lotto_visible_draws($pdo, $activityId)['draws'],
    ];
}

/** Estado para la pantalla del estudiante (solo datos propios). */
function triviax_lotto_student_state(PDO $pdo, array $activity, array $student): array {
    $stmt = $pdo->prepare("SELECT draw_state FROM lotto_draws WHERE activity_id = ? AND student_id = ? AND draw_state = 'current'");
    $stmt->execute([(int)$activity['id'], (int)$student['id']]);
    $isCalled = (bool)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT response_text, submitted_at FROM lotto_student_responses WHERE activity_id = ? AND student_id = ?');
    $stmt->execute([(int)$activity['id'], (int)$student['id']]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'activity' => [
            'codigo'         => $activity['codigo'],
            'titulo'         => $activity['titulo'],
            'status'         => $activity['status'],
            'phase_deadline' => triviax_lotto_phase_deadline($pdo, $activity),
            'server_now'     => date('Y-m-d H:i:s'),
        ],
        'student' => [
            'student_number' => (int)$student['student_number'],
            'first_name'     => $student['first_name'],
            'status'         => $student['status'],
            'is_called'      => $isCalled,
        ],
        'response' => $response ? [
            'text'      => (string)$response['response_text'],
            'submitted' => $response['submitted_at'] !== null,
        ] : null,
    ];
}

// ─────────────────────────────────────────────
// Reporte
// ─────────────────────────────────────────────

function triviax_lotto_report(PDO $pdo, array $activity): array {
    $activityId = (int)$activity['id'];

    $stmt = $pdo->prepare('SELECT st.id, st.student_number, st.first_name, st.full_name, st.status, st.logged_at,
            sec.titulo AS section_title, a.study_text, a.student_questions_json, a.oral_main_question,
            a.teacher_expected_answers_json, a.oral_followups_json, a.difficulty,
            r.response_text, r.submitted_at,
            d.draw_order, d.draw_state,
            e.quick_result, e.numeric_score, e.max_score, e.rubric_scores_json, e.teacher_comment
        FROM lotto_students st
        LEFT JOIN lotto_assignments a ON a.activity_id = st.activity_id AND a.student_id = st.id
        LEFT JOIN lotto_sections sec ON sec.id = a.section_id
        LEFT JOIN lotto_student_responses r ON r.activity_id = st.activity_id AND r.student_id = st.id
        LEFT JOIN lotto_draws d ON d.id = (SELECT MAX(d2.id) FROM lotto_draws d2 WHERE d2.activity_id = st.activity_id AND d2.student_id = st.id)
        LEFT JOIN lotto_evaluations e ON e.activity_id = st.activity_id AND e.student_id = st.id
        WHERE st.activity_id = ?
        ORDER BY st.student_number');
    $stmt->execute([$activityId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $students = [];
    $summary = ['total' => 0, 'logged' => 0, 'not_logged' => 0, 'evaluated' => 0, 'pending' => 0, 'absent' => 0, 'postponed' => 0, 'score_sum' => 0.0, 'score_count' => 0];
    foreach ($rows as $r) {
        $summary['total']++;
        if ($r['logged_at'] !== null) { $summary['logged']++; } else { $summary['not_logged']++; }
        if ($r['quick_result'] !== null && $r['quick_result'] !== 'sin_evaluar') { $summary['evaluated']++; }
        elseif ($r['status'] === 'absent') { $summary['absent']++; }
        elseif ($r['status'] === 'postponed') { $summary['postponed']++; }
        else { $summary['pending']++; }
        if ($r['numeric_score'] !== null) {
            $summary['score_sum'] += (float)$r['numeric_score'];
            $summary['score_count']++;
        }
        $students[] = [
            'student_number'    => (int)$r['student_number'],
            'first_name'        => $r['first_name'],
            'full_name'         => $r['full_name'],
            'status'            => $r['status'],
            'logged_at'         => $r['logged_at'],
            'section_title'     => $r['section_title'],
            'study_text'        => $r['study_text'],
            'student_questions' => json_decode($r['student_questions_json'] ?? '[]', true) ?: [],
            'oral_main_question'=> $r['oral_main_question'],
            'expected_answers'  => json_decode($r['teacher_expected_answers_json'] ?? '[]', true) ?: [],
            'oral_followups'    => json_decode($r['oral_followups_json'] ?? '[]', true) ?: [],
            'difficulty'        => $r['difficulty'],
            'written_response'  => $r['response_text'],
            'response_submitted_at' => $r['submitted_at'],
            'draw_order'        => $r['draw_order'] !== null ? (int)$r['draw_order'] : null,
            'draw_state'        => $r['draw_state'],
            'quick_result'      => $r['quick_result'],
            'numeric_score'     => $r['numeric_score'] !== null ? (float)$r['numeric_score'] : null,
            'max_score'         => $r['max_score'] !== null ? (float)$r['max_score'] : null,
            'rubric_scores'     => json_decode($r['rubric_scores_json'] ?? 'null', true),
            'teacher_comment'   => $r['teacher_comment'],
        ];
    }

    $stmt = $pdo->prepare('SELECT event_type, duration_seconds, extra_seconds, created_at FROM lotto_timer_events WHERE activity_id = ? ORDER BY created_at');
    $stmt->execute([$activityId]);
    $timerEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'activity' => [
            'id'          => $activityId,
            'codigo'      => $activity['codigo'],
            'titulo'      => $activity['titulo'],
            'nivel'       => $activity['nivel'],
            'grupo'       => $activity['grupo'],
            'status'      => $activity['status'],
            'source_hash' => $activity['source_hash'],
            'created_at'  => $activity['created_at'],
            'started_at'  => $activity['started_at'],
            'finished_at' => $activity['finished_at'],
            'rubric'      => json_decode($activity['rubric_json'] ?? 'null', true),
            'settings'    => json_decode($activity['settings_json'] ?? 'null', true),
        ],
        'students' => $students,
        'summary'  => [
            'total'      => $summary['total'],
            'logged'     => $summary['logged'],
            'not_logged' => $summary['not_logged'],
            'evaluated'  => $summary['evaluated'],
            'pending'    => $summary['pending'],
            'absent'     => $summary['absent'],
            'postponed'  => $summary['postponed'],
            'average_score' => $summary['score_count'] > 0 ? round($summary['score_sum'] / $summary['score_count'], 2) : null,
        ],
        'timer_events' => $timerEvents,
    ];
}
