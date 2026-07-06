<?php
/**
 * Test unitario (SIN BD) del motor del metajuego TRIVIAX+ (Épica 1).
 *
 *   php tests/metagame_test.php
 *
 * Ejercita las funciones puras de economía: niveles, rachas, multiplicadores
 * y recompensas. Sale con código 0 si todo pasa, 1 si no.
 */

require_once __DIR__ . '/../php/metagame_engine.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

// ── Niveles ──────────────────────────────────────────────────────
check('0 XP → nivel 1', triviax_metagame_level(0), 1);
check('99 XP → nivel 1', triviax_metagame_level(99), 1);
check('100 XP → nivel 2', triviax_metagame_level(100), 2);
check('399 XP → nivel 2', triviax_metagame_level(399), 2);
check('400 XP → nivel 3', triviax_metagame_level(400), 3);
check('XP negativa → nivel 1', triviax_metagame_level(-50), 1);
check('xp_for_level(1) = 0', triviax_metagame_xp_for_level(1), 0);
check('xp_for_level(2) = 100', triviax_metagame_xp_for_level(2), 100);
check('xp_for_level(3) = 400', triviax_metagame_xp_for_level(3), 400);
check('xp_for_level inversa de level', triviax_metagame_level(triviax_metagame_xp_for_level(5)), 5);

// ── Multiplicador de racha ───────────────────────────────────────
check('racha 0 → x1.0', triviax_metagame_streak_multiplier(0), 1.0);
check('racha 1 → x1.0', triviax_metagame_streak_multiplier(1), 1.0);
check('racha 2 → x1.1', triviax_metagame_streak_multiplier(2), 1.1);
check('racha 7 → x1.6 (tope)', triviax_metagame_streak_multiplier(7), 1.6);
check('racha 30 → x1.6 (tope)', triviax_metagame_streak_multiplier(30), 1.6);

// ── Cálculo de racha por fechas ──────────────────────────────────
check('primer acceso → racha 1', triviax_metagame_next_streak(null, 0, '2026-07-06'), 1);
check('mismo día → mantiene racha', triviax_metagame_next_streak('2026-07-06', 3, '2026-07-06'), 3);
check('día consecutivo → racha +1', triviax_metagame_next_streak('2026-07-05', 3, '2026-07-06'), 4);
check('racha rota → vuelve a 1', triviax_metagame_next_streak('2026-07-01', 9, '2026-07-06'), 1);
check('mismo día con racha 0 → racha 1', triviax_metagame_next_streak('2026-07-06', 0, '2026-07-06'), 1);
check('consecutivo cruza mes', triviax_metagame_next_streak('2026-06-30', 2, '2026-07-01'), 3);

// ── Recompensas ──────────────────────────────────────────────────
$r = triviax_metagame_calc_rewards(20, 'correct', 1);
check('correct 20 pts racha 1 → 20 XP', $r['xp'], 20);
check('correct 20 pts → 6 monedas', $r['monedas'], 6);
check('correct racha 1 → x1.0', $r['multiplicador'], 1.0);

$r = triviax_metagame_calc_rewards(20, 'correct', 7);
check('correct 20 pts racha 7 → 32 XP', $r['xp'], 32);
check('correct racha 7 → x1.6', $r['multiplicador'], 1.6);

$r = triviax_metagame_calc_rewards(0, 'correct', 1);
check('correct 0 pts → mínimo 10 XP', $r['xp'], 10);
check('correct 0 pts → 4 monedas', $r['monedas'], 4);

$r = triviax_metagame_calc_rewards(-15, 'incorrect', 5);
check('incorrect → 2 XP de participación', $r['xp'], 2);
check('incorrect → 0 monedas', $r['monedas'], 0);
check('incorrect → sin multiplicador', $r['multiplicador'], 1.0);

$r = triviax_metagame_calc_rewards(0, 'timeout', 3);
check('timeout → 2 XP de participación', $r['xp'], 2);

echo "\n== metagame: {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
