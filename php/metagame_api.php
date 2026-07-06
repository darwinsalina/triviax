<?php
/**
 * Endpoints metagame_* del metajuego TRIVIAX+ (Épica 1).
 *
 * Identidad del estudiante (en orden de preferencia):
 *   1. Sesión PHP autenticada con rol estudiante (triviax_usuario_actual).
 *   2. Terna sesion_id + jugador_id + player_token de una partida en curso,
 *      siempre que ese jugador esté vinculado a una cuenta (usuario_id).
 *
 * Acciones:
 *   GET  metagame_profile — perfil (xp, monedas, racha, nivel, inventario)
 *   GET  metagame_shop    — catálogo de la tienda con posesión/equipado
 *   POST metagame_buy     — comprar ítem (CSRF)
 *   POST metagame_equip   — equipar/desequipar ítem (CSRF)
 */

require_once __DIR__ . '/metagame_engine.php';

function _metagame_require_db(): PDO {
    require_once __DIR__ . '/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

/**
 * Intenta resolver el usuario_id del llamante; devuelve 0 si no hay identidad.
 * Acepta identidad por sesión PHP o por credenciales de jugador de partida.
 */
function _metagame_user_or_zero(PDO $pdo, array $input): int {
    $u = triviax_usuario_actual();
    if ($u && in_array($u['rol'], ['estudiante', 'docente', 'superadmin'], true)) {
        return (int)$u['id'];
    }

    $sesionId    = (int)($input['sesion_id'] ?? ($_GET['sesion_id'] ?? 0));
    $jugadorId   = (int)($input['jugador_id'] ?? ($_GET['jugador_id'] ?? 0));
    $playerToken = trim((string)($input['player_token'] ?? ($_GET['player_token'] ?? '')));
    if ($sesionId > 0 && $jugadorId > 0 && strlen($playerToken) >= 32) {
        $stmt = $pdo->prepare('SELECT usuario_id FROM sesion_jugadores WHERE sesion_id = ? AND id = ? AND player_token = ?');
        $stmt->execute([$sesionId, $jugadorId, triviax_api_token_hash($playerToken)]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
    return 0;
}

/** Igual que _metagame_user_or_zero pero corta con 401 si no hay identidad. */
function _metagame_resolve_user(PDO $pdo, array $input): int {
    $usuarioId = _metagame_user_or_zero($pdo, $input);
    if ($usuarioId <= 0) {
        triviax_api_error('UNAUTHORIZED', 'Se requiere una cuenta de estudiante para el metajuego.', 401);
    }
    return $usuarioId;
}

function triviax_metagame_api_handle(string $action): void {
    $pdo = _metagame_require_db();

    if (!triviax_metagame_available($pdo)) {
        triviax_api_error('NOT_AVAILABLE', 'El metajuego no está habilitado (falta la migración 6.7).', 503);
    }

    switch ($action) {
        case 'metagame_profile': {
            $usuarioId = _metagame_resolve_user($pdo, triviax_api_input());
            $perfil = triviax_metagame_profile($pdo, $usuarioId);
            if ($perfil === null) {
                triviax_api_error('SERVER_ERROR', 'No se pudo leer el perfil.', 500);
            }
            triviax_api_success(['perfil' => $perfil]);
            break;
        }

        case 'metagame_shop': {
            // El catálogo es público; la posesión solo se marca con identidad.
            $usuarioId = _metagame_user_or_zero($pdo, triviax_api_input());
            $items = triviax_metagame_shop_items($pdo, $usuarioId);
            if ($items === null) {
                triviax_api_error('SERVER_ERROR', 'No se pudo leer la tienda.', 500);
            }
            triviax_api_success(['items' => $items]);
            break;
        }

        case 'metagame_buy': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            triviax_api_throttle('metagame_buy', 60, 60);
            $input = triviax_api_input();
            $usuarioId = _metagame_resolve_user($pdo, $input);
            $itemId = (int)($input['item_id'] ?? 0);
            $res = triviax_metagame_buy($pdo, $usuarioId, $itemId);
            if (!$res['ok']) {
                $mensajes = [
                    'ITEM_NO_EXISTE'         => 'El ítem no existe o no está disponible.',
                    'YA_ADQUIRIDO'           => 'Ya tienes este ítem.',
                    'MONEDAS_INSUFICIENTES'  => 'No tienes monedas suficientes.',
                ];
                triviax_api_error($res['error'], $mensajes[$res['error']] ?? 'No se pudo completar la compra.', 400);
            }
            triviax_api_success(['monedas_total' => $res['monedas_total']], 'Compra realizada.');
            break;
        }

        case 'metagame_equip': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();
            $usuarioId = _metagame_resolve_user($pdo, $input);
            $itemId  = (int)($input['item_id'] ?? 0);
            $equipar = !isset($input['equipar']) || (bool)$input['equipar'];
            $res = triviax_metagame_equip($pdo, $usuarioId, $itemId, $equipar);
            if (!$res['ok']) {
                triviax_api_error($res['error'], $res['error'] === 'NO_ADQUIRIDO' ? 'Primero debes adquirir el ítem.' : 'No se pudo equipar el ítem.', 400);
            }
            triviax_api_success([], $equipar ? 'Ítem equipado.' : 'Ítem desequipado.');
            break;
        }

        default:
            triviax_api_error('BAD_ACTION', 'Acción de metajuego desconocida.', 400);
    }
}
