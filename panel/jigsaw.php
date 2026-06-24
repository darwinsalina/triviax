<?php
/**
 * TRIVIAX — Panel docente de la modalidad "Puzle" (jigsaw).
 * Crear, publicar/despublicar, eliminar y ver el reporte de los puzles propios.
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
    <title>Puzle — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=6.0.1">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">🧩 <span>Puzle</span> <em>Panel docente</em></a>
        <nav class="act-topnav">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/jigsaw.php" target="_blank">Ver juego ↗</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="act-main">
        <!-- Gestión -->
        <section class="act-screen active" data-screen="manage">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Tus puzles</h2>
                    <button type="button" class="act-btn primary sm" data-go="create">➕ Nuevo puzle</button>
                </div>
                <div class="act-grid-cards" id="project-list" aria-live="polite"></div>
                <p class="act-empty" id="list-empty" hidden>Todavía no creaste ningún puzle.</p>
            </div>
        </section>

        <!-- Crear -->
        <section class="act-screen" data-screen="create">
            <div class="act-panel">
                <button type="button" class="act-back" data-go="manage">‹ Volver</button>
                <h2>Nuevo puzle</h2>
                <form id="create-form" autocomplete="off">
                    <label class="act-field">
                        <span class="act-label">Nombre de la actividad</span>
                        <input type="text" id="f-name" maxlength="200" required placeholder="Ej: Mapa de América del Sur">
                    </label>
                    <label class="act-field">
                        <span class="act-label">Imagen del puzle</span>
                        <input type="file" id="f-image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <small class="act-hint">Se guardará optimizada en JPG (calidad 80).</small>
                    </label>
                    <div class="act-preview" id="create-preview" hidden>
                        <img id="create-preview-img" alt="Vista previa">
                    </div>

                    <fieldset class="act-field">
                        <legend class="act-label">Dificultad</legend>
                        <label class="act-radio"><input type="radio" name="diffmode" value="free" checked> El jugador la elige (libre)</label>
                        <label class="act-radio"><input type="radio" name="diffmode" value="fixed"> Fija</label>
                        <div class="act-subfield" id="fixed-grid-wrap" hidden>
                            <span class="act-label">Cuadrícula</span>
                            <select id="f-fixed-grid">
                                <option value="3">3 × 3 (9 piezas)</option>
                                <option value="4" selected>4 × 4 (16 piezas)</option>
                                <option value="5">5 × 5 (25 piezas)</option>
                                <option value="6">6 × 6 (36 piezas)</option>
                                <option value="8">8 × 8 (64 piezas)</option>
                                <option value="10">10 × 10 (100 piezas)</option>
                                <option value="12">12 × 12 (144 piezas)</option>
                                <option value="16">16 × 16 (256 piezas)</option>
                            </select>
                        </div>
                    </fieldset>

                    <fieldset class="act-field">
                        <legend class="act-label">Forma de las piezas</legend>
                        <label class="act-radio"><input type="radio" name="shape" value="classic" checked> Clásica — rectangulares</label>
                        <label class="act-radio"><input type="radio" name="shape" value="jigsaw"> Jigsaw — con pestañas y huecos</label>
                    </fieldset>

                    <fieldset class="act-field">
                        <legend class="act-label">Modo de juego</legend>
                        <label class="act-radio"><input type="radio" name="playmode" value="tray" checked> Bandeja — grilla vacía y piezas al costado</label>
                        <label class="act-radio"><input type="radio" name="playmode" value="scatter"> Revueltas — piezas barajadas sobre la grilla</label>
                        <label class="act-radio"><input type="radio" name="playmode" value="choose"> Que el jugador elija</label>
                    </fieldset>

                    <fieldset class="act-field">
                        <legend class="act-label">Pista de fondo</legend>
                        <label class="act-check"><input type="checkbox" id="f-show-hint"> Mostrar la imagen tenue detrás de la grilla</label>
                        <div class="act-subfield" id="hint-opacity-wrap" hidden>
                            <span class="act-label">Opacidad: <strong id="hint-opacity-val">60</strong>%</span>
                            <input type="range" id="f-hint-opacity" min="10" max="90" value="60" step="5">
                        </div>
                    </fieldset>

                    <div class="act-actions">
                        <button type="button" class="act-btn ghost" data-go="manage">Cancelar</button>
                        <button type="submit" class="act-btn primary" id="btn-create">Crear puzle</button>
                    </div>
                    <p class="act-msg" id="create-msg" role="status"></p>
                </form>
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
        window.TRIVIAX_BASE = '../';   /* este panel vive en /panel/ */
        window.TRIVIAX_API  = '../api.php';
    </script>
    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=6.0.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/panel/jigsawPanel.js?v=6.0.0"></script>
</body>
</html>
