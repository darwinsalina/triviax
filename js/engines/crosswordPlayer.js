/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Juego "Crucigrama" para jugadores (crucigrama.php).
   Catálogo de actividades publicadas + juego con inputs por casilla,
   pistas horizontales/verticales, navegación con teclado, verificar y
   revelar. Con ?id=N abre esa actividad directamente (enlace compartido
   por el docente). Usa los endpoints cw_* de api.php.
   Integrado desde experimental/crucigramas.
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
        const res = await apiGet('cw_list_published');
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
let lastFocusCell = null;

async function openGame(id) {
    go('game');
    $('#win-banner').hidden = true;
    $('#cw-grid').innerHTML = '<p style="color:var(--act-text-dim)">Cargando…</p>';
    try {
        const params = ACCESS_CODE ? { id, codigo: ACCESS_CODE } : { id };
        const res = await apiGet('cw_get', params);
        if (!res.project || !res.project.puzzle) throw new Error('Actividad incompleta.');
        startGame(res.project);
    } catch (err) {
        $('#cw-grid').innerHTML = `<p style="color:var(--act-bad)">${escapeHtml(err.message)}</p>`;
    }
}

function buildNumberMap(puzzle) {
    const m = new Map();
    puzzle.placed.forEach((p) => { if (p.number) m.set(p.row + ',' + p.col, p.number); });
    return m;
}
function buildCellWordIndex(puzzle) {
    const idx = new Map();
    puzzle.placed.forEach((p) => {
        for (let i = 0; i < p.length; i++) {
            const rr = p.dir === 'down' ? p.row + i : p.row;
            const cc = p.dir === 'across' ? p.col + i : p.col;
            const k = rr + ',' + cc;
            const entry = idx.get(k) || { across: null, down: null };
            entry[p.dir] = p.id;
            idx.set(k, entry);
        }
    });
    return idx;
}

function startGame(project) {
    const puzzle = project.puzzle;
    game = {
        id: project.id,
        title: project.title,
        puzzle,
        numAt: buildNumberMap(puzzle),
        cellWords: buildCellWordIndex(puzzle),
        current: null,
        startedAt: Date.now(),
        won: false,
        submitted: false,
    };
    lastFocusCell = null;
    $('#game-info').textContent = `${project.title} · ${puzzle.placed.length} palabras`;
    renderGameGrid();
    renderClueLists();
    const first = puzzle.placed.find((p) => p.dir === 'across') || puzzle.placed[0];
    if (first) setCurrent(first.id, first.dir);
}

function cellAt(r, c) { return $(`.cw-cell[data-row="${r}"][data-col="${c}"]`, $('#cw-grid')); }
function inputAt(r, c) { const cell = cellAt(r, c); return cell ? cell.querySelector('input') : null; }
function moveFocus(r, c) { const inp = inputAt(r, c); if (inp) inp.focus(); }

function renderGameGrid() {
    const { rows, cols, grid } = game.puzzle;
    const box = $('#cw-grid');
    box.innerHTML = '';
    box.style.gridTemplateColumns = `repeat(${cols}, var(--cw-cell))`;
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const ch = grid[r][c];
            const cell = el('div', 'cw-cell' + (ch === null ? ' empty' : ' fill'));
            cell.dataset.row = r; cell.dataset.col = c;
            if (ch !== null) {
                // borde derecho/inferior solo donde termina el bloque de casillas
                if (c + 1 >= cols || grid[r][c + 1] === null) cell.classList.add('b-r');
                if (r + 1 >= rows || grid[r + 1][c] === null) cell.classList.add('b-b');
                const num = game.numAt.get(r + ',' + c);
                if (num) cell.append(el('span', 'cw-num', String(num)));
                const input = document.createElement('input');
                input.type = 'text'; input.className = 'cw-input'; input.maxLength = 1; input.autocomplete = 'off';
                input.dataset.row = r; input.dataset.col = c;
                input.addEventListener('mousedown', () => onCellMouseDown(r, c));
                input.addEventListener('focus', () => onCellFocus(r, c));
                input.addEventListener('keydown', (e) => onCellKeydown(e, r, c));
                input.addEventListener('input', (e) => onCellInput(e, r, c));
                cell.append(input);
            }
            box.append(cell);
        }
    }
}

function onCellMouseDown(r, c) {
    const entry = game.cellWords.get(r + ',' + c);
    if (!entry) return;
    let dir;
    if (lastFocusCell && lastFocusCell.r === r && lastFocusCell.c === c && entry.across != null && entry.down != null) {
        dir = (game.current && game.current.dir === 'across') ? 'down' : 'across';
    } else {
        dir = entry.across != null ? 'across' : 'down';
    }
    lastFocusCell = { r, c };
    setCurrent(entry[dir], dir);
}
function onCellFocus(r, c) {
    $$('.cw-cell.active-cell', $('#cw-grid')).forEach((cel) => cel.classList.remove('active-cell'));
    const cellEl = cellAt(r, c);
    if (cellEl) cellEl.classList.add('active-cell');
}

function currentWord() {
    if (!game.current) return null;
    return game.puzzle.placed.find((p) => p.id === game.current.wordId);
}
function setCurrent(wordId, dir) {
    if (wordId == null) return;
    game.current = { wordId, dir };
    highlightCurrent();
    updateClueActive();
}
function highlightCurrent() {
    $$('.cw-cell.active-word', $('#cw-grid')).forEach((c) => c.classList.remove('active-word'));
    const w = currentWord();
    if (!w) return;
    for (let i = 0; i < w.length; i++) {
        const rr = w.dir === 'down' ? w.row + i : w.row;
        const cc = w.dir === 'across' ? w.col + i : w.col;
        const cellEl = cellAt(rr, cc);
        if (cellEl) cellEl.classList.add('active-word');
    }
}

function onCellKeydown(e, r, c) {
    const { rows, cols } = game.puzzle;
    const entryHere = game.cellWords.get(r + ',' + c);
    if (e.key === 'ArrowRight') {
        e.preventDefault();
        const nc = Math.min(cols - 1, c + 1);
        const entry = game.cellWords.get(r + ',' + nc);
        if (entry && entry.across != null) setCurrent(entry.across, 'across');
        moveFocus(r, nc);
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        const nc = Math.max(0, c - 1);
        const entry = game.cellWords.get(r + ',' + nc);
        if (entry && entry.across != null) setCurrent(entry.across, 'across');
        moveFocus(r, nc);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        const nr = Math.min(rows - 1, r + 1);
        const entry = game.cellWords.get(nr + ',' + c);
        if (entry && entry.down != null) setCurrent(entry.down, 'down');
        moveFocus(nr, c);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const nr = Math.max(0, r - 1);
        const entry = game.cellWords.get(nr + ',' + c);
        if (entry && entry.down != null) setCurrent(entry.down, 'down');
        moveFocus(nr, c);
    } else if (e.key === 'Backspace' && !e.target.value) {
        e.preventDefault();
        stepBack(r, c);
    } else if (e.key === ' ') {
        e.preventDefault();
        if (entryHere && entryHere.across != null && entryHere.down != null) {
            const dir = (game.current && game.current.dir === 'across') ? 'down' : 'across';
            setCurrent(entryHere[dir], dir);
        }
    }
}
function stepBack(r, c) {
    if (!game.current) return;
    const dir = game.current.dir;
    const prev = dir === 'across' ? { r, c: c - 1 } : { r: r - 1, c };
    if (game.cellWords.has(prev.r + ',' + prev.c)) moveFocus(prev.r, prev.c);
}

const NORMALIZE_CHAR_MAP = { 'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U', 'Ü': 'U' };
function normalizeChar(ch) {
    ch = String(ch || '').toUpperCase();
    ch = NORMALIZE_CHAR_MAP[ch] || ch;
    return /^[A-ZÑ]$/.test(ch) ? ch : '';
}
function onCellInput(e, r, c) {
    const ch = normalizeChar(e.target.value.slice(-1));
    e.target.value = ch;
    const cellEl = cellAt(r, c);
    if (cellEl) cellEl.classList.remove('correct', 'incorrect');
    if (ch && game.current) {
        const dir = game.current.dir;
        const next = dir === 'across' ? { r, c: c + 1 } : { r: r + 1, c };
        if (game.cellWords.has(next.r + ',' + next.c)) moveFocus(next.r, next.c);
    }
    updateSolvedClues();
    checkWinSilently();
}

function updateSolvedClues() {
    game.puzzle.placed.forEach((p) => {
        let solved = true;
        for (let i = 0; i < p.length; i++) {
            const rr = p.dir === 'down' ? p.row + i : p.row;
            const cc = p.dir === 'across' ? p.col + i : p.col;
            const inp = inputAt(rr, cc);
            if (!inp || inp.value !== game.puzzle.grid[rr][cc]) { solved = false; break; }
        }
        const li = $(`.clue-list li[data-number="${p.number}"][data-dir="${p.dir}"]`);
        if (li) li.classList.toggle('solved', solved);
    });
}
function checkWinSilently() {
    const inputs = $$('.cw-input', $('#cw-grid'));
    for (const inp of inputs) {
        const r = +inp.dataset.row, c = +inp.dataset.col;
        if (inp.value !== game.puzzle.grid[r][c]) return;
    }
    showWin(false);
}
function showWin(revealed) {
    if (game.won) return;
    game.won = true;
    const secs = Math.round((Date.now() - game.startedAt) / 1000);
    $('#win-detail').textContent = revealed ? 'Revelaste las respuestas.' : `Resolviste el crucigrama en ${secs} segundos.`;
    $('#win-banner').hidden = false;
    submitResult(revealed).catch(() => {});
}

async function submitResult(revealed) {
    if (!game || game.submitted) return;
    game.submitted = true;
    let correct = 0, filled = 0, total = 0;
    $$('.cw-input', $('#cw-grid')).forEach((inp) => {
        const r = +inp.dataset.row, c = +inp.dataset.col;
        total++;
        if (inp.value) filled++;
        if (inp.value === game.puzzle.grid[r][c]) correct++;
    });
    await apiPost('cw_submit', {
        id: game.id,
        codigo: ACCESS_CODE || undefined,
        correct_cells: correct,
        filled_cells: filled,
        total_cells: total,
        revealed: !!revealed,
        time_ms: Math.max(0, Date.now() - game.startedAt),
        started_at: new Date(game.startedAt).toISOString().slice(0, 19).replace('T', ' '),
    });
}

function renderClueLists() {
    const acrossUl = $('#clue-across'), downUl = $('#clue-down');
    acrossUl.innerHTML = ''; downUl.innerHTML = '';
    game.puzzle.acrossClues.forEach((c) => acrossUl.append(buildClueLi(c, 'across')));
    game.puzzle.downClues.forEach((c) => downUl.append(buildClueLi(c, 'down')));
}
function buildClueLi(c, dir) {
    const li = el('li');
    li.dataset.number = c.number; li.dataset.dir = dir;
    li.append(el('b', '', String(c.number) + '.'), document.createTextNode(' ' + c.clue));
    li.addEventListener('click', () => {
        const word = game.puzzle.placed.find((p) => p.number === c.number && p.dir === dir);
        if (word) { setCurrent(word.id, dir); moveFocus(word.row, word.col); }
    });
    return li;
}
function updateClueActive() {
    $$('.clue-list li.active').forEach((li) => li.classList.remove('active'));
    const w = currentWord();
    if (!w) return;
    const li = $(`.clue-list li[data-number="${w.number}"][data-dir="${w.dir}"]`);
    if (li) { li.classList.add('active'); if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' }); }
}

$('#btn-replay-game').addEventListener('click', () => { if (game) openGame(game.id); });
$('#btn-replay').addEventListener('click', () => { $('#win-banner').hidden = true; if (game) openGame(game.id); });
$('#btn-check').addEventListener('click', () => {
    if (!game) return;
    let correct = 0, filled = 0, total = 0;
    $$('.cw-input', $('#cw-grid')).forEach((inp) => {
        const r = +inp.dataset.row, c = +inp.dataset.col;
        total++;
        if (!inp.value) return;
        filled++;
        const ok = inp.value === game.puzzle.grid[r][c];
        const cellEl = cellAt(r, c);
        if (cellEl) { cellEl.classList.toggle('correct', ok); cellEl.classList.toggle('incorrect', !ok); }
        if (ok) correct++;
    });
    const prevInfo = `${game.title} · ${game.puzzle.placed.length} palabras`;
    $('#game-info').textContent = `${correct}/${total} correctas (${filled} completadas)`;
    setTimeout(() => { if ($('#game-info')) $('#game-info').textContent = prevInfo; }, 3000);
});
$('#btn-reveal').addEventListener('click', () => {
    if (!game) return;
    $$('.cw-input', $('#cw-grid')).forEach((inp) => {
        const r = +inp.dataset.row, c = +inp.dataset.col;
        inp.value = game.puzzle.grid[r][c];
        const cellEl = cellAt(r, c);
        if (cellEl) { cellEl.classList.add('correct'); cellEl.classList.remove('incorrect'); }
    });
    updateSolvedClues();
    showWin(true);
});

/* ---------- arranque: catálogo o enlace directo ?id=N ---------- */
const directId = parseInt(URL_PARAMS.get('id') || '', 10);
if (!Number.isNaN(directId) && directId > 0) {
    openGame(directId);
} else {
    loadCatalog();
}

})();
