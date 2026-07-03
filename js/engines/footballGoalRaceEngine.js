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
        const p = pathPoint(side, state.positions[side]);
        const token = $(`#token-${side}`);
        token.style.left = `${p.x}%`;
        token.style.top = `${p.y}%`;
        token.textContent = side === 'blue' ? 'A' : 'R';
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
        diceSides: 6,
        teamAnswerMode: $('#answer-mode').value,
        teams: {
            blue: splitTeam($('#team-blue').value, 'Azul'),
            red: splitTeam($('#team-red').value, 'Rojo'),
        },
    };
    const res = await api('football_start', payload);
    sessionId = res.session_id;
    sessionToken = res.token;
    $('#football-setup').hidden = true;
    $('#play-panel').hidden = false;
    renderState(res, 'Partido creado. Tira el dado.');
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
    $('#football-setup').addEventListener('submit', startGame);
    $('#btn-roll').addEventListener('click', roll);
}

init().catch((err) => {
    $('#football-msg').textContent = err.message;
});
})();
