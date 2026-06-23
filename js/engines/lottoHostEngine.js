/**
 * TRIVIAX — Frontend host (pizarra/proyector) de "TRIVIAX Lotto" (lotto_oral).
 * Administra el estado en tiempo real, temporizadores, sorteo y evaluación.
 * Polling cada 2 segundos y actualización reactiva de la interfaz.
 */

const root = document.querySelector('[data-lotto-host]');
const csrfToken = root?.dataset.csrf || '';
const activityId = Number(root?.dataset.activityId || 0);
const rubricData = JSON.parse(root?.dataset.rubric || 'null');

const state = {
    activityId,
    status: null,
    deadline: null,         // epoch ms, ajustado con reloj del servidor
    pollTimer: null,
    tickTimer: null,
    students: [],
    draws: [],
    currentEvalStudent: null, // Estudiante actualmente en evaluación modal
    rubricScores: {}         // Puntajes seleccionados en el modal { criterionId: score }
};

const els = {
    timer: document.querySelector('[data-host-timer]'),
    phase: document.querySelector('[data-host-phase]'),
    studentList: document.querySelector('[data-student-list]'),
    studentCountTitle: document.getElementById('student-count-title'),
    carousel: document.querySelector('[data-draw-carousel]'),
    oralActions: document.querySelector('[data-oral-actions]'),
    
    // Botones de fase
    actOpenLogin: document.querySelector('[data-act-open-login]'),
    actStartStudy: document.querySelector('[data-act-start-study]'),
    actExtendStudy: document.querySelector('[data-act-extend-study]'),
    actStartResponse: document.querySelector('[data-act-start-response]'),
    actExtendResponse: document.querySelector('[data-act-extend-response]'),
    actStartOral: document.querySelector('[data-act-start-oral]'),
    actDrawInitial: document.querySelector('[data-act-draw-initial]'),
    actFinish: document.querySelector('[data-act-finish]'),
    
    // Botones de sorteo individual
    btnEvaluate: document.querySelector('[data-btn-evaluate]'),
    btnPostpone: document.querySelector('[data-btn-postpone]'),
    btnAbsent: document.querySelector('[data-btn-absent]'),
    btnSkip: document.querySelector('[data-btn-skip]'),
    
    // Modal de evaluación
    evalModal: document.querySelector('[data-eval-modal]'),
    modalTitle: document.querySelector('[data-modal-title]'),
    modalSubtitle: document.querySelector('[data-modal-subtitle]'),
    modalSecTitle: document.querySelector('[data-modal-sec-title]'),
    modalSecSummary: document.querySelector('[data-modal-sec-summary]'),
    modalOralQuestion: document.querySelector('[data-modal-oral-question]'),
    modalExpectedList: document.querySelector('[data-modal-expected]'),
    modalFollowupsList: document.querySelector('[data-modal-followups]'),
    modalRubricContainer: document.querySelector('[data-modal-rubric-container]'),
    modalRubricTotal: document.querySelector('[data-modal-rubric-total]'),
    modalQuickRes: document.querySelector('[data-modal-quick-res]'),
    modalNumScore: document.querySelector('[data-modal-num-score]'),
    modalComment: document.querySelector('[data-modal-comment]'),
    modalClose: document.querySelector('[data-modal-close]'),
    modalBtnCancel: document.querySelector('[data-modal-btn-cancel]'),
    modalBtnSave: document.querySelector('[data-modal-btn-save]')
};

const PHASE_LABELS = {
    draft: 'Borrador',
    published: 'Publicada (esperando ingreso)',
    login_open: 'Ingreso abierto',
    study: '📖 Fase de estudio',
    response: '✍️ Fase de respuesta',
    oral: '🎤 Ronda oral',
    finished: 'Actividad finalizada',
    cancelled: 'Actividad cancelada',
    archived: 'Actividad archivada'
};

// ─────────────────────────────────────────────
// Helpers de API
// ─────────────────────────────────────────────

function apiUrl(action, params = {}) {
    const url = new URL((window.TRIVIAX_BASE ?? '') + '/api.php', window.location.origin);
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

// ─────────────────────────────────────────────
// Polling y Heartbeat
// ─────────────────────────────────────────────

function startPolling() {
    stopPolling();
    pollState();
    state.pollTimer = setInterval(pollState, 2000);
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
        const data = await api('lotto_host_state', { params: { activity_id: state.activityId } });
        applyState(data);
    } catch (err) {
        console.error('Error de polling:', err.message);
    }
}

// ─────────────────────────────────────────────
// Renderizado de Interfaz
// ─────────────────────────────────────────────

function applyState(data) {
    const act = data.activity;
    state.status = act.status;
    state.students = data.students || [];
    state.draws = data.draws || [];

    // Título y grupo
    document.querySelector('[data-activity-title]').textContent = act.titulo;
    document.querySelector('[data-activity-group]').textContent = act.grupo || '—';
    els.phase.textContent = PHASE_LABELS[act.status] || act.status;

    // Timer deadline
    if (act.phase_deadline) {
        const serverNow = new Date(act.server_now.replace(' ', 'T')).getTime();
        const deadline = new Date(act.phase_deadline.replace(' ', 'T')).getTime();
        state.deadline = Date.now() + (deadline - serverNow);
        els.timer.classList.remove('lotto-hidden');
    } else {
        state.deadline = null;
        els.timer.classList.add('lotto-hidden');
        els.timer.textContent = '--:--';
    }

    // Alumnos
    renderStudents();

    // Sorteo & Controles
    renderDrawCarousel();
    renderControlsPanel(act);
}

function renderStudents() {
    const loggedCount = state.students.filter(s => s.effective_status !== 'pending' && s.effective_status !== 'absent').length;
    els.studentCountTitle.textContent = `Estudiantes (${loggedCount}/${state.students.length})`;

    const fragment = document.createDocumentFragment();
    state.students.forEach(st => {
        const item = document.createElement('div');
        item.className = 'student-item';
        item.dataset.status = st.effective_status;

        const info = document.createElement('div');
        info.className = 'student-info';

        const num = document.createElement('span');
        num.className = 'student-num';
        num.textContent = String(st.student_number).padStart(2, '0');

        const name = document.createElement('span');
        name.className = 'student-name';
        name.textContent = st.first_name;

        info.append(num, name);
        item.append(info);

        const actions = document.createElement('div');
        actions.style.display = 'flex';
        actions.style.alignItems = 'center';
        actions.style.gap = '8px';

        // Badge de estado
        const badge = document.createElement('span');
        badge.className = 'student-badge';
        badge.textContent = translateStatus(st.effective_status, st.quick_result);
        actions.append(badge);

        // Botón liberar ingreso si está conectado pero no evaluado
        if (['logged', 'studying', 'ready', 'disconnected'].includes(st.effective_status)) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-release-login';
            btn.textContent = 'Liberar';
            btn.title = 'Liberar número para reingresar';
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                releaseStudentLogin(st.id, st.first_name);
            });
            actions.append(btn);
        }

        item.append(actions);
        fragment.append(item);
    });

    els.studentList.replaceChildren(fragment);
}

function translateStatus(status, quickResult) {
    if (status === 'evaluated' && quickResult && quickResult !== 'sin_evaluar') {
        const quickLabels = { excelente: 'Exc', correcto: 'Corr', incompleto: 'Inc', no_responde: 'N/R' };
        return quickLabels[quickResult] || 'Eval';
    }
    const labels = {
        pending: 'Ausente',
        logged: 'Ingresó',
        studying: 'Estudiando',
        ready: 'Listo',
        called: 'Al Oral',
        evaluated: 'Eval',
        postponed: 'Pospuesto',
        absent: 'Ausente',
        disconnected: 'Desconectado'
    };
    return labels[status] || status;
}

// Organiza los sorteos para que el "current" quede siempre en el slot 3 (índice 2)
function renderDrawCarousel() {
    const slots = [null, null, null, null, null];
    const current = state.draws.find(d => d.draw_state === 'current');
    const queued = state.draws.filter(d => d.draw_state === 'queued');

    if (current) {
        slots[2] = current;
    }
    if (queued.length > 0) slots[1] = queued[0];
    if (queued.length > 1) slots[3] = queued[1];
    if (queued.length > 2) slots[0] = queued[2];
    if (queued.length > 3) slots[4] = queued[3];

    for (let i = 0; i < 5; i++) {
        const slotEl = els.carousel.querySelector(`[data-slot="${i}"]`);
        const numEl = slotEl.querySelector('.card-number');
        const nameEl = slotEl.querySelector('.card-name');
        
        const draw = slots[i];
        if (draw) {
            slotEl.classList.remove('lotto-hidden');
            numEl.textContent = String(draw.student_number).padStart(2, '0');
            nameEl.textContent = draw.first_name;
            if (draw.draw_state === 'current') {
                slotEl.classList.add('is-current');
            } else {
                slotEl.classList.remove('is-current');
            }
        } else {
            // Si no hay sorteos o el slot está vacío
            if (state.status === 'oral' && state.draws.length > 0) {
                slotEl.classList.add('lotto-hidden');
            } else {
                slotEl.classList.remove('lotto-hidden');
                slotEl.classList.remove('is-current');
                numEl.textContent = '—';
                nameEl.textContent = state.status === 'oral' ? 'Vacío' : 'Esperando';
            }
        }
    }

    // Visibilidad de controles de sorteo oral
    const isOralStateWithDraws = state.status === 'oral' && current !== undefined;
    els.oralActions.classList.toggle('lotto-hidden', !isOralStateWithDraws);
}

function renderControlsPanel(act) {
    // Escondemos todos los botones por defecto
    els.actOpenLogin.classList.add('lotto-hidden');
    els.actStartStudy.classList.add('lotto-hidden');
    els.actExtendStudy.classList.add('lotto-hidden');
    els.actStartResponse.classList.add('lotto-hidden');
    els.actExtendResponse.classList.add('lotto-hidden');
    els.actStartOral.classList.add('lotto-hidden');
    els.actDrawInitial.classList.add('lotto-hidden');
    els.actFinish.classList.add('lotto-hidden');

    const status = act.status;
    const allowExt = !!act.allow_time_extension;

    if (status === 'published') {
        els.actOpenLogin.classList.remove('lotto-hidden');
    } else if (status === 'login_open') {
        els.actStartStudy.classList.remove('lotto-hidden');
        els.actFinish.classList.remove('lotto-hidden');
    } else if (status === 'study') {
        els.actStartResponse.classList.remove('lotto-hidden');
        if (allowExt) els.actExtendStudy.classList.remove('lotto-hidden');
        els.actFinish.classList.remove('lotto-hidden');
    } else if (status === 'response') {
        els.actStartOral.classList.remove('lotto-hidden');
        if (allowExt) els.actExtendResponse.classList.remove('lotto-hidden');
        els.actFinish.classList.remove('lotto-hidden');
    } else if (status === 'oral') {
        if (state.draws.length === 0) {
            els.actDrawInitial.classList.remove('lotto-hidden');
        }
        els.actFinish.classList.remove('lotto-hidden');
    }
}

function renderTimer() {
    if (!state.deadline) {
        els.timer.textContent = '--:--';
        els.timer.dataset.tone = '';
        return;
    }
    const remaining = Math.max(0, Math.floor((state.deadline - Date.now()) / 1000));
    const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
    const ss = String(remaining % 60).padStart(2, '0');
    els.timer.textContent = `${mm}:${ss}`;
    els.timer.dataset.tone = remaining > 0 && remaining <= 60 ? 'low' : '';

    // Auto avance de fase cuando el timer llega a cero?
    // Pedagógicamente en Lotto, el docente inicia la siguiente fase manualmente
    // por lo que solo mostramos el timer en rojo parpadeando.
}

// ─────────────────────────────────────────────
// Acciones del Panel Docente (API POST)
// ─────────────────────────────────────────────

async function executeTransition(action, label) {
    if (!confirm(`¿Quieres pasar a la fase: ${label}?`)) return;
    try {
        stopPolling();
        const data = await api(action, { method: 'POST', body: { activity_id: state.activityId } });
        applyState(data);
        startPolling();
    } catch (err) {
        alert(`Error al cambiar de fase: ${err.message}`);
        startPolling();
    }
}

async function extendTime(action, seconds) {
    try {
        const data = await api(action, { method: 'POST', body: { activity_id: state.activityId, extra_seconds: seconds } });
        if (data.phase_deadline) {
            const serverNow = new Date().getTime(); // aproximado
            state.deadline = Date.now() + seconds * 1000;
        }
        pollState();
    } catch (err) {
        alert(`Error al extender el tiempo: ${err.message}`);
    }
}

async function releaseStudentLogin(studentId, name) {
    if (!confirm(`¿Liberar el ingreso de ${name}? Esto le permitirá volver a iniciar sesión si tuvo un error.`)) return;
    try {
        await api('lotto_release_student_login', { method: 'POST', body: { activity_id: state.activityId, student_id: studentId } });
        pollState();
    } catch (err) {
        alert(err.message);
    }
}

async function executeDrawInitial() {
    try {
        const data = await api('lotto_draw_initial', { method: 'POST', body: { activity_id: state.activityId } });
        applyState(data);
    } catch (err) {
        alert(`Error al iniciar sorteo: ${err.message}`);
    }
}

async function advanceDrawResolution(actionName, label) {
    if (!confirm(`¿Seguro que quieres marcar al estudiante como ${label}? Se sorteará el siguiente.`)) return;
    try {
        const data = await api(actionName, { method: 'POST', body: { activity_id: state.activityId } });
        applyState(data);
    } catch (err) {
        alert(err.message);
    }
}

async function finishActivity() {
    if (!confirm('¿Seguro que quieres finalizar la actividad? Los estudiantes serán desconectados y se cerrará el sorteo.')) return;
    try {
        stopPolling();
        await api('lotto_finish_activity', { method: 'POST', body: { activity_id: state.activityId } });
        window.location.href = `${window.TRIVIAX_BASE ?? ""}/panel/lotto.php`;
    } catch (err) {
        alert(err.message);
        startPolling();
    }
}

// ─────────────────────────────────────────────
// Modal de Evaluación
// ─────────────────────────────────────────────

async function openEvaluationModal() {
    const currentDraw = state.draws.find(d => d.draw_state === 'current');
    if (!currentDraw) {
        alert('No hay ningún estudiante asignado al oral actualmente.');
        return;
    }
    
    // Cambiar texto de carga en el modal
    els.modalTitle.textContent = `Evaluar Oral`;
    els.modalSubtitle.textContent = `Cargando detalles de ${currentDraw.first_name}...`;
    els.modalSecTitle.textContent = '--';
    els.modalSecSummary.textContent = '--';
    els.modalOralQuestion.textContent = '--';
    els.modalExpectedList.replaceChildren();
    els.modalFollowupsList.replaceChildren();
    
    // Generar la rúbrica vacía
    buildRubricCriteriaDOM();
    els.modalQuickRes.value = 'sin_evaluar';
    els.modalNumScore.value = '';
    els.modalComment.value = '';
    
    // Mostrar modal
    els.evalModal.classList.remove('lotto-hidden');
    
    try {
        // Obtener datos del reporte para ver la ficha del estudiante
        const data = await api('lotto_report', { params: { activity_id: state.activityId } });
        const studentDetail = data.students?.find(s => s.student_number === currentDraw.student_number);
        if (studentDetail) {
            state.currentEvalStudent = studentDetail;
            populateModalWithStudentData(studentDetail);
        } else {
            throw new Error('No se encontró la ficha del estudiante en el reporte.');
        }
    } catch (err) {
        alert(`Error al cargar datos del alumno: ${err.message}`);
        closeEvaluationModal();
    }
}

function closeEvaluationModal() {
    els.evalModal.classList.add('lotto-hidden');
    state.currentEvalStudent = null;
}

function buildRubricCriteriaDOM() {
    state.rubricScores = {};
    els.modalRubricContainer.replaceChildren();
    
    if (!rubricData || !Array.isArray(rubricData.criteria)) {
        return;
    }
    
    rubricData.criteria.forEach(c => {
        state.rubricScores[c.id] = 0; // por defecto 0
        
        const row = document.createElement('div');
        row.className = 'rubric-row';
        row.dataset.criterionId = c.id;
        
        const label = document.createElement('span');
        label.className = 'rubric-label';
        label.textContent = c.label;
        
        const options = document.createElement('div');
        options.className = 'rubric-options';
        
        const max = c.max || 3;
        for (let scoreVal = 0; scoreVal <= max; scoreVal++) {
            const opt = document.createElement('button');
            opt.type = 'button';
            opt.className = 'rubric-opt';
            if (scoreVal === 0) opt.classList.add('is-selected');
            opt.textContent = scoreVal;
            opt.dataset.value = scoreVal;
            opt.addEventListener('click', () => {
                row.querySelectorAll('.rubric-opt').forEach(b => b.classList.remove('is-selected'));
                opt.classList.add('is-selected');
                state.rubricScores[c.id] = scoreVal;
                updateRubricTotal();
            });
            options.append(opt);
        }
        
        row.append(label, options);
        els.modalRubricContainer.append(row);
    });
    
    updateRubricTotal();
}

function updateRubricTotal() {
    let sum = 0;
    let max = 0;
    if (rubricData && Array.isArray(rubricData.criteria)) {
        rubricData.criteria.forEach(c => {
            sum += state.rubricScores[c.id] || 0;
            max += c.max || 3;
        });
    }
    els.modalRubricTotal.textContent = `${sum} / ${max}`;
}

function populateModalWithStudentData(student) {
    els.modalSubtitle.textContent = `Estudiante: ${student.student_number} — ${student.full_name || student.first_name}`;
    els.modalSecTitle.textContent = student.section_title || '—';
    els.modalSecSummary.textContent = student.study_text || '—';
    
    // Si escribió respuesta
    if (student.written_response) {
        els.modalSecSummary.textContent += `\n\n[Respuesta Escrita del Estudiante]: ${student.written_response}`;
    }

    els.modalOralQuestion.textContent = student.oral_main_question || '—';

    // Respuestas esperadas
    const expectedFrag = document.createDocumentFragment();
    if (Array.isArray(student.expected_answers) && student.expected_answers.length > 0) {
        student.expected_answers.forEach(ans => {
            const li = document.createElement('li');
            li.textContent = ans;
            expectedFrag.append(li);
        });
    } else {
        const li = document.createElement('li');
        li.textContent = 'No especificadas.';
        expectedFrag.append(li);
    }
    els.modalExpectedList.replaceChildren(expectedFrag);

    // Repreguntas / followups
    const followupsFrag = document.createDocumentFragment();
    if (Array.isArray(student.oral_followups) && student.oral_followups.length > 0) {
        student.oral_followups.forEach(fl => {
            const li = document.createElement('li');
            li.textContent = fl;
            followupsFrag.append(li);
        });
    } else {
        const li = document.createElement('li');
        li.textContent = 'No especificadas.';
        followupsFrag.append(li);
    }
    els.modalFollowupsList.replaceChildren(followupsFrag);
    
    // Pre-cargar valores si ya estaba evaluado
    if (student.quick_result && student.quick_result !== 'sin_evaluar') {
        els.modalQuickRes.value = student.quick_result;
    }
    if (student.numeric_score !== null) {
        els.modalNumScore.value = student.numeric_score;
    }
    if (student.teacher_comment) {
        els.modalComment.value = student.teacher_comment;
    }
    
    // Cargar rúbrica guardada si existe
    if (student.rubric_scores && typeof student.rubric_scores === 'object') {
        Object.entries(student.rubric_scores).forEach(([critId, scoreVal]) => {
            const row = els.modalRubricContainer.querySelector(`[data-criterion-id="${critId}"]`);
            if (row) {
                row.querySelectorAll('.rubric-opt').forEach(b => b.classList.remove('is-selected'));
                const opt = row.querySelector(`[data-value="${scoreVal}"]`);
                if (opt) {
                    opt.classList.add('is-selected');
                    state.rubricScores[critId] = Number(scoreVal);
                }
            }
        });
        updateRubricTotal();
    }
}

async function saveEvaluation() {
    if (!state.currentEvalStudent) return;
    
    const quick_result = els.modalQuickRes.value;
    if (quick_result === 'sin_evaluar') {
        alert('Por favor, selecciona un resultado rápido para el estudiante (Excelente, Correcto, etc.).');
        return;
    }

    const numeric_score = els.modalNumScore.value !== '' ? Number(els.modalNumScore.value) : null;
    const comment = els.modalComment.value.trim();
    
    let sum = 0;
    let max = 0;
    if (rubricData && Array.isArray(rubricData.criteria)) {
        rubricData.criteria.forEach(c => {
            sum += state.rubricScores[c.id] || 0;
            max += c.max || 3;
        });
    }

    const payload = {
        activity_id: state.activityId,
        student_id: state.currentEvalStudent.id || state.draws.find(d => d.draw_state === 'current')?.student_id,
        evaluation: {
            quick_result,
            numeric_score,
            max_score: max,
            teacher_comment: comment,
            rubric_scores: state.rubricScores
        },
        advance_draw: true // Avance automático del sorteo
    };

    if (!payload.student_id) {
        alert('No se pudo identificar el ID del estudiante para guardar la evaluación.');
        return;
    }

    try {
        els.modalBtnSave.disabled = true;
        els.modalBtnSave.textContent = 'Guardando...';
        
        const data = await api('lotto_evaluate_student', { method: 'POST', body: payload });
        closeEvaluationModal();
        applyState(data);
    } catch (err) {
        alert(`Error al guardar la evaluación: ${err.message}`);
    } finally {
        els.modalBtnSave.disabled = false;
        els.modalBtnSave.textContent = 'Registrar y Continuar';
    }
}

// ─────────────────────────────────────────────
// Inicialización y Eventos
// ─────────────────────────────────────────────

function setupEventListeners() {
    // Transiciones generales de fase
    els.actOpenLogin.addEventListener('click', () => executeTransition('lotto_open_login', 'Abrir ingreso'));
    els.actStartStudy.addEventListener('click', () => executeTransition('lotto_start_study', 'Iniciar estudio (15 min)'));
    els.actStartResponse.addEventListener('click', () => executeTransition('lotto_start_response', 'Iniciar preparación de respuestas (5 min)'));
    els.actStartOral.addEventListener('click', () => executeTransition('lotto_start_oral', 'Iniciar ronda oral'));
    els.actFinish.addEventListener('click', finishActivity);
    
    // Timer extensions
    els.actExtendStudy.addEventListener('click', () => extendTime('lotto_extend_study', 120)); // +2 min
    els.actExtendResponse.addEventListener('click', () => extendTime('lotto_extend_response', 60)); // +1 min
    
    // Inicialización del sorteo
    els.actDrawInitial.addEventListener('click', executeDrawInitial);
    
    // Acciones individuales del actual
    els.btnEvaluate.addEventListener('click', openEvaluationModal);
    els.btnPostpone.addEventListener('click', () => advanceDrawResolution('lotto_postpone_student', 'pospuesto'));
    els.btnAbsent.addEventListener('click', () => advanceDrawResolution('lotto_mark_absent', 'ausente'));
    els.btnSkip.addEventListener('click', () => advanceDrawResolution('lotto_draw_next', 'omitido'));

    // Modal
    els.modalClose.addEventListener('click', closeEvaluationModal);
    els.modalBtnCancel.addEventListener('click', closeEvaluationModal);
    els.modalBtnSave.addEventListener('click', saveEvaluation);
}

function init() {
    if (activityId <= 0) return;
    setupEventListeners();
    startPolling();
}

init();
