<?php
/**
 * TRIVIAX — Pantalla estudiante de "TRIVIAX Lotto" (lotto_oral).
 * Ingreso por código + número, ficha de estudio, timer de fase y respuesta breve.
 * No requiere cuenta: la identidad es código + número + token de servidor.
 */

require_once __DIR__ . '/php/auth.php';
triviax_session_start();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$prefillCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIVIAX Lotto — Estudiante</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.8">
    <style>
        body { overflow: auto; }

        .lotto-student-page {
            min-height: 100vh; width: min(860px, calc(100% - 24px));
            margin: 0 auto; padding: 26px 0 50px; display: grid; gap: 18px; align-content: start;
        }
        .lotto-brand { text-align: center; }
        .lotto-brand h1 { color: var(--text-primary); font-size: 1.9rem; letter-spacing: 2px; }
        .lotto-brand p { color: var(--text-secondary); margin-top: 4px; }

        .lotto-card { padding: 22px; display: grid; gap: 14px; }
        .lotto-card h2 { color: var(--text-primary); font-size: 1.15rem; }
        .lotto-card p { color: var(--text-secondary); line-height: 1.55; }

        .lotto-field { display: grid; gap: 7px; color: var(--text-secondary); font-weight: 700; font-size: 0.9rem; }
        .lotto-input, .lotto-textarea {
            width: 100%; padding: 13px 15px; color: var(--text-primary);
            background: rgba(9,13,22,0.82); border: 1px solid rgba(255,255,255,0.14);
            border-radius: 10px; font-family: inherit; font-size: 1.05rem; box-sizing: border-box;
        }
        .lotto-input:focus, .lotto-textarea:focus { outline: none; border-color: rgba(99,102,241,0.8); }
        .lotto-input[data-login-code] { text-transform: uppercase; letter-spacing: 4px; font-weight: 800; }
        .lotto-textarea { min-height: 140px; resize: vertical; line-height: 1.55; font-size: 0.98rem; }

        .lotto-status {
            min-height: 40px; padding: 12px 15px; color: var(--text-secondary);
            background: rgba(17,24,39,0.68); border: 1px solid var(--border-color); border-radius: 12px;
        }
        .lotto-status[data-tone="ok"] { color: #34d399; border-color: rgba(16,185,129,0.28); }
        .lotto-status[data-tone="warn"] { color: #fbbf24; border-color: rgba(245,158,11,0.28); }
        .lotto-status[data-tone="error"] { color: #f87171; border-color: rgba(239,68,68,0.28); }

        .lotto-hello { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .lotto-hello strong { color: var(--text-primary); font-size: 1.2rem; }
        .lotto-mynumber {
            min-width: 74px; padding: 10px 16px; text-align: center;
            font-size: 1.9rem; font-weight: 900; color: #fcd360;
            background: rgba(99,102,241,0.14); border: 2px solid rgba(99,102,241,0.55); border-radius: 14px;
        }

        .lotto-phase-bar {
            display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
            padding: 14px 16px; border-radius: 12px;
            background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3);
        }
        .lotto-phase-label { color: var(--text-primary); font-weight: 800; }
        .lotto-timer { font-family: Consolas, monospace; font-size: 2rem; font-weight: 900; color: var(--text-primary); }
        .lotto-timer[data-tone="low"] { color: #f87171; animation: lottoPulse 1s infinite; }
        @keyframes lottoPulse { 50% { opacity: 0.45; } }

        .lotto-called {
            padding: 20px; text-align: center; border-radius: 14px;
            color: #0b1020; background: #fcd360; font-size: 1.25rem; font-weight: 900;
            animation: lottoPulse 1.2s infinite;
        }

        .lotto-study-text { color: var(--text-primary); line-height: 1.65; white-space: pre-wrap; }
        .lotto-list { display: grid; gap: 8px; padding-left: 20px; color: var(--text-secondary); line-height: 1.55; }
        .lotto-list li::marker { color: #a5b4fc; }

        .lotto-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .lotto-hidden { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<main class="lotto-student-page" data-lotto-student data-csrf="<?php echo $csrf; ?>" data-prefill-code="<?php echo htmlspecialchars($prefillCode, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="lotto-brand">
        <h1>TRIVIAX LOTTO</h1>
        <p>Sorteo oral de aprendizaje</p>
    </div>

    <div class="lotto-status" data-status aria-live="polite">Ingresa con el código de la actividad y tu número.</div>

    <!-- Login -->
    <section class="lotto-card glass-card" data-screen="login">
        <h2>Ingresar a la actividad</h2>
        <label class="lotto-field">
            Código de actividad
            <input type="text" class="lotto-input" data-login-code maxlength="6" autocomplete="off" placeholder="ABC123">
        </label>
        <label class="lotto-field">
            Tu número asignado
            <input type="number" class="lotto-input" data-login-number min="1" max="999" placeholder="Ej. 7">
        </label>
        <div class="lotto-actions">
            <button type="button" class="btn btn-primary" data-login-btn>Ingresar</button>
        </div>
    </section>

    <!-- Ficha -->
    <section class="lotto-card glass-card lotto-hidden" data-screen="ficha">
        <div class="lotto-hello">
            <div>
                <strong data-st-hello>Hola</strong>
                <p data-st-activity></p>
            </div>
            <div class="lotto-mynumber" data-st-number>—</div>
        </div>

        <div class="lotto-phase-bar">
            <span class="lotto-phase-label" data-st-phase>Esperando inicio</span>
            <span class="lotto-timer lotto-hidden" data-st-timer aria-live="off">--:--</span>
        </div>

        <div class="lotto-called lotto-hidden" data-st-called>🎤 ¡Es tu turno! Te toca pasar al oral.</div>

        <div data-st-assignment class="lotto-card" style="padding:0; gap:14px;">
            <div>
                <h2 data-st-section></h2>
                <p class="lotto-study-text" data-st-study></p>
            </div>
            <div data-st-ideas-wrap class="lotto-hidden">
                <h2>Ideas clave</h2>
                <ul class="lotto-list" data-st-ideas></ul>
            </div>
            <div>
                <h2>Tus preguntas guía</h2>
                <ul class="lotto-list" data-st-questions></ul>
            </div>
            <div>
                <h2>Pregunta oral principal</h2>
                <p class="lotto-study-text" data-st-oral></p>
            </div>
        </div>

        <div data-st-response-wrap class="lotto-hidden" style="display:grid; gap:10px;">
            <h2>Tu respuesta breve o esquema</h2>
            <p>Anota las ideas centrales que vas a decir en el oral. Puedes guardar borradores y, cuando estés conforme, enviar la versión final.</p>
            <textarea class="lotto-textarea" data-st-response maxlength="4000" placeholder="Escribe aquí tu respuesta breve, esquema o ideas centrales..."></textarea>
            <div class="lotto-actions">
                <button type="button" class="btn btn-secondary" data-st-save>Guardar borrador</button>
                <button type="button" class="btn btn-primary" data-st-submit>Enviar respuesta</button>
                <button type="button" class="btn btn-secondary" data-st-ready>Estoy preparado/a</button>
            </div>
        </div>
    </section>
</main>

<script>window.TRIVIAX_BASE = <?= json_encode(TRIVIAX_BASE) ?>;</script>
<script type="module" src="<?= TRIVIAX_BASE ?>/js/engines/lottoStudentEngine.js?v=6.1.0"></script>
</body>
</html>
