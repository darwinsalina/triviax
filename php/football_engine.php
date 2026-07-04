<?php
/**
 * TRIVIAX Futbol - Camino al Gol.
 *
 * Motor de reglas puro para la modalidad football_goal_race. No depende de
 * base de datos ni de sesion HTTP; las APIs lo usan como fuente autoritativa.
 */

const TRIVIAX_FOOTBALL_MODE = 'football_goal_race';
const TRIVIAX_FOOTBALL_GOAL = 30;
const TRIVIAX_FOOTBALL_BACK_AFTER_MISS = 28;

function triviax_football_default_special_cells(): array {
    $cells = [
        7  => ['color' => 'yellow', 'type' => 'wall_pass', 'label' => 'Pared'],
        14 => ['color' => 'yellow', 'type' => 'wall_pass', 'label' => 'Pared'],
        15 => ['color' => 'black',  'type' => 'var_risk', 'label' => 'VAR'],
        19 => ['color' => 'orange', 'type' => 'long_pass', 'label' => 'Pase largo'],
        24 => ['color' => 'purple', 'type' => 'free_kick', 'label' => 'Tiro libre'],
    ];
    return ['blue' => $cells, 'red' => $cells];
}

function triviax_football_default_path(string $side): array {
    $points = [];
    for ($n = 0; $n <= TRIVIAX_FOOTBALL_GOAL; $n++) {
        $t = $n / TRIVIAX_FOOTBALL_GOAL;
        $x = $side === 'blue' ? 50 - (42 * $t) : 50 + (42 * $t);
        $wave = sin($t * M_PI * 2) * 9;
        $y = 50 + ($side === 'blue' ? $wave : -$wave);
        if ($n === 0) {
            $x = 50.0;
            $y = $side === 'blue' ? 47.0 : 53.0;
        }
        if ($n === TRIVIAX_FOOTBALL_GOAL) {
            $x = $side === 'blue' ? 8.0 : 92.0;
            $y = 50.0;
        }
        $points[] = ['n' => $n, 'x' => round($x, 2), 'y' => round($y, 2)];
    }
    return $points;
}

function triviax_football_default_board(): array {
    if (function_exists('triviax_db')) {
        try {
            $pdo = triviax_db();
            $stmt = $pdo->prepare('SELECT config_json FROM football_boards WHERE board_key = ? AND enabled = 1 LIMIT 1');
            $stmt->execute(['football_pitch_30_v1']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $board = json_decode($row['config_json'], true);
                if (is_array($board)) {
                    return $board;
                }
            }
        } catch (Throwable $e) {
            // Silencioso
        }
    }

    $jsonPath = __DIR__ . '/../images/tableros/football_pitch_30_v1.json';
    if (is_file($jsonPath)) {
        $board = json_decode((string)file_get_contents($jsonPath), true);
        if (is_array($board)) {
            return $board;
        }
    }

    return [
        'id' => 'football_pitch_30_v1',
        'label' => 'Cancha de futbol - Camino al Gol',
        'type' => TRIVIAX_FOOTBALL_MODE,
        'version' => '1.0',
        'image' => 'images/cancha.png',
        'size' => ['width' => 1672, 'height' => 941],
        'goalPosition' => TRIVIAX_FOOTBALL_GOAL,
        'paths' => [
            'blue' => triviax_football_default_path('blue'),
            'red' => triviax_football_default_path('red'),
        ],
        'specialCells' => triviax_football_default_special_cells(),
    ];
}


function triviax_football_create_state(array $options = []): array {
    $blueMembers = triviax_football_clean_members($options['teams']['blue'] ?? ['Azul']);
    $redMembers = triviax_football_clean_members($options['teams']['red'] ?? ['Rojo']);
    return [
        'mode' => TRIVIAX_FOOTBALL_MODE,
        'status' => 'playing',
        'currentSide' => 'blue',
        'turnNumber' => 1,
        'diceSides' => (int)($options['diceSides'] ?? 6) === 4 ? 4 : 6,
        'goalPosition' => TRIVIAX_FOOTBALL_GOAL,
        'wrongFinalShotBackTo' => TRIVIAX_FOOTBALL_BACK_AFTER_MISS,
        'requireFinalShotQuestion' => true,
        'positions' => ['blue' => 0, 'red' => 0],
        'scores' => ['blue' => 0, 'red' => 0],
        'pendingAction' => null,
        'usedQuestionIds' => [],
        'winner' => null,
        'winner_side' => null,
        'teamAnswerMode' => triviax_football_answer_mode($options['teamAnswerMode'] ?? 'short_team_help'),
        'teams' => [
            'blue' => triviax_football_team_state($blueMembers),
            'red' => triviax_football_team_state($redMembers),
        ],
        'events' => [],
    ];
}

function triviax_football_clean_members(array $members): array {
    $out = [];
    foreach ($members as $member) {
        $name = trim((string)$member);
        if ($name !== '') {
            $out[] = mb_substr($name, 0, 80, 'UTF-8');
        }
        if (count($out) >= 4) {
            break;
        }
    }
    return $out ?: ['Equipo'];
}

function triviax_football_team_state(array $members): array {
    $players = [];
    foreach ($members as $i => $name) {
        $players[] = [
            'display_name' => $name,
            'rotation_order' => $i + 1,
            'turns_answered' => 0,
            'correct_answers' => 0,
            'incorrect_answers' => 0,
        ];
    }
    return ['members' => $players, 'currentIndex' => 0];
}

function triviax_football_answer_mode(string $mode): string {
    return in_array($mode, ['no_help', 'short_team_help', 'consensus'], true)
        ? $mode : 'short_team_help';
}

function triviax_football_current_member(array $state, string $side): ?array {
    $team = $state['teams'][$side] ?? null;
    if (!$team || empty($team['members'])) {
        return null;
    }
    $idx = (int)($team['currentIndex'] ?? 0);
    return $team['members'][$idx] ?? $team['members'][0];
}

function triviax_football_roll(array $state, array $question, ?int $forcedDice = null): array {
    triviax_football_assert_playing($state);
    if (!empty($state['pendingAction'])) {
        throw new RuntimeException('Ya hay una accion pendiente.');
    }
    $diceSides = (int)($state['diceSides'] ?? 6);
    $dice = $forcedDice !== null ? max(1, min($diceSides, $forcedDice)) : random_int(1, $diceSides);
    $side = $state['currentSide'];
    $state['pendingAction'] = [
        'type' => 'normal_question',
        'side' => $side,
        'dice' => $dice,
        'questionId' => $question['id'] ?? null,
        'question' => $question,
        'member' => triviax_football_current_member($state, $side),
    ];
    $state['lastDice'] = $dice;
    $state['events'][] = triviax_football_event($state, $side, 'dice_rolled', [
        'dice_value' => $dice,
        'from_position' => $state['positions'][$side],
        'to_position' => $state['positions'][$side],
        'question_id' => $question['id'] ?? null,
    ]);
    return $state;
}

function triviax_football_submit(array $state, $answer, array $questionProvider): array {
    triviax_football_assert_playing($state);
    $pending = $state['pendingAction'] ?? null;
    if (!$pending) {
        throw new RuntimeException('No hay una pregunta pendiente.');
    }
    $side = $pending['side'];
    $question = $pending['question'] ?? null;
    $correct = triviax_football_answer_is_correct($question, $answer);
    $from = (int)$state['positions'][$side];
    $points = 0;
    $eventType = $pending['type'];

    if ($pending['type'] === 'normal_question') {
        if ($correct) {
            $points = 10;
            $state['scores'][$side] += $points;
            $state['positions'][$side] = min(TRIVIAX_FOOTBALL_GOAL, $from + (int)$pending['dice']);
        }
        triviax_football_mark_member_answer($state, $side, $correct);
        $state['events'][] = triviax_football_event($state, $side, $eventType, [
            'question_id' => $pending['questionId'] ?? null,
            'answer_is_correct' => $correct,
            'dice_value' => $pending['dice'] ?? null,
            'from_position' => $from,
            'to_position' => $state['positions'][$side],
            'points_delta' => $points,
        ]);
        $state['pendingAction'] = null;
        return triviax_football_after_movement($state, $side, $questionProvider);
    }

    if ($pending['type'] === 'special_question') {
        $delta = triviax_football_special_delta((string)$pending['specialType'], $correct);
        $points = $correct ? 15 : triviax_football_special_penalty((string)$pending['specialType']);
        $state['scores'][$side] += $points;
        $state['positions'][$side] = max(0, min(TRIVIAX_FOOTBALL_GOAL, $from + $delta));
        triviax_football_mark_member_answer($state, $side, $correct);
        $state['events'][] = triviax_football_event($state, $side, $eventType, [
            'question_id' => $pending['questionId'] ?? null,
            'answer_is_correct' => $correct,
            'special_type' => $pending['specialType'] ?? null,
            'from_position' => $from,
            'to_position' => $state['positions'][$side],
            'points_delta' => $points,
        ]);
        $state['pendingAction'] = null;
        return triviax_football_after_movement($state, $side, $questionProvider);
    }

    if ($pending['type'] === 'final_shot') {
        triviax_football_mark_member_answer($state, $side, $correct);
        if ($correct) {
            $points = 30;
            $state['scores'][$side] += $points;
            $state['status'] = 'finished';
            $state['winner'] = $side;
            $state['winner_side'] = $side;
        } else {
            $state['positions'][$side] = TRIVIAX_FOOTBALL_BACK_AFTER_MISS;
        }
        $state['events'][] = triviax_football_event($state, $side, $eventType, [
            'question_id' => $pending['questionId'] ?? null,
            'answer_is_correct' => $correct,
            'from_position' => $from,
            'to_position' => $state['positions'][$side],
            'points_delta' => $points,
        ]);
        $state['pendingAction'] = null;
        if (!$correct) {
            triviax_football_switch_side($state);
        }
        return $state;
    }

    throw new RuntimeException('Accion pendiente desconocida.');
}

function triviax_football_after_movement(array $state, string $side, array $questionProvider): array {
    $position = (int)$state['positions'][$side];
    if ($position >= TRIVIAX_FOOTBALL_GOAL) {
        $q = triviax_football_take_question($state, $questionProvider, 'final');
        $state['pendingAction'] = [
            'type' => 'final_shot',
            'side' => $side,
            'questionId' => $q['id'] ?? null,
            'question' => $q,
            'member' => triviax_football_current_member($state, $side),
        ];
        $state['events'][] = triviax_football_event($state, $side, 'final_shot_pending', [
            'question_id' => $q['id'] ?? null,
            'from_position' => $position,
            'to_position' => $position,
        ]);
        return $state;
    }

    $special = triviax_football_default_special_cells()[$side][$position] ?? null;
    if ($special) {
        $q = triviax_football_take_question($state, $questionProvider, 'special', $special['type']);
        $state['pendingAction'] = [
            'type' => 'special_question',
            'side' => $side,
            'specialType' => $special['type'],
            'specialLabel' => $special['label'],
            'questionId' => $q['id'] ?? null,
            'question' => $q,
            'member' => triviax_football_current_member($state, $side),
        ];
        return $state;
    }

    triviax_football_switch_side($state);
    return $state;
}

/**
 * Selecciona el pool de preguntas para un tipo de jugada. El proveedor
 * puede venir agrupado ({normal, special, final, <specialType>}) como el
 * fixture demo, o ser una lista plana (bancos de proyectos adaptados).
 */
function triviax_football_question_pool(array $provider, string $kind, ?string $specialType = null): array {
    if ($specialType && isset($provider[$specialType]) && is_array($provider[$specialType]) && $provider[$specialType]) {
        return array_values($provider[$specialType]);
    }
    if (isset($provider[$kind]) && is_array($provider[$kind]) && $provider[$kind]) {
        return array_values($provider[$kind]);
    }
    return array_values($provider['normal'] ?? $provider);
}

function triviax_football_pick_question(array $provider, string $kind, ?string $specialType = null): array {
    $pool = triviax_football_question_pool($provider, $kind, $specialType);
    return $pool[array_rand($pool)];
}

/**
 * Toma una pregunta SIN repetir: excluye las ya usadas en la partida
 * (state.usedQuestionIds). Cuando el pool se agota, libera solo las ids
 * de ese pool y empieza un nuevo ciclo, así ningún banco chico corta el
 * juego y ninguna pregunta se repite antes de agotar las demás.
 */
function triviax_football_take_question(array &$state, array $provider, string $kind, ?string $specialType = null): array {
    $pool = triviax_football_question_pool($provider, $kind, $specialType);
    if (!$pool) {
        throw new RuntimeException('El banco de preguntas esta vacio.');
    }
    $usadas = array_map('strval', $state['usedQuestionIds'] ?? []);
    $flip = array_flip($usadas);
    $frescas = array_values(array_filter($pool, static function ($q) use ($flip) {
        $id = isset($q['id']) ? (string)$q['id'] : '';
        return $id === '' || !isset($flip[$id]);
    }));
    if (!$frescas) {
        // Ciclo completo de este pool: liberar sus ids y volver a empezar.
        $poolIds = [];
        foreach ($pool as $q) {
            if (isset($q['id'])) {
                $poolIds[(string)$q['id']] = true;
            }
        }
        $state['usedQuestionIds'] = array_values(array_filter($usadas, static function ($id) use ($poolIds) {
            return !isset($poolIds[$id]);
        }));
        $frescas = $pool;
    }
    $q = $frescas[array_rand($frescas)];
    if (isset($q['id']) && (string)$q['id'] !== '') {
        $state['usedQuestionIds'][] = (string)$q['id'];
    }
    return $q;
}

function triviax_football_special_delta(string $type, bool $correct): int {
    if ($correct) {
        return ['wall_pass' => 2, 'long_pass' => 3, 'var_risk' => 3, 'free_kick' => 2][$type] ?? 0;
    }
    return ['wall_pass' => 0, 'long_pass' => -1, 'var_risk' => -3, 'free_kick' => 0][$type] ?? 0;
}

function triviax_football_special_penalty(string $type): int {
    return $type === 'var_risk' ? -5 : 0;
}

function triviax_football_answer_is_correct(?array $question, $answer): bool {
    if (!$question) {
        return false;
    }
    $expected = $question['correct'] ?? $question['answer'] ?? null;
    if (is_array($expected)) {
        return in_array((string)$answer, array_map('strval', $expected), true);
    }
    return mb_strtolower(trim((string)$answer), 'UTF-8') === mb_strtolower(trim((string)$expected), 'UTF-8');
}

function triviax_football_mark_member_answer(array &$state, string $side, bool $correct): void {
    if (empty($state['teams'][$side]['members'])) {
        return;
    }
    $idx = (int)($state['teams'][$side]['currentIndex'] ?? 0);
    if (!isset($state['teams'][$side]['members'][$idx])) {
        $idx = 0;
    }
    $state['teams'][$side]['members'][$idx]['turns_answered']++;
    if ($correct) {
        $state['teams'][$side]['members'][$idx]['correct_answers']++;
    } else {
        $state['teams'][$side]['members'][$idx]['incorrect_answers']++;
    }
    $count = count($state['teams'][$side]['members']);
    $state['teams'][$side]['currentIndex'] = ($idx + 1) % max(1, $count);
}

function triviax_football_switch_side(array &$state): void {
    $state['currentSide'] = $state['currentSide'] === 'blue' ? 'red' : 'blue';
    $state['turnNumber'] = (int)$state['turnNumber'] + 1;
}

function triviax_football_event(array $state, string $side, string $type, array $data = []): array {
    return array_merge([
        'turn_number' => (int)($state['turnNumber'] ?? 1),
        'side' => $side,
        'event_type' => $type,
        'created_at' => date('c'),
    ], $data);
}

function triviax_football_assert_playing(array $state): void {
    if (($state['mode'] ?? '') !== TRIVIAX_FOOTBALL_MODE) {
        throw new RuntimeException('Estado de partida invalido.');
    }
    if (($state['status'] ?? '') !== 'playing') {
        throw new RuntimeException('La partida no esta en juego.');
    }
}

function triviax_football_public_question(?array $question): ?array {
    if (!$question) {
        return null;
    }
    $out = $question;
    unset($out['correct'], $out['answer']);
    return $out;
}

function triviax_football_public_state(array $state): array {
    if (!empty($state['pendingAction']['question'])) {
        $state['pendingAction']['question'] = triviax_football_public_question($state['pendingAction']['question']);
    }
    return $state;
}
