<?php
/**
 * Test unitario (SIN BD) de los helpers del Modo Tarea (TRIVIAX+ Épica 2).
 *
 *   php tests/session_mode_test.php
 */

require_once __DIR__ . '/../php/session_mode.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

// ── Detección de modalidad ───────────────────────────────────────
check('sin columna → síncrona', triviax_sesion_es_asincrona([]), false);
check('sincrono → síncrona', triviax_sesion_es_asincrona(['modalidad_sincronia' => 'sincrono']), false);
check('asincrono_tarea → asíncrona', triviax_sesion_es_asincrona(['modalidad_sincronia' => 'asincrono_tarea']), true);
check('valor basura → síncrona', triviax_sesion_es_asincrona(['modalidad_sincronia' => 'xxx']), false);
check('null → síncrona', triviax_sesion_es_asincrona(['modalidad_sincronia' => null]), false);

// ── Vencimiento de la tarea ──────────────────────────────────────
$tarea = ['modalidad_sincronia' => 'asincrono_tarea'];
check('tarea sin límite → no vence', triviax_sesion_tarea_vencida($tarea), false);
check('tarea límite NULL → no vence', triviax_sesion_tarea_vencida($tarea + ['fecha_limite_tarea' => null]), false);
check(
    'tarea con límite futuro → no vence',
    triviax_sesion_tarea_vencida($tarea + ['fecha_limite_tarea' => '2026-07-10 12:00:00'], '2026-07-06 09:00:00'),
    false
);
check(
    'tarea con límite pasado → vence',
    triviax_sesion_tarea_vencida($tarea + ['fecha_limite_tarea' => '2026-07-05 23:59:00'], '2026-07-06 09:00:00'),
    true
);
check(
    'límite exacto → no vence (inclusive)',
    triviax_sesion_tarea_vencida($tarea + ['fecha_limite_tarea' => '2026-07-06 09:00:00'], '2026-07-06 09:00:00'),
    false
);
check(
    'sesión síncrona nunca vence aunque tenga fecha',
    triviax_sesion_tarea_vencida(['modalidad_sincronia' => 'sincrono', 'fecha_limite_tarea' => '2020-01-01 00:00:00'], '2026-07-06 09:00:00'),
    false
);

echo "\n== session_mode: {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
