<?php
/**
 * TRIVIAX — Endpoints de la modalidad "Etiquetar" (etiquetar_*).
 *
 * Incluido desde api.php cuando $action empieza con "etiquetar_".
 * Mismo molde que jigsaw_api.php / study_answer_api.php.
 */

require_once __DIR__ . '/etiquetar_engine.php';

function _etiquetar_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _etiquetar_require_docente(): array {
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

function _etiquetar_load_own(int $id, array $docente): array {
    $p = triviax_etiquetar_load_project($id);
    if (!$p) {
        triviax_api_error('NOT_FOUND', 'La actividad no existe.', 404);
    }
    if ((int)$p['docente_id'] !== (int)$docente['id'] && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tenés permiso sobre esta actividad.', 403);
    }
    return $p;
}

function _etiquetar_throttle(string $scope, string $id, int $max, int $win, int $block = 60): void {
    if (!triviax_rate_limit_check($scope, $id, $max, $win)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Esperá un momento.', 429);
    }
    triviax_rate_limit_hit($scope, $id, $win, $block, $max);
}

function triviax_etiquetar_api_handle(string $action): void {
    switch ($action) {

        // ══════════════ DOCENTE ══════════════

        case 'etiquetar_list_projects': {
            $docente = _etiquetar_require_docente();
            _etiquetar_require_db();
            $isSuper = $docente['rol'] === TRIVIAX_ROL_SUPERADMIN;
            triviax_api_success(['projects' => triviax_etiquetar_list_for_docente((int)$docente['id'], $isSuper)]);
            break;
        }

        case 'etiquetar_save_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _etiquetar_require_docente();
            _etiquetar_require_db();
            _etiquetar_throttle('etiquetar_save', (string)$docente['id'], 30, 600);

            $val = triviax_etiquetar_validate($_POST);
            if (!$val['ok']) {
                triviax_api_error('VALIDATION_ERROR', 'Revisá los datos de la actividad.', 422, $val['errors']);
            }

            $slug = triviax_etiquetar_slugify($val['values']['titulo']);
            $destDir = triviax_etiquetar_media_root() . '/' . $slug;
            $img = triviax_img_process_upload($_FILES['image'] ?? [], $destDir);
            if (!$img['ok']) {
                triviax_api_error('VALIDATION_ERROR', $img['error'], 422);
            }

            try {
                $saved = triviax_etiquetar_create_project($val['values'], (int)$docente['id'], $slug, $img['image_w'], $img['image_h']);
                triviax_audit_log('etiquetar_project_created', 'etiquetar_project', (string)$saved['id'], ['slug' => $slug, 'labels' => $saved['labels']]);
                triviax_api_success(['id' => $saved['id'], 'slug' => $saved['slug'], 'labels' => $saved['labels']], 'Actividad creada como borrador.');
            } catch (Throwable $e) {
                triviax_img_delete_dir($destDir);
                triviax_api_error('SERVER_ERROR', 'No se pudo guardar la actividad.', 500);
            }
            break;
        }

        case 'etiquetar_publish_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _etiquetar_require_docente();
            $pdo = _etiquetar_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            _etiquetar_load_own($id, $docente);
            $estado = $input['estado'] ?? 'published';
            if (!in_array($estado, ['published', 'draft', 'archived'], true)) {
                triviax_api_error('VALIDATION_ERROR', 'Estado inválido.', 400);
            }
            $pdo->prepare('UPDATE etiquetar_projects SET estado = ? WHERE id = ?')->execute([$estado, $id]);
            triviax_audit_log('etiquetar_project_estado', 'etiquetar_project', (string)$id, ['estado' => $estado]);
            triviax_api_success(['id' => $id, 'estado' => $estado], 'Estado actualizado.');
            break;
        }

        case 'etiquetar_delete_project': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $docente = _etiquetar_require_docente();
            _etiquetar_require_db();
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $project = _etiquetar_load_own($id, $docente);
            triviax_etiquetar_delete_project($project);
            triviax_audit_log('etiquetar_project_deleted', 'etiquetar_project', (string)$id, []);
            triviax_api_success(['id' => $id], 'Actividad eliminada.');
            break;
        }

        case 'etiquetar_report': {
            $docente = _etiquetar_require_docente();
            $pdo = _etiquetar_require_db();
            $id = (int)($_GET['id'] ?? 0);
            _etiquetar_load_own($id, $docente);
            $stmt = $pdo->prepare("
                SELECT s.id, s.estado, s.time_ms, s.correct, s.total, s.started_at, s.finished_at,
                       COALESCE(CONCAT(u.nombre, ' ', u.apellido), 'Invitado') AS jugador
                FROM etiquetar_sessions s
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

        case 'etiquetar_list_published': {
            _etiquetar_require_db();
            triviax_api_success(['projects' => triviax_etiquetar_list_published()]);
            break;
        }

        case 'etiquetar_start': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _etiquetar_require_db();
            _etiquetar_throttle('etiquetar_start', (string)($_SERVER['REMOTE_ADDR'] ?? 'local'), 30, 600);
            $input = triviax_api_input();
            $id = (int)($input['id'] ?? 0);
            $project = triviax_etiquetar_load_project($id);
            if (!$project || $project['estado'] !== 'published') {
                triviax_api_error('NOT_FOUND', 'La actividad no existe o no está publicada.', 404);
            }
            triviax_session_start();
            $usuario = triviax_usuario_actual();
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('
                INSERT INTO etiquetar_sessions (project_id, usuario_id, player_token_hash, estado, started_at, last_seen_at)
                VALUES (?, ?, ?, \'active\', NOW(), NOW())
            ')->execute([
                $id,
                $usuario !== null ? (int)$usuario['id'] : null,
                hash('sha256', $token),
            ]);
            triviax_api_success(['session_id' => (int)$pdo->lastInsertId(), 'session_token' => $token], 'Partida iniciada.');
            break;
        }

        case 'etiquetar_finish': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $pdo = _etiquetar_require_db();
            $input = triviax_api_input();
            $sessionId = (int)($input['session_id'] ?? 0);
            $token = trim((string)($input['session_token'] ?? ''));
            if ($sessionId <= 0 || strlen($token) < 32) {
                triviax_api_error('UNAUTHORIZED', 'Credenciales de partida inválidas.', 401);
            }
            $stmt = $pdo->prepare('SELECT * FROM etiquetar_sessions WHERE id = ? AND player_token_hash = ?');
            $stmt->execute([$sessionId, hash('sha256', $token)]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                triviax_api_error('FORBIDDEN', 'Partida no autorizada.', 403);
            }
            if ($session['estado'] === 'finished') {
                triviax_api_success(['cached' => true], 'La partida ya estaba registrada.');
            }
            $timeMs = isset($input['time_ms']) ? max(0, (int)$input['time_ms']) : null;
            $total = isset($input['total']) ? max(0, (int)$input['total']) : null;
            $correct = isset($input['correct']) ? max(0, (int)$input['correct']) : null;
            $summary = ['time_ms' => $timeMs, 'correct' => $correct, 'total' => $total];
            $pdo->prepare('
                UPDATE etiquetar_sessions
                   SET estado = \'finished\', finished_at = NOW(), last_seen_at = NOW(),
                       time_ms = ?, correct = ?, total = ?, summary_json = ?
                 WHERE id = ?
            ')->execute([$timeMs, $correct, $total, json_encode($summary, JSON_UNESCAPED_UNICODE), $sessionId]);
            triviax_api_success(['session_id' => $sessionId], 'Partida registrada.');
            break;
        }

        default:
            triviax_api_error('VALIDATION_ERROR', 'Acción etiquetar_ desconocida.', 400);
    }
}
