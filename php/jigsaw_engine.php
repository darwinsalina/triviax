<?php
/**
 * TRIVIAX — Motor server-side de la modalidad "Puzle" (jigsaw).
 *
 * Las imágenes viven en filesystem (media/jigsaw/<slug>/); aquí se gestionan
 * los metadatos en BD y las sesiones de juego (para el reporte docente).
 *
 * Dependencias: php/db.php, php/jigsaw_validator.php, php/image_upload.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jigsaw_validator.php';
require_once __DIR__ . '/image_upload.php';

if (!defined('TRIVIAX_JIGSAW_MEDIA_REL')) {
    define('TRIVIAX_JIGSAW_MEDIA_REL', 'media/jigsaw'); // relativa a la raíz del proyecto
}

/** Carpeta absoluta donde viven las imágenes de los puzles. */
function triviax_jigsaw_media_root(): string {
    return dirname(__DIR__) . '/' . TRIVIAX_JIGSAW_MEDIA_REL;
}

/** Slug único estable (mismo patrón que study_answer: nombre_ymd_hex). */
function triviax_jigsaw_slugify(string $title): string {
    $slug = $title;
    if (function_exists('iconv')) {
        $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($c !== false) {
            $slug = $c;
        }
    }
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $slug));
    $slug = trim($slug, '_');
    return ($slug !== '' ? $slug : 'puzle') . '_' . date('ymd') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function triviax_jigsaw_load_project(int $id): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM jigsaw_projects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function triviax_jigsaw_load_project_by_slug(string $slug): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM jigsaw_projects WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Forma pública de un proyecto (la que consume el cliente del juego).
 * No oculta nada sensible: el puzle no tiene "respuesta" secreta.
 */
function triviax_jigsaw_public_project(array $row): array {
    $base = TRIVIAX_JIGSAW_MEDIA_REL . '/' . $row['slug'];
    return [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'name' => $row['titulo'],
        'description' => $row['descripcion'],
        'image' => $base . '/source.jpg',
        'thumb' => $base . '/thumb.jpg',
        'imageW' => (int)$row['image_w'],
        'imageH' => (int)$row['image_h'],
        'difficultyMode' => $row['difficulty_mode'],
        'fixedGrid' => (int)$row['fixed_grid'],
        'pieceShape' => $row['piece_shape'],
        'playMode' => $row['play_mode'],
        'showHint' => (bool)$row['show_hint'],
        'hintOpacity' => (int)$row['hint_opacity'],
        'estado' => $row['estado'],
    ];
}

function triviax_jigsaw_list_published(): array {
    $pdo = triviax_db();
    $stmt = $pdo->query("SELECT * FROM jigsaw_projects WHERE estado = 'published' ORDER BY updated_at DESC, created_at DESC");
    return array_map('triviax_jigsaw_public_project', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function triviax_jigsaw_list_for_docente(int $docenteId, bool $isSuper): array {
    $pdo = triviax_db();
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM jigsaw_sessions s WHERE s.project_id = p.id) AS sessions
            FROM jigsaw_projects p '
        . ($isSuper ? '' : 'WHERE p.docente_id = ? ')
        . 'ORDER BY p.updated_at DESC, p.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($isSuper ? [] : [$docenteId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pub = triviax_jigsaw_public_project($row);
        $pub['sessions'] = (int)$row['sessions'];
        $pub['docente_id'] = (int)$row['docente_id'];
        $pub['created_at'] = $row['created_at'];
        $out[] = $pub;
    }
    return $out;
}

/**
 * Crea un proyecto de puzle. La imagen ya fue procesada por el caller con
 * triviax_img_process_upload() (devuelve image_w/image_h). Inserta la fila y
 * devuelve el id + slug.
 *
 * @param array $values  Opciones normalizadas (triviax_jigsaw_validate_options).
 * @param int   $docenteId
 * @param string $slug   Slug ya generado (la carpeta de la imagen ya existe).
 * @param int   $imageW
 * @param int   $imageH
 * @return array ['id'=>int, 'slug'=>string]
 */
function triviax_jigsaw_create_project(array $values, int $docenteId, string $slug, int $imageW, int $imageH): array {
    $pdo = triviax_db();
    $imagePath = TRIVIAX_JIGSAW_MEDIA_REL . '/' . $slug;
    $pdo->prepare('
        INSERT INTO jigsaw_projects
            (docente_id, slug, titulo, descripcion, image_path, image_w, image_h,
             difficulty_mode, fixed_grid, piece_shape, play_mode, show_hint, hint_opacity, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')
    ')->execute([
        $docenteId, $slug, $values['titulo'], ($values['descripcion'] !== '' ? $values['descripcion'] : null),
        $imagePath, $imageW, $imageH,
        $values['difficulty_mode'], $values['fixed_grid'], $values['piece_shape'],
        $values['play_mode'], $values['show_hint'], $values['hint_opacity'],
    ]);
    return ['id' => (int)$pdo->lastInsertId(), 'slug' => $slug];
}

/** Borra un proyecto (fila + carpeta de imágenes). */
function triviax_jigsaw_delete_project(array $project): void {
    $pdo = triviax_db();
    $pdo->prepare('DELETE FROM jigsaw_projects WHERE id = ?')->execute([(int)$project['id']]);
    $dir = dirname(__DIR__) . '/' . $project['image_path'];
    triviax_img_delete_dir($dir);
}
