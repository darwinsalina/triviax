/**
 * TRIVIAX FeedbackEngine
 * Controla la retroalimentación visual y auditiva tras responder un desafío.
 */
import { playSuccessSound, playErrorSound } from '../sound.js';
import { escapeHTML, qs } from '../utils.js';
import { normalizeChallenge, normalizeChallengeType } from '../validators/challengeValidators.js';

export class FeedbackEngine {
    /**
     * Muestra la retroalimentación en la interfaz tras responder un desafío
     * @param {boolean} isCorrect 
     * @param {string} penaltyMode 
     * @param {boolean} isTimeout 
     * @param {HTMLElement} feedbackPanel - Panel de feedback (.feedback-panel)
     * @param {HTMLElement} feedbackMsg - Elemento de texto del mensaje (#feedback-msg)
     * @param {Object} challenge - Desafío evaluado
     * @param {string} selectedText - Respuesta seleccionada por el estudiante
     * @param {HTMLElement} feedbackDetails - Elemento de detalles del feedback (#feedback-details)
     */
    static show(isCorrect, penaltyMode, isTimeout, feedbackPanel, feedbackMsg, challenge = null, selectedText = '', feedbackDetails = null) {
        if (!feedbackPanel || !feedbackMsg) return;

        feedbackPanel.classList.remove('hidden');
        feedbackMsg.className = 'feedback-message';

        if (isCorrect) {
            feedbackMsg.innerText = '¡Respuesta correcta! +10 puntos.';
            feedbackMsg.classList.add('txt-success');
            playSuccessSound();
        } else {
            let penaltyText = '';
            if (penaltyMode === 'lose_turn') {
                penaltyText = 'pierdes tu próximo turno';
            } else if (penaltyMode === 'deduct_points') {
                penaltyText = '-5 puntos';
            } else {
                penaltyText = '-5 puntos y pierdes tu próximo turno';
            }

            if (isTimeout) {
                feedbackMsg.innerText = `¡Tiempo agotado! ${penaltyText}`;
            } else {
                feedbackMsg.innerText = `Respuesta incorrecta. ${penaltyText}`;
            }
            feedbackMsg.classList.add('txt-danger');
            playErrorSound();
        }

        // Mostrar comparación de respuesta seleccionada vs correcta si hay panel de detalles y el estudiante falló/timeout
        if (feedbackDetails) {
            if (isCorrect) {
                feedbackDetails.classList.add('hidden');
                feedbackDetails.innerHTML = '';
            } else {
                feedbackDetails.classList.remove('hidden');
                
                const correctText = FeedbackEngine.getCorrectAnswerText(challenge);
                let detailsHTML = '';
                
                if (!isTimeout && selectedText) {
                    detailsHTML += `<div><strong>Tu respuesta:</strong> <span class="txt-danger" style="margin-left: 4px;">${escapeHTML(selectedText)}</span></div>`;
                }
                
                if (correctText) {
                    const formattedCorrect = correctText.includes('<br>') 
                        ? correctText 
                        : escapeHTML(correctText).replace(/\n/g, '<br>');
                    detailsHTML += `<div style="margin-top: 6px;"><strong>Respuesta correcta:</strong> <span class="txt-success" style="margin-left: 4px;">${formattedCorrect}</span></div>`;
                }
                
                feedbackDetails.innerHTML = detailsHTML;
            }
        }
    }

    /**
     * Obtiene el texto representativo de la respuesta correcta de un desafío
     * @param {Object} challenge 
     * @returns {string}
     */
    static getCorrectAnswerText(challenge) {
        if (!challenge) return '';
        
        const normalizedChallenge = normalizeChallenge(challenge);
        const type = normalizeChallengeType(normalizedChallenge.type || 'multiple_choice');
        challenge = normalizedChallenge;
        
        switch (type) {
            case 'multiple_choice': {
                const correctOpt = (challenge.options || challenge.answers || []).find(
                    o => o.correct || (challenge.answer && challenge.answer.correctOptionId === o.id)
                );
                return correctOpt ? correctOpt.text : '';
            }
            case 'true_false': {
                const correctAnswer = challenge.answer?.value ?? challenge.answers?.find(a => a.correct)?.text;
                if (typeof correctAnswer === 'boolean') {
                    return correctAnswer ? 'Verdadero' : 'Falso';
                }
                return String(correctAnswer || '');
            }
            case 'matching_pairs': {
                return (challenge.pairs || []).map(p => `• "${p.left}" con "${p.right}"`).join('<br>');
            }
            case 'sequence_order': {
                const expectedOrder = challenge.answer?.order || challenge.items || [];
                return expectedOrder.join(' → ');
            }
            case 'drag_drop': {
                const answers = challenge.answer || {};
                return (challenge.draggables || []).map(drag => {
                    const zoneId = answers[drag.id];
                    const zone = (challenge.dropzones || []).find(z => z.id === zoneId);
                    return `• "${drag.text}" → "${zone ? zone.label : ''}"`;
                }).join('<br>');
            }
            case 'media_choice': {
                const correctOpt = (challenge.options || []).find(
                    o => challenge.answer && challenge.answer.correctOptionId === o.id
                );
                return correctOpt ? correctOpt.text : '';
            }
            case 'image_hotspot': {
                return 'Zona correcta señalada en la imagen';
            }
            case 'fill_blank': {
                const blanksData = challenge.blanks || {};
                return Object.keys(blanksData).map(k => `• ${k}: ${blanksData[k].correct}`).join('<br>');
            }
            case 'code_challenge': {
                const expectedLines = challenge.answer?.lines || challenge.lines || [];
                return expectedLines.join('\n');
            }
            default: {
                const fallbackOpt = (challenge.answers || []).find(o => o.correct);
                return fallbackOpt ? fallbackOpt.text : '';
            }
        }
    }
}
