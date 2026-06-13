<?php
/**
 * TRIVIAX — Endpoints de la modalidad "TRIVIAX Lotto" (lotto_*).
 *
 * Incluido desde api.php cuando $action empieza con "lotto_".
 * Usa los helpers de api.php (triviax_api_json/success/error/input/require_post)
 * y de php/auth.php (CSRF, rate limit, sesión, roles).
 *
 * Seguridad (docs/LOTTO.md):
 *  - El estudiante recibe SOLO su ficha pública (sin respuestas esperadas).
 *  - Sorteo, evaluación y estados se deciden en servidor.
 *  - Docente: sesión PHP rol docente + CSRF + propiedad de la actividad.
 *  - Estudiante: token de sesión lotto + CSRF + rate limit.
 */

require_once __DIR__ . '/lotto_engine.php';

// ─────────────────────────────────────────────
// Helpers locales
// ─────────────────────────────────────────────

function _lotto_api_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _lotto_api_require_docente(): array {
    triviax_session_start();
    $u = triviax_usuario_actual();
    if ($u === null) {
        triviax_api_error('UNAUTHORIZED', 'Necesitás iniciar sesión.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Acceso solo para docentes.', 403);
    }
    return $u;
}

/** Carga una actividad verificando propiedad (docente dueño o superadmin). */
function _lotto_api_load_own_activity(PDO $pdo, int $activityId, array $docente): array {
    $activity = triviax_lotto_load_activity($pdo, $activityId);
    if (!$activity) {
        triviax_api_error('ACTIVITY_NOT_FOUND', 'La actividad no existe.', 404);
    }
    $esDuenio = (int)$activity['docente_id'] === (int)$docente['id'];
    if (!$esDuenio && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tienes permiso sobre esta actividad.', 403);
    }
    return $activity;
}

function _lotto_api_throttle(string $scope, string $identifier, int $maxPerWindow, int $windowSeconds, int $blockSeconds = 60): void {
    if (!triviax_rate_limit_check($scope, $identifier, $maxPerWindow, $windowSeconds)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.', 429);
    }
    triviax_rate_limit_hit($scope, $identifier, $windowSeconds, $blockSeconds, $maxPerWindow);
}

function _lotto_api_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
}

/** Autentica al estudiante a partir del input JSON o query string. */
function _lotto_api_auth_student(PDO $pdo, array $input): array {
    $activityId = (int)($input['activity_id'] ?? $_GET['activity_id'] ?? 0);
    $studentId = (int)($input['student_id'] ?? $_GET['student_id'] ?? 0);
    $token = trim((string)($input['token'] ?? $_GET['token'] ?? ''));
    try {
        $activity = triviax_lotto_load_activity($pdo, $activityId);
        if (!$activity) {
            triviax_api_error('ACTIVITY_NOT_FOUND', 'La actividad no existe.', 404);
        }
        $student = triviax_lotto_auth_student($pdo, $activityId, $studentId, $token);
        return [$activity, $student];
    } catch (RuntimeException $e) {
        triviax_api_error('FORBIDDEN', $e->getMessage(), 403);
    }
}

/** Ejecuta una transición de estado docente estándar y responde. */
function _lotto_api_transition(string $newStatus, ?string $throttleScope = null): void {
    triviax_api_require_post();
    triviax_verify_csrf_json();
    $pdo = _lotto_api_require_db();
    $docente = _lotto_api_require_docente();
    $input = triviax_api_input();
    $activityId = (int)($input['activity_id'] ?? 0);
    _lotto_api_load_own_activity($pdo, $activityId, $docente);
    if ($throttleScope !== null) {
        _lotto_api_throttle($throttleScope, (string)$activityId, 20, 600);
    }
    try {
        $result = triviax_lotto_set_status($pdo, $activityId, (int)$docente['id'], $newStatus);
        triviax_api_success($result, 'Estado actualizado.');
    } catch (InvalidArgumentException $e) {
        triviax_api_error('INVALID_STATE', $e->getMessage(), 409);
    }
}

// ─────────────────────────────────────────────
// Despachador
// ─────────────────────────────────────────────

function triviax_lotto_api_handle(string $action): void {
    switch ($action) {
        // ══════════════ DOCENTE ══════════════

        case 'lotto_list_activities': {
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $stmt = $pdo->prepare('SELECT a.id, a.codigo, a.titulo, a.nivel, a.grupo, a.status, a.created_at,
                    (SELECT COUNT(*) FROM lotto_students st WHERE st.activity_id = a.id) AS students,
                    (SELECT COUNT(*) FROM lotto_evaluations e WHERE e.activity_id = a.id) AS evaluations
                FROM lotto_activities a WHERE a.docente_id = ? ORDER BY a.created_at DESC LIMIT 100');
            $stmt->execute([(int)$docente['id']]);
            triviax_api_success(['activities' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'lotto_validate_payload': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            _lotto_api_require_docente();
            $input = triviax_api_input();
            $payload = $input['payload'] ?? null;
            $result = triviax_lotto_validate_payload(is_array($payload) ? $payload : null);
            if ($result['ok']) {
                triviax_api_success(['ok' => true, 'errors' => [], 'warnings' => $result['warnings']], 'La actividad es válida.');
            } else {
                triviax_api_json([
                    'ok' => false, 'success' => false, 'code' => 'VALIDATION_ERROR',
                    'message' => 'La actividad tiene errores.',
                    'errors' => $result['errors'], 'warnings' => $result['warnings'],
                ], 422);
            }
            break;
        }

        case 'lotto_save_activity': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            _lotto_api_throttle('lotto_save', (string)$docente['id'], 30, 600);
            $input = triviax_api_input();
            $payload = $input['payload'] ?? null;
            $activityId = isset($input['activity_id']) && $input['activity_id'] !== null ? (int)$input['activity_id'] : null;
            if (!is_array($payload)) {
                triviax_api_error('VALIDATION_ERROR', 'Falta el contenido de la actividad (payload).', 422);
            }
            try {
                $saved = triviax_lotto_save_activity($pdo, (int)$docente['id'], $payload, $activityId);
                triviax_audit_log('lotto_activity_saved', 'lotto_activity', (string)$saved['activity_id'], ['students' => $saved['students']]);
                triviax_api_success($saved, 'Actividad guardada como borrador.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('VALIDATION_ERROR', $e->getMessage(), 422);
            }
            break;
        }

        case 'lotto_publish_activity':
            _lotto_api_transition('published');
            break;
        case 'lotto_open_login':
            _lotto_api_transition('login_open', 'lotto_phase');
            break;
        case 'lotto_start_study':
            _lotto_api_transition('study', 'lotto_phase');
            break;
        case 'lotto_start_response':
            _lotto_api_transition('response', 'lotto_phase');
            break;
        case 'lotto_start_oral':
            _lotto_api_transition('oral', 'lotto_phase');
            break;
        case 'lotto_finish_activity':
            _lotto_api_transition('finished', 'lotto_phase');
            break;
        case 'lotto_archive_activity':
            _lotto_api_transition('archived');
            break;
        case 'lotto_cancel_activity':
            _lotto_api_transition('cancelled');
            break;

        case 'lotto_extend_study':
        case 'lotto_extend_response': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $input = triviax_api_input();
            $activityId = (int)($input['activity_id'] ?? 0);
            _lotto_api_load_own_activity($pdo, $activityId, $docente);
            try {
                $result = triviax_lotto_extend_timer($pdo, $activityId, (int)$docente['id'], (int)($input['extra_seconds'] ?? 0));
                triviax_api_success($result, 'Tiempo extendido.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('INVALID_STATE', $e->getMessage(), 409);
            }
            break;
        }

        case 'lotto_activity_detail': {
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $activity = _lotto_api_load_own_activity($pdo, (int)($_GET['activity_id'] ?? 0), $docente);
            triviax_api_success([
                'activity' => [
                    'id'      => (int)$activity['id'],
                    'codigo'  => $activity['codigo'],
                    'titulo'  => $activity['titulo'],
                    'nivel'   => $activity['nivel'],
                    'grupo'   => $activity['grupo'],
                    'status'  => $activity['status'],
                    'payload' => json_decode($activity['generated_json'] ?? 'null', true),
                ],
            ]);
            break;
        }

        case 'lotto_host_state': {
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $activityId = (int)($_GET['activity_id'] ?? 0);
            $activity = _lotto_api_load_own_activity($pdo, $activityId, $docente);
            _lotto_api_throttle('lotto_host_state', (string)$activityId, 60, 60);
            triviax_api_success(triviax_lotto_host_state($pdo, $activity));
            break;
        }

        case 'lotto_draw_initial': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $input = triviax_api_input();
            $activityId = (int)($input['activity_id'] ?? 0);
            _lotto_api_load_own_activity($pdo, $activityId, $docente);
            _lotto_api_throttle('lotto_draw', (string)$activityId, 30, 600);
            try {
                triviax_api_success(triviax_lotto_draw_initial($pdo, $activityId, (int)$docente['id']), 'Sorteo iniciado.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('INVALID_STATE', $e->getMessage(), 409);
            }
            break;
        }

        case 'lotto_draw_next':
        case 'lotto_postpone_student':
        case 'lotto_mark_absent': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $input = triviax_api_input();
            $activityId = (int)($input['activity_id'] ?? 0);
            _lotto_api_load_own_activity($pdo, $activityId, $docente);
            _lotto_api_throttle('lotto_draw', (string)$activityId, 30, 600);
            $resolution = [
                'lotto_draw_next'        => 'skipped',
                'lotto_postpone_student' => 'postponed',
                'lotto_mark_absent'      => 'absent',
            ][$action];
            try {
                triviax_api_success(triviax_lotto_advance_draw($pdo, $activityId, (int)$docente['id'], $resolution), 'Sorteo avanzado.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('INVALID_STATE', $e->getMessage(), 409);
            }
            break;
        }

        case 'lotto_evaluate_student': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $input = triviax_api_input();
            $activityId = (int)($input['activity_id'] ?? 0);
            $studentId = (int)($input['student_id'] ?? 0);
            $activity = _lotto_api_load_own_activity($pdo, $activityId, $docente);
            _lotto_api_throttle('lotto_evaluate', (string)$activityId, 60, 1800);
            try {
                triviax_lotto_evaluate_student($pdo, $activityId, (int)$docente['id'], $studentId, is_array($input['evaluation'] ?? null) ? $input['evaluation'] : []);
                $draws = null;
                // Si el evaluado es el actual del sorteo, avanzar automáticamente.
                if (!empty($input['advance_draw']) && $activity['status'] === 'oral') {
                    $stmt = $pdo->prepare("SELECT id FROM lotto_draws WHERE activity_id = ? AND student_id = ? AND draw_state = 'current'");
                    $stmt->execute([$activityId, $studentId]);
                    if ($stmt->fetchColumn()) {
                        $draws = triviax_lotto_advance_draw($pdo, $activityId, (int)$docente['id'], 'evaluated');
                    }
                }
                triviax_audit_log('lotto_student_evaluated', 'lotto_student', (string)$studentId);
                triviax_api_success(['evaluated' => true, 'draws' => $draws['draws'] ?? null], 'Evaluación registrada.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('VALIDATION_ERROR', $e->getMessage(), 422);
            }
            break;
        }

        case 'lotto_release_student_login': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $input = triviax_api_input();
            $activityId = (int)($input['activity_id'] ?? 0);
            $studentId = (int)($input['student_id'] ?? 0);
            _lotto_api_load_own_activity($pdo, $activityId, $docente);
            $stmt = $pdo->prepare("UPDATE lotto_students SET status = 'pending', login_token_hash = NULL, logged_at = NULL, last_seen_at = NULL WHERE id = ? AND activity_id = ? AND status NOT IN ('evaluated')");
            $stmt->execute([$studentId, $activityId]);
            if (!$stmt->rowCount()) {
                triviax_api_error('INVALID_STATE', 'No se pudo liberar ese número (no existe o ya fue evaluado).', 409);
            }
            triviax_lotto_log_event($pdo, $activityId, 'student_login_released', $studentId, (int)$docente['id']);
            triviax_audit_log('lotto_login_released', 'lotto_student', (string)$studentId);
            triviax_api_success([], 'Número liberado. El estudiante puede volver a ingresar.');
            break;
        }

        case 'lotto_report': {
            $pdo = _lotto_api_require_db();
            $docente = _lotto_api_require_docente();
            $activity = _lotto_api_load_own_activity($pdo, (int)($_GET['activity_id'] ?? 0), $docente);
            triviax_api_success(triviax_lotto_report($pdo, $activity));
            break;
        }

        // ══════════════ ESTUDIANTE ══════════════

        case 'lotto_public_info': {
            $pdo = _lotto_api_require_db();
            _lotto_api_throttle('lotto_public_info', _lotto_api_ip(), 60, 60);
            $activity = triviax_lotto_load_activity_by_code($pdo, (string)($_GET['code'] ?? ''));
            if (!$activity || !in_array($activity['status'], LOTTO_ACTIVE_STATES, true)) {
                triviax_api_error('ACTIVITY_NOT_FOUND', 'No hay una actividad abierta con ese código.', 404);
            }
            triviax_api_success(['activity' => [
                'codigo' => $activity['codigo'],
                'titulo' => $activity['titulo'],
                'grupo'  => $activity['grupo'],
                'status' => $activity['status'],
            ]]);
            break;
        }

        case 'lotto_student_login': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            _lotto_api_throttle('lotto_student_login', _lotto_api_ip(), 10, 300, 300);
            $input = triviax_api_input();
            try {
                $result = triviax_lotto_student_login($pdo, (string)($input['code'] ?? ''), (int)($input['student_number'] ?? 0));
                triviax_api_success($result, 'Ingreso correcto.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('VALIDATION_ERROR', $e->getMessage(), 422);
            } catch (LottoConflictException $e) {
                triviax_api_error($e->codeName, $e->getMessage(), 409);
            } catch (RuntimeException $e) {
                triviax_api_error('FORBIDDEN', $e->getMessage(), 409);
            }
            break;
        }

        case 'lotto_student_state': {
            $pdo = _lotto_api_require_db();
            [$activity, $student] = _lotto_api_auth_student($pdo, []);
            _lotto_api_throttle('lotto_student_state', (string)$student['id'], 60, 60);
            // El polling de estado funciona también como heartbeat.
            $pdo->prepare('UPDATE lotto_students SET last_seen_at = NOW() WHERE id = ?')->execute([(int)$student['id']]);
            triviax_api_success(triviax_lotto_student_state($pdo, $activity, $student));
            break;
        }

        case 'lotto_student_assignment': {
            $pdo = _lotto_api_require_db();
            [$activity, $student] = _lotto_api_auth_student($pdo, []);
            $assignment = triviax_lotto_student_assignment($pdo, (int)$activity['id'], (int)$student['id']);
            if (!$assignment) {
                triviax_api_error('NOT_FOUND', 'No tienes una ficha asignada en esta actividad.', 404);
            }
            triviax_api_success(['assignment' => $assignment]);
            break;
        }

        case 'lotto_student_heartbeat': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $input = triviax_api_input();
            [, $student] = _lotto_api_auth_student($pdo, $input);
            $pdo->prepare('UPDATE lotto_students SET last_seen_at = NOW() WHERE id = ?')->execute([(int)$student['id']]);
            triviax_api_success([]);
            break;
        }

        case 'lotto_student_ready': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $input = triviax_api_input();
            [$activity, $student] = _lotto_api_auth_student($pdo, $input);
            $pdo->prepare("UPDATE lotto_students SET status = 'ready', ready_at = COALESCE(ready_at, NOW()) WHERE id = ? AND status IN ('logged','studying')")
                ->execute([(int)$student['id']]);
            triviax_lotto_log_event($pdo, (int)$activity['id'], 'student_ready', (int)$student['id']);
            triviax_api_success([], 'Quedaste marcado como preparado.');
            break;
        }

        case 'lotto_student_save_response':
        case 'lotto_student_submit_response': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _lotto_api_require_db();
            $input = triviax_api_input();
            [$activity, $student] = _lotto_api_auth_student($pdo, $input);
            _lotto_api_throttle('lotto_student_response', (string)$student['id'], 20, 60);
            $submit = $action === 'lotto_student_submit_response';
            try {
                $result = triviax_lotto_save_student_response(
                    $pdo, (int)$activity['id'], (int)$student['id'],
                    (string)($input['text'] ?? ''), $submit,
                    isset($input['idempotency_key']) ? substr((string)$input['idempotency_key'], 0, 64) : null
                );
                triviax_api_success($result, $submit ? 'Respuesta enviada.' : 'Borrador guardado.');
            } catch (InvalidArgumentException $e) {
                triviax_api_error('INVALID_STATE', $e->getMessage(), 409);
            }
            break;
        }

        default:
            triviax_api_error('UNKNOWN_ACTION', 'Acción lotto desconocida.', 400);
    }
    exit;
}
