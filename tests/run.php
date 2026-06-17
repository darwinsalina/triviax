<?php
/**
 * Runner de pruebas unitarias (sin BD) de TRIVIAX.
 *
 *   php tests/run.php
 *
 * Ejecuta cada tests/*_test.php en un subproceso PHP y agrega los resultados.
 * Sale con código 0 si todas pasan, 1 si alguna falla.
 */

$dir   = __DIR__;
$files = glob($dir . '/*_test.php') ?: [];
sort($files);

if (!$files) {
    echo "No hay archivos *_test.php en tests/.\n";
    exit(0);
}

$php    = PHP_BINARY ?: 'php';
$failed = 0;

foreach ($files as $file) {
    echo "── " . basename($file) . " " . str_repeat('─', max(0, 50 - strlen(basename($file)))) . "\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($file);
    passthru($cmd, $code);
    if ($code !== 0) {
        $failed++;
    }
    echo "\n";
}

$total = count($files);
echo "════════════════════════════════════════\n";
echo "Suites: {$total} · Fallidas: {$failed}\n";
exit($failed === 0 ? 0 : 1);
