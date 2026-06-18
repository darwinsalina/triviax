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

echo "\n== board_grade: {$pass} OK, {$fail} FAIL ==\n";
exit($fail > 0 ? 1 : 0);
