<?php
/**
 * TRIVIAX — Modalidad "Etiquetar" (etiquetar) para jugadores.
 *
 * Catálogo de actividades publicadas + juego de arrastrar etiquetas a su
 * casilla. Jugar es abierto (no requiere sesión).
 */

require_once __DIR__ . '/php/auth.php';

$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$usuario = triviax_usuario_actual();
$nombreUsuario = $usuario ? trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')) : 'Invitado';
$nombreUsuario = $nombreUsuario !== '' ? $nombreUsuario : 'Invitado';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetar — TRIVIAX</title>
    <link rel="stylesheet" href="css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="css/actividades.css?v=6.0.0">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="index.html" aria-label="Volver al inicio de TRIVIAX">🏷️ <span>Etiquetar</span> <em>TRIVIAX</em></a>
        <nav class="act-topnav" aria-label="Navegación">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="index.html">Inicio</a>
            <?php if ($usuario): ?>
                <a class="act-btn ghost sm" href="auth/logout.php">Salir</a>
            <?php else: ?>
                <a class="act-btn ghost sm" href="auth/login.php">Ingresar</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="act-main">
        <!-- Catálogo -->
        <section class="act-screen active" data-screen="catalog">
            <div class="act-hero">
                <h1>Etiquetá la imagen</h1>
                <p>Elegí una actividad y arrastrá cada etiqueta hasta la casilla que señala su punto exacto en la imagen.</p>
            </div>
            <div class="act-grid-cards" id="catalog" aria-live="polite"></div>
            <p class="act-empty" id="catalog-empty" hidden>Todavía no hay actividades publicadas.</p>
        </section>

        <!-- Juego -->
        <section class="act-screen" data-screen="game">
            <div class="act-game-bar">
                <button type="button" class="act-btn ghost sm" data-go="catalog">‹ Salir</button>
                <span class="act-game-info" id="game-info">—</span>
                <button type="button" class="act-btn ghost sm" id="btn-replay-game">Reiniciar</button>
            </div>
            <div class="act-game-area">
                <div class="lab-stage" id="stage-play">
                    <img id="img-play" alt="Imagen de la actividad">
                    <svg class="lab-svg" id="svg-play"></svg>
                    <div class="lab-overlay" id="overlay-play"></div>
                    <div class="lab-tray" id="tray-float">
                        <div class="lab-tray-head" id="tray-head">
                            <span>Etiquetas <span id="tray-count">0</span></span>
                            <button type="button" id="btn-tray-collapse" title="Colapsar / expandir" aria-label="Colapsar o expandir la caja de etiquetas">▢</button>
                        </div>
                        <div class="lab-tray-body" id="tray"></div>
                    </div>
                </div>
            </div>
            <div class="act-win" id="win-banner" hidden>
                <div class="act-win-card">
                    <div class="act-win-emoji">🎉</div>
                    <h3>¡Todo etiquetado!</h3>
                    <p id="win-detail">—</p>
                    <div class="act-win-actions">
                        <button type="button" class="act-btn ghost" data-go="catalog">Otra actividad</button>
                        <button type="button" class="act-btn primary" id="btn-replay">Jugar de nuevo</button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = '';
    </script>
    <script src="js/brand.js?v=6.0.0"></script>
    <script src="js/engines/etiquetarEngine.js?v=6.0.0"></script>
</body>
</html>
