/**
 * TRIVIAX — Frontend estudiante de "TRIVIAX Lotto" (lotto_oral).
 * Login por código + número, ficha propia, timer de fase, respuesta breve
 * y polling de estado (que funciona también como heartbeat).
 * Nunca recibe ni muestra respuestas esperadas del docente.
 */

const root = document.querySelector('[data-lotto-student]');
const csrfToken = root?.dataset.csrf || '';

const state = {
    activityId: null,
    studentId: null,
    token: '',
    phase: null,
    deadline: null,        // epoch ms, ajustado con el reloj del servidor
    pollTimer: null,
    tickTimer: null,
    submitted: false
};

const els = {
    status: document.querySelector('[data-status]'),
    screens: {
        login: document.querySelector('[data-screen="login"]'),
        ficha: document.querySelector('[data-screen="ficha"]')
    },
    loginCode: document.querySelector('[data-login-code]'),
    loginNumber: document.querySelector('[data-login-number]'),
    loginBtn: document.querySelector('[data-login-btn]'),
    hello: document.querySelector('[data-st-hello]'),
    activity: document.querySelector('[data-st-activity]'),
    number: document.querySelector('[data-st-number]'),
    phase: document.querySelector('[data-st-phase]'),
    timer: document.querySelector('[data-st-timer]'),
    called: document.querySelector('[data-st-called]'),
    section: document.querySelector('[data-st-section]'),
    study: document.querySelector('[data-st-study]'),
    ideasWrap: document.querySelector('[data-st-ideas-wrap]'),
    ideas: document.querySelector('[data-st-ideas]'),
    questions: document.querySelector('[data-st-questions]'),
    oral: document.querySelector('[data-st-oral]'),
    responseWrap: document.querySelector('[data-st-response-wrap]'),
    response: document.querySelector('[data-st-response]'),
    save: document.querySelector('[data-st-save]'),
    submit: document.querySelector('[data-st-submit]'),
    ready: document.querySelector('[data-st-ready]')
};

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

function authParams() {
    return { activity_id: state.activityId, student_id: state.studentId, token: state.token };
}

function storageKey(activityId, studentId) {
    return `triviax_lotto_${activityId}_student_${studentId}_token`;
}

const PHASE_LABELS = {
    login_open: 'Esperando que el docente inicie el estudio',
    study: '📖 Fase de estudio',
    response: '✍️ Fase de respuesta y preparación final',
    oral: '🎤 Ronda de orales',
    finished: 'Actividad finalizada',
    cancelled: 'Actividad cancelada'
};

// ─────────────────────────────────────────────
// Login
// ─────────────────────────────────────────────

async function doLogin() {
    const code = els.loginCode.value.trim().toUpperCase();
    const number = Number(els.loginNumber.value);
    if (code.length !== 6) {
        setStatus('El código de actividad tiene 6 caracteres.', 'warn');
        els.loginCode.focus();
        return;
    }
    if (!Number.isInteger(number) || number < 1) {
        setStatus('Ingresa tu número asignado.', 'warn');
        els.loginNumber.focus();
        return;
    }
    try {
        els.loginBtn.disabled = true;
        setStatus('Ingresando...');
        const data = await api('lotto_student_login', { method: 'POST', body: { code, student_number: number } });
        state.activityId = Number(data.activity_id);
        state.studentId = Number(data.student_id);
        state.token = String(data.token);
        try {
            localStorage.setItem(storageKey(state.activityId, state.studentId), state.token);
            localStorage.setItem('triviax_lotto_last', JSON.stringify({ activityId: state.activityId, studentId: state.studentId }));
        } catch (e) { /* almacenamiento no disponible: la sesión vive en memoria */ }
        await enterFicha(data.first_name);
    } catch (err) {
        setStatus(err.message || 'No se pudo ingresar.', 'error');
    } finally {
        els.loginBtn.disabled = false;
    }
}

async function tryRestoreSession() {
    try {
        const last = JSON.parse(localStorage.getItem('triviax_lotto_last') || 'null');
        if (!last) return false;
        const token = localStorage.getItem(storageKey(last.activityId, last.studentId));
        if (!token) return false;
        state.activityId = Number(last.activityId);
        state.studentId = Number(last.studentId);
        state.token = token;
        const data = await api('lotto_student_state', { params: authParams() });
        if (['finished', 'cancelled', 'archived'].includes(data.activity.status)) return false;
        await enterFicha(data.student.first_name);
        return true;
    } catch (err) {
        state.activityId = null;
        state.studentId = null;
        state.token = '';
        return false;
    }
}

async function enterFicha(firstName) {
    els.screens.login.classList.add('lotto-hidden');
    els.screens.ficha.classList.remove('lotto-hidden');
    els.hello.textContent = `Hola, ${firstName}`;
    try {
        const data = await api('lotto_student_assignment', { params: authParams() });
        renderAssignment(data.assignment);
    } catch (err) {
        setStatus(err.message || 'No se pudo cargar tu ficha.', 'error');
    }
    startPolling();
    setStatus('Ingreso correcto. Leé tu ficha mientras esperás las indicaciones del docente.', 'ok');
}

function renderAssignment(assignment) {
    els.section.textContent = `Tu tema: ${assignment.section_title}`;
    els.study.textContent = assignment.study_text;
    els.questions.replaceChildren(...assignment.student_questions.map((q) => {
        const li = document.createElement('li');
        li.textContent = q;
        return li;
    }));
    els.oral.textContent = assignment.oral_main_question;
    if (assignment.key_ideas?.length) {
        els.ideasWrap.classList.remove('lotto-hidden');
        els.ideas.replaceChildren(...assignment.key_ideas.map((idea) => {
            const li = document.createElement('li');
            li.textContent = idea;
            return li;
        }));
    }
}

// ─────────────────────────────────────────────
// Polling de estado + timer
// ─────────────────────────────────────────────

function startPolling() {
    stopPolling();
    pollState();
    state.pollTimer = setInterval(pollState, 4000);
    state.tickTimer = setInterval(renderTimer, 500);
}

function stopPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    if (state.tickTimer) clearInterval(state.tickTimer);
    state.pollTimer = null;
    state.tickTimer = null;
}

async function pollState() {
    try {
        const data = await api('lotto_student_state', { params: authParams() });
        applyState(data);
    } catch (err) {
        // Errores transitorios de red no interrumpen; un 403 indica número liberado.
        if (err.data && err.data.code === 'FORBIDDEN') {
            stopPolling();
            setStatus('Tu sesión fue liberada por el docente. Volvé a ingresar con tu número.', 'warn');
            els.screens.ficha.classList.add('lotto-hidden');
            els.screens.login.classList.remove('lotto-hidden');
        }
    }
}

function applyState(data) {
    const activity = data.activity;
    const student = data.student;
    state.phase = activity.status;

    els.activity.textContent = activity.titulo;
    els.number.textContent = String(student.student_number).padStart(2, '0');
    els.phase.textContent = PHASE_LABELS[activity.status] || activity.status;

    if (activity.phase_deadline) {
        // Ajuste con el reloj del servidor para que el timer no dependa del reloj local.
        const serverNow = new Date(activity.server_now.replace(' ', 'T')).getTime();
        const deadline = new Date(activity.phase_deadline.replace(' ', 'T')).getTime();
        state.deadline = Date.now() + (deadline - serverNow);
        els.timer.classList.remove('lotto-hidden');
    } else {
        state.deadline = null;
        els.timer.classList.add('lotto-hidden');
    }

    els.called.classList.toggle('lotto-hidden', !student.is_called);

    const canRespond = ['study', 'response'].includes(activity.status) && !student.is_called;
    els.responseWrap.classList.toggle('lotto-hidden', !['study', 'response', 'oral'].includes(activity.status));
    els.save.disabled = !canRespond && activity.status !== 'oral';
    els.submit.disabled = els.save.disabled;

    if (data.response && !els.response.matches(':focus') && els.response.value === '') {
        els.response.value = data.response.text || '';
    }
    if (data.response?.submitted && !state.submitted) {
        state.submitted = true;
        els.submit.textContent = 'Respuesta enviada ✓';
    }

    if (['finished', 'cancelled'].includes(activity.status)) {
        stopPolling();
        setStatus('La actividad terminó. ¡Gracias por participar!', 'ok');
        els.responseWrap.classList.add('lotto-hidden');
    }
}

function renderTimer() {
    if (!state.deadline) return;
    const remaining = Math.max(0, Math.floor((state.deadline - Date.now()) / 1000));
    const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
    const ss = String(remaining % 60).padStart(2, '0');
    els.timer.textContent = `${mm}:${ss}`;
    els.timer.dataset.tone = remaining > 0 && remaining <= 60 ? 'low' : '';
}

// ─────────────────────────────────────────────
// Respuesta breve
// ─────────────────────────────────────────────

async function saveResponse(submit) {
    try {
        const action = submit ? 'lotto_student_submit_response' : 'lotto_student_save_response';
        const data = await api(action, {
            method: 'POST',
            body: {
                ...authParams(),
                text: els.response.value,
                idempotency_key: `${state.activityId}_${state.studentId}_${Date.now()}`
            }
        });
        if (submit) {
            state.submitted = true;
            els.submit.textContent = 'Respuesta enviada ✓';
        }
        setStatus(data.message || (submit ? 'Respuesta enviada.' : 'Borrador guardado.'), 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo guardar.', 'error');
    }
}

async function markReady() {
    try {
        await api('lotto_student_ready', { method: 'POST', body: authParams() });
        setStatus('Quedaste marcado como preparado/a. El docente lo ve en la pantalla del salón.', 'ok');
    } catch (err) {
        setStatus(err.message || 'No se pudo marcar.', 'error');
    }
}

// ─────────────────────────────────────────────
// Init
// ─────────────────────────────────────────────

function init() {
    els.loginBtn.addEventListener('click', doLogin);
    els.loginNumber.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
    els.save.addEventListener('click', () => saveResponse(false));
    els.submit.addEventListener('click', () => saveResponse(true));
    els.ready.addEventListener('click', markReady);

    const prefill = root?.dataset.prefillCode || '';
    if (prefill) els.loginCode.value = prefill;

    tryRestoreSession();
}

init();
