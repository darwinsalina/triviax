<?php
/**
 * Pruebas sin BD de la capa transversal de acceso v7.0:
 * alias de estudiantes, códigos seguros, dominios y ventanas de plazo.
 */
require_once __DIR__ . '/../php/activity_access.php';

$pass = 0;
$fail = 0;
function access_check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

// ── Alias de estudiantes ─────────────────────────────────────
access_check('Juan Pérez → jperez', triviax_alias_base('Juan', 'Pérez') === 'jperez');
access_check('María Rodríguez → mrodriguez', triviax_alias_base('María', 'Rodríguez') === 'mrodriguez');
access_check('Ana Núñez conserva la ñ', triviax_alias_base('Ana', 'Núñez') === 'anuñez');
access_check('Sofía Méndez → smendez (sin tildes)', triviax_alias_base('Sofía', 'Méndez') === 'smendez');
access_check('José María De León → jdeleon', triviax_alias_base('José María', 'De León') === 'jdeleon');
access_check('María-José Núñez → mnuñez (regla consistente)', triviax_alias_base('María-José', 'Núñez') === 'mnuñez');
access_check("O'Connor pierde el apóstrofe", triviax_alias_base('Ana', "O'Connor") === 'aoconnor');
access_check('Apellido con punto y guion queda limpio', triviax_alias_base('Luis', 'García-Peña Jr.') === 'lgarciapeñajr');
access_check('Diéresis se elimina (Müller)', triviax_alias_base('Karl', 'Müller') === 'kmuller');
access_check('Nombre vacío no rompe', triviax_alias_base('', '') === 'estudiante');

access_check('Sin duplicado devuelve la base', triviax_alias_make_unique('jperez', ['mrodriguez']) === 'jperez');
access_check('Duplicado agrega sufijo 2', triviax_alias_make_unique('jperez', ['jperez']) === 'jperez2');
access_check('Sufijos correlativos', triviax_alias_make_unique('jperez', ['jperez', 'jperez2', 'jperez3']) === 'jperez4');
access_check('Comparación de duplicados sin mayúsculas', triviax_alias_make_unique('JPerez', ['jperez']) === 'JPerez2');

// ── Códigos seguros ──────────────────────────────────────────
$code = triviax_generate_secure_code(12);
access_check('Código de 12 caracteres', strlen($code) === 12);
access_check('Código solo alfanumérico', preg_match('/^[A-Z0-9]+$/', $code) === 1);
access_check('Código evita caracteres confusos', preg_match('/[O0I1L]/', $code) === 0);
$otro = triviax_generate_secure_code(12);
access_check('Dos códigos consecutivos difieren', $code !== $otro);

$hash = triviax_code_hash($code);
access_check('Hash no contiene el código en claro', strpos($hash, $code) === false);
access_check('Verificación acepta el código correcto', triviax_code_verify($code, $hash));
access_check('Verificación insensible a mayúsculas', triviax_code_verify(strtolower($code), $hash));
access_check('Verificación rechaza código incorrecto', !triviax_code_verify('XXXXXXXXXXXX', $hash));
access_check('Código vacío no verifica', !triviax_code_verify('', $hash));
access_check('Hash nulo no verifica', !triviax_code_verify($code, null));

$corto = triviax_generate_secure_code(6);
access_check('Código de grupo de 6 caracteres', strlen($corto) === 6);

// ── Dominios ─────────────────────────────────────────────────
access_check('Dominio normalizado sin @ y en minúsculas',
    triviax_normalize_domain(' @Woodsideschool.EDU.uy ') === 'woodsideschool.edu.uy');
access_check('Dominio extraído del email',
    triviax_email_domain('Ana.Perez@WoodsideSchool.edu.uy') === 'woodsideschool.edu.uy');
access_check('Email sin arroba devuelve null', triviax_email_domain('no-es-email') === null);
access_check('Email null devuelve null', triviax_email_domain(null) === null);

// ── Referencia de actividad ──────────────────────────────────
access_check('Ref de proyecto conserva slug', triviax_activity_ref('proyecto', 'demo_mixto') === 'demo_mixto');
access_check('Ref numérica se vuelve string', triviax_activity_ref('study_deck', 7) === '7');
$lanzo = false;
try {
    triviax_activity_ref('tipo_inexistente', 1);
} catch (InvalidArgumentException $e) {
    $lanzo = true;
}
access_check('Tipo desconocido lanza excepción', $lanzo);

// ── Ventana de publicación y plazos ──────────────────────────
$base = triviax_policy_defaults('proyecto', 'x');
access_check('Política por defecto abierta', triviax_policy_window_open($base) === 'ok');

$p = $base; $p['estado_publicacion'] = 'borrador';
access_check('Borrador no publicado', triviax_policy_window_open($p) === 'no_publicada');
$p['estado_publicacion'] = 'desactivada';
access_check('Desactivada no publicada', triviax_policy_window_open($p) === 'no_publicada');
$p['estado_publicacion'] = 'archivada';
access_check('Archivada no publicada', triviax_policy_window_open($p) === 'no_publicada');
$p['estado_publicacion'] = 'cerrada';
access_check('Cerrada = plazo finalizado', triviax_policy_window_open($p) === 'plazo_finalizado');

$p = $base;
$p['estado_publicacion'] = 'programada';
$p['abre_at'] = date('Y-m-d H:i:s', time() + 3600);
access_check('Bloquea antes de abre_at', triviax_policy_window_open($p) === 'no_disponible_aun');
$p['abre_at'] = date('Y-m-d H:i:s', time() - 3600);
$p['cierra_at'] = date('Y-m-d H:i:s', time() + 3600);
access_check('Permite durante el intervalo', triviax_policy_window_open($p) === 'ok');
$p['cierra_at'] = date('Y-m-d H:i:s', time() - 60);
access_check('Bloquea después de cierra_at', triviax_policy_window_open($p) === 'plazo_finalizado');

echo PHP_EOL . "== activity_access: {$pass} OK, {$fail} FAIL ==" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
