<?php
/**
 * TRIVIAX — Panel docente de la modalidad "Etiquetar" (etiquetar).
 * Editor (imagen + etiquetas con punto-ancla y línea guía), gestión y reporte.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$nombreUsuario = trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')) ?: 'Docente';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetar — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=6.0.1">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">🏷️ <span>Etiquetar</span> <em>Panel docente</em></a>
        <nav class="act-topnav">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/etiquetar.php" target="_blank">Ver juego ↗</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="act-main">
        <!-- Gestión -->
        <section class="act-screen active" data-screen="manage">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Tus actividades de etiquetado</h2>
                    <button type="button" class="act-btn primary sm" data-go="create">➕ Nueva actividad</button>
                </div>
                <div class="act-grid-cards" id="project-list" aria-live="polite"></div>
                <p class="act-empty" id="list-empty" hidden>Todavía no creaste ninguna actividad.</p>
            </div>
        </section>

        <!-- Crear / Editor -->
        <section class="act-screen" data-screen="create">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Nueva actividad de etiquetado</h2>
                    <div class="act-actions" style="margin:0">
                        <button type="button" class="act-btn ghost" data-go="manage">Cancelar</button>
                        <button type="button" class="act-btn primary" id="btn-create" disabled>Crear actividad</button>
                    </div>
                </div>
                <p class="act-msg" id="create-msg" role="status"></p>

                <div class="act-editor-layout" id="editor-setup-row">
                    <label class="act-field">
                        <span class="act-label">Nombre de la actividad</span>
                        <input type="text" id="f-name" maxlength="200" placeholder="Ej: Partes de la célula">
                    </label>
                    <label class="act-field">
                        <span class="act-label">Imagen</span>
                        <input type="file" id="f-image" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small class="act-hint">Se guardará optimizada en JPG (calidad 80).</small>
                    </label>
                </div>

                <div class="act-editor-layout" id="editor-workspace" hidden>
                    <div class="act-editor-sidebar">
                        <div class="act-field" style="margin:0">
                            <span class="act-label">Escribí una etiqueta y pulsá Enter</span>
                            <input type="text" id="f-label-input" placeholder="Ej: Núcleo" autocomplete="off">
                            <small class="act-hint">También podés pegar varias separadas por comas o saltos de línea.</small>
                        </div>
                        <div class="lab-pool-head">
                            <span class="act-label" style="margin:0">Sin colocar</span>
                            <span class="lab-pool-count" id="pool-count">0</span>
                        </div>
                        <div class="lab-pool" id="labels-pool"></div>
                        <p class="act-editor-note">Arrastrá cada etiqueta sobre la imagen. Quedará un punto (el lugar exacto) unido a la etiqueta por una línea. Podés mover el punto o la etiqueta.</p>
                    </div>
                    <div>
                        <div class="lab-stage" id="stage-edit">
                            <img id="img-edit" alt="Imagen de la actividad">
                            <svg class="lab-svg" id="svg-edit"></svg>
                            <div class="lab-overlay" id="overlay-edit"></div>
                            <div class="lab-tray box-preview" id="box-preview" hidden>
                                <div class="lab-tray-head" id="box-preview-head"><span>📋 Caja de etiquetas</span></div>
                                <div class="lab-tray-body">Arrastrame al lugar donde querés que aparezca durante el juego (que no tape zonas importantes).</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Reporte -->
        <section class="act-screen" data-screen="report">
            <div class="act-panel wide">
                <button type="button" class="act-back" data-go="manage">‹ Volver</button>
                <h2 id="report-title">Reporte</h2>
                <div id="report-body"></div>
            </div>
        </section>
    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = '../';
        window.TRIVIAX_API  = '../api.php';
    </script>
    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=6.0.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/panel/etiquetarPanel.js?v=6.0.0"></script>
</body>
</html>
