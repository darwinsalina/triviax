/**
 * TRIVIAX GameEngine
 * Controla la lógica central del juego, turnos, penalizaciones y estado de partida.
 */
import { PLAYER_COLORS, SCORE_TARGET_OPTIONS, getBoardProfile } from '../config.js';

export class GameEngine {
    constructor() {
        this.players = [];
        this.currentPlayerIndex = 0;
        this.turnNumber = 0;
        this.winner = null;
        this.isGameOver = false;
        
        this.baseTimeSeconds = 20;
        this.penaltyMode = 'lose_turn'; // 'lose_turn' | 'deduct_points' | 'both'
        this.victoryMode = 'race'; // 'race' | 'exact' | 'points'
        this.boardProfile = getBoardProfile();
        this.boardSize = this.boardProfile.size;
        this.boardLoop = this.boardProfile.loop;
        this.scoreTarget = 100;
        this.lapBonus = 20;
        this.lastMove = null;
        this.questionAttempts = [];
    }

    /**
     * Inicializa los jugadores y configuraciones de la partida
     * @param {Array} playersSetup - [{ name, colorId }]
     * @param {number} baseTime 
     * @param {string} penaltyMode
     * @param {Object} rules
     */
    setupGame(playersSetup, baseTime = 20, penaltyMode = 'lose_turn', rules = {}) {
        this.baseTimeSeconds = baseTime;
        this.penaltyMode = penaltyMode;
        this.boardProfile = getBoardProfile(rules.boardProfileId);
        this.boardSize = this.boardProfile.size;
        this.boardLoop = this.boardProfile.loop;

        const requestedVictoryMode = ['race', 'exact', 'points'].includes(rules.victoryMode)
            ? rules.victoryMode
            : this.boardProfile.defaultVictoryMode;
        this.victoryMode = this.boardProfile.allowedVictoryModes.includes(requestedVictoryMode)
            ? requestedVictoryMode
            : this.boardProfile.defaultVictoryMode;

        const parsedScoreTarget = parseInt(rules.scoreTarget, 10);
        this.scoreTarget = SCORE_TARGET_OPTIONS.includes(parsedScoreTarget) ? parsedScoreTarget : 100;
        const parsedLapBonus = parseInt(rules.lapBonus, 10);
        this.lapBonus = Number.isFinite(parsedLapBonus) ? Math.max(0, parsedLapBonus) : 20;
        this.lastMove = null;
        this.questionAttempts = [];
        this.winner = null;
        this.isGameOver = false;
        this.currentPlayerIndex = 0;
        this.turnNumber = 1;

        this.players = playersSetup.map((setup, index) => {
            const defaultName = `Jugador ${index + 1}`;
            const name = setup.name.trim() !== '' ? setup.name.trim() : defaultName;
            
            const colorConfig = PLAYER_COLORS.find(c => c.id === setup.colorId) || PLAYER_COLORS[index % PLAYER_COLORS.length];
            
            return {
                id: index + 1,
                name: name,
                color: colorConfig,
                position: 0,         // Casilla inicial (Salida)
                score: 0,            // Puntuación inicial
                skipNextTurn: false, // Penalización de turno
                correctAnswersCount: 0,
                incorrectAnswersCount: 0
            };
        });
    }

    /**
     * Retorna el jugador activo
     * @returns {Object}
     */
    getCurrentPlayer() {
        return this.players[this.currentPlayerIndex];
    }

    /**
     * Calcula el tiempo límite para la pregunta según la posición de la ficha
     * @param {Object} player 
     * @returns {number} segundos
     */
    getQuestionTimeForPlayer(player) {
        if (this.baseTimeSeconds >= 999) {
            return this.baseTimeSeconds; // Tiempo libre
        }
        
        const pos = player.position;
        let offset = 0;
        
        const boardSize = this.boardSize || 50;
        if (pos >= boardSize * 0.82) {
            offset = 6;
        } else if (pos >= boardSize * 0.62) {
            offset = 4;
        } else if (pos >= boardSize * 0.42) {
            offset = 2;
        } else if (pos >= boardSize * 0.22) {
            offset = 1;
        }

        return Math.max(2, this.baseTimeSeconds - offset);
    }

    /**
     * Avanza el jugador actual según el valor del dado
     * @param {number} steps 
     * @returns {number} Nueva posición
     */
    advancePlayer(steps) {
        if (this.isGameOver) return 0;
        
        const player = this.getCurrentPlayer();
        const from = player.position;
        let target = from;
        const path = [];
        const boardSize = this.boardSize || 50;

        if (this.victoryMode === 'exact') {
            let direction = 1;
            let current = from;
            for (let i = 0; i < steps; i++) {
                if (direction === 1 && current >= boardSize) {
                    direction = -1;
                }
                current += direction;
                if (current >= boardSize) {
                    current = boardSize;
                    direction = -1;
                }
                if (current < 0) {
                    current = 0;
                }
                path.push(current);
            }
            target = path[path.length - 1] ?? from;
        } else if (this.victoryMode === 'points') {
            let current = from;
            let lapsCompleted = 0;
            for (let i = 0; i < steps; i++) {
                current += 1;
                if (current > boardSize) {
                    current = 1;
                    lapsCompleted++;
                }
                path.push(current);
            }
            target = path[path.length - 1] ?? from;
            this.lastMove = {
                from,
                to: target,
                steps,
                path,
                mode: this.victoryMode,
                lapsCompleted,
                lapBonus: this.lapBonus,
                bonusPoints: lapsCompleted * this.lapBonus
            };
            player.position = target;
            return player.position;
        } else {
            target = Math.min(boardSize, from + steps);
            for (let current = from + 1; current <= target; current++) {
                path.push(current);
            }
        }

        player.position = target;
        this.lastMove = {
            from,
            to: target,
            steps,
            path,
            mode: this.victoryMode
        };
        return player.position;
    }

    /**
     * Retrocede al jugador actual una cantidad de casillas (ej: penalizaciones de casillas especiales)
     * @param {number} steps 
     * @returns {number} Nueva posición
     */
    retrogradePlayer(steps) {
        if (this.isGameOver) return 0;
        
        const player = this.getCurrentPlayer();
        player.position = Math.max(0, player.position - steps);
        return player.position;
    }

    /**
     * Coloca al jugador en una casilla fija (ej: casillas puente, calavera)
     * @param {number} targetCell 
     * @returns {number} Nueva posición
     */
    teleportPlayer(targetCell) {
        if (this.isGameOver) return 0;
        
        const player = this.getCurrentPlayer();
        player.position = Math.max(0, Math.min(this.boardSize, targetCell));
        return player.position;
    }

    /**
     * Registra el intento del desafío en el historial
     * @param {number|string} questionId 
     * @param {string} questionText 
     * @param {string} playerName 
     * @param {string} result - 'correct' | 'incorrect' | 'timeout'
     */
    recordAttempt(questionId, questionText, playerName, result, challengeType = 'multiple_choice') {
        this.questionAttempts.push({
            questionId,
            questionText,
            challengeType,
            playerName,
            result
        });
    }

    /**
     * Verifica si hay ganador
     * @returns {boolean}
     */
    checkWinCondition() {
        const player = this.getCurrentPlayer();
        const hasWon = this.victoryMode === 'points'
            ? player.score >= this.scoreTarget
            : player.position >= this.boardSize;

        if (hasWon) {
            this.winner = player;
            this.isGameOver = true;
            return true;
        }
        return false;
    }

    /**
     * Pasa el turno al siguiente jugador consumiendo penalizaciones de turno
     * @returns {Array<Object>} Jugadores que saltaron turno
     */
    nextTurn() {
        if (this.isGameOver) return [];

        const skippedPlayers = [];
        let nextIndex = (this.currentPlayerIndex + 1) % this.players.length;
        
        for (let i = 0; i < this.players.length; i++) {
            const player = this.players[nextIndex];
            if (player.skipNextTurn) {
                player.skipNextTurn = false; // Se consume la penalización
                skippedPlayers.push(player);
                nextIndex = (nextIndex + 1) % this.players.length;
            } else {
                this.currentPlayerIndex = nextIndex;
                this.turnNumber++;
                return skippedPlayers;
            }
        }

        // Si todos están penalizados
        this.currentPlayerIndex = nextIndex;
        this.turnNumber++;
        return skippedPlayers;
    }
}
