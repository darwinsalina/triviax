<?php
/**
 * TRIVIAX v4.1 — Verificación de email
 * El usuario llega aquí desde el enlace enviado por email.
 * Activa la cuenta y redirige al panel correspondiente.
 */

require_once __DIR__ . '/../php/auth.php';

$token = trim($_GET['token'] ?? '');

$resultado   = null;
$autoLoginOk = false;

if ($token !== '') {
    // ── Hay token: procesar siempre, aunque el usuario ya tenga sesión ──
    // Caso típico: el usuario tenía una sesión de otro rol o de una cuenta
    // distinta cuando llegó al enlace de verificación. Cerramos esa sesión
    // antes de activar la nueva cuenta para no mezclar identidades.
    if (triviax_esta_autenticado()) {
        triviax_logout();
    }

    $resultado = triviax_verificar_token_email($token);

    if ($resultado['ok'] && $resultado['usuario'] !== null) {
        // Auto-login con la cuenta recién verificada
        triviax_session_start();
        session_regenerate_id(true);
        $_SESSION['triviax_usuario'] = $resultado['usuario'];
        $autoLoginOk = true;
    }
} else {
    // ── Sin token: si ya está autenticado, llevarlo a su panel ──────────
    if (triviax_esta_autenticado()) {
        $destino = triviax_es_docente() ? '/triviax/panel/dashboard.php' : '/triviax/index.html';
        header('Location: ' . $destino);
        exit;
    }
}

// Determinar destino de redirección automática (3 seg)
$destino = '/triviax/auth/login.php';
if ($autoLoginOk) {
    $destino = ($resultado['usuario']['rol'] === TRIVIAX_ROL_DOCENTE)
        ? '/triviax/panel/dashboard.php?bienvenido=1'
        : '/triviax/index.html?bienvenido=1';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar cuenta — TRIVIAX</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.6">
    <?php if ($autoLoginOk): ?>
    <meta http-equiv="refresh" content="4;url=<?= htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
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
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            backdrop-filter: blur(16px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), 0 0 0 1px var(--border-glow);
            text-align: center;
        }

        .auth-logo .logo-text {
            font-size: 2.2rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
        }

        .verify-icon {
            font-size: 3.5rem;
            line-height: 1;
        }

        .verify-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .verify-msg {
            color: var(--text-secondary);
            line-height: 1.7;
            font-size: 0.95rem;
            margin: 0;
        }

        .verify-success {
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.3);
            border-radius: 12px;
            padding: 14px 20px;
            color: #86efac;
            font-size: 0.9rem;
            font-weight: 500;
            width: 100%;
        }

        .verify-error {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 12px;
            padding: 14px 20px;
            color: #fca5a5;
            font-size: 0.9rem;
            font-weight: 500;
            width: 100%;
        }

        .verify-warn {
            background: rgba(245,158,11,0.1);
            border: 1px solid rgba(245,158,11,0.25);
            border-radius: 12px;
            padding: 14px 20px;
            color: #fcd34d;
            font-size: 0.9rem;
            width: 100%;
        }

        .btn-auth {
            display: inline-block;
            background: var(--accent-gradient);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px 32px;
            font-family: var(--font-main);
            font-size: 1rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            letter-spacing: 0.03em;
        }

        .btn-auth:hover { opacity: 0.9; transform: translateY(-1px); }

        .redirect-bar {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.07);
            border-radius: 4px;
            overflow: hidden;
        }

        .redirect-bar-fill {
            height: 100%;
            background: var(--accent-gradient);
            border-radius: 4px;
            animation: countdown 4s linear forwards;
        }

        @keyframes countdown {
            from { width: 100%; }
            to   { width: 0%; }
        }

        .auth-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .auth-link:hover { color: var(--text-primary); }
        .auth-link span { color: #a5b4fc; font-weight: 600; }
    </style>
</head>
<body>
<div class="auth-screen">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="logo-text">TRIVIAX</div>
        </div>

        <?php if ($token === ''): ?>
            <!-- Sin token -->
            <div class="verify-icon">❓</div>
            <h2 class="verify-title">Enlace inválido</h2>
            <p class="verify-msg">No se encontró un token de verificación en el enlace.</p>
            <div class="verify-warn">
                Asegúrate de copiar el enlace completo desde tu correo.
            </div>
            <a href="/triviax/auth/login.php" class="auth-link">← Volver al inicio de sesión</a>

        <?php elseif ($resultado['ok']): ?>
            <!-- Verificación exitosa -->
            <div class="verify-icon">✅</div>
            <h2 class="verify-title">¡Cuenta verificada!</h2>
            <p class="verify-msg">
                Hola, <strong><?= htmlspecialchars($resultado['usuario']['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>.<br>
                Tu cuenta quedó activada correctamente.<br>
                En unos segundos te redirigimos automáticamente.
            </p>
            <div class="verify-success">
                🎉 Estás ingresando como
                <strong><?= $resultado['usuario']['rol'] === TRIVIAX_ROL_DOCENTE ? 'docente' : 'estudiante' ?></strong>
            </div>
            <div class="redirect-bar"><div class="redirect-bar-fill"></div></div>
            <a href="<?= htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') ?>" class="btn-auth">
                Ingresar ahora →
            </a>

        <?php else: ?>
            <!-- Error en la verificación -->
            <div class="verify-icon">⚠️</div>
            <h2 class="verify-title">No se pudo verificar</h2>
            <div class="verify-error">
                <?= htmlspecialchars($resultado['error'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="verify-msg">
                Si el problema persiste, intenta registrarte de nuevo<br>
                o contacta al administrador del sistema.
            </p>
            <a href="/triviax/auth/registro.php" class="btn-auth">Registrarme de nuevo</a>
            <a href="/triviax/auth/login.php" class="auth-link">← Volver al inicio de sesión</a>

        <?php endif; ?>

    </div>
</div>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
