<?php
/**
 * Endpoints de TRIVIAX Futbol - Camino al Gol (football_*).
 *
 * La v1 usa sesion PHP como almacenamiento autoritativo para permitir demo
 * inmediata. La migracion SQL adjunta deja preparada la persistencia formal.
 */

require_once __DIR__ . '/football_engine.php';
require_once __DIR__ . '/football_validator.php';

function _football_store(): array {
    triviax_session_start();
    if (!isset($_SESSION['triviax_football_sessions']) || !is_array($_SESSION['triviax_football_sessions'])) {
        $_SESSION['triviax_football_sessions'] = [];
    }
    return $_SESSION['triviax_football_sessions'];
}

function _football_save_store(array $store): void {
    $_SESSION['triviax_football_sessions'] = $store;
}

function _football_fixture(): array {
    $path = __DIR__ . '/../docs/fixtures/football_goal_race_demo.json';
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        triviax_api_error('SERVER_ERROR', 'No se pudo cargar la actividad demo de futbol.', 500);
    }
    return $data;
}

function _football_questions(): array {
    $fixture = _football_fixture();
    return $fixture['questions'] ?? [];
}

function _football_public_payload(array $entry): array {
    return [
        'session_id' => $entry['id'],
        'state' => triviax_football_public_state($entry['state']),
        'board' => triviax_football_default_board(),
        'activity' => [
            'id' => 'football_goal_race_demo',
            'title' => 'TRIVIAX Futbol - Camino al Gol',
        ],
    ];
}

function _football_require_entry(array &$store, array $input): array {
    $id = (string)($input['session_id'] ?? $_GET['session_id'] ?? '');
    $token = (string)($input['token'] ?? $_GET['token'] ?? '');
    if ($id === '' || $token === '' || empty($store[$id])) {
        triviax_api_error('NOT_FOUND', 'La partida no existe o expiro.', 404);
    }
    if (!hash_equals((string)$store[$id]['token_hash'], hash('sha256', $token))) {
        triviax_api_error('FORBIDDEN', 'Token de partida invalido.', 403);
    }
    return $store[$id];
}

function triviax_football_api_handle(string $action): void {
    switch ($action) {
        case 'football_board': {
            $board = triviax_football_default_board();
            $errors = triviax_football_validate_board($board);
            if ($errors) {
                triviax_api_error('SERVER_ERROR', 'El tablero de futbol no es valido.', 500, $errors);
            }
            triviax_api_success(['board' => $board]);
            break;
        }

        case 'football_demo': {
            triviax_api_success(['activity' => _football_fixture(), 'board' => triviax_football_default_board()]);
            break;
        }

        case 'football_start': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();
            $state = triviax_football_create_state([
                'diceSides' => (int)($input['diceSides'] ?? 6),
                'teamAnswerMode' => (string)($input['teamAnswerMode'] ?? 'short_team_help'),
                'teams' => [
                    'blue' => $input['teams']['blue'] ?? ['Azul'],
                    'red' => $input['teams']['red'] ?? ['Rojo'],
                ],
            ]);
            $id = bin2hex(random_bytes(8));
            $token = bin2hex(random_bytes(24));
            $store = _football_store();
            $store[$id] = [
                'id' => $id,
                'token_hash' => hash('sha256', $token),
                'state' => $state,
                'processed' => [],
                'created_at' => time(),
            ];
            _football_save_store($store);
            $payload = _football_public_payload($store[$id]);
            $payload['token'] = $token;
            triviax_api_success($payload, 'Partida de futbol creada.');
            break;
        }

        case 'football_state': {
            $store = _football_store();
            $entry = _football_require_entry($store, $_GET);
            triviax_api_success(_football_public_payload($entry));
            break;
        }

        case 'football_roll': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();
            $store = _football_store();
            $entry = _football_require_entry($store, $input);
            try {
                $entry['state'] = triviax_football_roll(
                    $entry['state'],
                    triviax_football_pick_question(_football_questions(), 'normal')
                );
                $store[$entry['id']] = $entry;
                _football_save_store($store);
                triviax_api_success(_football_public_payload($entry), 'Dado resuelto.');
            } catch (Throwable $e) {
                triviax_api_error('INVALID_TURN', $e->getMessage(), 409);
            }
            break;
        }

        case 'football_answer': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();
            $store = _football_store();
            $entry = _football_require_entry($store, $input);
            $key = trim((string)($input['idempotency_key'] ?? ''));
            if ($key !== '' && isset($entry['processed'][$key])) {
                triviax_api_success(_football_public_payload($entry), 'Respuesta ya procesada.');
            }
            try {
                $entry['state'] = triviax_football_submit($entry['state'], $input['answer'] ?? '', _football_questions());
                if ($key !== '') {
                    $entry['processed'][$key] = time();
                }
                $store[$entry['id']] = $entry;
                _football_save_store($store);
                triviax_api_success(_football_public_payload($entry), 'Respuesta registrada.');
            } catch (Throwable $e) {
                triviax_api_error('INVALID_TURN', $e->getMessage(), 409);
            }
            break;
        }

        default:
            triviax_api_error('INVALID_ACTION', 'Accion invalida.', 400);
    }
}
