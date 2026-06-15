/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX — Panel docente de la modalidad "Puzle" (jigsaw).
   Crear (multipart con imagen), listar, publicar/despublicar, eliminar y
   ver el reporte de sesiones. Reutiliza los endpoints jigsaw_* de api.php.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API  = window.TRIVIAX_API  || '../api.php';
const BASE = window.TRIVIAX_BASE || '../';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';

function apiPost(action, fields) {
    const fd = new FormData();
    if (fields) Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: fd }).then(r => r.json());
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
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

/* ════════════ LISTA / GESTIÓN ════════════ */
async function loadProjects() {
    const list = $('#project-list');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await fetch(API + '?action=jigsaw_list_projects', { headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
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
        const piezas = p.difficultyMode === 'fixed'
            ? `${p.fixedGrid}×${p.fixedGrid}` : 'Libre';
        const published = p.estado === 'published';
        const card = document.createElement('div');
        card.className = 'act-pcard';
        card.innerHTML = `
            <img class="act-pcard-thumb" src="${BASE}${p.thumb}" alt="${escapeHtml(p.name)}" loading="lazy">
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(p.name)}</p>
                <p class="act-pcard-meta">${piezas} · ${p.sessions} partida(s)</p>
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
        const d = await apiPost('jigsaw_publish_project', { id: b.dataset.id, estado: b.dataset.estado });
        if (d.ok) loadProjects(); else alert(d.error || 'No se pudo cambiar el estado.');
    }));
    $$('[data-act="del"]', list).forEach(b => b.addEventListener('click', async () => {
        if (!confirm('¿Eliminar este puzle y su imagen? Esta acción no se puede deshacer.')) return;
        const d = await apiPost('jigsaw_delete_project', { id: b.dataset.id });
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
        const data = await fetch(API + '?action=jigsaw_report&id=' + encodeURIComponent(id), { headers: { 'X-CSRF-Token': CSRF } }).then(r => r.json());
        if (!data.ok) throw new Error(data.error || 'Error');
        const rows = data.sessions || [];
        if (!rows.length) { body.innerHTML = '<p class="act-empty">Sin partidas registradas.</p>'; return; }
        const fmt = ms => ms == null ? '—' : (Math.floor(ms / 60000) + ':' + String(Math.floor((ms % 60000) / 1000)).padStart(2, '0'));
        body.innerHTML = `
            <table class="act-table">
                <thead><tr><th>Jugador</th><th>Estado</th><th>Cuadrícula</th><th>Modo</th><th>Tiempo</th><th>Movimientos</th><th>Fecha</th></tr></thead>
                <tbody>${rows.map(s => `
                    <tr>
                        <td>${escapeHtml(s.jugador || 'Invitado')}</td>
                        <td>${escapeHtml(s.estado)}</td>
                        <td>${s.grid ? s.grid + '×' + s.grid : '—'}</td>
                        <td>${escapeHtml(s.mode || '—')}</td>
                        <td>${fmt(s.time_ms)}</td>
                        <td>${s.moves ?? '—'}</td>
                        <td>${escapeHtml((s.finished_at || s.started_at || '').replace('T', ' '))}</td>
                    </tr>`).join('')}</tbody>
            </table>`;
    } catch (err) {
        body.innerHTML = '<p class="act-empty">No se pudo cargar el reporte: ' + escapeHtml(err.message) + '</p>';
    }
}

/* ════════════ CREAR ════════════ */
const fName = $('#f-name');
const fImage = $('#f-image');

$$('input[name="diffmode"]').forEach(r => r.addEventListener('change', () => {
    $('#fixed-grid-wrap').hidden = $('input[name="diffmode"]:checked').value !== 'fixed';
}));
$('#f-show-hint').addEventListener('change', e => { $('#hint-opacity-wrap').hidden = !e.target.checked; });
$('#f-hint-opacity').addEventListener('input', e => { $('#hint-opacity-val').textContent = e.target.value; });
fImage.addEventListener('change', () => {
    const file = fImage.files[0];
    const box = $('#create-preview');
    if (!file) { box.hidden = true; return; }
    $('#create-preview-img').src = URL.createObjectURL(file);
    box.hidden = false;
});

$('#create-form').addEventListener('submit', async e => {
    e.preventDefault();
    const msg = $('#create-msg');
    const btn = $('#btn-create');
    msg.textContent = ''; msg.className = 'act-msg';
    if (!fName.value.trim()) { msg.textContent = 'Indicá un nombre.'; msg.classList.add('err'); return; }
    if (!fImage.files[0])    { msg.textContent = 'Seleccioná una imagen.'; msg.classList.add('err'); return; }

    const fd = new FormData();
    fd.append('action', 'jigsaw_save_project');
    fd.append('csrf_token', CSRF);
    fd.append('name', fName.value.trim());
    fd.append('image', fImage.files[0]);
    fd.append('difficulty_mode', $('input[name="diffmode"]:checked').value);
    fd.append('fixed_grid', $('#f-fixed-grid').value);
    fd.append('play_mode', $('input[name="playmode"]:checked').value);
    fd.append('piece_shape', $('input[name="shape"]:checked').value);
    fd.append('show_hint', $('#f-show-hint').checked ? '1' : '0');
    fd.append('hint_opacity', $('#f-hint-opacity').value);

    btn.disabled = true; btn.textContent = 'Creando…';
    try {
        const data = await fetch(API + '?action=jigsaw_save_project', { method: 'POST', body: fd }).then(r => r.json());
        if (!data.ok) {
            const extra = Array.isArray(data.errors) && data.errors.length ? ' (' + data.errors.join(' ') + ')' : '';
            throw new Error((data.error || 'Error') + extra);
        }
        msg.textContent = '✓ Puzle creado. Publicalo para que aparezca en el juego.';
        msg.classList.add('ok');
        e.target.reset();
        $('#create-preview').hidden = true;
        $('#fixed-grid-wrap').hidden = true;
        $('#hint-opacity-wrap').hidden = true;
        setTimeout(() => go('manage'), 900);
    } catch (err) {
        msg.textContent = '✗ ' + err.message;
        msg.classList.add('err');
    } finally {
        btn.disabled = false; btn.textContent = 'Crear puzle';
    }
});

/* Inicio */
loadProjects();

})();
