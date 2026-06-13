<?php
/**
 * TRIVIAX v4.2 — Recuperación de contraseña (paso 2: ingresar nueva contraseña)
 *
 * Recibe el token por GET (?token=xxx) y muestra el formulario.
 * Al enviarlo, valida el token y actualiza la contraseña.
 */

require_once __DIR__ . '/../php/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Validar token de forma temprana para dar feedback rápido
$tokenValido = false;
$tokenError  = '';
$nombreUsuario = '';

if ($token !== '') {
    $check = triviax_verificar_reset_token($token);
    if ($check['ok']) {
        $tokenValido    = true;
        $nombreUsuario  = $check['usuario']['nombre'] ?? '';
    } else {
        $tokenError = $check['error'];
    }
} else {
    $tokenError = 'No se recibió ningún token. Usa el enlace del correo.';
}

$error   = '';
$success = false;

// Procesar formulario de nueva contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValido) {
    triviax_verificar_csrf();

    $pass1 = $_POST['password']         ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if (strlen(trim($pass1)) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $resultado = triviax_ejecutar_reset_password($token, $pass1);
        if ($resultado['ok']) {
            $success = true;
        } else {
            $error = $resultado['error'];
        }
    }
}

$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva contraseña — TRIVIAX</title>
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
        .password-hint {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: -2px;
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
            padding: 18px 20px;
            color: #6ee7b7;
            font-size: 0.95rem;
            line-height: 1.7;
            text-align: center;
        }
        .auth-success .success-icon { font-size: 2rem; display: block; margin-bottom: 10px; }
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
            <p>Restablecer contraseña</p>
        </div>

        <?php if ($success): ?>

            <!-- ══ Éxito ══ -->
            <div class="auth-success">
                <span class="success-icon">✅</span>
                <strong>¡Contraseña actualizada!</strong><br>
                Ya puedes iniciar sesión con tu nueva contraseña.
            </div>
            <a href="/triviax/auth/login.php" class="btn-auth" style="text-align:center;text-decoration:none;display:block;">
                Ir al inicio de sesión
            </a>

        <?php elseif (!$tokenValido): ?>

            <!-- ══ Token inválido / expirado ══ -->
            <h2>Enlace inválido</h2>
            <div class="auth-error"><?= htmlspecialchars($tokenError, ENT_QUOTES, 'UTF-8') ?></div>
            <a href="/triviax/auth/recuperar.php" class="btn-auth" style="text-align:center;text-decoration:none;display:block;">
                Solicitar un nuevo enlace
            </a>

        <?php else: ?>

            <!-- ══ Formulario de nueva contraseña ══ -->
            <h2>Nueva contraseña<?= $nombreUsuario !== '' ? ' para ' . htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') : '' ?></h2>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token"      value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div class="form-group">
                        <label for="password">Nueva contraseña</label>
                        <input type="password" id="password" name="password"
                               placeholder="Mínimo 6 caracteres"
                               autocomplete="new-password"
                               required autofocus>
                        <span class="password-hint">Mínimo 6 caracteres.</span>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirma la contraseña</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="Repetí la contraseña"
                               autocomplete="new-password"
                               required>
                    </div>

                    <button type="submit" class="btn-auth">Guardar nueva contraseña</button>
                </div>
            </form>

        <?php endif; ?>

        <hr class="auth-divider">

        <div class="auth-links">
            <a href="/triviax/auth/login.php">← Volver al inicio de sesión</a>
        </div>

    </div>
</div>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
