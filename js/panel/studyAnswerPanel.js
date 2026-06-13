const root = document.querySelector('[data-study-admin]');
const csrfToken = root?.dataset.csrf || '';

const state = {
    decks: [],
    currentDeckId: null,
    currentEstado: null,
    currentSlug: '',
    lastProject: null
};

const els = {
    status: document.querySelector('[data-status]'),
    deckList: document.querySelector('[data-deck-list]'),
    editor: document.querySelector('[data-json-editor]'),
    fileInput: document.querySelector('[data-file-input]'),
    validation: document.querySelector('[data-validation]'),
    preview: document.querySelector('[data-preview]'),
    report: document.querySelector('[data-report]'),
    currentTitle: document.querySelector('[data-current-title]'),
    currentMeta: document.querySelector('[data-current-meta]'),
    openStudent: document.querySelector('[data-open-student]')
};

function el(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== '') {
        node.textContent = text;
    }
    return node;
}

function clear(node) {
    while (node && node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

function setStatus(message, tone = '') {
    els.status.textContent = message;
    els.status.dataset.tone = tone;
}

function apiUrl(action, params = {}) {
    const url = new URL('/triviax/api.php', window.location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, String(value));
        }
    });
    return url.toString();
}

async function api(action, { method = 'GET', body = null, params = {} } = {}) {
    const options = { method };
    if (body !== null) {
        options.headers = {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        };
        options.body = JSON.stringify(body);
    }
    const response = await fetch(apiUrl(action, params), options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false || data.ok === false) {
        const err = new Error(data.error || data.message || `Error HTTP ${response.status}`);
        err.data = data;
        throw err;
    }
    return data;
}

function parseEditorJson() {
    const raw = els.editor.value.trim();
    if (!raw) {
        throw new Error('Pega o importa un JSON de mazo antes de continuar.');
    }
    try {
        return JSON.parse(raw);
    } catch (err) {
        throw new Error(`JSON inválido: ${err.message}`);
    }
}

function formatJson(data) {
    return JSON.stringify(data, null, 2);
}

function renderList() {
    clear(els.deckList);
    if (!state.decks.length) {
        const empty = el('div', 'study-empty', 'Todavía no hay mazos. Cargá el ejemplo o importa un JSON.');
        els.deckList.append(empty);
        return;
    }

    state.decks.forEach((deck) => {
        const item = el('button', 'study-deck-item');
        item.type = 'button';
        if (Number(deck.id) === Number(state.currentDeckId)) {
            item.classList.add('is-active');
        }
        item.append(el('strong', '', deck.titulo || 'Mazo sin título'));
        item.append(el('span', '', deck.descripcion || 'Sin descripción'));
        const badges = el('div', 'study-badges');
        const status = el('span', 'study-badge', labelEstado(deck.estado));
        status.dataset.state = deck.estado;
        badges.append(status);
        badges.append(el('span', 'study-badge', `${deck.cards || 0} cartas`));
        badges.append(el('span', 'study-badge', `${deck.sessions || 0} sesiones`));
        item.append(badges);
        item.addEventListener('click', () => loadDeck(deck.id));
        els.deckList.append(item);
    });
}

function labelEstado(estado) {
    const labels = {
        draft: 'Borrador',
        published: 'Publicado',
        archived: 'Archivado'
    };
    return labels[estado] || estado || 'Sin estado';
}

function updateCurrentMeta(project = null) {
    const title = project?.studyAnswer?.title || project?.metadata?.title || 'Sin mazo seleccionado';
    const cards = Array.isArray(project?.studyAnswer?.cards) ? project.studyAnswer.cards.length : 0;
    els.currentTitle.textContent = title;
    els.currentMeta.textContent = state.currentDeckId
        ? `ID ${state.currentDeckId} · ${labelEstado(state.currentEstado)} · ${cards} cartas`
        : `${cards} cartas · borrador nuevo`;

    if (state.currentDeckId && state.currentEstado === 'published') {
        els.openStudent.href = `/triviax/study.php?deck_id=${encodeURIComponent(state.currentDeckId)}`;
        els.openStudent.classList.remove('study-hidden');
    } else {
        els.openStudent.classList.add('study-hidden');
    }
}

function renderMessages(target, title, messages, tone = '') {
    clear(target);
    const box = el('div', 'study-result-box');
    box.append(el('strong', '', title));
    if (!messages.length) {
        box.append(el('p', '', 'No hay observaciones.'));
    } else {
        const list = el('ul', 'study-result-list');
        messages.forEach((message) => list.append(el('li', '', String(message))));
        box.append(list);
    }
    if (tone) {
        box.dataset.tone = tone;
    }
    target.append(box);
}

function createBlankProject() {
    const today = new Date().toISOString().slice(0, 10);
    return {
        metadata: {
            title: 'Nuevo mazo de estudio',
            author: '',
            nivel: '',
            obs: '',
            date: today,
            background: 'fondo.jpg',
            mode: 'study_answer'
        },
        board: {
            type: 'study_deck'
        },
        studyAnswer: {
            version: '1.0',
            title: 'Nuevo mazo de estudio',
            description: 'Descripción breve del mazo.',
            source: {
                type: 'manual',
                documents: []
            },
            settings: {
                cardCount: 3,
                orderMode: 'progressive',
                allowedQuestionTypes: ['multiple_choice', 'true_false', 'fill_blank'],
                repeatPolicy: {
                    wrong: 'after_n_cards',
                    afterCards: 2,
                    maxAttempts: 3
                },
                mastery: {
                    mode: 'leitner',
                    masteryBox: 2,
                    requiredCorrect: 2,
                    resetOnWrong: true
                },
                showExplanation: true,
                allowHints: false,
                timeLimit: 45
            },
            cards: [
                {
                    id: 'sa001',
                    type: 'study_card',
                    title: 'Primera carta',
                    order: 1,
                    learningObjective: 'Comprender una idea clave.',
                    studyText: 'Escribe aquí un texto breve de estudio. Debe ser claro, concreto y suficiente para que el estudiante pueda responder después.',
                    keyIdea: 'Idea central de la carta.',
                    vocabulary: [],
                    assessment: {
                        type: 'multiple_choice',
                        prompt: '¿Cuál es la idea principal?',
                        options: ['Opción correcta', 'Distractor 1', 'Distractor 2', 'Distractor 3'],
                        answer: 'Opción correcta'
                    },
                    feedback: {
                        correct: 'Correcto.',
                        incorrect: 'Revisa la idea principal.',
                        explanation: 'Explicación breve que ayuda a aprender del error.'
                    },
                    difficulty: 'baja',
                    cognitiveLevel: 'comprender',
                    tags: [],
                    estimatedReadTime: 25,
                    estimatedAnswerTime: 20,
                    points: 100
                }
            ]
        }
    };
}

function projectFromPreview(preview) {
    return {
        metadata: {
            title: preview.deck.titulo,
            author: '',
            nivel: '',
            obs: preview.deck.descripcion || '',
            date: new Date().toISOString().slice(0, 10),
            background: 'fondo.jpg',
            mode: 'study_answer'
        },
        board: {
            type: 'study_deck'
        },
        studyAnswer: {
            version: '1.0',
            title: preview.deck.titulo,
            description: preview.deck.descripcion || '',
            source: {
                type: 'manual',
                documents: []
            },
            settings: preview.deck.settings || {},
            cards: (preview.cards || []).map((card) => ({
                id: card.card_key,
                type: 'study_card',
                title: card.title,
                order: card.order,
                learningObjective: card.learningObjective,
                studyText: card.studyText,
                keyIdea: card.keyIdea,
                vocabulary: card.vocabulary || [],
                assessment: card.assessment || {},
                feedback: card.feedback || {},
                difficulty: card.difficulty,
                cognitiveLevel: card.cognitiveLevel,
                tags: card.tags || [],
                points: card.points
            }))
        }
    };
}

async function refreshDecks() {
    const data = await api('study_list_decks');
    state.decks = data.decks || [];
    renderList();
}

async function loadDeck(deckId) {
    try {
        setStatus('Cargando mazo...');
        const data = await api('study_deck_preview', { params: { deck_id: deckId } });
        state.currentDeckId = Number(deckId);
        state.currentEstado = data.deck?.estado || null;
        state.currentSlug = data.deck?.slug || '';
        const project = projectFromPreview(data);
        state.lastProject = project;
        els.editor.value = formatJson(project);
        updateCurrentMeta(project);
        renderPreview(data);
        clear(els.report);
        clear(els.validation);
        renderList();
        setStatus('Mazo cargado para edición.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el mazo.', 'error');
    }
}

async function loadDemo() {
    try {
        setStatus('Cargando ejemplo...');
        const response = await fetch('/triviax/docs/fixtures/study_answer_demo.json', { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`No se pudo cargar el ejemplo: HTTP ${response.status}`);
        }
        const data = await response.json();
        state.currentDeckId = null;
        state.currentEstado = 'draft';
        state.currentSlug = '';
        state.lastProject = data;
        els.editor.value = formatJson(data);
        updateCurrentMeta(data);
        clear(els.preview);
        clear(els.report);
        clear(els.validation);
        renderList();
        setStatus('Ejemplo cargado. Puedes validarlo, editarlo y guardarlo.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el ejemplo.', 'error');
    }
}

async function validateDeck() {
    try {
        const project = parseEditorJson();
        setStatus('Validando mazo...');
        const data = await api('study_validate_deck', {
            method: 'POST',
            body: { project }
        });
        state.lastProject = project;
        updateCurrentMeta(project);
        const messages = [
            ...(data.errors || []).map((m) => `Error: ${m}`),
            ...(data.warnings || []).map((m) => `Aviso: ${m}`)
        ];
        renderMessages(els.validation, data.ok ? 'Mazo válido' : 'El mazo tiene errores', messages, data.ok ? 'ok' : 'error');
        setStatus(data.message || 'Validación completada.', data.ok ? 'ok' : 'warn');
        return data.ok === true;
    } catch (err) {
        const messages = err.data
            ? [
                ...(err.data.errors || []).map((m) => `Error: ${m}`),
                ...(err.data.warnings || []).map((m) => `Aviso: ${m}`)
            ]
            : [err.message];
        renderMessages(els.validation, 'No se pudo validar', messages.length ? messages : [err.message], 'error');
        setStatus(err.message || 'No se pudo validar.', 'error');
        return false;
    }
}

async function saveDeck() {
    try {
        const project = parseEditorJson();
        setStatus('Guardando mazo...');
        const data = await api('study_save_deck', {
            method: 'POST',
            body: {
                deck_id: state.currentDeckId,
                project
            }
        });
        const wasExisting = Boolean(state.currentDeckId);
        const previousEstado = state.currentEstado;
        state.currentDeckId = Number(data.deck_id);
        state.currentEstado = wasExisting ? (previousEstado || 'draft') : 'draft';
        state.currentSlug = data.slug || '';
        state.lastProject = project;
        updateCurrentMeta(project);
        renderMessages(els.validation, 'Mazo guardado', [
            `ID: ${data.deck_id}`,
            `Slug: ${data.slug}`,
            `Cartas: ${data.cards}`,
            ...((data.warnings || []).map((m) => `Aviso: ${m}`))
        ], 'ok');
        await refreshDecks();
        setStatus('Mazo guardado como borrador.', 'ok');
        return true;
    } catch (err) {
        renderMessages(els.validation, 'No se pudo guardar', [err.message], 'error');
        setStatus(err.message || 'No se pudo guardar.', 'error');
        return false;
    }
}

async function setDeckEstado(estado) {
    try {
        if (!state.currentDeckId) {
            const saved = await saveDeck();
            if (!saved) {
                return;
            }
        }
        setStatus(estado === 'published' ? 'Publicando mazo...' : 'Actualizando estado...');
        const data = await api('study_publish_deck', {
            method: 'POST',
            body: {
                deck_id: state.currentDeckId,
                estado
            }
        });
        state.currentEstado = data.estado || estado;
        updateCurrentMeta(state.lastProject || null);
        await refreshDecks();
        setStatus(`Estado actualizado: ${labelEstado(state.currentEstado)}.`, 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo actualizar el estado.', 'error');
    }
}

async function previewDeck() {
    try {
        if (!state.currentDeckId) {
            setStatus('Guardá el mazo antes de previsualizarlo.', 'warn');
            return;
        }
        setStatus('Cargando previsualización...');
        const data = await api('study_deck_preview', { params: { deck_id: state.currentDeckId } });
        renderPreview(data);
        setStatus('Previsualización cargada.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo previsualizar.', 'error');
    }
}

function renderPreview(data) {
    clear(els.preview);
    const title = el('div', 'study-result-box');
    title.append(el('strong', '', 'Previsualización docente'));
    title.append(el('p', '', `${data.cards?.length || 0} cartas. Esta vista muestra respuestas porque es solo para el docente dueño.`));
    els.preview.append(title);

    (data.cards || []).forEach((card) => {
        const box = el('article', 'study-preview-card');
        box.append(el('strong', '', `${card.order}. ${card.title}`));
        box.append(el('p', '', card.studyText || ''));
        box.append(el('p', '', `Consigna: ${card.assessment?.prompt || ''}`));
        const answer = card.assessment?.answer;
        if (answer !== undefined) {
            box.append(el('p', '', `Respuesta: ${Array.isArray(answer) ? answer.join(', ') : String(answer)}`));
        }
        box.append(el('p', '', `Tipo: ${card.assessment?.type || 'sin tipo'} · Puntos: ${card.points || 0}`));
        els.preview.append(box);
    });
}

async function reportDeck() {
    try {
        if (!state.currentDeckId) {
            setStatus('Selecciona un mazo guardado para ver reportes.', 'warn');
            return;
        }
        setStatus('Cargando reporte...');
        const data = await api('study_deck_report', { params: { deck_id: state.currentDeckId } });
        renderReport(data);
        setStatus('Reporte cargado.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el reporte.', 'error');
    }
}

function renderReport(data) {
    clear(els.report);
    const summary = el('div', 'study-result-box');
    summary.append(el('strong', '', 'Reporte del mazo'));
    summary.append(el('p', '', `${data.sessions?.length || 0} sesiones registradas · ${data.cards?.length || 0} cartas analizadas`));
    els.report.append(summary);

    const table = el('table', 'study-report-table');
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    ['Carta', 'Intentos', 'Correctas', 'Fallos'].forEach((label) => headRow.append(el('th', '', label)));
    thead.append(headRow);
    table.append(thead);
    const tbody = document.createElement('tbody');
    (data.cards || []).forEach((card) => {
        const row = document.createElement('tr');
        row.append(el('td', '', card.titulo || card.card_key || 'Carta'));
        row.append(el('td', '', String(card.attempts || 0)));
        row.append(el('td', '', String(card.correct || 0)));
        row.append(el('td', '', String(card.wrong || 0)));
        tbody.append(row);
    });
    table.append(tbody);
    els.report.append(table);
}

function handleImportFile() {
    els.fileInput.click();
}

function setupEvents() {
    document.querySelector('[data-action="new"]').addEventListener('click', () => {
        const project = createBlankProject();
        state.currentDeckId = null;
        state.currentEstado = 'draft';
        state.currentSlug = '';
        state.lastProject = project;
        els.editor.value = formatJson(project);
        updateCurrentMeta(project);
        clear(els.validation);
        clear(els.preview);
        clear(els.report);
        renderList();
        setStatus('Borrador nuevo creado.', 'ok');
    });
    document.querySelector('[data-action="load-demo"]').addEventListener('click', loadDemo);
    document.querySelector('[data-action="import-file"]').addEventListener('click', handleImportFile);
    document.querySelector('[data-action="validate"]').addEventListener('click', validateDeck);
    document.querySelector('[data-action="save"]').addEventListener('click', saveDeck);
    document.querySelector('[data-action="publish"]').addEventListener('click', () => setDeckEstado('published'));
    document.querySelector('[data-action="archive"]').addEventListener('click', () => setDeckEstado('archived'));
    document.querySelector('[data-action="preview"]').addEventListener('click', previewDeck);
    document.querySelector('[data-action="report"]').addEventListener('click', reportDeck);

    els.fileInput.addEventListener('change', async () => {
        const file = els.fileInput.files?.[0];
        if (!file) {
            return;
        }
        try {
            const text = await file.text();
            const data = JSON.parse(text);
            state.currentDeckId = null;
            state.currentEstado = 'draft';
            state.currentSlug = '';
            state.lastProject = data;
            els.editor.value = formatJson(data);
            updateCurrentMeta(data);
            clear(els.validation);
            clear(els.preview);
            clear(els.report);
            renderList();
            setStatus(`Archivo importado: ${file.name}`, 'ok');
        } catch (err) {
            setStatus(`No se pudo importar el archivo: ${err.message}`, 'error');
        } finally {
            els.fileInput.value = '';
        }
    });
}

/* ============================================================
 * Asistente de creación de mazos (wizard)
 * Dos caminos: con IA (prompt + pegar JSON) o manual (formulario
 * carta por carta). Ambos terminan guardando y publicando el mazo
 * con los mismos endpoints study_* del editor avanzado.
 * ============================================================ */

const WIZ_STEP_LABELS = {
    tema: 'Tema',
    cantidad: 'Cantidad',
    modalidad: 'Modalidad',
    metodo: 'Método',
    'ia-prompt': 'Prompt de IA',
    'ia-json': 'Pegar respuesta',
    'manual-cartas': 'Crear cartas',
    fin: 'Publicado'
};

const WIZ_COMMON_FLOW = ['tema', 'cantidad', 'modalidad', 'metodo'];
const WIZ_FLOWS = {
    ia: [...WIZ_COMMON_FLOW, 'ia-prompt', 'ia-json', 'fin'],
    manual: [...WIZ_COMMON_FLOW, 'manual-cartas', 'fin']
};

const WIZ_MODALIDAD_TYPES = {
    multiple_choice: ['multiple_choice'],
    true_false: ['true_false'],
    fill_blank: ['fill_blank'],
    short_answer: ['short_answer'],
    mixtas: ['multiple_choice', 'true_false', 'fill_blank', 'short_answer']
};

const WIZ_MODALIDAD_LABELS = {
    multiple_choice: 'Opción múltiple',
    true_false: 'Verdadero o falso',
    fill_blank: 'Completar un espacio',
    short_answer: 'Respuesta breve',
    mixtas: 'Mixtas'
};

const WIZ_TYPE_LABELS = {
    multiple_choice: 'Opción múltiple',
    true_false: 'V/F',
    fill_blank: 'Completar',
    short_answer: 'Resp. breve'
};

const wizard = {
    open: false,
    step: 'tema',
    metodo: null,
    titulo: '',
    nivel: '',
    tema: '',
    cantidad: 10,
    modalidad: 'multiple_choice',
    cards: [],
    deckId: null
};

const wiz = {
    root: document.querySelector('[data-wizard]'),
    steps: document.querySelector('[data-wizard-steps]'),
    panels: Array.from(document.querySelectorAll('[data-wizard-step]')),
    back: document.querySelector('[data-wizard-back]'),
    next: document.querySelector('[data-wizard-next]'),
    cancel: document.querySelector('[data-wizard-cancel]'),
    titulo: document.querySelector('[data-wiz-titulo]'),
    nivel: document.querySelector('[data-wiz-nivel]'),
    tema: document.querySelector('[data-wiz-tema]'),
    cantidad: document.querySelector('[data-wiz-cantidad]'),
    modalidad: document.querySelector('[data-wiz-modalidad]'),
    prompt: document.querySelector('[data-wiz-prompt]'),
    copyPrompt: document.querySelector('[data-wiz-copy-prompt]'),
    json: document.querySelector('[data-wiz-json]'),
    jsonFeedback: document.querySelector('[data-wiz-json-feedback]'),
    generate: document.querySelector('[data-wiz-generate]'),
    manualTitle: document.querySelector('[data-manual-title]'),
    manualProgress: document.querySelector('[data-manual-progress]'),
    manualList: document.querySelector('[data-manual-list]'),
    manualTypeRow: document.querySelector('[data-manual-type-row]'),
    manualFeedback: document.querySelector('[data-manual-feedback]'),
    cardType: document.querySelector('[data-card-type]'),
    cardTitle: document.querySelector('[data-card-title]'),
    cardStudy: document.querySelector('[data-card-study]'),
    cardPrompt: document.querySelector('[data-card-prompt]'),
    cardExplanation: document.querySelector('[data-card-explanation]'),
    mcCorrect: document.querySelector('[data-mc-correct]'),
    mcWrong: [
        document.querySelector('[data-mc-wrong1]'),
        document.querySelector('[data-mc-wrong2]'),
        document.querySelector('[data-mc-wrong3]')
    ],
    textAnswers: document.querySelector('[data-text-answers]'),
    addCard: document.querySelector('[data-wiz-add-card]'),
    finishManual: document.querySelector('[data-wiz-finish-manual]'),
    finalSummary: document.querySelector('[data-final-summary]'),
    finalOpen: document.querySelector('[data-final-open]'),
    finalEdit: document.querySelector('[data-final-edit]')
};

function wizFlow() {
    return wizard.metodo ? WIZ_FLOWS[wizard.metodo] : WIZ_COMMON_FLOW;
}

function wizRenderSteps() {
    clear(wiz.steps);
    const flow = wizard.metodo ? WIZ_FLOWS[wizard.metodo] : [...WIZ_COMMON_FLOW, 'fin'];
    const currentIndex = flow.indexOf(wizard.step);
    flow.forEach((stepName, index) => {
        const item = el('li', '', `${index + 1}. ${WIZ_STEP_LABELS[stepName]}`);
        if (stepName === wizard.step) {
            item.classList.add('is-current');
        } else if (currentIndex >= 0 && index < currentIndex) {
            item.classList.add('is-done');
        }
        wiz.steps.append(item);
    });
}

function wizShowStep(stepName) {
    wizard.step = stepName;
    wiz.panels.forEach((panel) => {
        panel.classList.toggle('study-hidden', panel.dataset.wizardStep !== stepName);
    });
    const flow = wizFlow();
    const index = flow.indexOf(stepName);
    wiz.back.classList.toggle('study-hidden', index <= 0 || stepName === 'fin');
    const nextVisible = !['metodo', 'ia-json', 'manual-cartas', 'fin'].includes(stepName);
    wiz.next.classList.toggle('study-hidden', !nextVisible);
    if (stepName === 'ia-prompt') {
        wiz.prompt.value = wizBuildPrompt();
    }
    if (stepName === 'manual-cartas') {
        wizRenderManual();
    }
    wizRenderSteps();
}

function wizOpen() {
    wizard.open = true;
    wizard.metodo = null;
    wizard.cards = [];
    wizard.deckId = null;
    wiz.json.value = '';
    clear(wiz.jsonFeedback);
    clear(wiz.manualFeedback);
    document.querySelectorAll('[data-wiz-metodo]').forEach((btn) => btn.classList.remove('is-selected'));
    wiz.root.classList.remove('study-hidden');
    wizShowStep('tema');
    wiz.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setStatus('Asistente iniciado. Completa los pasos para crear tu actividad.', 'ok');
}

function wizClose() {
    wizard.open = false;
    wiz.root.classList.add('study-hidden');
}

function wizCollectCommon() {
    wizard.titulo = wiz.titulo.value.trim();
    wizard.nivel = wiz.nivel.value.trim();
    wizard.tema = wiz.tema.value.trim();
    wizard.cantidad = Math.round(Number(wiz.cantidad.value) || 0);
    wizard.modalidad = wiz.modalidad.value;
}

function wizValidateStep(stepName) {
    wizCollectCommon();
    if (stepName === 'tema') {
        if (!wizard.titulo) {
            setStatus('Escribe un título para la actividad.', 'warn');
            wiz.titulo.focus();
            return false;
        }
        if (!wizard.tema) {
            setStatus('Describí brevemente el tema a evaluar.', 'warn');
            wiz.tema.focus();
            return false;
        }
    }
    if (stepName === 'cantidad') {
        if (!Number.isFinite(wizard.cantidad) || wizard.cantidad < 10 || wizard.cantidad > 20) {
            setStatus('La cantidad de cartas debe estar entre 10 y 20.', 'warn');
            wiz.cantidad.focus();
            return false;
        }
    }
    return true;
}

function wizNext() {
    if (!wizValidateStep(wizard.step)) {
        return;
    }
    const flow = wizFlow();
    const index = flow.indexOf(wizard.step);
    if (index >= 0 && index < flow.length - 1) {
        wizShowStep(flow[index + 1]);
    }
}

function wizBack() {
    const flow = wizFlow();
    const index = flow.indexOf(wizard.step);
    if (index > 0) {
        wizShowStep(flow[index - 1]);
    }
}

/* ---------- Camino IA: generación del prompt ---------- */

function wizPromptTypeRules(types) {
    const rules = [];
    if (types.includes('multiple_choice')) {
        rules.push('- "multiple_choice": "assessment" debe tener "options" (lista de 3 o 4 textos) y "answer" (el texto EXACTO de la opción correcta, que debe estar incluido en "options"). Una sola respuesta correcta.');
    }
    if (types.includes('true_false')) {
        rules.push('- "true_false": el "prompt" debe ser una afirmación y "answer" debe ser true o false (booleano JSON, sin comillas).');
    }
    if (types.includes('fill_blank')) {
        rules.push('- "fill_blank": el "prompt" debe contener un espacio a completar marcado con ____ y "answer" debe ser una lista con las respuestas aceptadas (por ejemplo ["núcleo", "el núcleo"]).');
    }
    if (types.includes('short_answer')) {
        rules.push('- "short_answer": pregunta de respuesta breve; "answer" debe ser una lista con todas las variantes aceptadas de la respuesta.');
    }
    return rules.join('\n');
}

function wizExampleAssessment(types) {
    if (types.includes('multiple_choice')) {
        return `{
        "type": "multiple_choice",
        "prompt": "¿Qué ocurre con el contenido de la RAM al apagar la computadora?",
        "options": ["Se pierde", "Se guarda para siempre", "Se imprime"],
        "answer": "Se pierde"
      }`;
    }
    if (types.includes('true_false')) {
        return `{
        "type": "true_false",
        "prompt": "La memoria RAM conserva su contenido al apagar la computadora.",
        "answer": false
      }`;
    }
    return `{
        "type": "${types[0]}",
        "prompt": "La memoria que pierde su contenido al apagar la computadora se llama ____.",
        "answer": ["RAM", "memoria RAM"]
      }`;
}

function wizBuildPrompt() {
    wizCollectCommon();
    const types = WIZ_MODALIDAD_TYPES[wizard.modalidad];
    const typesJson = JSON.stringify(types);
    const count = wizard.cantidad;
    const today = new Date().toISOString().slice(0, 10);
    const mixNote = wizard.modalidad === 'mixtas'
        ? `Distribuí los tipos de pregunta de forma variada entre las ${count} cartas, usando los tipos permitidos.`
        : `Todas las cartas deben usar el tipo de pregunta "${types[0]}".`;

    return `Actuá como un redactor de contenido educativo especializado en actividades de estudio para estudiantes de nivel escolar.

A partir del documento de estudio que te voy a dar al final de este mensaje, genera un mazo de ${count} cartas de estudio sobre el tema: "${wizard.tema}".

Cada carta tiene dos partes: primero un texto breve de estudio (3 a 6 líneas, máximo 900 caracteres) que enseña UNA sola idea, y después una pregunta para evaluar esa misma idea.

Tu respuesta debe ser ÚNICAMENTE un objeto JSON válido, sin ningún texto antes ni después, sin comentarios y sin bloques de código markdown. Usa exactamente esta estructura:

{
  "metadata": {
    "title": ${JSON.stringify(wizard.titulo)},
    "author": "",
    "nivel": ${JSON.stringify(wizard.nivel)},
    "obs": "",
    "date": "${today}",
    "background": "fondo.jpg",
    "mode": "study_answer"
  },
  "board": { "type": "study_deck" },
  "studyAnswer": {
    "version": "1.0",
    "title": ${JSON.stringify(wizard.titulo)},
    "description": ${JSON.stringify(wizard.tema)},
    "source": { "type": "manual", "documents": [] },
    "settings": {
      "cardCount": ${count},
      "orderMode": "progressive",
      "allowedQuestionTypes": ${typesJson},
      "repeatPolicy": { "wrong": "after_n_cards", "afterCards": 2, "maxAttempts": 3 },
      "mastery": { "mode": "leitner", "masteryBox": 2, "requiredCorrect": 2, "resetOnWrong": true },
      "showExplanation": true,
      "allowHints": false,
      "timeLimit": 45
    },
    "cards": [ ... exactamente ${count} cartas ... ]
  }
}

Cada elemento de "cards" debe tener esta forma (este es un EJEMPLO, reemplazá el contenido):

{
  "id": "sa001",
  "type": "study_card",
  "title": "Título breve de la carta",
  "order": 1,
  "learningObjective": "Qué debe comprender el estudiante.",
  "studyText": "Texto breve de estudio de 3 a 6 líneas que enseña una sola idea, suficiente para responder la pregunta.",
  "keyIdea": "La idea central en una frase.",
  "vocabulary": [],
  "assessment": ${wizExampleAssessment(types)},
  "feedback": {
    "correct": "Mensaje breve si responde bien.",
    "incorrect": "Mensaje breve si responde mal.",
    "explanation": "Explicación que ayuda a aprender del error."
  },
  "difficulty": "baja",
  "cognitiveLevel": "comprender",
  "tags": [],
  "estimatedReadTime": 25,
  "estimatedAnswerTime": 20,
  "points": 100
}

Reglas obligatorias:
1. Genera exactamente ${count} cartas, con "id" consecutivos ("sa001", "sa002", ...) y "order" consecutivo desde 1, sin repetir.
2. ${mixNote}
3. Los únicos valores permitidos de "assessment.type" son: ${typesJson}.
4. Formato de "assessment" según el tipo:
${wizPromptTypeRules(types)}
5. "studyText" nunca puede superar los 900 caracteres y debe permitir responder la pregunta solo con leerlo.
6. "difficulty" solo puede ser "baja", "media" o "alta". "cognitiveLevel" solo puede ser "recordar", "comprender", "aplicar" o "analizar".
7. Todas las cartas deben tener "feedback" con "correct", "incorrect" y "explanation".
8. Basate estrictamente en el documento de estudio provisto; no inventes contenido que no esté en él.
9. Recuerda: tu respuesta es SOLO el JSON, empezando con { y terminando con }.

Aquí está el documento de estudio:`;
}

async function wizCopyPrompt() {
    try {
        wiz.prompt.value = wizBuildPrompt();
        await navigator.clipboard.writeText(wiz.prompt.value);
        setStatus('Prompt copiado al portapapeles. Pégalo en tu chatbot de IA.', 'ok');
    } catch (err) {
        wiz.prompt.select();
        document.execCommand('copy');
        setStatus('Prompt seleccionado y copiado.', 'ok');
    }
}

/* ---------- Camino IA: pegar y procesar el JSON ---------- */

function wizCleanPastedJson(raw) {
    let text = String(raw || '').trim();
    text = text.replace(/^```(?:json)?/i, '').replace(/```$/, '').trim();
    const start = text.indexOf('{');
    const end = text.lastIndexOf('}');
    if (start === -1 || end === -1 || end <= start) {
        throw new Error('No se encontró un bloque JSON en el texto pegado. Copia la respuesta completa de la IA, que empieza con { y termina con }.');
    }
    return text.slice(start, end + 1);
}

function wizNormalizeProject(parsed) {
    wizCollectCommon();
    const types = WIZ_MODALIDAD_TYPES[wizard.modalidad];
    const blank = createBlankProject();

    let project = parsed;
    if (!parsed.studyAnswer && Array.isArray(parsed.cards)) {
        // La IA devolvió solo la lista de cartas: la envolvemos nosotros.
        project = blank;
        project.studyAnswer.cards = parsed.cards;
    }

    project.metadata = Object.assign({}, blank.metadata, project.metadata || {}, { mode: 'study_answer' });
    project.board = { type: 'study_deck' };
    project.studyAnswer = Object.assign({}, blank.studyAnswer, project.studyAnswer || {});
    project.studyAnswer.settings = Object.assign({}, blank.studyAnswer.settings, project.studyAnswer.settings || {});

    if (!project.metadata.title) project.metadata.title = wizard.titulo;
    if (!project.metadata.nivel) project.metadata.nivel = wizard.nivel;
    if (!project.studyAnswer.title || project.studyAnswer.title === blank.studyAnswer.title) {
        project.studyAnswer.title = wizard.titulo;
    }
    if (!project.studyAnswer.description || project.studyAnswer.description === blank.studyAnswer.description) {
        project.studyAnswer.description = wizard.tema;
    }
    project.studyAnswer.settings.allowedQuestionTypes = types;
    project.studyAnswer.settings.cardCount = Array.isArray(project.studyAnswer.cards)
        ? project.studyAnswer.cards.length
        : 0;
    return project;
}

async function wizPublishProject(project, feedbackTarget) {
    const validation = await api('study_validate_deck', { method: 'POST', body: { project } });
    if (validation.ok !== true) {
        const messages = [
            ...(validation.errors || []).map((m) => `Error: ${m}`),
            ...(validation.warnings || []).map((m) => `Aviso: ${m}`)
        ];
        renderMessages(feedbackTarget, 'El mazo tiene errores y no se puede publicar', messages, 'error');
        throw new Error('El contenido tiene errores. Revisa los detalles más abajo.');
    }

    const saved = await api('study_save_deck', { method: 'POST', body: { deck_id: null, project } });
    const deckId = Number(saved.deck_id);
    await api('study_publish_deck', { method: 'POST', body: { deck_id: deckId, estado: 'published' } });

    wizard.deckId = deckId;
    state.currentDeckId = deckId;
    state.currentEstado = 'published';
    state.currentSlug = saved.slug || '';
    state.lastProject = project;
    els.editor.value = formatJson(project);
    updateCurrentMeta(project);
    await refreshDecks();

    const cards = Array.isArray(project.studyAnswer?.cards) ? project.studyAnswer.cards.length : 0;
    clear(wiz.finalSummary);
    wiz.finalSummary.append(el('p', '', ''));
    wiz.finalSummary.firstChild.innerHTML = `La actividad <strong>${escapeHtml(project.studyAnswer.title)}</strong> quedó <strong>publicada</strong> con <strong>${cards} cartas</strong>. Tus estudiantes ya pueden encontrarla en la vista de práctica junto con el resto de las actividades. Si quieres ajustar algo, puedes editar el mazo desde este mismo panel.`;
    wiz.finalOpen.href = `/triviax/study.php?deck_id=${encodeURIComponent(deckId)}`;
    wiz.finalOpen.classList.remove('study-hidden');
    return deckId;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text ?? '');
    return div.innerHTML;
}

async function wizGenerateFromJson() {
    clear(wiz.jsonFeedback);
    try {
        const cleaned = wizCleanPastedJson(wiz.json.value);
        let parsed;
        try {
            parsed = JSON.parse(cleaned);
        } catch (err) {
            throw new Error(`El texto pegado no es un JSON válido (${err.message}). Pedile a la IA que devuelva solo el JSON y volvé a copiarlo completo.`);
        }
        const project = wizNormalizeProject(parsed);
        wiz.generate.disabled = true;
        setStatus('Revisando y publicando el mazo...');
        await wizPublishProject(project, wiz.jsonFeedback);
        setStatus('Mazo publicado correctamente.', 'ok');
        wizShowStep('fin');
    } catch (err) {
        setStatus(err.message || 'No se pudo generar el mazo.', 'error');
        if (!wiz.jsonFeedback.hasChildNodes()) {
            renderMessages(wiz.jsonFeedback, 'No se pudo generar el mazo', [err.message], 'error');
        }
    } finally {
        wiz.generate.disabled = false;
    }
}

/* ---------- Camino manual: formulario carta por carta ---------- */

function wizManualCurrentType() {
    if (wizard.modalidad === 'mixtas') {
        return wiz.cardType.value;
    }
    return WIZ_MODALIDAD_TYPES[wizard.modalidad][0];
}

function wizRenderManualAnswers() {
    const type = wizManualCurrentType();
    const blockFor = {
        multiple_choice: 'multiple_choice',
        true_false: 'true_false',
        fill_blank: 'text_answer',
        short_answer: 'text_answer'
    }[type];
    document.querySelectorAll('[data-answers]').forEach((block) => {
        block.classList.toggle('study-hidden', block.dataset.answers !== blockFor);
    });
    wiz.cardPrompt.placeholder = type === 'true_false'
        ? 'Escribe la afirmación que el estudiante deberá marcar como verdadera o falsa...'
        : (type === 'fill_blank'
            ? 'Escribe la frase con el espacio a completar marcado con ____ ...'
            : 'Escribe la pregunta que responderá el estudiante...');
}

function wizRenderManual() {
    wizCollectCommon();
    wiz.manualTypeRow.classList.toggle('study-hidden', wizard.modalidad !== 'mixtas');
    if (wizard.modalidad === 'mixtas') {
        const allowed = WIZ_MODALIDAD_TYPES.mixtas;
        Array.from(wiz.cardType.options).forEach((option) => {
            option.hidden = !allowed.includes(option.value);
        });
    }
    wizRenderManualAnswers();
    wizRenderManualProgress();
}

function wizRenderManualProgress() {
    const total = wizard.cantidad;
    const done = wizard.cards.length;
    wiz.manualProgress.textContent = done >= total
        ? `Ya creaste las ${done} cartas previstas. Puedes agregar más (hasta 30) o terminar el mazo.`
        : `Carta ${done + 1} de ${total} — llevás ${done} creada${done === 1 ? '' : 's'}.`;
    wiz.finishManual.disabled = done < 1;

    clear(wiz.manualList);
    wizard.cards.forEach((card, index) => {
        const chip = el('span', 'study-manual-chip');
        chip.append(el('span', '', `${index + 1}. ${card.title} (${WIZ_TYPE_LABELS[card.assessment.type] || card.assessment.type})`));
        const remove = el('button', '', '✕');
        remove.type = 'button';
        remove.title = 'Quitar esta carta';
        remove.addEventListener('click', () => {
            wizard.cards.splice(index, 1);
            wizRenderManualProgress();
        });
        chip.append(remove);
        wiz.manualList.append(chip);
    });
}

function wizSplitAnswers(raw) {
    return String(raw || '')
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

function wizShuffle(items) {
    const list = [...items];
    for (let i = list.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));
        [list[i], list[j]] = [list[j], list[i]];
    }
    return list;
}

function wizBuildManualAssessment(type) {
    const prompt = wiz.cardPrompt.value.trim();
    if (!prompt) {
        throw new Error(type === 'true_false'
            ? 'Escribe la afirmación de la carta.'
            : 'Escribe la pregunta de la carta.');
    }
    if (type === 'multiple_choice') {
        const correct = wiz.mcCorrect.value.trim();
        const wrong = wiz.mcWrong.map((input) => input.value.trim()).filter((value) => value !== '');
        if (!correct) {
            throw new Error('Escribe la respuesta correcta.');
        }
        if (!wrong.length) {
            throw new Error('Escribe al menos una respuesta incorrecta.');
        }
        return {
            type,
            prompt,
            options: wizShuffle([correct, ...wrong]),
            answer: correct
        };
    }
    if (type === 'true_false') {
        const selected = document.querySelector('input[name="wiz-tf"]:checked');
        return { type, prompt, answer: selected?.value === 'true' };
    }
    const answers = wizSplitAnswers(wiz.textAnswers.value);
    if (!answers.length) {
        throw new Error('Escribe al menos una respuesta aceptada (una por línea).');
    }
    if (type === 'fill_blank' && !prompt.includes('____')) {
        throw new Error('En "completar un espacio", marcá el espacio a completar con ____ dentro de la frase.');
    }
    return { type, prompt, answer: answers };
}

function wizClearCardForm() {
    wiz.cardTitle.value = '';
    wiz.cardStudy.value = '';
    wiz.cardPrompt.value = '';
    wiz.cardExplanation.value = '';
    wiz.mcCorrect.value = '';
    wiz.mcWrong.forEach((input) => { input.value = ''; });
    wiz.textAnswers.value = '';
    clear(wiz.manualFeedback);
    wiz.cardStudy.focus();
}

function wizAddManualCard() {
    clear(wiz.manualFeedback);
    try {
        if (wizard.cards.length >= 30) {
            throw new Error('Llegaste al máximo de 30 cartas por mazo.');
        }
        const studyText = wiz.cardStudy.value.trim();
        if (!studyText) {
            throw new Error('Escribe el texto de estudio de la carta.');
        }
        if (studyText.length > 900) {
            throw new Error(`El texto de estudio supera los 900 caracteres (tiene ${studyText.length}).`);
        }
        const type = wizManualCurrentType();
        const assessment = wizBuildManualAssessment(type);
        const index = wizard.cards.length + 1;
        const explanation = wiz.cardExplanation.value.trim();
        wizard.cards.push({
            id: `sa${String(index).padStart(3, '0')}`,
            type: 'study_card',
            title: wiz.cardTitle.value.trim() || `Carta ${index}`,
            order: index,
            learningObjective: '',
            studyText,
            keyIdea: '',
            vocabulary: [],
            assessment,
            feedback: {
                correct: '¡Correcto!',
                incorrect: 'Volvé a leer el texto de estudio.',
                explanation: explanation || 'Releé el texto de la carta: ahí está la idea clave.'
            },
            difficulty: 'media',
            cognitiveLevel: 'comprender',
            tags: [],
            estimatedReadTime: 25,
            estimatedAnswerTime: 20,
            points: 100
        });
        wizClearCardForm();
        wizRenderManualProgress();
        setStatus(`Carta ${index} agregada.`, 'ok');
    } catch (err) {
        renderMessages(wiz.manualFeedback, 'Revisa la carta', [err.message], 'error');
    }
}

function wizBuildManualProject() {
    wizCollectCommon();
    const blank = createBlankProject();
    const today = new Date().toISOString().slice(0, 10);
    // Reasigna ids/orden por si se quitaron cartas intermedias.
    const cards = wizard.cards.map((card, index) => ({
        ...card,
        id: `sa${String(index + 1).padStart(3, '0')}`,
        order: index + 1
    }));
    return {
        metadata: {
            title: wizard.titulo,
            author: '',
            nivel: wizard.nivel,
            obs: '',
            date: today,
            background: 'fondo.jpg',
            mode: 'study_answer'
        },
        board: { type: 'study_deck' },
        studyAnswer: {
            version: '1.0',
            title: wizard.titulo,
            description: wizard.tema,
            source: { type: 'manual', documents: [] },
            settings: Object.assign({}, blank.studyAnswer.settings, {
                cardCount: cards.length,
                allowedQuestionTypes: WIZ_MODALIDAD_TYPES[wizard.modalidad]
            }),
            cards
        }
    };
}

async function wizFinishManual() {
    clear(wiz.manualFeedback);
    try {
        if (!wizard.cards.length) {
            throw new Error('Agregá al menos una carta antes de terminar.');
        }
        if (wizard.cards.length < wizard.cantidad) {
            const seguir = window.confirm(`Planificaste ${wizard.cantidad} cartas y llevás ${wizard.cards.length}. ¿Quieres publicar el mazo igual?`);
            if (!seguir) {
                return;
            }
        }
        wiz.finishManual.disabled = true;
        setStatus('Guardando y publicando el mazo...');
        const project = wizBuildManualProject();
        await wizPublishProject(project, wiz.manualFeedback);
        setStatus('Mazo publicado correctamente.', 'ok');
        wizShowStep('fin');
    } catch (err) {
        setStatus(err.message || 'No se pudo publicar el mazo.', 'error');
        if (!wiz.manualFeedback.hasChildNodes()) {
            renderMessages(wiz.manualFeedback, 'No se pudo publicar', [err.message], 'error');
        }
    } finally {
        wiz.finishManual.disabled = wizard.cards.length < 1;
    }
}

/* ---------- Eventos del asistente ---------- */

function setupWizardEvents() {
    if (!wiz.root) {
        return;
    }
    document.querySelector('[data-action="wizard"]').addEventListener('click', wizOpen);
    wiz.cancel.addEventListener('click', () => {
        if (!wizard.cards.length || wizard.step === 'fin' || window.confirm('Si cancelás ahora vas a perder las cartas creadas. ¿Cancelar igual?')) {
            wizClose();
            setStatus('Asistente cerrado.', '');
        }
    });
    wiz.next.addEventListener('click', wizNext);
    wiz.back.addEventListener('click', wizBack);
    document.querySelectorAll('[data-wiz-metodo]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!wizValidateStep('tema') || !wizValidateStep('cantidad')) {
                wizShowStep('tema');
                return;
            }
            wizard.metodo = btn.dataset.wizMetodo;
            document.querySelectorAll('[data-wiz-metodo]').forEach((b) => b.classList.toggle('is-selected', b === btn));
            wizShowStep(wizard.metodo === 'ia' ? 'ia-prompt' : 'manual-cartas');
        });
    });
    wiz.copyPrompt.addEventListener('click', wizCopyPrompt);
    wiz.generate.addEventListener('click', wizGenerateFromJson);
    wiz.cardType.addEventListener('change', wizRenderManualAnswers);
    wiz.addCard.addEventListener('click', wizAddManualCard);
    wiz.finishManual.addEventListener('click', wizFinishManual);
    wiz.finalEdit.addEventListener('click', () => {
        wizClose();
        if (wizard.deckId) {
            loadDeck(wizard.deckId);
        }
    });
}

async function init() {
    setupEvents();
    setupWizardEvents();
    try {
        await refreshDecks();
        const blank = createBlankProject();
        els.editor.value = formatJson(blank);
        updateCurrentMeta(blank);
        setStatus('Panel listo. Selecciona un mazo existente o crea uno nuevo.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el panel.', 'error');
    }
}

init();
