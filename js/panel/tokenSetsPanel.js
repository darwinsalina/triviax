(function () {
'use strict';

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const BASE = document.body.dataset.base || '';
const API = BASE + '/api.php';
const CSRF = document.body.dataset.actCsrf || '';
const CATEGORIES = [
    ['male', 'Masculino'],
    ['female', 'Femenino'],
    ['neutral', 'Neutral'],
    ['object', 'Objeto'],
    ['animal', 'Animal'],
    ['vehicle', 'Vehiculo'],
    ['ship', 'Nave'],
    ['creature', 'Criatura'],
    ['symbol', 'Simbolo'],
    ['other', 'Otro']
];

let sets = [];
let currentSet = null;
let currentAssets = [];

function msg(text, kind = '') {
    const el = $('#tokens-message');
    el.textContent = text || '';
    el.className = 'tokens-message ' + kind;
}

async function api(action, payload = null, params = null) {
    const query = new URLSearchParams({ action });
    if (params) {
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') query.set(key, value);
        });
    }
    const options = payload === null
        ? { method: 'GET' }
        : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, payload))
        };
    const res = await fetch(API + '?' + query.toString(), options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false || data.ok === false) {
        throw new Error(data.error || data.message || 'Error de servidor');
    }
    return data;
}

function formPayload() {
    return {
        id: $('#set-id').value || null,
        title: $('#set-title').value.trim(),
        description: $('#set-description').value.trim(),
        activity_theme: $('#set-theme').value.trim(),
        token_type: $('#set-type').value,
        visual_style: $('#set-style').value.trim(),
        notes: $('#set-notes').value.trim(),
        status: $('#set-status').value
    };
}

function fillPrompts(set) {
    $('#prompt-main').value = set?.prompt_text || '';
    $('#prompt-negative').value = set?.negative_prompt_text || '';
    $('#prompt-cut').value = set?.cut_prompt_text || '';
}

function resetEditor() {
    currentSet = null;
    currentAssets = [];
    $('#set-id').value = '';
    $('#set-title').value = '';
    $('#set-description').value = '';
    $('#set-theme').value = '';
    $('#set-type').value = 'personajes humanos';
    $('#set-style').value = '';
    $('#set-notes').value = '';
    $('#set-status').value = 'draft';
    $('#editor-title').textContent = 'Crear coleccion';
    fillPrompts(null);
    $('#source-preview').innerHTML = '';
    renderAssets([]);
    msg('');
}

function renderSets() {
    const list = $('#token-sets-list');
    if (!sets.length) {
        list.innerHTML = '<p class="tokens-empty">Todavia no tienes colecciones. Crea la primera y pega el prompt en tu generador de imagenes.</p>';
        return;
    }
    list.innerHTML = sets.map((set) => `
        <button class="token-set-card ${currentSet && currentSet.id === set.id ? 'active' : ''}" type="button" data-id="${set.id}">
            <strong>${escapeHtml(set.title)}</strong>
            <span>${escapeHtml(set.activity_theme || 'Sin tema')}</span>
            <small>${set.status} · ${set.asset_count || 0} fichas</small>
        </button>
    `).join('');
    $$('.token-set-card', list).forEach((btn) => {
        btn.addEventListener('click', () => loadSet(btn.dataset.id));
    });
}

function renderAssets(assets) {
    currentAssets = assets || [];
    const grid = $('#tokens-assets-grid');
    if (!currentAssets.length) {
        grid.innerHTML = '<p class="tokens-empty">Cuando cortes la imagen madre apareceran aqui las 24 fichas.</p>';
        return;
    }
    grid.innerHTML = currentAssets.map((asset) => `
        <article class="token-asset-card" data-id="${asset.id}">
            <img src="${BASE}/${asset.thumb_path || asset.file_path}" alt="${escapeHtml(asset.label)}" loading="lazy">
            <input class="asset-label" value="${escapeAttr(asset.label)}" maxlength="120" aria-label="Etiqueta">
            <select class="asset-category" aria-label="Categoria">
                ${CATEGORIES.map(([value, label]) => `<option value="${value}" ${asset.category === value ? 'selected' : ''}>${label}</option>`).join('')}
            </select>
            <label class="asset-active"><input type="checkbox" ${asset.active ? 'checked' : ''}> Activa</label>
        </article>
    `).join('');
}

function fillEditor(set, assets) {
    currentSet = set;
    $('#set-id').value = set.id;
    $('#set-title').value = set.title || '';
    $('#set-description').value = set.description || '';
    $('#set-theme').value = set.activity_theme || '';
    $('#set-type').value = set.token_type || 'personajes humanos';
    $('#set-style').value = set.visual_style || '';
    $('#set-status').value = set.status || 'draft';
    $('#editor-title').textContent = 'Editar coleccion';
    fillPrompts(set);
    $('#source-preview').innerHTML = set.source_image_path
        ? `<img src="${BASE}/${set.source_image_path}" alt="Imagen madre de ${escapeAttr(set.title)}">`
        : '';
    renderAssets(assets || []);
    renderSets();
}

async function loadSets() {
    const data = await api('tokens_list');
    sets = data.sets || [];
    renderSets();
}

async function loadSet(id) {
    msg('Cargando coleccion...');
    const data = await api('tokens_get', null, { id });
    fillEditor(data.set, data.assets || []);
    msg('');
}

async function saveSet(e) {
    e.preventDefault();
    msg('Guardando coleccion...');
    const data = await api('tokens_save', formPayload());
    fillEditor(data.set, currentAssets);
    await loadSets();
    await loadSet(data.set.id);
    msg('Coleccion guardada.', 'ok');
}

async function uploadSource(e) {
    e.preventDefault();
    if (!currentSet) {
        msg('Guarda la coleccion antes de subir la imagen.', 'error');
        return;
    }
    const file = $('#source-image').files[0];
    if (!file) {
        msg('Selecciona una imagen madre.', 'error');
        return;
    }
    msg('Subiendo imagen...');
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('id', currentSet.id);
    fd.append('source_image', file);
    const res = await fetch(API + '?action=tokens_upload_source', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: fd
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) throw new Error(data.error || 'No se pudo subir la imagen');
    currentSet.source_image_path = data.source_image_path;
    $('#source-preview').innerHTML = `<img src="${BASE}/${data.source_image_path}" alt="Imagen madre">`;
    msg(`Imagen lista (${data.width} x ${data.height}). Revisa la grilla visualmente y corta fichas.`, 'ok');
}

async function sliceSource() {
    if (!currentSet) {
        msg('Guarda la coleccion antes de cortar.', 'error');
        return;
    }
    msg('Cortando 24 fichas...');
    const data = await api('tokens_slice', { id: currentSet.id });
    renderAssets(data.assets || []);
    await loadSets();
    msg('Corte completado. Revisa etiquetas y categorias.', 'ok');
}

async function saveAssets() {
    if (!currentSet) return;
    const assets = $$('.token-asset-card').map((card) => ({
        id: card.dataset.id,
        label: $('.asset-label', card).value.trim(),
        category: $('.asset-category', card).value,
        active: $('.asset-active input', card).checked
    }));
    msg('Guardando fichas...');
    const data = await api('tokens_assets_update', { id: currentSet.id, assets });
    renderAssets(data.assets || []);
    await loadSets();
    msg('Fichas actualizadas.', 'ok');
}

async function copyField(selector) {
    const value = $(selector).value || '';
    if (!value) return;
    await navigator.clipboard.writeText(value);
    msg('Texto copiado al portapapeles.', 'ok');
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

function bind() {
    $('#btn-new-set').addEventListener('click', resetEditor);
    $('#token-set-form').addEventListener('submit', saveSet);
    $('#token-upload-form').addEventListener('submit', uploadSource);
    $('#btn-slice').addEventListener('click', sliceSource);
    $('#btn-save-assets').addEventListener('click', saveAssets);
    $('#btn-copy-main').addEventListener('click', () => copyField('#prompt-main'));
    $('#btn-copy-negative').addEventListener('click', () => copyField('#prompt-negative'));
    $('#btn-copy-cut').addEventListener('click', () => copyField('#prompt-cut'));
}

bind();
resetEditor();
loadSets().catch((err) => msg(err.message, 'error'));
})();
