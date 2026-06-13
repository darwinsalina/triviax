<?php
/**
 * TRIVIAX v4.0 — Página de login
 * Docentes y estudiantes se autentican aquí.
 */

require_once __DIR__ . '/../php/auth.php';

$error      = '';
$redir      = $_GET['redir'] ?? '';
$msgExterno = '';

// ── Gestión de sesión activa ──────────────────────────────────────────────
if (triviax_esta_autenticado()) {

    if (($_GET['error'] ?? '') === 'acceso_denegado') {
        // El usuario tiene sesión con el rol incorrecto para la sección que
        // intentó acceder. Cerramos esa sesión para que pueda ingresar con
        // la cuenta correcta. Sin esto se produce un bucle infinito.
        triviax_logout();
        $msgExterno = 'Esa sección requiere una cuenta docente. '
                    . 'Ingresa con tu cuenta docente o regístrate como docente.';
        // Continuamos hacia el formulario de login (no hacemos exit)

    } else {
        // Sesión normal activa → redirigir a su panel propio
        if (triviax_es_superadmin()) {
            header('Location: /triviax/panel/super.php');
        } elseif (triviax_es_docente()) {
            header('Location: /triviax/panel/dashboard.php');
        } else {
            header('Location: /triviax/index.html');
        }
        exit;
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $resultado = triviax_login($email, $password);

    if ($resultado['ok']) {
        // Superadmin → su panel especial (siempre, sin override de redir)
        if (!empty($resultado['redirect'])) {
            header('Location: ' . $resultado['redirect']);
            exit;
        }
        $destino = '/triviax/index.html';
        if ($resultado['usuario']['rol'] === TRIVIAX_ROL_DOCENTE) {
            $destino = '/triviax/panel/dashboard.php';
        }
        // Respetar redirección solicitada si es segura
        if ($redir !== '' && strpos($redir, '/triviax/') === 0) {
            $destino = $redir;
        }
        header('Location: ' . $destino);
        exit;
    }

    $error = $resultado['error'];
}

$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar — TRIVIAX</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.6">
    <style>
        .auth-screen {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100vw;
            height: 100vh;
        }

        .auth-card {
            width: 440px;
            max-width: 94vw;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            backdrop-filter: blur(16px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 0 1px var(--border-glow);
        }

        .auth-logo {
            text-align: center;
        }

        .auth-logo .logo-text {
            font-size: 2.4rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
        }

        .auth-logo p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 4px;
        }

        .auth-card h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            text-align: center;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-group input {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-family: var(--font-main);
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .btn-auth {
            background: var(--accent-gradient);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-family: var(--font-main);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            letter-spacing: 0.03em;
        }

        .btn-auth:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-auth:active {
            transform: translateY(0);
        }

        .auth-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            border-radius: 10px;
            padding: 10px 14px;
            color: #fca5a5;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .auth-info {
            background: rgba(99,102,241,0.1);
            border: 1px solid rgba(99,102,241,0.25);
            border-radius: 10px;
            padding: 10px 14px;
            color: #a5b4fc;
            font-size: 0.9rem;
        }

        .auth-links {
            display: flex;
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }

        .auth-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .auth-links a:hover {
            color: var(--text-primary);
        }

        .auth-links a span {
            color: #a5b4fc;
            font-weight: 600;
        }

        .auth-divider {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 0;
        }
    </style>
</head>
<body>
<div class="auth-screen">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="logo-text">TRIVIAX</div>
            <p>Juego educativo de preguntas y respuestas</p>
        </div>

        <h2>Iniciar sesión</h2>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($msgExterno !== ''): ?>
            <div class="auth-info"><?= htmlspecialchars($msgExterno, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($redir !== ''): ?>
                <input type="hidden" name="redir" value="<?= htmlspecialchars($redir, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div style="display:flex;flex-direction:column;gap:16px;">
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="tu@correo.com"
                           autocomplete="email"
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password"
                           placeholder="••••••••"
                           autocomplete="current-password"
                           required>
                </div>

                <button type="submit" class="btn-auth">Ingresar</button>
            </div>
        </form>

        <hr class="auth-divider">

        <div class="auth-links">
            <a href="/triviax/auth/recuperar.php">¿Olvidaste tu contraseña? <span>Recuperar acceso</span></a>
            <a href="/triviax/auth/registro.php">¿Eres estudiante y no tienes cuenta? <span>Regístrate</span></a>
            <a href="/triviax/auth/registro_docente.php">¿Eres docente y no tienes cuenta? <span>Crear cuenta docente</span></a>
            <a href="/triviax/index.html">← Volver al juego</a>
        </div>

    </div>
</div>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
