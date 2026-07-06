<?php
/**
 * Test unitario (SIN BD) del diagnóstico pedagógico (TRIVIAX+ Épica 4).
 *
 *   php tests/diagnostico_test.php
 */

require_once __DIR__ . '/../php/diagnostico_engine.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

// ── Clasificación individual ─────────────────────────────────────
check('sin intentos → crítica (prioridad de atención)',
    triviax_diagnostico_perfil(['total' => 0, 'correctas' => 0]), 'comprension_critica');
check('40% de acierto → crítica',
    triviax_diagnostico_perfil(['total' => 10, 'correctas' => 4]), 'comprension_critica');
check('67% justo bajo umbral → crítica',
    triviax_diagnostico_perfil(['total' => 100, 'correctas' => 67]), 'comprension_critica');
check('68% sobre umbral → avanzado',
    triviax_diagnostico_perfil(['total' => 100, 'correctas' => 68]), 'dominio_avanzado');
check('90% consistente → avanzado',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 18, 'complejos' => 6, 'complejos_ok' => 5]), 'dominio_avanzado');

// Inconsistencia: bien en general, mal en aplicación
check('80% general pero 33% complejo → inconsistencia',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 16, 'complejos' => 6, 'complejos_ok' => 2]), 'inconsistencia_aplicacion');
check('brecha de 0.25 → inconsistencia',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 15, 'complejos' => 4, 'complejos_ok' => 2]), 'inconsistencia_aplicacion');
check('pocos complejos (<3) no clasifican inconsistencia',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 16, 'complejos' => 2, 'complejos_ok' => 0]), 'dominio_avanzado');
check('acierto bajo con complejos malos → crítica (no inconsistencia)',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 8, 'complejos' => 6, 'complejos_ok' => 1]), 'comprension_critica');
check('complejos perfectos y 85% → avanzado',
    triviax_diagnostico_perfil(['total' => 20, 'correctas' => 17, 'complejos' => 8, 'complejos_ok' => 8]), 'dominio_avanzado');

// ── Agrupamiento ─────────────────────────────────────────────────
$grupos = triviax_diagnostico_clasificar([
    ['nombre' => 'Ana',   'total' => 10, 'correctas' => 3,  'complejos' => 0, 'complejos_ok' => 0],
    ['nombre' => 'Bruno', 'total' => 20, 'correctas' => 16, 'complejos' => 6, 'complejos_ok' => 2],
    ['nombre' => 'Carla', 'total' => 20, 'correctas' => 19, 'complejos' => 6, 'complejos_ok' => 6],
]);
check('tres perfiles siempre presentes', array_keys($grupos), ['comprension_critica', 'inconsistencia_aplicacion', 'dominio_avanzado']);
check('Ana en crítica', $grupos['comprension_critica']['estudiantes'][0]['nombre'] ?? '', 'Ana');
check('Bruno en inconsistencia', $grupos['inconsistencia_aplicacion']['estudiantes'][0]['nombre'] ?? '', 'Bruno');
check('Carla en avanzado', $grupos['dominio_avanzado']['estudiantes'][0]['nombre'] ?? '', 'Carla');
check('acierto calculado (Ana 30%)', $grupos['comprension_critica']['estudiantes'][0]['acierto'] ?? -1, 30);
check('acierto complejo null sin complejos', $grupos['comprension_critica']['estudiantes'][0]['acierto_complejo'], null);
check('acierto complejo de Bruno 33%', $grupos['inconsistencia_aplicacion']['estudiantes'][0]['acierto_complejo'] ?? -1, 33);
check('cada grupo trae info de presentación', isset($grupos['dominio_avanzado']['info']['nombre']), true);

// ── Prompt de refuerzo ───────────────────────────────────────────
$prompt = triviax_diagnostico_prompt_refuerzo('Hardware 7mo', [
    ['challenge_key' => 'mc_004', 'challenge_type' => 'multiple_choice', 'shown' => 12, 'correct' => 3, 'incorrect' => 9, 'acierto' => 25],
], ['comprension_critica' => 2, 'inconsistencia_aplicacion' => 1, 'dominio_avanzado' => 4]);
check('prompt menciona la actividad', str_contains($prompt, 'Hardware 7mo'), true);
check('prompt incluye el desafío débil', str_contains($prompt, 'mc_004'), true);
check('prompt incluye la distribución', str_contains($prompt, '2 estudiantes'), true);
check('prompt pide JSON canónico', str_contains($prompt, '"challenges"'), true);

echo "\n== diagnostico: {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
