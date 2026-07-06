<?php
/**
 * Endpoints marketplace_* del Marketplace educativo (TRIVIAX+ Épica 3).
 *
 * Todas las acciones requieren sesión de docente (o superadmin). Las
 * mutaciones (publicar, clonar) exigen POST + CSRF (token síncrono o
 * cabecera X-CSRF-Token), regla de calidad del plan TRIVIAX+.
 *
 * Acciones:
 *   GET  marketplace_list    — actividades públicas (filtros q, nivel, orden)
 *   GET  marketplace_levels  — niveles disponibles para el filtro
 *   GET  marketplace_mine    — proyectos propios con su estado de publicación
 *   POST marketplace_publish — publicar/despublicar un proyecto propio
 *   POST marketplace_clone   — clonar una actividad pública al catálogo propio
 */

require_once __DIR__ . '/marketplace_engine.php';

function _marketplace_require_docente(): array {
    $u = triviax_usuario_actual();
    if (!$u) {
        triviax_api_error('UNAUTHORIZED', 'Se requiere sesión de docente.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Solo docentes pueden usar el marketplace.', 403);
    }
    return $u;
}

function _marketplace_require_db(): PDO {
    require_once __DIR__ . '/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $pdo = triviax_db();
    if (!triviax_marketplace_available($pdo)) {
        triviax_api_error('NOT_AVAILABLE', 'El marketplace no está habilitado (falta la migración 6.9).', 503);
    }
    return $pdo;
}

function triviax_marketplace_api_handle(string $action): void {
    $u   = _marketplace_require_docente();
    $pdo = _marketplace_require_db();
    $esSuperadmin = ($u['rol'] === TRIVIAX_ROL_SUPERADMIN);

    switch ($action) {
        case 'marketplace_list': {
            $items = triviax_marketplace_list($pdo, [
                'q'     => (string)($_GET['q'] ?? ''),
                'nivel' => (string)($_GET['nivel'] ?? ''),
                'orden' => (string)($_GET['orden'] ?? ''),
            ], (int)$u['id']);
            triviax_api_success(['items' => $items]);
            break;
        }

        case 'marketplace_levels': {
            triviax_api_success(['niveles' => triviax_marketplace_levels($pdo)]);
            break;
        }

        case 'marketplace_mine': {
            $stmt = $pdo->prepare("
                SELECT p.id, p.title, p.nivel, p.es_publico, p.descargas_count, p.clonado_desde_id,
                       (SELECT COUNT(*) FROM desafios d WHERE d.proyecto_id = p.id) AS num_desafios
                FROM proyectos p
                WHERE p.in_trash = 0 AND " . ($esSuperadmin ? '1=1' : 'p.docente_id = ?') . "
                ORDER BY p.updated_at DESC
                LIMIT 200
            ");
            $stmt->execute($esSuperadmin ? [] : [(int)$u['id']]);
            $items = array_map(static function (array $r): array {
                $r['es_publico']      = (bool)$r['es_publico'];
                $r['descargas_count'] = (int)$r['descargas_count'];
                $r['num_desafios']    = (int)$r['num_desafios'];
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            triviax_api_success(['items' => $items]);
            break;
        }

        case 'marketplace_publish': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();
            $projectId = trim((string)($input['project_id'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectId)) {
                triviax_api_error('VALIDATION_ERROR', 'Proyecto inválido.', 400);
            }
            $res = triviax_marketplace_publish($pdo, $projectId, (int)$u['id'], !empty($input['publico']), $esSuperadmin);
            if (!$res['ok']) {
                $mensajes = [
                    'NOT_FOUND' => 'El proyecto no existe.',
                    'FORBIDDEN' => 'Solo puedes publicar tus propios proyectos.',
                ];
                triviax_api_error($res['error'], $mensajes[$res['error']] ?? 'No se pudo cambiar la publicación.', $res['error'] === 'FORBIDDEN' ? 403 : 400);
            }
            triviax_audit_log('marketplace_publish', 'proyecto', $projectId, ['publico' => !empty($input['publico'])]);
            triviax_api_success([], !empty($input['publico']) ? 'Actividad publicada en el marketplace.' : 'Actividad retirada del marketplace.');
            break;
        }

        case 'marketplace_clone': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            triviax_api_throttle('marketplace_clone', 20, 300, 300);
            $input = triviax_api_input();
            $projectId = trim((string)($input['project_id'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectId)) {
                triviax_api_error('VALIDATION_ERROR', 'Proyecto inválido.', 400);
            }
            global $baseProjectsDir;
            $res = triviax_marketplace_clone($pdo, $projectId, (int)$u['id'], (string)$baseProjectsDir);
            if (!$res['ok']) {
                triviax_api_error($res['error'], $res['error'] === 'NOT_FOUND'
                    ? 'La actividad no está publicada en el marketplace.'
                    : 'No se pudo clonar la actividad.', $res['error'] === 'NOT_FOUND' ? 404 : 500);
            }
            triviax_audit_log('marketplace_clone', 'proyecto', $res['nuevo_id'], ['origen' => $projectId]);
            triviax_api_success(['nuevo_id' => $res['nuevo_id']], 'Actividad clonada a tu catálogo.');
            break;
        }

        default:
            triviax_api_error('BAD_ACTION', 'Acción de marketplace desconocida.', 400);
    }
}
