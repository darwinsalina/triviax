/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Motor de juego de la modalidad "Puzle" (jigsaw).
   Portado del experimento: corte de piezas por background-position, formas
   jigsaw por clipPath, lupa flotante y modos bandeja/revueltas. Solo cambia
   el origen de datos (api.php?action=jigsaw_*) y el registro de la partida.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API  = 'api.php';
const BASE = window.TRIVIAX_BASE || '';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';

function apiPost(action, body) {
    const fd = body instanceof FormData ? body : new FormData();
    if (!(body instanceof FormData) && body) {
        Object.keys(body).forEach(k => fd.append(k, body[k]));
    }
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: fd }).then(r => r.json());
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

/* ---------- Navegación ---------- */
function go(screen) {
    $$('.act-screen').forEach(s => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (magnifier) magnifier.hidden = true;
    if (screen === 'catalog') loadCatalog();
}
document.addEventListener('click', e => {
    const t = e.target.closest('[data-go]');
    if (t) go(t.dataset.go);
});

/* ════════════════════════ CATÁLOGO ════════════════════════ */
async function loadCatalog() {
    const list = $('#catalog');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await fetch(API + '?action=jigsaw_list_published').then(r => r.json());
        if (!data.ok) throw new Error(data.error || 'Error');
        renderCatalog(data.projects || []);
    } catch (err) {
        list.innerHTML = '<p class="act-empty">No se pudo cargar el catálogo: ' + escapeHtml(err.message) + '</p>';
    }
}

function renderCatalog(projects) {
    const list = $('#catalog');
    $('#catalog-empty').hidden = projects.length > 0;
    list.innerHTML = '';
    projects.forEach(p => {
        const piezas = p.difficultyMode === 'fixed'
            ? `${p.fixedGrid}×${p.fixedGrid} (${p.fixedGrid * p.fixedGrid} piezas)`
            : 'Dificultad libre';
        const card = document.createElement('div');
        card.className = 'act-pcard';
        card.innerHTML = `
            <img class="act-pcard-thumb" src="${BASE}${p.thumb}" alt="${escapeHtml(p.name)}" loading="lazy">
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(p.name)}</p>
                <p class="act-pcard-meta">${piezas}</p>
            </div>`;
        card.addEventListener('click', () => openSetup(p));
        list.appendChild(card);
    });
}

/* ════════════════════════ CONFIGURAR ════════════════════════ */
let currentProject = null;

function openSetup(p) {
    currentProject = p;
    $('#setup-title').textContent = p.name;
    $('#setup-img').src = BASE + p.image;
    const gridWrap = $('#setup-grid-wrap');
    if (p.difficultyMode === 'fixed') {
        gridWrap.hidden = true;
    } else {
        gridWrap.hidden = false;
        $('#setup-grid').value = '4';
    }
    $('#setup-mode-wrap').hidden = (p.playMode !== 'choose');
    go('setup');
}

$('#btn-start-play').addEventListener('click', () => {
    const p = currentProject;
    const grid = p.difficultyMode === 'fixed' ? p.fixedGrid : parseInt($('#setup-grid').value, 10);
    let mode = p.playMode;
    if (mode === 'choose') mode = $('input[name="setupmode"]:checked').value;
    startGame(p, grid, mode);
});

/* ════════════════════════ JUEGO ════════════════════════ */
const board = $('#board');
const tray  = $('#tray');
const magnifier = $('#magnifier');

let game = null;
let session = null; // {id, token} de la partida en BD

async function startGame(project, N, mode) {
    go('game');

    const ratio = project.imageW / project.imageH;
    const trayMode = (mode === 'tray');
    const maxW = Math.min(window.innerWidth - (trayMode ? 60 : 40), trayMode ? 560 : 640);
    const maxH = window.innerHeight - 200;
    let w = maxW, h = w / ratio;
    if (h > maxH) { h = maxH; w = h * ratio; }
    const pieceW = Math.floor(w / N);
    const pieceH = Math.floor(h / N);
    const boardW = pieceW * N;
    const boardH = pieceH * N;

    const shape = (project.pieceShape === 'jigsaw') ? 'jigsaw' : 'classic';
    const padX = shape === 'jigsaw' ? Math.ceil(TAB_FACTOR * pieceH) + 1 : 0;
    const padY = shape === 'jigsaw' ? Math.ceil(TAB_FACTOR * pieceW) + 1 : 0;

    game = {
        project, N, mode, shape, pieceW, pieceH, boardW, boardH, padX, padY,
        imgUrl: new URL(BASE + project.image, document.baseURI).href,
        total: N * N,
        cells: new Array(N * N).fill(null),
        pieces: [],
        startTime: Date.now(),
        solved: false,
        moves: 0,
        trayScale: Math.min(1, 64 / pieceW),
    };

    if (shape === 'jigsaw') buildClipDefs();
    buildBoard();
    buildPieces();

    $('#win-banner').hidden = true;
    $('#btn-hint-toggle').hidden = !project.showHint;
    updateInfo();

    // Registrar la partida (para el reporte docente). No bloquea el juego.
    session = null;
    apiPost('jigsaw_start', { id: String(project.id) })
        .then(d => { if (d.ok) session = { id: d.session_id, token: d.session_token }; })
        .catch(() => {});
}

/* ---------- Geometría de piezas jigsaw ---------- */
const TAB_FACTOR = 0.27;

function genEdgeSigns(N) {
    const H = Array.from({ length: N + 1 }, () => new Array(N).fill(0));
    const V = Array.from({ length: N + 1 }, () => new Array(N).fill(0));
    for (let r = 1; r < N; r++) for (let c = 0; c < N; c++) H[r][c] = Math.random() < 0.5 ? -1 : 1;
    for (let c = 1; c < N; c++) for (let r = 0; r < N; r++) V[c][r] = Math.random() < 0.5 ? -1 : 1;
    return { H, V };
}

function pieceSigns(N, signs, r, c) {
    return {
        top:    r === 0     ? 0 : -signs.H[r][c],
        bottom: r === N - 1 ? 0 :  signs.H[r + 1][c],
        left:   c === 0     ? 0 : -signs.V[c][r],
        right:  c === N - 1 ? 0 :  signs.V[c + 1][r],
    };
}

const f4 = n => Math.round(n * 10000) / 10000;

function edgePath(S, E, Nrm, sign, th) {
    if (!sign) return `L ${f4(E[0])} ${f4(E[1])} `;
    const D = [E[0] - S[0], E[1] - S[1]];
    const P = (u, p) => [
        S[0] + D[0] * u + Nrm[0] * p * th * sign,
        S[1] + D[1] * u + Nrm[1] * p * th * sign,
    ];
    const a  = P(0.40, 0.0);
    const b1 = P(0.34, 0.0), b2 = P(0.28, 1.0), b = P(0.50, 1.0);
    const c1 = P(0.72, 1.0), c2 = P(0.66, 0.0), c = P(0.60, 0.0);
    return `L ${f4(a[0])} ${f4(a[1])} ` +
           `C ${f4(b1[0])} ${f4(b1[1])} ${f4(b2[0])} ${f4(b2[1])} ${f4(b[0])} ${f4(b[1])} ` +
           `C ${f4(c1[0])} ${f4(c1[1])} ${f4(c2[0])} ${f4(c2[1])} ${f4(c[0])} ${f4(c[1])} ` +
           `L ${f4(E[0])} ${f4(E[1])} `;
}

function buildClipDefs() {
    const g = game;
    const Bw = g.pieceW + 2 * g.padX;
    const Bh = g.pieceH + 2 * g.padY;
    const fx0 = g.padX / Bw, fx1 = (g.padX + g.pieceW) / Bw;
    const fy0 = g.padY / Bh, fy1 = (g.padY + g.pieceH) / Bh;
    const thX = (TAB_FACTOR * g.pieceH) / Bw;
    const thY = (TAB_FACTOR * g.pieceW) / Bh;

    const signs = genEdgeSigns(g.N);
    let defs = '';
    for (let id = 0; id < g.total; id++) {
        const c = id % g.N, r = Math.floor(id / g.N);
        const s = pieceSigns(g.N, signs, r, c);
        let d = `M ${f4(fx0)} ${f4(fy0)} `;
        d += edgePath([fx0, fy0], [fx1, fy0], [0, -1], s.top,    thY);
        d += edgePath([fx1, fy0], [fx1, fy1], [1,  0], s.right,  thX);
        d += edgePath([fx1, fy1], [fx0, fy1], [0,  1], s.bottom, thY);
        d += edgePath([fx0, fy1], [fx0, fy0], [-1, 0], s.left,   thX);
        d += 'Z';
        defs += `<clipPath id="clip-${id}" clipPathUnits="objectBoundingBox"><path d="${d}"/></clipPath>`;
    }
    $('#clip-defs defs').innerHTML = defs;
}

function buildBoard() {
    const g = game;
    board.classList.toggle('shaped', g.shape === 'jigsaw');
    board.style.width = g.boardW + 'px';
    board.style.height = g.boardH + 'px';
    board.style.gridTemplateColumns = `repeat(${g.N}, ${g.pieceW}px)`;
    board.style.gridTemplateRows = `repeat(${g.N}, ${g.pieceH}px)`;
    board.style.setProperty('--src-img', `url('${g.imgUrl}')`);

    if (g.project.showHint) {
        board.classList.remove('no-hint');
        board.style.setProperty('--hint-img', `url('${g.imgUrl}')`);
        board.style.setProperty('--hint-opacity', (g.project.hintOpacity / 100).toString());
    } else {
        board.classList.add('no-hint');
    }

    board.innerHTML = '';
    for (let i = 0; i < g.total; i++) {
        const cell = document.createElement('div');
        cell.className = 'cell';
        cell.dataset.index = i;
        board.appendChild(cell);
    }
}

function buildPieces() {
    const g = game;
    g.pieces = [];
    tray.innerHTML = '';

    for (let i = 0; i < g.total; i++) {
        const el = document.createElement('div');
        el.className = 'piece';
        el.dataset.id = i;
        if (g.shape === 'jigsaw') {
            el.classList.add('shaped');
            el.style.clipPath = `url(#clip-${i})`;
        }
        attachDrag(el);
        g.pieces.push({ id: i, el });
    }

    if (g.mode === 'tray') {
        tray.hidden = false;
        shuffled([...g.pieces]).forEach(p => placeInTray(p.el));
    } else {
        tray.hidden = true;
        shuffled(g.pieces.map(p => p.id)).forEach((id, cellIndex) => placeInCell(getPiece(id).el, cellIndex, true));
    }
}

function getPiece(id) { return game.pieces.find(p => p.id === Number(id)); }

function shuffled(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function setPieceVisual(el, scale) {
    const g = game;
    const id = Number(el.dataset.id);
    const col = id % g.N, row = Math.floor(id / g.N);
    const fullW = g.pieceW + 2 * g.padX;
    const fullH = g.pieceH + 2 * g.padY;
    const bx = col * g.pieceW - g.padX;
    const by = row * g.pieceH - g.padY;
    el.style.width  = (fullW * scale) + 'px';
    el.style.height = (fullH * scale) + 'px';
    el.style.backgroundImage = `url('${g.imgUrl}')`;
    el.style.backgroundSize = (g.boardW * scale) + 'px ' + (g.boardH * scale) + 'px';
    el.style.backgroundPosition = `${-bx * scale}px ${-by * scale}px`;
}

function placeInTray(el) {
    detachFromCell(el);
    el.classList.remove('in-cell', 'correct');
    el.style.position = 'relative';
    el.style.left = el.style.top = 'auto';
    setPieceVisual(el, game.trayScale);
    tray.appendChild(el);
}

function placeInCell(el, index, silent) {
    detachFromCell(el);
    const g = game;
    const col = index % g.N, row = Math.floor(index / g.N);
    el.classList.add('in-cell');
    el.style.position = 'absolute';
    el.style.left = (col * g.pieceW - g.padX) + 'px';
    el.style.top  = (row * g.pieceH - g.padY) + 'px';
    setPieceVisual(el, 1);
    board.appendChild(el);
    g.cells[index] = Number(el.dataset.id);
    el.dataset.cell = index;
    el.classList.toggle('correct', Number(el.dataset.id) === index);
    if (!silent) checkWin();
}

function detachFromCell(el) {
    if (el.dataset.cell != null && el.dataset.cell !== '') {
        const idx = Number(el.dataset.cell);
        if (game.cells[idx] === Number(el.dataset.id)) game.cells[idx] = null;
        delete el.dataset.cell;
    }
}

/* ---------- Arrastre (pointer events) ---------- */
function attachDrag(el) {
    el.addEventListener('pointerdown', onPointerDown);
    el.addEventListener('mouseenter', onPieceHover);
    el.addEventListener('mousemove', onPieceHoverMove);
    el.addEventListener('mouseleave', hideMagnifier);
}

let drag = null;

function onPointerDown(e) {
    if (game.solved) return;
    if (e.button != null && e.button !== 0) return;
    const el = e.currentTarget;
    hideMagnifier();
    e.preventDefault();

    const rect = el.getBoundingClientRect();
    drag = {
        el,
        fromCell: el.dataset.cell != null ? Number(el.dataset.cell) : null,
        offX: e.clientX - rect.left,
        offY: e.clientY - rect.top,
        w: rect.width,
        h: rect.height,
    };

    setPieceVisual(el, 1);
    el.classList.add('dragging');
    el.style.position = 'fixed';
    const fullW = game.pieceW + 2 * game.padX;
    const fullH = game.pieceH + 2 * game.padY;
    drag.offX = drag.offX * (fullW / drag.w);
    drag.offY = drag.offY * (fullH / drag.h);
    moveDragTo(e.clientX, e.clientY);

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
}

function moveDragTo(x, y) {
    drag.el.style.left = (x - drag.offX) + 'px';
    drag.el.style.top  = (y - drag.offY) + 'px';
}

function onPointerMove(e) {
    if (!drag) return;
    moveDragTo(e.clientX, e.clientY);
    const tgt = dropTargetAt(e.clientX, e.clientY);
    $$('.drop-hover').forEach(n => n.classList.remove('drop-hover'));
    if (tgt && tgt.kind === 'cell') tgt.cell.classList.add('drop-hover');
    if (tgt && tgt.kind === 'tray') tray.classList.add('drop-hover');
}

function onPointerUp(e) {
    if (!drag) return;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    $$('.drop-hover').forEach(n => n.classList.remove('drop-hover'));

    const el = drag.el;
    el.classList.remove('dragging');
    const tgt = dropTargetAt(e.clientX, e.clientY);
    const d = drag;
    drag = null;

    if (!tgt) { restorePiece(el, d); return; }
    game.moves++;

    if (tgt.kind === 'tray') {
        if (game.mode === 'tray') placeInTray(el);
        else restorePiece(el, d);
        checkWin();
        return;
    }

    const index = tgt.index;
    const occupantId = game.cells[index];

    if (occupantId == null) {
        placeInCell(el, index);
    } else if (occupantId === Number(el.dataset.id)) {
        placeInCell(el, index);
    } else {
        const occupant = getPiece(occupantId).el;
        if (d.fromCell != null) {
            placeInCell(occupant, d.fromCell, true);
            placeInCell(el, index);
        } else {
            placeInTray(occupant);
            placeInCell(el, index);
        }
    }
}

function restorePiece(el, d) {
    if (d.fromCell != null) placeInCell(el, d.fromCell, true);
    else placeInTray(el);
}

function dropTargetAt(x, y) {
    if (drag) drag.el.style.pointerEvents = 'none';
    const node = document.elementFromPoint(x, y);
    if (drag) drag.el.style.pointerEvents = '';
    if (!node) return null;
    const cell = node.closest('.cell');
    if (cell) return { kind: 'cell', cell, index: Number(cell.dataset.index) };
    if (node.closest('#tray')) return { kind: 'tray' };
    const piece = node.closest('.piece');
    if (piece && piece.dataset.cell != null) {
        return { kind: 'cell', cell: board.children[Number(piece.dataset.cell)], index: Number(piece.dataset.cell) };
    }
    return null;
}

/* ---------- Lupa ---------- */
function onPieceHover(e) {
    const el = e.currentTarget;
    if (game.mode !== 'tray' || el.parentElement !== tray || drag) return;
    const g = game;
    const id = Number(el.dataset.id);
    const col = id % g.N, row = Math.floor(id / g.N);
    const zoom = Math.min(2.4, 150 / g.pieceW);
    const bx = col * g.pieceW - g.padX;
    const by = row * g.pieceH - g.padY;
    magnifier.style.width  = ((g.pieceW + 2 * g.padX) * zoom) + 'px';
    magnifier.style.height = ((g.pieceH + 2 * g.padY) * zoom) + 'px';
    magnifier.style.backgroundImage = `url('${g.imgUrl}')`;
    magnifier.style.backgroundSize = (g.boardW * zoom) + 'px ' + (g.boardH * zoom) + 'px';
    magnifier.style.backgroundPosition = `${-bx * zoom}px ${-by * zoom}px`;
    magnifier.style.clipPath = (g.shape === 'jigsaw') ? `url(#clip-${id})` : 'none';
    magnifier.hidden = false;
    onPieceHoverMove(e);
}

function onPieceHoverMove(e) {
    if (magnifier.hidden) return;
    const pad = 18;
    let x = e.clientX + pad, y = e.clientY + pad;
    const w = magnifier.offsetWidth, h = magnifier.offsetHeight;
    if (x + w > window.innerWidth)  x = e.clientX - pad - w;
    if (y + h > window.innerHeight) y = e.clientY - pad - h;
    magnifier.style.left = x + 'px';
    magnifier.style.top = y + 'px';
}

function hideMagnifier() { magnifier.hidden = true; }

/* ---------- Estado / victoria ---------- */
function updateInfo() {
    const g = game;
    const correct = g.cells.filter((id, i) => id === i).length;
    $('#game-info').textContent = `${g.N}×${g.N} · ${g.total} piezas · ${correct}/${g.total} en su lugar`;
}

function checkWin() {
    updateInfo();
    const g = game;
    const done = g.cells.every((id, i) => id === i);
    if (done && !g.solved) {
        g.solved = true;
        const ms = Date.now() - g.startTime;
        const secs = Math.round(ms / 1000);
        const mm = String(Math.floor(secs / 60)).padStart(2, '0');
        const ss = String(secs % 60).padStart(2, '0');
        $('#win-detail').textContent = `${g.N}×${g.N} (${g.total} piezas) · Tiempo ${mm}:${ss}`;
        hideMagnifier();
        if (session) {
            apiPost('jigsaw_finish', {
                session_id: String(session.id),
                session_token: session.token,
                time_ms: String(ms),
                moves: String(g.moves),
                grid: String(g.N),
                mode: g.mode === 'scatter' ? 'scatter' : 'tray',
            }).catch(() => {});
        }
        setTimeout(() => { $('#win-banner').hidden = false; }, 250);
    }
}

/* ---------- Controles ---------- */
$('#btn-hint-toggle').addEventListener('click', () => board.classList.toggle('no-hint'));
$('#btn-shuffle').addEventListener('click', () => {
    if (!game) return;
    game.solved = false;
    game.moves = 0;
    $('#win-banner').hidden = true;
    buildBoard();
    buildPieces();
    game.startTime = Date.now();
    updateInfo();
});
$('#btn-replay').addEventListener('click', () => {
    if (!game) return;
    startGame(game.project, game.N, game.mode);
});

/* Inicio */
loadCatalog();

})();
