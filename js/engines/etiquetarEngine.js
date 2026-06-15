/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Motor de juego de la modalidad "Etiquetar" (etiquetar).
   Portado del experimento: capas img+svg+overlay en %, líneas guía con
   viewBox 0..100 + non-scaling-stroke, caja flotante movible/colapsable y
   arrastre de etiquetas a su casilla. Solo cambia el origen de datos
   (api.php?action=etiquetar_*) y el registro de la partida.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API  = 'api.php';
const BASE = window.TRIVIAX_BASE || '';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';
const SVGNS = 'http://www.w3.org/2000/svg';
const clamp01 = v => Math.max(0, Math.min(1, v));
const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
const DEFAULT_BOX = { x: 0.62, y: 0.04 };

function apiPost(action, fields) {
    const fd = new FormData();
    if (fields) Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: fd }).then(r => r.json());
}

/* ---------- Navegación ---------- */
function go(screen) {
    $$('.act-screen').forEach(s => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (screen === 'catalog') loadCatalog();
}
document.addEventListener('click', e => {
    const t = e.target.closest('[data-go]');
    if (t) go(t.dataset.go);
});

function pointerToNorm(stage, clientX, clientY) {
    const r = stage.getBoundingClientRect();
    return { nx: clamp01((clientX - r.left) / r.width), ny: clamp01((clientY - r.top) / r.height) };
}
function placeBoxNorm(boxEl, stageEl, nx, ny) {
    const sw = stageEl.clientWidth, sh = stageEl.clientHeight;
    let x = Math.max(0, Math.min(sw - boxEl.offsetWidth, nx * sw));
    let y = Math.max(0, Math.min(sh - boxEl.offsetHeight, ny * sh));
    boxEl.style.left = x + 'px'; boxEl.style.top = y + 'px';
    boxEl.style.right = 'auto'; boxEl.style.bottom = 'auto';
    return { x: sw ? x / sw : nx, y: sh ? y / sh : ny };
}
function readBoxNorm(boxEl, stageEl) {
    const sw = stageEl.clientWidth || 1, sh = stageEl.clientHeight || 1;
    return { x: clamp01(boxEl.offsetLeft / sw), y: clamp01(boxEl.offsetTop / sh) };
}
function shuffled(arr) {
    for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]]; }
    return arr;
}

/* ════════════════════════ CATÁLOGO ════════════════════════ */
async function loadCatalog() {
    const list = $('#catalog');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await fetch(API + '?action=etiquetar_list_published').then(r => r.json());
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
        const n = (p.labels || []).length;
        const card = document.createElement('div');
        card.className = 'act-pcard';
        card.innerHTML = `
            <img class="act-pcard-thumb" src="${BASE}${p.thumb}" alt="${escapeHtml(p.name)}" loading="lazy">
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(p.name)}</p>
                <p class="act-pcard-meta">${n} etiqueta${n === 1 ? '' : 's'}</p>
            </div>`;
        card.addEventListener('click', () => startGame(p));
        list.appendChild(card);
    });
}

/* ════════════════════════ JUEGO ════════════════════════ */
const imgPlay = $('#img-play');
const stagePlay = $('#stage-play');
const svgPlay = $('#svg-play');
const overlayPlay = $('#overlay-play');
const tray = $('#tray');
svgPlay.setAttribute('viewBox', '0 0 100 100');
svgPlay.setAttribute('preserveAspectRatio', 'none');

let game = null;
let session = null;

function startGame(project) {
    go('game');
    resetTrayFloat();
    const labels = (project.labels || []).map((l, i) => ({ ...l, id: i }));
    game = {
        project, labels,
        fill: {}, slotEls: {}, anchorEls: {}, chips: {},
        trayBox: { ...(project.box || DEFAULT_BOX) },
        startTime: Date.now(),
        solved: false,
    };
    imgPlay.onload = renderGame;
    imgPlay.src = new URL(BASE + project.image, document.baseURI).href;
    $('#win-banner').hidden = true;

    session = null;
    apiPost('etiquetar_start', { id: String(project.id) })
        .then(d => { if (d.ok) session = { id: d.session_id, token: d.session_token }; })
        .catch(() => {});
}

function renderGame() {
    const g = game;
    svgPlay.innerHTML = ''; overlayPlay.innerHTML = ''; tray.innerHTML = '';
    g.fill = {}; g.slotEls = {}; g.anchorEls = {}; g.chips = {};

    g.labels.forEach(lab => {
        const line = document.createElementNS(SVGNS, 'line');
        line.setAttribute('vector-effect', 'non-scaling-stroke');
        line.setAttribute('x1', lab.bx * 100); line.setAttribute('y1', lab.by * 100);
        line.setAttribute('x2', lab.ax * 100); line.setAttribute('y2', lab.ay * 100);
        svgPlay.appendChild(line);

        const anchor = document.createElement('div');
        anchor.className = 'lab-anchor';
        anchor.style.left = (lab.ax * 100) + '%'; anchor.style.top = (lab.ay * 100) + '%';
        overlayPlay.appendChild(anchor);
        g.anchorEls[lab.id] = anchor;

        const slot = document.createElement('div');
        slot.className = 'lab-slot';
        slot.dataset.slot = lab.id;
        slot.style.left = (lab.bx * 100) + '%'; slot.style.top = (lab.by * 100) + '%';
        overlayPlay.appendChild(slot);
        g.slotEls[lab.id] = slot;
    });

    shuffled(g.labels.map(l => l.id)).forEach(id => {
        const lab = g.labels.find(item => item.id === id);
        if (!lab) return;
        const chip = document.createElement('div');
        chip.className = 'lab-chip';
        chip.textContent = lab.text;
        chip.dataset.chip = id;
        attachChipDrag(chip);
        tray.appendChild(chip);
        g.chips[id] = chip;
    });

    g.trayBox = placeBoxNorm(trayFloat, stagePlay, g.trayBox.x, g.trayBox.y);
    updateTrayCount();
    updateInfo();
}

/* --- arrastre de etiquetas (bandeja <-> casillas) --- */
let drag = null;
function attachChipDrag(chip) { chip.addEventListener('pointerdown', onChipDown); }

function onChipDown(e) {
    if (game.solved) return;
    if (e.button != null && e.button !== 0) return;
    e.preventDefault();
    const chip = e.currentTarget;
    const id = Number(chip.dataset.chip);
    const rect = chip.getBoundingClientRect();
    drag = { chip, id, fromSlot: chip.dataset.slot != null ? Number(chip.dataset.slot) : null,
             offX: e.clientX - rect.left, offY: e.clientY - rect.top };
    chip.classList.add('dragging', 'floating');
    chip.style.transform = 'none';
    chip.style.margin = '0';
    moveChip(e.clientX, e.clientY);
    window.addEventListener('pointermove', onChipMove);
    window.addEventListener('pointerup', onChipUp);
}
function moveChip(x, y) {
    drag.chip.style.left = (x - drag.offX) + 'px';
    drag.chip.style.top  = (y - drag.offY) + 'px';
}
function onChipMove(e) {
    moveChip(e.clientX, e.clientY);
    const t = dropTargetAt(e.clientX, e.clientY);
    $$('.drop-hover').forEach(n => n.classList.remove('drop-hover'));
    if (t && t.kind === 'slot') t.el.classList.add('drop-hover');
    if (t && t.kind === 'tray') tray.classList.add('drop-hover');
}
function onChipUp(e) {
    window.removeEventListener('pointermove', onChipMove);
    window.removeEventListener('pointerup', onChipUp);
    $$('.drop-hover').forEach(n => n.classList.remove('drop-hover'));
    const t = dropTargetAt(e.clientX, e.clientY);
    const d = drag; drag = null;
    d.chip.classList.remove('dragging', 'floating');

    if (!t) { restoreChip(d); return; }
    if (t.kind === 'tray') { chipToTray(d.chip); checkWin(); return; }

    const slotId = t.slotId;
    const occupant = g_fill(slotId);
    if (occupant == null || occupant === d.id) {
        chipToSlot(d.chip, slotId);
    } else {
        if (d.fromSlot != null) chipToSlot(game.chips[occupant], d.fromSlot);
        else chipToTray(game.chips[occupant]);
        chipToSlot(d.chip, slotId);
    }
    checkWin();
}
function g_fill(slotId) { return game.fill[slotId] != null ? game.fill[slotId] : null; }

function clearChipPlacement(chip) {
    const prev = chip.dataset.slot;
    const prevSlot = Number(prev);
    if (prev != null && prev !== '' && game.fill[prevSlot] === Number(chip.dataset.chip)) {
        delete game.fill[prevSlot];
        game.slotEls[prevSlot].classList.remove('filled');
        game.anchorEls[prevSlot].classList.remove('correct');
    }
    delete chip.dataset.slot;
}
function chipToSlot(chip, slotId) {
    clearChipPlacement(chip);
    const numericSlot = Number(slotId);
    const numericChip = Number(chip.dataset.chip);
    const lab = game.labels.find(item => item.id === numericSlot);
    if (!lab) return;
    chip.style.position = 'absolute';
    chip.style.transform = 'translate(-50%, -50%)';
    chip.style.left = (lab.bx * 100) + '%';
    chip.style.top  = (lab.by * 100) + '%';
    overlayPlay.appendChild(chip);
    chip.dataset.slot = numericSlot;
    chip.classList.add('filled');
    game.fill[numericSlot] = numericChip;
    game.slotEls[numericSlot].classList.add('filled');
    game.anchorEls[numericSlot].classList.toggle('correct', numericChip === numericSlot);
    updateTrayCount();
}
function chipToTray(chip) {
    clearChipPlacement(chip);
    chip.classList.remove('filled');
    chip.style.position = 'relative';
    chip.style.transform = 'none';
    chip.style.left = chip.style.top = 'auto';
    tray.appendChild(chip);
    updateTrayCount();
}
function restoreChip(d) {
    if (d.fromSlot != null) chipToSlot(d.chip, d.fromSlot);
    else chipToTray(d.chip);
}
function dropTargetAt(x, y) {
    if (drag) drag.chip.style.pointerEvents = 'none';
    const node = document.elementFromPoint(x, y);
    if (drag) drag.chip.style.pointerEvents = '';
    if (!node) return null;
    const slot = node.closest('.lab-slot');
    if (slot) return { kind: 'slot', el: slot, slotId: Number(slot.dataset.slot) };
    const placed = node.closest('.lab-chip[data-slot]');
    if (placed) { const sid = Number(placed.dataset.slot); return { kind: 'slot', el: game.slotEls[sid], slotId: sid }; }
    if (node.closest('#tray')) return { kind: 'tray' };
    return null;
}

function updateInfo() {
    const total = game.labels.length;
    const correct = game.labels.filter(l => game.fill[l.id] === l.id).length;
    $('#game-info').textContent = `${total} etiqueta${total === 1 ? '' : 's'} · ${correct}/${total} correctas`;
}
function checkWin() {
    updateInfo();
    const g = game;
    const done = g.labels.every(l => g.fill[l.id] === l.id);
    if (done && !g.solved) {
        g.solved = true;
        const ms = Date.now() - g.startTime;
        const secs = Math.round(ms / 1000);
        const mm = String(Math.floor(secs / 60)).padStart(2, '0');
        const ss = String(secs % 60).padStart(2, '0');
        $('#win-detail').textContent = `${g.labels.length} etiquetas · Tiempo ${mm}:${ss}`;
        if (session) {
            apiPost('etiquetar_finish', {
                session_id: String(session.id),
                session_token: session.token,
                time_ms: String(ms),
                correct: String(g.labels.length),
                total: String(g.labels.length),
            }).catch(() => {});
        }
        setTimeout(() => { $('#win-banner').hidden = false; }, 250);
    }
}

$('#btn-replay').addEventListener('click', () => { if (game) startGame(game.project); });
$('#btn-replay-game').addEventListener('click', () => { if (game) startGame(game.project); });

/* ---------- Caja flotante (movible / colapsable) ---------- */
const trayFloat = $('#tray-float');
const trayHead = $('#tray-head');
const btnCollapse = $('#btn-tray-collapse');

function updateTrayCount() { $('#tray-count').textContent = tray.querySelectorAll('.lab-chip').length; }
function resetTrayFloat() { trayFloat.classList.remove('collapsed'); btnCollapse.textContent = '—'; }

btnCollapse.addEventListener('click', () => {
    const current = readBoxNorm(trayFloat, stagePlay);
    const collapsed = trayFloat.classList.toggle('collapsed');
    btnCollapse.textContent = collapsed ? '▢' : '—';
    if (game) game.trayBox = placeBoxNorm(trayFloat, stagePlay, current.x, current.y);
});

function makeBoxDraggable(headEl, boxEl, stageEl, onEnd) {
    headEl.addEventListener('pointerdown', e => {
        if (e.target.closest('button')) return;
        e.preventDefault();
        headEl.setPointerCapture(e.pointerId);
        const sr = stageEl.getBoundingClientRect();
        const br = boxEl.getBoundingClientRect();
        const ox = e.clientX - br.left, oy = e.clientY - br.top;
        boxEl.style.right = 'auto'; boxEl.style.bottom = 'auto';
        const move = ev => {
            let x = Math.max(0, Math.min(sr.width  - boxEl.offsetWidth,  ev.clientX - sr.left - ox));
            let y = Math.max(0, Math.min(sr.height - boxEl.offsetHeight, ev.clientY - sr.top  - oy));
            boxEl.style.left = x + 'px'; boxEl.style.top = y + 'px';
        };
        const up = () => {
            headEl.releasePointerCapture(e.pointerId);
            headEl.removeEventListener('pointermove', move);
            headEl.removeEventListener('pointerup', up);
            if (onEnd) onEnd();
        };
        headEl.addEventListener('pointermove', move);
        headEl.addEventListener('pointerup', up);
    });
}
makeBoxDraggable(trayHead, trayFloat, stagePlay, () => { if (game) game.trayBox = readBoxNorm(trayFloat, stagePlay); });

window.addEventListener('resize', () => {
    if (game && !trayFloat.hidden) game.trayBox = placeBoxNorm(trayFloat, stagePlay, game.trayBox.x, game.trayBox.y);
});

/* Inicio */
loadCatalog();

})();
