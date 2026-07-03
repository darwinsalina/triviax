<?php
/**
 * TRIVIAX — Endpoints de la modalidad "Puzle" (jigsaw_*).
 *
 * Incluido desde api.php cuando $action empieza con "jigsaw_".
 * Usa los helpers de api.php (triviax_api_*) y de php/auth.php (CSRF, rate
 * limit, sesión, roles), igual que study_answer_api.php.
 *
 * Seguridad:
 *  - Docente: sesión rol docente/superadmin + CSRF + propiedad del proyecto.
 *  - Juego: abierto (cualquiera con el enlace); CSRF por token de sesión PHP.
 */

require_once __DIR__ . '/jigsaw_engine.php';
require_once __DIR__ . '/activity_access.php'; // v7.0: políticas de acceso

function _jigsaw_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _jigsaw_require_docente(): array {
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

/** Carga un proyecto verificando propiedad (dueño o superadmin). */
function _jigsaw_load_own(int $id, array $docente): array {
    $p = triviax_jigsaw_load_project($id);
    if (!$p) {
        triviax_api_error('NOT_FOUND', 'El puzle no existe.', 404);
    }
    if ((int)$p['docente_id'] !== (int)$docente['id'] && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tenés permiso sobre este puzle.', 403);
    }
    return $p;
}

function _jigsaw_throttle(string $scope, string $id, int $max, int $win, int $block = 60): void {
    if (!triviax_rate_limit_check($scope, $id, $max, $win)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Esperá un momento.', 429);
    }
    triviax_rate_limit_hit($scope, $id, $win, $block, $max);
}

function triviax_jigsaw_api_handle(string $action): void {
    switch ($action) {

        // ══════════════ DOCENTE ══════════════

        case 'jigsaw_list_projects': {
            $docente = _jigsaw_require_docente();
            _jigsaw_require_db();
            $isSuper = $docente['rol'] === TRIVIAX_ROL_SUPERADMIN;
            triviax_api_success(['projects' => triviax_jigsaw_list_for_docente((int)$docente['id'], $isSuper)]);
            break;
        }

        case 'jigsaw_save_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _jigsaw_require_docente();
            _jigsaw_require_db();
            _jigsaw_throttle('jigsaw_save', (string)$docente['id'], 30, 600);

            $val = triviax_jigsaw_validate_options($_POST);
            if (!$val['ok']) {
                triviax_api_error('VALIDATION_ERROR', 'Revisá los datos del puzle.', 422, $val['errors']);
            }

            $slug = triviax_jigsaw_slugify($val['values']['titulo']);
            $destDir = triviax_jigsaw_media_root() . '/' . $slug;
            $img = triviax_img_process_upload($_FILES['image'] ?? [], $destDir);
            if (!$img['ok']) {
                triviax_api_error('VALIDATION_ERROR', $img['error'], 422);
            }

            try {
                $saved = triviax_jigsaw_create_project($val['values'], (int)$docente['id'], $slug, $img['image_w'], $img['image_h']);
                triviax_audit_log('jigsaw_project_created', 'jigsaw_project', (string)$saved['id'], ['slug' => $slug]);
                triviax_api_success(['id' => $saved['id'], 'slug' => $saved['slug']], 'Puzle creado como borrador.');
            } catch (Throwable $e) {
                triviax_img_delete_dir($destDir);
                triviax_api_error('SERVER_ERROR', 'No se pudo guardar el puzle.', 500);
            }
            break;
        }

        case 'jigsaw_publish_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _jigsaw_require_docente();
            $pdo = _jigsaw_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $project = _jigsaw_load_own($id, $docente);
            $estado = $input['estado'] ?? 'published';
            if (!in_array($estado, ['published', 'draft', 'archived'], true)) {
                triviax_api_error('VALIDATION_ERROR', 'Estado inválido.', 400);
            }
            $pdo->prepare('UPDATE jigsaw_projects SET estado = ? WHERE id = ?')->execute([$estado, $id]);
            triviax_sync_policy_publication('jigsaw_project', (string)$id, $estado, (int)$docente['id']);
            triviax_audit_log('jigsaw_project_estado', 'jigsaw_project', (string)$id, ['estado' => $estado]);
            triviax_api_success(['id' => $id, 'estado' => $estado], 'Estado actualizado.');
            break;
        }

        case 'jigsaw_delete_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _jigsaw_require_docente();
            _jigsaw_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $project = _jigsaw_load_own($id, $docente);
            if (!triviax_can_delete_activity('jigsaw_project', (string)$id)) {
                triviax_api_error('HAS_RESULTS',
                    'El puzle tiene partidas registradas. Archívalo en lugar de eliminarlo.', 409);
            }
            triviax_jigsaw_delete_project($project);
            triviax_purge_activity_policy('jigsaw_project', (string)$id);
            triviax_audit_log('jigsaw_project_deleted', 'jigsaw_project', (string)$id, []);
            triviax_api_success(['id' => $id], 'Puzle eliminado.');
            break;
        }

        case 'jigsaw_report': {
            $docente = _jigsaw_require_docente();
            $pdo = _jigsaw_require_db();
            $id = (int)($_GET['id'] ?? 0);
            _jigsaw_load_own($id, $docente);
            $stmt = $pdo->prepare("
                SELECT s.id, s.estado, s.grid, s.mode, s.time_ms, s.moves, s.started_at, s.finished_at,
                       COALESCE(CONCAT(u.nombre, ' ', u.apellido), 'Invitado') AS jugador
                FROM jigsaw_sessions s
                LEFT JOIN usuarios u ON u.id = s.usuario_id
                WHERE s.project_id = ?
                ORDER BY s.started_at DESC
                LIMIT 200
            ");
            $stmt->execute([$id]);
            triviax_api_success(['sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ══════════════ JUEGO (abierto) ══════════════

        case 'jigsaw_list_published': {
            _jigsaw_require_db();
            triviax_session_start();
            $projects = triviax_filter_catalog(triviax_usuario_actual(), 'jigsaw_project',
                triviax_jigsaw_list_published(), fn($p) => (string)$p['id']);
            triviax_api_success(['projects' => $projects]);
            break;
        }

        case 'jigsaw_start': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _jigsaw_require_db();
            _jigsaw_throttle('jigsaw_start', (string)($_SERVER['REMOTE_ADDR'] ?? 'local'), 30, 600);
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $project = triviax_jigsaw_load_project($id);
            if (!$project || $project['estado'] !== 'published') {
                triviax_api_error('NOT_FOUND', 'El puzle no existe o no está publicado.', 404);
            }
            triviax_session_start();
            $usuario = triviax_usuario_actual();
            // v7.0: política transversal (visibilidad, plazos, requisitos)
            $codigo = trim((string)($input['codigo'] ?? ''));
            $acc = triviax_can_start_activity($usuario, 'jigsaw_project', (string)$id, $codigo !== '' ? $codigo : null);
            if (!$acc['ok']) {
                triviax_api_error('ACCESS_DENIED', $acc['message'], 403, ['motivo' => $acc['reason']]);
            }
            $politica = $acc['policy'];
            $esEval = !$acc['preview'] && !empty($politica['evaluativa']) && $acc['reason'] !== 'owner_preview';
            $version = $esEval ? triviax_get_current_activity_version('jigsaw_project', (string)$id) : null;
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('
                INSERT INTO jigsaw_sessions (project_id, usuario_id, player_token_hash, estado, started_at, last_seen_at,
                                             politica_id, actividad_version_id, grupo_id, grupo_estudiante_id, evaluativa)
                VALUES (?, ?, ?, \'active\', NOW(), NOW(), ?, ?, ?, ?, ?)
            ')->execute([
                $id,
                $usuario !== null ? (int)$usuario['id'] : null,
                hash('sha256', $token),
                $politica['id'] ?? null,
                $version['id'] ?? null,
                $acc['grupo_id'],
                $acc['grupo_estudiante_id'],
                (int)$esEval,
            ]);
            triviax_api_success([
                'session_id' => (int)$pdo->lastInsertId(),
                'session_token' => $token,
            ], 'Partida iniciada.');
            break;
        }

        case 'jigsaw_finish': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _jigsaw_require_db();
            $input = triviax_api_input();
            $sessionId = (int)($input['session_id'] ?? 0);
            $token = trim((string)($input['session_token'] ?? ''));
            if ($sessionId <= 0 || strlen($token) < 32) {
                triviax_api_error('UNAUTHORIZED', 'Credenciales de partida inválidas.', 401);
            }
            $stmt = $pdo->prepare('SELECT * FROM jigsaw_sessions WHERE id = ? AND player_token_hash = ?');
            $stmt->execute([$sessionId, hash('sha256', $token)]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                triviax_api_error('FORBIDDEN', 'Partida no autorizada.', 403);
            }
            if ($session['estado'] === 'finished') {
                triviax_api_success(['cached' => true], 'La partida ya estaba registrada.');
            }
            $timeMs = isset($input['time_ms']) ? max(0, (int)$input['time_ms']) : null;
            $moves = isset($input['moves']) ? max(0, (int)$input['moves']) : null;
            $grid = isset($input['grid']) ? max(2, min(40, (int)$input['grid'])) : null;
            $mode = in_array($input['mode'] ?? '', ['tray', 'scatter'], true) ? $input['mode'] : null;
            $summary = ['time_ms' => $timeMs, 'moves' => $moves, 'grid' => $grid, 'mode' => $mode];
            $pdo->prepare('
                UPDATE jigsaw_sessions
                   SET estado = \'finished\', finished_at = NOW(), last_seen_at = NOW(),
                       time_ms = ?, moves = ?, grid = ?, mode = ?, summary_json = ?
                 WHERE id = ?
            ')->execute([$timeMs, $moves, $grid, $mode, json_encode($summary, JSON_UNESCAPED_UNICODE), $sessionId]);
            // v7.0: entrega transversal (reporte/exportación/evaluación)
            try {
                triviax_record_activity_submission([
                    'actividad_tipo' => 'jigsaw_project',
                    'actividad_ref' => (string)$session['project_id'],
                    'politica_id' => $session['politica_id'] ?? null,
                    'version_id' => $session['actividad_version_id'] ?? null,
                    'usuario_id' => $session['usuario_id'] ?? null,
                    'grupo_id' => $session['grupo_id'] ?? null,
                    'grupo_estudiante_id' => $session['grupo_estudiante_id'] ?? null,
                    'source_table' => 'jigsaw_sessions',
                    'source_id' => $sessionId,
                    'estado' => 'submitted',
                    'time_ms' => $timeMs,
                    'started_at' => $session['started_at'] ?? null,
                    'evaluativa' => (int)($session['evaluativa'] ?? 0),
                    'summary_json' => $summary,
                ]);
            } catch (Throwable $e) {
                // La entrega transversal nunca corta el flujo del juego.
            }
            triviax_api_success(['session_id' => $sessionId], 'Partida registrada.');
            break;
        }

        default:
            triviax_api_error('VALIDATION_ERROR', 'Acción jigsaw_ desconocida.', 400);
    }
}
