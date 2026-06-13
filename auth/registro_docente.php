<?php
/**
 * TRIVIAX v5.1 — Registro de cuentas docente
 *
 * Registro abierto: cualquier docente puede crear su cuenta; la cuenta
 * queda inactiva hasta que verifique su email. Contra el abuso automatizado
 * se aplican las capas de triviax_registro_antibot_check() (rate limit por
 * IP, honeypot, tiempo mínimo y Cloudflare Turnstile si está configurado).
 * Una vez creada la cuenta, el acceso es siempre por email + contraseña.
 */

require_once __DIR__ . '/../php/auth.php';

// Si ya está autenticado como docente, ir al panel
if (triviax_es_docente()) {
    header('Location: /triviax/panel/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $nombre    = trim($_POST['nombre']    ?? '');
    $apellido  = trim($_POST['apellido']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = trim($_POST['password']  ?? '');
    $confirm   = trim($_POST['confirm']   ?? '');

    $antibot = triviax_registro_antibot_check();
    if (!$antibot['ok']) {
        $error = $antibot['error'];
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $resultado = triviax_registrar_usuario($nombre, $apellido, $email, $password, TRIVIAX_ROL_DOCENTE);

        if ($resultado['ok']) {
            // En local: redirige al simulador de email con el enlace clicable
            if (!empty($resultado['local_preview'])) {
                header('Location: ' . $resultado['local_preview']);
                exit;
            }
            // En remoto: el email ya fue enviado, mostrar mensaje
            $success = "¡Cuenta docente creada! Te enviamos un correo a <strong>" .
                       htmlspecialchars($email, ENT_QUOTES, 'UTF-8') .
                       "</strong> con un enlace para activarla.<br>" .
                       "Revisa tu bandeja de entrada (y la carpeta de spam).";
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
    <title>Registro docente — TRIVIAX</title>
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
            width: 500px;
            max-width: 95vw;
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

        .auth-logo p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 4px;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(99,102,241,0.2), rgba(168,85,247,0.2));
            border: 1px solid rgba(99,102,241,0.35);
            border-radius: 30px;
            padding: 6px 18px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #c4b5fd;
            letter-spacing: 0.04em;
            margin: 0 auto;
            width: fit-content;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-group input {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 11px 15px;
            color: var(--text-primary);
            font-family: var(--font-main);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .form-group input::placeholder { color: var(--text-muted); }

        .code-hint {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 10px;
            padding: 11px 14px;
            color: #fcd34d;
            font-size: 0.82rem;
            line-height: 1.5;
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
        }

        .btn-auth:hover { opacity: 0.9; transform: translateY(-1px); }

        .auth-success {
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.3);
            border-radius: 10px;
            padding: 14px 18px;
            color: #86efac;
            font-size: 0.92rem;
            line-height: 1.6;
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

        .auth-divider {
            border: none;
            border-top: 1px solid var(--border-color);
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

        .auth-links a:hover { color: var(--text-primary); }
        .auth-links a span { color: #a5b4fc; font-weight: 600; }
    </style>
</head>
<body>
<div class="auth-screen">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="logo-text">TRIVIAX</div>
            <p>Juego educativo de preguntas y respuestas</p>
        </div>

        <div class="role-badge">🎓 Crear cuenta docente</div>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="auth-success"><?= $success ?></div>
            <div style="text-align:center;">
                <a href="/triviax/auth/login.php" style="color:#a5b4fc;font-weight:600;text-decoration:none;font-size:0.95rem;">
                    → Ir al inicio de sesión
                </a>
            </div>
        <?php else: ?>
        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div style="display:flex;flex-direction:column;gap:16px;">

                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">Nombre</label>
                        <input type="text" id="nombre" name="nombre"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ana" autocomplete="given-name" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="apellido">Apellido</label>
                        <input type="text" id="apellido" name="apellido"
                               value="<?= htmlspecialchars($_POST['apellido'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="García" autocomplete="family-name">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Correo electrónico institucional</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="docente@escuela.edu" autocomplete="email" required>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password"
                           placeholder="Al menos 6 caracteres"
                           autocomplete="new-password" required>
                </div>

                <div class="form-group">
                    <label for="confirm">Repetir contraseña</label>
                    <input type="password" id="confirm" name="confirm"
                           placeholder="Repite la contraseña"
                           autocomplete="new-password" required>
                </div>

                <div class="code-hint">
                    ✉️ Te enviaremos un correo con un enlace para activar tu cuenta.
                    Después accedes siempre con tu email y contraseña.
                </div>

                <?= triviax_registro_campos_antibot() ?>

                <button type="submit" class="btn-auth">Crear cuenta docente</button>
            </div>
        </form>

        <hr class="auth-divider">

        <div class="auth-links">
            <a href="/triviax/auth/login.php">¿Ya tienes cuenta? <span>Iniciar sesión</span></a>
            <a href="/triviax/index.html">← Volver al juego</a>
        </div>

        <?php endif; ?>

    </div>
</div>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
