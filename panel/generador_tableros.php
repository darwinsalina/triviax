<?php
/**
 * TRIVIAX v6.0 — Generador y Editor Visual de Tableros
 * Permite diseñar tableros personalizados de forma interactiva.
 */

require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/image_upload.php';

// Control de accesos (docente y superadmin)
$usuario = triviax_usuario_actual();
if (!$usuario || ($usuario['rol'] !== TRIVIAX_ROL_DOCENTE && $usuario['rol'] !== TRIVIAX_ROL_SUPERADMIN)) {
    header('Location: ' . TRIVIAX_BASE . '/auth/login.php');
    exit;
}

$pdo = triviax_db();
$error = '';
$success = '';
$selectedImage = '';

// ──────────────────────────────────────────────────────────────────
// ACCIÓN: GUARDAR TABLERO (AJAX POST JSON)
// ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        triviax_verify_csrf_json(); // acepta token por $_POST, body JSON o header X-CSRF-Token
        
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        
        if (!$data || empty($data['id']) || empty($data['label'])) {
            throw new Exception('Faltan datos obligatorios para guardar el tablero.');
        }

        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['id']);
        if ($slug === '') {
            throw new Exception('Slug de tablero inválido.');
        }

        // Estructurar el perfil del tablero
        $boardProfile = [
            'id' => $slug,
            'label' => trim($data['label']),
            'description' => trim($data['description'] ?? 'Tablero personalizado.'),
            'image' => trim($data['image']),
            'type' => 'map',
            'size' => (int)($data['size'] ?? 50),
            'loop' => (bool)($data['loop'] ?? false),
            'defaultVictoryMode' => trim($data['defaultVictoryMode'] ?? 'race'),
            'allowedVictoryModes' => $data['allowedVictoryModes'] ?? ['race', 'exact', 'points'],
            'cellShape' => trim($data['cellShape'] ?? 'circle'),
            'smoothPath' => (bool)($data['smoothPath'] ?? true),
            'customPositions' => array_map(function($p) {
                return [
                    'x' => round((float)$p['x'], 2),
                    'y' => round((float)$p['y'], 2)
                ];
            }, $data['customPositions'] ?? []),
            'specialCells' => array_map(function($c) {
                return [
                    'cell' => (int)$c['cell'],
                    'type' => trim($c['type']),
                    'effect' => trim($c['effect'] ?? ''),
                    'value' => (int)($c['value'] ?? 0)
                ];
            }, $data['specialCells'] ?? []),
            'createdAt' => date('Y-m-d'),
            'source' => 'visual-editor',
            // Tablero-imagen: al jugar no se dibujan casillas ni recorrido encima;
            // la imagen ya los trae pintados y las fichas usan customPositions.
            'bareBoard' => true
        ];

        // Validar tamaño
        $posCount = count($boardProfile['customPositions']);
        $expectedCount = $boardProfile['size'] + 1;
        if ($posCount !== $expectedCount) {
            throw new Exception("El número de coordenadas ({$posCount}) no coincide con el tamaño esperado ({$expectedCount} para salida + {$boardProfile['size']} casillas).");
        }

        // Código único del tablero (estable entre ediciones).
        $codigo = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['codigo'] ?? ''));
        if ($codigo === '') {
            $codigo = 'tbl_' . date('ymd') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $boardProfile['codigo'] = $codigo;

        // Guardar archivo JSON (lo consume el juego vía api.php?action=list_boards)
        $destDir = __DIR__ . '/../images/tableros';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $destPath = $destDir . '/' . $slug . '.json';
        if (file_put_contents($destPath, json_encode($boardProfile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception('No se pudo escribir el archivo JSON del tablero.');
        }

        // Registrar/actualizar las características en la BD (para reutilizar el
        // tablero en actividades). No bloqueante: si la BD falla, el tablero ya
        // quedó como archivo y el juego puede usarlo igual.
        try {
            $u = triviax_usuario_actual();
            $stmt = $pdo->prepare(
                'INSERT INTO tableros
                    (codigo, slug, docente_id, label, descripcion, image_path, size, is_loop,
                     default_victory_mode, allowed_victory_modes, cell_shape, smooth_path,
                     custom_positions, special_cells)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    slug = VALUES(slug), label = VALUES(label), descripcion = VALUES(descripcion),
                    image_path = VALUES(image_path), size = VALUES(size), is_loop = VALUES(is_loop),
                    default_victory_mode = VALUES(default_victory_mode),
                    allowed_victory_modes = VALUES(allowed_victory_modes),
                    cell_shape = VALUES(cell_shape), smooth_path = VALUES(smooth_path),
                    custom_positions = VALUES(custom_positions), special_cells = VALUES(special_cells)'
            );
            $stmt->execute([
                $codigo,
                $slug,
                $u['id'] ?? null,
                $boardProfile['label'],
                $boardProfile['description'],
                $boardProfile['image'],
                $boardProfile['size'],
                $boardProfile['loop'] ? 1 : 0,
                $boardProfile['defaultVictoryMode'],
                json_encode($boardProfile['allowedVictoryModes'], JSON_UNESCAPED_UNICODE),
                $boardProfile['cellShape'],
                $boardProfile['smoothPath'] ? 1 : 0,
                json_encode($boardProfile['customPositions'], JSON_UNESCAPED_UNICODE),
                json_encode($boardProfile['specialCells'], JSON_UNESCAPED_UNICODE),
            ]);
            triviax_audit_log('tablero_guardado', 'tablero', $codigo, ['slug' => $slug]);
        } catch (Throwable $dbe) {
            // Silencioso: el archivo ya se guardó correctamente.
        }

        echo json_encode(['success' => true, 'codigo' => $codigo]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACCIÓN: SUBIR FRAGMENTO (AJAX) — para imágenes grandes que superan los
// límites de PHP. El cliente trocea el archivo (1.5 MB por trozo) y aquí se
// reensambla en un archivo temporal del sistema. Estrategia tomada de
// C:\wamp64\www\imageoptimizer.
// ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload_chunk') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        triviax_verify_csrf_json();
        if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el fragmento de la imagen.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $_POST['upload_id'] ?? '')) {
            throw new Exception('Identificador de subida inválido.');
        }
        $uploadId = $_POST['upload_id'];
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $part = sys_get_temp_dir() . '/triviax_tab_' . $uploadId . '.part';

        if ($offset === 0 && is_file($part)) {
            @unlink($part);
        }
        $current = is_file($part) ? (int)filesize($part) : 0;
        if ($current !== $offset) {
            throw new Exception('Los fragmentos llegaron fuera de orden. Volvé a intentarlo.');
        }
        $src = @fopen($_FILES['chunk']['tmp_name'], 'rb');
        $dst = @fopen($part, 'ab');
        if (!$src || !$dst) {
            if ($src) { fclose($src); }
            if ($dst) { fclose($dst); }
            throw new Exception('No se pudo guardar el fragmento en el servidor.');
        }
        stream_copy_to_stream($src, $dst);
        fclose($src);
        fclose($dst);
        clearstatcache(true, $part);
        echo json_encode(['success' => true, 'received' => (int)filesize($part)]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────
// ACCIÓN: SUBIR IMAGEN DE TABLERO (AJAX) — acepta cualquier formato y
// nombre. El origen puede ser un archivo directo (imágenes chicas) o el
// reensamblado por fragmentos (upload_id). La convierte a JPG comprimido
// con nombre normalizado único y la guarda en images/tableros/.
// ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload_image') {
    header('Content-Type: application/json; charset=utf-8');
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);
    $partToClean = null;
    try {
        triviax_verify_csrf_json(); // acepta token por $_POST, body JSON o header X-CSRF-Token

        // Origen de la imagen: archivo directo o fragmentos reensamblados.
        if (isset($_FILES['board_image']) && $_FILES['board_image']['error'] === UPLOAD_ERR_OK) {
            $srcPath = $_FILES['board_image']['tmp_name'];
        } elseif (preg_match('/^[a-f0-9]{32}$/', $_POST['upload_id'] ?? '')) {
            $partToClean = sys_get_temp_dir() . '/triviax_tab_' . $_POST['upload_id'] . '.part';
            if (!is_file($partToClean)) {
                throw new Exception('No se encontró la imagen reensamblada. Reintentá la subida.');
            }
            $srcPath = $partToClean;
        } else {
            throw new Exception('No se recibió la imagen (o superó el tamaño permitido por el servidor).');
        }

        $type = null;
        $img = triviax_img_load($srcPath, $type);
        if (!$img) {
            throw new Exception('Formato de imagen no soportado. Usá PNG, JPG, WEBP, GIF o BMP.');
        }
        $img = triviax_img_apply_exif($img, $srcPath, $type);
        $fitted = triviax_img_fit($img, 1920); // lado máx. 1920 (tableros 16:9)

        $destDir = __DIR__ . '/../images/tableros';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        // Nombre normalizado y único, independiente del nombre original.
        $fileName = 'tab_' . date('ymd') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
        $ok = @imagejpeg($fitted, $destDir . '/' . $fileName, 82);
        imagedestroy($img);
        imagedestroy($fitted);
        if ($partToClean) { @unlink($partToClean); $partToClean = null; }
        if (!$ok) {
            throw new Exception('No se pudo guardar la imagen convertida.');
        }

        echo json_encode(['success' => true, 'image' => 'images/tableros/' . $fileName, 'name' => $fileName]);
    } catch (Exception $e) {
        if ($partToClean) { @unlink($partToClean); }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────────
// LEER IMÁGENES DE TABLEROS EXISTENTES
// ──────────────────────────────────────────────────────────────────
$images = [];
$dirTableros = __DIR__ . '/../images/tableros';
if (is_dir($dirTableros)) {
    $files = scandir($dirTableros);
    foreach ($files as $file) {
        if (preg_match('/\.(png|jpe?g|webp)$/i', $file)) {
            $images[] = 'images/tableros/' . $file;
        }
    }
}

// ──────────────────────────────────────────────────────────────────
// LEER DATOS PARA EDICIÓN (?edit=<slug>)
// ──────────────────────────────────────────────────────────────────
$editDataJson = 'null';
if (isset($_GET['edit'])) {
    $editSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['edit']);
    $editPath = $dirTableros . '/' . $editSlug . '.json';
    if (is_file($editPath)) {
        $editContent = file_get_contents($editPath);
        if ($editContent !== false) {
            $editDataJson = $editContent;
        }
    }
}

$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Tableros — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <style>
        body {
            overflow: auto;
            background-attachment: fixed;
        }

        .generator-layout {
            display: flex;
            flex-direction: row;
            min-height: 100vh;
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .config-sidebar {
            flex: 0 0 380px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .canvas-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .board-editor-view {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            background-color: #0c0f17;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
            user-select: none;
        }

        .board-editor-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1;
            pointer-events: none;
        }

        .board-editor-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
            pointer-events: none;
        }

        .editor-space-node {
            position: absolute;
            width: 3.5%;
            height: 6.2%;
            transform: translate(-50%, -50%);
            border: 2px solid #ffffff;
            background: rgba(99, 102, 241, 0.85);
            color: #ffffff;
            font-weight: 800;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: grab;
            z-index: 10;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
            transition: transform 0.1s, border-color 0.2s, background-color 0.2s;
        }

        .editor-space-node.is-active, .editor-space-node:hover {
            transform: translate(-50%, -50%) scale(1.25);
            background-color: #a855f7;
            border-color: #ffd43b;
            z-index: 20;
            cursor: grabbing;
        }

        .editor-space-node.node-start {
            background-color: #2563eb;
            border-width: 2.5px;
        }

        .editor-space-node.node-meta {
            background-color: #10b981;
            border-width: 2.5px;
        }

        .editor-space-node.node-special {
            border-color: #ffd43b;
            box-shadow: 0 0 12px #ffd43b;
        }

        .editor-space-node::after {
            content: attr(data-special-icon);
            position: absolute;
            top: -12px;
            right: -12px;
            font-size: 0.8rem;
        }

        .editor-path-line {
            fill: none;
            stroke: rgba(168, 85, 247, 0.6);
            stroke-width: 5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .prompt-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px;
            font-family: monospace;
            font-size: 0.78rem;
            color: var(--text-secondary);
            max-height: 180px;
            overflow-y: auto;
            white-space: pre-wrap;
            position: relative;
        }

        .btn-copy-prompt {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid var(--accent);
            color: #ffffff;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 0.65rem;
            cursor: pointer;
        }

        .btn-copy-prompt:hover {
            background: var(--accent-gradient);
        }

        .form-group {
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .form-control {
            background: rgba(17, 24, 39, 0.6);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 8px 12px;
            font-family: var(--font-main);
            font-size: 0.88rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
        }

        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 15px;
            width: 100%;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #a7f3d0;
        }

        #editor-token {
            position: absolute;
            width: 2.5%;
            height: 4.4%;
            background-color: #ffd43b;
            border: 2px solid #ffffff;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            z-index: 30;
            display: none;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.65rem;
            color: #000000;
            box-shadow: 0 0 12px rgba(255,255,255,0.8);
            transition: left 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                        top 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 30px;
            background: rgba(9, 13, 22, 0.82);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(12px);
        }

        .top-nav-title {
            font-size: 1.35rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 1.5px;
        }

        .editor-card-title {
            font-size: 1rem;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding-bottom: 8px;
            margin-bottom: 12px;
            color: var(--text-primary);
        }
    </style>
</head>
<body>

    <!-- TOPBAR -->
    <header class="top-nav">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="top-nav-title">TRIVIAX</span>
            <span class="badge-level" style="background: rgba(168, 85, 247, 0.15); border: 1px solid var(--accent); color: #c084fc;">Map Creator</span>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="<?= TRIVIAX_BASE ?>/admin.php" class="btn btn-secondary" style="text-decoration: none; padding: 6px 14px; text-transform: none; font-size: 0.8rem;">
                🎓 Panel Docente
            </a>
            <?php if ($usuario['rol'] === TRIVIAX_ROL_SUPERADMIN): ?>
                <a href="<?= TRIVIAX_BASE ?>/panel/super.php" class="btn btn-secondary" style="text-decoration: none; padding: 6px 14px; text-transform: none; font-size: 0.8rem;">
                    ⚡ Superadmin
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="generator-layout">

        <!-- PANEL DE CONFIGURACIÓN -->
        <aside class="config-sidebar">

            <!-- DATOS BÁSICOS -->
            <div class="glass-card" style="padding: 20px;">
                <h3 class="editor-card-title">⚙️ Configuración del Tablero</h3>
                
                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="image-upload-form" style="margin-bottom: 15px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="slug" id="upload-slug-ref">
                    
                    <div class="form-group">
                        <label for="board_image">Subir imagen del tablero</label>
                        <input type="file" name="board_image" id="board_image" accept="image/*" class="form-control" onchange="triggerImageUpload()">
                        <small style="font-size: 0.72rem; color: var(--text-secondary);">Cualquier formato y nombre. Se convierte sola a JPG comprimido y se previsualiza acá abajo. No hace falta escribir el slug antes.<br><strong>Proporción recomendada 16:9</strong> (ideal 1600×900 px). El editor recorta cualquier imagen a 16:9 igual que en el juego, así que lo que ves acá es lo que verán los jugadores.</small>
                    </div>
                </form>

                <div class="form-group">
                    <label for="b-name">Nombre del Tablero</label>
                    <input type="text" id="b-name" class="form-control" placeholder="Ej: Espacio Profundo" value="Mi Tablero">
                </div>

                <div class="form-group">
                    <label for="b-slug">Slug (Único, sin espacios)</label>
                    <input type="text" id="b-slug" class="form-control" placeholder="Ej: espacio-profundo" value="mi-tablero">
                </div>

                <div class="form-group">
                    <label for="b-size">Cantidad de Casillas</label>
                    <input type="number" id="b-size" class="form-control" value="50" min="5" max="100">
                </div>

                <div class="form-group">
                    <label for="b-image-select">Imagen de Fondo</label>
                    <select id="b-image-select" class="form-control" onchange="changeBgImage(this.value)">
                        <option value="">-- Selecciona una Imagen --</option>
                        <?php foreach ($images as $img): ?>
                            <option value="<?= htmlspecialchars($img) ?>" <?= $img === $selectedImage ? 'selected' : '' ?>><?= htmlspecialchars(basename($img)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" id="b-smooth" checked onchange="drawPath()"> Camino Suave (Curva)
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" id="b-circle" checked onchange="updateCellShape(this.checked)"> Casillas Circulares
                    </label>
                </div>
            </div>

            <!-- PROMPT PARA IA -->
            <div class="glass-card" style="padding: 20px;">
                <h3 class="editor-card-title">🤖 Prompt para la IA</h3>
                <p style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 10px;">
                    Copia este prompt y pégalo en Claude o ChatGPT adjuntando la imagen del tablero.
                </p>
                <div class="prompt-card" id="ia-prompt-text">Generando prompt...</div>
            </div>

            <!-- INPUT JSON -->
            <div class="glass-card" style="padding: 20px;">
                <h3 class="editor-card-title">📥 Pegar Respuesta JSON</h3>
                <div class="form-group">
                    <textarea id="json-input" class="form-control" rows="5" style="font-family: monospace; font-size: 0.75rem; resize: vertical;" placeholder="Pega el JSON devuelto por la IA aquí..."></textarea>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn btn-primary" onclick="loadPastedJson()" style="flex: 1; padding: 8px 12px; font-size: 0.78rem;">Procesar JSON</button>
                    <button class="btn btn-secondary" onclick="generateDefaultPoints()" style="padding: 8px 12px; font-size: 0.78rem; text-transform: none;">Crear Manual</button>
                </div>
            </div>

        </aside>

        <!-- LIENZO INTERACTIVO (CANVAS) -->
        <section class="canvas-area">
            
            <div class="glass-card" style="width: 100%; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    <span>Casillas: </span><strong id="info-casillas">0</strong> |
                    <span id="info-drag-msg" style="color: #ffd43b;">Arrastra cualquier casilla para ajustar su posición.</span>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn btn-secondary" onclick="simulateToken()" style="padding: 8px 16px; font-size: 0.8rem; text-transform: none;">
                        🏃 Simular Ficha
                    </button>
                    <button class="btn btn-primary" onclick="saveBoardProfile()" style="padding: 8px 16px; font-size: 0.8rem;">
                        💾 Guardar Tablero
                    </button>
                </div>
            </div>

            <!-- TABLERO VISUAL -->
            <div class="board-editor-view" id="editor-board-view">
                <!-- Imagen de fondo -->
                <img src="" class="board-editor-bg" id="editor-bg-img" alt="">
                
                <!-- Capa SVG del camino -->
                <svg class="board-editor-svg" viewBox="0 0 100 100" preserveAspectRatio="none" id="editor-svg-layer"></svg>
                
                <!-- Capa Nodos (se añaden dinámicamente) -->
                <div id="editor-nodes-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 3;"></div>
                
                <!-- Ficha de previsualización -->
                <div id="editor-token">S</div>
            </div>

            <!-- PANEL DE CASILLA ESPECIAL -->
            <div class="glass-card" id="special-cell-panel" style="width: 100%; padding: 15px 20px; display: none; background: rgba(99, 102, 241, 0.08); border-color: rgba(99, 102, 241, 0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-weight: 800; font-size: 0.95rem; color: #ffffff;">Casilla <span id="sc-number">0</span> seleccionada</span>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" id="sc-is-special" onchange="toggleSpecialCell()"> Es Casilla Especial
                        </label>
                    </div>
                    <div id="sc-config-controls" style="display: none; align-items: center; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <label for="sc-type" style="font-size: 0.8rem; font-weight: 700;">Tipo:</label>
                            <select id="sc-type" class="form-control" style="padding: 4px 8px; font-size: 0.8rem;" onchange="updateSpecialCellData()">
                                <option value="bonus">Bonus (+)</option>
                                <option value="penalty">Castigo (-)</option>
                            </select>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <label for="sc-value" style="font-size: 0.8rem; font-weight: 700;">Desplazamiento:</label>
                            <input type="number" id="sc-value" class="form-control" style="padding: 4px 8px; width: 60px; font-size: 0.8rem;" value="3" min="1" max="10" oninput="updateSpecialCellData()">
                        </div>
                    </div>
                    <button class="btn btn-secondary" onclick="closeSpecialCellPanel()" style="padding: 4px 10px; font-size: 0.75rem; text-transform: none;">✕ Cerrar</button>
                </div>
            </div>

        </section>

    </div>

    <script>
        const csrfToken = "<?= $csrfToken ?>";
        let points = [];
        let specialCells = [];
        let isDragging = false;
        let dragIndex = -1;
        let activeIndex = -1;
        let containerRect = null;

        // Cargar datos si estamos editando
        const editData = <?= $editDataJson ?>;

        window.addEventListener('DOMContentLoaded', () => {
            syncSlug();
            document.getElementById('b-slug').addEventListener('input', syncSlug);
            document.getElementById('b-name').addEventListener('input', updatePrompt);
            document.getElementById('b-size').addEventListener('input', () => {
                updatePrompt();
                document.getElementById('info-casillas').innerText = document.getElementById('b-size').value;
            });

            if (editData) {
                loadEditData(editData);
            } else {
                // Seleccionar primer imagen de fondo por defecto
                const bgSelect = document.getElementById('b-image-select');
                if (bgSelect.options.length > 1) {
                    bgSelect.selectedIndex = 1;
                    changeBgImage(bgSelect.value);
                }
                generateDefaultPoints();
            }
        });

        function syncSlug() {
            const nameVal = document.getElementById('b-name').value;
            const slugInput = document.getElementById('b-slug');
            
            // Si estamos editando no cambiamos el slug automáticamente
            if (editData) return;

            let slug = slugInput.value;
            if (nameVal && slugInput.value === '') {
                slug = nameVal.toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
                slugInput.value = slug;
            }
            document.getElementById('upload-slug-ref').value = slug;
            updatePrompt();
        }

        // Tamaño de fragmento (1.5 MB), por debajo de los límites típicos de PHP.
        const CHUNK_BYTES = 1572864;

        function createUploadId() {
            const b = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(b);
            } else {
                for (let i = 0; i < b.length; i++) b[i] = Math.floor(Math.random() * 256);
            }
            return Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
        }

        // Lee la respuesta y arma un mensaje de error específico (HTTP + detalle).
        async function readJsonResponse(res) {
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch (_) {}
            if (!res.ok) {
                const detalle = (data && (data.error || data.message)) || (text ? text.slice(0, 300) : '');
                throw new Error(`El servidor respondió ${res.status} ${res.statusText}.` + (detalle ? ` — ${detalle}` : ''));
            }
            if (!data) {
                throw new Error('Respuesta no válida del servidor: ' + (text ? text.slice(0, 300) : '(vacía)'));
            }
            return data;
        }

        // Sube la imagen y la convierte a JPG. Si es grande, la trocea en
        // fragmentos para esquivar los límites de subida de PHP.
        async function uploadBoardImage(file, onProgress) {
            if (file.size <= CHUNK_BYTES) {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('board_image', file);
                const res = await fetch('generador_tableros.php?action=upload_image', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd });
                const data = await readJsonResponse(res);
                if (!data.success) throw new Error(data.error || 'No se pudo subir la imagen.');
                return data;
            }
            // Imagen grande → subir por fragmentos.
            const uploadId = createUploadId();
            let offset = 0;
            while (offset < file.size) {
                const chunk = file.slice(offset, offset + CHUNK_BYTES);
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('upload_id', uploadId);
                fd.append('offset', String(offset));
                fd.append('chunk', chunk, file.name + '.part');
                const res = await fetch('generador_tableros.php?action=upload_chunk', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd });
                const data = await readJsonResponse(res);
                if (!data.success) throw new Error(data.error || 'No se pudo subir un fragmento.');
                offset += chunk.size;
                if (onProgress) onProgress(Math.round((offset / file.size) * 100));
            }
            // Reensamblado listo → convertir a JPG.
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('upload_id', uploadId);
            const res = await fetch('generador_tableros.php?action=upload_image', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: fd });
            const data = await readJsonResponse(res);
            if (!data.success) throw new Error(data.error || 'No se pudo procesar la imagen.');
            return data;
        }

        function triggerImageUpload() {
            const input = document.getElementById('board_image');
            const file = input.files[0];
            if (!file) return;

            const msg = document.getElementById('info-drag-msg');
            const prev = msg ? msg.innerText : '';
            const setMsg = (txt, color) => { if (msg) { msg.style.color = color || '#ffd43b'; msg.innerText = txt; } };
            setMsg('⏳ Subiendo imagen…');

            uploadBoardImage(file, p => setMsg(`⏳ Subiendo imagen… ${p}%`))
                .then(data => {
                    const sel = document.getElementById('b-image-select');
                    let opt = Array.from(sel.options).find(o => o.value === data.image);
                    if (!opt) { opt = new Option(data.name, data.image); sel.add(opt); }
                    sel.value = data.image;
                    changeBgImage(data.image); // previsualiza sin perder el trabajo en curso
                    setMsg('✓ Imagen lista. Ahora colocá/ajustá las casillas y pulsá «Guardar Tablero».', '#10b981');
                })
                .catch(err => { alert('No se pudo subir la imagen:\n\n' + err.message); setMsg(prev); })
                .finally(() => { input.value = ''; }); // permite volver a subir el mismo archivo
        }

        function changeBgImage(path) {
            const img = document.getElementById('editor-bg-img');
            img.src = path ? '<?= TRIVIAX_BASE ?>/' + path : '';
            img.style.display = path ? 'block' : 'none';
            updatePrompt();
        }

        function updateCellShape(isCircle) {
            const nodes = document.querySelectorAll('.editor-space-node');
            nodes.forEach(node => {
                node.style.borderRadius = isCircle ? '50%' : '12px';
            });
        }

        // ──────────────────────────────────────────────────────────────────
        // GENERACIÓN DE PROMPT IA
        // ──────────────────────────────────────────────────────────────────
        function updatePrompt() {
            const name = document.getElementById('b-name').value || 'Mi Tablero';
            const slug = document.getElementById('b-slug').value || 'mi-tablero';
            const size = parseInt(document.getElementById('b-size').value) || 50;
            const bgSelect = document.getElementById('b-image-select');
            const bgFile = bgSelect.value ? bgSelect.options[bgSelect.selectedIndex].text : 'tablero.png';

            const prompt = `Actúa como un extractor de coordenadas espaciales para tableros de juego educativos.
Tu objetivo es analizar la imagen del tablero adjunto ("${bgFile}") y devolver un JSON estructurado con las coordenadas de cada casilla para que las fichas se posicionen exactamente en el centro de las casillas impresas en el fondo.

### Sistema de Coordenadas:
- Origen (0,0) en la esquina superior izquierda.
- Eje X se extiende de 0 a 100 hacia la derecha.
- Eje Y se extiende de 0 a 100 hacia abajo.
- Las coordenadas representan porcentajes del ancho y alto totales de la imagen.

### Reglas de Extracción:
1. Sigue estrictamente la numeración del camino desde la salida hasta la meta.
2. Identifica la salida (START/Salida) como la casilla 0.
3. Extrae exactamente las casillas intermedias en orden ascendente (1, 2, 3, etc.).
4. Identifica la meta (FINISH/Meta) como la última casilla número ${size}.
5. Debes devolver EXACTAMENTE ${size + 1} puntos (salida + ${size} casillas).
6. Conserva la precisión del recorrido (meandros, curvas, espirales).
7. UBICACIÓN EXACTA (la regla más importante): la coordenada (x, y) de cada casilla debe caer JUSTO sobre el NÚMERO impreso dentro de esa casilla. Centrá el punto exactamente sobre ese número, NO en el borde de la casilla, NI en un dibujo, NI en el espacio entre casillas.
8. Todas las casillas deben quedar DENTRO del recorrido dibujado del tablero (sobre el camino), nunca fuera de él.
9. Para la Salida (0) y la Meta, si no muestran número, usá el centro de la casilla "Salida"/"Meta".

### Esquema del JSON de respuesta esperado:
Devuelve ÚNICAMENTE el siguiente bloque JSON, sin introducciones ni comentarios explicativos:

\`\`\`json
{
  "customPositions": [
    { "x": 10.5, "y": 85.2 }, // Casilla 0 (Salida)
    { "x": 15.2, "y": 83.1 }, // Casilla 1
    // ... casillas intermedias ...
    { "x": 50.0, "y": 50.0 }  // Casilla ${size} (Meta)
  ]
}
\`\`\`

Verifica que el array tenga exactamente ${size + 1} elementos y que los valores de X e Y estén acotados estrictamente en el rango [0.0, 100.0].`;

            const card = document.getElementById('ia-prompt-text');
            card.innerHTML = '';
            
            const btn = document.createElement('button');
            btn.className = 'btn-copy-prompt';
            btn.innerText = 'Copiar';
            btn.onclick = () => {
                navigator.clipboard.writeText(prompt);
                btn.innerText = '¡Copiado!';
                setTimeout(() => btn.innerText = 'Copiar', 2000);
            };
            card.appendChild(btn);
            
            const pre = document.createElement('span');
            pre.innerText = prompt;
            card.appendChild(pre);
        }

        // ──────────────────────────────────────────────────────────────────
        // CARGA Y GENERACIÓN DE PUNTOS
        // ──────────────────────────────────────────────────────────────────
        function generateDefaultPoints() {
            const size = parseInt(document.getElementById('b-size').value) || 50;
            points = [];
            
            // Generar un camino simple horizontal zig-zag de prueba
            const cols = 10;
            for (let i = 0; i <= size; i++) {
                const row = Math.floor(i / cols);
                const col = row % 2 === 0 ? (i % cols) : ((cols - 1) - (i % cols));
                const x = 10 + col * 8;
                const y = 80 - row * 12;
                points.push({ x: parseFloat(x.toFixed(2)), y: parseFloat(y.toFixed(2)) });
            }
            
            activeIndex = -1;
            document.getElementById('info-casillas').innerText = size;
            renderEditorNodes();
            drawPath();
        }

        function loadPastedJson() {
            try {
                const textarea = document.getElementById('json-input');
                const raw = textarea.value.trim();
                if (raw === '') throw new Exception('El campo JSON está vacío.');
                
                const data = json_decode_with_fallbacks(raw);
                if (!data || !Array.isArray(data.customPositions)) {
                    throw new Exception('El JSON no contiene un array "customPositions" válido.');
                }
                
                const size = parseInt(document.getElementById('b-size').value) || 50;
                const expected = size + 1;
                if (data.customPositions.length !== expected) {
                    throw new Exception(`El JSON tiene ${data.customPositions.length} puntos. Se esperaban exactamente ${expected} para un tablero de ${size} casillas.`);
                }
                
                points = data.customPositions.map(p => ({
                    x: Math.max(0, Math.min(100, parseFloat(p.x))),
                    y: Math.max(0, Math.min(100, parseFloat(p.y)))
                }));

                specialCells = data.specialCells || [];
                
                renderEditorNodes();
                drawPath();
                textarea.value = '';
                alert('¡JSON cargado con éxito! Ajusta los puntos visualmente.');
            } catch (err) {
                alert('Error al cargar JSON: ' + err.message);
            }
        }

        function json_decode_with_fallbacks(str) {
            // Intentar parser JSON nativo
            try { return JSON.parse(str); } catch(_) {}
            
            // Reemplazo de comillas tipográficas si se pegó desde chat
            let clean = str.replace(/[\u201C\u201D]/g, '"').replace(/[\u2018\u2019]/g, "'");
            return JSON.parse(clean);
        }

        function loadEditData(data) {
            document.getElementById('b-name').value = data.label;
            document.getElementById('b-slug').value = data.id;
            document.getElementById('b-size').value = data.size;
            document.getElementById('b-smooth').checked = !!data.smoothPath;
            document.getElementById('b-circle').checked = data.cellShape === 'circle';
            
            const select = document.getElementById('b-image-select');
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === data.image) {
                    select.selectedIndex = i;
                    break;
                }
            }
            
            changeBgImage(data.image);
            points = data.customPositions || [];
            specialCells = data.specialCells || [];
            
            document.getElementById('info-casillas').innerText = data.size;
            renderEditorNodes();
            drawPath();
        }

        // ──────────────────────────────────────────────────────────────────
        // RENDERIZADO DEL LIENZO
        // ──────────────────────────────────────────────────────────────────
        function renderEditorNodes() {
            const layer = document.getElementById('editor-nodes-layer');
            layer.innerHTML = '';
            
            const isCircle = document.getElementById('b-circle').checked;
            
            points.forEach((p, idx) => {
                const node = document.createElement('div');
                node.className = 'editor-space-node';
                node.style.left = `${p.x}%`;
                node.style.top = `${p.y}%`;
                node.style.borderRadius = isCircle ? '50%' : '12px';
                
                if (idx === 0) {
                    node.classList.add('node-start');
                    node.innerText = 'S';
                } else if (idx === points.length - 1) {
                    node.classList.add('node-meta');
                    node.innerText = 'M';
                } else {
                    node.innerText = idx;
                }

                // Casilla especial badge
                const spec = specialCells.find(c => c.cell === idx);
                if (spec) {
                    node.classList.add('node-special');
                    node.setAttribute('data-special-icon', spec.type === 'bonus' ? '⭐' : '💀');
                }
                
                // Evento drag
                node.onmousedown = (e) => startDrag(e, idx);
                node.ontouchstart = (e) => startDrag(e, idx);
                
                // Evento seleccionar para casilla especial
                node.onclick = (e) => {
                    e.stopPropagation();
                    selectCell(idx);
                };
                
                layer.appendChild(node);
            });
        }

        function getCurvePath(pointsList) {
            if (pointsList.length < 2) return '';
            let d = `M ${pointsList[0].x} ${pointsList[0].y}`;
            for (let i = 0; i < pointsList.length - 1; i++) {
                const p0 = pointsList[i - 1] || pointsList[i];
                const p1 = pointsList[i];
                const p2 = pointsList[i + 1];
                const p3 = pointsList[i + 2] || p2;

                const cp1x = p1.x + (p2.x - p0.x) / 6;
                const cp1y = p1.y + (p2.y - p0.y) / 6;
                const cp2x = p2.x - (p3.x - p1.x) / 6;
                const cp2y = p2.y - (p3.y - p1.y) / 6;

                d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x} ${p2.y}`;
            }
            return d;
        }

        function drawPath() {
            const svg = document.getElementById('editor-svg-layer');
            svg.innerHTML = '';
            
            const isSmooth = document.getElementById('b-smooth').checked;
            
            if (isSmooth) {
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('class', 'editor-path-line');
                path.setAttribute('d', getCurvePath(points));
                svg.appendChild(path);
            } else {
                const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                polyline.setAttribute('class', 'editor-path-line');
                let pointsStr = points.map(p => `${p.x},${p.y}`).join(' ');
                polyline.setAttribute('points', pointsStr);
                svg.appendChild(polyline);
            }
        }

        // ──────────────────────────────────────────────────────────────────
        // INTERACCIÓN: DRAG & DROP
        // ──────────────────────────────────────────────────────────────────
        function startDrag(e, index) {
            isDragging = true;
            dragIndex = index;
            
            const container = document.getElementById('editor-board-view');
            containerRect = container.getBoundingClientRect();
            
            // Añadir clases activas
            const nodes = document.querySelectorAll('.editor-space-node');
            nodes[index].classList.add('is-active');
            
            document.addEventListener('mousemove', handleDrag);
            document.addEventListener('mouseup', endDrag);
            document.addEventListener('touchmove', handleDrag, { passive: false });
            document.addEventListener('touchend', endDrag);
        }

        function handleDrag(e) {
            if (!isDragging || dragIndex === -1) return;
            
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            let x = ((clientX - containerRect.left) / containerRect.width) * 100;
            let y = ((clientY - containerRect.top) / containerRect.height) * 100;
            
            // Acotar 0-100
            x = Math.max(0, Math.min(100, x));
            y = Math.max(0, Math.min(100, y));
            
            points[dragIndex].x = parseFloat(x.toFixed(2));
            points[dragIndex].y = parseFloat(y.toFixed(2));
            
            // Actualizar nodo
            const nodes = document.querySelectorAll('.editor-space-node');
            nodes[dragIndex].style.left = `${x}%`;
            nodes[dragIndex].style.top = `${y}%`;
            
            drawPath();
        }

        function endDrag() {
            if (isDragging && dragIndex !== -1) {
                const nodes = document.querySelectorAll('.editor-space-node');
                if (nodes[dragIndex]) {
                    nodes[dragIndex].classList.remove('is-active');
                }
            }
            isDragging = false;
            dragIndex = -1;
            
            document.removeEventListener('mousemove', handleDrag);
            document.removeEventListener('mouseup', endDrag);
            document.removeEventListener('touchmove', handleDrag);
            document.removeEventListener('touchend', endDrag);
        }

        // ──────────────────────────────────────────────────────────────────
        // CASILLAS ESPECIALES
        // ──────────────────────────────────────────────────────────────────
        function selectCell(idx) {
            activeIndex = idx;
            document.getElementById('sc-number').innerText = idx;
            
            const spec = specialCells.find(c => c.cell === idx);
            const chk = document.getElementById('sc-is-special');
            const controls = document.getElementById('sc-config-controls');
            
            if (spec) {
                chk.checked = true;
                controls.style.display = 'flex';
                document.getElementById('sc-type').value = spec.type;
                document.getElementById('sc-value').value = spec.value;
            } else {
                chk.checked = false;
                controls.style.display = 'none';
            }
            
            document.getElementById('special-cell-panel').style.display = 'block';
        }

        // Variable global para evitar bucles infinitos en el evento onchange
        let isTogglingSpecial = false;

        function toggleSpecialCell() {
            if (activeIndex === -1 || isTogglingSpecial) return;
            isTogglingSpecial = true;
            
            const isSpecial = document.getElementById('sc-is-special').checked;
            const controls = document.getElementById('sc-config-controls');
            
            if (isSpecial) {
                controls.style.display = 'flex';
                // Añadir datos por defecto si no existen
                if (!specialCells.some(c => c.cell === activeIndex)) {
                    const type = document.getElementById('sc-type').value;
                    const value = parseInt(document.getElementById('sc-value').value) || 3;
                    specialCells.push({
                        cell: activeIndex,
                        type: type,
                        effect: type === 'bonus' ? 'advance' : 'retrocede',
                        value: value
                    });
                }
            } else {
                controls.style.display = 'none';
                specialCells = specialCells.filter(c => c.cell !== activeIndex);
            }
            
            renderEditorNodes();
            isTogglingSpecial = false;
        }

        function updateSpecialCellData() {
            if (activeIndex === -1) return;
            const type = document.getElementById('sc-type').value;
            const value = parseInt(document.getElementById('sc-value').value) || 3;
            
            const spec = specialCells.find(c => c.cell === activeIndex);
            if (spec) {
                spec.type = type;
                spec.effect = type === 'bonus' ? 'advance' : 'retrocede';
                spec.value = value;
            }
            renderEditorNodes();
        }

        function closeSpecialCellPanel() {
            document.getElementById('special-cell-panel').style.display = 'none';
            activeIndex = -1;
        }

        // ──────────────────────────────────────────────────────────────────
        // SIMULACIÓN
        // ──────────────────────────────────────────────────────────────────
        async function simulateToken() {
            const token = document.getElementById('editor-token');
            token.style.display = 'flex';
            
            for (let i = 0; i < points.length; i++) {
                const p = points[i];
                token.style.left = `${p.x}%`;
                token.style.top = `${p.y}%`;
                token.innerText = i === 0 ? 'S' : (i === points.length - 1 ? 'M' : i);
                await new Promise(r => setTimeout(r, 150));
            }
            
            setTimeout(() => {
                token.style.display = 'none';
            }, 1000);
        }

        // ──────────────────────────────────────────────────────────────────
        // PERSISTENCIA (GUARDADO)
        // ──────────────────────────────────────────────────────────────────
        function saveBoardProfile() {
            const name = document.getElementById('b-name').value.trim();
            const slug = document.getElementById('b-slug').value.trim();
            const size = parseInt(document.getElementById('b-size').value) || 50;
            const imgPath = document.getElementById('b-image-select').value;
            
            if (name === '' || slug === '') {
                alert('Debes completar el Nombre y el Slug del tablero.');
                return;
            }
            if (!imgPath) {
                alert('Debes seleccionar o subir una Imagen de Fondo.');
                return;
            }

            const payload = {
                id: slug,
                codigo: (editData && editData.codigo) ? editData.codigo : '',
                label: name,
                description: `Tablero personalizado con ${size} casillas.`,
                image: imgPath,
                size: size,
                loop: false,
                defaultVictoryMode: 'race',
                allowedVictoryModes: ['race', 'exact', 'points'],
                cellShape: document.getElementById('b-circle').checked ? 'circle' : 'rounded',
                smoothPath: document.getElementById('b-smooth').checked,
                customPositions: points,
                specialCells: specialCells
            };

            fetch('generador_tableros.php?action=save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            })
            .then(async res => {
                const text = await res.text();
                let data = null;
                try { data = JSON.parse(text); } catch (_) {}
                if (!res.ok) {
                    const detalle = (data && (data.error || data.message)) || (text ? text.slice(0, 300) : '');
                    throw new Error(`El servidor respondió ${res.status} ${res.statusText}.` + (detalle ? `\nDetalle: ${detalle}` : ''));
                }
                if (!data) {
                    throw new Error('El servidor no devolvió JSON válido.\nRespuesta: ' + (text ? text.slice(0, 300) : '(vacía)'));
                }
                if (!data.success) {
                    throw new Error(data.error || data.message || 'No se pudo guardar el tablero (sin detalle).');
                }
                return data;
            })
            .then(data => {
                alert('¡Tablero guardado correctamente!' + (data.codigo ? '\nCódigo: ' + data.codigo : '') + '\nYa está disponible para seleccionar en la partida.');
                window.location.href = '<?= TRIVIAX_BASE ?>/admin.php';
            })
            .catch(err => {
                alert('No se pudo guardar el tablero.\n\n' + err.message);
                console.error('[Guardar tablero]', err);
            });
        }
    </script>
</body>
</html>
