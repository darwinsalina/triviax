<?php
require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/db.php';

triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);
$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$nombre = trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? ''));
$nombre = $nombre !== '' ? $nombre : ($usuario['email'] ?? 'Docente');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis fichas - TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.1.3">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=tokens-1">
</head>
<body class="act-app token-sets-app" data-act-csrf="<?= $csrf ?>" data-base="<?= htmlspecialchars(TRIVIAX_BASE, ENT_QUOTES, 'UTF-8') ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php"><span>TRIVIAX</span> <em>Mis fichas</em></a>
        <nav class="act-topnav" aria-label="Navegacion">
            <span class="act-user"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="tokens-shell">
        <section class="tokens-list-panel" aria-label="Colecciones de fichas">
            <div class="tokens-heading">
                <div>
                    <h1>Mis fichas</h1>
                    <p>Colecciones reutilizables para tableros y actividades.</p>
                </div>
                <button class="act-btn primary" id="btn-new-set" type="button">Nueva coleccion</button>
            </div>
            <div id="token-sets-list" class="token-sets-list"></div>
        </section>

        <section class="tokens-editor-panel" aria-label="Editor de coleccion">
            <form id="token-set-form" class="tokens-form">
                <input type="hidden" id="set-id" value="">
                <div class="tokens-editor-head">
                    <div>
                        <h2 id="editor-title">Crear coleccion</h2>
                        <p id="editor-subtitle">Define el tema, genera el prompt y sube una hoja 6 x 4.</p>
                    </div>
                    <select id="set-status" class="tokens-status">
                        <option value="draft">Borrador</option>
                        <option value="active">Activa</option>
                        <option value="archived">Archivada</option>
                    </select>
                </div>

                <label class="act-field">
                    <span class="act-label">Nombre</span>
                    <input id="set-title" maxlength="160" required placeholder="Exploradores del sistema solar">
                </label>
                <label class="act-field">
                    <span class="act-label">Descripcion</span>
                    <textarea id="set-description" rows="2" placeholder="Para actividades de astronomia de 7mo."></textarea>
                </label>
                <div class="tokens-form-grid">
                    <label class="act-field">
                        <span class="act-label">Tema de la actividad</span>
                        <input id="set-theme" maxlength="190" placeholder="Planetas, orbitas y exploracion espacial">
                    </label>
                    <label class="act-field">
                        <span class="act-label">Tipo de fichas</span>
                        <select id="set-type">
                            <option value="personajes humanos">Personajes humanos</option>
                            <option value="personajes antropomorficos">Personajes antropomorficos</option>
                            <option value="objetos">Objetos</option>
                            <option value="animales">Animales</option>
                            <option value="vehiculos">Vehiculos</option>
                            <option value="naves">Naves</option>
                            <option value="criaturas">Criaturas</option>
                            <option value="simbolos">Simbolos</option>
                        </select>
                    </label>
                    <label class="act-field">
                        <span class="act-label">Estilo visual</span>
                        <input id="set-style" maxlength="120" placeholder="ciencia ficcion juvenil, 3D limpio">
                    </label>
                    <label class="act-field">
                        <span class="act-label">Notas</span>
                        <input id="set-notes" maxlength="700" placeholder="Colores vivos, siluetas claras, sin texto">
                    </label>
                </div>

                <div class="tokens-actions">
                    <button class="act-btn primary" type="submit">Guardar y generar prompt</button>
                    <button class="act-btn ghost" type="button" id="btn-copy-main">Copiar prompt</button>
                    <button class="act-btn ghost" type="button" id="btn-copy-negative">Copiar restricciones</button>
                    <button class="act-btn ghost" type="button" id="btn-copy-cut">Copiar prompt de corte</button>
                </div>
            </form>

            <div class="tokens-prompts">
                <label class="act-field">
                    <span class="act-label">Prompt principal</span>
                    <textarea id="prompt-main" rows="9" readonly></textarea>
                </label>
                <label class="act-field">
                    <span class="act-label">Prompt negativo</span>
                    <textarea id="prompt-negative" rows="3" readonly></textarea>
                </label>
                <label class="act-field">
                    <span class="act-label">Prompt alternativo de corte</span>
                    <textarea id="prompt-cut" rows="3" readonly></textarea>
                </label>
            </div>

            <section class="tokens-upload">
                <div>
                    <h2>Subir y cortar hoja madre</h2>
                    <p>Formato ideal: 1536 x 1024 px, 6 columnas por 4 filas.</p>
                </div>
                <form id="token-upload-form" class="tokens-upload-form">
                    <input type="file" id="source-image" accept="image/png,image/jpeg,image/webp">
                    <button class="act-btn ghost" type="submit">Subir imagen</button>
                    <button class="act-btn primary" type="button" id="btn-slice">Cortar 24 fichas</button>
                </form>
                <div id="source-preview" class="token-source-preview"></div>
            </section>

            <section class="tokens-assets-section">
                <div class="tokens-assets-head">
                    <h2>Fichas generadas</h2>
                    <button class="act-btn ghost sm" id="btn-save-assets" type="button">Guardar etiquetas</button>
                </div>
                <div id="tokens-assets-grid" class="tokens-assets-grid"></div>
            </section>

            <p id="tokens-message" class="tokens-message" role="status"></p>
        </section>
    </main>

    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/panel/tokenSetsPanel.js?v=tokens-1"></script>
</body>
</html>
