<?php
/**
 * TRIVIAX — Panel docente de la modalidad "Sopa de letras".
 * Crear (asistente manual o con IA), publicar/despublicar, compartir el
 * enlace y eliminar las sopas de letras propias.
 * Integrado desde experimental/sopa-letras.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$csrf = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
$nombreUsuario = trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')) ?: 'Docente';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sopa de letras — Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=6.0.0">
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/actividades.css?v=6.3.0">
</head>
<body class="act-app" data-act-csrf="<?php echo $csrf; ?>">
    <header class="act-topbar">
        <a class="act-brand" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">🔤 <span>Sopa de letras</span> <em>Panel docente</em></a>
        <nav class="act-topnav">
            <span class="act-user"><?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/sopa.php" target="_blank">Ver juego ↗</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Panel</a>
            <a class="act-btn ghost sm" href="<?= TRIVIAX_BASE ?>/auth/logout.php">Salir</a>
        </nav>
    </header>

    <main class="act-main">

        <!-- ░░░ GESTIÓN ░░░ -->
        <section class="act-screen active" data-screen="manage">
            <div class="act-panel wide">
                <div class="act-panel-head">
                    <h2>Tus sopas de letras</h2>
                    <button type="button" class="act-btn primary sm" data-go="create">➕ Nueva sopa de letras</button>
                </div>
                <div class="act-grid-cards" id="project-list" aria-live="polite"></div>
                <p class="act-empty" id="list-empty" hidden>Todavía no creaste ninguna sopa de letras.</p>
            </div>
        </section>

        <!-- ░░░ CREAR (asistente) ░░░ -->
        <section class="act-screen" data-screen="create">
            <div class="act-panel" style="max-width:880px">
                <div class="wiz-head">
                    <h2>Nueva sopa de letras</h2>
                    <button type="button" class="act-btn ghost sm" data-go="manage">Cancelar</button>
                </div>
                <ol class="wiz-steps" id="wiz-steps"></ol>
                <p class="act-msg" id="wiz-msg" role="status"></p>

                <!-- Paso: datos -->
                <div class="wiz-panel" data-wizard-step="datos">
                    <label class="act-field">
                        <span class="act-label">Título de la actividad</span>
                        <input type="text" id="f-titulo" maxlength="200" placeholder="Ej: La célula y sus partes">
                    </label>
                </div>

                <!-- Paso: grilla -->
                <div class="wiz-panel" data-wizard-step="grilla">
                    <div class="field-row">
                        <label class="act-field">
                            <span class="act-label">Filas</span>
                            <input type="number" id="f-rows" min="6" max="30" value="12">
                        </label>
                        <label class="act-field">
                            <span class="act-label">Columnas</span>
                            <input type="number" id="f-cols" min="6" max="30" value="12">
                        </label>
                    </div>
                    <div class="act-field">
                        <span class="act-label">Direcciones habilitadas</span>
                        <div class="check-grid">
                            <label class="act-check"><input type="checkbox" id="f-dir-h" checked> Horizontal</label>
                            <label class="act-check"><input type="checkbox" id="f-dir-v" checked> Vertical</label>
                            <label class="act-check"><input type="checkbox" id="f-dir-d"> Diagonal</label>
                            <label class="act-check"><input type="checkbox" id="f-dir-r"> Sentido inverso (de derecha a izquierda / de abajo hacia arriba)</label>
                        </div>
                        <small class="act-hint">Más direcciones = más difícil. "Sentido inverso" aplica a las direcciones que actives.</small>
                    </div>
                    <div class="capacity-box" id="capacity-box">Calculando capacidad de la grilla…</div>
                </div>

                <!-- Paso: origen -->
                <div class="wiz-panel" data-wizard-step="origen">
                    <div class="origin-cards">
                        <button type="button" class="origin-card" data-origin="manual">
                            <span class="oc-icon">✍️</span>
                            <span class="oc-title">Yo elijo las palabras</span>
                            <span class="oc-sub">Escribís la lista directamente.</span>
                        </button>
                        <button type="button" class="origin-card" data-origin="ia">
                            <span class="oc-icon">🤖</span>
                            <span class="oc-title">Usar IA para extraerlas</span>
                            <span class="oc-sub">Pegás o adjuntás tu documento y la app genera los prompts.</span>
                        </button>
                    </div>
                </div>

                <!-- Paso: documento (solo IA) -->
                <div class="wiz-panel" data-wizard-step="documento">
                    <div class="act-field">
                        <span class="act-label">Adjuntar documento (PDF, MD o TXT)</span>
                        <div class="doc-attach">
                            <input type="file" id="f-doc-file" accept=".pdf,.md,.markdown,.txt,application/pdf,text/plain,text/markdown">
                        </div>
                        <p class="doc-attach-msg" id="doc-attach-msg">También podés pegar el texto directamente abajo.</p>
                    </div>
                    <label class="act-field">
                        <span class="act-label">Documento de estudio</span>
                        <textarea id="f-documento" rows="10" placeholder="Pegá acá el texto de estudio, o adjuntá un archivo arriba."></textarea>
                        <small class="act-hint">Este texto se incluye dentro del Prompt 1.</small>
                    </label>
                </div>

                <!-- Paso: prompt 1 (extracción) -->
                <div class="wiz-panel" data-wizard-step="prompt1">
                    <p class="wiz-step-explain">Copiá este prompt y pegalo en tu chatbot de IA (junto con el documento, que ya va incluido). Te va a proponer una lista de palabras candidatas.</p>
                    <textarea id="prompt1-text" class="prompt-box" rows="12" readonly></textarea>
                    <button type="button" class="act-btn ghost sm" id="btn-copy-prompt1">📋 Copiar Prompt 1</button>
                    <label class="act-field" style="margin-top:18px">
                        <span class="act-label">Pegá acá la respuesta de la IA (la lista de candidatas)</span>
                        <textarea id="f-candidatas-raw" rows="8" placeholder="Pegá la lista que te devolvió la IA, en cualquier formato (texto o JSON)."></textarea>
                    </label>
                </div>

                <!-- Paso: revisar candidatas -->
                <div class="wiz-panel" data-wizard-step="candidatas">
                    <p class="wiz-step-explain">Revisá la lista. Destildá las que no quieras usar o agregá otras manualmente. Cuando estés conforme, generamos el Prompt 2.</p>
                    <div class="candidate-add">
                        <input type="text" id="f-candidata-nueva" placeholder="Agregar palabra…">
                        <button type="button" class="act-btn ghost sm" id="btn-add-candidata">Agregar</button>
                    </div>
                    <ul class="candidate-list" id="candidate-list"></ul>
                </div>

                <!-- Paso: prompt 2 (JSON final) -->
                <div class="wiz-panel" data-wizard-step="prompt2">
                    <p class="wiz-step-explain">Este segundo prompt le pide a la IA el listado final ya confirmado, en JSON estricto. Pegalo en la misma conversación.</p>
                    <textarea id="prompt2-text" class="prompt-box" rows="10" readonly></textarea>
                    <button type="button" class="act-btn ghost sm" id="btn-copy-prompt2">📋 Copiar Prompt 2</button>
                    <label class="act-field" style="margin-top:18px">
                        <span class="act-label">Pegá acá el JSON final</span>
                        <textarea id="f-json-final" rows="8" placeholder='[{"word":"NUCLEO","clue":"Controla la célula"}, ...]'></textarea>
                    </label>
                </div>

                <!-- Paso: palabras (manual o revisión final IA) -->
                <div class="wiz-panel" data-wizard-step="palabras">
                    <p class="wiz-step-explain" id="palabras-explain"></p>
                    <label class="act-field" id="palabras-manual-field">
                        <span class="act-label">Lista de palabras (una por línea o separadas por coma)</span>
                        <textarea id="f-palabras-manual" rows="10" placeholder="CELULA, NUCLEO, MITOCONDRIA, ..."></textarea>
                    </label>
                    <div class="word-chip-list" id="word-chip-list"></div>
                    <div class="capacity-box" id="palabras-capacity"></div>
                </div>

                <!-- Paso: previsualizar -->
                <div class="wiz-panel" data-wizard-step="previsualizar">
                    <div class="preview-actions">
                        <button type="button" class="act-btn ghost sm" id="btn-regenerate">🎲 Volver a generar</button>
                    </div>
                    <p class="act-msg" id="preview-msg"></p>
                    <div class="ws-preview" id="ws-preview"></div>
                </div>

                <!-- Paso: fin -->
                <div class="wiz-panel" data-wizard-step="fin">
                    <div class="wiz-done">
                        <div class="act-win-emoji">✅</div>
                        <h3>Actividad guardada como borrador</h3>
                        <p id="fin-detail">—</p>
                        <div class="act-win-actions">
                            <button type="button" class="act-btn ghost" data-go="manage">Ver mis actividades</button>
                            <button type="button" class="act-btn primary" id="btn-fin-publicar">📢 Publicar ahora</button>
                        </div>
                        <p class="act-msg" id="fin-msg" role="status"></p>
                    </div>
                </div>

                <div class="wiz-nav">
                    <button type="button" class="act-btn ghost" id="wiz-back">‹ Atrás</button>
                    <button type="button" class="act-btn primary" id="wiz-next">Siguiente ›</button>
                </div>
            </div>
        </section>

    </main>

    <script>
        window.TRIVIAX_CSRF_TOKEN = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
        window.TRIVIAX_BASE = '../';
        window.TRIVIAX_API  = '../api.php';
    </script>
    <script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=6.0.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/wordsearch.js?v=6.3.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/docImport.js?v=6.3.0"></script>
    <script src="<?= TRIVIAX_BASE ?>/js/panel/sopaLetrasPanel.js?v=6.3.0"></script>
</body>
</html>
