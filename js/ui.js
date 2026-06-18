/**
 * Administrador de la Interfaz de Usuario (UI) de TRIVIAX
 * Adaptado a la arquitectura modular y extensible de motores
 */

import { escapeHTML, qs, qsa } from './utils.js';
import { playWarningBeep, playQuestionPopupSound, playOptionSelectSound, playHomepageFanfareSound } from './sound.js';
import { ActivityRendererRegistry } from './activityRenderers/activityRendererRegistry.js';
import { FeedbackEngine } from './engines/feedbackEngine.js';
import { DiagnosticsService } from './services/diagnosticsService.js';
import { getChallengeTypeLabel, getChallengeInstruction } from './validators/challengeValidators.js';

export class UIManager {
    constructor() {
        this.activeScreen = null;
        this.timerInterval = null;
        this.activityRegistry = new ActivityRendererRegistry();
        this.onPlayerRename = null; // Callback inyectado por main.js
        this.activeProjectData = null; // Guardará referencia del proyecto actual

        this.setupDiagnosticsEvents();
        this.setupQuestionOptionSoundEvents();
    }

    setupQuestionOptionSoundEvents() {
        const optionsContainer = qs('#options-container');
        if (!optionsContainer) return;

        optionsContainer.addEventListener('click', (e) => {
            const target = e.target;
            if (target && target.closest('button, input, select, label, [role="button"], .pair-card, .option-card')) {
                playOptionSelectSound();
            }
        });
    }

    /**
     * Enlaza eventos iniciales del panel de diagnóstico
     */
    setupDiagnosticsEvents() {
        const btnOpen = qs('#btn-open-diagnostics');
        const btnClose = qs('#btn-close-diagnostics');
        
        if (btnOpen) {
            btnOpen.onclick = () => {
                this.showModal('modal-diagnostics');
                this.updateDiagnosticsUI();
            };
        }
        
        if (btnClose) {
            btnClose.onclick = () => this.hideModal('modal-diagnostics');
        }

        // Acciones del Diagnóstico
        const btnCheck = qs('#btn-diag-check');
        if (btnCheck) {
            btnCheck.onclick = () => DiagnosticsService.checkForUpdates();
        }

        const btnClear = qs('#btn-diag-clear');
        if (btnClear) {
            btnClear.onclick = () => {
                if (confirm('¿Estás seguro de limpiar la caché y recargar? Esto borrará la sesión activa y reportes offline.')) {
                    DiagnosticsService.clearAllCachesAndReload();
                }
            };
        }

        const btnPrecache = qs('#btn-diag-precache');
        if (btnPrecache) {
            btnPrecache.onclick = () => {
                alert('Precargando assets del proyecto para modo offline. Por favor espera...');
                // Precarga básica silenciosa de assets del Service Worker
                if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage({ action: 'precache' });
                    alert('Precarga completada en segundo plano.');
                }
            };
        }

        const btnExport = qs('#btn-diag-export');
        if (btnExport) {
            btnExport.onclick = () => {
                DiagnosticsService.exportReportToFile(this.activeProjectData);
            };
        }
    }

    /**
     * Actualiza los datos mostrados en el modal de diagnóstico técnico
     */
    async updateDiagnosticsUI() {
        qs('#diag-app-version .value').innerText = DiagnosticsService.getAppVersion();
        qs('#diag-sw-status .value').innerText = await DiagnosticsService.getServiceWorkerStatus();
        qs('#diag-online-status .value').innerText = DiagnosticsService.getOnlineStatus();
        qs('#diag-backend-status .value').innerText = await DiagnosticsService.getBackendStatus();

        if (this.activeProjectData) {
            qs('#diag-proj-name').innerText = this.activeProjectData.name || '-';
            qs('#diag-proj-title').innerText = this.activeProjectData.metadata?.title || '-';
            qs('#diag-proj-author').innerText = this.activeProjectData.metadata?.author || '-';
            qs('#diag-proj-qty').innerText = this.activeProjectData.questions?.length || 0;
        }

        const activeCaches = await DiagnosticsService.getActiveCaches();
        qs('#diag-cache-name').innerText = activeCaches.join(', ') || 'Ninguna activa';
        qs('#diag-last-update').innerText = new Date().toLocaleTimeString();
    }

    /**
     * Alterna la visibilidad entre las pantallas principales (SPA)
     * @param {string} screenId 
     */
    showScreen(screenId) {
        const screens = qsa('.game-screen');
        screens.forEach(screen => {
            if (screen.id === screenId) {
                screen.classList.add('active');
                this.activeScreen = screen;
            } else {
                screen.classList.remove('active');
            }
        });

        // Reproducir fanfarria al volver a la pantalla de inicio
        if (screenId === 'screen-project-select') {
            playHomepageFanfareSound();
        }
    }

    showModal(modalId) {
        const modal = qs(`#${modalId}`);
        if (modal) {
            // Recordar el foco previo para devolverlo al cerrar (accesibilidad)
            this._lastFocusedBeforeModal = document.activeElement;
            modal.classList.add('active');
            // Mover el foco al diálogo para que el teclado/lector quede dentro
            const dialog = modal.querySelector('[role="dialog"]') || modal;
            if (!dialog.hasAttribute('tabindex')) dialog.setAttribute('tabindex', '-1');
            dialog.focus({ preventScroll: true });
        }
    }

    hideModal(modalId) {
        const modal = qs(`#${modalId}`);
        if (modal) {
            modal.classList.remove('active');
            // Devolver el foco al elemento que abrió el modal
            const prev = this._lastFocusedBeforeModal;
            if (prev && typeof prev.focus === 'function' && !prev.disabled) {
                prev.focus({ preventScroll: true });
            }
            this._lastFocusedBeforeModal = null;
        }
    }

    /**
     * Muestra la metadata del proyecto en la pantalla de inicio
     * @param {Object} metadata 
     */
    updateProjectDetails(metadata) {
        const detailsBox = qs('#project-details');
        detailsBox.classList.remove('hidden');
        const emptySelection = qs('#catalog-no-selection');
        if (emptySelection) {
            emptySelection.classList.add('hidden');
        }

        qs('#detail-title').innerText = metadata.title || 'TRIVIAX';
        qs('#detail-author').innerText = metadata.author || 'No especificado';
        qs('#detail-nivel').innerText = metadata.nivel || 'General';
        qs('#detail-date').innerText = metadata.date || 'Desconocida';
        qs('#detail-obs').innerText = metadata.obs || '¡Responde correctamente y avanza!';

        qs('#btn-go-to-setup').removeAttribute('disabled');
    }

    /**
     * Configura la cantidad de inputs de jugadores en setup
     * @param {number} count 
     */
    renderPlayersSetup(count) {
        const rows = qsa('.player-input-row');
        rows.forEach(row => {
            const index = parseInt(row.getAttribute('data-index'));
            if (index < count) {
                row.classList.remove('hidden');
            } else {
                row.classList.add('hidden');
                qs('input', row).value = '';
            }
        });
    }

    /**
     * Renderiza el tablero de puntuaciones lateral
     * @param {Array} players 
     * @param {number} currentIdx 
     */
    renderLeaderboard(players, currentIdx) {
        const container = qs('#players-list');
        container.innerHTML = '';

        players.forEach((player, idx) => {
            const isCurrent = idx === currentIdx;
            const row = document.createElement('div');
            row.className = `player-status-row ${isCurrent ? 'active-turn' : ''}`;

            const skipBadge = player.skipNextTurn 
                ? '<span class="player-status-penalties" title="Próximo turno perdido">!</span>' 
                : '';

            row.innerHTML = `
                <div class="player-status-left">
                    <span class="player-status-color" style="background-color: ${player.color.hex};"></span>
                    <span class="player-status-name" style="cursor: pointer;" title="Doble clic para cambiar nombre">${escapeHTML(player.name)}</span>
                    <button class="btn-edit-name" style="background: none; border: none; cursor: pointer; font-size: 0.8rem; opacity: 0.5; margin-left: 6px; display: inline-flex;" title="Editar nombre">✏️</button>
                    ${skipBadge}
                </div>
                <div class="player-status-right">
                    <div class="player-status-pos">Casilla ${player.position}</div>
                    <div class="player-status-score">${player.score} pts</div>
                </div>
            `;

            const nameEl = row.querySelector('.player-status-name');
            const editBtn = row.querySelector('.btn-edit-name');
            
            const handleRename = () => {
                const newName = prompt(`Escribe el nuevo nombre para ${player.name}:`, player.name);
                if (newName !== null && newName.trim() !== '') {
                    const trimmedName = newName.trim().substring(0, 15);
                    player.name = trimmedName;
                    
                    if (this.onPlayerRename) {
                        this.onPlayerRename(player);
                    }
                }
            };
            
            nameEl.addEventListener('dblclick', handleRename);
            editBtn.addEventListener('click', handleRename);

            container.appendChild(row);
        });
    }

    /**
     * Actualiza la ficha del turno actual
     * @param {Object} player 
     */
    updateCurrentPlayerBadge(player) {
        const badgeColor = qs('#current-player-display .player-badge-color');
        const badgeName = qs('#current-player-display .player-badge-name');
        
        badgeColor.style.backgroundColor = player.color.hex;
        badgeName.innerText = player.name;
    }

    /**
     * Agrega un log al feed del juego
     * @param {string} message 
     * @param {string} type 
     */
    addLog(message, type = 'system') {
        const logsContainer = qs('#game-logs');
        if (!logsContainer) return;

        const entry = document.createElement('div');
        entry.className = `log-entry ${type}-log`;
        
        const now = new Date();
        const timeStr = now.toTimeString().split(' ')[0].substring(0, 5);
        
        entry.innerHTML = `<span style="opacity: 0.5;">[${timeStr}]</span> ${escapeHTML(message)}`;
        
        logsContainer.appendChild(entry);
        logsContainer.scrollTop = logsContainer.scrollHeight;
    }

    /**
     * Muestra el modal del desafío llamando al renderizador modular
     * @param {Object} challenge - Desafío a presentar
     * @param {number} totalTime - Tiempo límite en segundos
     * @param {string} penaltyMode - Regla de penalización
     * @param {string} projectName - Proyecto activo
     * @param {Function} onAnswerCallback - Callback al resolver el modal
     */
    showQuestionModal(challenge, totalTime, penaltyMode, projectName, onAnswerCallback) {
        if (this.timerInterval) clearInterval(this.timerInterval);

        const modal = qs('#modal-question');
        const timerText = qs('#question-timer-text');
        const timerBar = qs('#question-timer-bar');
        const qNumber = qs('#q-number-badge');
        const qText = qs('#modal-question-text');
        const optionsContainer = qs('#options-container');
        const feedbackPanel = qs('#feedback-panel');
        const feedbackMsg = qs('#feedback-msg');
        const feedbackDetails = qs('#feedback-details');
        const nextBtn = qs('#btn-next-turn');

        qNumber.innerText = challenge.id;
        // La consigna del desafío
        qText.innerText = challenge.prompt?.text || challenge.text || 'Responde el siguiente desafío:';
        optionsContainer.innerHTML = '';

        // Instrucción contextual según el tipo de desafío
        const instrEl = qs('#challenge-type-instruction');
        if (instrEl) {
            const instrText = getChallengeInstruction(challenge.originalType || challenge.type || '');
            if (instrText) {
                instrEl.innerText = instrText;
                instrEl.style.display = 'block';
            } else {
                instrEl.style.display = 'none';
            }
        }
        
        feedbackPanel.classList.add('hidden');
        feedbackMsg.className = 'feedback-message';
        timerText.classList.remove('timer-warning');
        
        this.showModal('modal-question');
        playQuestionPopupSound();

        // Configurar tiempo
        let timeLeft = totalTime;
        const isTimeFree = totalTime >= 999;
        timerText.innerText = isTimeFree ? 'Tiempo Libre' : `${timeLeft}s`;
        timerBar.style.width = '100%';
        timerBar.style.display = isTimeFree ? 'none' : 'block';

        const startTime = Date.now();

        // Callback interno al responder
        const handleUserSubmission = (isCorrect, responseText, isTimeout = false, raw = null) => {
            clearInterval(this.timerInterval);
            timerText.classList.remove('timer-warning');

            const elapsedSeconds = Math.round((Date.now() - startTime) / 1000);

            // Bloquear inputs dentro del container
            const inputs = optionsContainer.querySelectorAll('button, select, input');
            inputs.forEach(i => i.disabled = true);

            // Resaltar opciones correctas e incorrectas en los botones option-btn
            const optionButtons = optionsContainer.querySelectorAll('.option-btn');
            optionButtons.forEach(btn => {
                const isBtnCorrect = btn.getAttribute('data-correct') === 'true';
                if (isBtnCorrect) {
                    btn.classList.add('opt-correct');
                } else if (btn.innerText === responseText && !isCorrect) {
                    btn.classList.add('opt-incorrect');
                }
            });

            // Mostrar feedback visual y detalles
            FeedbackEngine.show(isCorrect, penaltyMode, isTimeout, feedbackPanel, feedbackMsg, challenge, responseText, feedbackDetails);

            // Vinculación única al botón Siguiente
            const newNextBtn = nextBtn.cloneNode(true);
            nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
            
            newNextBtn.onclick = () => {
                this.hideModal('modal-question');
                onAnswerCallback({
                    isCorrect,
                    selectedText: responseText,
                    isTimeout,
                    timeUsed: elapsedSeconds,
                    raw // #1 Etapa 2: respuesta cruda estructurada para validación server-side
                });
            };
        };

        // Renderizar el contenido específico del desafío desde la Registry
        this.activityRegistry.renderChallenge(challenge, optionsContainer, projectName, (res) => {
            handleUserSubmission(res.isCorrect, res.selectedText, false, res.raw || null);
        });

        // Loop del temporizador
        if (!isTimeFree) {
            this.timerInterval = setInterval(() => {
                timeLeft--;
                timerText.innerText = `${timeLeft}s`;
                
                const percent = (timeLeft / totalTime) * 100;
                timerBar.style.width = `${percent}%`;

                if (timeLeft <= 3 && timeLeft > 0) {
                    timerText.classList.add('timer-warning');
                    playWarningBeep();
                }

                if (timeLeft <= 0) {
                    clearInterval(this.timerInterval);
                    handleUserSubmission(false, 'Tiempo agotado', true);
                }
            }, 1000);
        }
    }

    /**
     * Muestra la vista final de GameOver
     */
    showGameOver(players, stats, questionAttempts, mail, onRestart, onHome, onSendReport, gameSummary = {}) {
        this.showScreen('screen-game-over');

        const sortedPlayers = [...players].sort((a, b) => {
            if (gameSummary.victoryMode === 'points') {
                if (b.score !== a.score) return b.score - a.score;
                return b.position - a.position;
            }
            if (b.position !== a.position) return b.position - a.position;
            return b.score - a.score;
        });

        qs('#winner-name-display').innerText = gameSummary.winner?.name || sortedPlayers[0].name;

        // Renderizar tabla
        const tbody = qs('#game-over-stats-body');
        tbody.innerHTML = '';
        sortedPlayers.forEach((p, idx) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>#${idx + 1}</strong></td>
                <td>
                    <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background-color:${p.color.hex}; margin-right:6px;"></span>
                    ${escapeHTML(p.name)}
                </td>
                <td>Casilla ${p.position}</td>
                <td><strong>${p.score} pts</strong></td>
                <td style="color: #34d399;"><strong>${p.correctAnswersCount || 0}</strong></td>
                <td style="color: #f87171;"><strong>${p.incorrectAnswersCount || 0}</strong></td>
            `;
            tbody.appendChild(row);
        });

        qs('#stats-total-answered').innerText = stats.totalAnswered;
        qs('#stats-correct').innerText = stats.correct;
        qs('#stats-incorrect').innerText = stats.incorrect + stats.timeout;

        const attemptsList = qs('#detailed-attempts-list');
        if (attemptsList) {
            attemptsList.innerHTML = '';
            if (questionAttempts && questionAttempts.length > 0) {
                questionAttempts.forEach((att, idx) => {
                    const item = document.createElement('div');
                    item.className = 'attempt-item';
                    
                    let statusClass = 'attempt-incorrect';
                    let statusLabel = 'Incorrecto';
                    if (att.result === 'correct') {
                        statusClass = 'attempt-correct';
                        statusLabel = 'Correcto';
                    } else if (att.result === 'timeout') {
                        statusClass = 'attempt-timeout';
                        statusLabel = 'Tiempo Agotado';
                    }
                    
                    item.innerHTML = `
                        <div class="attempt-header">
                            <span class="attempt-num">#${idx + 1}</span>
                            <span class="attempt-badge ${statusClass}">${statusLabel}</span>
                            <span class="attempt-player">Jugador: ${escapeHTML(att.playerName)}</span>
                        </div>
                        <div class="attempt-question">${escapeHTML(att.questionText)}</div>
                        <div class="attempt-type">Modalidad: ${escapeHTML(getChallengeTypeLabel(att.challengeType || 'multiple_choice'))}</div>
                    `;
                    attemptsList.appendChild(item);
                });
            } else {
                attemptsList.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 10px;">No se respondieron preguntas.</div>';
            }
        }

        // Email Report Trigger
        const statusBox = qs('#email-report-status');
        if (statusBox) {
            if (mail && mail.trim() !== '') {
                statusBox.style.display = 'block';
                statusBox.className = 'email-status-box email-sending';
                statusBox.innerHTML = `✉️ Enviando reporte al docente (${escapeHTML(mail)})...`;
                
                const payload = {
                    email: mail,
                    title: qs('#game-project-title').innerText,
                    date: new Date().toLocaleDateString(),
                    time: new Date().toTimeString().split(' ')[0].substring(0, 5),
                    players: players.map(p => ({
                        name: p.name,
                        score: p.score,
                        correct: p.correctAnswersCount || 0,
                        incorrect: p.incorrectAnswersCount || 0
                    })),
                    attempts: questionAttempts
                };
                
                onSendReport(payload).then(res => {
                    if (res && res.success) {
                        statusBox.className = 'email-status-box email-success';
                        statusBox.innerHTML = `✅ Reporte enviado al docente (${escapeHTML(mail)}).`;
                    } else {
                        statusBox.className = 'email-status-box email-error';
                        const errMsg = res && res.error ? `: ${res.error}` : ' (error offline/servidor)';
                        statusBox.innerHTML = `⚠️ No se pudo enviar por correo${escapeHTML(errMsg)}`;
                    }
                });
            } else {
                statusBox.style.display = 'block';
                statusBox.className = 'email-status-box email-disabled';
                statusBox.innerHTML = `ℹ️ Proyecto sin email de reporte.`;
            }
        }

        qs('#btn-game-over-restart').onclick = onRestart;
        qs('#btn-game-over-home').onclick = onHome;
    }

    showError(message, onRetry) {
        this.showScreen('screen-error');
        qs('#error-message-text').innerText = message;
        qs('#btn-error-retry').onclick = onRetry;
    }
}
