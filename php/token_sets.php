<?php
/**
 * TRIVIAX - Colecciones de fichas / avatares.
 *
 * Este archivo contiene logica reutilizable: prompts, permisos, CRUD basico
 * y corte programatico de hojas 6x4 en PNGs individuales.
 */

require_once __DIR__ . '/auth.php';

const TRIVIAX_TOKEN_GRID_COLS = 6;
const TRIVIAX_TOKEN_GRID_ROWS = 4;
const TRIVIAX_TOKEN_CELL_SIZE = 256;
const TRIVIAX_TOKEN_TOTAL = 24;

function triviax_token_categories(): array {
    return ['male', 'female', 'neutral', 'object', 'animal', 'vehicle', 'ship', 'creature', 'symbol', 'other'];
}

function triviax_token_modes(): array {
    return ['standard_only', 'special_optional', 'special_required'];
}

function triviax_token_statuses(): array {
    return ['draft', 'active', 'archived'];
}

function triviax_token_public_path(string $absolutePath): string {
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $path = str_replace('\\', '/', $absolutePath);
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

function triviax_token_upload_root(int $teacherId, int $setId): string {
    return dirname(__DIR__) . '/uploads/token_sets/' . $teacherId . '/' . $setId;
}

function triviax_token_normalize_text($value, int $max = 190): string {
    $text = trim((string)$value);
    $text = strip_tags($text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

function triviax_token_prompt_parts(array $data): array {
    $theme = triviax_token_normalize_text($data['activity_theme'] ?? '', 220);
    $type = triviax_token_normalize_text($data['token_type'] ?? 'personajes', 120);
    $style = triviax_token_normalize_text($data['visual_style'] ?? 'educativo', 120);
    $notes = triviax_token_normalize_text($data['notes'] ?? '', 700);

    $prompt = "Crear una unica imagen en formato PNG, de 1536 x 1024 pixeles, compuesta por una cuadricula virtual de 6 columnas por 4 filas. La imagen debe contener exactamente 24 fichas o avatares individuales, uno por cada celda cuadrada de 256 x 256 pixeles.\n\n"
        . "Tema de la actividad: {$theme}\n"
        . "Tipo de fichas solicitadas: {$type}\n"
        . "Estilo visual: {$style}\n"
        . ($notes !== '' ? "Requisitos adicionales del docente: {$notes}\n" : '')
        . "\nPublico objetivo: estudiantes de educacion basica, adolescentes o jovenes, con estetica clara, atractiva y apropiada para uso educativo.\n\n"
        . "Cada ficha debe estar centrada dentro de su celda, dejando un margen interno seguro para que no se corte ninguna parte del avatar. Cada avatar debe verse completo, con silueta clara, buena legibilidad visual y suficiente contraste.\n\n"
        . "Todas las fichas deben compartir una estetica coherente, como si pertenecieran al mismo juego de mesa educativo o videojuego educativo.\n\n"
        . "Si las fichas son personajes humanos o antropomorficos, incluir al menos 6 personajes masculinos y 6 personajes femeninos. Las demas fichas pueden ser personajes neutros, variantes tematicas, objetos, criaturas, vehiculos o elementos relacionados con la actividad. Si las fichas no son humanas, crear 24 variantes claramente diferenciables.\n\n"
        . "Requisitos visuales: 24 fichas diferentes; una sola ficha por celda; sin texto; sin numeros; sin letras; sin nombres; sin logos; sin marcas de agua; sin bordes visibles de cuadricula; sin fondos complejos; fondo transparente si la herramienta lo permite; si no es posible, fondo blanco puro o fondo liso uniforme; no superponer fichas; no cortar partes; no repetir disenios; no incluir elementos fuera de las celdas.\n\n"
        . "Composicion: 6 columnas, 4 filas, 24 celdas cuadradas. Cada avatar debe ocupar aproximadamente entre el 70% y el 85% de su celda, con margen visual suficiente en los cuatro lados y estilo consistente.\n\n"
        . "Resultado esperado: una hoja de sprites o avatares lista para ser cortada automaticamente en 24 imagenes individuales de 256 x 256 pixeles con fondo transparente.";

    $negative = "No incluir texto, letras, numeros, etiquetas, firmas, marcas de agua, logotipos, bordes de cuadricula visibles, fondos detallados, escenas completas, personajes cortados, personajes superpuestos, duplicados, sombras invasivas, elementos fuera de celda, baja resolucion, desenfoque, perspectiva inconsistente, estilos mezclados, fondos con patrones, fondos fotograficos o detalles que dificulten el recorte.";

    $cut = "Usar la imagen adjunta como hoja de fichas. Dividirla exactamente en 24 imagenes individuales siguiendo una cuadricula de 6 columnas por 4 filas. Cada ficha debe quedar en una imagen PNG cuadrada de 256 x 256 pixeles. Mantener el contenido original de cada celda sin redibujar, reinterpretar ni modificar los avatares. Eliminar cualquier fondo liso si es posible y entregar cada ficha con fondo transparente. No agregar texto, bordes, sombras, nombres ni efectos nuevos. Nombrar los archivos en orden de lectura, de izquierda a derecha y de arriba abajo: ficha_01.png hasta ficha_24.png.";

    return [
        'prompt_text' => $prompt,
        'negative_prompt_text' => $negative,
        'cut_prompt_text' => $cut,
    ];
}

function triviax_token_decode_image(string $path, string $mime) {
    switch ($mime) {
        case 'image/png': return @imagecreatefrompng($path);
        case 'image/jpeg':
        case 'image/jpg': return @imagecreatefromjpeg($path);
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
    }
    return false;
}

function triviax_token_assert_ratio(int $width, int $height): void {
    $expected = 1.5;
    $ratio = $height > 0 ? ($width / $height) : 0;
    if (abs($ratio - $expected) > 0.025) {
        throw new RuntimeException('La imagen madre debe mantener proporcion 3:2 (ideal 1536 x 1024).');
    }
}

function triviax_token_slice_source(string $sourcePath, string $destDir): array {
    if (!extension_loaded('gd')) {
        throw new RuntimeException('La extension GD de PHP no esta disponible.');
    }
    $info = @getimagesize($sourcePath);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('El archivo subido no es una imagen valida.');
    }
    $width = (int)$info[0];
    $height = (int)$info[1];
    triviax_token_assert_ratio($width, $height);

    $src = triviax_token_decode_image($sourcePath, (string)$info['mime']);
    if (!$src) {
        throw new RuntimeException('No se pudo leer la imagen. Usa PNG, JPG o WEBP.');
    }

    $targetW = TRIVIAX_TOKEN_GRID_COLS * TRIVIAX_TOKEN_CELL_SIZE;
    $targetH = TRIVIAX_TOKEN_GRID_ROWS * TRIVIAX_TOKEN_CELL_SIZE;
    if ($width !== $targetW || $height !== $targetH) {
        $normalized = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($normalized, false);
        imagesavealpha($normalized, true);
        $transparent = imagecolorallocatealpha($normalized, 0, 0, 0, 127);
        imagefill($normalized, 0, 0, $transparent);
        imagecopyresampled($normalized, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imagedestroy($src);
        $src = $normalized;
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        imagedestroy($src);
        throw new RuntimeException('No se pudo crear la carpeta de fichas.');
    }

    $assets = [];
    for ($row = 0; $row < TRIVIAX_TOKEN_GRID_ROWS; $row++) {
        for ($col = 0; $col < TRIVIAX_TOKEN_GRID_COLS; $col++) {
            $order = $row * TRIVIAX_TOKEN_GRID_COLS + $col + 1;
            $dest = imagecreatetruecolor(TRIVIAX_TOKEN_CELL_SIZE, TRIVIAX_TOKEN_CELL_SIZE);
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
            imagefill($dest, 0, 0, $transparent);
            $padding = 8; // Margen de seguridad para evitar contaminar con celdas vecinas (pies, cabezas, bordes)
            $innerSize = TRIVIAX_TOKEN_CELL_SIZE - (2 * $padding);
            imagecopy(
                $dest,
                $src,
                $padding,
                $padding,
                $col * TRIVIAX_TOKEN_CELL_SIZE + $padding,
                $row * TRIVIAX_TOKEN_CELL_SIZE + $padding,
                $innerSize,
                $innerSize
            );
            $filename = 'ficha_' . str_pad((string)$order, 2, '0', STR_PAD_LEFT) . '.png';
            $absolute = rtrim($destDir, '/\\') . '/' . $filename;
            imagepng($dest, $absolute);
            imagedestroy($dest);
            $assets[] = [
                'label' => 'Ficha ' . str_pad((string)$order, 2, '0', STR_PAD_LEFT),
                'category' => 'other',
                'row_index' => $row,
                'col_index' => $col,
                'sort_order' => $order,
                'file_path' => triviax_token_public_path($absolute),
                'thumb_path' => triviax_token_public_path($absolute),
                'width' => TRIVIAX_TOKEN_CELL_SIZE,
                'height' => TRIVIAX_TOKEN_CELL_SIZE,
            ];
        }
    }

    imagedestroy($src);
    return $assets;
}

function triviax_token_require_docente(): array {
    triviax_session_start();
    $u = triviax_usuario_actual();
    if ($u === null) {
        triviax_api_error('UNAUTHORIZED', 'Necesitas iniciar sesion.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Acceso solo para docentes.', 403);
    }
    return $u;
}

function triviax_token_row_public(array $row): array {
    return [
        'id' => (int)$row['id'],
        'teacher_id' => (int)$row['teacher_id'],
        'title' => (string)$row['title'],
        'description' => (string)($row['description'] ?? ''),
        'activity_theme' => (string)($row['activity_theme'] ?? ''),
        'token_type' => (string)($row['token_type'] ?? ''),
        'visual_style' => (string)($row['visual_style'] ?? ''),
        'prompt_text' => (string)($row['prompt_text'] ?? ''),
        'negative_prompt_text' => (string)($row['negative_prompt_text'] ?? ''),
        'cut_prompt_text' => (string)($row['cut_prompt_text'] ?? ''),
        'source_image_path' => (string)($row['source_image_path'] ?? ''),
        'status' => (string)$row['status'],
        'grid_cols' => (int)$row['grid_cols'],
        'grid_rows' => (int)$row['grid_rows'],
        'cell_size' => (int)$row['cell_size'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'asset_count' => isset($row['asset_count']) ? (int)$row['asset_count'] : 0,
    ];
}

function triviax_token_assets(PDO $pdo, int $setId, bool $onlyActive = false): array {
    $sql = 'SELECT * FROM token_assets WHERE token_set_id = ?';
    if ($onlyActive) {
        $sql .= ' AND active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$setId]);
    return array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'label' => (string)$row['label'],
            'category' => (string)$row['category'],
            'row_index' => (int)$row['row_index'],
            'col_index' => (int)$row['col_index'],
            'sort_order' => (int)$row['sort_order'],
            'file_path' => (string)$row['file_path'],
            'thumb_path' => (string)($row['thumb_path'] ?? $row['file_path']),
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'active' => (int)$row['active'] === 1,
        ];
    }, $stmt->fetchAll());
}
