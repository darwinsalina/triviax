<?php
/**
 * TRIVIAX — Modalidad "Puzle" (jigsaw) para jugadores.
 *
 * Pantalla propia, fuera del tablero. El catálogo muestra los puzles
 * publicados por los docentes; jugar es abierto (no requiere sesión).
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
    <title>Puzle — TRIVIAX</title>
    <link rel="stylesheet" href="css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="css/actividades.css?v=6.0.0">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="index.html" aria-label="Volver al inicio de TRIVIAX">🧩 <span>Puzle</span> <em>TRIVIAX</em></a>
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
                <h1>Armá el rompecabezas</h1>
                <p>Elegí un puzle preparado por tus docentes, definí la dificultad si corresponde y resolvelo arrastrando las piezas.</p>
            </div>
            <div class="act-grid-cards" id="catalog" aria-live="polite"></div>
            <p class="act-empty" id="catalog-empty" hidden>Todavía no hay puzles publicados.</p>
        </section>

        <!-- Configurar partida -->
        <section class="act-screen" data-screen="setup">
            <div class="act-panel">
                <button type="button" class="act-back" data-go="catalog">‹ Volver al catálogo</button>
                <h2 id="setup-title">Puzle</h2>
                <div class="act-setup-layout">
                    <div class="act-setup-figure"><img id="setup-img" alt="Imagen del puzle"></div>
                    <div>
                        <div class="act-field" id="setup-grid-wrap">
                            <span class="act-label">Dificultad (cuadrícula)</span>
                            <select id="setup-grid">
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
                        <div class="act-field" id="setup-mode-wrap" hidden>
                            <span class="act-label">Modo de juego</span>
                            <label class="act-radio"><input type="radio" name="setupmode" value="tray" checked> Bandeja</label>
                            <label class="act-radio"><input type="radio" name="setupmode" value="scatter"> Revueltas</label>
                        </div>
                        <button type="button" class="act-btn primary big" id="btn-start-play">Armar puzle ▶</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Juego -->
        <section class="act-screen" data-screen="game">
            <div class="act-game-bar">
                <button type="button" class="act-btn ghost sm" data-go="catalog">‹ Salir</button>
                <span class="act-game-info" id="game-info">—</span>
                <div class="act-game-bar-actions">
                    <button type="button" class="act-btn ghost sm" id="btn-hint-toggle">Pista</button>
                    <button type="button" class="act-btn ghost sm" id="btn-shuffle">Barajar</button>
                </div>
            </div>
            <div class="act-game-area" id="game-area">
                <div class="board-wrap"><div class="board" id="board"></div></div>
                <div class="tray" id="tray" hidden></div>
            </div>
            <div class="act-win" id="win-banner" hidden>
                <div class="act-win-card">
                    <div class="act-win-emoji">🎉</div>
                    <h3>¡Puzle resuelto!</h3>
                    <p id="win-detail">—</p>
                    <div class="act-win-actions">
                        <button type="button" class="act-btn ghost" data-go="catalog">Otro puzle</button>
                        <button type="button" class="act-btn primary" id="btn-replay">Jugar de nuevo</button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <div class="piece-magnifier" id="magnifier" hidden></div>
    <svg id="clip-defs" width="0" height="0" aria-hidden="true" style="position:absolute"><defs></defs></svg>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = ''; /* esta página vive en la raíz del proyecto */
    </script>
    <script src="js/brand.js?v=6.0.0"></script>
    <script src="js/engines/jigsawEngine.js?v=6.0.0"></script>
</body>
</html>
