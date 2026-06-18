<?php
/**
 * Test unitario (SIN BD ni filesystem) del grader autoritativo de respuestas
 * crudas para TODOS los tipos (#1 Etapa 2): triviax_board_grade_answer().
 *
 *   php tests/board_grade_test.php
 *
 * Sale con código 0 si todo pasa, 1 si no.
 */

require_once __DIR__ . '/../php/board_eval.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

$g = 'triviax_board_grade_answer';

// ── multiple_choice ────────────────────────────────────────────
$mc = ['type' => 'multiple_choice', 'options' => [
    ['id' => 'a', 'text' => 'Cuatro', 'correct' => true],
    ['id' => 'b', 'text' => 'Tres',   'correct' => false],
]];
check('MC optionId correcto', $g($mc, ['optionId' => 'a']), true);
check('MC optionId incorrecto', $g($mc, ['optionId' => 'b']), false);
check('MC optionId inexistente → null', $g($mc, ['optionId' => 'z']), null);
check('MC optionText correcto', $g($mc, ['optionText' => 'Cuatro']), true);
check('MC sin raw útil → null', $g($mc, ['foo' => 'bar']), null);

// media_choice por correctOptionId
$media = ['type' => 'media_choice', 'options' => [['id' => 'x', 'text' => 'X'], ['id' => 'y', 'text' => 'Y']],
          'answer' => ['correctOptionId' => 'y']];
check('MEDIA optionId correcto', $g($media, ['optionId' => 'y']), true);
check('MEDIA optionId incorrecto', $g($media, ['optionId' => 'x']), false);

// ── true_false ─────────────────────────────────────────────────
$tf = ['type' => 'true_false', 'answer' => ['value' => false]];
check('TF acierto (false=false)', $g($tf, ['boolValue' => false]), true);
check('TF fallo (true≠false)', $g($tf, ['boolValue' => true]), false);
check('TF sin boolValue → null', $g($tf, []), null);

// ── sequence_order ─────────────────────────────────────────────
$seq = ['type' => 'sequence_order', 'answer' => ['order' => ['Uno', 'Dos', 'Tres']]];
check('SEQ orden correcto', $g($seq, ['order' => ['Uno', 'Dos', 'Tres']]), true);
check('SEQ orden incorrecto', $g($seq, ['order' => ['Dos', 'Uno', 'Tres']]), false);
check('SEQ longitud distinta', $g($seq, ['order' => ['Uno', 'Dos']]), false);
check('SEQ tolera espacios', $g($seq, ['order' => ['  Uno ', 'Dos', 'Tres ']]), true);

// ── matching_pairs ─────────────────────────────────────────────
$mp = ['type' => 'matching_pairs', 'pairs' => [
    ['left' => 'CPU', 'right' => 'Procesa'],
    ['left' => 'RAM', 'right' => 'Memoria'],
]];
check('MP mapa correcto', $g($mp, ['pairs' => ['CPU' => 'Procesa', 'RAM' => 'Memoria']]), true);
check('MP mapa cruzado', $g($mp, ['pairs' => ['CPU' => 'Memoria', 'RAM' => 'Procesa']]), false);
check('MP incompleto', $g($mp, ['pairs' => ['CPU' => 'Procesa']]), false);
check('MP lista [{left,right}]', $g($mp, ['pairs' => [['left' => 'CPU', 'right' => 'Procesa'], ['left' => 'RAM', 'right' => 'Memoria']]]), true);

// ── drag_drop (classification) ─────────────────────────────────
$cl = ['type' => 'classification', 'items' => [
    ['id' => 'teclado', 'categoryId' => 'hardware'],
    ['id' => 'so',      'categoryId' => 'software'],
]];
check('CLASS correcto', $g($cl, ['placements' => ['teclado' => 'hardware', 'so' => 'software']]), true);
check('CLASS incorrecto', $g($cl, ['placements' => ['teclado' => 'software', 'so' => 'software']]), false);
check('CLASS incompleto', $g($cl, ['placements' => ['teclado' => 'hardware']]), false);

// ── fill_blank (fill_blank_select) ─────────────────────────────
$fb = ['type' => 'fill_blank_select', 'blanks' => [
    'blank1' => ['correct' => 'CPU'],
    'blank2' => ['correct' => 'RAM'],
]];
check('FILL correcto', $g($fb, ['blanks' => ['blank1' => 'CPU', 'blank2' => 'RAM']]), true);
check('FILL un fallo', $g($fb, ['blanks' => ['blank1' => 'CPU', 'blank2' => 'red']]), false);
check('FILL vacío → false', $g($fb, ['blanks' => ['blank1' => 'CPU', 'blank2' => '']]), false);

// ── image_hotspot ──────────────────────────────────────────────
$hs = ['type' => 'image_hotspot', 'answer' => ['hotspot' => ['xMin' => 10, 'xMax' => 40, 'yMin' => 10, 'yMax' => 40]]];
check('HOTSPOT dentro', $g($hs, ['point' => ['x' => 25, 'y' => 25]]), true);
check('HOTSPOT fuera', $g($hs, ['point' => ['x' => 80, 'y' => 25]]), false);
check('HOTSPOT borde', $g($hs, ['point' => ['x' => 40, 'y' => 40]]), true);

// ── code_challenge ─────────────────────────────────────────────
$code = ['type' => 'code_challenge', 'answer' => ['lines' => ['inicio', 'paso', 'fin']]];
check('CODE correcto', $g($code, ['lines' => ['inicio', 'paso', 'fin']]), true);
check('CODE desordenado', $g($code, ['lines' => ['paso', 'inicio', 'fin']]), false);
check('CODE lines en challenge.lines', $g(['type' => 'code_challenge', 'lines' => ['a', 'b']], ['lines' => ['a', 'b']]), true);

// ── salvaguardas ───────────────────────────────────────────────
check('raw vacío → null', $g($mc, []), null);
check('tipo desconocido → null', $g(['type' => 'lo_que_sea'], ['x' => 1]), null);

// ── solución para feedback ─────────────────────────────────────
$s = 'triviax_board_solution_for_client';
check('SOL mc correctText', $s($mc)['correctText'], 'Cuatro');
check('SOL mc correctOptionId', $s($mc)['correctOptionId'], 'a');
check('SOL media correctOptionId', $s($media)['correctOptionId'], 'y');
check('SOL tf value', $s($tf)['value'], false);
check('SOL seq order', $s($seq)['order'], ['Uno', 'Dos', 'Tres']);
check('SOL mp pairs', $s($mp)['pairs'], [['left' => 'CPU', 'right' => 'Procesa'], ['left' => 'RAM', 'right' => 'Memoria']]);
check('SOL class items', count($s($cl)['items']), 2);
check('SOL fill blanks', $s($fb)['blanks'], ['blank1' => 'CPU', 'blank2' => 'RAM']);
check('SOL hotspot', $s($hs)['hotspot']['xMax'], 40);
check('SOL code lines', $s($code)['lines'], ['inicio', 'paso', 'fin']);

// ── saneador para el cliente (6.3c) ────────────────────────────
// Nada de respuestas correctas debe sobrevivir a action=get.
$san = 'triviax_board_sanitize_challenge_for_client';

// multiple_choice: sin 'correct' en opciones ni 'correctOptionId'.
$mcSan = $san($mc);
$mcAnyCorrect = false;
foreach ($mcSan['options'] as $o) { if (array_key_exists('correct', $o)) { $mcAnyCorrect = true; } }
check('SAN mc sin opt.correct', $mcAnyCorrect, false);
check('SAN mc conserva opciones', count($mcSan['options']), 2);
check('SAN media sin correctOptionId', isset($san($media)['answer']['correctOptionId']), false);

// true_false: sin answer.value (y answer vacío se elimina).
check('SAN tf sin answer.value', isset($san($tf)['answer']), false);

// sequence_order: sin answer.order.
check('SAN seq sin answer.order', isset($san($seq)['answer']['order']), false);

// matching_pairs: desacoplado (la asociación left↔right ya no es la correcta).
$mpSan = $san($mp);
$mpCoupled = true;
foreach ($mpSan['pairs'] as $i => $p) { if (($p['right'] ?? null) !== ($mp['pairs'][$i]['right'] ?? null)) { $mpCoupled = false; } }
check('SAN mp desacoplado', $mpCoupled, false);
check('SAN mp conserva los rights', (function() use ($mpSan, $mp) {
    $a = array_map(fn($p) => $p['right'], $mpSan['pairs']); sort($a);
    $b = array_map(fn($p) => $p['right'], $mp['pairs']);    sort($b);
    return $a === $b;
})(), true);

// classification: sin categoryId en items.
$clSan = $san($cl);
$clAnyCat = false;
foreach ($clSan['items'] as $it) { if (array_key_exists('categoryId', $it)) { $clAnyCat = true; } }
check('SAN class sin categoryId', $clAnyCat, false);
check('SAN class conserva items', count($clSan['items']), 2);

// fill_blank: sin 'correct' en blanks.
$fbSan = $san($fb);
$fbAnyCorrect = false;
foreach ($fbSan['blanks'] as $b) { if (array_key_exists('correct', $b)) { $fbAnyCorrect = true; } }
check('SAN fill sin blank.correct', $fbAnyCorrect, false);

// image_hotspot: sin answer.hotspot.
check('SAN hotspot sin answer.hotspot', isset($san($hs)['answer']['hotspot']), false);

// code_challenge: sin answer.lines.
check('SAN code sin answer.lines', isset($san($code)['answer']['lines']), false);

// El saneador NO altera el grader autoritativo (recibe el desafío ORIGINAL).
check('SAN no rompe grader mc', $g($mc, ['optionId' => 'a']), true);
check('SAN no rompe grader mp', $g($mp, ['pairs' => ['CPU' => 'Procesa', 'RAM' => 'Memoria']]), true);

echo "\n== board_grade: {$pass} OK, {$fail} FAIL ==\n";
exit($fail > 0 ? 1 : 0);
