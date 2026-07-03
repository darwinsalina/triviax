<?php
/**
 * TRIVIAX — Endpoints de la modalidad "Crucigrama" (cw_*).
 *
 * Incluido desde api.php cuando $action empieza con "cw_".
 * Usa los helpers de api.php (triviax_api_*) y de php/auth.php (CSRF,
 * rate limit, sesión, roles), igual que jigsaw_api.php.
 *
 * El armado del crucigrama (intersecciones, numeración, recorte) lo
 * hace el algoritmo local en el cliente (js/crossword.js); este backend
 * valida el resultado y lo persiste como JSON en crossword_projects
 * (migración 6.3).
 *
 * Seguridad:
 *  - Docente: sesión rol docente/superadmin + CSRF + propiedad del proyecto.
 *  - Juego: abierto (cualquiera con el enlace) sobre actividades publicadas.
 */

require_once __DIR__ . '/activity_access.php'; // v7.0: políticas de acceso

const CW_MAX_ROWS = 45;
const CW_MAX_COLS = 45;
const CW_MAX_WORDS_HARD = 60;

function _cw_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _cw_require_docente(): array {
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

function _cw_load(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM crossword_projects WHERE id = ?');
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
}

/** Carga un proyecto verificando propiedad (dueño o superadmin). */
function _cw_load_own(PDO $pdo, int $id, array $docente): array {
    $p = _cw_load($pdo, $id);
    if (!$p) {
        triviax_api_error('NOT_FOUND', 'La actividad no existe.', 404);
    }
    if ((int)$p['docente_id'] !== (int)$docente['id'] && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tenés permiso sobre esta actividad.', 403);
    }
    return $p;
}

function _cw_throttle(string $scope, string $id, int $max, int $win, int $block = 60): void {
    if (!triviax_rate_limit_check($scope, $id, $max, $win)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Esperá un momento.', 429);
    }
    triviax_rate_limit_hit($scope, $id, $win, $block, $max);
}

function _cw_normalize_word(string $raw): string {
    $map = ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U'];
    $w = mb_strtoupper(trim($raw), 'UTF-8');
    $w = strtr($w, $map);
    $w = preg_replace('/[^A-ZÑ]/u', '', $w);
    return $w;
}

/**
 * Valida y normaliza el payload de un crucigrama ya armado por el
 * cliente. Devuelve el puzzle limpio o null si es inválido.
 * (Portado de experimental/crucigramas/api.php.)
 */
function _cw_sanitize_puzzle(string $raw): ?array {
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    $rows = (int)($data['rows'] ?? 0);
    $cols = (int)($data['cols'] ?? 0);
    if ($rows < 2 || $rows > CW_MAX_ROWS || $cols < 2 || $cols > CW_MAX_COLS) return null;

    $grid = $data['grid'] ?? null;
    if (!is_array($grid) || count($grid) !== $rows) return null;
    $cleanGrid = [];
    foreach ($grid as $rowArr) {
        if (!is_array($rowArr) || count($rowArr) !== $cols) return null;
        $cleanRow = [];
        foreach ($rowArr as $ch) {
            if ($ch === null || $ch === '') { $cleanRow[] = null; continue; }
            $ch = mb_substr((string)$ch, 0, 1, 'UTF-8');
            $up = mb_strtoupper($ch, 'UTF-8');
            if (!preg_match('/^[A-ZÑ]$/u', $up)) return null;
            $cleanRow[] = $up;
        }
        $cleanGrid[] = $cleanRow;
    }

    $placedRaw = $data['placed'] ?? [];
    if (!is_array($placedRaw) || count($placedRaw) === 0) return null;
    $placed = [];
    foreach ($placedRaw as $p) {
        if (!is_array($p)) continue;
        $word = trim((string)($p['word'] ?? ''));
        $norm = _cw_normalize_word((string)($p['normalized'] ?? $word));
        if ($word === '' || $norm === '') continue;
        $row = (int)($p['row'] ?? -1);
        $col = (int)($p['col'] ?? -1);
        $dir = (string)($p['dir'] ?? '');
        $len = (int)($p['length'] ?? mb_strlen($norm, 'UTF-8'));
        $number = (int)($p['number'] ?? 0);
        if ($row < 0 || $row >= $rows || $col < 0 || $col >= $cols) continue;
        if (!in_array($dir, ['across', 'down'], true)) continue;
        if ($number < 1) continue;
        $clue = mb_substr(trim((string)($p['clue'] ?? '')), 0, 300, 'UTF-8');
        $placed[] = [
            'word' => mb_substr($word, 0, 40, 'UTF-8'),
            'normalized' => $norm,
            'clue' => $clue,
            'row' => $row, 'col' => $col, 'dir' => $dir, 'length' => $len, 'number' => $number,
            'id' => count($placed),
        ];
        if (count($placed) >= CW_MAX_WORDS_HARD) break;
    }
    if (count($placed) === 0) return null;

    $unplacedRaw = $data['unplaced'] ?? [];
    $unplaced = [];
    if (is_array($unplacedRaw)) {
        foreach ($unplacedRaw as $u) {
            $u = trim((string)$u);
            if ($u !== '') $unplaced[] = mb_substr($u, 0, 40, 'UTF-8');
            if (count($unplaced) >= CW_MAX_WORDS_HARD) break;
        }
    }

    $sanitizeClueList = function ($raw) {
        $out = [];
        if (!is_array($raw)) return $out;
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            $out[] = [
                'number' => (int)($c['number'] ?? 0),
                'word' => mb_substr(trim((string)($c['word'] ?? '')), 0, 40, 'UTF-8'),
                'clue' => mb_substr(trim((string)($c['clue'] ?? '')), 0, 300, 'UTF-8'),
                'length' => (int)($c['length'] ?? 0),
            ];
        }
        return $out;
    };

    return [
        'rows' => $rows, 'cols' => $cols,
        'grid' => $cleanGrid,
        'placed' => $placed,
        'unplaced' => $unplaced,
        'acrossClues' => $sanitizeClueList($data['acrossClues'] ?? []),
        'downClues' => $sanitizeClueList($data['downClues'] ?? []),
        'source' => in_array(($data['source'] ?? ''), ['manual', 'ia'], true) ? $data['source'] : 'manual',
    ];
}

/** Fila de la tabla → payload público (sin datos internos). */
function _cw_project_public(array $row, bool $withPuzzle): array {
    $out = [
        'id' => (int)$row['id'],
        'title' => $row['titulo'],
        'rows' => (int)$row['rows_n'],
        'cols' => (int)$row['cols_n'],
        'wordCount' => (int)$row['word_count'],
        'source' => $row['source'],
        'estado' => $row['estado'],
        'created' => $row['created_at'],
    ];
    if ($withPuzzle) {
        $puzzle = json_decode((string)$row['puzzle_json'], true);
        $out['puzzle'] = is_array($puzzle) ? $puzzle : null;
    }
    return $out;
}

function triviax_cw_api_handle(string $action): void {
    switch ($action) {

        // ══════════════ DOCENTE ══════════════

        case 'cw_list_projects': {
            $docente = _cw_require_docente();
            $pdo = _cw_require_db();
            $isSuper = $docente['rol'] === TRIVIAX_ROL_SUPERADMIN;
            $sql = 'SELECT * FROM crossword_projects' . ($isSuper ? '' : ' WHERE docente_id = ?') . ' ORDER BY id DESC LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($isSuper ? [] : [(int)$docente['id']]);
            $projects = array_map(fn($r) => _cw_project_public($r, false), $stmt->fetchAll(PDO::FETCH_ASSOC));
            triviax_api_success(['projects' => $projects]);
            break;
        }

        case 'cw_save_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _cw_require_docente();
            $pdo = _cw_require_db();
            _cw_throttle('cw_save', (string)$docente['id'], 30, 600);

            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200, 'UTF-8');
            if ($title === '') {
                triviax_api_error('VALIDATION_ERROR', 'Falta el título de la actividad.', 422);
            }
            $puzzle = _cw_sanitize_puzzle((string)($_POST['puzzle'] ?? ''));
            if ($puzzle === null) {
                triviax_api_error('VALIDATION_ERROR', 'El crucigrama generado no es válido. Volvé a generarlo e intentá de nuevo.', 422);
            }

            $stmt = $pdo->prepare('
                INSERT INTO crossword_projects
                    (docente_id, titulo, rows_n, cols_n, word_count, source, puzzle_json, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int)$docente['id'], $title,
                $puzzle['rows'], $puzzle['cols'], count($puzzle['placed']),
                $puzzle['source'], json_encode($puzzle, JSON_UNESCAPED_UNICODE),
                'draft',
            ]);
            $id = (int)$pdo->lastInsertId();
            triviax_audit_log('cw_project_created', 'crossword_project', (string)$id, ['titulo' => $title]);
            triviax_api_success(['id' => $id], 'Crucigrama creado como borrador.');
            break;
        }

        case 'cw_publish_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _cw_require_docente();
            $pdo = _cw_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            _cw_load_own($pdo, $id, $docente);
            $estado = $input['estado'] ?? 'published';
            if (!in_array($estado, ['published', 'draft', 'archived'], true)) {
                triviax_api_error('VALIDATION_ERROR', 'Estado inválido.', 400);
            }
            $pdo->prepare('UPDATE crossword_projects SET estado = ? WHERE id = ?')->execute([$estado, $id]);
            triviax_sync_policy_publication('crossword_project', (string)$id, $estado, (int)$docente['id']);
            triviax_audit_log('cw_project_estado', 'crossword_project', (string)$id, ['estado' => $estado]);
            triviax_api_success(['id' => $id, 'estado' => $estado], 'Estado actualizado.');
            break;
        }

        case 'cw_delete_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _cw_require_docente();
            $pdo = _cw_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            _cw_load_own($pdo, $id, $docente);
            if (!triviax_can_delete_activity('crossword_project', (string)$id)) {
                triviax_api_error('HAS_RESULTS',
                    'La actividad tiene resultados o entregas asociadas. Archívala en lugar de eliminarla.', 409);
            }
            $pdo->prepare('DELETE FROM crossword_projects WHERE id = ?')->execute([$id]);
            triviax_purge_activity_policy('crossword_project', (string)$id);
            triviax_audit_log('cw_project_deleted', 'crossword_project', (string)$id, []);
            triviax_api_success(['id' => $id], 'Actividad eliminada.');
            break;
        }

        // ══════════════ JUEGO (abierto) ══════════════

        case 'cw_list_published': {
            $pdo = _cw_require_db();
            triviax_api_throttle('cw_public_list', 240, 60);
            $stmt = $pdo->query("SELECT * FROM crossword_projects WHERE estado = 'published' ORDER BY id DESC LIMIT 200");
            triviax_session_start();
            $rows = triviax_filter_catalog(triviax_usuario_actual(), 'crossword_project',
                $stmt->fetchAll(PDO::FETCH_ASSOC), fn($r) => (string)$r['id']);
            $projects = array_map(fn($r) => _cw_project_public($r, false), $rows);
            triviax_api_success(['projects' => $projects]);
            break;
        }

        case 'cw_get': {
            $pdo = _cw_require_db();
            triviax_api_throttle('cw_public_get', 240, 60);
            $id = (int)($_GET['id'] ?? 0);
            $p = _cw_load($pdo, $id);
            if (!$p) {
                triviax_api_error('NOT_FOUND', 'La actividad no existe.', 404);
            }
            triviax_session_start();
            $u = triviax_usuario_actual();
            if ($p['estado'] !== 'published') {
                // Un borrador solo lo puede abrir su dueño (vista previa del docente).
                $esDueno = $u && ((int)$p['docente_id'] === (int)$u['id'] || $u['rol'] === TRIVIAX_ROL_SUPERADMIN);
                if (!$esDueno) {
                    triviax_api_error('NOT_FOUND', 'La actividad no está disponible.', 404);
                }
            }
            // v7.0: política transversal (visibilidad, plazos, requisitos)
            $codigo = trim((string)($_GET['codigo'] ?? ''));
            $acc = triviax_can_start_activity($u, 'crossword_project', (string)$id, $codigo !== '' ? $codigo : null);
            if (!$acc['ok']) {
                triviax_api_error('ACCESS_DENIED', $acc['message'], 403, ['motivo' => $acc['reason']]);
            }
            triviax_api_success(['project' => _cw_project_public($p, true)]);
            break;
        }

        case 'cw_submit': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _cw_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $p = _cw_load($pdo, $id);
            if (!$p) {
                triviax_api_error('NOT_FOUND', 'La actividad no existe.', 404);
            }
            triviax_session_start();
            $u = triviax_usuario_actual();
            $codigo = trim((string)($input['codigo'] ?? ''));
            $acc = triviax_can_start_activity($u, 'crossword_project', (string)$id, $codigo !== '' ? $codigo : null);
            if (!$acc['ok']) {
                triviax_api_error('ACCESS_DENIED', $acc['message'], 403, ['motivo' => $acc['reason']]);
            }

            $total = max(0, (int)($input['total_cells'] ?? 0));
            $correct = max(0, min($total, (int)($input['correct_cells'] ?? 0)));
            $filled = max(0, min($total, (int)($input['filled_cells'] ?? 0)));
            $revealed = !empty($input['revealed']);
            $timeMs = max(0, (int)($input['time_ms'] ?? 0));
            $score = $revealed ? 0 : $correct;
            $politica = $acc['policy'];
            $esEval = !$acc['preview'] && !empty($politica['evaluativa']) && $acc['reason'] !== 'owner_preview';
            $version = $esEval ? triviax_get_current_activity_version('crossword_project', (string)$id) : null;

            $submissionId = triviax_record_activity_submission([
                'actividad_tipo' => 'crossword_project',
                'actividad_ref' => (string)$id,
                'politica_id' => $politica['id'] ?? null,
                'version_id' => $version['id'] ?? null,
                'usuario_id' => $u['id'] ?? null,
                'grupo_id' => $acc['grupo_id'],
                'grupo_estudiante_id' => $acc['grupo_estudiante_id'],
                'source_table' => 'crossword_projects',
                'source_id' => $id,
                'estado' => 'submitted',
                'puntaje' => $score,
                'max_puntaje' => $total,
                'time_ms' => $timeMs,
                'started_at' => !empty($input['started_at']) ? (string)$input['started_at'] : null,
                'evaluativa' => (int)$esEval,
                'summary_json' => [
                    'correct_cells' => $correct,
                    'filled_cells' => $filled,
                    'total_cells' => $total,
                    'revealed' => $revealed,
                    'title' => $p['titulo'],
                ],
            ]);
            triviax_api_success(['submission_id' => $submissionId], 'Entrega registrada.');
            break;
        }

        default:
            triviax_api_error('INVALID_ACTION', 'Acción inválida.', 400);
    }
}
