<?php
/**
 * Test unitario (SIN BD) de la evaluación autoritativa del tablero (#1).
 *
 *   php tests/board_eval_test.php
 *
 * Crea un proyecto temporal en proyectos/__test_board_eval__/ y ejercita
 * triviax_board_* sin tocar MySQL. Sale con código 0 si todo pasa, 1 si no.
 */

require_once __DIR__ . '/../php/board_eval.php';

$baseProjectsDir = realpath(__DIR__ . '/../proyectos');
if ($baseProjectsDir === false) {
    fwrite(STDERR, "No se encontró la carpeta proyectos/.\n");
    exit(1);
}
$slug = '__test_board_eval__';
$dir  = $baseProjectsDir . '/' . $slug;
@mkdir($dir, 0777, true);

$project = [
    'metadata'   => ['title' => 'Test Board Eval'],
    'board'      => ['type' => 'serpentine'],
    'challenges' => [
        [
            'id'      => 'q1',
            'type'    => 'multiple_choice',
            'prompt'  => ['text' => '¿2 + 2?'],
            'options' => [
                ['id' => 'a', 'text' => 'Cuatro', 'correct' => true],
                ['id' => 'b', 'text' => 'Tres',   'correct' => false],
                ['id' => 'c', 'text' => 'Cinco',  'correct' => false],
            ],
        ],
        [
            'id'     => 'q2',
            'type'   => 'matching_pairs', // tipo estructurado → fallback (no se toca veredicto)
            'prompt' => ['text' => 'Asociá'],
            'pairs'  => [
                ['left' => 'A', 'right' => '1'],
                ['left' => 'B', 'right' => '2'],
            ],
        ],
    ],
];
file_put_contents($dir . '/proyecto.json', json_encode($project, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

$B = $baseProjectsDir;

// 1. Cliente dice "correct" y eligió la opción correcta → se mantiene correct.
$r = triviax_board_authoritative_result($B, $slug, 'q1', 'multiple_choice', 'correct', 10, 'Cuatro');
check('MC correcto legítimo se mantiene', [$r['resultado'], $r['overridden']], ['correct', false]);

// 2. Cliente dice "correct" pero eligió una opción INCORRECTA → degradado.
$r = triviax_board_authoritative_result($B, $slug, 'q1', 'multiple_choice', 'correct', 15, 'Cinco');
check('MC falso-correct degradado a incorrect', [$r['resultado'], $r['overridden'], $r['points_delta']], ['incorrect', true, 0]);

// 3. selectedText que no empareja con ninguna opción → fallback (respeta cliente).
$r = triviax_board_authoritative_result($B, $slug, 'q1', 'multiple_choice', 'correct', 10, 'texto raro');
check('MC sin emparejar respeta al cliente', [$r['resultado'], $r['overridden']], ['correct', false]);

// 4. Clamp de puntos arbitrarios en respuesta correcta legítima (techo 15 dif=1).
$r = triviax_board_authoritative_result($B, $slug, 'q1', 'multiple_choice', 'correct', 99999, 'Cuatro');
check('Clamp de puntos correctos a techo', $r['points_delta'], 15);

// 5. Tipo estructurado → no se toca el veredicto (fallback), pero sí clamp.
$r = triviax_board_authoritative_result($B, $slug, 'q2', 'matching_pairs', 'correct', 99999, 'lo que sea');
check('Estructurado: veredicto respetado', $r['resultado'], 'correct');
check('Estructurado: puntos clampeados', $r['points_delta'], 15);

// 6. Incorrecto: puntos acotados a [-5, 0].
$r = triviax_board_authoritative_result($B, $slug, 'q1', 'multiple_choice', 'incorrect', -9999, 'Tres');
check('Incorrecto: clamp a -5', $r['points_delta'], -5);

// 7. Desafío inexistente → fallback total (respeta cliente + clamp).
$r = triviax_board_authoritative_result($B, $slug, 'noexiste', 'multiple_choice', 'correct', 12, 'X');
check('Desafío inexistente respeta al cliente', [$r['resultado'], $r['overridden']], ['correct', false]);

// 8. Slug con path traversal → null en find_challenge.
check('find_challenge rechaza traversal', triviax_board_find_challenge($B, '../etc', 'q1'), null);

// Limpieza
@unlink($dir . '/proyecto.json');
@rmdir($dir);

echo "\n== board_eval: {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
