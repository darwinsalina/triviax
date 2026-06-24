<?php
/** Verifica el contrato estructurado usado por el editor docente. */
require_once __DIR__ . '/../php/challenge_validator.php';

$pass = 0;
$fail = 0;
function validator_check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$project = [
    'metadata' => ['title' => 'Demo'],
    'board' => ['type' => 'serpentine'],
    'challenges' => [[
        'id' => 'q_sin_respuesta',
        'type' => 'multiple_choice',
        'prompt' => ['text' => 'Pregunta'],
        'options' => [
            ['text' => 'A', 'correct' => false],
            ['text' => 'B', 'correct' => false],
            ['text' => 'C', 'correct' => false],
        ],
    ]],
];

$detailed = triviax_validate_project_detailed($project);
validator_check('localiza el desafío inválido por índice', count($detailed['challenges']) === 1 && $detailed['challenges'][0]['index'] === 0);
validator_check('conserva el id del desafío', $detailed['challenges'][0]['id'] === 'q_sin_respuesta');
validator_check('explica la respuesta faltante', str_contains(implode(' ', $detailed['challenges'][0]['errors']), 'respuesta correcta'));

$project['challenges'][0]['options'][1]['correct'] = true;
$valid = triviax_validate_project_detailed($project);
validator_check('sin errores tras marcar respuesta', $valid['general'] === [] && $valid['challenges'] === []);

$project['challenges'] = ['texto no válido'];
$malformed = triviax_validate_project_detailed($project);
validator_check('desafío no-objeto se informa sin error fatal', str_contains($malformed['challenges'][0]['errors'][0], 'debe ser un objeto'));

echo PHP_EOL . "== challenge_validator: {$pass} OK, {$fail} FAIL ==" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
