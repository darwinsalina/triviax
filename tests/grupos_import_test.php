<?php
/**
 * Pruebas sin BD del parser CSV de importación de estudiantes (v7.0).
 * _grp_parse_csv vive en php/grupos_api.php; aquí solo se usan las
 * funciones puras (no se toca la BD ni el despachador).
 */
require_once __DIR__ . '/../php/grupos_api.php';

$pass = 0;
$fail = 0;
function import_check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

// ── Con cabecera y punto y coma ──────────────────────────────
$csv = "nombre;apellido;email\nJuan;Pérez;juan@test.edu.uy\nAna;Núñez;ana@test.edu.uy\n";
$r = _grp_parse_csv($csv);
import_check('Detecta cabecera y separador ;', count($r['rows']) === 2 && $r['errors'] === []);
import_check('Primera fila correcta', $r['rows'][0]['nombre'] === 'Juan' && $r['rows'][0]['apellido'] === 'Pérez');
import_check('Email en minúsculas', $r['rows'][0]['email'] === 'juan@test.edu.uy');

// ── Con coma y columnas desordenadas ─────────────────────────
$csv = "email,apellido,nombre\nmaria@test.edu.uy,Rodríguez,María\n";
$r = _grp_parse_csv($csv);
import_check('Mapea columnas por nombre aunque estén desordenadas',
    count($r['rows']) === 1 && $r['rows'][0]['nombre'] === 'María' && $r['rows'][0]['apellido'] === 'Rodríguez'
    && $r['rows'][0]['email'] === 'maria@test.edu.uy');

// ── Sin cabecera (posicional) ────────────────────────────────
$csv = "Sofía;Méndez;sofia@test.edu.uy\n";
$r = _grp_parse_csv($csv);
import_check('Sin cabecera usa orden posicional', count($r['rows']) === 1 && $r['rows'][0]['nombre'] === 'Sofía');

// ── BOM UTF-8 ────────────────────────────────────────────────
$csv = "\xEF\xBB\xBFnombre;apellido\nLuis;García\n";
$r = _grp_parse_csv($csv);
import_check('Tolera BOM UTF-8', count($r['rows']) === 1 && $r['rows'][0]['nombre'] === 'Luis');

// ── Latin-1 → UTF-8 ──────────────────────────────────────────
$csv = mb_convert_encoding("nombre;apellido\nJosé;Muñoz\n", 'ISO-8859-1', 'UTF-8');
$r = _grp_parse_csv($csv);
import_check('Convierte Latin-1 a UTF-8', count($r['rows']) === 1 && $r['rows'][0]['apellido'] === 'Muñoz');

// ── Filas inválidas ──────────────────────────────────────────
$csv = "nombre;apellido;email\n;SinNombre;\nJuan;;\nAna;Pérez;no-es-email\n";
$r = _grp_parse_csv($csv);
import_check('Detecta fila sin nombre', count(array_filter($r['errors'], fn($e) => strpos($e['error'], 'nombre') !== false)) >= 1);
import_check('Fila con email inválido se importa sin email',
    count($r['rows']) === 1 && $r['rows'][0]['email'] === null);
import_check('El error de email queda registrado con número de línea',
    count(array_filter($r['errors'], fn($e) => strpos($e['error'], 'no-es-email') !== false && $e['line'] === 4)) === 1);

// ── Email opcional ───────────────────────────────────────────
$csv = "nombre;apellido\nMaría;Núñez\n";
$r = _grp_parse_csv($csv);
import_check('Email es opcional', count($r['rows']) === 1 && $r['rows'][0]['email'] === null);

// ── Archivo vacío ────────────────────────────────────────────
$r = _grp_parse_csv('');
import_check('Archivo vacío devuelve error', $r['rows'] === [] && count($r['errors']) === 1);

// ── Campos entrecomillados con separador interno ─────────────
$csv = "nombre;apellido;email\n\"Juan Andrés\";\"De León; Pérez\";juan@test.edu.uy\n";
$r = _grp_parse_csv($csv);
import_check('Respeta comillas con separador interno',
    count($r['rows']) === 1 && $r['rows'][0]['apellido'] === 'De León; Pérez');

echo PHP_EOL . "== grupos_import: {$pass} OK, {$fail} FAIL ==" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
