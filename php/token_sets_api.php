<?php
/**
 * Endpoints tokens_* para colecciones de fichas / avatares.
 */

require_once __DIR__ . '/token_sets.php';

function _tokens_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _tokens_get_owned_set(PDO $pdo, int $setId, array $u): array {
    $stmt = $pdo->prepare('SELECT ts.*, COUNT(ta.id) AS asset_count FROM token_sets ts LEFT JOIN token_assets ta ON ta.token_set_id = ts.id WHERE ts.id = ? GROUP BY ts.id');
    $stmt->execute([$setId]);
    $row = $stmt->fetch();
    if (!$row) {
        triviax_api_error('NOT_FOUND', 'Coleccion no encontrada.', 404);
    }
    if ($u['rol'] !== TRIVIAX_ROL_SUPERADMIN && (int)$row['teacher_id'] !== (int)$u['id']) {
        triviax_api_error('FORBIDDEN', 'No tienes permiso sobre esta coleccion.', 403);
    }
    return $row;
}

function _tokens_public_set(PDO $pdo, int $setId): array {
    $stmt = $pdo->prepare('SELECT ts.*, COUNT(ta.id) AS asset_count FROM token_sets ts LEFT JOIN token_assets ta ON ta.token_set_id = ts.id WHERE ts.id = ? AND ts.status IN ("active","archived") GROUP BY ts.id');
    $stmt->execute([$setId]);
    $row = $stmt->fetch();
    if (!$row) {
        triviax_api_error('NOT_FOUND', 'Coleccion no disponible.', 404);
    }
    return $row;
}

function _tokens_save_asset_rows(PDO $pdo, int $setId, array $assets): void {
    $pdo->prepare('DELETE FROM token_assets WHERE token_set_id = ?')->execute([$setId]);
    $stmt = $pdo->prepare(
        'INSERT INTO token_assets (token_set_id, label, category, row_index, col_index, sort_order, file_path, thumb_path, width, height, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($assets as $asset) {
        $stmt->execute([
            $setId,
            $asset['label'],
            $asset['category'],
            $asset['row_index'],
            $asset['col_index'],
            $asset['sort_order'],
            $asset['file_path'],
            $asset['thumb_path'],
            $asset['width'],
            $asset['height'],
        ]);
    }
}

function triviax_tokens_api_handle(string $action): void {
    $pdo = _tokens_require_db();

    switch ($action) {
        case 'tokens_prompt': {
            $in = triviax_api_input();
            triviax_api_success(triviax_token_prompt_parts($in));
            break;
        }

        case 'tokens_list': {
            $u = triviax_token_require_docente();
            $status = (string)($_GET['status'] ?? '');
            $where = 'WHERE ts.teacher_id = ?';
            $params = [(int)$u['id']];
            if ($u['rol'] === TRIVIAX_ROL_SUPERADMIN && isset($_GET['all'])) {
                $where = 'WHERE 1=1';
                $params = [];
            }
            if ($status !== '' && in_array($status, triviax_token_statuses(), true)) {
                $where .= ' AND ts.status = ?';
                $params[] = $status;
            }
            $stmt = $pdo->prepare("SELECT ts.*, COUNT(ta.id) AS asset_count FROM token_sets ts LEFT JOIN token_assets ta ON ta.token_set_id = ts.id {$where} GROUP BY ts.id ORDER BY ts.updated_at DESC, ts.id DESC");
            $stmt->execute($params);
            triviax_api_success(['sets' => array_map('triviax_token_row_public', $stmt->fetchAll())]);
            break;
        }

        case 'tokens_active': {
            $u = triviax_token_require_docente();
            $stmt = $pdo->prepare(
                'SELECT ts.*, COUNT(ta.id) AS asset_count
                 FROM token_sets ts
                 LEFT JOIN token_assets ta ON ta.token_set_id = ts.id AND ta.active = 1
                 WHERE ts.teacher_id = ? AND ts.status = "active"
                 GROUP BY ts.id
                 HAVING asset_count > 0
                 ORDER BY ts.title ASC'
            );
            $stmt->execute([(int)$u['id']]);
            triviax_api_success(['sets' => array_map('triviax_token_row_public', $stmt->fetchAll())]);
            break;
        }

        case 'tokens_get': {
            $u = triviax_token_require_docente();
            $setId = (int)($_GET['id'] ?? 0);
            $set = _tokens_get_owned_set($pdo, $setId, $u);
            triviax_api_success([
                'set' => triviax_token_row_public($set),
                'assets' => triviax_token_assets($pdo, $setId),
            ]);
            break;
        }

        case 'tokens_public': {
            $setId = (int)($_GET['id'] ?? 0);
            $set = _tokens_public_set($pdo, $setId);
            triviax_api_success([
                'set' => triviax_token_row_public($set),
                'assets' => triviax_token_assets($pdo, $setId, true),
            ]);
            break;
        }

        case 'tokens_save': {
            triviax_api_require_post();
            $u = triviax_token_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $setId = (int)($in['id'] ?? 0);
            $status = (string)($in['status'] ?? 'draft');
            if (!in_array($status, triviax_token_statuses(), true)) {
                $status = 'draft';
            }
            $parts = triviax_token_prompt_parts($in);
            $data = [
                triviax_token_normalize_text($in['title'] ?? 'Nueva coleccion', 160),
                triviax_token_normalize_text($in['description'] ?? '', 800),
                triviax_token_normalize_text($in['activity_theme'] ?? '', 190),
                triviax_token_normalize_text($in['token_type'] ?? 'personajes', 80),
                triviax_token_normalize_text($in['visual_style'] ?? 'educativo', 120),
                $parts['prompt_text'],
                $parts['negative_prompt_text'],
                $parts['cut_prompt_text'],
                $status,
            ];
            if ($data[0] === '') {
                triviax_api_error('VALIDATION', 'El nombre de la coleccion es obligatorio.', 422);
            }
            if ($setId > 0) {
                _tokens_get_owned_set($pdo, $setId, $u);
                $stmt = $pdo->prepare(
                    'UPDATE token_sets SET title=?, description=?, activity_theme=?, token_type=?, visual_style=?, prompt_text=?, negative_prompt_text=?, cut_prompt_text=?, status=? WHERE id=?'
                );
                $stmt->execute(array_merge($data, [$setId]));
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO token_sets (teacher_id, title, description, activity_theme, token_type, visual_style, prompt_text, negative_prompt_text, cut_prompt_text, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute(array_merge([(int)$u['id']], $data));
                $setId = (int)$pdo->lastInsertId();
            }
            $set = _tokens_get_owned_set($pdo, $setId, $u);
            triviax_api_success(['set' => triviax_token_row_public($set)]);
            break;
        }

        case 'tokens_upload_source': {
            triviax_api_require_post();
            $u = triviax_token_require_docente();
            triviax_verify_csrf_json();
            $setId = (int)($_POST['id'] ?? 0);
            _tokens_get_owned_set($pdo, $setId, $u);
            if (empty($_FILES['source_image']) || !is_uploaded_file($_FILES['source_image']['tmp_name'])) {
                triviax_api_error('VALIDATION', 'Sube una imagen madre.', 422);
            }
            if ((int)$_FILES['source_image']['size'] > 8 * 1024 * 1024) {
                triviax_api_error('VALIDATION', 'La imagen no puede superar 8 MB.', 422);
            }
            $tmp = $_FILES['source_image']['tmp_name'];
            $info = @getimagesize($tmp);
            $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
            if (!$info || !in_array((string)$info['mime'], $allowed, true)) {
                triviax_api_error('VALIDATION', 'Formato no valido. Usa PNG, JPG o WEBP.', 422);
            }
            triviax_token_assert_ratio((int)$info[0], (int)$info[1]);
            $root = triviax_token_upload_root((int)$u['id'], $setId);
            if (!is_dir($root) && !mkdir($root, 0775, true)) {
                triviax_api_error('SERVER_ERROR', 'No se pudo crear la carpeta de subida.', 500);
            }
            $sourcePath = $root . '/source.png';
            $img = triviax_token_decode_image($tmp, (string)$info['mime']);
            if (!$img) {
                triviax_api_error('VALIDATION', 'No se pudo leer la imagen.', 422);
            }
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagepng($img, $sourcePath);
            imagedestroy($img);
            $public = triviax_token_public_path($sourcePath);
            $pdo->prepare('UPDATE token_sets SET source_image_path = ? WHERE id = ?')->execute([$public, $setId]);
            triviax_api_success(['source_image_path' => $public, 'width' => (int)$info[0], 'height' => (int)$info[1]]);
            break;
        }

        case 'tokens_slice': {
            triviax_api_require_post();
            $u = triviax_token_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $setId = (int)($in['id'] ?? 0);
            $set = _tokens_get_owned_set($pdo, $setId, $u);
            if (empty($set['source_image_path'])) {
                triviax_api_error('VALIDATION', 'Primero sube la imagen madre.', 422);
            }
            $source = dirname(__DIR__) . '/' . ltrim(str_replace(['..', '\\'], ['', '/'], (string)$set['source_image_path']), '/');
            if (!is_file($source)) {
                triviax_api_error('NOT_FOUND', 'No se encontro la imagen madre.', 404);
            }
            $tokensDir = triviax_token_upload_root((int)$u['id'], $setId) . '/tokens';
            $assets = triviax_token_slice_source($source, $tokensDir);
            _tokens_save_asset_rows($pdo, $setId, $assets);
            triviax_api_success(['assets' => triviax_token_assets($pdo, $setId)]);
            break;
        }

        case 'tokens_assets_update': {
            triviax_api_require_post();
            $u = triviax_token_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $setId = (int)($in['id'] ?? 0);
            _tokens_get_owned_set($pdo, $setId, $u);
            $assets = is_array($in['assets'] ?? null) ? $in['assets'] : [];
            $stmt = $pdo->prepare('UPDATE token_assets SET label = ?, category = ?, active = ? WHERE id = ? AND token_set_id = ?');
            foreach ($assets as $asset) {
                $assetId = (int)($asset['id'] ?? 0);
                $label = triviax_token_normalize_text($asset['label'] ?? '', 120);
                $category = (string)($asset['category'] ?? 'other');
                if ($label === '') {
                    $label = 'Ficha';
                }
                if (!in_array($category, triviax_token_categories(), true)) {
                    $category = 'other';
                }
                $stmt->execute([$label, $category, !empty($asset['active']) ? 1 : 0, $assetId, $setId]);
            }
            triviax_api_success(['assets' => triviax_token_assets($pdo, $setId)]);
            break;
        }

        case 'tokens_status': {
            triviax_api_require_post();
            $u = triviax_token_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $setId = (int)($in['id'] ?? 0);
            _tokens_get_owned_set($pdo, $setId, $u);
            $status = (string)($in['status'] ?? 'draft');
            if (!in_array($status, triviax_token_statuses(), true)) {
                triviax_api_error('VALIDATION', 'Estado no valido.', 422);
            }
            if ($status === 'active' && count(triviax_token_assets($pdo, $setId, true)) < 1) {
                triviax_api_error('VALIDATION', 'Genera al menos una ficha antes de activar la coleccion.', 422);
            }
            $pdo->prepare('UPDATE token_sets SET status = ? WHERE id = ?')->execute([$status, $setId]);
            triviax_api_success(['status' => $status]);
            break;
        }

        default:
            triviax_api_error('INVALID_ACTION', 'Accion invalida.', 400);
    }
}
