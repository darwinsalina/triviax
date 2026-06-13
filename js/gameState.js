/**
 * Control del Estado del Juego (Game State) de TRIVIAX
 */

import { BOARD_SIZE, PLAYER_COLORS, POINTS_CORRECT, POINTS_INCORRECT } from './config.js';

export class GameState {
    constructor() {
        this.players = [];
        this.currentPlayerIndex = 0;
        this.turnNumber = 0;
        this.winner = null;
        this.isGameOver = false;
        this.baseTimeSeconds = 20;
        this.penaltyMode = 'both';
        this.questionAttempts = [];
    }

    /**
     * Configura e inicializa los jugadores
     * @param {Array} playersSetup - Array de objetos { name, colorId }
     * @param {number} baseTime - Tiempo base de respuesta elegido
     */
    setupPlayers(playersSetup, baseTime = 20, penaltyMode = 'both') {
        this.baseTimeSeconds = baseTime;
        this.penaltyMode = penaltyMode;
        this.questionAttempts = [];
        this.players = playersSetup.map((setup, index) => {
            const defaultName = `Jugador ${index + 1}`;
            const name = setup.name.trim() !== '' ? setup.name.trim() : defaultName;
            
            // Buscar el color configurado o elegir por defecto
            const colorConfig = PLAYER_COLORS.find(c => c.id === setup.colorId) || PLAYER_COLORS[index % PLAYER_COLORS.length];
            
            return {
                id: index + 1,
                name: name,
                color: colorConfig,
                position: 0, // Inician en casilla 0 (Salida)
                score: 0,    // Puntuación inicial
                skipNextTurn: false, // Flag de penalización
                correctAnswersCount: 0,
                incorrectAnswersCount: 0
            };
        });

        this.currentPlayerIndex = 0;
        this.turnNumber = 1;
        this.winner = null;
        this.isGameOver = false;
    }

    /**
     * Calcula el tiempo límite de respuesta dinámicamente según la posición del jugador
     * Casillas:
     * - 1 al 10: Tiempo base
     * - 11 al 20: Tiempo base - 1
     * - 21 al 30: Tiempo base - 2
     * - 31 al 40: Tiempo base - 4
     * - 41 al 50: Tiempo base - 6
     * Asegura un mínimo de 2 segundos.
     * @param {Object} player 
     * @returns {number} Tiempo en segundos
     */
    getQuestionTimeForPlayer(player) {
        if (this.baseTimeSeconds >= 999) {
            return this.baseTimeSeconds;
        }
        const pos = player.position;
        let offset = 0;
        
        if (pos >= 41) {
            offset = 6;
        } else if (pos >= 31) {
            offset = 4;
        } else if (pos >= 21) {
            offset = 2;
        } else if (pos >= 11) {
            offset = 1;
        } else {
            offset = 0;
        }

        return Math.max(2, this.baseTimeSeconds - offset);
    }


    /**
     * Retorna el jugador al que le toca tirar en el turno actual
     * @returns {Object}
     */
    getCurrentPlayer() {
        return this.players[this.currentPlayerIndex];
    }

    /**
     * Avanza al jugador actual en el tablero
     * @param {number} steps - Valor del dado (1 a 6)
     * @returns {number} Nueva posición del jugador
     */
    advancePlayer(steps) {
        if (this.isGameOver) return 0;
        
        const player = this.getCurrentPlayer();
        player.position = Math.min(BOARD_SIZE, player.position + steps);
        return player.position;
    }

    /**
     * Aplica el resultado de la trivia al jugador actual
     * @param {boolean} isCorrect - Si la respuesta fue correcta
     * @returns {Object} Puntos ganados/perdidos y puntaje total actualizado
     */
    applyAnswerResult(isCorrect) {
        const player = this.getCurrentPlayer();
        let pointsChange = 0;

        if (isCorrect) {
            pointsChange = POINTS_CORRECT;
            player.score += POINTS_CORRECT;
            player.correctAnswersCount++;
        } else {
            // Apply points deduction if active mode allows it
            if (this.penaltyMode === 'deduct_points' || this.penaltyMode === 'both') {
                pointsChange = -POINTS_INCORRECT;
                player.score = Math.max(0, player.score - POINTS_INCORRECT);
            } else {
                pointsChange = 0;
            }

            // Apply turn skipping if active mode allows it
            if (this.penaltyMode === 'lose_turn' || this.penaltyMode === 'both') {
                player.skipNextTurn = true;
            } else {
                player.skipNextTurn = false;
            }

            player.incorrectAnswersCount++;
        }

        return {
            pointsChange,
            newScore: player.score,
            skipApplied: player.skipNextTurn
        };
    }

    /**
     * Registra un intento de respuesta en el historial del juego
     * @param {number} questionId
     * @param {string} questionText 
     * @param {string} playerName 
     * @param {string} resultType - 'correct' | 'incorrect' | 'timeout'
     */
    recordQuestionAttempt(questionId, questionText, playerName, resultType) {
        this.questionAttempts.push({
            questionId,
            questionText,
            playerName,
            result: resultType
        });
    }

    /**
     * Verifica si el jugador actual ha alcanzado la meta final
     * @returns {boolean} True si ganó
     */
    checkWinCondition() {
        const player = this.getCurrentPlayer();
        if (player.position >= BOARD_SIZE) {
            this.winner = player;
            this.isGameOver = true;
            return true;
        }
        return false;
    }

    /**
     * Pasa al siguiente turno, verificando las penalizaciones de salto de turno.
     * @returns {Array} Lista de jugadores penalizados que saltaron su turno en esta transición
     */
    nextTurn() {
        if (this.isGameOver) return [];

        const skippedPlayers = [];
        let nextIndex = (this.currentPlayerIndex + 1) % this.players.length;
        
        // Loop para recorrer los jugadores y saltar a los penalizados
        for (let i = 0; i < this.players.length; i++) {
            const candidatePlayer = this.players[nextIndex];
            
            if (candidatePlayer.skipNextTurn) {
                // Consumir la penalización
                candidatePlayer.skipNextTurn = false;
                skippedPlayers.push(candidatePlayer);
                
                // Mover al siguiente
                nextIndex = (nextIndex + 1) % this.players.length;
            } else {
                // Jugador válido para jugar
                this.currentPlayerIndex = nextIndex;
                this.turnNumber++;
                return skippedPlayers;
            }
        }

        // Si por alguna razón extrema todos los jugadores tuvieran que saltar turno,
        // avanzamos al siguiente index de manera segura.
        this.currentPlayerIndex = nextIndex;
        this.turnNumber++;
        return skippedPlayers;
    }
}
