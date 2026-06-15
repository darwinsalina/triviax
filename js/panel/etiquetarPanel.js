/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Panel docente de la modalidad "Etiquetar" (etiquetar).
   Editor (imagen + etiquetas con punto-ancla + línea guía + caja-guía),
   listado, publicar/eliminar y reporte. Endpoints etiquetar_* de api.php.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API  = window.TRIVIAX_API  || '../api.php';
const BASE = window.TRIVIAX_BASE || '../';
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

function go(screen) {
    $$('.act-screen').forEach(s => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (screen === 'manage') loadProjects();
}
document.addEventListener('click', e => {
    const t = e.target.closest('[data-go]');
    if (t) go(t.dataset.go);
});

/* ════════════ LISTA ════════════ */
async function loadProjects() {
    const list = $('#project-list');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await fetch(API + '?action=etiquetar_list_projects', { headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
        if (!data.ok) throw new Error(data.error || 'Error');
        renderProjects(data.projects || []);
    } catch (err) {
        list.innerHTML = '<p class="act-empty">No se pudo cargar: ' + escapeHtml(err.message) + '</p>';
    }
}
function renderProjects(projects) {
    const list = $('#project-list');
    $('#list-empty').hidden = projects.length > 0;
    list.innerHTML = '';
    projects.forEach(p => {
        const published = p.estado === 'published';
        const card = document.createElement('div');
        card.className = 'act-pcard';
        card.innerHTML = `
            <img class="act-pcard-thumb" src="${BASE}${p.thumb}" alt="${escapeHtml(p.name)}" loading="lazy">
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(p.name)}</p>
                <p class="act-pcard-meta">${p.labelsCount} etiqueta(s) · ${p.sessions} partida(s)</p>
                <span class="act-pcard-badge ${published ? 'published' : ''}">${published ? 'Publicado' : 'Borrador'}</span>
            </div>
            <div class="act-pcard-actions">
                <button class="act-btn ghost sm" data-act="toggle" data-id="${p.id}" data-estado="${published ? 'draft' : 'published'}">${published ? 'Despublicar' : 'Publicar'}</button>
                <button class="act-btn ghost sm" data-act="report" data-id="${p.id}" data-name="${escapeHtml(p.name)}">Reporte</button>
                <button class="act-btn ghost sm" data-act="del" data-id="${p.id}">Eliminar</button>
            </div>`;
        list.appendChild(card);
    });
    $$('[data-act="toggle"]', list).forEach(b => b.addEventListener('click', async () => {
        const d = await apiPost('etiquetar_publish_project', { id: b.dataset.id, estado: b.dataset.estado });
        if (d.ok) loadProjects(); else alert(d.error || 'No se pudo cambiar el estado.');
    }));
    $$('[data-act="del"]', list).forEach(b => b.addEventListener('click', async () => {
        if (!confirm('¿Eliminar esta actividad y su imagen? Esta acción no se puede deshacer.')) return;
        const d = await apiPost('etiquetar_delete_project', { id: b.dataset.id });
        if (d.ok) loadProjects(); else alert(d.error || 'No se pudo eliminar.');
    }));
    $$('[data-act="report"]', list).forEach(b => b.addEventListener('click', () => openReport(b.dataset.id, b.dataset.name)));
}

/* ════════════ REPORTE ════════════ */
async function openReport(id, name) {
    $('#report-title').textContent = 'Reporte · ' + name;
    const body = $('#report-body');
    body.innerHTML = '<p class="act-empty">Cargando…</p>';
    go('report');
    try {
        const data = await fetch(API + '?action=etiquetar_report&id=' + encodeURIComponent(id), { headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
        if (!data.ok) throw new Error(data.error || 'Error');
        const rows = data.sessions || [];
        if (!rows.length) { body.innerHTML = '<p class="act-empty">Sin partidas registradas.</p>'; return; }
        const fmt = ms => ms == null ? '—' : (Math.floor(ms / 60000) + ':' + String(Math.floor((ms % 60000) / 1000)).padStart(2, '0'));
        body.innerHTML = `
            <table class="act-table">
                <thead><tr><th>Jugador</th><th>Estado</th><th>Aciertos</th><th>Tiempo</th><th>Fecha</th></tr></thead>
                <tbody>${rows.map(s => `
                    <tr>
                        <td>${escapeHtml(s.jugador || 'Invitado')}</td>
                        <td>${escapeHtml(s.estado)}</td>
                        <td>${s.correct != null ? s.correct + '/' + (s.total ?? '?') : '—'}</td>
                        <td>${fmt(s.time_ms)}</td>
                        <td>${escapeHtml((s.finished_at || s.started_at || '').replace('T', ' '))}</td>
                    </tr>`).join('')}</tbody>
            </table>`;
    } catch (err) {
        body.innerHTML = '<p class="act-empty">No se pudo cargar el reporte: ' + escapeHtml(err.message) + '</p>';
    }
}

/* ════════════ EDITOR ════════════ */
const fName  = $('#f-name');
const fImage = $('#f-image');
const imgEdit = $('#img-edit');
const stageEdit = $('#stage-edit');
const svgEdit = $('#svg-edit');
const overlayEdit = $('#overlay-edit');
svgEdit.setAttribute('viewBox', '0 0 100 100');
svgEdit.setAttribute('preserveAspectRatio', 'none');

const boxPreview = $('#box-preview');
const boxPreviewHead = $('#box-preview-head');

let editor = { file: null, pool: [], placed: [], seq: 0, box: { ...DEFAULT_BOX } };

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

fImage.addEventListener('change', () => {
    const file = fImage.files[0];
    if (!file) return;
    editor.file = file;
    imgEdit.onload = () => {
        $('#editor-workspace').hidden = false;
        boxPreview.hidden = false;
        editor.box = placeBoxNorm(boxPreview, stageEdit, editor.box.x, editor.box.y);
        refreshCreateEnabled();
    };
    imgEdit.src = URL.createObjectURL(file);
});
fName.addEventListener('input', refreshCreateEnabled);

const labelInput = $('#f-label-input');
labelInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); addLabelsFromString(labelInput.value); labelInput.value = ''; }
});
labelInput.addEventListener('paste', e => {
    const txt = (e.clipboardData || window.clipboardData).getData('text');
    if (/[,\n]/.test(txt)) { e.preventDefault(); addLabelsFromString(txt); labelInput.value = ''; }
});

function addLabelsFromString(str) {
    const existing = new Set([...editor.pool.map(p => p.text.toLowerCase()), ...editor.placed.map(p => p.text.toLowerCase())]);
    str.split(/[,\n]+/).map(s => s.trim()).filter(Boolean).forEach(text => {
        text = text.slice(0, 60);
        if (existing.has(text.toLowerCase())) return;
        existing.add(text.toLowerCase());
        editor.pool.push({ id: ++editor.seq, text });
    });
    renderPool();
}
function renderPool() {
    const pool = $('#labels-pool');
    pool.innerHTML = '';
    editor.pool.forEach(item => {
        const chip = document.createElement('div');
        chip.className = 'lab-chip';
        chip.textContent = item.text;
        chip.dataset.poolId = item.id;
        chip.addEventListener('pointerdown', startPoolDrag);
        pool.appendChild(chip);
    });
    $('#pool-count').textContent = editor.pool.length;
    refreshCreateEnabled();
}
function refreshCreateEnabled() {
    $('#btn-create').disabled = !(editor.file && fName.value.trim() && editor.placed.length > 0 && editor.pool.length === 0);
}

let poolDrag = null;
function startPoolDrag(e) {
    e.preventDefault();
    const chip = e.currentTarget;
    const item = editor.pool.find(p => p.id === Number(chip.dataset.poolId));
    if (!item) return;
    const ghost = chip.cloneNode(true);
    ghost.classList.add('dragging', 'floating');
    ghost.style.position = 'fixed';
    ghost.style.pointerEvents = 'none';
    ghost.style.margin = '0';
    document.body.appendChild(ghost);
    poolDrag = { item, ghost };
    movePoolGhost(e.clientX, e.clientY);
    window.addEventListener('pointermove', onPoolDragMove);
    window.addEventListener('pointerup', onPoolDragUp);
}
function movePoolGhost(x, y) {
    poolDrag.ghost.style.left = (x - poolDrag.ghost.offsetWidth / 2) + 'px';
    poolDrag.ghost.style.top  = (y - 16) + 'px';
}
function onPoolDragMove(e) { if (poolDrag) movePoolGhost(e.clientX, e.clientY); }
function onPoolDragUp(e) {
    if (!poolDrag) return;
    window.removeEventListener('pointermove', onPoolDragMove);
    window.removeEventListener('pointerup', onPoolDragUp);
    poolDrag.ghost.remove();
    const r = stageEdit.getBoundingClientRect();
    const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
    if (inside) {
        const { nx, ny } = pointerToNorm(stageEdit, e.clientX, e.clientY);
        const by = ny < 0.18 ? clamp01(ny + 0.12) : clamp01(ny - 0.12);
        placeLabel(poolDrag.item, nx, ny, nx, by);
        editor.pool = editor.pool.filter(p => p.id !== poolDrag.item.id);
        renderPool();
    }
    poolDrag = null;
}

function placeLabel(item, ax, ay, bx, by) {
    const lab = { id: item.id, text: item.text, ax, ay, bx, by, line: null, anchor: null, tag: null };

    const line = document.createElementNS(SVGNS, 'line');
    line.setAttribute('vector-effect', 'non-scaling-stroke');
    svgEdit.appendChild(line);

    const anchor = document.createElement('div');
    anchor.className = 'lab-anchor editor';
    makeStageDrag(anchor, stageEdit, (nx, ny) => { lab.ax = nx; lab.ay = ny; updateLab(lab); });
    overlayEdit.appendChild(anchor);

    const tag = document.createElement('div');
    tag.className = 'lab-chip';
    tag.innerHTML = `<span>${escapeHtml(item.text)}</span><span class="lab-tag-del" title="Quitar">✕</span>`;
    $('.lab-tag-del', tag).addEventListener('pointerdown', ev => ev.stopPropagation());
    $('.lab-tag-del', tag).addEventListener('click', ev => { ev.stopPropagation(); removeLabel(lab); });
    makeStageDrag(tag, stageEdit, (nx, ny) => { lab.bx = nx; lab.by = ny; updateLab(lab); });
    overlayEdit.appendChild(tag);

    lab.line = line; lab.anchor = anchor; lab.tag = tag;
    editor.placed.push(lab);
    updateLab(lab);
    refreshCreateEnabled();
}
function updateLab(lab) {
    lab.anchor.style.left = (lab.ax * 100) + '%';
    lab.anchor.style.top  = (lab.ay * 100) + '%';
    lab.tag.style.left = (lab.bx * 100) + '%';
    lab.tag.style.top  = (lab.by * 100) + '%';
    lab.line.setAttribute('x1', lab.bx * 100); lab.line.setAttribute('y1', lab.by * 100);
    lab.line.setAttribute('x2', lab.ax * 100); lab.line.setAttribute('y2', lab.ay * 100);
}
function removeLabel(lab) {
    lab.line.remove(); lab.anchor.remove(); lab.tag.remove();
    editor.placed = editor.placed.filter(l => l.id !== lab.id);
    editor.pool.push({ id: lab.id, text: lab.text });
    renderPool();
}

function makeStageDrag(el, stage, onMove) {
    el.addEventListener('pointerdown', e => {
        if (e.target.closest('.lab-tag-del')) return;
        e.preventDefault(); e.stopPropagation();
        el.setPointerCapture(e.pointerId);
        el.classList.add('dragging');
        const move = ev => { const { nx, ny } = pointerToNorm(stage, ev.clientX, ev.clientY); onMove(nx, ny); };
        const up = () => {
            el.classList.remove('dragging');
            el.releasePointerCapture(e.pointerId);
            el.removeEventListener('pointermove', move);
            el.removeEventListener('pointerup', up);
        };
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerup', up);
    });
}

function makeBoxDraggable(headEl, boxEl, stageEl, onEnd) {
    headEl.addEventListener('pointerdown', e => {
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
makeBoxDraggable(boxPreviewHead, boxPreview, stageEdit, () => { editor.box = readBoxNorm(boxPreview, stageEdit); });

window.addEventListener('resize', () => {
    if (!boxPreview.hidden && editor.file) editor.box = placeBoxNorm(boxPreview, stageEdit, editor.box.x, editor.box.y);
});

$('#btn-create').addEventListener('click', async () => {
    const msg = $('#create-msg'); msg.textContent = ''; msg.className = 'act-msg';
    const btn = $('#btn-create');
    const labels = editor.placed.map(l => ({ text: l.text, ax: l.ax, ay: l.ay, bx: l.bx, by: l.by }));

    const fd = new FormData();
    fd.append('action', 'etiquetar_save_project');
    fd.append('csrf_token', CSRF);
    fd.append('name', fName.value.trim());
    fd.append('image', editor.file);
    fd.append('labels', JSON.stringify(labels));
    fd.append('box', JSON.stringify(editor.box));

    btn.disabled = true; btn.textContent = 'Creando…';
    try {
        const data = await fetch(API + '?action=etiquetar_save_project', { method: 'POST', body: fd }).then(r => r.json());
        if (!data.ok) {
            const extra = Array.isArray(data.errors) && data.errors.length ? ' (' + data.errors.join(' ') + ')' : '';
            throw new Error((data.error || 'Error') + extra);
        }
        msg.textContent = '✓ Actividad creada. Publicala para que aparezca en el juego.';
        msg.classList.add('ok');
        resetEditor();
        setTimeout(() => go('manage'), 900);
    } catch (err) {
        msg.textContent = '✗ ' + err.message; msg.classList.add('err');
    } finally {
        btn.textContent = 'Crear actividad'; refreshCreateEnabled();
    }
});

function resetEditor() {
    editor = { file: null, pool: [], placed: [], seq: 0, box: { ...DEFAULT_BOX } };
    fName.value = ''; fImage.value = ''; labelInput.value = '';
    $('#editor-workspace').hidden = true;
    boxPreview.hidden = true;
    boxPreview.style.left = boxPreview.style.top = '';
    svgEdit.innerHTML = ''; overlayEdit.innerHTML = ''; $('#labels-pool').innerHTML = '';
    $('#pool-count').textContent = '0';
}

/* Inicio */
loadProjects();

})();
