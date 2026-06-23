<?php
/**
 * TRIVIAX — Diagnóstico de envío de email (solo superadmin)
 *
 * Permite enviar un correo de prueba para verificar que mail() funcione
 * correctamente en el servidor de producción.
 * URL: /triviax/panel/test_mail.php
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_superadmin();

$result  = null;
$error   = null;
$testTo  = $_POST['test_to'] ?? TRIVIAX_ADMIN_EMAIL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $dest = trim($_POST['test_to'] ?? '');
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        $error = 'Dirección de destino no válida.';
    } else {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body'
              . ' style="margin:0;padding:32px;background:#090d16;font-family:system-ui,sans-serif;color:#e5e7eb;">'
              . '<h1 style="margin:0 0 8px;font-size:1.8rem;font-weight:800;letter-spacing:2px;'
              . 'background:linear-gradient(135deg,#6366f1,#a855f7);-webkit-background-clip:text;'
              . '-webkit-text-fill-color:transparent;background-clip:text;">TRIVIAX</h1>'
              . '<p style="color:#9ca3af;margin:0 0 24px;">Diagnóstico de correo — ' . date('d/m/Y H:i:s') . '</p>'
              . '<p style="color:#d1d5db;line-height:1.7;">✅ <strong>El envío de correos está funcionando.</strong><br>'
              . 'Este mensaje fue enviado desde <code>' . htmlspecialchars(TRIVIAX_FROM_EMAIL, ENT_QUOTES, 'UTF-8') . '</code>'
              . ' al hacer clic en "Enviar correo de prueba" en el panel superadmin.</p>'
              . '</body></html>';

        $ok = _triviax_enviar_email(
            $dest,
            'TRIVIAX — Correo de prueba',
            $html,
            'Test de envío de correo TRIVIAX — ' . date('d/m/Y H:i:s')
        );

        if ($ok) {
            $result = 'ok';
        } else {
            $result = 'fail';
            // Intentar leer el último error PHP para más contexto
            $le = error_get_last();
            $error = $le ? $le['message'] : 'mail() devolvió false sin detalle adicional.';
        }
    }
}

$csrf = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test de correo — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }
        .test-screen {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 16px;
        }
        .test-card {
            width: 560px;
            max-width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 36px 32px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            backdrop-filter: blur(16px);
        }
        .test-card h1 {
            font-size: 1.15rem; font-weight: 700;
            color: var(--text-primary); margin: 0;
            display: flex; align-items: center; gap: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 6px 16px;
            font-size: 0.85rem;
        }
        .info-grid .lbl { color: var(--text-muted); font-weight: 600; white-space: nowrap; }
        .info-grid .val { color: var(--text-secondary); font-family: monospace; }
        .alert {
            padding: 12px 16px; border-radius: 10px; font-size: 0.9rem; line-height: 1.6;
        }
        .alert-ok   { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }
        .alert-fail { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.3);  color: #fca5a5; }
        .alert-info { background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.2); color: #a5b4fc; font-size: 0.82rem; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
        .form-group input {
            background: rgba(255,255,255,0.05); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 11px 14px; color: var(--text-primary);
            font-size: 0.95rem; font-family: var(--font-main); outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .btn-send {
            background: var(--accent-gradient); color: #fff; border: none;
            border-radius: 10px; padding: 12px; font-family: var(--font-main);
            font-size: 0.95rem; font-weight: 700; cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
        }
        .btn-send:hover { opacity: 0.9; transform: translateY(-1px); }
        hr { border: none; border-top: 1px solid var(--border-color); }
        .back-link { font-size: 0.85rem; color: var(--text-muted); text-decoration: none; }
        .back-link:hover { color: var(--text-secondary); }
        code { background: rgba(255,255,255,0.07); border-radius: 4px; padding: 1px 6px; font-size: 0.88em; }
    </style>
</head>
<body>
<div class="test-screen">
    <div class="test-card">
        <h1>📧 Diagnóstico de envío de correo</h1>

        <!-- Configuración actual -->
        <div class="info-grid">
            <span class="lbl">From:</span>
            <span class="val"><?= htmlspecialchars(TRIVIAX_FROM_NAME . ' <' . TRIVIAX_FROM_EMAIL . '>', ENT_QUOTES, 'UTF-8') ?></span>

            <span class="lbl">Reply-To:</span>
            <span class="val"><?= htmlspecialchars(TRIVIAX_ADMIN_EMAIL, ENT_QUOTES, 'UTF-8') ?></span>

            <span class="lbl">Función:</span>
            <span class="val">PHP mail() — sendmail nativo del servidor</span>

            <span class="lbl">PHP:</span>
            <span class="val"><?= phpversion() ?></span>

            <span class="lbl">Host:</span>
            <span class="val"><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>

            <span class="lbl">Entorno:</span>
            <span class="val"><?= _triviax_es_local() ? '🖥️ Local (WAMP)' : '🌐 Servidor remoto' ?></span>
        </div>

        <?php if (_triviax_es_local()): ?>
        <div class="alert alert-info">
            ℹ️ Estás en <strong>entorno local</strong>. El servidor de WAMP generalmente no tiene
            un agente de correo (MTA) configurado, por lo que <code>mail()</code> puede devolver
            <code>false</code> aunque la configuración sea correcta.<br>
            Para probar el flujo completo de emails, usa el simulador de correo (<code>mail_preview.php</code>).
        </div>
        <?php endif; ?>

        <?php if ($result === 'ok'): ?>
        <div class="alert alert-ok">
            ✅ <strong>mail() devolvió <code>true</code></strong> — el mensaje fue aceptado por el MTA local.<br>
            Revisa la bandeja de entrada de <strong><?= htmlspecialchars($testTo, ENT_QUOTES, 'UTF-8') ?></strong>
            (también la carpeta de <em>spam/correo no deseado</em>).
        </div>
        <?php elseif ($result === 'fail'): ?>
        <div class="alert alert-fail">
            ❌ <strong>mail() devolvió <code>false</code></strong> — el mensaje no fue aceptado.<br>
            <strong>Detalle:</strong> <?= htmlspecialchars($error ?? '—', ENT_QUOTES, 'UTF-8') ?><br><br>
            <strong>Posibles causas en el servidor:</strong><br>
            • <code>sendmail_path</code> no configurado en <code>php.ini</code><br>
            • El usuario del servidor web no tiene permiso para ejecutar sendmail<br>
            • El hosting bloquea <code>mail()</code> — puede requerir SMTP externo (PHPMailer/SMTP)
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="test_to">Enviar correo de prueba a</label>
                <input type="email" id="test_to" name="test_to"
                       value="<?= htmlspecialchars($testTo, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="destinatario@ejemplo.com"
                       required>
            </div>
            <br>
            <button type="submit" class="btn-send">📨 Enviar correo de prueba</button>
        </form>

        <hr>
        <a href="<?= TRIVIAX_BASE ?>/panel/super.php" class="back-link">← Volver al panel superadmin</a>
    </div>
</div>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
