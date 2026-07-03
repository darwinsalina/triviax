/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Panel docente de la modalidad "Sopa de letras".
   Asistente de creación (manual o con IA, con documento pegado o
   adjunto PDF/MD/TXT), listado, publicar/despublicar, compartir enlace
   y eliminar. Usa los endpoints ws_* de api.php y el motor local
   js/wordsearch.js. Integrado desde experimental/sopa-letras.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API  = window.TRIVIAX_API  || '../api.php';
const BASE = window.TRIVIAX_BASE || '../';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';

const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

function apiGet(action, params) {
    let url = API + '?action=' + encodeURIComponent(action);
    if (params) url += '&' + new URLSearchParams(params).toString();
    return fetch(url, { headers: { 'X-CSRF-Token': CSRF } })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.error || 'Error de red'); return d; });
}
function apiPost(action, fields) {
    const fd = new FormData();
    if (fields) Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.error || 'Error de red'); return d; });
}

/* ---------- Navegación ---------- */
function go(screen) {
    if (screen === 'create') wizReset();
    $$('.act-screen').forEach((s) => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (screen === 'manage') loadProjects();
}
document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-go]');
    if (t) go(t.dataset.go);
});

/* ════════════════════════════════════════════════════════════════
   LISTA / GESTIÓN
   ════════════════════════════════════════════════════════════════ */
function playUrl(id) {
    return new URL(BASE + 'sopa.php?id=' + id, window.location.href).href;
}

async function loadProjects() {
    const list = $('#project-list');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await apiGet('ws_list_projects');
        renderProjects(data.projects || []);
    } catch (err) {
        list.innerHTML = '<p class="act-empty">No se pudo cargar: ' + escapeHtml(err.message) + '</p>';
    }
}

function renderProjects(projects) {
    const list = $('#project-list');
    $('#list-empty').hidden = projects.length > 0;
    list.innerHTML = '';
    projects.forEach((p) => {
        const published = p.estado === 'published';
        const card = document.createElement('div');
        card.className = 'act-pcard';
        card.innerHTML = `
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(p.title)}</p>
                <p class="act-pcard-meta">${p.rows}×${p.cols} · ${p.wordCount} palabras · ${p.source === 'ia' ? 'con IA' : 'manual'}</p>
                <span class="act-pcard-badge ${published ? 'published' : ''}">${published ? 'Publicada' : 'Borrador'}</span>
            </div>
            <div class="act-pcard-actions">
                <button class="act-btn ghost sm" data-act="toggle" data-id="${p.id}" data-estado="${published ? 'draft' : 'published'}">${published ? 'Despublicar' : 'Publicar'}</button>
                <button class="act-btn ghost sm" data-act="link" data-id="${p.id}">🔗 Enlace</button>
                <a class="act-btn ghost sm" href="${escapeHtml(playUrl(p.id))}" target="_blank">Probar ↗</a>
                <button class="act-btn ghost sm" data-act="del" data-id="${p.id}">Eliminar</button>
            </div>`;
        list.appendChild(card);
    });

    $$('[data-act="toggle"]', list).forEach((b) => b.addEventListener('click', async () => {
        try {
            await apiPost('ws_publish_project', { id: b.dataset.id, estado: b.dataset.estado });
            loadProjects();
        } catch (err) { alert(err.message || 'No se pudo cambiar el estado.'); }
    }));
    $$('[data-act="link"]', list).forEach((b) => b.addEventListener('click', async () => {
        const url = playUrl(b.dataset.id);
        try {
            await navigator.clipboard.writeText(url);
            const prev = b.textContent;
            b.textContent = '✅ Copiado';
            setTimeout(() => { b.textContent = prev; }, 1800);
        } catch (e) {
            prompt('Copiá el enlace para compartir:', url);
        }
    }));
    $$('[data-act="del"]', list).forEach((b) => b.addEventListener('click', async () => {
        if (!confirm('¿Eliminar esta sopa de letras? Esta acción no se puede deshacer.')) return;
        try {
            await apiPost('ws_delete_project', { id: b.dataset.id });
            loadProjects();
        } catch (err) { alert(err.message || 'No se pudo eliminar.'); }
    }));
}

/* ════════════════════════════════════════════════════════════════
   ASISTENTE DE CREACIÓN
   ════════════════════════════════════════════════════════════════ */
function manualFlow() { return ['datos', 'grilla', 'origen', 'palabras', 'previsualizar', 'fin']; }
function iaFlow()     { return ['datos', 'grilla', 'origen', 'documento', 'prompt1', 'candidatas', 'prompt2', 'palabras', 'previsualizar', 'fin']; }

const STEP_LABELS = {
    datos: 'Datos', grilla: 'Grilla', origen: 'Origen', documento: 'Documento',
    prompt1: 'Prompt 1', candidatas: 'Candidatas', prompt2: 'Prompt 2',
    palabras: 'Palabras', previsualizar: 'Vista previa', fin: 'Listo',
};

let wizard = null;
function wizReset() {
    wizard = {
        step: 'datos',
        flow: manualFlow(),
        origin: null,
        titulo: '',
        rows: 12, cols: 12,
        dirOpts: { horizontal: true, vertical: true, diagonal: false, reverse: false },
        documento: '',
        candidatos: [],     // [{word, clue, included}]
        palabrasFinal: [],  // [{word, clue}]
        puzzle: null,       // resultado de WordSearchEngine.buildPuzzle
        savedId: null,
    };
    $('#f-titulo').value = '';
    $('#f-rows').value = 12; $('#f-cols').value = 12;
    $('#f-dir-h').checked = true; $('#f-dir-v').checked = true;
    $('#f-dir-d').checked = false; $('#f-dir-r').checked = false;
    $('#f-documento').value = '';
    $('#f-candidatas-raw').value = '';
    $('#f-json-final').value = '';
    $('#f-palabras-manual').value = '';
    $$('.origin-card').forEach((c) => c.classList.remove('selected'));
    $('#wiz-next').textContent = 'Siguiente ›';
    $('#wiz-next').disabled = false;
    docAttachStatus('También podés pegar el texto directamente abajo.', '');
    setMsg('');
    wizShowStep('datos');
}

function setMsg(text, kind) {
    const box = $('#wiz-msg');
    box.textContent = text || '';
    box.className = 'act-msg' + (kind ? ' ' + kind : '');
}

function wizCollect() {
    wizard.titulo = $('#f-titulo').value.trim();
    wizard.rows = clampInt($('#f-rows').value, 6, 30, 12);
    wizard.cols = clampInt($('#f-cols').value, 6, 30, 12);
    wizard.dirOpts = {
        horizontal: $('#f-dir-h').checked,
        vertical: $('#f-dir-v').checked,
        diagonal: $('#f-dir-d').checked,
        reverse: $('#f-dir-r').checked,
    };
    wizard.documento = $('#f-documento').value;
}
function clampInt(v, min, max, fallback) {
    const n = parseInt(v, 10);
    if (Number.isNaN(n)) return fallback;
    return Math.max(min, Math.min(max, n));
}

function describeDirections(opts) {
    const parts = [];
    if (opts.horizontal) parts.push('horizontal');
    if (opts.vertical) parts.push('vertical');
    if (opts.diagonal) parts.push('diagonal');
    if (!parts.length) parts.push('horizontal');
    let s = parts.join(', ');
    s += opts.reverse ? ' (en ambos sentidos de lectura)' : ' (solo en sentido de lectura normal)';
    return s;
}

function estimateCapacity() {
    return WordSearchEngine.estimateMaxWords(wizard.rows, wizard.cols, wizard.dirOpts);
}

function renderCapacityBox(targetId, count) {
    const box = $(targetId);
    if (!box) return;
    const max = estimateCapacity();
    let cls = '';
    let msg = `Con ${wizard.rows}×${wizard.cols} y las direcciones elegidas, la grilla admite aproximadamente <strong>${max} palabras</strong> (estimación por simulación).`;
    if (typeof count === 'number') {
        if (count === 0) {
            cls = '';
        } else if (count > max) {
            cls = 'bad';
            msg += ` Tenés <strong>${count}</strong> cargadas: es probable que algunas no entren. Quitá palabras, agrandá la grilla o habilitá más direcciones.`;
        } else if (count > max * 0.85) {
            cls = 'warn';
            msg += ` Tenés <strong>${count}</strong> cargadas: está cerca del límite, puede que alguna no entre.`;
        } else {
            msg += ` Tenés <strong>${count}</strong> cargadas: debería entrar todo.`;
        }
    }
    box.innerHTML = msg;
    box.className = 'capacity-box' + (cls ? ' ' + cls : '');
}

/* ---------- navegación del asistente ---------- */
function wizRenderSteps() {
    const steps = $('#wiz-steps');
    steps.innerHTML = '';
    const idx = wizard.flow.indexOf(wizard.step);
    wizard.flow.forEach((name, i) => {
        const li = el('li', '', `${i + 1}. ${STEP_LABELS[name]}`);
        if (name === wizard.step) li.classList.add('is-current');
        else if (i < idx) li.classList.add('is-done');
        steps.append(li);
    });
}

function wizShowStep(name) {
    wizard.step = name;
    $$('.wiz-panel').forEach((p) => p.classList.toggle('is-active', p.dataset.wizardStep === name));
    const idx = wizard.flow.indexOf(name);
    $('#wiz-back').style.visibility = idx <= 0 ? 'hidden' : 'visible';
    $('#wiz-next').style.visibility = (name === 'fin' || name === 'origen') ? 'hidden' : 'visible';

    if (name === 'grilla') renderCapacityBox('#capacity-box');
    if (name === 'prompt1') $('#prompt1-text').value = buildPrompt1();
    if (name === 'candidatas') renderCandidateList();
    if (name === 'prompt2') $('#prompt2-text').value = buildPrompt2();
    if (name === 'palabras') wizEnterPalabras();
    if (name === 'previsualizar') generatePreview();
    if (name === 'fin') wizEnterFin();

    setMsg('');
    wizRenderSteps();
}

function wizValidateStep(step) {
    if (step === 'datos') {
        wizCollect();
        if (wizard.titulo === '') { setMsg('Escribí un título para la actividad.', 'err'); return false; }
        return true;
    }
    if (step === 'grilla') {
        wizCollect();
        if (!wizard.dirOpts.horizontal && !wizard.dirOpts.vertical && !wizard.dirOpts.diagonal) {
            setMsg('Activá al menos una dirección.', 'err'); return false;
        }
        return true;
    }
    if (step === 'documento') {
        wizCollect();
        if (wizard.documento.trim().length < 30) {
            setMsg('Pegá o adjuntá el documento de estudio (texto un poco más largo) para que la IA tenga contexto suficiente.', 'err');
            return false;
        }
        return true;
    }
    if (step === 'prompt1') {
        const raw = $('#f-candidatas-raw').value.trim();
        if (!raw) { setMsg('Pegá la respuesta de la IA con la lista de candidatas antes de continuar.', 'err'); return false; }
        const parsed = parseLooseWordList(raw);
        if (!parsed.length) { setMsg('No pude interpretar ninguna palabra en ese texto. Revisá el formato y volvé a pegarlo.', 'err'); return false; }
        wizard.candidatos = parsed.map((p) => ({ word: p.word, clue: p.clue || '', included: true }));
        return true;
    }
    if (step === 'candidatas') {
        const included = wizard.candidatos.filter((c) => c.included);
        if (!included.length) { setMsg('Marcá al menos una palabra para continuar.', 'err'); return false; }
        return true;
    }
    if (step === 'prompt2') {
        const raw = $('#f-json-final').value.trim();
        if (!raw) { setMsg('Pegá el JSON que te devolvió la IA antes de continuar.', 'err'); return false; }
        const parsed = parseLooseWordList(raw);
        if (!parsed.length) { setMsg('No pude leer el JSON pegado. Pedile a la IA que devuelva solo el JSON, sin texto adicional, y volvé a copiarlo.', 'err'); return false; }
        wizard.palabrasFinal = dedupeWords(parsed);
        return true;
    }
    if (step === 'palabras') {
        if (wizard.origin === 'manual') {
            const parsed = parseLooseWordList($('#f-palabras-manual').value);
            if (parsed.length) wizard.palabrasFinal = dedupeWords(parsed.concat(wizard.palabrasFinal));
        }
        if (wizard.palabrasFinal.length < 2) {
            setMsg('Cargá al menos 2 palabras para armar la sopa de letras.', 'err');
            return false;
        }
        const maxLen = Math.max(wizard.rows, wizard.cols);
        const tooLong = wizard.palabrasFinal.filter((w) => WordSearchEngine.normalizeWord(w.word).length > maxLen);
        if (tooLong.length) {
            setMsg(`Estas palabras son más largas que la grilla (máx. ${maxLen} letras): ${tooLong.map((w) => w.word).join(', ')}. Quitalas, acortalas o agrandá la grilla.`, 'err');
            return false;
        }
        return true;
    }
    return true;
}

function dedupeWords(list) {
    const seen = new Set();
    const out = [];
    for (const item of list) {
        const norm = WordSearchEngine.normalizeWord(item.word);
        if (!norm || seen.has(norm)) continue;
        seen.add(norm);
        out.push({ word: item.word.trim(), clue: (item.clue || '').trim() });
    }
    return out;
}

function wizNext() {
    if (!wizValidateStep(wizard.step)) return;
    if (wizard.step === 'previsualizar') { saveActivity(); return; }
    const i = wizard.flow.indexOf(wizard.step);
    if (i >= 0 && i < wizard.flow.length - 1) wizShowStep(wizard.flow[i + 1]);
}
function wizBack() {
    const i = wizard.flow.indexOf(wizard.step);
    if (i > 0) wizShowStep(wizard.flow[i - 1]);
}
$('#wiz-next').addEventListener('click', wizNext);
$('#wiz-back').addEventListener('click', wizBack);

/* ---------- paso: origen ---------- */
$$('.origin-card').forEach((card) => {
    card.addEventListener('click', () => {
        wizCollect();
        wizard.origin = card.dataset.origin;
        wizard.flow = wizard.origin === 'manual' ? manualFlow() : iaFlow();
        $$('.origin-card').forEach((c) => c.classList.toggle('selected', c === card));
        const idx = wizard.flow.indexOf('origen');
        wizShowStep(wizard.flow[idx + 1]);
    });
});

/* ---------- paso: documento (pegar o adjuntar PDF/MD/TXT) ---------- */
function docAttachStatus(text, kind) {
    const box = $('#doc-attach-msg');
    if (!box) return;
    box.textContent = text || '';
    box.className = 'doc-attach-msg' + (kind ? ' ' + kind : '');
}
TriviaxDocImport.bind($('#f-doc-file'), {
    base: BASE,
    onText: (text) => { $('#f-documento').value = text; },
    onStatus: docAttachStatus,
});

/* ---------- paso: prompts IA ---------- */
function tryExtractJson(text) {
    let t = text.trim();
    t = t.replace(/^```(?:json)?/i, '').replace(/```$/, '').trim();
    const sa = t.indexOf('['), ea = t.lastIndexOf(']');
    const so = t.indexOf('{'), eo = t.lastIndexOf('}');
    if (sa !== -1 && ea !== -1 && ea > sa) return t.slice(sa, ea + 1);
    if (so !== -1 && eo !== -1 && eo > so) return t.slice(so, eo + 1);
    return null;
}

function parseLooseWordList(raw) {
    const text = String(raw || '').trim();
    if (!text) return [];
    const jsonChunk = tryExtractJson(text);
    if (jsonChunk) {
        try {
            let parsed = JSON.parse(jsonChunk);
            if (parsed && !Array.isArray(parsed) && Array.isArray(parsed.words)) parsed = parsed.words;
            if (Array.isArray(parsed)) {
                const out = parsed.map((item) => {
                    if (typeof item === 'string') return { word: item, clue: '' };
                    if (item && typeof item === 'object') {
                        const word = item.word || item.palabra || item.term || item.termino || '';
                        const clue = item.clue || item.definicion || item.definition || item.pista || '';
                        return { word: String(word).trim(), clue: String(clue).trim() };
                    }
                    return null;
                }).filter((it) => it && it.word);
                if (out.length) return out;
            }
        } catch (e) { /* sigue con el parseo de texto libre */ }
    }
    const rawLines = text.split(/\r?\n/);
    const lines = [];
    for (const l of rawLines) {
        if (l.includes(',') && !/[:\-–—]/.test(l)) lines.push(...l.split(','));
        else lines.push(l);
    }
    const out = [];
    for (const raw0 of lines) {
        const l = raw0.replace(/^[\s*••\d.)\-]+/, '').trim();
        if (!l) continue;
        const m = l.match(/^([^:\-–—]+)[:\-–—]\s*(.+)$/);
        if (m) out.push({ word: m[1].trim(), clue: m[2].trim() });
        else out.push({ word: l, clue: '' });
    }
    return out;
}

function buildPrompt1() {
    wizCollect();
    const max = estimateCapacity();
    return `Actuá como diseñador pedagógico de materiales didácticos en español.

A partir del documento de estudio que está al final de este mensaje, proponé una lista de palabras clave para armar una SOPA DE LETRAS de ${wizard.rows} filas × ${wizard.cols} columnas (direcciones habilitadas: ${describeDirections(wizard.dirOpts)}).

Con esa grilla, la capacidad estimada es de hasta ${max} palabras. Elegí la cantidad ÓPTIMA para el tema (no hace falta llegar al máximo): priorizá los términos más relevantes, evitá repetidos y evitá palabras más largas que ${Math.max(wizard.rows, wizard.cols)} letras (no entrarían en la grilla).

Para cada palabra:
- Usá UNA sola palabra, sin espacios (si un concepto es una frase, elegí el término clave o uníla sin espacio, ej. "fotosintesis").
- Agregá una definición o pista breve que sirva como material de estudio.

Devolveme el resultado como una lista clara en texto (todavía NO hace falta JSON), por ejemplo:

1. PALABRA — definición breve.
2. PALABRA — definición breve.

Después de que yo revise esta lista te voy a pedir el formato final en JSON, así que no te adelantes a eso todavía.

Aquí está el documento fuente:

${wizard.documento}`;
}

function buildPrompt2() {
    const included = wizard.candidatos.filter((c) => c.included);
    const list = included.map((c) => `- ${c.word}${c.clue ? ' — ' + c.clue : ''}`).join('\n');
    return `Perfecto, confirmamos esta lista final de ${included.length} palabras para la sopa de letras:

${list}

Devolveme AHORA esta misma lista en formato JSON ESTRICTO, sin texto antes ni después, sin comentarios y sin bloques de código markdown. Usá exactamente esta estructura:

[
  { "word": "PALABRA", "clue": "definición breve" }
]

Reglas obligatorias:
1. Una palabra por elemento, en mayúsculas, sin espacios ni tildes (podés conservar la Ñ).
2. No agregues palabras nuevas ni quites ninguna de la lista confirmada arriba.
3. "clue" es opcional pero recomendado: una definición o pista breve en español.
4. Tu respuesta es SOLO el JSON, empezando con [ y terminando con ].`;
}

async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        setMsg('Prompt copiado al portapapeles. Pegalo en tu chatbot de IA.', 'ok');
    } catch (e) {
        setMsg('No se pudo copiar automáticamente: seleccioná el texto y copialo manualmente.', 'warn');
    }
}
$('#btn-copy-prompt1').addEventListener('click', () => copyToClipboard($('#prompt1-text').value));
$('#btn-copy-prompt2').addEventListener('click', () => copyToClipboard($('#prompt2-text').value));

/* ---------- paso: candidatas ---------- */
function renderCandidateList() {
    const ul = $('#candidate-list');
    ul.innerHTML = '';
    wizard.candidatos.forEach((c, i) => {
        const li = el('li');
        li.classList.toggle('excluded', !c.included);
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = c.included;
        cb.addEventListener('change', () => { c.included = cb.checked; li.classList.toggle('excluded', !c.included); });
        const body = el('div');
        const w = el('span', 'candidate-word', c.word);
        body.append(w);
        if (c.clue) body.append(el('span', 'candidate-clue', c.clue));
        const del = el('button', 'candidate-del', '✕');
        del.type = 'button';
        del.addEventListener('click', () => { wizard.candidatos.splice(i, 1); renderCandidateList(); });
        li.append(cb, body, del);
        ul.append(li);
    });
}
$('#btn-add-candidata').addEventListener('click', () => {
    const input = $('#f-candidata-nueva');
    const word = input.value.trim();
    if (!word) return;
    wizard.candidatos.push({ word, clue: '', included: true });
    input.value = '';
    renderCandidateList();
});
$('#f-candidata-nueva').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#btn-add-candidata').click(); }
});

/* ---------- paso: palabras (manual o revisión final IA) ---------- */
function wizEnterPalabras() {
    const isManual = wizard.origin === 'manual';
    $('#palabras-manual-field').hidden = !isManual;
    $('#palabras-explain').textContent = isManual
        ? 'Escribí las palabras a incluir (una por línea o separadas por coma). Podés agregarlas también una por una abajo.'
        : 'Esta es la lista final confirmada por la IA. Podés sacar o agregar palabras antes de generar la sopa.';
    if (!isManual) $('#f-palabras-manual').value = '';
    renderWordChips();
}
function renderWordChips() {
    const box = $('#word-chip-list');
    box.innerHTML = '';
    const maxLen = Math.max(wizard.rows, wizard.cols);
    wizard.palabrasFinal.forEach((w, i) => {
        const chip = el('span', 'word-chip');
        const norm = WordSearchEngine.normalizeWord(w.word);
        if (norm.length < 2 || norm.length > maxLen) chip.classList.add('invalid');
        chip.append(document.createTextNode(w.word + ' '));
        const del = el('button', '', '✕');
        del.type = 'button';
        del.addEventListener('click', () => { wizard.palabrasFinal.splice(i, 1); renderWordChips(); });
        chip.append(del);
        box.append(chip);
    });
    renderCapacityBox('#palabras-capacity', wizard.palabrasFinal.length);
}
let manualParseTimer = null;
$('#f-palabras-manual').addEventListener('input', () => {
    clearTimeout(manualParseTimer);
    manualParseTimer = setTimeout(() => {
        const parsed = parseLooseWordList($('#f-palabras-manual').value);
        // vista previa en vivo sin perder lo ya confirmado en pasos anteriores
        const base = wizard.origin === 'manual' ? [] : wizard.palabrasFinal;
        renderCapacityBox('#palabras-capacity', base.length + parsed.length);
    }, 250);
});

/* ---------- paso: previsualizar ---------- */
function generatePreview() {
    const words = wizard.palabrasFinal.map((w) => w.word);
    const result = WordSearchEngine.buildPuzzle(words, wizard.rows, wizard.cols, wizard.dirOpts);
    const clueMap = new Map(wizard.palabrasFinal.map((w) => [WordSearchEngine.normalizeWord(w.word), w.clue || '']));
    result.placed.forEach((p) => { p.clue = clueMap.get(p.normalized) || ''; });
    wizard.puzzle = result;
    renderPreviewGrid(result);

    const msg = $('#preview-msg');
    if (result.unplaced.length) {
        msg.className = 'act-msg warn';
        msg.textContent = `Se ubicaron ${result.placed.length} de ${words.length} palabras. No entraron: ${result.unplaced.join(', ')}. Probá agrandar la grilla, habilitar más direcciones, acortar esas palabras o volver a generar.`;
    } else {
        msg.className = 'act-msg ok';
        msg.textContent = `¡Listo! Se ubicaron las ${result.placed.length} palabras en la grilla de ${result.rows}×${result.cols}.`;
    }
    $('#wiz-next').textContent = '💾 Guardar actividad';
    $('#wiz-next').disabled = result.placed.length === 0;
}
function renderPreviewGrid(result) {
    const box = $('#ws-preview');
    box.innerHTML = '';
    const grid = el('div', 'ws-grid');
    grid.style.gridTemplateColumns = `repeat(${result.cols}, var(--ws-cell))`;
    for (let r = 0; r < result.rows; r++) {
        for (let c = 0; c < result.cols; c++) {
            grid.append(el('div', 'ws-cell', result.grid[r][c]));
        }
    }
    box.append(grid);
}
$('#btn-regenerate').addEventListener('click', generatePreview);

/* ---------- guardar / fin ---------- */
async function saveActivity() {
    if (!wizard.puzzle) { setMsg('No hay sopa de letras generada todavía.', 'err'); return; }
    const btn = $('#wiz-next');
    btn.disabled = true;
    try {
        const payload = {
            rows: wizard.puzzle.rows, cols: wizard.puzzle.cols,
            grid: wizard.puzzle.grid,
            placed: wizard.puzzle.placed,
            unplaced: wizard.puzzle.unplaced,
            directions: wizard.puzzle.directions,
            dirOpts: wizard.dirOpts,
            source: wizard.origin || 'manual',
        };
        const res = await apiPost('ws_save_project', { title: wizard.titulo, puzzle: JSON.stringify(payload) });
        wizard.savedId = res.id;
        $('#wiz-next').textContent = 'Siguiente ›';
        const idx = wizard.flow.indexOf('previsualizar');
        wizShowStep(wizard.flow[idx + 1]);
    } catch (err) {
        setMsg('Error al guardar: ' + err.message, 'err');
    } finally {
        btn.disabled = false;
    }
}
function wizEnterFin() {
    $('#fin-detail').textContent = `"${wizard.titulo}" se guardó con ${wizard.puzzle ? wizard.puzzle.placed.length : 0} palabras. Publicala para que tus estudiantes la vean en el catálogo, o compartiles el enlace directo.`;
    $('#fin-msg').textContent = '';
    $('#fin-msg').className = 'act-msg';
    $('#btn-fin-publicar').disabled = false;
}
$('#btn-fin-publicar').addEventListener('click', async () => {
    if (!wizard.savedId) return;
    const btn = $('#btn-fin-publicar');
    btn.disabled = true;
    const msg = $('#fin-msg');
    try {
        await apiPost('ws_publish_project', { id: wizard.savedId, estado: 'published' });
        const url = playUrl(wizard.savedId);
        try { await navigator.clipboard.writeText(url); } catch (e) { /* sin permiso de portapapeles */ }
        msg.className = 'act-msg ok';
        msg.textContent = 'Publicada. Enlace para compartir (copiado): ' + url;
    } catch (err) {
        btn.disabled = false;
        msg.className = 'act-msg err';
        msg.textContent = 'No se pudo publicar: ' + err.message;
    }
});

/* ---------- arranque ---------- */
wizReset();
go('manage');

})();
