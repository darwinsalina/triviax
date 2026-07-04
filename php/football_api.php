<?php
/**
 * Endpoints de TRIVIAX Futbol - Camino al Gol (football_*).
 *
 * La v1 usa sesion PHP como almacenamiento autoritativo para permitir demo
 * inmediata. La migracion SQL adjunta deja preparada la persistencia formal.
 */

require_once __DIR__ . '/football_engine.php';
require_once __DIR__ . '/football_validator.php';
require_once __DIR__ . '/activity_access.php'; // v7.0: políticas de acceso

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

/**
 * Adapta desafíos del tablero (formato normalizado de project_import:
 * prompt.text + options[{text,correct}] / answer.value) al formato plano
 * que espera el motor de fútbol: {id, prompt, options[str], correct}.
 * Solo los tipos con opciones cerradas son jugables en la cancha:
 * multiple_choice y true_false; el resto se omite.
 */
function _football_adapt_challenges(array $challenges): array {
    $out = [];
    foreach ($challenges as $i => $c) {
        if (!is_array($c)) {
            continue;
        }
        $type = (string)($c['type'] ?? '');
        $prompt = is_array($c['prompt'] ?? null)
            ? trim((string)($c['prompt']['text'] ?? ''))
            : trim((string)($c['prompt'] ?? ''));
        if ($prompt === '') {
            continue;
        }
        $id = trim((string)($c['id'] ?? '')) ?: ('q' . $i);

        if ($type === 'multiple_choice' && is_array($c['options'] ?? null)) {
            $options = [];
            $correct = null;
            foreach ($c['options'] as $o) {
                $text = is_array($o) ? trim((string)($o['text'] ?? '')) : trim((string)$o);
                if ($text === '') {
                    continue;
                }
                $options[] = $text;
                if (is_array($o) && !empty($o['correct'])) {
                    $correct = $text;
                }
            }
            if (count($options) >= 2 && $correct !== null) {
                $out[] = ['id' => $id, 'prompt' => $prompt, 'options' => $options, 'correct' => $correct];
            }
            continue;
        }

        if ($type === 'true_false') {
            $value = null;
            if (is_array($c['answer'] ?? null) && array_key_exists('value', $c['answer'])) {
                $value = (bool)$c['answer']['value'];
            } elseif (array_key_exists('correct', $c) && is_bool($c['correct'])) {
                $value = $c['correct'];
            }
            if ($value !== null) {
                $out[] = [
                    'id' => $id,
                    'prompt' => $prompt,
                    'options' => ['Verdadero', 'Falso'],
                    'correct' => $value ? 'Verdadero' : 'Falso',
                ];
            }
        }
    }
    return $out;
}

/**
 * Banco de preguntas efectivo de una partida: los desafíos del proyecto
 * elegido (BD primero, filesystem como fallback), adaptados al formato
 * de fútbol; si no hay proyecto o no tiene preguntas jugables, la demo.
 */
function _football_questions(?array $entry = null): array {
    $projectSlug = '';
    if ($entry !== null && isset($entry['project_slug'])) {
        $projectSlug = (string)$entry['project_slug'];
    }

    if ($projectSlug !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $projectSlug)) {
        require_once __DIR__ . '/project_import.php';
        $challenges = null;
        try {
            if (triviax_db_available()) {
                $challenges = triviax_db_load_challenges(triviax_db(), $projectSlug);
            }
        } catch (Throwable $e) {
            $challenges = null;
        }
        if (!$challenges) {
            $projectDir = __DIR__ . '/../proyectos/' . $projectSlug;
            if (is_dir($projectDir)) {
                try {
                    $challenges = triviax_load_project_challenges($projectDir);
                } catch (Throwable $e) {
                    $challenges = null;
                }
            }
        }
        if ($challenges) {
            $adapted = _football_adapt_challenges($challenges);
            if ($adapted) {
                return $adapted;
            }
        }
    }

    $fixture = _football_fixture();
    return $fixture['questions'] ?? [];
}

function _football_public_payload(array $entry): array {
    $projectSlug = $entry['project_slug'] ?? '';
    $title = 'TRIVIAX Futbol - Camino al Gol';
    if ($projectSlug !== '') {
        try {
            if (triviax_db_available()) {
                $stmt = triviax_db()->prepare('SELECT title FROM proyectos WHERE id = ?');
                $stmt->execute([$projectSlug]);
                $t = $stmt->fetchColumn();
                if ($t) {
                    $title = $t;
                }
            }
        } catch (Throwable $e) {}
    }
    return [
        'session_id' => $entry['id'],
        'state' => triviax_football_public_state($entry['state']),
        'board' => triviax_football_default_board(),
        'activity' => [
            'id' => $projectSlug !== '' ? $projectSlug : 'football_goal_race_demo',
            'title' => $title,
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

        // Bancos de preguntas disponibles para la cancha: proyectos del
        // tablero con desafíos de opciones cerradas (multiple_choice o
        // true_false), filtrados por la política de visibilidad v7.0.
        case 'football_projects': {
            $bancos = [];
            try {
                if (triviax_db_available()) {
                    $rows = triviax_db()->query(
                        "SELECT p.id, p.title, p.nivel, COUNT(d.id) AS preguntas
                         FROM proyectos p
                         JOIN desafios d ON d.proyecto_id = p.id
                              AND d.tipo IN ('multiple_choice','true_false')
                         WHERE p.in_trash = 0
                         GROUP BY p.id, p.title, p.nivel
                         HAVING preguntas >= 4
                         ORDER BY p.title"
                    )->fetchAll(PDO::FETCH_ASSOC);
                    triviax_session_start();
                    $bancos = triviax_filter_catalog(triviax_usuario_actual(), 'proyecto', $rows,
                        static function ($r) { return (string)$r['id']; });
                }
            } catch (Throwable $e) {
                $bancos = [];
            }
            triviax_api_success(['projects' => $bancos]);
            break;
        }

        case 'football_start': {
            triviax_api_require_post();
            triviax_verify_csrf_json();
            $input = triviax_api_input();

            // Banco de preguntas elegido por los jugadores (vacío = demo).
            $projectSlug = trim((string)($input['project'] ?? ''));
            if ($projectSlug !== '') {
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectSlug)) {
                    triviax_api_error('VALIDATION_ERROR', 'Actividad no valida.', 422);
                }
                // v7.0: la política del proyecto también gobierna la cancha.
                triviax_session_start();
                $acc = triviax_can_start_activity(triviax_usuario_actual(), 'proyecto', $projectSlug,
                    trim((string)($input['codigo'] ?? '')) !== '' ? trim((string)$input['codigo']) : null);
                if (!$acc['ok']) {
                    triviax_api_error('ACCESS_DENIED', $acc['message'], 403, ['motivo' => $acc['reason']]);
                }
                $preguntas = _football_questions(['project_slug' => $projectSlug]);
                // Si cayó al fixture demo es que el proyecto no tiene preguntas jugables.
                if (isset($preguntas['normal']) || count($preguntas) < 4) {
                    triviax_api_error('VALIDATION_ERROR',
                        'La actividad elegida no tiene suficientes preguntas de opción múltiple o verdadero/falso (mínimo 4).', 422);
                }
            }

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
                'project_slug' => $projectSlug,
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
                // take_question registra la pregunta como usada en el estado
                // para no repetirla hasta agotar el banco.
                $pregunta = triviax_football_take_question($entry['state'], _football_questions($entry), 'normal');
                $entry['state'] = triviax_football_roll($entry['state'], $pregunta);
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
                $entry['state'] = triviax_football_submit($entry['state'], $input['answer'] ?? '', _football_questions($entry));
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
