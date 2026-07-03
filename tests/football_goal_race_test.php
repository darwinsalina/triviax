<?php
require_once __DIR__ . '/../php/football_engine.php';
require_once __DIR__ . '/../php/football_validator.php';

$pass = 0;
$fail = 0;
function football_check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$qOk = ['id' => 'ok', 'prompt' => 'Demo', 'options' => ['A', 'B'], 'correct' => 'A'];
$qNo = ['id' => 'no', 'prompt' => 'Demo', 'options' => ['A', 'B'], 'correct' => 'B'];
$provider = ['normal' => [$qOk], 'special' => [$qOk], 'final' => [$qOk]];

$board = triviax_football_default_board();
football_check('Tablero default valido', triviax_football_validate_board($board) === []);
football_check('Crear partida en modo correcto', triviax_football_create_state()['mode'] === TRIVIAX_FOOTBALL_MODE);

$state = triviax_football_create_state(['teams' => ['blue' => ['Ana', 'Luis'], 'red' => ['Eva', 'Tomi']]]);
football_check('Blue y red inician en 0', $state['positions']['blue'] === 0 && $state['positions']['red'] === 0);

$state = triviax_football_roll($state, $qOk, 4);
$state = triviax_football_submit($state, 'A', $provider);
football_check('Turno azul correcto avanza segun dado', $state['positions']['blue'] === 4);
football_check('Despues de turno normal pasa a red', $state['currentSide'] === 'red');

$state = triviax_football_roll($state, $qOk, 5);
$state = triviax_football_submit($state, 'B', $provider);
football_check('Turno rojo incorrecto no avanza', $state['positions']['red'] === 0);

$state = triviax_football_create_state();
$state['positions']['blue'] = 3;
$state = triviax_football_roll($state, $qOk, 4);
$state = triviax_football_submit($state, 'A', $provider);
football_check('Caer en amarilla activa Pared', ($state['pendingAction']['specialType'] ?? '') === 'wall_pass');
$state = triviax_football_submit($state, 'A', $provider);
football_check('Pared correcta avanza 2', $state['positions']['blue'] === 9);

$state = triviax_football_create_state();
$state['positions']['blue'] = 19;
$state['pendingAction'] = ['type' => 'special_question', 'side' => 'blue', 'specialType' => 'long_pass', 'question' => $qOk, 'questionId' => 'ok'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('Pase largo correcto avanza 3', $state['positions']['blue'] === 22);

$state = triviax_football_create_state();
$state['positions']['blue'] = 19;
$state['pendingAction'] = ['type' => 'special_question', 'side' => 'blue', 'specialType' => 'long_pass', 'question' => $qNo, 'questionId' => 'no'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('Pase largo incorrecto retrocede 1', $state['positions']['blue'] === 18);

$state = triviax_football_create_state();
$state['positions']['blue'] = 15;
$state['pendingAction'] = ['type' => 'special_question', 'side' => 'blue', 'specialType' => 'var_risk', 'question' => $qOk, 'questionId' => 'ok'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('VAR correcto avanza 3', $state['positions']['blue'] === 18);

$state = triviax_football_create_state();
$state['positions']['blue'] = 15;
$state['pendingAction'] = ['type' => 'special_question', 'side' => 'blue', 'specialType' => 'var_risk', 'question' => $qNo, 'questionId' => 'no'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('VAR incorrecto retrocede 3', $state['positions']['blue'] === 12);
football_check('VAR incorrecto puede penalizar puntaje', $state['scores']['blue'] === -5);

$state = triviax_football_create_state();
$state['positions']['blue'] = 24;
$state['pendingAction'] = ['type' => 'special_question', 'side' => 'blue', 'specialType' => 'free_kick', 'question' => $qOk, 'questionId' => 'ok'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('Tiro libre correcto avanza 2', $state['positions']['blue'] === 26);

$state = triviax_football_create_state();
$state['positions']['blue'] = 28;
$state = triviax_football_roll($state, $qOk, 4);
$state = triviax_football_submit($state, 'A', $provider);
football_check('Llegar a 30 activa tiro al arco', ($state['pendingAction']['type'] ?? '') === 'final_shot');

$win = triviax_football_submit($state, 'A', $provider);
football_check('Tiro final correcto termina partida', $win['status'] === 'finished' && $win['winner_side'] === 'blue');

$state = triviax_football_create_state();
$state['positions']['blue'] = 30;
$state['pendingAction'] = ['type' => 'final_shot', 'side' => 'blue', 'question' => $qNo, 'questionId' => 'no'];
$state = triviax_football_submit($state, 'A', $provider);
football_check('Tiro final incorrecto vuelve a 28', $state['positions']['blue'] === 28);
football_check('Tiro final incorrecto pasa al rival', $state['currentSide'] === 'red');

$state = triviax_football_create_state(['teams' => ['blue' => ['Ana', 'Luis'], 'red' => ['Eva']]]);
$state = triviax_football_roll($state, $qOk, 1);
$state = triviax_football_submit($state, 'A', $provider);
$state['currentSide'] = 'blue';
$state = triviax_football_roll($state, $qOk, 1);
$state = triviax_football_submit($state, 'A', $provider);
football_check('En modo equipo rota integrantes', $state['teams']['blue']['members'][0]['turns_answered'] === 1 && $state['teams']['blue']['members'][1]['turns_answered'] === 1);
football_check('Ningun integrante repite antes de completar ronda', $state['teams']['blue']['currentIndex'] === 0);
football_check('Eventos de partida registrados', count($state['events']) >= 4);
$state = triviax_football_roll($state, $qOk, 1);
$public = triviax_football_public_state($state);
football_check('Estado publico no expone respuesta', !isset($public['pendingAction']['question']['correct']));

echo PHP_EOL . "== football_goal_race: {$pass} OK, {$fail} FAIL ==" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
