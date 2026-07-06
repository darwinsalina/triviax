<?php
/**
 * Test unitario (SIN BD) del motor del Marketplace (TRIVIAX+ Épica 3).
 *
 *   php tests/marketplace_test.php
 */

require_once __DIR__ . '/../php/marketplace_engine.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    echo ($ok ? "  OK  " : " FAIL ") . $name
        . ($ok ? "" : "  (got=" . var_export($got, true) . " exp=" . var_export($expected, true) . ")") . "\n";
    $ok ? $pass++ : $fail++;
}

// ── Slugs ────────────────────────────────────────────────────────
check('título simple', triviax_marketplace_slugify('Componentes de Hardware'), 'componentes_de_hardware');
check('acentos y eñes', triviax_marketplace_slugify('Programación en Español ñoño'), 'programacion_en_espanol_nono');
check('símbolos fuera', triviax_marketplace_slugify('¿Qué es TIC? (2026) — ¡Repaso!'), 'que_es_tic_2026_repaso');
check('vacío → actividad', triviax_marketplace_slugify('   '), 'actividad');
check('espacios múltiples colapsan', triviax_marketplace_slugify('a   b'), 'a_b');
check('largo acotado a 60', strlen(triviax_marketplace_slugify(str_repeat('x', 200))), 60);

// ── IDs de clon ──────────────────────────────────────────────────
$id = triviax_marketplace_new_project_id('Máquina de Bebidas', '260706', 'abc123');
check('formato slug_fecha_sufijo', $id, 'maquina_de_bebidas_260706_abc123');
check('cabe en VARCHAR(100)', strlen(triviax_marketplace_new_project_id(str_repeat('z', 300))) <= 100, true);
$otro = triviax_marketplace_new_project_id('Título');
check('sufijo aleatorio de 6 hex', (bool)preg_match('/_\d{6}_[0-9a-f]{6}$/', $otro), true);
check('dos llamadas → ids distintos', triviax_marketplace_new_project_id('X') === triviax_marketplace_new_project_id('X'), false);
check('id válido para la API (regex de api.php)', (bool)preg_match('/^[a-zA-Z0-9_-]+$/', $otro), true);

echo "\n== marketplace: {$pass} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
