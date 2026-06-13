/**
 * TRIVIAX — Panel docente de "TRIVIAX Lotto" (lotto_oral).
 * Asistente de creación (prompt de IA + pegar JSON), listado de actividades,
 * publicación y reporte. El sorteo en vivo se maneja en lotto_host.php.
 */

const root = document.querySelector('[data-lotto-admin]');
const csrfToken = root?.dataset.csrf || '';

const els = {
    status: document.querySelector('[data-status]'),
    list: document.querySelector('[data-activity-list]'),
    reportPanel: document.querySelector('[data-report-panel]'),
    reportTitle: document.querySelector('[data-report-title]'),
    report: document.querySelector('[data-report]')
};

function el(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = text;
    return node;
}

function clear(node) {
    while (node && node.firstChild) node.removeChild(node.firstChild);
}

function setStatus(message, tone = '') {
    els.status.textContent = message;
    els.status.dataset.tone = tone;
}

function apiUrl(action, params = {}) {
    const url = new URL('/triviax/api.php', window.location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    });
    return url.toString();
}

async function api(action, { method = 'GET', body = null, params = {} } = {}) {
    const options = { method };
    if (body !== null) {
        options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken };
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

const STATE_LABELS = {
    draft: 'Borrador',
    published: 'Publicada',
    login_open: 'Ingreso abierto',
    study: 'Estudio',
    response: 'Respuesta',
    oral: 'Ronda oral',
    finished: 'Finalizada',
    archived: 'Archivada',
    cancelled: 'Cancelada'
};

const QUICK_LABELS = {
    excelente: 'Excelente',
    correcto: 'Correcto',
    incompleto: 'Incompleto',
    no_responde: 'No responde',
    sin_evaluar: 'Sin evaluar'
};

function renderMessages(target, title, messages, tone = '') {
    clear(target);
    const box = el('div', 'lotto-result-box');
    box.append(el('strong', '', title));
    if (messages.length) {
        const list = el('ul', 'lotto-result-list');
        messages.forEach((m) => list.append(el('li', '', String(m))));
        box.append(list);
    }
    if (tone) box.dataset.tone = tone;
    target.append(box);
}

// ─────────────────────────────────────────────
// Listado de actividades
// ─────────────────────────────────────────────

async function refreshList() {
    const data = await api('lotto_list_activities');
    const activities = data.activities || [];
    clear(els.list);
    if (!activities.length) {
        els.list.append(el('div', 'lotto-result-box', 'Todavía no creaste ninguna actividad Lotto. Usa el asistente para crear la primera.'));
        return;
    }
    const table = el('table', 'lotto-table');
    const thead = document.createElement('thead');
    const hr = document.createElement('tr');
    ['Actividad', 'Código', 'Grupo', 'Estado', 'Estudiantes', 'Acciones'].forEach((h) => hr.append(el('th', '', h)));
    thead.append(hr);
    table.append(thead);
    const tbody = document.createElement('tbody');

    activities.forEach((a) => {
        const tr = document.createElement('tr');
        const tdTitle = document.createElement('td');
        tdTitle.append(el('strong', '', a.titulo));
        tdTitle.append(el('div', '', a.created_at || ''));
        tr.append(tdTitle);
        tr.append(el('td', 'lotto-code', a.codigo));
        tr.append(el('td', '', a.grupo || '—'));
        const tdState = document.createElement('td');
        const badge = el('span', 'lotto-badge', STATE_LABELS[a.status] || a.status);
        badge.dataset.state = a.status;
        tdState.append(badge);
        tr.append(tdState);
        tr.append(el('td', '', `${a.students} (${a.evaluations} eval.)`));

        const tdActions = document.createElement('td');
        const actions = el('div', 'lotto-row-actions');

        const addBtn = (label, primary, handler) => {
            const btn = el('button', `btn ${primary ? 'btn-primary' : 'btn-secondary'}`, label);
            btn.type = 'button';
            btn.addEventListener('click', handler);
            actions.append(btn);
        };
        const addLink = (label, href) => {
            const link = el('a', 'btn btn-secondary', label);
            link.href = href;
            link.target = '_blank';
            link.rel = 'noopener';
            actions.append(link);
        };

        if (a.status === 'draft') {
            addBtn('Publicar', true, () => transition('lotto_publish_activity', a.id, 'Actividad publicada. Abre el ingreso cuando empiece la clase.'));
        }
        if (a.status === 'published') {
            addBtn('Abrir ingreso', true, () => transition('lotto_open_login', a.id, 'Ingreso abierto. Tus estudiantes ya pueden entrar con su número.'));
        }
        if (['published', 'login_open', 'study', 'response', 'oral'].includes(a.status)) {
            addLink('Pantalla salón', `/triviax/lotto_host.php?code=${encodeURIComponent(a.codigo)}`);
        }
        if (a.status !== 'draft') {
            addBtn('Reporte', false, () => showReport(a));
        }
        if (['published', 'finished', 'cancelled'].includes(a.status)) {
            addBtn('Archivar', false, () => transition('lotto_archive_activity', a.id, 'Actividad archivada.'));
        }

        tdActions.append(actions);
        tr.append(tdActions);
        tbody.append(tr);
    });
    table.append(tbody);
    els.list.append(table);
}

async function transition(action, activityId, okMessage) {
    try {
        setStatus('Actualizando estado...');
        await api(action, { method: 'POST', body: { activity_id: activityId } });
        await refreshList();
        setStatus(okMessage, 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo actualizar el estado.', 'error');
    }
}

// ─────────────────────────────────────────────
// Reporte
// ─────────────────────────────────────────────

async function showReport(activity) {
    try {
        setStatus('Cargando reporte...');
        const data = await api('lotto_report', { params: { activity_id: activity.id } });
        els.reportPanel.classList.remove('lotto-hidden');
        els.reportTitle.textContent = `Reporte — ${data.activity.titulo} (${data.activity.codigo})`;
        renderReport(data);
        els.reportPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setStatus('Reporte cargado.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el reporte.', 'error');
    }
}

function renderReport(data) {
    clear(els.report);
    const s = data.summary;
    const summary = el('div', 'lotto-result-box');
    summary.append(el('strong', '', 'Resumen'));
    summary.append(el('p', '', `Estudiantes: ${s.total} · Ingresaron: ${s.logged} · No ingresaron: ${s.not_logged}`));
    summary.append(el('p', '', `Evaluados: ${s.evaluated} · Pendientes: ${s.pending} · Pospuestos: ${s.postponed} · Ausentes: ${s.absent}`));
    if (s.average_score !== null) {
        summary.append(el('p', '', `Promedio de puntaje: ${s.average_score}`));
    }
    els.report.append(summary);

    data.students.forEach((st) => {
        const box = el('div', 'lotto-result-box lotto-report-detail');
        box.append(el('strong', '', `${String(st.student_number).padStart(2, '0')} — ${st.full_name || st.first_name}`));
        box.append(el('p', '', `Estado: ${st.status} · Sección: ${st.section_title || '—'} · ${st.logged_at ? 'Ingresó' : 'No ingresó'}`));
        if (st.oral_main_question) box.append(el('p', '', `Pregunta oral: ${st.oral_main_question}`));
        if (st.expected_answers?.length) box.append(el('p', '', `Respuestas esperadas: ${st.expected_answers.join(' · ')}`));
        if (st.written_response) box.append(el('p', '', `Respuesta escrita: ${st.written_response}`));
        if (st.quick_result && st.quick_result !== 'sin_evaluar') {
            const scoreTxt = st.numeric_score !== null ? ` · Puntaje: ${st.numeric_score}${st.max_score ? '/' + st.max_score : ''}` : '';
            box.append(el('p', '', `Evaluación: ${QUICK_LABELS[st.quick_result] || st.quick_result}${scoreTxt}`));
        }
        if (st.teacher_comment) box.append(el('p', '', `Observación: ${st.teacher_comment}`));
        els.report.append(box);
    });
}

// ─────────────────────────────────────────────
// Asistente
// ─────────────────────────────────────────────

const WIZ_STEP_LABELS = {
    datos: 'Datos',
    documento: 'Documento',
    estudiantes: 'Estudiantes',
    config: 'Configuración',
    'ia-prompt': 'Prompt de IA',
    'ia-json': 'Pegar respuesta',
    fin: 'Creada'
};
const WIZ_FLOW = ['datos', 'documento', 'estudiantes', 'config', 'ia-prompt', 'ia-json', 'fin'];

const wizard = {
    step: 'datos',
    titulo: '', nivel: '', grupo: '', descripcion: '',
    documento: '', estudiantes: [],
    secciones: 5, preguntas: 3, minEstudio: 15, minRespuesta: 5,
    extension: true, sorteo: 'random_no_repeat',
    activityId: null, codigo: ''
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
    grupo: document.querySelector('[data-wiz-grupo]'),
    descripcion: document.querySelector('[data-wiz-descripcion]'),
    documento: document.querySelector('[data-wiz-documento]'),
    docCount: document.querySelector('[data-wiz-doc-count]'),
    estudiantes: document.querySelector('[data-wiz-estudiantes]'),
    studentsCount: document.querySelector('[data-wiz-students-count]'),
    secciones: document.querySelector('[data-wiz-secciones]'),
    preguntas: document.querySelector('[data-wiz-preguntas]'),
    minEstudio: document.querySelector('[data-wiz-min-estudio]'),
    minRespuesta: document.querySelector('[data-wiz-min-respuesta]'),
    extension: document.querySelector('[data-wiz-extension]'),
    sorteo: document.querySelector('[data-wiz-sorteo]'),
    prompt: document.querySelector('[data-wiz-prompt]'),
    copyPrompt: document.querySelector('[data-wiz-copy-prompt]'),
    json: document.querySelector('[data-wiz-json]'),
    jsonFeedback: document.querySelector('[data-wiz-json-feedback]'),
    generate: document.querySelector('[data-wiz-generate]'),
    finalSummary: document.querySelector('[data-final-summary]'),
    finalHost: document.querySelector('[data-final-host]'),
    finalClose: document.querySelector('[data-final-close]')
};

function wizParseStudents(raw) {
    return String(raw || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line, i) => {
            const firstName = line.split(/\s+/)[0];
            return { number: i + 1, firstName, fullName: line !== firstName ? line : '' };
        });
}

function wizCollect() {
    wizard.titulo = wiz.titulo.value.trim();
    wizard.nivel = wiz.nivel.value.trim();
    wizard.grupo = wiz.grupo.value.trim();
    wizard.descripcion = wiz.descripcion.value.trim();
    wizard.documento = wiz.documento.value.trim();
    wizard.estudiantes = wizParseStudents(wiz.estudiantes.value);
    wizard.secciones = Number(wiz.secciones.value);
    wizard.preguntas = Number(wiz.preguntas.value);
    wizard.minEstudio = Math.round(Number(wiz.minEstudio.value) || 0);
    wizard.minRespuesta = Math.round(Number(wiz.minRespuesta.value) || 0);
    wizard.extension = wiz.extension.value === '1';
    wizard.sorteo = wiz.sorteo.value;
}

function wizValidateStep(step) {
    wizCollect();
    if (step === 'datos' && !wizard.titulo) {
        setStatus('Escribe un título para la actividad.', 'warn');
        wiz.titulo.focus();
        return false;
    }
    if (step === 'documento') {
        if (wizard.documento.length < 200) {
            setStatus('El documento de estudio es muy corto. Pega el texto completo (al menos unas líneas).', 'warn');
            wiz.documento.focus();
            return false;
        }
    }
    if (step === 'estudiantes') {
        if (wizard.estudiantes.length < 1) {
            setStatus('Agregá al menos un estudiante (uno por línea).', 'warn');
            wiz.estudiantes.focus();
            return false;
        }
        if (wizard.estudiantes.length > 60) {
            setStatus('El máximo es 60 estudiantes por actividad.', 'warn');
            return false;
        }
    }
    if (step === 'config') {
        if (wizard.minEstudio < 1 || wizard.minRespuesta < 1) {
            setStatus('Los tiempos de estudio y respuesta deben ser de al menos 1 minuto.', 'warn');
            return false;
        }
    }
    return true;
}

function wizRenderSteps() {
    clear(wiz.steps);
    const currentIndex = WIZ_FLOW.indexOf(wizard.step);
    WIZ_FLOW.forEach((name, i) => {
        const item = el('li', '', `${i + 1}. ${WIZ_STEP_LABELS[name]}`);
        if (name === wizard.step) item.classList.add('is-current');
        else if (i < currentIndex) item.classList.add('is-done');
        wiz.steps.append(item);
    });
}

function wizShowStep(name) {
    wizard.step = name;
    wiz.panels.forEach((p) => p.classList.toggle('lotto-hidden', p.dataset.wizardStep !== name));
    const index = WIZ_FLOW.indexOf(name);
    wiz.back.classList.toggle('lotto-hidden', index <= 0 || name === 'fin');
    wiz.next.classList.toggle('lotto-hidden', ['ia-json', 'fin'].includes(name));
    if (name === 'ia-prompt') wiz.prompt.value = wizBuildPrompt();
    wizRenderSteps();
}

function wizOpen() {
    wizard.activityId = null;
    wizard.codigo = '';
    wiz.json.value = '';
    clear(wiz.jsonFeedback);
    wiz.root.classList.remove('lotto-hidden');
    wizShowStep('datos');
    wiz.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setStatus('Asistente iniciado. Completa los pasos para crear tu Lotto.', 'ok');
}

function wizClose() {
    wiz.root.classList.add('lotto-hidden');
}

function wizNext() {
    if (!wizValidateStep(wizard.step)) return;
    const i = WIZ_FLOW.indexOf(wizard.step);
    if (i >= 0 && i < WIZ_FLOW.length - 1) wizShowStep(WIZ_FLOW[i + 1]);
}

function wizBack() {
    const i = WIZ_FLOW.indexOf(wizard.step);
    if (i > 0) wizShowStep(WIZ_FLOW[i - 1]);
}

function wizBuildPrompt() {
    wizCollect();
    const today = new Date().toISOString().slice(0, 10);
    const studentsJson = JSON.stringify(wizard.estudiantes.map((s) => ({
        number: s.number, firstName: s.firstName, fullName: s.fullName || s.firstName
    })), null, 2);

    return `Actuá como diseñador pedagógico para estudiantes de nivel escolar (aproximadamente 12 años).

A partir del documento fuente que está al final de este mensaje y de la lista de estudiantes, crea una actividad "TRIVIAX Lotto" (sorteo oral de aprendizaje). Cada estudiante recibirá una ficha breve de estudio y preguntas guía; luego el docente sorteará en clase quién presenta oralmente.

Tu respuesta debe ser ÚNICAMENTE un objeto JSON válido, sin texto antes ni después, sin comentarios y sin bloques de código markdown. Usa exactamente esta estructura:

{
  "metadata": {
    "title": ${JSON.stringify(wizard.titulo)},
    "author": "",
    "nivel": ${JSON.stringify(wizard.nivel)},
    "grupo": ${JSON.stringify(wizard.grupo)},
    "date": "${today}",
    "mode": "lotto_oral"
  },
  "lotto": {
    "version": "1.0",
    "title": ${JSON.stringify(wizard.titulo)},
    "description": ${JSON.stringify(wizard.descripcion)},
    "settings": {
      "sectionsCount": ${wizard.secciones},
      "questionsPerStudent": ${wizard.preguntas},
      "studyMinutes": ${wizard.minEstudio},
      "responseMinutes": ${wizard.minRespuesta},
      "allowTimeExtension": ${wizard.extension},
      "drawMode": ${JSON.stringify(wizard.sorteo)},
      "showFirstNamesOnHost": true,
      "requireAllLoggedBeforeStart": true
    },
    "rubric": {
      "scale": "0-12",
      "criteria": [
        { "id": "comprension", "label": "Comprensión del tema", "max": 3 },
        { "id": "claridad", "label": "Claridad al explicar", "max": 3 },
        { "id": "vocabulario", "label": "Uso de vocabulario específico", "max": 3 },
        { "id": "repregunta", "label": "Respuesta a repregunta", "max": 3 }
      ]
    },
    "students": ${studentsJson.split('\n').join('\n    ')},
    "sections": [ ... ${wizard.secciones} secciones ... ],
    "assignments": [ ... una asignación por estudiante ... ]
  }
}

Cada elemento de "sections" debe tener esta forma:
{
  "id": "sec01",
  "order": 1,
  "title": "Título de la sección",
  "summaryText": "Resumen breve de la sección.",
  "keyIdeas": ["idea 1", "idea 2"],
  "vocabulary": [{ "term": "término", "definition": "definición breve" }],
  "difficulty": "baja"
}

Cada elemento de "assignments" debe tener esta forma:
{
  "studentNumber": 1,
  "sectionId": "sec01",
  "studyText": "Texto breve de estudio para este estudiante (máximo 2000 caracteres, apto para leer en ${wizard.minEstudio} minutos).",
  "keyIdeas": ["idea 1", "idea 2"],
  "studentQuestions": ["pregunta guía 1", "..."],
  "oralMainQuestion": "Pregunta oral principal.",
  "teacherExpectedAnswers": ["qué debe mencionar el estudiante", "..."],
  "oralFollowups": ["repregunta 1", "repregunta 2"],
  "difficulty": "media"
}

Reglas obligatorias:
1. Dividí el tema en exactamente ${wizard.secciones} secciones coherentes, con ids "sec01", "sec02", ...
2. Incluí en "students" exactamente la lista de estudiantes provista, sin cambiar números ni nombres.
3. Cada estudiante debe tener exactamente UNA asignación. Distribuí las secciones de forma equilibrada entre los estudiantes.
4. Cada asignación debe tener exactamente ${wizard.preguntas} preguntas guía en "studentQuestions".
5. Equilibrá la dificultad: todos reciben textos de extensión parecida y preguntas de exigencia comparable. Cada estudiante recibe al menos una pregunta de comprensión; si hay más, incluí una de aplicación o comparación.
6. Las preguntas deben servir para ORALIDAD: explicar, definir con sus palabras, comparar, dar ejemplos, justificar, diferenciar, relacionar. NO uses opción múltiple.
7. "studyText" máximo 2000 caracteres. "difficulty" solo puede ser "baja", "media" o "alta".
8. Usa solo información presente en el documento fuente. No inventes datos externos.
9. Redactá en español claro, escolar y preciso.
10. Recuerda: tu respuesta es SOLO el JSON, empezando con { y terminando con }.

Lista de estudiantes:
${studentsJson}

Aquí está el documento fuente:

${wizard.documento}`;
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

function wizNormalizePayload(parsed) {
    wizCollect();
    const payload = parsed && typeof parsed === 'object' ? parsed : {};
    payload.metadata = Object.assign({
        title: wizard.titulo, author: '', nivel: wizard.nivel, grupo: wizard.grupo,
        date: new Date().toISOString().slice(0, 10)
    }, payload.metadata || {}, { mode: 'lotto_oral' });
    if (!payload.lotto || typeof payload.lotto !== 'object') payload.lotto = {};
    const lotto = payload.lotto;
    lotto.version = lotto.version || '1.0';
    lotto.title = lotto.title || wizard.titulo;
    lotto.description = lotto.description || wizard.descripcion;
    lotto.sourceText = wizard.documento;
    // La configuración del asistente manda sobre lo que haya devuelto la IA.
    lotto.settings = Object.assign({}, lotto.settings || {}, {
        sectionsCount: Array.isArray(lotto.sections) && lotto.sections.length ? lotto.sections.length : wizard.secciones,
        questionsPerStudent: wizard.preguntas,
        studyMinutes: wizard.minEstudio,
        responseMinutes: wizard.minRespuesta,
        allowTimeExtension: wizard.extension,
        drawMode: wizard.sorteo,
        showFirstNamesOnHost: true,
        requireAllLoggedBeforeStart: true
    });
    if (!lotto.rubric || !Array.isArray(lotto.rubric.criteria) || !lotto.rubric.criteria.length) {
        lotto.rubric = {
            scale: '0-12',
            criteria: [
                { id: 'comprension', label: 'Comprensión del tema', max: 3 },
                { id: 'claridad', label: 'Claridad al explicar', max: 3 },
                { id: 'vocabulario', label: 'Uso de vocabulario específico', max: 3 },
                { id: 'repregunta', label: 'Respuesta a repregunta', max: 3 }
            ]
        };
    }
    return payload;
}

async function wizGenerate() {
    clear(wiz.jsonFeedback);
    try {
        const cleaned = wizCleanPastedJson(wiz.json.value);
        let parsed;
        try {
            parsed = JSON.parse(cleaned);
        } catch (err) {
            throw new Error(`El texto pegado no es un JSON válido (${err.message}). Pedile a la IA que devuelva solo el JSON y volvé a copiarlo completo.`);
        }
        const payload = wizNormalizePayload(parsed);

        wiz.generate.disabled = true;
        setStatus('Validando la actividad...');
        const validation = await api('lotto_validate_payload', { method: 'POST', body: { payload } }).catch((err) => {
            const msgs = [
                ...((err.data?.errors) || []).map((m) => `Error: ${m}`),
                ...((err.data?.warnings) || []).map((m) => `Aviso: ${m}`)
            ];
            renderMessages(wiz.jsonFeedback, 'La actividad tiene errores', msgs.length ? msgs : [err.message], 'error');
            throw new Error('El contenido tiene errores. Revisa el detalle, pedile correcciones a la IA y volvé a pegar la respuesta.');
        });
        if (validation.warnings?.length) {
            renderMessages(wiz.jsonFeedback, 'Avisos', validation.warnings, '');
        }

        setStatus('Guardando y publicando la actividad...');
        const saved = await api('lotto_save_activity', { method: 'POST', body: { payload, activity_id: null } });
        await api('lotto_publish_activity', { method: 'POST', body: { activity_id: saved.activity_id } });

        wizard.activityId = saved.activity_id;
        wizard.codigo = saved.codigo;

        clear(wiz.finalSummary);
        const p1 = el('p');
        p1.append('La actividad ');
        p1.append(el('strong', '', payload.lotto.title));
        p1.append(` quedó creada y publicada con ${saved.students} estudiantes y ${saved.sections} secciones.`);
        const p2 = el('p');
        p2.append('Código para tus estudiantes: ');
        p2.append(el('span', 'lotto-code', saved.codigo));
        const p3 = el('p', '', 'Cuando empiece la clase: abre la pantalla del salón, presiona "Abrir ingreso" y espera a que todos ingresen con su número antes de iniciar el estudio.');
        wiz.finalSummary.append(p1, p2, p3);
        wiz.finalHost.href = `/triviax/lotto_host.php?code=${encodeURIComponent(saved.codigo)}`;
        wiz.finalHost.classList.remove('lotto-hidden');

        await refreshList();
        setStatus('Actividad creada y publicada.', 'ok');
        wizShowStep('fin');
    } catch (err) {
        setStatus(err.message || 'No se pudo crear la actividad.', 'error');
        if (!wiz.jsonFeedback.hasChildNodes()) {
            renderMessages(wiz.jsonFeedback, 'No se pudo crear la actividad', [err.message], 'error');
        }
    } finally {
        wiz.generate.disabled = false;
    }
}

// ─────────────────────────────────────────────
// Eventos e init
// ─────────────────────────────────────────────

function setupEvents() {
    document.querySelector('[data-action="wizard"]').addEventListener('click', wizOpen);
    wiz.cancel.addEventListener('click', () => { wizClose(); setStatus('Asistente cerrado.', ''); });
    wiz.next.addEventListener('click', wizNext);
    wiz.back.addEventListener('click', wizBack);
    wiz.copyPrompt.addEventListener('click', wizCopyPrompt);
    wiz.generate.addEventListener('click', wizGenerate);
    wiz.finalClose.addEventListener('click', () => { wizClose(); });
    wiz.documento.addEventListener('input', () => {
        wiz.docCount.textContent = `${wiz.documento.value.trim().length} caracteres (máximo 12.000)`;
    });
    wiz.estudiantes.addEventListener('input', () => {
        const n = wizParseStudents(wiz.estudiantes.value).length;
        wiz.studentsCount.textContent = `${n} estudiante${n === 1 ? '' : 's'} (máximo 60)`;
    });
}

async function init() {
    setupEvents();
    try {
        await refreshList();
        setStatus('Panel listo. Crea una actividad o gestioná las existentes.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar el panel.', 'error');
    }
}

init();
