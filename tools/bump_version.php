<?php
/**
 * TRIVIAX v5.0.9 — Sincronizador de versión de la aplicación
 *
 * Fuente única de verdad: este script mantiene alineados todos los lugares
 * donde vive APP_VERSION, porque un service worker clásico no puede importar
 * el módulo ES js/config.js y por eso la constante existe duplicada.
 *
 * Uso (CLI):
 *   php tools/bump_version.php --check        Verifica consistencia (exit 0/1)
 *   php tools/bump_version.php 5.0.10         Actualiza la versión en todos los archivos
 *   php tools/bump_version.php 5.0.10 --assets  Además sube TODOS los querystrings ?v= de index.html
 *
 * Archivos sincronizados:
 *   - js/config.js          → export const APP_VERSION = 'X.Y.Z';
 *   - service-worker.js     → const APP_VERSION = 'X.Y.Z';  (renueva cachés PWA)
 *   - index.html            → textos fallback de los pies de página
 *                             <span data-app-version-full="...">TRIVIAX Plus vX.Y.Z</span>
 *
 * La versión "resumida" (data-app-version, splash, píldora de marca) se deriva
 * sola de APP_VERSION (major.minor) en el script inline de index.html; este
 * script solo mantiene al día su TEXTO FALLBACK estático (visible sin JS),
 * que había quedado fosilizado en v5.0 hasta la 7.0.0.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

$root = dirname(__DIR__);

$files = [
    'config' => $root . '/js/config.js',
    'sw'     => $root . '/service-worker.js',
    'index'  => $root . '/index.html',
];

foreach ($files as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: no se encuentra {$path}\n");
        exit(1);
    }
}

/** Extrae la versión declarada en un archivo, o null. */
function extract_version(string $content, string $pattern): ?string {
    return preg_match($pattern, $content, $m) ? $m[1] : null;
}

$reConfig = "/export const APP_VERSION\s*=\s*'(\d+\.\d+\.\d+)'/";
$reSw     = "/^const APP_VERSION\s*=\s*'(\d+\.\d+\.\d+)'/m";
// Texto fallback de los footers: vX.Y.Z dentro de spans con data-app-version-full
$reFooter = "/data-app-version-full=\"[^\"]*\"[^>]*>[^<]*?v(\d+\.\d+\.\d+)</";
// Texto fallback de la versión resumida (píldora de marca): vX.Y
$rePill = "/data-app-version=\"[^\"]*\"[^>]*>[^<]*?v(\d+\.\d+)</";

$contents = array_map('file_get_contents', $files);

$vConfig = extract_version($contents['config'], $reConfig);
$vSw     = extract_version($contents['sw'], $reSw);

preg_match_all($reFooter, $contents['index'], $mFooters);
$vFooters = array_unique($mFooters[1]);

preg_match_all($rePill, $contents['index'], $mPills);
$vPills = array_unique($mPills[1]);
$vShort = $vConfig !== null ? preg_replace('/^(\d+\.\d+)\.\d+$/', '$1', $vConfig) : null;

$arg = $argv[1] ?? '--check';

if ($arg === '--check') {
    $ok = true;
    echo "js/config.js      : " . ($vConfig ?? 'NO ENCONTRADA') . "\n";
    echo "service-worker.js : " . ($vSw ?? 'NO ENCONTRADA') . "\n";
    echo "index.html footers: " . ($vFooters ? implode(', ', $vFooters) : 'NO ENCONTRADA') . "\n";

    if (!$vConfig || !$vSw || !$vFooters) {
        echo "FAIL: no se pudo leer la versión en algún archivo (¿cambió el formato?).\n";
        $ok = false;
    } elseif ($vConfig !== $vSw) {
        echo "FAIL: config.js ({$vConfig}) ≠ service-worker.js ({$vSw}).\n";
        $ok = false;
    } elseif (count($vFooters) > 1 || $vFooters[0] !== $vConfig) {
        echo "FAIL: los footers de index.html no coinciden con APP_VERSION ({$vConfig}).\n";
        $ok = false;
    } elseif ($vPills && (count($vPills) > 1 || $vPills[0] !== $vShort)) {
        echo "FAIL: la píldora de marca (fallback) dice v" . implode(', v', $vPills) . " y debería decir v{$vShort}.\n";
        $ok = false;
    } else {
        echo "OK: versión {$vConfig} consistente en todos los archivos.\n";
    }
    exit($ok ? 0 : 1);
}

if (!preg_match('/^\d+\.\d+\.\d+$/', $arg)) {
    fwrite(STDERR, "Uso: php tools/bump_version.php --check | X.Y.Z [--assets]\n");
    exit(1);
}

$new = $arg;
$bumpAssets = in_array('--assets', $argv, true);

$newContents = [
    'config' => preg_replace($reConfig, "export const APP_VERSION = '{$new}'", $contents['config'], 1, $n1),
    'sw'     => preg_replace($reSw, "const APP_VERSION = '{$new}'", $contents['sw'], 1, $n2),
    'index'  => preg_replace_callback(
        "/(data-app-version-full=\"[^\"]*\"[^>]*>[^<]*?v)\d+\.\d+\.\d+(<)/",
        fn($m) => $m[1] . $new . $m[2],
        $contents['index'], -1, $n3
    ),
];

// Fallback estático de la píldora de marca (versión resumida major.minor)
$newShort = preg_replace('/^(\d+\.\d+)\.\d+$/', '$1', $new);
$newContents['index'] = preg_replace_callback(
    "/(data-app-version=\"[^\"]*\"[^>]*>[^<]*?v)\d+\.\d+(?:\.\d+)?(<)/",
    fn($m) => $m[1] . $newShort . $m[2],
    $newContents['index'], -1, $nPill
);

if (!$n1 || !$n2 || !$n3) {
    fwrite(STDERR, "ERROR: algún patrón no coincidió (config={$n1}, sw={$n2}, footers={$n3}). No se escribió nada.\n");
    exit(1);
}

if ($bumpAssets) {
    $newContents['index'] = preg_replace(
        '/\?v=\d+\.\d+\.\d+/',
        '?v=' . $new,
        $newContents['index'], -1, $nAssets
    );
    echo "Querystrings ?v= actualizados en index.html: {$nAssets}\n";
}

foreach ($newContents as $label => $content) {
    file_put_contents($files[$label], $content);
}

echo "Versión actualizada a {$new}:\n";
echo "  js/config.js      ({$vConfig} → {$new})\n";
echo "  service-worker.js ({$vSw} → {$new})\n";
echo "  index.html        ({$n3} footer(s) + {$nPill} píldora(s) fallback actualizados)\n";
echo "Recordatorio: la versión resumida del splash/píldora se deriva sola (major.minor); aquí solo se actualiza su texto fallback.\n";
exit(0);
