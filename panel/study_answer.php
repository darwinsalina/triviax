<?php
/**
 * TRIVIAX — Panel docente para la modalidad "Estudia y responde".
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
    <title>Estudia y responde — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body {
            overflow: auto;
        }

        .study-admin-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 32px;
            background: rgba(9, 13, 22, 0.85);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-logo {
            color: var(--text-primary);
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topbar-user {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .study-admin-content {
            width: min(1280px, calc(100% - 32px));
            margin: 0 auto;
            padding: 30px 0 46px;
            display: grid;
            gap: 22px;
        }

        .study-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 18px;
        }

        .study-admin-header h1 {
            color: var(--text-primary);
            font-size: 1.75rem;
            line-height: 1.15;
        }

        .study-admin-header p {
            max-width: 720px;
            margin-top: 8px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .study-status {
            min-height: 44px;
            padding: 13px 16px;
            color: var(--text-secondary);
            background: rgba(17, 24, 39, 0.68);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .study-status[data-tone="ok"] {
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.28);
        }

        .study-status[data-tone="warn"] {
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.28);
        }

        .study-status[data-tone="error"] {
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.28);
        }

        .study-admin-grid {
            display: grid;
            grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .study-panel {
            padding: 18px;
        }

        .study-panel h2 {
            color: var(--text-primary);
            font-size: 1.05rem;
            margin-bottom: 14px;
        }

        .study-list {
            display: grid;
            gap: 12px;
        }

        .study-deck-item {
            width: 100%;
            padding: 14px;
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            cursor: pointer;
            text-align: left;
        }

        .study-deck-item:hover,
        .study-deck-item.is-active {
            border-color: rgba(99, 102, 241, 0.8);
            background: rgba(99, 102, 241, 0.16);
        }

        .study-deck-item strong,
        .study-preview-card strong {
            display: block;
            color: var(--text-primary);
            line-height: 1.25;
        }

        .study-deck-item span {
            display: block;
            margin-top: 6px;
            color: var(--text-secondary);
            font-size: 0.82rem;
        }

        .study-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 10px;
        }

        .study-badge {
            padding: 4px 8px;
            color: #c4b5fd;
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.22);
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .study-badge[data-state="published"] {
            color: #6ee7b7;
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.24);
        }

        .study-badge[data-state="archived"] {
            color: #cbd5e1;
            background: rgba(148, 163, 184, 0.12);
            border-color: rgba(148, 163, 184, 0.22);
        }

        .study-editor-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 420px);
            gap: 16px;
        }

        .study-toolbar,
        .study-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .study-toolbar {
            margin-bottom: 12px;
        }

        .study-json-label {
            display: grid;
            gap: 8px;
            color: var(--text-secondary);
            font-weight: 700;
        }

        .study-json-editor {
            width: 100%;
            min-height: 560px;
            padding: 14px;
            color: var(--text-primary);
            background: rgba(9, 13, 22, 0.82);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 0.88rem;
            line-height: 1.45;
            resize: vertical;
        }

        .study-results,
        .study-preview,
        .study-report {
            display: grid;
            gap: 12px;
        }

        .study-result-box {
            padding: 13px 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-secondary);
        }

        .study-result-box strong {
            color: var(--text-primary);
        }

        .study-result-list {
            display: grid;
            gap: 7px;
            padding-left: 18px;
            color: var(--text-secondary);
        }

        .study-preview-card {
            padding: 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .study-preview-card p {
            margin-top: 7px;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        .study-preview-card code {
            color: #a5b4fc;
            overflow-wrap: anywhere;
        }

        .study-report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .study-report-table th,
        .study-report-table td {
            padding: 9px 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: left;
            font-size: 0.85rem;
        }

        .study-report-table th {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .study-report-table td {
            color: var(--text-secondary);
        }

        .study-empty {
            padding: 26px 16px;
            color: var(--text-muted);
            text-align: center;
            border: 1px dashed rgba(148, 163, 184, 0.26);
            border-radius: 12px;
        }

        .study-hidden {
            display: none !important;
        }

        /* ===== Asistente de creación ===== */
        .study-wizard {
            padding: 22px;
            display: grid;
            gap: 18px;
        }

        .study-wizard-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
        }

        .study-wizard-head h2 {
            color: var(--text-primary);
            font-size: 1.3rem;
        }

        .study-wizard-head p {
            margin-top: 6px;
            color: var(--text-secondary);
            line-height: 1.5;
            max-width: 720px;
        }

        .study-wizard-steps {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            list-style: none;
            padding: 0;
        }

        .study-wizard-steps li {
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .study-wizard-steps li.is-current {
            color: #c4b5fd;
            background: rgba(99, 102, 241, 0.16);
            border-color: rgba(99, 102, 241, 0.5);
        }

        .study-wizard-steps li.is-done {
            color: #6ee7b7;
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
        }

        .study-wizard-step {
            display: grid;
            gap: 14px;
        }

        .study-wizard-step h3 {
            color: var(--text-primary);
            font-size: 1.05rem;
        }

        .study-wizard-step > p {
            color: var(--text-secondary);
            line-height: 1.55;
            max-width: 760px;
        }

        .study-field {
            display: grid;
            gap: 7px;
            color: var(--text-secondary);
            font-weight: 700;
            font-size: 0.9rem;
        }

        .study-field small {
            font-weight: 400;
            color: var(--text-muted);
        }

        .study-input,
        .study-select,
        .study-textarea {
            width: 100%;
            padding: 11px 14px;
            color: var(--text-primary);
            background: rgba(9, 13, 22, 0.82);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.95rem;
            box-sizing: border-box;
        }

        .study-textarea {
            min-height: 110px;
            resize: vertical;
            line-height: 1.5;
        }

        .study-input:focus,
        .study-select:focus,
        .study-textarea:focus {
            outline: none;
            border-color: rgba(99, 102, 241, 0.8);
        }

        .study-select option {
            background: #0f172a;
            color: #f8fafc;
        }

        .study-wizard-grid2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

        .study-wizard-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 14px;
        }

        .study-wizard-nav .study-wizard-nav-right {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .study-method-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }

        .study-method-card {
            padding: 18px;
            text-align: left;
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            cursor: pointer;
            font-family: inherit;
        }

        .study-method-card:hover,
        .study-method-card.is-selected {
            border-color: rgba(99, 102, 241, 0.8);
            background: rgba(99, 102, 241, 0.14);
        }

        .study-method-card strong {
            display: block;
            font-size: 1.02rem;
            margin-bottom: 7px;
        }

        .study-method-card span {
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.5;
            display: block;
        }

        .study-prompt-box {
            position: relative;
        }

        .study-prompt-box textarea {
            min-height: 280px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 0.82rem;
        }

        .study-copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
        }

        .study-help-list {
            display: grid;
            gap: 7px;
            padding-left: 20px;
            color: var(--text-secondary);
            line-height: 1.55;
        }

        .study-json-paste {
            min-height: 240px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 0.82rem;
        }

        .study-manual-progress {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            color: var(--text-secondary);
            font-weight: 700;
        }

        .study-manual-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .study-manual-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            color: #c4b5fd;
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .study-manual-chip button {
            border: none;
            background: transparent;
            color: #f87171;
            font-weight: 800;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .study-answers-block {
            display: grid;
            gap: 10px;
            padding: 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
        }

        .study-radio-row {
            display: flex;
            gap: 18px;
            color: var(--text-primary);
            font-weight: 600;
        }

        .study-radio-row label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }

        .study-final-summary {
            padding: 18px;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .study-final-summary strong {
            color: #6ee7b7;
        }

        @media (max-width: 980px) {
            .study-admin-grid,
            .study-editor-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .topbar,
            .study-admin-header {
                align-items: stretch;
                flex-direction: column;
            }

            .study-admin-content {
                width: min(100% - 22px, 680px);
                padding-top: 18px;
            }

            .study-json-editor {
                min-height: 420px;
            }

            .study-actions .btn,
            .study-toolbar .btn,
            .topbar-actions .btn {
                flex: 1 1 160px;
            }
        }
    </style>
</head>
<body>
<div class="study-admin-layout" data-study-admin data-csrf="<?php echo $csrf; ?>">
    <header class="topbar">
        <a class="topbar-logo" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">TRIVIAX</a>
        <div class="topbar-actions">
            <span class="topbar-user">Docente: <strong><?php echo htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <a class="btn btn-secondary" href="<?= TRIVIAX_BASE ?>/study.php">Vista estudiante</a>
            <a class="btn btn-secondary" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Dashboard</a>
            <a class="btn btn-secondary" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </div>
    </header>

    <main class="study-admin-content">
        <section class="study-admin-header">
            <div>
                <h1>Estudia y responde</h1>
                <p>Crea mazos de microlectura, valida el JSON, previsualiza cartas y publica actividades de repaso adaptativo.</p>
            </div>
            <div class="study-actions">
                <button type="button" class="btn btn-primary" data-action="wizard">Crear actividad (asistente)</button>
                <button type="button" class="btn btn-secondary" data-action="new">Modo avanzado (JSON)</button>
                <button type="button" class="btn btn-secondary" data-action="load-demo">Cargar ejemplo</button>
            </div>
        </section>

        <div class="study-status" data-status aria-live="polite">Cargando panel...</div>

        <!-- ===== Asistente de creación de mazos ===== -->
        <section class="study-wizard glass-card study-hidden" data-wizard>
            <div class="study-wizard-head">
                <div>
                    <h2>Asistente para crear una actividad</h2>
                    <p>Te guiamos paso a paso. No necesitás saber nada técnico: al final, la actividad queda publicada y lista para tus estudiantes.</p>
                </div>
                <button type="button" class="btn btn-secondary" data-wizard-cancel>Cancelar</button>
            </div>

            <ol class="study-wizard-steps" data-wizard-steps></ol>

            <!-- Paso: tema -->
            <div class="study-wizard-step study-hidden" data-wizard-step="tema">
                <h3>1. ¿Sobre qué tema es la actividad?</h3>
                <p>Indica el título de la actividad y el tema que quieres evaluar. Si vas a usar un documento de estudio (apunte, resumen, capítulo), tenlo a mano: lo vas a necesitar más adelante.</p>
                <div class="study-wizard-grid2">
                    <label class="study-field">
                        Título de la actividad
                        <input type="text" class="study-input" data-wiz-titulo maxlength="150" placeholder="Ej. Repaso: La célula y sus partes">
                    </label>
                    <label class="study-field">
                        Curso o nivel <small>(opcional)</small>
                        <input type="text" class="study-input" data-wiz-nivel maxlength="60" placeholder="Ej. 1º año">
                    </label>
                </div>
                <label class="study-field">
                    Tema a evaluar
                    <small>Describí brevemente el tema o el documento de estudio. Esto se usa para orientar las preguntas y como descripción de la actividad.</small>
                    <textarea class="study-textarea" data-wiz-tema maxlength="600" placeholder="Ej. Partes de la célula animal y vegetal: membrana, citoplasma, núcleo y organelas principales, según el apunte de la unidad 3."></textarea>
                </label>
            </div>

            <!-- Paso: cantidad -->
            <div class="study-wizard-step study-hidden" data-wizard-step="cantidad">
                <h3>2. ¿Cuántas cartas quieres generar?</h3>
                <p>Cada carta tiene un texto breve de estudio y una pregunta. Para una actividad de aula recomendamos entre 10 y 20 cartas.</p>
                <label class="study-field" style="max-width: 260px;">
                    Cantidad de cartas (entre 10 y 20)
                    <input type="number" class="study-input" data-wiz-cantidad min="10" max="20" step="1" value="10">
                </label>
            </div>

            <!-- Paso: modalidad -->
            <div class="study-wizard-step study-hidden" data-wizard-step="modalidad">
                <h3>3. ¿Qué tipo de preguntas tendrá?</h3>
                <p>Elige la modalidad de las preguntas que verán los estudiantes después de leer cada carta.</p>
                <label class="study-field" style="max-width: 420px;">
                    Modalidad de las preguntas
                    <select class="study-select" data-wiz-modalidad>
                        <option value="multiple_choice" selected>Opción múltiple</option>
                        <option value="true_false">Verdadero o falso</option>
                        <option value="fill_blank">Completar un espacio</option>
                        <option value="short_answer">Respuesta breve</option>
                        <option value="mixtas">Mixtas (combinación de las anteriores)</option>
                    </select>
                </label>
            </div>

            <!-- Paso: método -->
            <div class="study-wizard-step study-hidden" data-wizard-step="metodo">
                <h3>4. ¿Cómo quieres crear las cartas?</h3>
                <div class="study-method-options">
                    <button type="button" class="study-method-card" data-wiz-metodo="ia">
                        <strong>🤖 Con ayuda de una IA</strong>
                        <span>Te damos un texto (prompt) ya preparado para que lo pegues en tu chatbot de IA preferido (ChatGPT, Claude, Gemini, etc.) junto con tu documento de estudio. La IA te devuelve el contenido listo y tú lo pegas acá. Es la forma más rápida.</span>
                    </button>
                    <button type="button" class="study-method-card" data-wiz-metodo="manual">
                        <strong>✏️ Crear las cartas yo mismo</strong>
                        <span>Completas un formulario simple por cada carta: el texto de estudio, la pregunta y las respuestas correctas e incorrectas. Tienes control total sobre el contenido.</span>
                    </button>
                </div>
            </div>

            <!-- Paso IA: prompt -->
            <div class="study-wizard-step study-hidden" data-wizard-step="ia-prompt">
                <h3>5. Copia este texto y pégalo en tu chatbot de IA</h3>
                <p>Preparamos las instrucciones exactas para que una IA genere tus cartas. Seguí estos pasos:</p>
                <ol class="study-help-list">
                    <li>Presiona el botón <strong>Copiar prompt</strong>.</li>
                    <li>Abre tu chatbot de IA preferido (ChatGPT, Claude, Gemini u otro).</li>
                    <li>Pega el texto copiado en el chat.</li>
                    <li>A continuación del texto, pega o adjunta tu documento de estudio (apunte, resumen, etc.).</li>
                    <li>Envía el mensaje y espera la respuesta: la IA va a devolver un bloque de texto con formato especial (JSON). No hace falta que lo entiendas: solo cópialo completo.</li>
                </ol>
                <div class="study-prompt-box">
                    <button type="button" class="btn btn-primary study-copy-btn" data-wiz-copy-prompt>Copiar prompt</button>
                    <textarea class="study-textarea" data-wiz-prompt readonly spellcheck="false"></textarea>
                </div>
            </div>

            <!-- Paso IA: pegar JSON -->
            <div class="study-wizard-step study-hidden" data-wizard-step="ia-json">
                <h3>6. Pega acá la respuesta de la IA</h3>
                <p>Copia la respuesta completa que te dio el chatbot (el bloque que empieza con <code>{</code>) y pégala en el recuadro. Después presiona <strong>Generar mazo</strong>: lo revisamos, lo guardamos y lo publicamos para tus estudiantes.</p>
                <textarea class="study-textarea study-json-paste" data-wiz-json spellcheck="false" placeholder='Pega acá la respuesta de la IA. Debe empezar con { y terminar con }'></textarea>
                <div data-wiz-json-feedback></div>
                <div>
                    <button type="button" class="btn btn-primary" data-wiz-generate>Generar mazo</button>
                </div>
            </div>

            <!-- Paso manual: cartas -->
            <div class="study-wizard-step study-hidden" data-wizard-step="manual-cartas">
                <h3 data-manual-title>5. Crea tus cartas</h3>
                <div class="study-manual-progress">
                    <span data-manual-progress></span>
                </div>
                <div class="study-manual-cards" data-manual-list></div>
                <div class="study-answers-block" data-manual-form>
                    <label class="study-field study-hidden" data-manual-type-row>
                        Tipo de pregunta para esta carta
                        <select class="study-select" data-card-type>
                            <option value="multiple_choice">Opción múltiple</option>
                            <option value="true_false">Verdadero o falso</option>
                            <option value="fill_blank">Completar un espacio</option>
                            <option value="short_answer">Respuesta breve</option>
                        </select>
                    </label>
                    <label class="study-field">
                        Título de la carta <small>(opcional)</small>
                        <input type="text" class="study-input" data-card-title maxlength="200" placeholder="Ej. La membrana celular">
                    </label>
                    <label class="study-field">
                        Texto de estudio
                        <small>Es lo primero que verá el estudiante en la carta: un texto breve (3 a 6 líneas) que enseña la idea antes de preguntar.</small>
                        <textarea class="study-textarea" data-card-study maxlength="900" placeholder="Escribe el texto breve de estudio..."></textarea>
                    </label>
                    <label class="study-field" data-card-prompt-row>
                        Pregunta
                        <textarea class="study-textarea" data-card-prompt style="min-height: 70px;" placeholder="Escribe la pregunta que responderá el estudiante..."></textarea>
                    </label>

                    <!-- Respuestas: opción múltiple -->
                    <div class="study-answers-block study-hidden" data-answers="multiple_choice">
                        <label class="study-field">
                            Respuesta correcta
                            <input type="text" class="study-input" data-mc-correct placeholder="La respuesta correcta">
                        </label>
                        <label class="study-field">
                            Respuestas incorrectas <small>(al menos una; conviene 2 o 3)</small>
                            <input type="text" class="study-input" data-mc-wrong1 placeholder="Respuesta incorrecta 1">
                            <input type="text" class="study-input" data-mc-wrong2 placeholder="Respuesta incorrecta 2 (opcional)">
                            <input type="text" class="study-input" data-mc-wrong3 placeholder="Respuesta incorrecta 3 (opcional)">
                        </label>
                    </div>

                    <!-- Respuestas: verdadero/falso -->
                    <div class="study-answers-block study-hidden" data-answers="true_false">
                        <span class="study-field">La afirmación de la pregunta es...</span>
                        <div class="study-radio-row">
                            <label><input type="radio" name="wiz-tf" value="true" checked> Verdadera</label>
                            <label><input type="radio" name="wiz-tf" value="false"> Falsa</label>
                        </div>
                    </div>

                    <!-- Respuestas: completar / respuesta breve -->
                    <div class="study-answers-block study-hidden" data-answers="text_answer">
                        <label class="study-field">
                            Respuestas aceptadas
                            <small>Escribe una por línea. Cualquiera de ellas se considera correcta.</small>
                            <textarea class="study-textarea" data-text-answers style="min-height: 80px;" placeholder="Ej.:&#10;núcleo&#10;el núcleo"></textarea>
                        </label>
                    </div>

                    <label class="study-field">
                        Explicación para el estudiante <small>(opcional, se muestra después de responder y ayuda a aprender del error)</small>
                        <textarea class="study-textarea" data-card-explanation style="min-height: 70px;" placeholder="Ej. La membrana regula qué entra y sale de la célula..."></textarea>
                    </label>

                    <div data-manual-feedback></div>

                    <div class="study-actions">
                        <button type="button" class="btn btn-primary" data-wiz-add-card>Agregar carta</button>
                        <button type="button" class="btn btn-secondary" data-wiz-finish-manual disabled>Terminar y publicar el mazo</button>
                    </div>
                </div>
            </div>

            <!-- Paso final -->
            <div class="study-wizard-step study-hidden" data-wizard-step="fin">
                <h3>¡Listo! Tu actividad está publicada</h3>
                <div class="study-final-summary" data-final-summary></div>
                <div class="study-actions">
                    <a class="btn btn-primary study-hidden" data-final-open href="#" target="_blank" rel="noopener">Abrir práctica (vista estudiante)</a>
                    <button type="button" class="btn btn-primary study-hidden study-copy-btn" data-final-copy>🔗 Clic aquí para copiar el enlace a compartir</button>
                    <button type="button" class="btn btn-secondary" data-final-edit>Ver y editar el mazo</button>
                </div>
            </div>

            <div class="study-wizard-nav">
                <button type="button" class="btn btn-secondary" data-wizard-back>Atrás</button>
                <div class="study-wizard-nav-right">
                    <button type="button" class="btn btn-primary" data-wizard-next>Continuar</button>
                </div>
            </div>
        </section>

        <section class="study-admin-grid">
            <aside class="study-panel glass-card">
                <h2>Mis mazos</h2>
                <div class="study-list" data-deck-list></div>
            </aside>

            <section class="study-panel glass-card">
                <div class="study-toolbar">
                    <input type="file" accept="application/json,.json" class="study-hidden" data-file-input>
                    <button type="button" class="btn btn-secondary" data-action="import-file">Importar JSON</button>
                    <button type="button" class="btn btn-secondary" data-action="validate">Validar</button>
                    <button type="button" class="btn btn-primary" data-action="save">Guardar borrador</button>
                    <button type="button" class="btn btn-secondary" data-action="publish">Publicar</button>
                    <button type="button" class="btn btn-secondary" data-action="archive">Archivar</button>
                </div>

                <div class="study-editor-grid">
                    <label class="study-json-label">
                        JSON del mazo
                        <textarea class="study-json-editor" spellcheck="false" data-json-editor></textarea>
                    </label>

                    <div class="study-results">
                        <div class="study-result-box">
                            <strong data-current-title>Sin mazo seleccionado</strong>
                            <div data-current-meta>Importa o pega un JSON para comenzar.</div>
                        </div>

                        <div class="study-actions">
                            <button type="button" class="btn btn-secondary" data-action="preview">Previsualizar</button>
                            <button type="button" class="btn btn-secondary" data-action="report">Reporte</button>
                            <a class="btn btn-secondary study-hidden" data-open-student href="<?= TRIVIAX_BASE ?>/study.php" target="_blank" rel="noopener">Abrir práctica</a>
                            <button type="button" class="btn btn-secondary study-hidden study-copy-btn" data-copy-student>🔗 Copiar enlace</button>
                        </div>

                        <div data-validation></div>
                        <div class="study-preview" data-preview></div>
                        <div class="study-report" data-report></div>
                    </div>
                </div>
            </section>
        </section>
    </main>
</div>

    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
    <script>window.TRIVIAX_BASE = <?= json_encode(TRIVIAX_BASE) ?>;</script>
<script type="module" src="<?= TRIVIAX_BASE ?>/js/panel/studyAnswerPanel.js?v=6.1.1"></script>
</body>
</html>
