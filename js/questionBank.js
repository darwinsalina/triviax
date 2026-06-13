/**
 * Gestor del Banco de Preguntas de TRIVIAX
 */

import { shuffle } from './utils.js';

export class QuestionBank {
    constructor() {
        this.questions = [];
        this.history = {}; // Mapa de ID -> Historial de la pregunta
        this.lastQuestionId = null;
    }

    /**
     * Inicializa el banco con un conjunto de preguntas
     * @param {Array} questions 
     */
    init(questions) {
        this.questions = shuffle(questions);
        this.history = {};
        this.lastQuestionId = null;

        // Inicializar el registro de historial vacío por cada pregunta
        this.questions.forEach(q => {
            this.history[q.id] = {
                questionId: q.id,
                timesShown: 0,
                lastResponse: null,
                result: null, // 'correct' | 'incorrect' | 'timeout'
                player: null,
                turn: null
            };
        });
    }

    /**
     * Obtiene la siguiente pregunta según las prioridades establecidas:
     * 1. Preguntas nunca mostradas en la sesión.
     * 2. Preguntas contestadas incorrectamente o por timeout en la sesión.
     * 3. Preguntas contestadas correctamente.
     * Además, evita repetir de forma inmediata si existen otras opciones.
     * 
     * @returns {Object|null} Pregunta seleccionada o null si no hay preguntas cargadas
     */
    getNextQuestion() {
        if (this.questions.length === 0) {
            return null;
        }

        const unused = [];
        const failed = [];
        const correct = [];

        this.questions.forEach(q => {
            const record = this.history[q.id];
            if (record.timesShown === 0) {
                unused.push(q);
            } else if (record.result === 'incorrect' || record.result === 'timeout') {
                failed.push(q);
            } else if (record.result === 'correct') {
                correct.push(q);
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
            candidates = this.questions; // Fallback
        }

        // Filtro para evitar repetición inmediata si hay alternativas en el grupo
        let finalCandidates = candidates;
        if (candidates.length > 1 && this.lastQuestionId !== null) {
            finalCandidates = candidates.filter(q => q.id !== this.lastQuestionId);
        }

        // Selección aleatoria dentro del grupo filtrado
        const randomIndex = Math.floor(Math.random() * finalCandidates.length);
        const selectedQuestion = finalCandidates[randomIndex];

        // Guardar referencia para evitar repetición inmediata en el próximo turno
        this.lastQuestionId = selectedQuestion.id;

        return selectedQuestion;
    }

    /**
     * Registra el resultado de una respuesta en la sesión
     * @param {number} questionId 
     * @param {string} responseText 
     * @param {string} resultType - 'correct' | 'incorrect' | 'timeout'
     * @param {string} playerName 
     * @param {number} turnNumber 
     */
    recordResult(questionId, responseText, resultType, playerName, turnNumber) {
        const record = this.history[questionId];
        if (record) {
            record.timesShown++;
            record.lastResponse = responseText;
            record.result = resultType;
            record.player = playerName;
            record.turn = turnNumber;
        }
    }

    /**
     * Mezcla las opciones de respuesta de una pregunta sin modificar la pregunta original
     * @param {Object} question 
     * @returns {Array} Respuestas mezcladas
     */
    getRandomizedAnswers(question) {
        return shuffle(question.answers);
    }

    /**
     * Obtiene estadísticas de respuestas de la sesión actual
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
