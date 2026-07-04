(function () {
'use strict';

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const API = (window.TRIVIAX_BASE || '') + 'api.php';
const CSRF = window.TRIVIAX_CSRF_TOKEN || document.body?.dataset.actCsrf || '';

let sessionId = null;
let sessionToken = null;
let board = null;
let state = null;

// Posición (n) actualmente pintada de cada ficha, para animar el trayecto
// casilla por casilla en vez de saltar directo al destino.
const renderedPositions = { blue: null, red: null };
// Generación de animación por lado: invalida un recorrido en curso si llega
// un destino nuevo (evita que dos animaciones se pisen).
const animGen = { blue: 0, red: 0 };
// Duración de cada tramo entre casillas contiguas (ms). Igual al de la
// transición CSS que se fija en runtime → deslizamiento continuo y fluido.
const STEP_MS = 200;

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

function placeToken(side, p) {
    const token = $(`#token-${side}`);
    if (!token) return;
    token.style.left = `${p.x}%`;
    token.style.top = `${p.y}%`;
    if (!token.textContent) token.textContent = side === 'blue' ? 'A' : 'R';
}

// Coloca la ficha en `n` sin animación (primer render, reduced-motion o cuando
// no cambió de casilla).
function snapToken(side, n) {
    const token = $(`#token-${side}`);
    if (!token) return;
    token.style.transition = 'none';
    placeToken(side, pathPoint(side, n));
    // Forzar reflow para que el 'none' tenga efecto antes de restaurar la
    // transición por defecto de la hoja de estilos (para futuros movimientos).
    void token.offsetWidth;
    token.style.transition = '';
    renderedPositions[side] = n;
}

// Recorre la ruta casilla por casilla desde `fromN` hasta `toN`, deslizando de
// forma continua (una transición lineal por tramo, encadenadas).
async function animateTokenAlongPath(side, fromN, toN) {
    const token = $(`#token-${side}`);
    if (!token) return;
    const gen = ++animGen[side];
    token.style.transition = `left ${STEP_MS}ms linear, top ${STEP_MS}ms linear`;
    const dir = toN >= fromN ? 1 : -1;
    for (let n = fromN + dir; dir > 0 ? n <= toN : n >= toN; n += dir) {
        if (gen !== animGen[side]) return; // llegó un destino más nuevo
        placeToken(side, pathPoint(side, n));
        renderedPositions[side] = n;
        await wait(STEP_MS);
    }
}

function api(action, body = null) {
    const options = body
        ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body)) }
        : {};
    return fetch(API + '?action=' + encodeURIComponent(action), options)
        .then((r) => r.json())
        .then((d) => {
            if (!d.ok && !d.success) throw new Error(d.message || d.error || 'Error de red');
            return d;
        });
}

function splitTeam(value, fallback) {
    const members = String(value || '').split(',').map((s) => s.trim()).filter(Boolean).slice(0, 4);
    return members.length ? members : [fallback];
}

function sideName(side) {
    return side === 'blue' ? 'Azul' : 'Rojo';
}

function pathPoint(side, n) {
    return board.paths[side].find((p) => p.n === n) || board.paths[side][0];
}

function renderBoard(newBoard) {
    board = newBoard;
    const errors = window.FootballGoalRaceValidator?.validateBoard(board) || [];
    if (errors.length) throw new Error(errors[0]);

    const svg = $('#football-routes');
    const markers = $('#football-markers');
    svg.innerHTML = '';
    markers.innerHTML = '';

    ['blue', 'red'].forEach((side) => {
        const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        poly.setAttribute('points', board.paths[side].map((p) => `${p.x},${p.y}`).join(' '));
        poly.setAttribute('class', `football-route football-route-${side}`);
        svg.append(poly);

        board.paths[side].forEach((p) => {
            const special = board.specialCells?.[side]?.[p.n];
            const marker = document.createElement('span');
            marker.className = `football-cell football-cell-${side}` + (special ? ` special special-${special.type}` : '');
            marker.style.left = `${p.x}%`;
            marker.style.top = `${p.y}%`;
            marker.textContent = special ? special.label.slice(0, 3).toUpperCase() : String(p.n);
            marker.title = `${sideName(side)} posicion ${p.n}${special ? ' - ' + special.label : ''}`;
            markers.append(marker);
        });
    });
}

function updateTokens() {
    if (!board || !state) return;
    ['blue', 'red'].forEach((side) => {
        const target = state.positions[side];
        const from = renderedPositions[side];
        // Primer render, sin cambio de casilla o reduced-motion → colocar directo.
        if (from === null || from === target || prefersReducedMotion()) {
            snapToken(side, target);
            return;
        }
        // Cambió de casilla → recorrer la ruta paso a paso.
        animateTokenAlongPath(side, from, target);
    });
}

function currentMemberLabel() {
    const side = state.currentSide;
    const team = state.teams?.[side];
    const member = team?.members?.[team.currentIndex || 0]?.display_name || sideName(side);
    return `${sideName(side)} responde: ${member}`;
}

function describePending(pending) {
    if (!pending) return 'Tira el dado para avanzar.';
    if (pending.type === 'normal_question') return `Dado ${pending.dice}: responde para avanzar.`;
    if (pending.type === 'special_question') return `${pending.specialLabel}: jugada especial.`;
    if (pending.type === 'final_shot') return 'Tiro al arco: si aciertas, ganas.';
    return 'Jugada pendiente.';
}

function renderState(payload, message = '') {
    state = payload.state;
    if (payload.board && !board) renderBoard(payload.board);
    $('#score-blue').textContent = state.scores.blue;
    $('#score-red').textContent = state.scores.red;
    $('#pos-blue').textContent = `Pos. ${state.positions.blue}`;
    $('#pos-red').textContent = `Pos. ${state.positions.red}`;
    $('#turn-label').textContent = state.status === 'finished'
        ? `Gol de ${sideName(state.winner_side)}`
        : `Turno ${state.turnNumber}: ${sideName(state.currentSide)}`;
    $('#dice-label').textContent = state.lastDice ? `D${state.diceSides}: ${state.lastDice}` : `D${state.diceSides}`;
    $('#member-label').textContent = state.status === 'finished' ? 'Partido finalizado' : currentMemberLabel();
    $('#football-msg').textContent = message || describePending(state.pendingAction);
    $('#btn-roll').hidden = state.status !== 'playing' || !!state.pendingAction;
    renderQuestion();
    updateTokens();
    if (state.status === 'finished') {
        $('#goal-flash').hidden = false;
        $('#goal-flash').textContent = `Gol academico ${sideName(state.winner_side)}`;
    }
}

function renderQuestion() {
    const pending = state?.pendingAction;
    const box = $('#question-box');
    const options = $('#question-options');
    if (!pending?.question) {
        box.hidden = true;
        options.innerHTML = '';
        return;
    }
    box.hidden = false;
    $('#play-label').textContent = describePending(pending);
    $('#question-prompt').textContent = pending.question.prompt;
    options.innerHTML = '';
    (pending.question.options || []).forEach((option) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'football-option';
        btn.textContent = option;
        btn.addEventListener('click', () => answer(option));
        options.append(btn);
    });
}

async function startGame(e) {
    e.preventDefault();
    $('#football-msg').textContent = 'Creando partido...';
    const payload = {
        project: $('#question-bank')?.value || window.TRIVIAX_PROJECT_SLUG || '',
        diceSides: 6,
        teamAnswerMode: $('#answer-mode').value,
        teams: {
            blue: splitTeam($('#team-blue').value, 'Azul'),
            red: splitTeam($('#team-red').value, 'Rojo'),
        },
    };
    try {
        const res = await api('football_start', payload);
        sessionId = res.session_id;
        sessionToken = res.token;
        $('#football-setup').hidden = true;
        $('#play-panel').hidden = false;
        const bankTitle = res.activity?.title || '';
        renderState(res, bankTitle ? `Partido creado con "${bankTitle}". Tira el dado.` : 'Partido creado. Tira el dado.');
    } catch (err) {
        $('#football-msg').textContent = err.message;
    }
}

// Bancos de preguntas disponibles (proyectos del tablero con preguntas
// de opciones cerradas). La demo incluida queda siempre como fallback.
async function loadQuestionBanks() {
    const select = $('#question-bank');
    if (!select) return;
    try {
        const res = await api('football_projects');
        (res.projects || []).forEach((p) => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.title || p.id}${p.nivel ? ' · ' + p.nivel : ''} (${p.preguntas} preguntas)`;
            select.append(opt);
        });
        if (window.TRIVIAX_PROJECT_SLUG) {
            select.value = window.TRIVIAX_PROJECT_SLUG;
            if (select.value !== window.TRIVIAX_PROJECT_SLUG) select.value = '';
        }
    } catch (err) {
        // Sin bancos externos: se juega con la demo incluida.
    }
}

async function roll() {
    $('#football-msg').textContent = 'Resolviendo dado...';
    const res = await api('football_roll', { session_id: sessionId, token: sessionToken });
    renderState(res);
}

async function answer(value) {
    $('#football-msg').textContent = 'Registrando respuesta...';
    const res = await api('football_answer', {
        session_id: sessionId,
        token: sessionToken,
        answer: value,
        idempotency_key: `${Date.now()}_${Math.random().toString(36).slice(2)}`,
    });
    renderState(res);
}

async function init() {
    const boardRes = await api('football_board');
    renderBoard(boardRes.board);
    updateTokens();
    await loadQuestionBanks();
    $('#football-setup').addEventListener('submit', startGame);
    $('#btn-roll').addEventListener('click', roll);
}

init().catch((err) => {
    $('#football-msg').textContent = err.message;
});
})();
