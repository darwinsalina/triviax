<?php
/**
 * TRIVIAX — Modalidad "Sopa de letras" para jugadores.
 *
 * Pantalla propia, fuera del tablero. El catálogo muestra las sopas de
 * letras publicadas por los docentes; jugar es abierto (no requiere
 * sesión). Con ?id=N se abre una actividad directamente (enlace que el
 * docente comparte con sus estudiantes).
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
    <title>Sopa de letras — TRIVIAX</title>
    <link rel="stylesheet" href="css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="css/actividades.css?v=6.3.0">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="index.html" aria-label="Volver al inicio de TRIVIAX">🔤 <span>Sopa de letras</span> <em>TRIVIAX</em></a>
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
                <h1>Encontrá las palabras</h1>
                <p>Elegí una sopa de letras preparada por tus docentes y marcá las palabras arrastrando sobre la grilla, en cualquier dirección habilitada.</p>
            </div>
            <div class="act-grid-cards" id="catalog" aria-live="polite"></div>
            <p class="act-empty" id="catalog-empty" hidden>Todavía no hay sopas de letras publicadas.</p>
        </section>

        <!-- Juego -->
        <section class="act-screen" data-screen="game">
            <div class="act-game-bar">
                <button type="button" class="act-btn ghost sm" data-go="catalog">‹ Salir</button>
                <span class="act-game-info" id="game-info">—</span>
                <div class="act-game-bar-actions">
                    <button type="button" class="act-btn ghost sm" id="btn-hint">💡 Pista</button>
                    <button type="button" class="act-btn ghost sm" id="btn-replay-game">Reiniciar</button>
                </div>
            </div>
            <div class="act-game-area">
                <div class="ws-grid-wrap">
                    <div class="ws-grid" id="ws-grid"></div>
                </div>
                <aside class="word-panel">
                    <h3>Palabras <span class="tray-count" id="word-progress">0/0</span></h3>
                    <ul class="word-list" id="word-list"></ul>
                </aside>
            </div>
            <div class="act-win" id="win-banner" hidden>
                <div class="act-win-card">
                    <div class="act-win-emoji">🎉</div>
                    <h3>¡Sopa resuelta!</h3>
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
        window.TRIVIAX_BASE = ''; /* esta página vive en la raíz del proyecto */
    </script>
    <script src="js/brand.js?v=6.0.0"></script>
    <script src="js/wordsearch.js?v=6.3.0"></script>
    <script src="js/engines/wordsearchPlayer.js?v=6.3.0"></script>
</body>
</html>
