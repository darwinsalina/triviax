/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX v7.0 — Panel docente "Mis grupos".
   CRUD de grupos, código de inscripción (hash, se muestra una vez),
   importación CSV con previsualización y alias sugeridos, validación
   de identidad de estudiantes y exportación. Endpoints grp_* de api.php.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const API  = window.TRIVIAX_API  || '../api.php';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';

const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function apiGet(action, params) {
    let url = API + '?action=' + encodeURIComponent(action);
    if (params) url += '&' + new URLSearchParams(params).toString();
    return fetch(url, { headers: { 'X-CSRF-Token': CSRF } })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.message || d.error || 'Error de red'); return d; });
}
function apiPostJson(action, payload) {
    return fetch(API + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(Object.assign({ csrf_token: CSRF }, payload || {})),
    })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.message || d.error || 'Error de red'); return d; });
}
function apiPostForm(action, formData) {
    formData.append('csrf_token', CSRF);
    return fetch(API + '?action=' + encodeURIComponent(action), { method: 'POST', body: formData })
        .then((r) => r.json())
        .then((d) => { if (!d.ok) throw new Error(d.message || d.error || 'Error de red'); return d; });
}

function setMsg(id, text, isError) {
    const n = $(id);
    if (!n) return;
    n.textContent = text || '';
    n.style.color = isError ? '#f87171' : '';
}

/* ---------- Estado ---------- */
let grupoActual = null;      // {id, nombre, nivel}
let editandoGrupo = null;    // grupo en edición (o null = crear)
let previewRows = [];        // filas de previsualización de importación
let previewFilename = null;

/* ---------- Navegación ---------- */
function go(screen) {
    document.querySelectorAll('.act-screen').forEach((s) => s.classList.toggle('active', s.dataset.screen === screen));
    window.scrollTo(0, 0);
    if (screen === 'manage') loadGroups();
    if (screen === 'create' && !editandoGrupo) {
        $('#create-title').textContent = 'Nuevo grupo';
        $('#f-nivel').value = '';
        $('#f-nombre').value = '';
        $('#f-descripcion').value = '';
        setMsg('#create-msg', '');
    }
    if (screen === 'import') {
        $('#import-title').textContent = 'Importar estudiantes — ' + (grupoActual ? grupoActual.nombre : '');
        $('#import-step-upload').hidden = false;
        $('#import-step-preview').hidden = true;
        setMsg('#import-msg', '');
    }
}
document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-go]');
    if (t) {
        if (t.dataset.go === 'create') editandoGrupo = null;
        go(t.dataset.go);
    }
});

/* ════════════════════════════════════════════════════════════════
   LISTA DE GRUPOS
   ════════════════════════════════════════════════════════════════ */
async function loadGroups() {
    const list = $('#group-list');
    list.innerHTML = '<p class="act-empty">Cargando…</p>';
    try {
        const data = await apiGet('grp_list');
        renderGroups(data.grupos || []);
    } catch (err) {
        list.innerHTML = '<p class="act-empty">No se pudo cargar: ' + escapeHtml(err.message) + '</p>';
    }
}

function renderGroups(grupos) {
    const list = $('#group-list');
    $('#list-empty').hidden = grupos.length > 0;
    list.innerHTML = '';
    grupos.forEach((g) => {
        const card = document.createElement('div');
        card.className = 'act-pcard';
        const nivel = g.nivel ? escapeHtml(g.nivel) + ' · ' : '';
        const pendientes = Number(g.pendientes) > 0
            ? ` · <span class="grp-badge pendiente">${g.pendientes} por validar</span>` : '';
        card.innerHTML = `
            <div class="act-pcard-body">
                <p class="act-pcard-title">${escapeHtml(g.nombre)}</p>
                <p class="act-pcard-meta">${nivel}${g.total_estudiantes} estudiantes${pendientes}</p>
                <span class="act-pcard-badge ${g.activo == 1 ? 'published' : ''}">${g.activo == 1 ? 'Activo' : 'Inactivo'}</span>
            </div>
            <div class="act-pcard-actions">
                <button type="button" class="act-btn primary sm" data-open="${g.id}">Abrir</button>
            </div>`;
        card.querySelector('[data-open]').addEventListener('click', () => openGroup(g));
        list.appendChild(card);
    });
}

/* ---------- Código docente personal ---------- */
$('#btn-codigo-docente').addEventListener('click', async () => {
    if (!confirm('¿Generar un nuevo código docente? El anterior dejará de ser válido.')) return;
    try {
        const d = await apiPostJson('grp_codigo_docente_regen', {});
        $('#codigo-docente-valor').textContent = d.codigo;
        $('#codigo-docente-box').hidden = false;
        setMsg('#manage-msg', '');
    } catch (err) {
        setMsg('#manage-msg', err.message, true);
    }
});

/* ════════════════════════════════════════════════════════════════
   CREAR / EDITAR
   ════════════════════════════════════════════════════════════════ */
$('#btn-save-group').addEventListener('click', async () => {
    const payload = {
        nombre: $('#f-nombre').value.trim(),
        nivel: $('#f-nivel').value.trim(),
        descripcion: $('#f-descripcion').value.trim(),
    };
    if (!payload.nombre) {
        setMsg('#create-msg', 'El nombre del grupo es obligatorio.', true);
        return;
    }
    try {
        if (editandoGrupo) {
            payload.grupo_id = editandoGrupo.id;
            await apiPostJson('grp_update', payload);
            grupoActual = Object.assign({}, grupoActual, { nombre: payload.nombre, nivel: payload.nivel });
            editandoGrupo = null;
            openGroup(grupoActual);
        } else {
            await apiPostJson('grp_create', payload);
            go('manage');
        }
    } catch (err) {
        setMsg('#create-msg', err.message, true);
    }
});

$('#btn-edit-group').addEventListener('click', () => {
    if (!grupoActual) return;
    editandoGrupo = grupoActual;
    $('#create-title').textContent = 'Editar grupo';
    $('#f-nivel').value = grupoActual.nivel || '';
    $('#f-nombre').value = grupoActual.nombre || '';
    $('#f-descripcion').value = grupoActual.descripcion || '';
    setMsg('#create-msg', '');
    go('create');
});

/* ════════════════════════════════════════════════════════════════
   DETALLE DE GRUPO
   ════════════════════════════════════════════════════════════════ */
function openGroup(g) {
    grupoActual = g;
    $('#detail-title').textContent = (g.nivel ? g.nivel + ' — ' : '') + g.nombre;
    $('#group-code-box').hidden = true;
    setMsg('#detail-msg', '');
    go('detail');
    loadStudents();
}

async function loadStudents() {
    const body = $('#students-body');
    body.innerHTML = '<tr><td colspan="7">Cargando…</td></tr>';
    try {
        const data = await apiGet('grp_students', { grupo_id: grupoActual.id });
        renderStudents(data.estudiantes || []);
    } catch (err) {
        body.innerHTML = '';
        setMsg('#detail-msg', 'No se pudo cargar: ' + err.message, true);
    }
}

function renderStudents(estudiantes) {
    const body = $('#students-body');
    $('#students-empty').hidden = estudiantes.length > 0;
    body.innerHTML = '';
    estudiantes.forEach((e) => {
        const tr = document.createElement('tr');
        const acciones = [];
        if (e.estado !== 'validado') acciones.push(`<button type="button" class="act-btn ghost sm" data-estado="validado">✔ Validar</button>`);
        if (e.estado !== 'rechazado') acciones.push(`<button type="button" class="act-btn ghost sm" data-estado="rechazado">✖ Rechazar</button>`);
        if (e.estado !== 'desactivado') acciones.push(`<button type="button" class="act-btn ghost sm" data-estado="desactivado">⏸ Desactivar</button>`);
        if (e.estado === 'desactivado' || e.estado === 'rechazado') acciones.push(`<button type="button" class="act-btn ghost sm" data-estado="pendiente">↩ Reactivar</button>`);
        tr.innerHTML = `
            <td>${escapeHtml(e.apellido)}</td>
            <td>${escapeHtml(e.nombre)}</td>
            <td>${escapeHtml(e.email || '—')}</td>
            <td><code>${escapeHtml(e.alias)}</code></td>
            <td>${escapeHtml(e.source)}</td>
            <td><span class="grp-badge ${escapeHtml(e.estado)}">${escapeHtml(e.estado)}</span></td>
            <td><div class="grp-row-actions">${acciones.join('')}</div></td>`;
        tr.querySelectorAll('[data-estado]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    await apiPostJson('grp_student_estado', { estudiante_id: e.id, estado: btn.dataset.estado });
                    loadStudents();
                } catch (err) {
                    setMsg('#detail-msg', err.message, true);
                }
            });
        });
        body.appendChild(tr);
    });
}

$('#btn-add-student').addEventListener('click', async () => {
    const nombre = $('#add-nombre').value.trim();
    const apellido = $('#add-apellido').value.trim();
    const email = $('#add-email').value.trim();
    if (!nombre || !apellido) {
        setMsg('#detail-msg', 'Nombre y apellido son obligatorios.', true);
        return;
    }
    try {
        await apiPostJson('grp_student_add', { grupo_id: grupoActual.id, nombre, apellido, email });
        $('#add-nombre').value = '';
        $('#add-apellido').value = '';
        $('#add-email').value = '';
        setMsg('#detail-msg', '');
        loadStudents();
    } catch (err) {
        setMsg('#detail-msg', err.message, true);
    }
});

$('#btn-regen-code').addEventListener('click', async () => {
    if (!confirm('¿Generar un nuevo código de inscripción? El anterior dejará de funcionar.')) return;
    try {
        const d = await apiPostJson('grp_regen_code', { grupo_id: grupoActual.id });
        $('#group-code-valor').textContent = d.codigo;
        $('#group-code-box').hidden = false;
    } catch (err) {
        setMsg('#detail-msg', err.message, true);
    }
});

$('#btn-export').addEventListener('click', () => {
    window.location.href = API + '?action=grp_export&grupo_id=' + encodeURIComponent(grupoActual.id);
});

$('#btn-import-back').addEventListener('click', () => {
    if (grupoActual) openGroup(grupoActual);
    else go('manage');
});

/* ════════════════════════════════════════════════════════════════
   IMPORTACIÓN CSV
   ════════════════════════════════════════════════════════════════ */
$('#btn-preview').addEventListener('click', async () => {
    const fileInput = $('#f-csv-file');
    const text = $('#f-csv-text').value.trim();
    const fd = new FormData();
    fd.append('grupo_id', grupoActual.id);
    if (fileInput.files && fileInput.files[0]) {
        fd.append('file', fileInput.files[0]);
    } else if (text) {
        fd.append('csv_text', text);
    } else {
        setMsg('#import-msg', 'Sube un archivo CSV o pega el contenido.', true);
        return;
    }
    setMsg('#import-msg', 'Analizando…');
    try {
        const d = await apiPostForm('grp_import_preview', fd);
        previewRows = d.preview || [];
        previewFilename = d.filename || null;
        renderPreview(previewRows, d.errores || []);
        $('#import-step-upload').hidden = true;
        $('#import-step-preview').hidden = false;
        setMsg('#import-msg', previewRows.length + ' filas detectadas.');
    } catch (err) {
        setMsg('#import-msg', err.message, true);
    }
});

function renderPreview(rows, errores) {
    const body = $('#preview-body');
    body.innerHTML = '';
    rows.forEach((r, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" data-check="${i}" ${r.duplicado ? '' : 'checked'}></td>
            <td>${escapeHtml(r.nombre)}</td>
            <td>${escapeHtml(r.apellido)}</td>
            <td>${escapeHtml(r.email || '—')}</td>
            <td><input type="text" class="grp-alias-input" data-alias="${i}" value="${escapeHtml(r.alias)}" maxlength="80"></td>
            <td>${r.duplicado ? '<span class="grp-badge dup">' + escapeHtml(r.motivo) + '</span>' : ''}</td>`;
        body.appendChild(tr);
    });
    const errBox = $('#preview-errors');
    errBox.innerHTML = errores.length
        ? '<strong>Filas con problemas (no se importan):</strong><br>' +
          errores.map((e) => `Línea ${e.line}: ${escapeHtml(e.error)}`).join('<br>')
        : '';
}

$('#btn-preview-back').addEventListener('click', () => {
    $('#import-step-upload').hidden = false;
    $('#import-step-preview').hidden = true;
});

$('#btn-confirm-import').addEventListener('click', async () => {
    const seleccionadas = [];
    previewRows.forEach((r, i) => {
        const check = $(`[data-check="${i}"]`);
        if (!check || !check.checked) return;
        const aliasInput = $(`[data-alias="${i}"]`);
        seleccionadas.push({
            nombre: r.nombre,
            apellido: r.apellido,
            email: r.email,
            alias: aliasInput ? aliasInput.value.trim() : r.alias,
        });
    });
    if (!seleccionadas.length) {
        setMsg('#import-msg', 'No hay filas seleccionadas para importar.', true);
        return;
    }
    setMsg('#import-msg', 'Importando…');
    try {
        const d = await apiPostJson('grp_import_confirm', {
            grupo_id: grupoActual.id,
            filename: previewFilename,
            rows: seleccionadas,
        });
        let msg = d.message || 'Importación completa.';
        if (d.errores && d.errores.length) {
            msg += ' Con ' + d.errores.length + ' errores.';
        }
        setMsg('#import-msg', msg, d.errores && d.errores.length > 0);
        openGroup(grupoActual);
    } catch (err) {
        setMsg('#import-msg', err.message, true);
    }
});

/* ---------- Init ---------- */
loadGroups();

})();
