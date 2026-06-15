<?php
/**
 * TRIVIAX — Motor server-side de la modalidad "Etiquetar" (etiquetar).
 *
 * Imágenes en filesystem (media/etiquetar/<slug>/); metadatos, etiquetas y
 * sesiones en BD. Las coordenadas se guardan como ax, ay, lx, ly (0..1);
 * el cliente usa bx/by → mapeadas en la forma pública.
 *
 * Dependencias: php/db.php, php/etiquetar_validator.php, php/image_upload.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/etiquetar_validator.php';
require_once __DIR__ . '/image_upload.php';

if (!defined('TRIVIAX_ETIQUETAR_MEDIA_REL')) {
    define('TRIVIAX_ETIQUETAR_MEDIA_REL', 'media/etiquetar');
}

function triviax_etiquetar_media_root(): string {
    return dirname(__DIR__) . '/' . TRIVIAX_ETIQUETAR_MEDIA_REL;
}

function triviax_etiquetar_slugify(string $title): string {
    $slug = $title;
    if (function_exists('iconv')) {
        $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($c !== false) {
            $slug = $c;
        }
    }
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $slug));
    $slug = trim($slug, '_');
    return ($slug !== '' ? $slug : 'etiquetar') . '_' . date('ymd') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function triviax_etiquetar_load_project(int $id): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM etiquetar_projects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function triviax_etiquetar_load_labels(int $projectId): array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare('SELECT * FROM etiquetar_labels WHERE project_id = ? ORDER BY orden ASC, id ASC');
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Forma pública de un proyecto con sus etiquetas (lx/ly → bx/by para el cliente).
 */
function triviax_etiquetar_public_project(array $row, bool $withLabels = true): array {
    $base = TRIVIAX_ETIQUETAR_MEDIA_REL . '/' . $row['slug'];
    $out = [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'name' => $row['titulo'],
        'description' => $row['descripcion'],
        'image' => $base . '/source.jpg',
        'thumb' => $base . '/thumb.jpg',
        'imageW' => (int)$row['image_w'],
        'imageH' => (int)$row['image_h'],
        'box' => ['x' => (float)$row['box_x'], 'y' => (float)$row['box_y']],
        'estado' => $row['estado'],
    ];
    if ($withLabels) {
        $out['labels'] = array_map(function ($l) {
            return [
                'text' => $l['text'],
                'ax' => (float)$l['ax'],
                'ay' => (float)$l['ay'],
                'bx' => (float)$l['lx'],
                'by' => (float)$l['ly'],
            ];
        }, triviax_etiquetar_load_labels((int)$row['id']));
    }
    return $out;
}

function triviax_etiquetar_list_published(): array {
    $pdo = triviax_db();
    $rows = $pdo->query("SELECT * FROM etiquetar_projects WHERE estado = 'published' ORDER BY updated_at DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => triviax_etiquetar_public_project($r, true), $rows);
}

function triviax_etiquetar_list_for_docente(int $docenteId, bool $isSuper): array {
    $pdo = triviax_db();
    $sql = 'SELECT p.*,
                   (SELECT COUNT(*) FROM etiquetar_labels l WHERE l.project_id = p.id) AS labels_count,
                   (SELECT COUNT(*) FROM etiquetar_sessions s WHERE s.project_id = p.id) AS sessions
            FROM etiquetar_projects p '
        . ($isSuper ? '' : 'WHERE p.docente_id = ? ')
        . 'ORDER BY p.updated_at DESC, p.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($isSuper ? [] : [$docenteId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pub = triviax_etiquetar_public_project($row, false);
        $pub['labelsCount'] = (int)$row['labels_count'];
        $pub['sessions'] = (int)$row['sessions'];
        $pub['docente_id'] = (int)$row['docente_id'];
        $pub['created_at'] = $row['created_at'];
        $out[] = $pub;
    }
    return $out;
}

/**
 * Crea un proyecto de etiquetado con sus etiquetas, en una transacción.
 * La imagen ya fue procesada por el caller.
 *
 * @return array ['id'=>int, 'slug'=>string, 'labels'=>int]
 */
function triviax_etiquetar_create_project(array $values, int $docenteId, string $slug, int $imageW, int $imageH): array {
    $pdo = triviax_db();
    $imagePath = TRIVIAX_ETIQUETAR_MEDIA_REL . '/' . $slug;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO etiquetar_projects
                (docente_id, slug, titulo, descripcion, image_path, image_w, image_h, box_x, box_y, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')
        ')->execute([
            $docenteId, $slug, $values['titulo'], ($values['descripcion'] !== '' ? $values['descripcion'] : null),
            $imagePath, $imageW, $imageH, $values['box']['x'], $values['box']['y'],
        ]);
        $projectId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO etiquetar_labels (project_id, orden, text, ax, ay, lx, ly) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $i = 0;
        foreach ($values['labels'] as $l) {
            $ins->execute([$projectId, $i, $l['text'], $l['ax'], $l['ay'], $l['lx'], $l['ly']]);
            $i++;
        }
        $pdo->commit();
        return ['id' => $projectId, 'slug' => $slug, 'labels' => $i];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function triviax_etiquetar_delete_project(array $project): void {
    $pdo = triviax_db();
    $pdo->prepare('DELETE FROM etiquetar_projects WHERE id = ?')->execute([(int)$project['id']]);
    $dir = dirname(__DIR__) . '/' . $project['image_path'];
    triviax_img_delete_dir($dir);
}
