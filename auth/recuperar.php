<?php
/**
 * TRIVIAX v4.2 — Recuperación de contraseña (paso 1: pedir email)
 */

require_once __DIR__ . '/../php/auth.php';

// Si ya tiene sesión activa, redirigir a su panel
if (triviax_esta_autenticado()) {
    if (triviax_es_superadmin()) {
        header('Location: ' . TRIVIAX_BASE . '/panel/super.php');
    } elseif (triviax_es_docente()) {
        header('Location: ' . TRIVIAX_BASE . '/panel/dashboard.php');
    } else {
        header('Location: ' . TRIVIAX_BASE . '/index.html');
    }
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $email = trim($_POST['email'] ?? '');

    $resultado = triviax_solicitar_reset_password($email);

    if ($resultado['ok']) {
        if (!empty($resultado['local_preview'])) {
            // Modo local: abrir simulador de email
            header('Location: ' . $resultado['local_preview']);
            exit;
        }
        // Modo remoto: confirmación
        $success = 'Te enviamos un correo a <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong> '
                 . 'con el enlace para restablecer tu contraseña. Revisa también la carpeta de spam.';
    } else {
        $error = $resultado['error'];
    }
}

$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
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
        .auth-logo { text-align: center; }
        .auth-logo .logo-text {
            font-size: 2.4rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
        }
        .auth-logo p { color: var(--text-secondary); font-size: 0.95rem; margin-top: 4px; }
        .auth-card h2 { font-size: 1.3rem; font-weight: 700; color: var(--text-primary); text-align: center; }
        .auth-card p.desc { color: var(--text-secondary); font-size: 0.9rem; text-align: center; line-height: 1.6; margin: 0; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
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
        .form-group input::placeholder { color: var(--text-muted); }
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
        .btn-auth:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-auth:active { transform: translateY(0); }
        .auth-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            border-radius: 10px;
            padding: 10px 14px;
            color: #fca5a5;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .auth-success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.3);
            border-radius: 10px;
            padding: 10px 14px;
            color: #6ee7b7;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        .auth-links { display: flex; flex-direction: column; gap: 10px; text-align: center; }
        .auth-links a { color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; transition: color 0.2s; }
        .auth-links a:hover { color: var(--text-primary); }
        .auth-links a span { color: #a5b4fc; font-weight: 600; }
        .auth-divider { border: none; border-top: 1px solid var(--border-color); margin: 0; }
    </style>
</head>
<body>
<div class="auth-screen">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="logo-text">TRIVIAX</div>
            <p>Recuperación de contraseña</p>
        </div>

        <h2>¿Olvidaste tu contraseña?</h2>
        <p class="desc">Ingresa tu correo electrónico y te enviaremos un enlace para crear una nueva contraseña.</p>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="auth-success"><?= $success ?></div>
        <?php else: ?>
        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="tu@correo.com"
                           autocomplete="email"
                           required autofocus>
                </div>
                <button type="submit" class="btn-auth">Enviar enlace de recuperación</button>
            </div>
        </form>
        <?php endif; ?>

        <hr class="auth-divider">

        <div class="auth-links">
            <a href="<?= TRIVIAX_BASE ?>/auth/login.php">← Volver al inicio de sesión</a>
            <a href="<?= TRIVIAX_BASE ?>/index.html">← Volver al juego</a>
        </div>

    </div>
</div>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
