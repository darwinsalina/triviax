<?php
/**
 * TRIVIAX v5.1 — Registro de estudiantes
 * Protegido contra registros automatizados con triviax_registro_antibot_check()
 * (rate limit por IP, honeypot, tiempo mínimo y Turnstile si está configurado).
 */

require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/activity_access.php'; // v7.0: vinculación a grupos

// Si ya está autenticado, redirigir
if (triviax_esta_autenticado()) {
    header('Location: ' . TRIVIAX_BASE . '/index.html');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $nombre      = trim($_POST['nombre']       ?? '');
    $apellido    = trim($_POST['apellido']     ?? '');
    $email       = trim($_POST['email']        ?? '');
    $password    = trim($_POST['password']     ?? '');
    $confirm     = trim($_POST['confirm']      ?? '');
    $codigoGrupo = trim($_POST['codigo_grupo'] ?? ''); // v7.0: opcional

    $antibot = triviax_registro_antibot_check();
    if (!$antibot['ok']) {
        $error = $antibot['error'];
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $resultado = triviax_registrar_usuario($nombre, $apellido, $email, $password, TRIVIAX_ROL_ESTUDIANTE);

        if ($resultado['ok']) {
            // v7.0: asociar la cuenta a los grupos donde el docente importó
            // este email, y unirse por código de grupo si se indicó uno.
            if (!empty($resultado['id'])) {
                triviax_vincular_estudiante_registrado(
                    (int)$resultado['id'], $email, $nombre, $apellido,
                    $codigoGrupo !== '' ? $codigoGrupo : null
                );
            }
            // En local: redirige al simulador de email con el enlace clicable
            if (!empty($resultado['local_preview'])) {
                header('Location: ' . $resultado['local_preview']);
                exit;
            }
            // En remoto: el email ya fue enviado, mostrar mensaje
            $success = "¡Registro exitoso! Te enviamos un correo a <strong>" .
                       htmlspecialchars($email, ENT_QUOTES, 'UTF-8') .
                       "</strong> con un enlace para activar tu cuenta.<br>" .
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
    <title>Registrarse — TRIVIAX</title>
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
            width: 480px;
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
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.3);
            border-radius: 10px;
            padding: 14px 18px;
            color: #86efac;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        .auth-hint {
            color: var(--text-muted);
            font-size: 0.82rem;
            line-height: 1.5;
            text-align: center;
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

        <h2>Crear cuenta de estudiante</h2>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="auth-success"><?= $success ?></div>
            <div style="text-align:center;">
                <a href="<?= TRIVIAX_BASE ?>/auth/login.php" style="color:#a5b4fc;font-weight:600;text-decoration:none;font-size:0.95rem;">
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
                               placeholder="Juan"
                               autocomplete="given-name"
                               required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="apellido">Apellido</label>
                        <input type="text" id="apellido" name="apellido"
                               value="<?= htmlspecialchars($_POST['apellido'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Pérez"
                               autocomplete="family-name">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="tu@correo.com"
                           autocomplete="email"
                           required>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password"
                           placeholder="Al menos 6 caracteres"
                           autocomplete="new-password"
                           required>
                </div>

                <div class="form-group">
                    <label for="confirm">Repetir contraseña</label>
                    <input type="password" id="confirm" name="confirm"
                           placeholder="Repite la contraseña"
                           autocomplete="new-password"
                           required>
                </div>

                <div class="form-group">
                    <label for="codigo_grupo">Código de grupo (opcional)</label>
                    <input type="text" id="codigo_grupo" name="codigo_grupo"
                           value="<?= htmlspecialchars($_POST['codigo_grupo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Si tu docente te dio un código, escríbelo aquí"
                           maxlength="8" autocomplete="off"
                           style="text-transform:uppercase;">
                </div>

                <p class="auth-hint">
                    Al registrarte puedes guardar tu historial de partidas<br>
                    y unirte a actividades con el código de tu docente.
                </p>

                <?= triviax_registro_campos_antibot() ?>

                <button type="submit" class="btn-auth">Crear cuenta</button>
            </div>
        </form>

        <hr class="auth-divider">

        <div class="auth-links">
            <a href="<?= TRIVIAX_BASE ?>/auth/login.php">¿Ya tienes cuenta? <span>Iniciar sesión</span></a>
            <a href="<?= TRIVIAX_BASE ?>/index.html">← Volver al juego</a>
        </div>

        <?php endif; ?>

    </div>
</div>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
