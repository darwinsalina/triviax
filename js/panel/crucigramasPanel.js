/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Panel docente de la modalidad "Crucigrama".
   Asistente de creación (manual o con IA, con documento pegado o
   adjunto PDF/MD/TXT), listado, publicar/despublicar, compartir enlace
   y eliminar. Usa los endpoints cw_* de api.php y el motor local
   js/crossword.js. Integrado desde experimental/crucigramas.
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
    return new URL(BASE + 'crucigrama.php?id=' + id, window.location.href).href;
}

async function loadProjects() {
    const list = $('#project-list');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await apiGet('cw_list_projects');
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
                <span class="act-pcard-badge ${published ? 'published' : ''}">${published ? 'Publicado' : 'Borrador'}</span>
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
            await apiPost('cw_publish_project', { id: b.dataset.id, estado: b.dataset.estado });
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
        if (!confirm('¿Eliminar este crucigrama? Esta acción no se puede deshacer.')) return;
        try {
            await apiPost('cw_delete_project', { id: b.dataset.id });
            loadProjects();
        } catch (err) { alert(err.message || 'No se pudo eliminar.'); }
    }));
}

/* ════════════════════════════════════════════════════════════════
   ASISTENTE DE CREACIÓN
   ════════════════════════════════════════════════════════════════ */
function manualFlow() { return ['datos', 'origen', 'palabras', 'previsualizar', 'fin']; }
function iaFlow()     { return ['datos', 'origen', 'documento', 'prompt1', 'candidatas', 'prompt2', 'palabras', 'previsualizar', 'fin']; }

const STEP_LABELS = {
    datos: 'Datos', origen: 'Origen', documento: 'Documento',
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
        documento: '',
        candidatos: [],     // [{word, clue, included}]
        palabrasFinal: [],  // [{word, clue}]
        puzzle: null,       // resultado de CrosswordEngine.buildCrossword
        savedId: null,
    };
    $('#f-titulo').value = '';
    $('#f-documento').value = '';
    $('#f-candidatas-raw').value = '';
    $('#f-json-final').value = '';
    $('#f-palabras-manual').value = '';
    $('#f-palabra-nueva').value = '';
    $('#f-clue-nueva').value = '';
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
    wizard.documento = $('#f-documento').value;
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
            renderWordFinalList();
        }
        if (wizard.palabrasFinal.length < 2) {
            setMsg('Cargá al menos 2 palabras con su definición.', 'err');
            return false;
        }
        const sinClue = wizard.palabrasFinal.filter((w) => !w.clue.trim());
        if (sinClue.length) {
            setMsg(`Faltan definiciones para: ${sinClue.map((w) => w.word).join(', ')}. La definición es obligatoria para poder jugar (completala en la lista de abajo).`, 'err');
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
        const norm = CrosswordEngine.normalizeWord(item.word);
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
    return `Actuá como diseñador pedagógico de materiales didácticos en español.

A partir del documento de estudio que está al final de este mensaje, proponé entre 10 y 20 palabras clave para armar un CRUCIGRAMA sobre el tema.

Para que el crucigrama pueda armarse con buenas intersecciones, priorizá términos que compartan letras entre sí (por ejemplo, varios conceptos de la misma familia temática). Evitá elegir solo palabras totalmente distintas entre sí sin ninguna letra en común.

Para cada palabra:
- Usá UNA sola palabra, sin espacios (si un concepto es una frase, elegí el término clave o uníla sin espacio, ej. "fotosintesis").
- Agregá una DEFINICIÓN breve y clara, redactada como la pista de un crucigrama (sin repetir la palabra dentro de la definición). Es obligatoria: sin definición la palabra no se puede jugar.

Devolveme el resultado como una lista clara en texto (todavía NO hace falta JSON), por ejemplo:

1. PALABRA — definición breve.
2. PALABRA — definición breve.

Después de que yo revise esta lista te voy a pedir el formato final en JSON, así que no te adelantes a eso todavía.

Aquí está el documento fuente:

${wizard.documento}`;
}

function buildPrompt2() {
    const included = wizard.candidatos.filter((c) => c.included);
    const list = included.map((c) => `- ${c.word} — ${c.clue || '(FALTA DEFINICIÓN, completá una)'}`).join('\n');
    return `Perfecto, confirmamos esta lista final de ${included.length} palabras para el crucigrama:

${list}

Devolveme AHORA esta misma lista en formato JSON ESTRICTO, sin texto antes ni después, sin comentarios y sin bloques de código markdown. Usá exactamente esta estructura:

[
  { "word": "PALABRA", "clue": "definición breve" }
]

Reglas obligatorias:
1. Una palabra por elemento, en mayúsculas, sin espacios ni tildes (podés conservar la Ñ).
2. "clue" es OBLIGATORIO en todos los elementos: una definición breve y clara, en español, sin repetir la palabra.
3. No agregues palabras nuevas ni quites ninguna de la lista confirmada arriba.
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

/* ---------- paso: candidatas (palabra + definición editable) ---------- */
function renderCandidateList() {
    const ul = $('#candidate-list');
    ul.innerHTML = '';
    wizard.candidatos.forEach((c, i) => {
        const li = el('li');
        li.classList.toggle('excluded', !c.included);
        li.classList.toggle('no-clue', !c.clue.trim());
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = c.included;
        cb.addEventListener('change', () => { c.included = cb.checked; li.classList.toggle('excluded', !c.included); });
        const w = el('span', 'candidate-word', c.word);
        const clueInput = document.createElement('input');
        clueInput.type = 'text'; clueInput.className = 'candidate-clue-input';
        clueInput.placeholder = 'Definición / pista'; clueInput.value = c.clue;
        clueInput.addEventListener('input', () => { c.clue = clueInput.value; li.classList.toggle('no-clue', !c.clue.trim()); });
        const del = el('button', 'candidate-del', '✕');
        del.type = 'button';
        del.addEventListener('click', () => { wizard.candidatos.splice(i, 1); renderCandidateList(); });
        li.append(cb, w, clueInput, del);
        ul.append(li);
    });
}

/* ---------- paso: palabras (manual o revisión final IA) ---------- */
function wizEnterPalabras() {
    const isManual = wizard.origin === 'manual';
    $('#palabras-manual-field').hidden = !isManual;
    $('#palabras-explain').textContent = isManual
        ? 'Escribí cada palabra con su definición (formato "PALABRA: definición", una por línea). También podés agregarlas una por una abajo.'
        : 'Esta es la lista final confirmada por la IA. Completá las definiciones que falten y ajustá lo que necesites antes de generar el crucigrama.';
    if (!isManual) $('#f-palabras-manual').value = '';
    renderWordFinalList();
}
function renderWordFinalList() {
    const ul = $('#word-final-list');
    ul.innerHTML = '';
    wizard.palabrasFinal.forEach((w, i) => {
        const li = el('li');
        li.classList.toggle('no-clue', !w.clue.trim());
        const wordSpan = el('span', 'candidate-word', w.word);
        const clueInput = document.createElement('input');
        clueInput.type = 'text'; clueInput.className = 'candidate-clue-input';
        clueInput.placeholder = 'Definición / pista (obligatoria)'; clueInput.value = w.clue;
        clueInput.addEventListener('input', () => { w.clue = clueInput.value; li.classList.toggle('no-clue', !w.clue.trim()); renderCapacityMsg(); });
        const del = el('button', 'candidate-del', '✕');
        del.type = 'button';
        del.addEventListener('click', () => { wizard.palabrasFinal.splice(i, 1); renderWordFinalList(); });
        li.append(wordSpan, clueInput, del);
        ul.append(li);
    });
    renderCapacityMsg();
}
function renderCapacityMsg() {
    const box = $('#palabras-capacity');
    const missing = wizard.palabrasFinal.filter((w) => !w.clue.trim()).length;
    box.className = 'act-msg' + (missing ? ' warn' : '');
    let msg = `${wizard.palabrasFinal.length} palabra(s) cargada(s).`;
    if (missing) msg += ` Faltan definiciones en ${missing}.`;
    if (wizard.palabrasFinal.length > 30) msg += ' Con muchas palabras es más difícil que entren todas conectadas; considerá usar entre 10 y 20.';
    box.textContent = msg;
}
$('#btn-add-palabra').addEventListener('click', () => {
    const wordInput = $('#f-palabra-nueva'), clueInput = $('#f-clue-nueva');
    const word = wordInput.value.trim();
    if (!word) return;
    wizard.palabrasFinal.push({ word, clue: clueInput.value.trim() });
    wordInput.value = ''; clueInput.value = '';
    renderWordFinalList();
});
$('#f-clue-nueva').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#btn-add-palabra').click(); }
});

/* ---------- paso: previsualizar ---------- */
function buildNumberMap(result) {
    const m = new Map();
    result.placed.forEach((p) => { if (p.number) m.set(p.row + ',' + p.col, p.number); });
    return m;
}
function generatePreview() {
    const words = wizard.palabrasFinal.map((w) => ({ word: w.word, clue: w.clue }));
    const result = CrosswordEngine.buildCrossword(words);
    wizard.puzzle = result;
    renderPreviewGrid(result);

    const msg = $('#preview-msg');
    if (!result.placed.length) {
        msg.className = 'act-msg err';
        msg.textContent = 'No se pudo armar el crucigrama: ninguna palabra comparte letras con las demás. Agregá términos relacionados (que compartan letras) o revisá la ortografía.';
    } else if (result.unplaced.length) {
        msg.className = 'act-msg warn';
        msg.textContent = `Se ubicaron ${result.placed.length} de ${words.length} palabras (${result.acrossClues.length} horizontales, ${result.downClues.length} verticales). No lograron cruzarse con el resto: ${result.unplaced.join(', ')}. Es un límite real de construir crucigramas con vocabulario libre: esas palabras no comparten letras con ninguna otra. Probá agregar términos relacionados o volver a generar.`;
    } else {
        msg.className = 'act-msg ok';
        msg.textContent = `¡Listo! Se ubicaron las ${result.placed.length} palabras (${result.acrossClues.length} horizontales, ${result.downClues.length} verticales) en una grilla de ${result.rows}×${result.cols}.`;
    }
    $('#wiz-next').textContent = '💾 Guardar actividad';
    $('#wiz-next').disabled = result.placed.length === 0;
}
function renderPreviewGrid(result) {
    const box = $('#cw-preview');
    box.innerHTML = '';
    if (!result.rows) { box.textContent = '—'; return; }
    const numAt = buildNumberMap(result);
    const grid = el('div', 'cw-grid');
    grid.style.gridTemplateColumns = `repeat(${result.cols}, var(--cw-cell))`;
    for (let r = 0; r < result.rows; r++) {
        for (let c = 0; c < result.cols; c++) {
            const ch = result.grid[r][c];
            const cell = el('div', 'cw-cell' + (ch === null ? ' empty' : ' fill'));
            if (ch !== null) {
                // borde derecho/inferior solo donde termina el bloque de casillas
                if (c + 1 >= result.cols || result.grid[r][c + 1] === null) cell.classList.add('b-r');
                if (r + 1 >= result.rows || result.grid[r + 1][c] === null) cell.classList.add('b-b');
                const num = numAt.get(r + ',' + c);
                if (num) cell.append(el('span', 'cw-num', String(num)));
                cell.append(el('span', 'cw-letter', ch));
            }
            grid.append(cell);
        }
    }
    box.append(grid);
}
$('#btn-regenerate').addEventListener('click', generatePreview);

/* ---------- guardar / fin ---------- */
async function saveActivity() {
    if (!wizard.puzzle || !wizard.puzzle.placed.length) { setMsg('No hay crucigrama generado todavía.', 'err'); return; }
    const btn = $('#wiz-next');
    btn.disabled = true;
    try {
        const p = wizard.puzzle;
        const payload = {
            rows: p.rows, cols: p.cols, grid: p.grid,
            placed: p.placed, unplaced: p.unplaced,
            acrossClues: p.acrossClues, downClues: p.downClues,
            source: wizard.origin || 'manual',
        };
        const res = await apiPost('cw_save_project', { title: wizard.titulo, puzzle: JSON.stringify(payload) });
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
    $('#fin-detail').textContent = `"${wizard.titulo}" se guardó con ${wizard.puzzle ? wizard.puzzle.placed.length : 0} palabras. Publicalo para que tus estudiantes lo vean en el catálogo, o compartiles el enlace directo.`;
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
        await apiPost('cw_publish_project', { id: wizard.savedId, estado: 'published' });
        const url = playUrl(wizard.savedId);
        try { await navigator.clipboard.writeText(url); } catch (e) { /* sin permiso de portapapeles */ }
        msg.className = 'act-msg ok';
        msg.textContent = 'Publicado. Enlace para compartir (copiado): ' + url;
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
