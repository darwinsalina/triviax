<?php
/**
 * TRIVIAX v4.1 — Simulador de email (solo entorno local)
 *
 * En desarrollo local no se envía email real. En cambio, el sistema
 * redirige aquí para mostrar el contenido del mensaje con el enlace
 * de verificación listo para hacer clic.
 *
 * Este archivo es INACCESIBLE en producción (redirige al inicio).
 */

require_once __DIR__ . '/../php/auth.php';

// ── Solo en local ────────────────────────────────────────────────────────────
if (!_triviax_es_local()) {
    header('Location: /triviax/index.html');
    exit;
}

// Parámetros
$type     = trim($_GET['type']     ?? 'verify');  // 'verify' | 'reset'
$token    = trim($_GET['token']    ?? '');
$email    = trim($_GET['email']    ?? '');
$nombre   = trim($_GET['nombre']   ?? 'Usuario');
$apellido = trim($_GET['apellido'] ?? '');
$rol      = trim($_GET['rol']      ?? 'estudiante');

// Validación mínima del token (64 chars hex)
if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    header('Location: /triviax/auth/login.php');
    exit;
}

$rolTxt       = $rol === 'docente' ? 'docente' : 'estudiante';
$verifyUrl    = '/triviax/auth/verificar.php?token=' . urlencode($token);
$resetUrl     = '/triviax/auth/reset_password.php?token=' . urlencode($token);
$destAfter    = $rol === 'docente' ? '/triviax/panel/dashboard.php' : '/triviax/index.html';
$adminEmail   = defined('TRIVIAX_ADMIN_EMAIL') ? TRIVIAX_ADMIN_EMAIL : 'saltmine.development@gmail.com';
$emailDestino = $email !== '' ? $email : 'usuario@ejemplo.local';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulador de correo — TRIVIAX Local</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.6">
    <style>
        /* ── Layout ─────────────────────────────── */
        body { padding: 0; margin: 0; }

        .preview-screen {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 16px 64px;
            gap: 24px;
        }

        /* ── Badge "Modo local" ─────────────────── */
        .local-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(245,158,11,0.12);
            border: 1px solid rgba(245,158,11,0.3);
            border-radius: 30px;
            padding: 7px 18px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #fcd34d;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        /* ── Caja de email ──────────────────────── */
        .email-wrapper {
            width: 100%;
            max-width: 600px;
        }

        .email-header-bar {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 14px 14px 0 0;
            padding: 14px 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .email-header-bar .meta-row {
            display: flex;
            gap: 8px;
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .email-header-bar .meta-row strong {
            color: var(--text-secondary);
            min-width: 52px;
        }

        .email-header-bar .meta-row span {
            color: #a5b4fc;
        }

        .email-body {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-color);
            border-radius: 0 0 14px 14px;
            padding: 28px 28px 32px;
        }

        /* ── Contenido interno del email ────────── */
        .email-logo {
            font-size: 1.8rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
            margin-bottom: 4px;
        }

        .email-subtitle {
            color: #6b7280;
            font-size: 0.82rem;
            margin-bottom: 28px;
        }

        .email-greeting {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .email-text {
            color: var(--text-secondary);
            font-size: 0.92rem;
            line-height: 1.7;
            margin-bottom: 28px;
        }

        /* ── Botón de verificación ──────────────── */
        .verify-btn {
            display: block;
            text-align: center;
            background: var(--accent-gradient);
            color: #fff !important;
            text-decoration: none;
            font-weight: 700;
            font-size: 1.05rem;
            padding: 16px 32px;
            border-radius: 12px;
            letter-spacing: 0.03em;
            transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 20px rgba(99,102,241,0.35);
            margin-bottom: 24px;
        }

        .verify-btn:hover {
            opacity: 0.92;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(99,102,241,0.5);
        }

        .verify-btn:active {
            transform: translateY(0);
        }

        .email-link-raw {
            font-size: 0.75rem;
            color: #6366f1;
            word-break: break-all;
            background: rgba(99,102,241,0.07);
            border: 1px solid rgba(99,102,241,0.15);
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 24px;
        }

        .email-footer {
            font-size: 0.75rem;
            color: #4b5563;
            line-height: 1.6;
            border-top: 1px solid rgba(255,255,255,0.06);
            padding-top: 18px;
        }

        /* ── Notificación admin ─────────────────── */
        .admin-card {
            width: 100%;
            max-width: 600px;
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.2);
            border-radius: 14px;
            padding: 20px 24px;
        }

        .admin-card h3 {
            font-size: 0.88rem;
            font-weight: 700;
            color: #a5b4fc;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0 0 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-card table {
            width: 100%;
            font-size: 0.85rem;
            border-collapse: collapse;
        }

        .admin-card td {
            padding: 5px 0;
            vertical-align: top;
        }

        .admin-card td:first-child {
            color: #6b7280;
            width: 90px;
            padding-right: 12px;
        }

        .admin-card td:last-child {
            color: var(--text-secondary);
        }

        /* ── Nota explicativa ───────────────────── */
        .dev-note {
            width: 100%;
            max-width: 600px;
            background: rgba(245,158,11,0.06);
            border: 1px solid rgba(245,158,11,0.18);
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 0.8rem;
            color: #92400e;
            color: #d97706;
            line-height: 1.6;
        }

        .dev-note strong { color: #fcd34d; }
    </style>
</head>
<body>
<div class="preview-screen">

    <!-- Badge de entorno -->
    <div class="local-badge">
        🖥️ Modo local · Simulador de correo
    </div>

    <?php if ($type === 'reset'): ?>
    <!-- ══ EMAIL: Recuperación de contraseña ══════════════ -->
    <div class="email-wrapper">
        <div class="email-header-bar">
            <div class="meta-row"><strong>Para:</strong>  <span><?= htmlspecialchars($emailDestino, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="meta-row"><strong>De:</strong>    <span>TRIVIAX Sistema &lt;noreply@triviax.app&gt;</span></div>
            <div class="meta-row"><strong>Asunto:</strong><span>TRIVIAX — Restablecé tu contraseña</span></div>
        </div>
        <div class="email-body">
            <div class="email-logo">TRIVIAX</div>
            <div class="email-subtitle">Juego educativo</div>
            <div class="email-greeting">¡Hola, <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>!</div>
            <div class="email-text">
                Recibimos una solicitud para restablecer la contraseña de tu cuenta.<br><br>
                Haz clic en el botón para crear una nueva contraseña:
            </div>
            <a href="<?= htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') ?>" class="verify-btn">
                🔑 Crear nueva contraseña
            </a>
            <div class="email-text" style="margin-bottom:8px;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
            </div>
            <div class="email-link-raw">
                http://localhost<?= htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="email-footer">
                ⏱ Este enlace expira en <strong>1 hora</strong>.<br>
                Si no solicitaste el cambio, ignora este mensaje — tu contraseña no cambiará.
            </div>
        </div>
    </div><!-- /email-wrapper -->

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════
         EMAIL 1: Verificación de cuenta → al usuario
    ══════════════════════════════════════════════════ -->
    <div class="email-wrapper">

        <!-- Encabezado del sobre -->
        <div class="email-header-bar">
            <div class="meta-row"><strong>Para:</strong>  <span><?= htmlspecialchars($emailDestino, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="meta-row"><strong>De:</strong>    <span>TRIVIAX Sistema &lt;noreply@triviax.app&gt;</span></div>
            <div class="meta-row"><strong>Asunto:</strong><span>TRIVIAX — Confirma tu cuenta</span></div>
        </div>

        <!-- Cuerpo del email -->
        <div class="email-body">
            <div class="email-logo">TRIVIAX</div>
            <div class="email-subtitle">Juego educativo</div>

            <div class="email-greeting">
                ¡Hola, <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>!
            </div>

            <div class="email-text">
                Tu cuenta de <strong><?= htmlspecialchars($rolTxt, ENT_QUOTES, 'UTF-8') ?></strong>
                en TRIVIAX fue creada correctamente.<br><br>
                Para activarla y poder iniciar sesión, haz clic en el botón:
            </div>

            <a href="<?= htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') ?>" class="verify-btn">
                ✔ Verificar mi cuenta
            </a>

            <div class="email-text" style="margin-bottom:8px;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
            </div>
            <div class="email-link-raw">
                http://localhost/triviax<?= htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="email-footer">
                ⏱ Este enlace expira en <strong>24 horas</strong>.<br>
                Si no creaste esta cuenta, ignora este mensaje.
            </div>
        </div>

    </div><!-- /email-wrapper -->

    <!-- ══════════════════════════════════════════════════
         EMAIL 2: Notificación al admin (resumen)
    ══════════════════════════════════════════════════ -->
    <div class="admin-card">
        <h3>🔔 Notificación enviada al admin</h3>
        <table>
            <tr><td>Para:</td>      <td><?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Asunto:</td>    <td>TRIVIAX — Nuevo registro: <?= htmlspecialchars($nombre . ' ' . $apellido, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Nombre:</td>    <td><?= htmlspecialchars($nombre . ' ' . $apellido, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Email:</td>     <td><?= htmlspecialchars($emailDestino, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Rol:</td>       <td><?= htmlspecialchars(strtoupper($rolTxt), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Fecha:</td>     <td><?= date('d/m/Y H:i:s') ?></td></tr>
        </table>
    </div>

    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════
         Nota de desarrollo
    ══════════════════════════════════════════════════ -->
    <div class="dev-note">
        <strong>💡 Esta página solo aparece en entorno local.</strong><br>
        En producción (servidor remoto) se envían emails reales y el usuario
        recibe los mensajes en su casilla. Las direcciones de correo ficticias
        no causan ningún problema en modo local.
    </div>

</div><!-- /preview-screen -->
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
