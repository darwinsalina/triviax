/**
 * Controlador Principal (Main Bootstrapper) de TRIVIAX
 * RediseÃ±ado para soportar arquitectura modular extensible y offline-first
 */

import {
    BOARD_PROFILES,
    DEFAULT_PROJECT,
    PLAYER_COLORS,
    PROJECTS_BASE_PATH,
    QUESTION_TIMEOUT_SECONDS,
    SCORE_TARGET_OPTIONS,
    getBoardProfile
} from './config.js';
import { GameEngine } from './engines/gameEngine.js';
import { ChallengeEngine } from './engines/challengeEngine.js';
import { BoardEngine } from './engines/boardEngine.js';
import { Dice } from './dice.js';
import { UIManager } from './ui.js';
import { ApiClient } from './services/apiClient.js';
import { StorageService } from './services/storageService.js';
import { ScoringEngine } from './engines/scoringEngine.js';
import { normalizeProjectChallenges, validateProjectData } from './validators/challengeValidators.js';
import { delay, escapeHTML, qs, qsa } from './utils.js';
import { playHomepageFanfareSound, isSoundEnabled, setSoundEnabled } from './sound.js';

// Instanciar controladores
const ui = new UIManager();
const game = new GameEngine();
const challengeEngine = new ChallengeEngine();
let board = null;
let dice = null;

// Variables de estado del proyecto activo
let activeProjectName = DEFAULT_PROJECT;
let activeProjectMetadata = null;
let currentPlayersSetup = [];
let currentBoardProfile = getRandomBoardProfile();
let availableProjectOptions = [];
const SPECIAL_ACTIVITY_MODES = [
    {
        id: 'special-jigsaw',
        title: '🧩 Puzles',
        author: 'Arma la imagen pieza a pieza',
        nivel: 'Modalidades',
        href: 'jigsaw.php',
        actionLabel: 'Abrir catálogo de puzles',
        keywords: 'puzle puzles puzzle jigsaw imagen piezas'
    },
    {
        id: 'special-etiquetar',
        title: '🏷️ Etiquetado',
        author: 'Ubica cada etiqueta sobre la imagen',
        nivel: 'Modalidades',
        href: 'etiquetar.php',
        actionLabel: 'Abrir catálogo de etiquetado',
        keywords: 'etiquetar etiquetado etiquetas imagen'
    }
];
let activityCatalogCategory = 'all';
let activityCatalogQuery = '';
let shouldOpenProjectFromUrl = false;
let shouldOpenLiveFromUrl = false;
let pendingSessionCodeFromUrl = '';

function getRandomBoardProfile() {
    if (!Array.isArray(BOARD_PROFILES) || BOARD_PROFILES.length === 0) {
        return getBoardProfile();
    }
    return BOARD_PROFILES[Math.floor(Math.random() * BOARD_PROFILES.length)];
}

function extractSessionCodeFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const namedCode = params.get('codigo') || params.get('code') || params.get('sesion') || params.get('session');
    if (namedCode && /^[a-zA-Z0-9]{6}$/.test(namedCode)) {
        return namedCode.toUpperCase();
    }

    const rawQuery = decodeURIComponent((window.location.search || '').replace(/^\?/, '')).trim();
    if (!rawQuery || rawQuery.includes('=')) {
        return '';
    }
    if (/^[a-zA-Z0-9]{6}$/.test(rawQuery)) {
        return rawQuery.toUpperCase();
    }
    if (rawQuery.length >= 18) {
        const embeddedCode = rawQuery.slice(12, 18);
        if (/^[a-zA-Z0-9]{6}$/.test(embeddedCode)) {
            return embeddedCode.toUpperCase();
        }
    }
    return '';
}

function openLiveSessionSetup(sessionCode = '') {
    activeProjectMetadata = null;
    activeProjectName = '';
    qs('#detail-title').innerText = 'Partida en vivo';
    qs('#project-dropdown').value = '';
    ui.showScreen('screen-players-setup');
    const codeGroup = qs('.session-code-group');
    if (codeGroup) {
        codeGroup.style.display = '';
    }
    const codeInput = qs('#session-code-input');
    if (codeInput) {
        codeInput.value = sessionCode;
        codeInput.focus();
        if (sessionCode) {
            codeInput.select();
        }
    }
}

// -- v4.0: estado de sesion BD (null si no hay sesion activa) --
let activeSesionId    = null;   // ID de la sesion en BD
let activeJugadorIds  = [];     // [jugadorId_p1, jugadorId_p2, ...]
let activePlayerTokens = [];    // [playerToken_p1, playerToken_p2, ...]
let activeTurnCounter = 0;      // contador de turnos para orden_turno
let activeTurnoId     = null;   // ID del turno actual en sesion_turnos (v4.5)

// -- v4.5: polling de estado de sesion --
let _sessionPollInterval = null;

function _startSessionPoll(sesionId) {
    _stopSessionPoll();
    _sessionPollInterval = setInterval(async () => {
        if (activeSesionId !== sesionId) { _stopSessionPoll(); return; }
        const jugadorId  = activeJugadorIds[0];
        const playerToken = activePlayerTokens[0]
            || (jugadorId ? localStorage.getItem(`triviax_session_${sesionId}_player_${jugadorId}_token`) : '')
            || '';
        if (!jugadorId || !playerToken) return;
        try {
            const state = await ApiClient.sessionState(sesionId, jugadorId, playerToken);
            if (state?.data?.estado === 'finished' || state?.estado === 'finished') {
                _stopSessionPoll();
                return;
            }
            const data = state?.data || state;
            if (data && data.jugadores) {
                let anyChanged = false;
                data.jugadores.forEach(dbPlayer => {
                    const dbId = parseInt(dbPlayer.id);
                    const localIndex = activeJugadorIds.indexOf(dbId);
                    if (localIndex !== -1) {
                        const localPlayer = game.players[localIndex];
                        const isCurrentPlayer = (localIndex === game.currentPlayerIndex);
                        const isLocalTurnActive = isCurrentPlayer && qs('#btn-roll-dice')?.hasAttribute('disabled');
                        
                        if (!isLocalTurnActive) {
                            const dbPos = parseInt(dbPlayer.posicion);
                            const dbScore = parseInt(dbPlayer.puntaje);
                            if (localPlayer.position !== dbPos) {
                                localPlayer.position = dbPos;
                                anyChanged = true;
                            }
                            if (localPlayer.score !== dbScore) {
                                localPlayer.score = dbScore;
                                anyChanged = true;
                            }
                        }
                    }
                });
                const dbCurrentPlayerId = parseInt(data.sesion?.current_turn_player_id);
                if (dbCurrentPlayerId) {
                    const dbCurrentPlayerIndex = activeJugadorIds.indexOf(dbCurrentPlayerId);
                    if (dbCurrentPlayerIndex !== -1 && game.currentPlayerIndex !== dbCurrentPlayerIndex) {
                        game.currentPlayerIndex = dbCurrentPlayerIndex;
                        anyChanged = true;
                    }
                }
                if (anyChanged) {
                    board.updateTokens(game.players);
                    updateGameUI();
                }
            }
        } catch (_) {}
    }, 3000);
}

function _stopSessionPoll() {
    if (_sessionPollInterval) {
        clearInterval(_sessionPollInterval);
        _sessionPollInterval = null;
    }
}

function _getPlayerToken(player) {
    const jugadorId = activeJugadorIds[player.id - 1];
    return activePlayerTokens[player.id - 1]
        || (jugadorId ? localStorage.getItem(`triviax_session_${activeSesionId}_player_${jugadorId}_token`) : '')
        || '';
}

// -- v4.6: control de pestana activa para sesiones BD --
let _sessionTabBlocked = false; // true = esta pestana esta en modo lectura
let _sessionChannel = null;     // BroadcastChannel de la sesion activa

function _openSessionChannel(sesionId) {
    if (!window.BroadcastChannel) return;
    const channel = new BroadcastChannel(`triviax_session_${sesionId}`);
    const tabKey  = `triviax_tab_${sesionId}`;

    const takeover = () => {
        localStorage.setItem(tabKey, Date.now().toString());
        channel.postMessage({ type: 'tab_claimed' });
        _sessionTabBlocked = false;
    };

    const existing = localStorage.getItem(tabKey);
    const stale = !existing || (Date.now() - parseInt(existing, 10)) > 12000;

    if (stale) {
        takeover();
    } else {
        _sessionTabBlocked = true;
        ui.addLog('⚠️ Esta sesión ya está abierta en otra pestaña. Esta pestaña está en modo solo lectura.', 'system');
    }

    // Heartbeat: renueva el timestamp cada 5 s mientras la pestaña esté activa
    const hb = setInterval(() => {
        if (!_sessionTabBlocked && activeSesionId === sesionId) {
            localStorage.setItem(tabKey, Date.now().toString());
        } else if (activeSesionId !== sesionId) {
            clearInterval(hb);
        }
    }, 5000);

    window.addEventListener('beforeunload', () => {
        localStorage.removeItem(tabKey);
        channel.postMessage({ type: 'tab_released' });
        channel.close();
    });

    channel.onmessage = (ev) => {
        if (ev.data?.type === 'tab_claimed' && !_sessionTabBlocked) {
            _sessionTabBlocked = true;
            ui.addLog('⚠️ Otra pestaña tomó el control de esta sesión. Acciones bloqueadas.', 'system');
            const rollBtn = document.querySelector('#btn-roll-dice');
            if (rollBtn) rollBtn.setAttribute('disabled', 'true');
        }
        if (ev.data?.type === 'tab_released' && _sessionTabBlocked) {
            takeover();
            ui.addLog('✅ La otra pestaña se cerró. Esta pestaña retoma el control.', 'system');
            const rollBtn = document.querySelector('#btn-roll-dice');
            if (rollBtn && !document.querySelector('#screen-game')?.classList.contains('hidden')) {
                rollBtn.removeAttribute('disabled');
            }
        }
    };

    _sessionChannel = channel;
}

function _closeSessionChannel(sesionId) {
    if (_sessionChannel) {
        _sessionChannel.close();
        _sessionChannel = null;
    }
    if (sesionId) localStorage.removeItem(`triviax_tab_${sesionId}`);
    _sessionTabBlocked = false;
}


if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', () => {
        initApp();
    });
} else {
    initApp();
}

/**
 * InicializaciÃ³n general de eventos y carga inicial
 */
async function initApp() {
    setupIntroSplash();

    board = new BoardEngine(qs('#board-container'));
    dice = new Dice(qs('#dice-element'));

    // Detectar si hay un proyecto o modo en vivo en la URL
    const urlParams = new URLSearchParams(window.location.search);
    const projectParam = urlParams.get('project');
    if (projectParam && /^[a-zA-Z0-9_-]+$/.test(projectParam)) {
        activeProjectName = projectParam;
        shouldOpenProjectFromUrl = true;
    }
    pendingSessionCodeFromUrl = extractSessionCodeFromUrl();
    if (urlParams.get('live') === '1' || pendingSessionCodeFromUrl) {
        shouldOpenLiveFromUrl = true;
    }
    setupScreenNavigationEvents();
    setupPlayerSelectionTabs();
    const timerReleasedCheckbox = qs('#timer-released-checkbox');
    const baseTimeRadios = qsa('input[name="base-time-option"]');
    const timeSelectorContainer = qs('.time-selector-container');
    if (timerReleasedCheckbox && baseTimeRadios.length > 0) {
        const syncTimerRadiosState = () => {
            baseTimeRadios.forEach(radio => {
                radio.disabled = timerReleasedCheckbox.checked;
            });
            if (timeSelectorContainer) {
                timeSelectorContainer.classList.toggle('disabled', timerReleasedCheckbox.checked);
            }
        };
        timerReleasedCheckbox.addEventListener('change', syncTimerRadiosState);
        syncTimerRadiosState();
    }

    // Cargar tableros personalizados ANTES de construir el desplegable de
    // tableros, para que aparezcan junto a los de fábrica (oca/monopoly/circular).
    try {
        const response = await fetch('api.php?action=list_boards');
        const data = await response.json();
        if (data && data.success && Array.isArray(data.boards)) {
            data.boards.forEach(customBoard => {
                if (!BOARD_PROFILES.some(b => b.id === customBoard.id)) {
                    BOARD_PROFILES.push(customBoard);
                }
            });
        }
    } catch (err) {
        console.error('No se pudieron cargar los tableros personalizados:', err);
    }

    setupBoardAndVictoryControls();

    // Sincronizar re-nombres interactivos
    ui.onPlayerRename = (player) => {
        const idx = player.id - 1;
        if (currentPlayersSetup[idx]) {
            currentPlayersSetup[idx].name = player.name;
        }
        ui.updateCurrentPlayerBadge(game.getCurrentPlayer());
        board.updateTokens(game.players);
        updateGameUI();
        ui.addLog(`El jugador fue renombrado a: ${player.name}`);
    };

    // Sincronizar reportes locales guardados sin red si estamos online
    if (navigator.onLine) {
        syncPendingReportsOffline();
    }
    window.addEventListener('online', syncPendingReportsOffline);

    // Reproducir la fanfarria de la homepage en el primer clic (debido a las restricciones de reproducciÃ³n automÃ¡tica)
    const playFanfareOnFirstInteraction = () => {
        const homeScreen = document.getElementById('screen-project-select');
        if (homeScreen && homeScreen.classList.contains('active')) {
            playHomepageFanfareSound();
        }
    };
    document.body.addEventListener('click', playFanfareOnFirstInteraction, { once: true });

    // Configurar botones de sonido y su sincronizaciÃ³n
    const handleSoundToggle = (e) => {
        e.stopPropagation(); // Evitar que el clic en el botÃ³n de la homepage desencadene la fanfarria
        const current = isSoundEnabled();
        setSoundEnabled(!current);
        updateSoundButtonsUI();
    };

    const homeSoundBtn = qs('#btn-home-sound');
    const mobileSoundBtn = qs('#btn-mobile-sound');
    const boardSoundBtn = qs('#btn-toggle-sound');

    if (homeSoundBtn) homeSoundBtn.addEventListener('click', handleSoundToggle);
    if (mobileSoundBtn) mobileSoundBtn.addEventListener('click', handleSoundToggle);
    if (boardSoundBtn) boardSoundBtn.addEventListener('click', handleSoundToggle);

    updateSoundButtonsUI();

    // Carga de proyectos desde la API o cachÃ© local
    await loadProjectsList();
}

function setupIntroSplash() {
    const splash = document.getElementById('intro-splash');
    if (!splash) return;

    const finishSplash = () => {
        splash.classList.add('is-finished');
        splash.setAttribute('aria-hidden', 'true');
    };

    splash.addEventListener('animationend', (event) => {
        if (event.animationName === 'splashFadeOut') {
            finishSplash();
        }
    });

    // Respaldo: la coreografía hélice es crecer (2.4s) + sostener + desvanecer
    // (delay 3.3s + 0.7s = 4.0s). El cierre real lo dispara 'splashFadeOut'.
    window.setTimeout(finishSplash, 4500);
}

function setupBoardAndVictoryControls() {
    const boardProfileSelect = qs('#board-profile-select');
    const boardProfileHelp = qs('#board-profile-help');
    const victoryModeSelect = qs('#victory-mode-select');
    const scoreTargetConfig = qs('#score-target-config');
    const victoryModeHelp = qs('#victory-mode-help');
    if (!boardProfileSelect || !victoryModeSelect || !scoreTargetConfig || !victoryModeHelp) return;

    boardProfileSelect.innerHTML = '';
    BOARD_PROFILES.forEach(profile => {
        const option = document.createElement('option');
        option.value = profile.id;
        option.innerText = `${profile.label} - ${profile.size} casillas`;
        option.selected = profile.id === currentBoardProfile.id;
        boardProfileSelect.appendChild(option);
    });

    const helpByMode = {
        race: 'Gana quien llega primero a la ultima casilla.',
        exact: 'Para ganar hay que caer exactamente en la meta. Si el dado se pasa, la ficha rebota hacia atras.',
        points: 'Gana quien alcanza primero el puntaje meta. Al completar una vuelta, continua desde la casilla 1 y suma un bono.'
    };

    const update = () => {
        currentBoardProfile = getBoardProfile(boardProfileSelect.value);
        const allowedModes = currentBoardProfile.allowedVictoryModes;

        Array.from(victoryModeSelect.options).forEach(option => {
            option.disabled = !allowedModes.includes(option.value);
        });

        if (!allowedModes.includes(victoryModeSelect.value)) {
            victoryModeSelect.value = currentBoardProfile.defaultVictoryMode;
        }

        victoryModeSelect.disabled = allowedModes.length === 1;
        if (boardProfileHelp) {
            boardProfileHelp.innerText = currentBoardProfile.description;
        }

        // El switch de "mostrar recorrido" solo aplica a tableros pelados
        const traceToggle = qs('#board-trace-toggle');
        if (traceToggle) {
            traceToggle.classList.toggle('hidden', !currentBoardProfile.bareBoard);
        }

        const mode = victoryModeSelect.value;
        scoreTargetConfig.classList.toggle('hidden', mode !== 'points');
        victoryModeHelp.innerText = helpByMode[mode] || helpByMode.race;
    };

    boardProfileSelect.addEventListener('change', update);
    victoryModeSelect.addEventListener('change', update);
    update();
}

/**
 * Aplica el bloqueo de tablero definido por la actividad.
 * Si la actividad fija un tablero (lockedBoardId), oculta el desplegable y lo
 * deja seleccionado de forma fija; si no, restaura la elección libre.
 */
function applyBoardLock() {
    const select = qs('#board-profile-select');
    if (!select) return;

    const group   = qs('#board-profile-group');
    const note    = qs('#board-locked-note');
    const nameEl  = qs('#board-locked-name');

    const lockedId      = activeProjectMetadata?.lockedBoardId || null;
    const lockedProfile = lockedId ? BOARD_PROFILES.find(b => b.id === lockedId) : null;

    if (lockedProfile) {
        select.value = lockedProfile.id;
        group?.classList.add('hidden');
        note?.classList.remove('hidden');
        if (nameEl) nameEl.textContent = lockedProfile.label;
    } else {
        if (lockedId) {
            console.warn(`Tablero fijado "${lockedId}" no encontrado; se permite elección libre.`);
        }
        group?.classList.remove('hidden');
        note?.classList.add('hidden');
    }

    // Refresca modalidad de victoria, ayuda y el switch de recorrido
    select.dispatchEvent(new Event('change'));
}
/**
 * Sincroniza los reportes locales guardados offline al recuperar la conexiÃ³n
 */
async function syncPendingReportsOffline() {
    try {
        const pending = await StorageService.getPendingReports();
        if (pending.length === 0) return;

        console.log(`[Offline Sync] Sincronizando ${pending.length} reporte(s) pendiente(s)...`);
        for (const report of pending) {
            const res = await ApiClient.sendReport(report);
            if (res && res.success) {
                await StorageService.markReportAsSynced(report.id || report.timestamp);
                console.log(`[Offline Sync] Reporte #${report.id || report.timestamp} sincronizado.`);
            }
        }
    } catch (err) {
        console.warn('[Offline Sync] Error al sincronizar reportes pendientes:', err);
    }
}

function getPlayCounts() {
    try {
        return JSON.parse(localStorage.getItem('triviax_project_play_counts') || '{}');
    } catch (_) {
        return {};
    }
}

function recordProjectPlay(projectName) {
    if (!projectName) return;
    try {
        const counts = getPlayCounts();
        counts[projectName] = (parseInt(counts[projectName], 10) || 0) + 1;
        localStorage.setItem('triviax_project_play_counts', JSON.stringify(counts));
    } catch (_) {
        // El juego no depende del historial local de uso.
    }
}

function normalizeProjectOption(project) {
    if (typeof project === 'string') {
        return {
            id: project,
            title: project,
            author: '',
            nivel: 'General',
            date: '',
            updatedAt: 0
        };
    }

    const id = project.id || project.folder || project.name;
    return {
        id,
        title: project.title || id,
        author: project.author || '',
        nivel: project.nivel || 'General',
        date: project.date || '',
        updatedAt: parseInt(project.updated_at || project.updatedAt || '0', 10) || 0
    };
}

function projectMatchesCatalogFilters(project) {
    const categoryMatch = activityCatalogCategory === 'all'
        || (project.nivel || 'General').trim().toLowerCase() === activityCatalogCategory;
    const haystack = [
        project.title,
        project.id,
        project.author,
        project.nivel || 'General'
    ].join(' ').toLowerCase();
    const queryMatch = !activityCatalogQuery || haystack.includes(activityCatalogQuery);
    return categoryMatch && queryMatch;
}

function renderProjectCard(project, variant = 'default') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `activity-card ${variant === 'featured' ? 'activity-card--featured' : ''}`;
    button.dataset.projectId = project.id;
    if (project.id === activeProjectName && activeProjectMetadata) {
        button.classList.add('selected');
    }

    const counts = getPlayCounts();
    const plays = parseInt(counts[project.id], 10) || 0;
    const level = project.nivel || 'General';

    button.innerHTML = `
        <span class="activity-card__level">${escapeHTML(level)}</span>
        <strong>${escapeHTML(project.title || project.id)}</strong>
        <span class="activity-card__meta">${escapeHTML(project.author || 'Docente no indicado')}</span>
        <span class="activity-card__plays">${plays > 0 ? `${plays} partida${plays === 1 ? '' : 's'}` : 'Lista para jugar'}</span>
    `;

    button.addEventListener('click', () => selectProjectFromCatalog(project.id));
    return button;
}

function specialActivityMatchesCatalogFilters(activity) {
    const categoryMatch = activityCatalogCategory === 'all' || activityCatalogCategory === 'modalidades';
    const haystack = [activity.title, activity.author, activity.nivel, activity.keywords || '', 'visual']
        .join(' ')
        .toLowerCase();
    return categoryMatch && (!activityCatalogQuery || haystack.includes(activityCatalogQuery));
}

function renderSpecialActivityCard(activity) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'activity-card activity-card--featured activity-card--special';
    button.innerHTML = `
        <span class="activity-card__level">Modalidad visual</span>
        <strong>${escapeHTML(activity.title)}</strong>
        <span class="activity-card__meta">${escapeHTML(activity.author)}</span>
        <span class="activity-card__plays">${escapeHTML(activity.actionLabel)} →</span>
    `;
    button.addEventListener('click', () => {
        window.location.href = activity.href;
    });
    return button;
}

function renderActivityCatalog() {
    const categoryList = qs('#activity-category-list');
    const allGrid = qs('#activities-grid');
    const popularGrid = qs('#popular-activities-grid');
    const newGrid = qs('#new-activities-grid');
    const specialGrid = qs('#special-activities-grid');
    const emptyState = qs('#catalog-empty-state');
    if (!categoryList || !allGrid || !popularGrid || !newGrid || !specialGrid || !emptyState) return;

    const categories = [...new Set(availableProjectOptions.map(project => (project.nivel || 'General').trim()))]
        .sort((a, b) => a.localeCompare(b, 'es'));

    categoryList.innerHTML = '';
    [
        { label: 'Todas', value: 'all' },
        { label: 'Modalidades visuales', value: 'modalidades' },
        ...categories.map(label => ({ label, value: label.trim().toLowerCase() }))
    ].forEach(category => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = `category-chip ${activityCatalogCategory === category.value ? 'active' : ''}`;
        chip.dataset.category = category.value;
        chip.textContent = category.label;
        chip.addEventListener('click', () => {
            activityCatalogCategory = category.value;
            renderActivityCatalog();
        });
        categoryList.appendChild(chip);
    });

    const filtered = availableProjectOptions.filter(projectMatchesCatalogFilters);
    const specialFiltered = SPECIAL_ACTIVITY_MODES.filter(specialActivityMatchesCatalogFilters);
    specialGrid.innerHTML = '';
    specialFiltered.forEach(activity => specialGrid.appendChild(renderSpecialActivityCard(activity)));
    allGrid.innerHTML = '';
    filtered.forEach(project => allGrid.appendChild(renderProjectCard(project)));

    const counts = getPlayCounts();
    const popular = [...filtered]
        .sort((a, b) => {
            const playDiff = (parseInt(counts[b.id], 10) || 0) - (parseInt(counts[a.id], 10) || 0);
            return playDiff || a.title.localeCompare(b.title, 'es');
        })
        .slice(0, 4);
    popularGrid.innerHTML = '';
    popular.forEach(project => popularGrid.appendChild(renderProjectCard(project, 'featured')));

    const recent = [...filtered]
        .sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0) || a.title.localeCompare(b.title, 'es'))
        .slice(0, 4);
    newGrid.innerHTML = '';
    recent.forEach(project => newGrid.appendChild(renderProjectCard(project, 'featured')));

    qs('#popular-count').innerText = popular.length;
    qs('#new-count').innerText = recent.length;
    qs('#special-count').innerText = specialFiltered.length;
    qs('#all-count').innerText = filtered.length;
    emptyState.classList.toggle('hidden', filtered.length + specialFiltered.length > 0);

    const popularSection = qs('section[data-section="popular"]');
    if (popularSection) {
        popularSection.style.display = popular.length > 0 ? '' : 'none';
    }
    const newSection = qs('section[data-section="new"]');
    if (newSection) {
        newSection.style.display = recent.length > 0 ? '' : 'none';
    }
    const specialSection = qs('section[data-section="special"]');
    if (specialSection) {
        specialSection.style.display = specialFiltered.length > 0 ? '' : 'none';
    }
}

function updateHiddenProjectDropdown(projectOptions) {
    const dropdown = qs('#project-dropdown');
    if (!dropdown) return;
    dropdown.innerHTML = '';

    if (projectOptions.length === 0) {
        dropdown.innerHTML = '<option value="">No hay proyectos disponibles</option>';
        return;
    }

    projectOptions.forEach(proj => {
        const opt = document.createElement('option');
        opt.value = proj.id;
        opt.innerText = proj.title;
        opt.title = proj.id;
        if (proj.id === activeProjectName) {
            opt.selected = true;
        }
        dropdown.appendChild(opt);
    });
}

async function selectProjectFromCatalog(projectName) {
    if (!projectName) return;
    await loadProjectData(projectName);
    updateHiddenProjectDropdown(availableProjectOptions);
    renderActivityCatalog();
}

/**
 * Carga la lista de proyectos para el catálogo de actividades.
 */
async function loadProjectsList() {
    try {
        let projects = [];
        try {
            projects = await ApiClient.listProjects();
        } catch (err) {
            if (navigator.onLine) {
                throw err;
            }
            projects = await StorageService.getLocalProjectNames();
        }

        availableProjectOptions = projects
            .map(normalizeProjectOption)
            .filter(project => project.id);

        updateHiddenProjectDropdown(availableProjectOptions);

        const selectedProjectExists = shouldOpenProjectFromUrl
            && availableProjectOptions.some(project => project.id === activeProjectName);
        if (!selectedProjectExists) {
            activeProjectName = '';
        }

        renderActivityCatalog();
        qs('#btn-go-to-catalog').removeAttribute('disabled');

        if (shouldOpenLiveFromUrl) {
            openLiveSessionSetup(pendingSessionCodeFromUrl);
        } else if (activeProjectName) {
            await selectProjectFromCatalog(activeProjectName);
            ui.showScreen('screen-activity-catalog');
        }

    } catch (err) {
        ui.showError(`Error de inicialización: ${err.message}`, () => loadProjectsList());
    }
}

/**
 * Carga los desafÃ­os de un proyecto
 * @param {string} projectName 
 */
async function loadProjectData(projectName) {
    activeProjectName = projectName;
    activeProjectMetadata = null;
    qs('#btn-go-to-setup').setAttribute('disabled', 'true');
    
    try {
        let projectData = null;
        try {
            projectData = await ApiClient.getProjectData(projectName);
            // Guardar en almacenamiento offline local para respaldar
            await StorageService.saveLocalProject(projectName, projectData);
        } catch (err) {
            if (navigator.onLine) {
                throw err;
            }
            projectData = await StorageService.getLocalProject(projectName);
        }

        if (!projectData) {
            throw new Error('El proyecto no se encuentra en el servidor ni está guardado localmente en caché.');
        }

        // Configurar el motor de desafÃ­os
        const validationErrors = validateProjectData(projectData);
        if (validationErrors.length > 0) {
            throw new Error(validationErrors.slice(0, 5).join(' | '));
        }

        projectData.questions = normalizeProjectChallenges(projectData.questions || projectData.challenges || []);
        challengeEngine.init(projectData.questions);
        activeProjectMetadata = projectData.metadata;

        // Tablero fijado por la actividad (si lo hay): bloquea la elección.
        if (activeProjectMetadata) {
            activeProjectMetadata.lockedBoardId = projectData.board?.lockedId || null;
        }
        applyBoardLock();

        // Guardar en la UI para la pantalla de diagnÃ³stico tÃ©cnico
        ui.activeProjectData = {
            name: projectName,
            metadata: projectData.metadata,
            questions: projectData.questions
        };

        ui.updateProjectDetails(projectData.metadata);

    } catch (err) {
        ui.showError(`Error al cargar el proyecto "${projectName}": ${err.message}`, () => loadProjectData(projectName));
    }
}

/**
 * Enlaza los botones de navegaciÃ³n entre pantallas
 */
function setupScreenNavigationEvents() {
    qs('#btn-go-to-catalog').addEventListener('click', () => {
        ui.showScreen('screen-activity-catalog');
    });

    const liveBtn = qs('#btn-go-to-live');
    if (liveBtn) {
        liveBtn.addEventListener('click', () => {
            openLiveSessionSetup();
        });
    }

    qs('#btn-open-manuals').addEventListener('click', () => {
        ui.showScreen('screen-manuals');
    });

    qs('#btn-manuals-back-home').addEventListener('click', () => {
        ui.showScreen('screen-project-select');
    });

    qs('#btn-catalog-back-home').addEventListener('click', () => {
        ui.showScreen('screen-project-select');
    });

    const searchInput = qs('#activity-search');
    const searchBtn = qs('#btn-activity-search');

    const executeSearch = () => {
        if (searchInput) {
            activityCatalogQuery = searchInput.value.trim().toLowerCase();
            renderActivityCatalog();
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            executeSearch();
        });
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                executeSearch();
            }
        });
    }

    if (searchBtn) {
        searchBtn.addEventListener('click', (e) => {
            e.preventDefault();
            executeSearch();
        });
    }

    qs('#btn-go-to-setup').addEventListener('click', () => {
        if (!activeProjectMetadata) {
            alert('Selecciona una actividad antes de continuar.');
            ui.showScreen('screen-activity-catalog');
            return;
        }
        ui.showScreen('screen-players-setup');
    });

    qs('#btn-back-to-projects').addEventListener('click', () => {
        if (!activeProjectMetadata) {
            ui.showScreen('screen-project-select');
        } else {
            ui.showScreen('screen-activity-catalog');
        }
    });

    qs('#players-form').addEventListener('submit', (e) => {
        e.preventDefault();
        startGameFlow();
    });

    // Botón junto a la casilla de código: une a la sesión y entra directo a su actividad
    const joinByCodeBtn = qs('#btn-join-by-code');
    if (joinByCodeBtn) {
        joinByCodeBtn.addEventListener('click', () => {
            const codeInput = qs('#session-code-input');
            const code = codeInput ? codeInput.value.trim().toUpperCase() : '';
            if (code.length !== 6) {
                alert('Ingresa el código de sesión de 6 caracteres que te dio el docente.');
                codeInput?.focus();
                return;
            }
            // Forzar la actividad de ESTA sesión: si había una preseleccionada del
            // catálogo, la descartamos para que el código mande (y un código inválido
            // dé error en vez de caer en una actividad equivocada).
            activeProjectMetadata = null;
            activeProjectName = '';
            startGameFlow();
        });
        // Enter dentro de la casilla equivale a tocar el botón
        const codeInput = qs('#session-code-input');
        if (codeInput) {
            codeInput.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    joinByCodeBtn.click();
                }
            });
        }
    }

    qs('#btn-roll-dice').addEventListener('click', () => {
        handleDiceRoll();
    });

    qs('#btn-restart-game').addEventListener('click', () => {
        if (confirm('¿Deseas reiniciar la partida actual? Se perderá el avance.')) {
            resetCurrentGame();
        }
    });

    const mobileRestartBtn = qs('#btn-mobile-restart');
    if (mobileRestartBtn) {
        mobileRestartBtn.addEventListener('click', () => {
            if (confirm('¿Deseas reiniciar la partida actual?')) {
                resetCurrentGame();
            }
        });
    }

    const exitBtn = qs('#btn-exit-game');
    if (exitBtn) {
        exitBtn.addEventListener('click', () => {
            if (confirm('¿Deseas salir y volver al catálogo de actividades?')) {
                ui.showScreen('screen-activity-catalog');
            }
        });
    }

    const mobileExitBtn = qs('#btn-mobile-exit');
    if (mobileExitBtn) {
        mobileExitBtn.addEventListener('click', () => {
            if (confirm('¿Deseas salir y volver a la pantalla inicial?')) {
                ui.showScreen('screen-project-select');
            }
        });
    }
}

function setupPlayerSelectionTabs() {
    const tabs = qsa('.btn-count');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const count = parseInt(tab.getAttribute('data-count'));
            ui.renderPlayersSetup(count);
        });
    });
    
    ui.renderPlayersSetup(2);
}

/**
 * Arranca la partida, inicializa tablero y jugadores
 */
async function startGameFlow() {
    const sessionCodeInput = qs('#session-code-input');
    const sessionCode = sessionCodeInput ? sessionCodeInput.value.trim().toUpperCase() : '';

    if (!activeProjectMetadata && sessionCode === '') {
        alert('Selecciona una actividad o ingresa un código de sesión válido para comenzar.');
        return;
    }

    const activeTab = qs('.btn-count.active');
    const count = parseInt(activeTab.getAttribute('data-count'));
    const rows = qsa('.player-input-row');
    const playerSetupList = [];

    const colors = PLAYER_COLORS.map(color => color.id);

    for (let i = 0; i < count; i++) {
        const input = qs('input', rows[i]);
        playerSetupList.push({
            name: input.value.trim() !== '' ? input.value.trim() : `Jugador ${i + 1}`,
            colorId: colors[i]
        });
    }

    currentPlayersSetup = playerSetupList;
    recordProjectPlay(activeProjectName);

    // ── v4.0: unirse a sesión BD si se ingresó un código ──────────

    // ── v4.0: unirse a sesión BD si se ingresó un código ──────────
    _stopSessionPoll();
    _closeSessionChannel(activeSesionId);
    activeSesionId   = null;
    activeJugadorIds = [];
    activePlayerTokens = [];
    activeTurnCounter = 0;
    activeTurnoId    = null;

    if (sessionCode !== '') {
        const playerNames = playerSetupList.map(p => p.name);
        try {
            const sesData = await ApiClient.unirseASesion(sessionCode, playerNames);
            if (sesData.success) {
                activeSesionId   = sesData.sesion_id;
                activeJugadorIds = sesData.jugador_ids;
                activePlayerTokens = (sesData.jugadores || []).map((j) => j.player_token || '');
                (sesData.jugadores || []).forEach((j) => {
                    if (j.jugador_id && j.player_token) {
                        localStorage.setItem(`triviax_session_${activeSesionId}_player_${j.jugador_id}_token`, j.player_token);
                    }
                });
                _openSessionChannel(activeSesionId);
                _startSessionPoll(activeSesionId);
                console.info(`[TRIVIAX v5.0] Sesión activa: ${sesData.sesion_nombre} (ID ${activeSesionId})`);
                
                if (!activeProjectMetadata) {
                    const proyectoId = sesData.proyecto_id;
                    if (!proyectoId) {
                        throw new Error('La sesión no está vinculada a ningún proyecto válido.');
                    }
                    await loadProjectData(proyectoId);
                }
            } else {
                throw new Error(sesData.error || 'Código no válido');
            }
        } catch (e) {
            console.warn('[TRIVIAX v5.0] No se pudo unir a la sesión:', e.message);
            if (!activeProjectMetadata) {
                alert(`No se pudo unir a la sesión en vivo: ${e.message}`);
                return;
            }
            ui.addLog(`⚠️ Código de sesión "${sessionCode}" no válido o sesión no disponible. La partida se jugará en modo local.`, 'system');
        }
    }

    if (!activeProjectMetadata) {
        alert('Error: No se pudo cargar el proyecto de la sesión.');
        return;
    }

    recordProjectPlay(activeProjectName);

    const timerReleasedCheckbox = qs('#timer-released-checkbox');
    let baseTime = QUESTION_TIMEOUT_SECONDS;

    if (timerReleasedCheckbox && timerReleasedCheckbox.checked) {
        baseTime = 999; // Modo sin tiempo (Tiempo Libre)
    } else {
        const checkedRadio = qs('input[name="base-time-option"]:checked');
        if (checkedRadio) {
            const val = parseInt(checkedRadio.value);
            if (!isNaN(val) && [20, 30, 40, 50, 60, 90, 120].includes(val)) {
                baseTime = val;
            }
        }
    }

    const penaltyMode = qs('#penalty-mode-select')?.value || 'lose_turn';
    const boardProfile = getBoardProfile(qs('#board-profile-select')?.value);
    const victoryMode = qs('#victory-mode-select')?.value || 'race';
    const scoreTargetValue = parseInt(qs('#score-target-select')?.value || '100', 10);
    const gameRules = {
        boardProfileId: boardProfile.id,
        victoryMode,
        scoreTarget: SCORE_TARGET_OPTIONS.includes(scoreTargetValue) ? scoreTargetValue : 100,
        lapBonus: boardProfile.lapBonus
    };
 
    // Configurar motor de juego
    game.setupGame(playerSetupList, baseTime, penaltyMode, gameRules);

    // Inicializar visualmente el tablero modular
    let bgPath = activeProjectMetadata.background 
        ? `./proyectos/${activeProjectName}/${activeProjectMetadata.background}`
        : `${PROJECTS_BASE_PATH}${activeProjectName}/fondo.jpg`;

    // Si el perfil de tablero tiene su propia imagen, la usamos
    if (boardProfile.image) {
        bgPath = boardProfile.image;
    }

    const boardConfig = {
        ...boardProfile,
        type: boardProfile.type,
        bareBoard: Boolean(boardProfile.bareBoard),
        showPath: Boolean(qs('#board-trace-checkbox')?.checked),
        specialCells: activeProjectMetadata.specialCells || [],
        customPositions: boardProfile.customPositions || activeProjectMetadata.customPositions || []
    };

    board.init(bgPath, boardConfig);
    board.updateTokens(game.players);

    qs('#game-project-title').innerText = activeProjectMetadata.title || 'TRIVIAX';
    qs('#game-project-title').title = activeProjectMetadata.title || 'TRIVIAX';
    qs('#game-project-level').innerText = activeProjectMetadata.nivel || 'S1';

    // Limpiar logs
    const logsContainer = qs('#game-logs');
    if (logsContainer) logsContainer.innerHTML = '';
    
    const modeLabels = {
        race: 'Llegar a la meta',
        exact: 'Meta exacta',
        points: `Puntos hasta ${game.scoreTarget} (+${game.lapBonus} por vuelta)`
    };
    ui.addLog(`¡Juego iniciado! Proyecto: ${activeProjectMetadata.title || activeProjectName}. Tablero: ${boardProfile.label}. Modalidad: ${modeLabels[game.victoryMode] || modeLabels.race}.`);
    
    updateGameUI();
    ui.showScreen('screen-game-board');
}

function updateGameUI() {
    ui.renderLeaderboard(game.players, game.currentPlayerIndex);
    ui.updateCurrentPlayerBadge(game.getCurrentPlayer());
}

/**
 * Ciclo de turno: Tirada de dados, presentaciÃ³n del desafÃ­o, cÃ¡lculo de puntaje y penalizaciones
 */
async function handleDiceRoll() {
    if (_sessionTabBlocked) {
        ui.addLog('⚠️ Esta pestaña está en modo solo lectura. Usa la pestaña activa para jugar.', 'system');
        return;
    }

    const rollBtn = qs('#btn-roll-dice');
    rollBtn.setAttribute('disabled', 'true');
    activeTurnoId = null;

    const player = game.getCurrentPlayer();
    ui.addLog(`Turno de ${player.name}. Tirando dado...`);

    // ── v4.5: registrar inicio de turno en BD ────────────────────
    if (activeSesionId !== null) {
        const jugadorId  = activeJugadorIds[player.id - 1];
        const playerToken = _getPlayerToken(player);
        if (jugadorId && playerToken) {
            try {
                const res = await ApiClient.startTurn(activeSesionId, jugadorId, playerToken);
                if (res?.data?.turno_id) activeTurnoId = res.data.turno_id;
                else if (res?.turno_id)  activeTurnoId = res.turno_id;
            } catch (e) {
                console.warn('[TRIVIAX v5.0] start_turn falló (modo local):', e.message);
            }
        }
    }
    // ─────────────────────────────────────────────────────────────

    const diceValue = await dice.roll();
    ui.addLog(`¡${player.name} sacó un ${diceValue}!`, 'roll');

    const challenge = challengeEngine.getNextChallenge();
    if (!challenge) {
        ui.addLog('Error: No quedan desafíos disponibles en la batería.', 'incorrect');
        alert('No se encontraron desafíos válidos.');
        rollBtn.removeAttribute('disabled');
        return;
    }

    // ── v4.5: registrar tirada y desafío en BD ───────────────────
    if (activeSesionId !== null && activeTurnoId !== null) {
        const jugadorId  = activeJugadorIds[player.id - 1];
        const playerToken = _getPlayerToken(player);
        if (jugadorId && playerToken) {
            try {
                await ApiClient.rollDice({
                    sesion_id:    activeSesionId,
                    jugador_id:   jugadorId,
                    turno_id:     activeTurnoId,
                    player_token: playerToken,
                    dice_value:   diceValue,
                    challenge_key: String(challenge.id)
                });
            } catch (e) {
                console.warn('[TRIVIAX v5.0] roll_dice falló (modo local):', e.message);
                activeTurnoId = null; // no se pudo registrar la tirada; usar guardar_intento
            }
        }
    }
    // ─────────────────────────────────────────────────────────────

    const questionTime = game.getQuestionTimeForPlayer(player);
    await delay(1000);

    // Presentar modal interactivo desde UIManager
    ui.showQuestionModal(challenge, questionTime, game.penaltyMode, activeProjectName, (result) => {
        resolveTurn(result, challenge, diceValue);
    });
}

/**
 * Resuelve la evaluaciÃ³n del desafÃ­o y activa las animaciones de ficha
 */
async function resolveTurn(result, challenge, diceValue) {
    const player = game.getCurrentPlayer();
    const originalPos = player.position;

    // Tipo de resultado
    const statusType = result.isCorrect ? 'correct' : (result.isTimeout ? 'timeout' : 'incorrect');

    // Registrar en los motores
    challengeEngine.recordResult(challenge.id, result.selectedText, statusType, player.name, game.turnNumber);
    const challengeType = challenge.originalType || challenge.type || 'multiple_choice';
    game.recordAttempt(challenge.id, challenge.prompt?.text || challenge.text, player.name, statusType, challengeType);

    // Guardar estadística persistente en background — modo legado (silencioso)
    ApiClient.saveQuestionStat(activeProjectName, challenge.id, statusType, challengeType);

    let _turnPointsDelta = 0; // acumulado para submit_answer al final del turno

    if (result.isCorrect) {
        // Calcular puntuaciÃ³n con bono de velocidad si corresponde
        const earnedPoints = ScoringEngine.calculateScore(challenge, true, result.timeUsed, game.baseTimeSeconds, true);
        _turnPointsDelta = earnedPoints;
        player.score += earnedPoints;
        player.correctAnswersCount++;

        ui.addLog(`✅ ¡Correcto! ${player.name} sumó ${earnedPoints} puntos. Avanza ${diceValue} casillas.`, 'correct');

        // Avanzar ficha segun la modalidad configurada
        const targetPos = game.advancePlayer(diceValue);
        // La ficha SIEMPRE se mueve saltando casilla a casilla (también en
        // tableros pelados, donde el salto se ve sobre las coordenadas del mapa).
        if (game.lastMove?.path?.length > 0 && typeof board.animateTokenPath === 'function') {
            await board.animateTokenPath(player, game.lastMove.path);
        } else {
            await board.animateTokenMove(player, originalPos, targetPos);
        }

        if (game.victoryMode === 'exact' && originalPos + diceValue > game.boardSize) {
            ui.addLog(`${player.name} se pasó de la meta y rebotó hasta la casilla ${targetPos}.`, 'system');
        } else if (game.victoryMode === 'points' && game.lastMove?.lapsCompleted > 0) {
            const bonus = game.lastMove.bonusPoints || 0;
            player.score += bonus;
            ui.addLog(`${player.name} completó ${game.lastMove.lapsCompleted} vuelta(s) y suma ${bonus} puntos de bono. Continúa en la casilla ${targetPos}.`, 'correct');
        }

        // --- VERIFICAR CASILLAS ESPECIALES TRAS EL AVANCE ---
        const specialCell = board.specialCells.find(c => c.cell === player.position);
        if (specialCell) {
            ui.addLog(`⭐ ¡Casilla especial en el tablero!`, 'system');
            if (specialCell.effect === 'extra_turn') {
                player.skipNextTurn = false;
                ui.addLog(`✨ ¡${player.name} obtiene un turno extra de bonus!`, 'correct');
            } else if (specialCell.effect === 'go_back') {
                const backVal = specialCell.value || 3;
                const prevPos = player.position;
                const nextPos = game.retrogradePlayer(backVal);
                ui.addLog(`💀 ¡Trampa! Retrocede ${backVal} casillas hasta la casilla ${nextPos}.`, 'incorrect');
                await board.animateTokenMove(player, prevPos, nextPos);
            } else if (specialCell.effect === 'teleport') {
                const target = specialCell.value || 0;
                const prevPos = player.position;
                const nextPos = game.teleportPlayer(target);
                ui.addLog(`🌀 ¡Portal! Teletransportado a la casilla ${nextPos}.`, 'system');
                await board.animateTokenMove(player, prevPos, nextPos);
            }
        }
    } else {
        // Respuesta incorrecta: aplicar deducciones y penalizaciones de turno
        const penalty = ScoringEngine.applyPenalty(player, game.penaltyMode);
        _turnPointsDelta = -(penalty.pointsDeducted || 0);

        let penaltyDesc = '';
        if (game.penaltyMode === 'lose_turn') {
            penaltyDesc = 'pierde su próximo turno';
        } else if (game.penaltyMode === 'deduct_points') {
            penaltyDesc = `pierde ${penalty.pointsDeducted} puntos`;
        } else {
            penaltyDesc = `pierde ${penalty.pointsDeducted} puntos y su próximo turno`;
        }

        const reason = result.isTimeout ? 'Tiempo agotado' : 'Incorrecto';
        ui.addLog(`❌ ${reason}. ${player.name} ${penaltyDesc}. Permanece en la casilla ${originalPos}.`, 'incorrect');
        board.updateTokens(game.players);
    }

    // ── v4.5: guardar intento/respuesta en BD (player.position es final aquí) ──
    if (activeSesionId !== null) {
        const jugadorId   = activeJugadorIds[player.id - 1];
        const playerToken = _getPlayerToken(player);
        if (jugadorId) {
            activeTurnCounter++;
            const idempotencyKey = window.crypto?.randomUUID?.()
                ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            const bdPayload = {
                player_token:        playerToken,
                idempotency_key:     idempotencyKey,
                challenge_key:       String(challenge.id),
                prompt_text:         challenge.prompt?.text || challenge.text || '',
                challenge_type:      challengeType,
                resultado:           statusType,
                answer_payload:      result,
                points_delta:        _turnPointsDelta,
                board_position_after: player.position,
                time_ms:             typeof result.timeUsed === 'number' ? Math.round(result.timeUsed * 1000) : null,
                orden_turno:         activeTurnCounter,
                nombre_jugador:      player.name
            };

            if (activeTurnoId !== null) {
                // v4.5: ciclo de turno completo con integridad transaccional
                const capturedTurnoId = activeTurnoId;
                activeTurnoId = null;
                ApiClient.submitAnswer({
                    sesion_id:  activeSesionId,
                    jugador_id: jugadorId,
                    turno_id:   capturedTurnoId,
                    ...bdPayload
                }).then(() =>
                    ApiClient.endTurn(activeSesionId, jugadorId, capturedTurnoId, playerToken)
                ).catch(e =>
                    console.warn('[TRIVIAX v5.0] submit/end_turn falló (resultado local preservado):', e.message)
                );
            } else {
                // Fallback legado: sin turno_id (start_turn o roll_dice fallaron)
                ApiClient.guardarIntento(activeSesionId, jugadorId, bdPayload);
            }
        }
    }
    // ─────────────────────────────────────────────────────────────

    updateGameUI();

    // Comprobar victoria
    if (result.isCorrect && game.checkWinCondition()) {
        const winMessage = game.victoryMode === 'points'
            ? `🏆 ¡${player.name} alcanzó ${game.scoreTarget} puntos y ganó la partida!`
            : `🏆 ¡${player.name} llegó a la meta y ganó la partida!`;
        ui.addLog(winMessage, 'correct');
        setTimeout(() => {
            endGameFlow();
        }, 1000);
        return;
    }

    // Avanzar turnos
    const skipped = game.nextTurn();
    skipped.forEach(p => {
        ui.addLog(`⚠️ ${p.name} se salta su turno por penalización.`, 'incorrect');
    });

    updateGameUI();
    qs('#btn-roll-dice').removeAttribute('disabled');
}

/**
 * Muestra las estadÃ­sticas finales y despacha reportes
 */
function endGameFlow() {
    _stopSessionPoll();
    const stats = challengeEngine.getStats();
    const teacherMail = activeProjectMetadata.mail || '';

    // ── v4.0: guardar resultados finales en BD (fire-and-forget) ──
    if (activeSesionId !== null && activeJugadorIds.length > 0) {
        // Ordenar jugadores por puntaje para asignar posición
        const ranking = [...game.players]
            .sort((a, b) => b.score - a.score || b.correctAnswersCount - a.correctAnswersCount);

        const resultados = game.players.map((p) => {
            const jugadorId = activeJugadorIds[p.id - 1];
            const posicion  = ranking.findIndex(r => r.id === p.id) + 1;
            return {
                jugador_id:  jugadorId,
                puntaje:     p.score,
                correctas:   p.correctAnswersCount,
                incorrectas: p.incorrectAnswersCount || 0,
                posicion
            };
        }).filter(r => r.jugador_id);

        ApiClient.guardarResultados(activeSesionId, resultados);
    }
    // ─────────────────────────────────────────────────────────────

    ui.showGameOver(
        game.players,
        stats,
        game.questionAttempts,
        teacherMail,
        () => resetCurrentGame(),
        () => ui.showScreen('screen-project-select'),
        async (payload) => {
            payload.project = activeProjectName;
            if (activeSesionId !== null) {
                payload.sesion_id = activeSesionId;
            }
            
            // Intentar enviar al backend
            try {
                if (navigator.onLine) {
                    const res = await ApiClient.sendReport(payload);
                    return res;
                } else {
                    // Guardar reporte localmente offline
                    await StorageService.saveLocalReport(payload);
                    return { success: true, error: null, details: 'Reporte guardado en local (sin conexión)' };
                }
            } catch (err) {
                // Guardar localmente
                await StorageService.saveLocalReport(payload);
                return { success: false, error: err.message };
            }
        },
        {
            boardProfile: game.boardProfile,
            victoryMode: game.victoryMode,
            scoreTarget: game.scoreTarget,
            winner: game.winner
        }
    );
}

/**
 * Sincroniza la visualizaciÃ³n de todos los botones de sonido del juego
 */
function updateSoundButtonsUI() {
    const soundEnabled = isSoundEnabled();
    const soundText = soundEnabled ? '🔊' : '🔇';
    
    const homeBtn = qs('#btn-home-sound');
    const mobileBtn = qs('#btn-mobile-sound');
    const boardBtn = qs('#btn-toggle-sound');
    
    if (homeBtn) {
        homeBtn.innerText = soundText;
        homeBtn.classList.toggle('sound-muted', !soundEnabled);
    }
    if (mobileBtn) {
        mobileBtn.innerText = soundText;
        mobileBtn.classList.toggle('sound-muted', !soundEnabled);
    }
    if (boardBtn) {
        boardBtn.innerText = soundText;
        boardBtn.classList.toggle('sound-muted', !soundEnabled);
    }
}

/**
 * Reinicia la partida conservando las configuraciones y los jugadores
 */
function resetCurrentGame() {
    loadProjectData(activeProjectName).then(() => {
        game.setupGame(currentPlayersSetup, game.baseTimeSeconds, game.penaltyMode, {
            boardProfileId: game.boardProfile.id,
            victoryMode: game.victoryMode,
            scoreTarget: game.scoreTarget,
            lapBonus: game.lapBonus
        });
        board.updateTokens(game.players);
        
        const logsContainer = qs('#game-logs');
        if (logsContainer) logsContainer.innerHTML = '';
        ui.addLog('La partida ha sido reiniciada. ¡Tira el dado!');

        updateGameUI();
        qs('#btn-roll-dice').removeAttribute('disabled');
        ui.showScreen('screen-game-board');
    });
}
