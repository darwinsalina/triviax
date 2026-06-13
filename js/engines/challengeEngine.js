/**
 * TRIVIAX ChallengeEngine
 * Administra el listado de desafíos y la lógica de selección priorizada
 */
import { shuffle } from '../utils.js';

export class ChallengeEngine {
    constructor() {
        this.challenges = [];
        this.history = {}; // Map of ID -> Historial del desafío
        this.lastChallengeId = null;
    }

    /**
     * Inicializa el motor de desafíos
     * @param {Array} challenges - Array de objetos desafío
     */
    init(challenges) {
        this.challenges = shuffle(challenges);
        this.history = {};
        this.lastChallengeId = null;

        this.challenges.forEach(c => {
            this.history[c.id] = {
                challengeId: c.id,
                timesShown: 0,
                lastResponse: null,
                result: null, // 'correct' | 'incorrect' | 'timeout'
                player: null,
                turn: null
            };
        });
    }

    /**
     * Selecciona el próximo desafío según las prioridades:
     * 1. Desafíos no realizados.
     * 2. Desafíos contestados incorrectamente o timeout.
     * 3. Desafíos contestados correctamente.
     * Evita repetir de forma inmediata.
     * @returns {Object|null} El desafío seleccionado o null
     */
    getNextChallenge() {
        if (this.challenges.length === 0) {
            return null;
        }

        const unused = [];
        const failed = [];
        const correct = [];

        this.challenges.forEach(c => {
            const record = this.history[c.id];
            if (record.timesShown === 0) {
                unused.push(c);
            } else if (record.result === 'incorrect' || record.result === 'timeout') {
                failed.push(c);
            } else if (record.result === 'correct') {
                correct.push(c);
            }
        });

        let candidates = [];
        if (unused.length > 0) {
            candidates = unused;
        } else if (failed.length > 0) {
            candidates = failed;
        } else if (correct.length > 0) {
            candidates = correct;
        } else {
            candidates = this.challenges; // Fallback
        }

        // Evitar repetición inmediata
        let finalCandidates = candidates;
        if (candidates.length > 1 && this.lastChallengeId !== null) {
            finalCandidates = candidates.filter(c => c.id !== this.lastChallengeId);
        }

        const randomIndex = Math.floor(Math.random() * finalCandidates.length);
        const selected = finalCandidates[randomIndex];

        this.lastChallengeId = selected.id;
        return selected;
    }

    /**
     * Registra el resultado del desafío
     * @param {number|string} id 
     * @param {any} response 
     * @param {string} resultType - 'correct' | 'incorrect' | 'timeout'
     * @param {string} playerName 
     * @param {number} turnNumber 
     */
    recordResult(id, response, resultType, playerName, turnNumber) {
        const record = this.history[id];
        if (record) {
            record.timesShown++;
            record.lastResponse = response;
            record.result = resultType;
            record.player = playerName;
            record.turn = turnNumber;
        }
    }

    /**
     * Obtiene las estadísticas acumuladas de la sesión
     * @returns {Object}
     */
    getStats() {
        let correctCount = 0;
        let incorrectCount = 0;
        let timeoutCount = 0;

        Object.values(this.history).forEach(record => {
            if (record.result === 'correct') {
                correctCount++;
            } else if (record.result === 'incorrect') {
                incorrectCount++;
            } else if (record.result === 'timeout') {
                timeoutCount++;
            }
        });

        return {
            correct: correctCount,
            incorrect: incorrectCount,
            timeout: timeoutCount,
            totalAnswered: correctCount + incorrectCount + timeoutCount
        };
    }
}
