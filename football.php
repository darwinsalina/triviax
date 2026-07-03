<?php
/**
 * TRIVIAX Futbol - Camino al Gol.
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
    <title>TRIVIAX Futbol - Camino al Gol</title>
    <link rel="stylesheet" href="css/styles.css?v=6.1.3">
    <link rel="stylesheet" href="css/actividades.css?v=football-1">
</head>
<body class="act-app football-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="index.html" aria-label="Volver al inicio">⚽ <span>TRIVIAX Futbol</span> <em>Camino al Gol</em></a>
        <nav class="act-topnav" aria-label="Navegacion">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="index.html">Inicio</a>
            <?php if ($usuario): ?>
                <a class="act-btn ghost sm" href="auth/logout.php">Salir</a>
            <?php else: ?>
                <a class="act-btn ghost sm" href="auth/login.php">Ingresar</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="football-shell">
        <section class="football-control-panel" aria-label="Configuracion y estado">
            <div class="football-scoreboard">
                <div class="football-team football-team-blue">
                    <span>Azul</span>
                    <strong id="score-blue">0</strong>
                    <small id="pos-blue">Pos. 0</small>
                </div>
                <div class="football-turn">
                    <span id="turn-label">Configura equipos</span>
                    <strong id="dice-label">D6</strong>
                </div>
                <div class="football-team football-team-red">
                    <span>Rojo</span>
                    <strong id="score-red">0</strong>
                    <small id="pos-red">Pos. 0</small>
                </div>
            </div>

            <form class="football-setup" id="football-setup">
                <label class="act-field">
                    <span class="act-label">Equipo azul</span>
                    <input type="text" id="team-blue" value="Azul" maxlength="120" placeholder="Ana, Luis">
                </label>
                <label class="act-field">
                    <span class="act-label">Equipo rojo</span>
                    <input type="text" id="team-red" value="Rojo" maxlength="120" placeholder="Eva, Tomi">
                </label>
                <label class="act-field">
                    <span class="act-label">Ayuda del equipo</span>
                    <select id="answer-mode">
                        <option value="short_team_help">Consulta breve</option>
                        <option value="no_help">Responde quien rota</option>
                        <option value="consensus">Consenso del equipo</option>
                    </select>
                </label>
                <button type="submit" class="act-btn primary">Crear partido</button>
            </form>

            <div class="football-play-panel" id="play-panel" hidden>
                <p class="football-member" id="member-label">-</p>
                <button type="button" class="act-btn primary" id="btn-roll">Tirar dado</button>
                <div class="football-question" id="question-box" hidden>
                    <p class="football-play" id="play-label"></p>
                    <h2 id="question-prompt"></h2>
                    <div class="football-options" id="question-options"></div>
                </div>
                <p class="act-msg" id="football-msg" role="status"></p>
            </div>
        </section>

        <section class="football-pitch-card" aria-label="Cancha de juego">
            <div class="football-pitch" id="football-pitch">
                <img src="images/cancha.png" alt="Cancha de futbol vista desde arriba">
                <svg class="football-routes" id="football-routes" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"></svg>
                <div class="football-markers" id="football-markers"></div>
                <div class="football-token token-blue" id="token-blue" aria-label="Ficha azul"></div>
                <div class="football-token token-red" id="token-red" aria-label="Ficha roja"></div>
                <div class="football-goal-flash" id="goal-flash" hidden>Gol academico</div>
            </div>
        </section>
    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = '';
    </script>
    <script src="js/brand.js?v=6.1.3"></script>
    <script src="js/validators/footballValidator.js?v=football-1"></script>
    <script src="js/engines/footballGoalRaceEngine.js?v=football-1"></script>
</body>
</html>
