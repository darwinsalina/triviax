<?php
/**
 * TRIVIAX - Monitor de una partida en vivo.
 */

require_once __DIR__ . '/../php/auth.php';
require_once __DIR__ . '/../php/live_session_summary.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$pdo = triviax_db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . TRIVIAX_BASE . '/panel/live_sessions.php');
    exit;
}

$sesion = triviax_live_session_load_for_docente($pdo, $id, (int)$usuario['id']);

if (!$sesion) {
    header('Location: ' . TRIVIAX_BASE . '/panel/live_sessions.php');
    exit;
}

if (($_GET['ajax'] ?? '') === 'summary') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(triviax_live_session_summary($pdo, $sesion), JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>En vivo - <?= htmlspecialchars($sesion['nombre'], ENT_QUOTES, 'UTF-8') ?> - TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }
        .live-layout { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 28px; background: rgba(9,13,22,0.9);
            border-bottom: 1px solid var(--border-color); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-logo {
            font-size: 1.4rem; font-weight: 800; background: var(--accent-gradient);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; letter-spacing: 2px;
        }
        .topbar-nav { display: flex; align-items: center; gap: 12px; }
        .topbar-nav a {
            color: var(--text-muted); text-decoration: none; font-size: 0.85rem;
            padding: 6px 12px; border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .topbar-nav a:hover { color: var(--text-primary); border-color: var(--accent); }
        .live-content {
            width: 100%; max-width: 1680px; margin: 0 auto;
            padding: 22px; display: flex; flex-direction: column; gap: 18px;
        }
        .live-header {
            display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: end;
        }
        .live-title { color: var(--text-primary); font-size: 1.7rem; font-weight: 900; }
        .live-meta { color: var(--text-secondary); font-size: 0.95rem; margin-top: 5px; }
        .live-code {
            display: inline-block; color: var(--text-primary); font-family: monospace;
            font-weight: 900; letter-spacing: 2px; background: rgba(255,255,255,0.07);
            border: 1px solid var(--border-color); padding: 6px 10px; border-radius: 8px;
        }
        .summary-grid {
            display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)); gap: 10px;
        }
        .summary-card {
            background: rgba(255,255,255,0.045); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 12px 14px;
        }
        .summary-label {
            color: var(--text-muted); font-size: 0.74rem; font-weight: 800;
            letter-spacing: 0.05em; text-transform: uppercase;
        }
        .summary-value { color: var(--text-primary); font-size: 1.55rem; font-weight: 900; margin-top: 2px; }
        .status-line {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            color: var(--text-muted); font-size: 0.86rem;
        }
        .live-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #10b981;
            display: inline-block; margin-right: 7px; animation: livePulse 1.5s infinite;
        }
        .questions-board {
            column-count: 5; column-gap: 14px; width: 100%;
        }
        .question-card {
            break-inside: avoid; display: inline-block; width: 100%;
            margin: 0 0 14px; border-radius: 8px; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.05);
        }
        .question-card.is-good { background: #178a53; color: #fff; }
        .question-card.is-bad { background: #c73535; color: #fff; }
        .question-card.is-even { background: rgba(255,255,255,0.08); color: var(--text-primary); }
        .question-text {
            padding: 12px 12px 9px; font-size: 0.94rem; font-weight: 800;
            line-height: 1.28; display: -webkit-box; -webkit-line-clamp: 4;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .question-stats {
            display: flex; justify-content: space-between; gap: 10px;
            border-top: 1px solid rgba(255,255,255,0.35); padding: 8px 12px 10px;
            font-size: 0.82rem; font-weight: 900;
        }
        .empty-state {
            text-align: center; padding: 52px 24px; color: var(--text-muted);
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 10px;
        }
        @keyframes livePulse {
            0% { opacity: 0.35; } 50% { opacity: 1; } 100% { opacity: 0.35; }
        }
        @media (max-width: 1300px) { .questions-board { column-count: 4; } }
        @media (max-width: 1000px) { .questions-board { column-count: 3; } }
        @media (max-width: 760px) {
            .topbar { padding: 12px 16px; }
            .live-content { padding: 16px 12px; }
            .live-header { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .questions-board { column-count: 1; }
        }
    </style>
</head>
<body>
<div class="live-layout">
    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="<?= TRIVIAX_BASE ?>/panel/live_sessions.php">Partidas en vivo</a>
            <a href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Dashboard</a>
        </div>
    </header>

    <main class="live-content">
        <div class="live-header">
            <div>
                <div class="live-title"><?= htmlspecialchars($sesion['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="live-meta">
                    <?= htmlspecialchars($sesion['proyecto_title'], ENT_QUOTES, 'UTF-8') ?>
                    &nbsp; Codigo <span class="live-code"><?= htmlspecialchars($sesion['codigo_acceso'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="status-line">
                <span id="live-status"><span class="live-dot"></span>Conectando en vivo...</span>
                <span id="live-updated">-</span>
            </div>
        </div>

        <section class="summary-grid">
            <div class="summary-card"><div class="summary-label">Equipos</div><div id="summary-players" class="summary-value">0</div></div>
            <div class="summary-card"><div class="summary-label">Respuestas</div><div id="summary-answers" class="summary-value">0</div></div>
            <div class="summary-card"><div class="summary-label">Aciertos</div><div id="summary-correct" class="summary-value">0</div></div>
            <div class="summary-card"><div class="summary-label">Errores</div><div id="summary-wrong" class="summary-value">0</div></div>
        </section>

        <section id="questions-board" class="questions-board">
            <div class="empty-state">Esperando las primeras respuestas...</div>
        </section>
    </main>
</div>

<script>
const sessionId = <?= (int)$sesion['id'] ?>;
const questionsBoard = document.getElementById('questions-board');
const statusEl = document.getElementById('live-status');
const updatedEl = document.getElementById('live-updated');

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value ?? 0);
}

function renderQuestions(questions) {
    if (!questionsBoard) return;
    if (!questions.length) {
        questionsBoard.innerHTML = '<div class="empty-state">Esperando las primeras respuestas...</div>';
        return;
    }
    questionsBoard.innerHTML = questions.map((q) => {
        const correctas = Number(q.correctas || 0);
        const errores = Number(q.errores || 0);
        const tone = correctas > errores ? 'is-good' : (errores > correctas ? 'is-bad' : 'is-even');
        return `
            <article class="question-card ${tone}">
                <div class="question-text">${escapeHtml(q.prompt_text)}</div>
                <div class="question-stats">
                    <span>Aciertos: ${correctas}</span>
                    <span>Errores: ${errores}</span>
                </div>
            </article>
        `;
    }).join('');
}

function applyLiveSessionData(data, mode = 'polling') {
    setText('summary-players', data.totals?.jugadores || 0);
    setText('summary-answers', data.totals?.respuestas || 0);
    setText('summary-correct', data.totals?.correctas || 0);
    setText('summary-wrong', data.totals?.errores || 0);
    renderQuestions(data.questions || []);

    if (statusEl) {
        const label = mode === 'sse' ? 'En vivo' : 'Actualizando cada 5 s';
        statusEl.innerHTML = `<span class="live-dot"></span>${label}`;
    }
    if (updatedEl) {
        updatedEl.textContent = new Date().toLocaleTimeString('es-UY', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }
}

async function refreshLiveSession(mode = 'polling') {
    try {
        const response = await fetch(`<?= TRIVIAX_BASE ?>/panel/live_session.php?id=${sessionId}&ajax=summary`, { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Respuesta invalida');
        applyLiveSessionData(data, mode);
    } catch (err) {
        if (statusEl) statusEl.textContent = 'Sin conexion con la partida';
    }
}

let fallbackTimer = null;
function startPollingFallback() {
    if (fallbackTimer) return;
    refreshLiveSession('polling');
    fallbackTimer = setInterval(() => refreshLiveSession('polling'), 5000);
}

if (window.EventSource) {
    const source = new EventSource(`<?= TRIVIAX_BASE ?>/events.php?stream=live_session_summary&id=${sessionId}`);
    let sseStarted = false;
    let sseLastEventAt = 0;
    const sseFallbackTimeout = setTimeout(() => {
        if (!sseStarted) {
            source.close();
            startPollingFallback();
        }
    }, 8000);

    source.addEventListener('summary', (event) => {
        try {
            const data = JSON.parse(event.data);
            if (data.success) {
                sseStarted = true;
                sseLastEventAt = Date.now();
                clearTimeout(sseFallbackTimeout);
                applyLiveSessionData(data, 'sse');
            }
        } catch (err) {
            console.warn('Evento SSE invalido', err);
        }
    });
    source.addEventListener('heartbeat', () => {
        sseStarted = true;
        sseLastEventAt = Date.now();
        clearTimeout(sseFallbackTimeout);
        if (statusEl) statusEl.innerHTML = '<span class="live-dot"></span>En vivo';
    });
    source.addEventListener('error', () => {
        if (statusEl) statusEl.innerHTML = '<span class="live-dot"></span>Reconectando...';
        if (!sseStarted || (Date.now() - sseLastEventAt > 15000)) {
            source.close();
            startPollingFallback();
        }
    });
    refreshLiveSession('sse');
} else {
    startPollingFallback();
}
</script>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
