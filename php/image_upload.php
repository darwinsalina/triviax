<?php
/**
 * TRIVIAX — Helper compartido para subir y optimizar imágenes (GD).
 *
 * Usado por las modalidades basadas en imágenes (jigsaw, etiquetar). Factoriza
 * la lógica que antes vivía duplicada en admin.php y en los experimentos:
 * cargar según tipo real, corregir orientación EXIF, redimensionar a un lado
 * máximo aplanando transparencias sobre blanco y generar miniatura.
 *
 * Todas las funciones llevan el prefijo triviax_img_ para evitar colisiones
 * (ver nota de funciones duplicadas en la memoria del proyecto).
 *
 * Requiere la extensión GD (disponible en este WAMP y en el servidor).
 */

if (!defined('TRIVIAX_IMG_MAX_SIDE'))   define('TRIVIAX_IMG_MAX_SIDE', 1600);  // lado mayor de la imagen principal
if (!defined('TRIVIAX_IMG_THUMB_SIDE')) define('TRIVIAX_IMG_THUMB_SIDE', 480); // lado mayor de la miniatura
if (!defined('TRIVIAX_IMG_QUALITY'))    define('TRIVIAX_IMG_QUALITY', 80);     // calidad JPG principal
if (!defined('TRIVIAX_IMG_THUMB_Q'))    define('TRIVIAX_IMG_THUMB_Q', 78);     // calidad JPG miniatura

/**
 * Carga una imagen GD desde un archivo según su tipo real.
 * @param string $path  Ruta del archivo.
 * @param int|null $type  (salida) IMAGETYPE_* detectado.
 * @return resource|\GdImage|null
 */
function triviax_img_load(string $path, ?int &$type = null) {
    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }
    $type = $info[2];
    switch ($type) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        case IMAGETYPE_GIF:  return @imagecreatefromgif($path);
        default:             return null;
    }
}

/**
 * Corrige la orientación según EXIF (solo JPEG con la extensión exif activa).
 */
function triviax_img_apply_exif($img, string $path, int $type) {
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    if (empty($exif['Orientation'])) {
        return $img;
    }
    switch ((int)$exif['Orientation']) {
        case 3: return imagerotate($img, 180, 0);
        case 6: return imagerotate($img, -90, 0);
        case 8: return imagerotate($img,  90, 0);
    }
    return $img;
}

/**
 * Redimensiona (si hace falta) a un lado máximo y aplana sobre blanco.
 * Devuelve un nuevo recurso GD (el original no se libera aquí).
 */
function triviax_img_fit($src, int $maxSide) {
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * Procesa una imagen subida ($_FILES[...]) y la guarda como source.jpg + thumb.jpg
 * dentro de $destDir. Crea el directorio si no existe.
 *
 * @param array  $file     Entrada de $_FILES (con 'tmp_name' y 'error').
 * @param string $destDir  Carpeta destino absoluta.
 * @return array ['ok'=>bool, 'error'=>?string, 'image_w'=>int, 'image_h'=>int,
 *                'source'=>'source.jpg', 'thumb'=>'thumb.jpg']
 */
function triviax_img_process_upload(array $file, string $destDir): array {
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg];

    if (!extension_loaded('gd')) {
        return $fail('El servidor no tiene la extensión GD para procesar imágenes.');
    }
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return $fail('No se recibió la imagen (o superó el tamaño máximo permitido por el servidor).');
    }

    $tmp = $file['tmp_name'];
    $type = null;
    $src = triviax_img_load($tmp, $type);
    if (!$src) {
        return $fail('Formato de imagen no soportado. Usá JPG, PNG, WebP o GIF.');
    }
    $src = triviax_img_apply_exif($src, $tmp, $type);

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        imagedestroy($src);
        return $fail('No se pudo crear la carpeta de la actividad.');
    }

    $main  = triviax_img_fit($src, TRIVIAX_IMG_MAX_SIDE);
    $thumb = triviax_img_fit($src, TRIVIAX_IMG_THUMB_SIDE);

    $okMain  = @imagejpeg($main,  $destDir . '/source.jpg', TRIVIAX_IMG_QUALITY);
    $okThumb = @imagejpeg($thumb, $destDir . '/thumb.jpg',  TRIVIAX_IMG_THUMB_Q);

    $imgW = imagesx($main);
    $imgH = imagesy($main);

    imagedestroy($src);
    imagedestroy($main);
    imagedestroy($thumb);

    if (!$okMain || !$okThumb) {
        return $fail('No se pudo guardar la imagen procesada.');
    }

    return [
        'ok'      => true,
        'error'   => null,
        'image_w' => $imgW,
        'image_h' => $imgH,
        'source'  => 'source.jpg',
        'thumb'   => 'thumb.jpg',
    ];
}

/**
 * Borra recursivamente la carpeta de una actividad (imágenes). No sigue symlinks.
 */
function triviax_img_delete_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($dir);
}
