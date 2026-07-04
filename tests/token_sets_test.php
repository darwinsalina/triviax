<?php
require_once __DIR__ . '/../php/token_sets.php';

$ok = 0;
$fail = 0;

function tassert($cond, $label) {
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
}

$parts = triviax_token_prompt_parts([
    'activity_theme' => 'Sistema solar',
    'token_type' => 'naves',
    'visual_style' => '3D educativo',
]);
tassert(strpos($parts['prompt_text'], '1536 x 1024') !== false, 'Prompt incluye tamano madre');
tassert(strpos($parts['prompt_text'], '24 fichas') !== false, 'Prompt exige 24 fichas');
tassert(strpos($parts['negative_prompt_text'], 'No incluir texto') !== false, 'Prompt negativo existe');
tassert(strpos($parts['cut_prompt_text'], 'ficha_01.png') !== false, 'Prompt de corte incluye nombres');

try {
    triviax_token_assert_ratio(1536, 1024);
    tassert(true, 'Proporcion 3:2 aceptada');
} catch (Throwable $e) {
    tassert(false, 'Proporcion 3:2 aceptada');
}

try {
    triviax_token_assert_ratio(1000, 1000);
    tassert(false, 'Proporcion cuadrada rechazada');
} catch (Throwable $e) {
    tassert(true, 'Proporcion cuadrada rechazada');
}

if (extension_loaded('gd')) {
    $tmpRoot = __DIR__ . '/tmp_token_sets_' . bin2hex(random_bytes(4));
    $source = $tmpRoot . '/source.png';
    $dest = $tmpRoot . '/tokens';
    mkdir($tmpRoot, 0777, true);

    $img = imagecreatetruecolor(1536, 1024);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    for ($row = 0; $row < 4; $row++) {
        for ($col = 0; $col < 6; $col++) {
            $color = imagecolorallocate($img, 20 + $col * 30, 30 + $row * 45, 140);
            imagefilledrectangle($img, $col * 256 + 16, $row * 256 + 16, $col * 256 + 240, $row * 256 + 240, $color);
        }
    }
    imagepng($img, $source);
    imagedestroy($img);

    $assets = triviax_token_slice_source($source, $dest);
    tassert(count($assets) === 24, 'Corte genera 24 assets');
    tassert(is_file($dest . '/ficha_01.png'), 'ficha_01.png creada');
    tassert(is_file($dest . '/ficha_24.png'), 'ficha_24.png creada');
    tassert($assets[0]['row_index'] === 0 && $assets[0]['col_index'] === 0, 'Orden inicia fila 0 col 0');
    tassert($assets[23]['row_index'] === 3 && $assets[23]['col_index'] === 5, 'Orden termina fila 3 col 5');

    foreach (glob($dest . '/*.png') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dest);
    @unlink($source);
    @rmdir($tmpRoot);
} else {
    echo "  SKIP Corte con GD (extension no disponible)\n";
}

echo "\n== token_sets: {$ok} OK, {$fail} FAIL ==\n";
exit($fail === 0 ? 0 : 1);
