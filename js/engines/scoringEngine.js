/**
 * TRIVIAX ScoringEngine
 * Gestiona el cálculo de puntajes, penalizaciones, bonos por velocidad y precisión
 */
import { POINTS_CORRECT, POINTS_INCORRECT } from '../config.js';

export class ScoringEngine {
    /**
     * Calcula la puntuación final de un desafío respondido
     * @param {Object} challenge - El objeto desafío
     * @param {boolean} isCorrect - Si es correcto
     * @param {number} timeUsed - Segundos usados
     * @param {number} totalTime - Tiempo total asignado
     * @param {boolean} speedBonusEnabled - Si está habilitado el bono de velocidad
     * @returns {number} Puntos a sumar (o restar si es negativo)
     */
    static calculateScore(challenge, isCorrect, timeUsed = 0, totalTime = 20, speedBonusEnabled = true) {
        if (!isCorrect) {
            return 0; // Las deducciones se aplican en applyPenalty()
        }

        // Obtener dificultad del desafío (por defecto 1)
        const difficulty = challenge.difficulty || 1;
        let points = POINTS_CORRECT * difficulty;

        // Bono por velocidad: si responde antes del 30% del tiempo total
        if (speedBonusEnabled && totalTime > 0 && timeUsed < totalTime * 0.3) {
            const speedBonus = Math.round((POINTS_CORRECT * 0.5) * difficulty); // +50%
            points += speedBonus;
        }

        return points;
    }

    /**
     * Aplica la penalización por respuesta incorrecta o timeout al jugador
     * @param {Object} player - El jugador actual
     * @param {string} penaltyMode - 'lose_turn' | 'deduct_points' | 'both'
     * @returns {Object} { pointsDeducted: number, skipTurnApplied: boolean }
     */
    static applyPenalty(player, penaltyMode) {
        let pointsDeducted = 0;
        let skipTurnApplied = false;

        if (penaltyMode === 'deduct_points' || penaltyMode === 'both') {
            pointsDeducted = POINTS_INCORRECT;
            player.score = Math.max(0, player.score - POINTS_INCORRECT);
        }

        if (penaltyMode === 'lose_turn' || penaltyMode === 'both') {
            player.skipNextTurn = true;
            skipTurnApplied = true;
        }

        player.incorrectAnswersCount++;
        return {
            pointsDeducted,
            skipTurnApplied
        };
    }
}
