<?php
/**
 * Pruebas sin BD de la modalidad "Hacia el Gol":
 *  - adaptador de desafíos del tablero al formato de la cancha
 *  - selección de preguntas sin repetición (usedQuestionIds)
 */
require_once __DIR__ . '/../php/football_api.php';

$pass = 0;
$fail = 0;
function football_check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

// ── Adaptador de desafíos ────────────────────────────────────
$challenges = [
    [
        'id' => 'ch_01', 'type' => 'multiple_choice',
        'prompt' => ['text' => '¿Cuántos bits tiene un byte?'],
        'options' => [
            ['text' => '2 bits', 'correct' => false],
            ['text' => '8 bits', 'correct' => true],
            ['text' => '1000 bits', 'correct' => false],
        ],
    ],
    [
        'id' => 'ch_02', 'type' => 'true_false',
        'prompt' => ['text' => 'La RAM es memoria permanente.'],
        'answer' => ['value' => false],
    ],
    // Tipos no jugables en la cancha: se omiten
    ['id' => 'ch_03', 'type' => 'fill_blank', 'prompt' => ['text' => 'Completa: ___'], 'answer' => ['value' => 'x']],
    ['id' => 'ch_04', 'type' => 'drag_drop', 'prompt' => ['text' => 'Arrastra']],
    // multiple_choice sin respuesta correcta: se omite
    ['id' => 'ch_05', 'type' => 'multiple_choice', 'prompt' => ['text' => 'Sin correcta'],
     'options' => [['text' => 'A', 'correct' => false], ['text' => 'B', 'correct' => false]]],
];
$adapted = _football_adapt_challenges($challenges);
football_check('Adapta solo los tipos jugables (2 de 5)', count($adapted) === 2);
football_check('multiple_choice: opciones como strings',
    $adapted[0]['options'] === ['2 bits', '8 bits', '1000 bits']);
football_check('multiple_choice: correcta como texto', $adapted[0]['correct'] === '8 bits');
football_check('multiple_choice: prompt plano', $adapted[0]['prompt'] === '¿Cuántos bits tiene un byte?');
football_check('true_false: opciones Verdadero/Falso', $adapted[1]['options'] === ['Verdadero', 'Falso']);
football_check('true_false: falso mapea a "Falso"', $adapted[1]['correct'] === 'Falso');
football_check('Conserva los ids originales', $adapted[0]['id'] === 'ch_01' && $adapted[1]['id'] === 'ch_02');

// El motor valida la respuesta adaptada
football_check('El motor acepta la respuesta correcta adaptada',
    triviax_football_answer_is_correct($adapted[0], '8 bits'));
football_check('El motor rechaza una respuesta incorrecta',
    !triviax_football_answer_is_correct($adapted[0], '2 bits'));

// ── Rotación sin repetición ──────────────────────────────────
$pool = [];
for ($i = 1; $i <= 5; $i++) {
    $pool[] = ['id' => "q{$i}", 'prompt' => "P{$i}", 'options' => ['A', 'B'], 'correct' => 'A'];
}
$state = triviax_football_create_state();

$vistas = [];
for ($i = 0; $i < 5; $i++) {
    $q = triviax_football_take_question($state, $pool, 'normal');
    $vistas[] = $q['id'];
}
football_check('Cinco tomas cubren las cinco preguntas sin repetir',
    count(array_unique($vistas)) === 5);
football_check('El estado registra las usadas', count($state['usedQuestionIds']) === 5);

// Sexta toma: el pool se agotó → nuevo ciclo (vuelve a haber pregunta)
$q6 = triviax_football_take_question($state, $pool, 'normal');
football_check('Al agotar el banco arranca un nuevo ciclo', in_array($q6['id'], ['q1','q2','q3','q4','q5'], true));
football_check('El nuevo ciclo reinicia el registro de usadas', count($state['usedQuestionIds']) === 1);

// Proveedor agrupado (fixture demo): grupos normal/final independientes
$provider = [
    'normal' => [['id' => 'n1', 'prompt' => 'N1', 'options' => ['A','B'], 'correct' => 'A']],
    'final' => [['id' => 'f1', 'prompt' => 'F1', 'options' => ['A','B'], 'correct' => 'A']],
];
$state2 = triviax_football_create_state();
$qa = triviax_football_take_question($state2, $provider, 'normal');
$qb = triviax_football_take_question($state2, $provider, 'final');
football_check('Proveedor agrupado: normal y final usan su propio pool',
    $qa['id'] === 'n1' && $qb['id'] === 'f1');

// Pool vacío lanza excepción clara
$lanzo = false;
try {
    $s = triviax_football_create_state();
    triviax_football_take_question($s, [], 'normal');
} catch (RuntimeException $e) {
    $lanzo = true;
}
football_check('Banco vacío lanza excepción', $lanzo);

echo PHP_EOL . "== football_questions: {$pass} OK, {$fail} FAIL ==" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
