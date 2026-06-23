<?php
/**
 * TRIVIAX — Modalidad "Estudia y responde" para estudiantes.
 *
 * Pantalla propia, separada del tablero, para practicar mazos publicados.
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
    <title>Estudia y responde — TRIVIAX</title>
    <link rel="stylesheet" href="css/styles.css?v=5.0.6">
</head>
<body class="study-page-body">
    <main class="study-page" data-study-app data-csrf="<?php echo $csrf; ?>">
        <header class="study-topbar">
            <a class="study-brand" href="index.html" aria-label="Volver al inicio de TRIVIAX">TRIVIAX</a>
            <nav class="study-topbar-actions" aria-label="Acciones de navegación">
                <span class="study-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
                <a class="btn btn-secondary" href="index.html">Inicio</a>
                <?php if ($usuario): ?>
                    <a class="btn btn-secondary" href="auth/logout.php">Salir</a>
                <?php else: ?>
                    <a class="btn btn-secondary" href="auth/login.php">Ingresar</a>
                <?php endif; ?>
            </nav>
        </header>

        <section class="study-hero">
            <div>
                <p class="study-kicker">Modo de aprendizaje</p>
                <h1>Estudia y responde</h1>
                <p>Leé una carta breve, respondé y repasá automáticamente lo que todavía cuesta.</p>
            </div>
            <div class="study-session-actions">
                <button type="button" class="btn btn-secondary study-hidden" data-study-restart>Nuevo mazo</button>
                <button type="button" class="btn btn-primary study-hidden" data-study-finish>Finalizar</button>
            </div>
        </section>

        <section class="study-status-row" aria-live="polite">
            <div class="study-status" data-study-status>Cargando...</div>
            <div class="study-progress">
                <div class="study-progress-track">
                    <div class="study-progress-bar" data-study-progress-bar></div>
                </div>
                <span data-study-progress-text>0 de 0 cerradas</span>
            </div>
        </section>

        <section class="study-metrics" data-study-metrics aria-label="Progreso de la práctica">
            <div class="study-metric">
                <strong data-metric="points">0</strong>
                <span>Puntos</span>
            </div>
            <div class="study-metric">
                <strong data-metric="accuracy">0%</strong>
                <span>Precisión</span>
            </div>
            <div class="study-metric">
                <strong data-metric="pending">0</strong>
                <span>Pendientes</span>
            </div>
            <div class="study-metric">
                <strong data-metric="mastered">0</strong>
                <span>Dominadas</span>
            </div>
        </section>

        <section class="study-catalog-grid" data-study-catalog aria-label="Mazos publicados"></section>
        <section class="study-workspace study-hidden" data-study-workspace aria-label="Carta actual"></section>
    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="js/brand.js?v=5.0.6"></script>
    <script type="module" src="js/engines/studyAnswerEngine.js?v=6.1.0"></script>
</body>
</html>
