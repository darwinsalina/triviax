/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Juego "Sopa de letras" para jugadores (sopa.php).
   Catálogo de actividades publicadas + juego por arrastre en las 8
   direcciones. Con ?id=N abre esa actividad directamente (enlace
   compartido por el docente). Usa los endpoints ws_* de api.php y
   window.WordSearchEngine (js/wordsearch.js) para las direcciones.
   Integrado desde experimental/sopa-letras.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API = (window.TRIVIAX_BASE || '') + 'api.php';
const CSRF = window.TRIVIAX_CSRF_TOKEN || document.body?.dataset.actCsrf || '';
const URL_PARAMS = new URLSearchParams(window.location.search);
const ACCESS_CODE = (URL_PARAMS.get('codigo') || URL_PARAMS.get('code') || '').trim();

const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

function apiGet(action, params) {
    let url = API + '?action=' + encodeURIComponent(action);
    if (params) url += '&' + new URLSearchParams(params).toString();
    return fetch(url)
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.error || 'Error de red'); return d; });
}
function apiPost(action, body) {
    return fetch(API + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(body || {}),
    })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.error || 'Error de red'); return d; });
}

/* ---------- Navegación ---------- */
function go(screen) {
    $$('.act-screen').forEach((s) => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (screen === 'catalog') loadCatalog();
}
document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-go]');
    if (t) go(t.dataset.go);
});

/* ════════════ CATÁLOGO ════════════ */
async function loadCatalog() {
    const list = $('#catalog');
    const empty = $('#catalog-empty');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const res = await apiGet('ws_list_published');
        const projects = res.projects || [];
        list.innerHTML = '';
        empty.hidden = projects.length > 0;
        projects.forEach((p) => {
            const card = el('div', 'act-pcard');
            const body = el('div', 'act-pcard-body');
            body.append(el('p', 'act-pcard-title', p.title));
            body.append(el('p', 'act-pcard-meta', `${p.rows}×${p.cols} · ${p.wordCount} palabras`));
            card.append(body);
            card.addEventListener('click', () => openGame(p.id));
            list.append(card);
        });
    } catch (err) {
        list.innerHTML = '';
        empty.hidden = false;
        empty.textContent = 'No se pudieron cargar las actividades: ' + err.message;
    }
}

/* ════════════ JUEGO ════════════ */
let game = null;

async function openGame(id) {
    go('game');
    $('#win-banner').hidden = true;
    $('#ws-grid').innerHTML = '<p style="color:var(--act-text-dim)">Cargando…</p>';
    try {
        const params = ACCESS_CODE ? { id, codigo: ACCESS_CODE } : { id };
        const res = await apiGet('ws_get', params);
        if (!res.project || !res.project.puzzle) throw new Error('Actividad incompleta.');
        startGame(res.project);
    } catch (err) {
        $('#ws-grid').innerHTML = `<p style="color:var(--act-bad)">${escapeHtml(err.message)}</p>`;
    }
}

function startGame(project) {
    const puzzle = project.puzzle;
    game = {
        id: project.id,
        title: project.title,
        puzzle,
        found: new Set(),
        startedAt: Date.now(),
        selecting: false,
        selStart: null,
        selPath: [],
        colorSeq: 0,
        submitted: false,
    };
    $('#game-info').textContent = `${project.title} · ${puzzle.rows}×${puzzle.cols}`;
    renderGameGrid();
    renderWordPanel();
    updateProgress();
}

function renderGameGrid() {
    const { rows, cols, grid } = game.puzzle;
    const box = $('#ws-grid');
    box.innerHTML = '';
    box.style.gridTemplateColumns = `repeat(${cols}, var(--ws-cell))`;
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const cell = el('div', 'ws-cell', grid[r][c]);
            cell.dataset.row = r; cell.dataset.col = c;
            box.append(cell);
        }
    }
    box.addEventListener('pointerdown', onCellPointerDown);
}
function cellsOf() { return $$('.ws-cell', $('#ws-grid')); }
function cellAt(r, c) { return $(`.ws-cell[data-row="${r}"][data-col="${c}"]`, $('#ws-grid')); }

function onCellPointerDown(e) {
    const cell = e.target.closest('.ws-cell');
    if (!cell) return;
    game.selecting = true;
    game.selStart = { row: +cell.dataset.row, col: +cell.dataset.col };
    game.selPath = [game.selStart];
    paintSelection();
    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', onPointerUp, { once: true });
}
function onPointerMove(e) {
    if (!game || !game.selecting) return;
    const target = document.elementFromPoint(e.clientX, e.clientY);
    const cell = target && target.closest && target.closest('.ws-cell');
    if (!cell || !$('#ws-grid').contains(cell)) return;
    const cur = { row: +cell.dataset.row, col: +cell.dataset.col };
    const start = game.selStart;
    const dRow = cur.row - start.row, dCol = cur.col - start.col;
    const isStraight = dRow === 0 || dCol === 0 || Math.abs(dRow) === Math.abs(dCol);
    if (!isStraight) return;
    const steps = Math.max(Math.abs(dRow), Math.abs(dCol));
    const sr = steps === 0 ? 0 : Math.sign(dRow);
    const sc = steps === 0 ? 0 : Math.sign(dCol);
    const path = [];
    for (let i = 0; i <= steps; i++) path.push({ row: start.row + sr * i, col: start.col + sc * i });
    game.selPath = path;
    paintSelection();
}
function onPointerUp() {
    document.removeEventListener('pointermove', onPointerMove);
    if (!game || !game.selecting) return;
    game.selecting = false;
    checkSelection();
    game.selPath = [];
    paintSelection();
}
function paintSelection() {
    cellsOf().forEach((c) => c.classList.remove('selecting'));
    game.selPath.forEach((p) => {
        const c = cellAt(p.row, p.col);
        if (c) c.classList.add('selecting');
    });
}

function checkSelection() {
    if (game.selPath.length < 2) return;
    const letters = game.selPath.map((p) => game.puzzle.grid[p.row][p.col]).join('');
    const reversed = letters.split('').reverse().join('');
    const match = game.puzzle.placed.find((w) => !game.found.has(w.word) && (w.normalized === letters || w.normalized === reversed));
    if (!match) return;
    game.found.add(match.word);
    game.colorSeq = (game.colorSeq % 4) + 1;
    const cls = game.colorSeq === 1 ? 'found' : `found-${game.colorSeq}`;
    game.selPath.forEach((p) => {
        const c = cellAt(p.row, p.col);
        if (c) c.classList.add(cls);
    });
    renderWordPanel();
    updateProgress();
    if (game.found.size === game.puzzle.placed.length) showWin();
}

function renderWordPanel() {
    const ul = $('#word-list');
    ul.innerHTML = '';
    game.puzzle.placed.forEach((w) => {
        const li = el('li');
        li.classList.toggle('found', game.found.has(w.word));
        li.append(document.createTextNode(w.word));
        if (w.clue) li.append(el('span', 'wl-clue', w.clue));
        ul.append(li);
    });
}
function updateProgress() {
    $('#word-progress').textContent = `${game.found.size}/${game.puzzle.placed.length}`;
}

function showWin() {
    const secs = Math.round((Date.now() - game.startedAt) / 1000);
    $('#win-detail').textContent = `Encontraste las ${game.puzzle.placed.length} palabras en ${secs} segundos.`;
    $('#win-banner').hidden = false;
    submitResult().catch(() => {});
}

async function submitResult() {
    if (!game || game.submitted) return;
    game.submitted = true;
    await apiPost('ws_submit', {
        id: game.id,
        codigo: ACCESS_CODE || undefined,
        found_words: game.found.size,
        total_words: game.puzzle.placed.length,
        time_ms: Math.max(0, Date.now() - game.startedAt),
        started_at: new Date(game.startedAt).toISOString().slice(0, 19).replace('T', ' '),
    });
}

$('#btn-replay-game').addEventListener('click', () => { if (game) openGame(game.id); });
$('#btn-replay').addEventListener('click', () => { $('#win-banner').hidden = true; if (game) openGame(game.id); });
$('#btn-hint').addEventListener('click', () => {
    if (!game) return;
    const pending = game.puzzle.placed.filter((w) => !game.found.has(w.word));
    if (!pending.length) return;
    const w = pending[Math.floor(Math.random() * pending.length)];
    const [dr, dc] = WordSearchEngine.DIRS[w.dir];
    for (let i = 0; i < w.length; i++) {
        const c = cellAt(w.row + dr * i, w.col + dc * i);
        if (c) c.classList.add('hint');
    }
    setTimeout(() => cellsOf().forEach((c) => c.classList.remove('hint')), 1200);
});

/* ---------- arranque: catálogo o enlace directo ?id=N ---------- */
const directId = parseInt(URL_PARAMS.get('id') || '', 10);
if (!Number.isNaN(directId) && directId > 0) {
    openGame(directId);
} else {
    loadCatalog();
}

})();
