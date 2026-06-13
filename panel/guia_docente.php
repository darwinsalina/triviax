<?php
/**
 * TRIVIAX — Guía del Docente (visor en línea).
 * Renderiza docs/GUIA_DOCENTE_TRIVIAX.md para consulta desde el panel.
 */

require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/guia_docente_lib.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario  = triviax_usuario_actual();
$guiaHtml = triviax_guia_docente_html();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guía del Docente — TRIVIAX</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.15">
    <style>
        body { overflow: auto; }
        .panel-layout { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 32px; background: rgba(9,13,22,0.85);
            border-bottom: 1px solid var(--border-color); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-logo {
            font-size: 1.5rem; font-weight: 800; background: var(--accent-gradient);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; letter-spacing: 2px;
        }
        .topbar-nav { display: flex; align-items: center; gap: 12px; }
        .topbar-nav a {
            color: var(--text-muted); text-decoration: none; font-size: 0.85rem;
            padding: 6px 12px; border: 1px solid var(--border-color);
            border-radius: 8px; transition: all 0.2s;
        }
        .topbar-nav a:hover { color: var(--text-primary); border-color: var(--accent); }
        .guia-content {
            flex: 1; max-width: 880px; width: 100%; margin: 0 auto;
            padding: 36px 26px 64px;
        }
        .guia-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 40px 44px; line-height: 1.75;
            color: var(--text-secondary);
        }
        .guia-card h1 {
            font-size: 1.8rem; font-weight: 900; color: var(--text-primary);
            margin: 0 0 18px; line-height: 1.3;
        }
        .guia-card h2 {
            font-size: 1.3rem; font-weight: 800; color: var(--text-primary);
            margin: 36px 0 12px; padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .guia-card h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 24px 0 8px; }
        .guia-card h4 { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 18px 0 6px; }
        .guia-card p  { margin: 0 0 12px; }
        .guia-card ul, .guia-card ol { margin: 0 0 14px; padding-left: 26px; }
        .guia-card li { margin-bottom: 6px; }
        .guia-card a  { color: #a5b4fc; text-decoration: none; }
        .guia-card a:hover { text-decoration: underline; }
        .guia-card code {
            background: rgba(99,102,241,0.12); border: 1px solid rgba(99,102,241,0.25);
            border-radius: 5px; padding: 1px 6px; font-size: 0.86em; color: #c7d2fe;
        }
        .guia-card pre {
            background: rgba(0,0,0,0.45); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 14px 16px; overflow-x: auto; margin: 0 0 14px;
        }
        .guia-card pre code { background: none; border: none; padding: 0; color: #d1d5db; }
        .guia-card hr { border: none; border-top: 1px solid rgba(255,255,255,0.08); margin: 26px 0; }
        @media (max-width: 700px) {
            .topbar { padding: 12px 16px; }
            .guia-content { padding: 20px 12px 48px; }
            .guia-card { padding: 24px 18px; }
        }
        @media print {
            .topbar { display: none; }
            .guia-card { border: none; background: none; color: #111; }
            .guia-card h1, .guia-card h2, .guia-card h3, .guia-card h4 { color: #000; }
        }
    </style>
</head>
<body>
<div class="panel-layout">
    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="javascript:window.print()">🖨 Imprimir / PDF</a>
            <a href="/triviax/panel/dashboard.php">← Dashboard</a>
        </div>
    </header>
    <main class="guia-content">
        <div class="guia-card">
            <?php if ($guiaHtml === ''): ?>
                <p>No se encontró el archivo de la guía en el servidor.</p>
            <?php else: ?>
                <?= $guiaHtml ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="/triviax/js/brand.js?v=5.0.15"></script>
</body>
</html>
