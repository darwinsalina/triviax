/* ═══════════════════════════════════════════════════════════════════
   TRIVIAX v7.0 — Panel docente "Acceso y evaluación".
   Configura la política transversal de cualquier modalidad y consulta
   las entregas. Endpoints acceso_* y listados por modalidad de api.php.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
'use strict';

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const API  = window.TRIVIAX_API  || '../api.php';
const CSRF = window.TRIVIAX_CSRF_TOKEN || '';

const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function apiGet(action, params) {
    let url = API + '?action=' + encodeURIComponent(action);
    if (params) url += '&' + new URLSearchParams(params).toString();
    return fetch(url, { headers: { 'X-CSRF-Token': CSRF } })
        .then((r) => r.json())
        .then((d) => { if (!d.ok && !d.success) throw new Error(d.message || d.error || 'Error de red'); return d; });
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
function setMsg(text, isError) {
    const n = $('#msg');
    n.textContent = text || '';
    n.style.color = isError ? '#f87171' : '';
}

/* Cómo listar actividades propias por modalidad: action + campo lista + id/título */
const LISTERS = {
    proyecto:           { action: 'list',                 list: 'projects',   id: 'id',  title: (p) => p.title || p.id },
    study_deck:         { action: 'study_list_decks',     list: 'decks',      id: 'id',  title: (p) => p.titulo },
    lotto_activity:     { action: 'lotto_list_activities',list: 'activities', id: 'id',  title: (p) => p.titulo },
    crossword_project:  { action: 'cw_list_projects',     list: 'projects',   id: 'id',  title: (p) => p.title || p.titulo },
    wordsearch_project: { action: 'ws_list_projects',     list: 'projects',   id: 'id',  title: (p) => p.title || p.titulo },
    jigsaw_project:     { action: 'jigsaw_list_projects', list: 'projects',   id: 'id',  title: (p) => p.titulo },
    etiquetar_project:  { action: 'etiquetar_list_projects', list: 'projects', id: 'id', title: (p) => p.titulo },
};

let gruposDocente = [];

async function loadActivities() {
    const tipo = $('#f-tipo').value;
    const sel = $('#f-ref');
    sel.innerHTML = '<option value="">Cargando…</option>';
    $('#policy-form').hidden = true;
    const lister = LISTERS[tipo];
    try {
        const d = await apiGet(lister.action);
        const items = d[lister.list] || (d.data && d.data[lister.list]) || [];
        sel.innerHTML = '<option value="">— Elige una actividad —</option>';
        items.forEach((it) => {
            const opt = document.createElement('option');
            opt.value = it[lister.id];
            opt.textContent = lister.title(it) || ('#' + it[lister.id]);
            sel.appendChild(opt);
        });
        if (!items.length) sel.innerHTML = '<option value="">No tienes actividades de esta modalidad</option>';
    } catch (err) {
        sel.innerHTML = '<option value="">Error al cargar</option>';
        setMsg(err.message, true);
    }
}

function toLocalInput(mysqlDt) {
    if (!mysqlDt) return '';
    return mysqlDt.replace(' ', 'T').slice(0, 16);
}

async function loadPolicy() {
    const tipo = $('#f-tipo').value;
    const ref = $('#f-ref').value;
    if (!ref) { $('#policy-form').hidden = true; return; }
    setMsg('Cargando política…');
    try {
        const [dPol, dGrupos] = await Promise.all([
            apiGet('acceso_get', { tipo, ref }),
            gruposDocente.length ? Promise.resolve(null) : apiGet('acceso_grupos_disponibles'),
        ]);
        if (dGrupos) gruposDocente = dGrupos.grupos || [];
        const p = dPol.politica;

        $('#p-estado').value = p.estado_publicacion || 'abierta';
        $('#p-visibilidad').value = p.visibilidad || 'publica';
        $('#p-abre').value = toLocalInput(p.abre_at);
        $('#p-cierra').value = toLocalInput(p.cierra_at);
        $('#p-login').checked = !!Number(p.requiere_login);
        $('#p-email').checked = !!Number(p.requiere_email_verificado);
        $('#p-validacion').checked = !!Number(p.requiere_validacion_docente);
        $('#p-evaluativa').checked = !!Number(p.evaluativa);
        $('#p-intentos').value = p.max_intentos || '';
        $('#p-feedback').value = p.feedback_policy || 'al_final';
        $('#p-dominios').value = (p.dominios || []).join('\n');
        $('#p-gen-codigo').checked = false;
        $('#p-quitar-codigo').checked = false;
        $('#codigo-estado').textContent = p.tiene_codigo ? 'Hay un código activo (oculto)' : 'Sin código';
        $('#codigo-box').hidden = true;
        $('#entregas-warning').hidden = !p.tiene_entregas;
        $('#version-info').textContent = p.version_actual
            ? `Versión congelada actual: v${p.version_actual.version_num} (${p.version_actual.created_at})`
            : 'Sin versión congelada. Se creará al publicar como evaluativa.';

        // Grupos habilitados
        const box = $('#p-grupos');
        box.innerHTML = '';
        const habilitados = new Set((p.grupos || []).map((g) => String(g.grupo_id)));
        if (!gruposDocente.length) {
            box.innerHTML = '<p class="act-hint">No tienes grupos. Créalos en <a href="grupos.php">Mis grupos</a>.</p>';
        }
        gruposDocente.forEach((g) => {
            const label = document.createElement('label');
            label.className = 'act-check';
            label.innerHTML = `<input type="checkbox" value="${g.id}" ${habilitados.has(String(g.id)) ? 'checked' : ''}>
                ${escapeHtml((g.nivel ? g.nivel + ' — ' : '') + g.nombre)} (${g.total} estudiantes)`;
            box.appendChild(label);
        });

        $('#entregas-box').hidden = true;
        $('#policy-form').hidden = false;
        setMsg('');
    } catch (err) {
        setMsg(err.message, true);
    }
}

async function savePolicy() {
    const tipo = $('#f-tipo').value;
    const ref = $('#f-ref').value;
    if (!ref) return;
    const grupos = Array.from($('#p-grupos').querySelectorAll('input:checked'))
        .map((i) => ({ grupo_id: Number(i.value), scope_type: 'grupo' }));
    const payload = {
        tipo, ref,
        estado_publicacion: $('#p-estado').value,
        visibilidad: $('#p-visibilidad').value,
        abre_at: $('#p-abre').value,
        cierra_at: $('#p-cierra').value,
        requiere_login: $('#p-login').checked ? 1 : 0,
        requiere_email_verificado: $('#p-email').checked ? 1 : 0,
        requiere_validacion_docente: $('#p-validacion').checked ? 1 : 0,
        evaluativa: $('#p-evaluativa').checked ? 1 : 0,
        max_intentos: $('#p-intentos').value ? Number($('#p-intentos').value) : 0,
        feedback_policy: $('#p-feedback').value,
        dominios: $('#p-dominios').value.split('\n').map((s) => s.trim()).filter(Boolean),
        grupos,
    };
    if ($('#p-gen-codigo').checked) payload.generar_codigo = 1;
    else if ($('#p-quitar-codigo').checked) payload.quitar_codigo = 1;

    setMsg('Guardando…');
    try {
        const d = await apiPostJson('acceso_save', payload);
        if (d.codigo) {
            $('#codigo-valor').textContent = d.codigo;
            $('#codigo-box').hidden = false;
        }
        setMsg(d.message || 'Política guardada.');
        loadPolicy();
        if (d.codigo) {
            $('#codigo-valor').textContent = d.codigo;
            $('#codigo-box').hidden = false;
        }
    } catch (err) {
        setMsg(err.message, true);
    }
}

async function loadEntregas() {
    const tipo = $('#f-tipo').value;
    const ref = $('#f-ref').value;
    if (!ref) return;
    try {
        const d = await apiGet('acceso_entregas', { tipo, ref });
        const rows = d.entregas || [];
        const body = $('#entregas-body');
        body.innerHTML = '';
        $('#entregas-empty').hidden = rows.length > 0;
        rows.forEach((r) => {
            const tr = document.createElement('tr');
            const nombre = [r.usuario_apellido, r.usuario_nombre].filter(Boolean).join(', ') || '—';
            tr.innerHTML = `
                <td>${escapeHtml(nombre)}</td>
                <td>${escapeHtml(r.alias || '—')}</td>
                <td>${escapeHtml(r.grupo_nombre || '—')}</td>
                <td>${r.puntaje != null ? escapeHtml(r.puntaje) + (r.max_puntaje != null ? ' / ' + escapeHtml(r.max_puntaje) : '') : '—'}</td>
                <td>${r.porcentaje != null ? escapeHtml(r.porcentaje) + '%' : '—'}</td>
                <td>${escapeHtml(r.estado)}</td>
                <td>${Number(r.evaluativa) ? 'Sí' : 'No'}</td>
                <td>${r.version_num ? 'v' + escapeHtml(r.version_num) : '—'}</td>
                <td>${escapeHtml(r.submitted_at || '—')}</td>`;
            body.appendChild(tr);
        });
        $('#btn-export-entregas').href = API + '?action=acceso_entregas&format=csv&tipo=' +
            encodeURIComponent(tipo) + '&ref=' + encodeURIComponent(ref);
        $('#entregas-box').hidden = false;
    } catch (err) {
        setMsg(err.message, true);
    }
}

/* ---------- Eventos ---------- */
$('#f-tipo').addEventListener('change', loadActivities);
$('#f-ref').addEventListener('change', loadPolicy);
$('#btn-save').addEventListener('click', savePolicy);
$('#btn-entregas').addEventListener('click', loadEntregas);
$('#p-gen-codigo').addEventListener('change', () => { if ($('#p-gen-codigo').checked) $('#p-quitar-codigo').checked = false; });
$('#p-quitar-codigo').addEventListener('change', () => { if ($('#p-quitar-codigo').checked) $('#p-gen-codigo').checked = false; });

/* Preselección por URL: acceso.php?tipo=X&ref=Y */
(async function init() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tipo') && LISTERS[params.get('tipo')]) $('#f-tipo').value = params.get('tipo');
    await loadActivities();
    if (params.get('ref')) {
        $('#f-ref').value = params.get('ref');
        loadPolicy();
    }
})();

})();
