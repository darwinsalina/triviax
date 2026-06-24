<?php
/**
 * Test unitario (SIN BD) del mapeo de desafíos para el importador #7.
 *   php tests/project_import_test.php
 *
 * Verifica triviax_map_challenge_to_row(): columnas escalares correctas,
 * normalización de tipo al ENUM, clamps y conservación del payload en data_json.
 */

require_once __DIR__ . '/../php/project_import.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

// 1. Opción múltiple básica: columnas escalares y data_json íntegro.
$mc = [
    'id' => 'q1', 'type' => 'multiple_choice',
    'prompt' => ['text' => '¿2 + 2?'],
    'options' => [['text' => 'Cuatro', 'correct' => true], ['text' => 'Tres', 'correct' => false]],
];
$r = triviax_map_challenge_to_row($mc, 0);
check('MC challenge_key', $r['challenge_key'], 'q1');
check('MC tipo', $r['tipo'], 'multiple_choice');
check('MC prompt_text', $r['prompt_text'], '¿2 + 2?');
check('MC difficulty default 1', $r['difficulty'], 1);
check('MC points default 10', $r['points'], 10);
check('MC orden', $r['orden'], 0);
check('MC data_json conserva options', json_decode($r['data_json'], true)['options'][0]['text'], 'Cuatro');
check('MC data_json conserva respuesta correcta', json_decode($r['data_json'], true)['options'][0]['correct'], true);

// La migración debe conservar también objetos answer anidados; son los que el
// editor docente necesita recuperar completos aunque la API del jugador los sanee.
$tfPayload = ['id' => 'tf_payload', 'type' => 'true_false', 'prompt' => ['text' => 'El cielo es azul'], 'answer' => ['value' => true]];
$tfRow = triviax_map_challenge_to_row($tfPayload, 1);
check('data_json conserva answer.value', json_decode($tfRow['data_json'], true)['answer']['value'], true);
check(
    'fingerprint ignora orden de claves JSON',
    triviax_project_challenges_fingerprint([['id' => 'q', 'answer' => ['value' => true]]]),
    triviax_project_challenges_fingerprint([['answer' => ['value' => true], 'id' => 'q']])
);

// 2. Normalización de tipo: classification → drag_drop (valor del ENUM).
$cl = ['id' => 'c1', 'type' => 'classification', 'prompt' => ['text' => 'Clasificá']];
check('classification → drag_drop', triviax_map_challenge_to_row($cl, 1)['tipo'], 'drag_drop');

// 3. Tipo desconocido → fallback multiple_choice.
$unk = ['id' => 'x1', 'type' => 'tipo_inexistente', 'prompt' => ['text' => 'X']];
check('tipo desconocido → multiple_choice', triviax_map_challenge_to_row($unk, 2)['tipo'], 'multiple_choice');

// 4. Clamps: difficulty a [1,3], points a smallint, time_limit.
$hard = ['id' => 'h1', 'type' => 'true_false', 'prompt' => ['text' => 'V/F'],
         'difficulty' => 9, 'points' => 999999, 'time_limit' => 45];
$r = triviax_map_challenge_to_row($hard, 3);
check('difficulty clamp a 3', $r['difficulty'], 3);
check('points clamp a 65535', $r['points'], 65535);
check('time_limit respetado', $r['time_limit'], 45);
check('true_false tipo', $r['tipo'], 'true_false');

// 5. Sin id → challenge_key vacío (el importador lo salta).
check('sin id → key vacío', triviax_map_challenge_to_row(['type' => 'multiple_choice'], 4)['challenge_key'], '');

// 6. title se recorta/normaliza; ausente → null.
check('title ausente → null', triviax_map_challenge_to_row($mc, 0)['title'], null);
check('title presente', triviax_map_challenge_to_row(['id' => 't1', 'type' => 'multiple_choice', 'prompt' => ['text' => 'p'], 'title' => '  Hola  '], 0)['title'], 'Hola');

echo "\n== project_import (mapeo): {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
