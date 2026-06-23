<?php
/**
 * TRIVIAX — Panel docente para la modalidad "TRIVIAX Lotto" (lotto_oral).
 * Crear (asistente con prompt de IA), listar, publicar, controlar y ver reportes.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIVIAX Lotto — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.8">
    <style>
        body { overflow: auto; }

        .lotto-admin-layout { min-height: 100vh; display: flex; flex-direction: column; }

        .topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 32px; background: rgba(9, 13, 22, 0.85);
            border-bottom: 1px solid var(--border-color); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-logo { color: var(--text-primary); text-decoration: none; font-size: 1.5rem; font-weight: 800; letter-spacing: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .topbar-user { color: var(--text-secondary); font-size: 0.9rem; }

        .lotto-admin-content {
            width: min(1280px, calc(100% - 32px));
            margin: 0 auto; padding: 30px 0 46px; display: grid; gap: 22px;
        }
        .lotto-admin-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; }
        .lotto-admin-header h1 { color: var(--text-primary); font-size: 1.75rem; line-height: 1.15; }
        .lotto-admin-header p { max-width: 720px; margin-top: 8px; color: var(--text-secondary); line-height: 1.5; }

        .lotto-status {
            min-height: 44px; padding: 13px 16px; color: var(--text-secondary);
            background: rgba(17, 24, 39, 0.68); border: 1px solid var(--border-color); border-radius: 12px;
        }
        .lotto-status[data-tone="ok"] { color: #34d399; border-color: rgba(16, 185, 129, 0.28); }
        .lotto-status[data-tone="warn"] { color: #fbbf24; border-color: rgba(245, 158, 11, 0.28); }
        .lotto-status[data-tone="error"] { color: #f87171; border-color: rgba(239, 68, 68, 0.28); }

        .lotto-panel { padding: 18px; }
        .lotto-panel h2 { color: var(--text-primary); font-size: 1.1rem; margin-bottom: 14px; }

        .lotto-table { width: 100%; border-collapse: collapse; }
        .lotto-table th, .lotto-table td {
            padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,0.08);
            text-align: left; font-size: 0.88rem; color: var(--text-secondary);
        }
        .lotto-table th { color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.74rem; }
        .lotto-table td strong { color: var(--text-primary); }
        .lotto-table .lotto-row-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .lotto-table .btn { padding: 6px 10px; font-size: 0.78rem; }

        .lotto-badge {
            display: inline-block; padding: 4px 10px; border-radius: 999px;
            font-size: 0.74rem; font-weight: 700;
            color: #c4b5fd; background: rgba(99,102,241,0.12); border: 1px solid rgba(99,102,241,0.22);
        }
        .lotto-badge[data-state="published"], .lotto-badge[data-state="finished"] { color: #6ee7b7; background: rgba(16,185,129,0.12); border-color: rgba(16,185,129,0.24); }
        .lotto-badge[data-state="login_open"], .lotto-badge[data-state="study"], .lotto-badge[data-state="response"], .lotto-badge[data-state="oral"] { color: #fbbf24; background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.24); }
        .lotto-badge[data-state="archived"], .lotto-badge[data-state="cancelled"] { color: #cbd5e1; background: rgba(148,163,184,0.12); border-color: rgba(148,163,184,0.22); }

        .lotto-code { font-family: Consolas, monospace; font-weight: 800; letter-spacing: 2px; color: #a5b4fc; }

        /* ===== Asistente ===== */
        .lotto-wizard { padding: 22px; display: grid; gap: 18px; }
        .lotto-wizard-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; }
        .lotto-wizard-head h2 { color: var(--text-primary); font-size: 1.3rem; }
        .lotto-wizard-head p { margin-top: 6px; color: var(--text-secondary); line-height: 1.5; max-width: 720px; }

        .lotto-wizard-steps { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; padding: 0; }
        .lotto-wizard-steps li {
            padding: 6px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 700;
            color: var(--text-muted); background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
        }
        .lotto-wizard-steps li.is-current { color: #c4b5fd; background: rgba(99,102,241,0.16); border-color: rgba(99,102,241,0.5); }
        .lotto-wizard-steps li.is-done { color: #6ee7b7; background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.3); }

        .lotto-wizard-step { display: grid; gap: 14px; }
        .lotto-wizard-step h3 { color: var(--text-primary); font-size: 1.05rem; }
        .lotto-wizard-step > p { color: var(--text-secondary); line-height: 1.55; max-width: 760px; }

        .lotto-field { display: grid; gap: 7px; color: var(--text-secondary); font-weight: 700; font-size: 0.9rem; }
        .lotto-field small { font-weight: 400; color: var(--text-muted); }

        .lotto-input, .lotto-select, .lotto-textarea {
            width: 100%; padding: 11px 14px; color: var(--text-primary);
            background: rgba(9,13,22,0.82); border: 1px solid rgba(255,255,255,0.14);
            border-radius: 10px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box;
        }
        .lotto-textarea { min-height: 120px; resize: vertical; line-height: 1.5; }
        .lotto-input:focus, .lotto-select:focus, .lotto-textarea:focus { outline: none; border-color: rgba(99,102,241,0.8); }
        .lotto-select option { background: #0f172a; color: #f8fafc; }

        .lotto-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }

        .lotto-wizard-nav {
            display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between;
            border-top: 1px solid rgba(255,255,255,0.08); padding-top: 14px;
        }

        .lotto-prompt-box { position: relative; }
        .lotto-prompt-box textarea { min-height: 280px; font-family: Consolas, 'Courier New', monospace; font-size: 0.82rem; }
        .lotto-copy-btn { position: absolute; top: 10px; right: 10px; z-index: 2; }
        .lotto-help-list { display: grid; gap: 7px; padding-left: 20px; color: var(--text-secondary); line-height: 1.55; }
        .lotto-json-paste { min-height: 240px; font-family: Consolas, 'Courier New', monospace; font-size: 0.82rem; }

        .lotto-result-box {
            padding: 13px 14px; background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color); border-radius: 12px; color: var(--text-secondary);
        }
        .lotto-result-box strong { color: var(--text-primary); }
        .lotto-result-list { display: grid; gap: 7px; padding-left: 18px; color: var(--text-secondary); }
        .lotto-result-box[data-tone="error"] { border-color: rgba(239,68,68,0.4); }
        .lotto-result-box[data-tone="ok"] { border-color: rgba(16,185,129,0.4); }

        .lotto-final-summary {
            padding: 18px; border-radius: 12px; background: rgba(16,185,129,0.08);
            border: 1px solid rgba(16,185,129,0.3); color: var(--text-secondary); line-height: 1.6;
        }
        .lotto-final-summary strong { color: #6ee7b7; }
        .lotto-final-summary .lotto-code { font-size: 1.4rem; }

        .lotto-hidden { display: none !important; }

        /* Reporte */
        .lotto-report { display: grid; gap: 12px; }
        .lotto-report-detail { font-size: 0.85rem; }
        .lotto-report-detail p { margin: 4px 0; color: var(--text-secondary); }
        .lotto-report-detail p strong { color: var(--text-primary); }

        @media (max-width: 700px) {
            .topbar, .lotto-admin-header { align-items: stretch; flex-direction: column; }
            .lotto-admin-content { width: min(100% - 22px, 680px); padding-top: 18px; }
            .lotto-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
<div class="lotto-admin-layout" data-lotto-admin data-csrf="<?php echo $csrf; ?>">
    <header class="topbar">
        <a class="topbar-logo" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">TRIVIAX</a>
        <div class="topbar-actions">
            <span class="topbar-user">Docente: <strong><?php echo htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <a class="btn btn-secondary" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Dashboard</a>
            <a class="btn btn-secondary" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </div>
    </header>

    <main class="lotto-admin-content">
        <section class="lotto-admin-header">
            <div>
                <h1>TRIVIAX Lotto</h1>
                <p>Sorteo oral de aprendizaje. Cada estudiante recibe un número y una ficha de estudio; la pantalla del salón sortea quién pasa al oral y tú registras la evaluación.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" data-action="wizard">Crear actividad (asistente)</button>
            </div>
        </section>

        <div class="lotto-status" data-status aria-live="polite">Cargando panel...</div>

        <!-- ===== Asistente ===== -->
        <section class="lotto-wizard glass-card lotto-hidden" data-wizard>
            <div class="lotto-wizard-head">
                <div>
                    <h2>Asistente para crear un TRIVIAX Lotto</h2>
                    <p>Te guiamos paso a paso: datos, documento de estudio, lista de estudiantes y configuración. Al final, una IA te arma las fichas y la actividad queda lista con su código de 6 caracteres.</p>
                </div>
                <button type="button" class="btn btn-secondary" data-wizard-cancel>Cancelar</button>
            </div>

            <ol class="lotto-wizard-steps" data-wizard-steps></ol>

            <!-- Paso: datos -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="datos">
                <h3>1. Datos de la actividad</h3>
                <div class="lotto-grid2">
                    <label class="lotto-field">
                        Título de la actividad
                        <input type="text" class="lotto-input" data-wiz-titulo maxlength="150" placeholder="Ej. Lotto: Memorias internas">
                    </label>
                    <label class="lotto-field">
                        Curso o nivel <small>(opcional)</small>
                        <input type="text" class="lotto-input" data-wiz-nivel maxlength="60" placeholder="Ej. 8vo EBI">
                    </label>
                    <label class="lotto-field">
                        Grupo <small>(opcional)</small>
                        <input type="text" class="lotto-input" data-wiz-grupo maxlength="100" placeholder="Ej. 8vo A">
                    </label>
                </div>
                <label class="lotto-field">
                    Descripción u observaciones <small>(opcional)</small>
                    <textarea class="lotto-textarea" data-wiz-descripcion maxlength="400" style="min-height:70px;" placeholder="Ej. Repaso oral antes del escrito de la unidad 3."></textarea>
                </label>
            </div>

            <!-- Paso: documento -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="documento">
                <h3>2. Documento de estudio</h3>
                <p>Pega el texto breve que tus estudiantes van a estudiar. Ideal: una carilla (máximo una carilla y media, hasta 12.000 caracteres). De este documento saldrán las secciones y las preguntas.</p>
                <label class="lotto-field">
                    Texto del documento
                    <textarea class="lotto-textarea" data-wiz-documento maxlength="12000" style="min-height:220px;" placeholder="Pega aquí el apunte, resumen o texto de estudio..."></textarea>
                    <small data-wiz-doc-count>0 caracteres</small>
                </label>
            </div>

            <!-- Paso: estudiantes -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="estudiantes">
                <h3>3. Lista de estudiantes</h3>
                <p>Escribe un estudiante por línea. El número de lista será el orden en que los escribas (1, 2, 3...). Puedes poner solo el nombre de pila, o el nombre completo: en la pantalla del salón se mostrará únicamente el nombre de pila.</p>
                <label class="lotto-field">
                    Estudiantes (uno por línea)
                    <textarea class="lotto-textarea" data-wiz-estudiantes style="min-height:180px;" placeholder="Sofía Pérez&#10;Mateo Rodríguez&#10;Valentina López&#10;Bruno Silva"></textarea>
                    <small data-wiz-students-count>0 estudiantes</small>
                </label>
            </div>

            <!-- Paso: configuración -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="config">
                <h3>4. Configuración de la dinámica</h3>
                <div class="lotto-grid2">
                    <label class="lotto-field">
                        Cantidad de secciones del tema
                        <select class="lotto-select" data-wiz-secciones>
                            <option value="4">4 secciones</option>
                            <option value="5" selected>5 secciones</option>
                        </select>
                    </label>
                    <label class="lotto-field">
                        Preguntas guía por estudiante
                        <select class="lotto-select" data-wiz-preguntas>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                    </label>
                    <label class="lotto-field">
                        Tiempo de estudio (minutos)
                        <input type="number" class="lotto-input" data-wiz-min-estudio min="1" max="120" value="15">
                    </label>
                    <label class="lotto-field">
                        Tiempo de respuesta/preparación (minutos)
                        <input type="number" class="lotto-input" data-wiz-min-respuesta min="1" max="60" value="5">
                    </label>
                    <label class="lotto-field">
                        ¿Puedes extender los plazos durante la clase?
                        <select class="lotto-select" data-wiz-extension>
                            <option value="1" selected>Sí, permitir extender</option>
                            <option value="0">No</option>
                        </select>
                    </label>
                    <label class="lotto-field">
                        Modo de sorteo
                        <select class="lotto-select" data-wiz-sorteo>
                            <option value="random_no_repeat" selected>Al azar, sin repetir evaluados</option>
                            <option value="random_simple">Al azar simple (puede repetir)</option>
                        </select>
                    </label>
                </div>
            </div>

            <!-- Paso: prompt IA -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="ia-prompt">
                <h3>5. Copia este texto y pégalo en tu chatbot de IA</h3>
                <p>El prompt ya incluye tu documento, tu lista de estudiantes y la configuración. Seguí estos pasos:</p>
                <ol class="lotto-help-list">
                    <li>Presiona <strong>Copiar prompt</strong>.</li>
                    <li>Abre tu chatbot de IA preferido (ChatGPT, Claude, Gemini u otro) y pega el texto completo.</li>
                    <li>Envía el mensaje: la IA dividirá el tema en secciones y armará la ficha de cada estudiante.</li>
                    <li>Copia la respuesta completa (el bloque que empieza con <code>{</code>). No hace falta que la entiendas.</li>
                </ol>
                <div class="lotto-prompt-box">
                    <button type="button" class="btn btn-primary lotto-copy-btn" data-wiz-copy-prompt>Copiar prompt</button>
                    <textarea class="lotto-textarea" data-wiz-prompt readonly spellcheck="false"></textarea>
                </div>
            </div>

            <!-- Paso: pegar JSON -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="ia-json">
                <h3>6. Pega acá la respuesta de la IA</h3>
                <p>Pega la respuesta completa del chatbot y presiona <strong>Crear actividad</strong>: la revisamos, la guardamos y te damos el código para tus estudiantes. También puedes pegar un JSON que hayas guardado antes.</p>
                <textarea class="lotto-textarea lotto-json-paste" data-wiz-json spellcheck="false" placeholder='Pega acá la respuesta de la IA. Debe empezar con { y terminar con }'></textarea>
                <div data-wiz-json-feedback></div>
                <div>
                    <button type="button" class="btn btn-primary" data-wiz-generate>Crear actividad</button>
                </div>
            </div>

            <!-- Paso final -->
            <div class="lotto-wizard-step lotto-hidden" data-wizard-step="fin">
                <h3>¡Listo! Tu TRIVIAX Lotto está creado</h3>
                <div class="lotto-final-summary" data-final-summary></div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a class="btn btn-primary lotto-hidden" data-final-host href="#" target="_blank" rel="noopener">Abrir pantalla del salón</a>
                    <button type="button" class="btn btn-secondary" data-final-close>Volver al listado</button>
                </div>
            </div>

            <div class="lotto-wizard-nav">
                <button type="button" class="btn btn-secondary" data-wizard-back>Atrás</button>
                <button type="button" class="btn btn-primary" data-wizard-next>Continuar</button>
            </div>
        </section>

        <!-- ===== Lista de actividades ===== -->
        <section class="lotto-panel glass-card">
            <h2>Mis actividades Lotto</h2>
            <div data-activity-list></div>
        </section>

        <!-- ===== Reporte ===== -->
        <section class="lotto-panel glass-card lotto-hidden" data-report-panel>
            <h2 data-report-title>Reporte</h2>
            <div class="lotto-report" data-report></div>
        </section>
    </main>
</div>

<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.8"></script>
<script>window.TRIVIAX_BASE = <?= json_encode(TRIVIAX_BASE) ?>;</script>
<script type="module" src="<?= TRIVIAX_BASE ?>/js/panel/lottoPanel.js?v=6.1.0"></script>
</body>
</html>
