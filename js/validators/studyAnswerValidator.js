/**
 * TRIVIAX — Validador de la modalidad "Estudia y responde" (study_answer)
 *
 * Espejo en cliente del validador PHP `php/study_answer_validator.php`.
 * Valida la estructura de un proyecto de mazo de cartas antes de guardarlo/publicarlo.
 *
 * Uso:
 *   import { validateStudyProject, isStudyProjectValid } from './studyAnswerValidator.js';
 *   const { ok, errors, warnings } = validateStudyProject(data);
 *
 * Reglas y límites tomados de docs/ESTUDIA_Y_RESPONDE.md §10–§11.
 */

export const STUDY_MODE = 'study_answer';
export const STUDY_BOARD_TYPE = 'study_deck';

// Tipos de evaluación permitidos en la modalidad (v1).
export const STUDY_ALLOWED_TYPES = [
    'multiple_choice',
    'true_false',
    'fill_blank',
    'short_answer',
    'matching_pairs',
    'classification',
    'sequence_order'
];

export const STUDY_DIFFICULTIES = ['baja', 'media', 'alta'];
export const STUDY_COGNITIVE_LEVELS = ['recordar', 'comprender', 'aplicar', 'analizar'];

export const STUDY_LIMITS = {
    cardsMin: 1,        // mínimo absoluto para no fallar
    cardsRecommendedMin: 3,
    cardsMax: 30,       // máximo v1
    studyTextMax: 900,  // caracteres
    studyTextIdealMin: 350,
    studyTextIdealMax: 750
};

function isPlainObject(v) {
    return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function strLen(v) {
    return typeof v === 'string' ? v.trim().length : 0;
}

/**
 * Valida un proyecto completo de modalidad study_answer.
 * @param {Object} data - objeto del proyecto (metadata, board, studyAnswer)
 * @returns {{ok:boolean, errors:string[], warnings:string[]}}
 */
export function validateStudyProject(data) {
    const errors = [];
    const warnings = [];

    if (!isPlainObject(data)) {
        return { ok: false, errors: ['El proyecto no es un objeto JSON válido.'], warnings };
    }

    const metadata = data.metadata;
    const board = data.board;
    const sa = data.studyAnswer;

    // ── Estructura raíz ───────────────────────────────────────────────
    if (!isPlainObject(metadata) || metadata.mode !== STUDY_MODE) {
        errors.push(`metadata.mode debe ser "${STUDY_MODE}".`);
    }
    if (!isPlainObject(board) || board.type !== STUDY_BOARD_TYPE) {
        errors.push(`board.type debe ser "${STUDY_BOARD_TYPE}".`);
    }
    if (!isPlainObject(sa)) {
        errors.push('Falta el bloque "studyAnswer".');
        return { ok: errors.length === 0, errors, warnings };
    }
    if (!sa.version) {
        errors.push('Falta studyAnswer.version.');
    }

    const settings = isPlainObject(sa.settings) ? sa.settings : {};
    if (!isPlainObject(sa.settings)) {
        errors.push('Falta studyAnswer.settings.');
    }

    const allowed = Array.isArray(settings.allowedQuestionTypes) && settings.allowedQuestionTypes.length
        ? settings.allowedQuestionTypes
        : STUDY_ALLOWED_TYPES;

    const orderMode = settings.orderMode || 'progressive';

    // ── Cartas ────────────────────────────────────────────────────────
    const cards = sa.cards;
    if (!Array.isArray(cards)) {
        errors.push('studyAnswer.cards debe ser un arreglo.');
        return { ok: errors.length === 0, errors, warnings };
    }
    if (cards.length < STUDY_LIMITS.cardsMin) {
        errors.push('El mazo debe tener al menos una carta.');
    }
    if (cards.length > STUDY_LIMITS.cardsMax) {
        errors.push(`El mazo supera el máximo de ${STUDY_LIMITS.cardsMax} cartas (tiene ${cards.length}).`);
    }
    if (cards.length > 0 && cards.length < STUDY_LIMITS.cardsRecommendedMin) {
        warnings.push(`Se recomiendan al menos ${STUDY_LIMITS.cardsRecommendedMin} cartas (hay ${cards.length}).`);
    }

    const seenIds = new Set();
    const seenOrders = new Set();

    cards.forEach((card, i) => {
        const ref = (card && card.id) ? `La carta ${card.id}` : `La carta #${i + 1}`;

        if (!isPlainObject(card)) {
            errors.push(`${ref} no es un objeto válido.`);
            return;
        }

        // id único
        const id = String(card.id || '').trim();
        if (id === '') {
            errors.push(`La carta #${i + 1} no tiene id.`);
        } else if (seenIds.has(id)) {
            errors.push(`Hay un id de carta duplicado: ${id}.`);
        } else {
            seenIds.add(id);
        }

        // order si progresivo
        if (orderMode === 'progressive') {
            if (card.order === undefined || card.order === null) {
                errors.push(`${ref} no tiene "order" y el mazo es progresivo.`);
            } else if (seenOrders.has(card.order)) {
                errors.push(`${ref} repite el valor de "order" (${card.order}).`);
            } else {
                seenOrders.add(card.order);
            }
        }

        // texto de estudio
        const stLen = strLen(card.studyText);
        if (stLen === 0) {
            errors.push(`${ref} no tiene texto de estudio.`);
        } else if (stLen > STUDY_LIMITS.studyTextMax) {
            errors.push(`${ref} supera el máximo de ${STUDY_LIMITS.studyTextMax} caracteres (tiene ${stLen}).`);
        }

        // dificultad y nivel cognitivo
        if (card.difficulty !== undefined && !STUDY_DIFFICULTIES.includes(card.difficulty)) {
            errors.push(`${ref} tiene una dificultad inválida: ${card.difficulty}.`);
        }
        if (card.cognitiveLevel !== undefined && !STUDY_COGNITIVE_LEVELS.includes(card.cognitiveLevel)) {
            errors.push(`${ref} tiene un nivel cognitivo inválido: ${card.cognitiveLevel}.`);
        }
        if (card.tags !== undefined && !Array.isArray(card.tags)) {
            errors.push(`${ref} tiene "tags" que no es un arreglo.`);
        }
        if (card.points !== undefined && (typeof card.points !== 'number' || card.points < 0)) {
            errors.push(`${ref} tiene "points" inválido.`);
        }

        // feedback.explanation recomendado
        const fb = card.feedback;
        if (!isPlainObject(fb) || strLen(fb.explanation) === 0) {
            warnings.push(`${ref} no tiene feedback.explanation (recomendado).`);
        }

        // evaluación
        validateAssessment(card.assessment, ref, allowed, errors);
    });

    return { ok: errors.length === 0, errors, warnings };
}

/**
 * Valida el bloque assessment de una carta según su tipo.
 */
function validateAssessment(assessment, ref, allowed, errors) {
    if (!isPlainObject(assessment)) {
        errors.push(`${ref} no tiene bloque de evaluación (assessment).`);
        return;
    }
    const type = assessment.type;
    if (!STUDY_ALLOWED_TYPES.includes(type)) {
        errors.push(`${ref} usa un tipo de evaluación desconocido o no soportado: ${type}.`);
        return;
    }
    if (!allowed.includes(type)) {
        errors.push(`${ref} usa un tipo de evaluación no permitido por el docente: ${type}.`);
        return;
    }
    if (strLen(assessment.prompt) === 0) {
        errors.push(`${ref} no tiene consigna (prompt).`);
    }

    switch (type) {
        case 'multiple_choice': {
            const opts = assessment.options;
            if (!Array.isArray(opts) || opts.length < 2 || opts.length > 6) {
                errors.push(`${ref} de opción múltiple debe tener entre 2 y 6 opciones.`);
                break;
            }
            const ans = assessment.answer;
            const okStr = typeof ans === 'string' && opts.map(String).includes(ans);
            const okIdx = Number.isInteger(ans) && ans >= 0 && ans < opts.length;
            if (!okStr && !okIdx) {
                errors.push(`${ref} de opción múltiple no tiene una respuesta correcta válida.`);
            }
            break;
        }
        case 'true_false': {
            if (typeof assessment.answer !== 'boolean') {
                errors.push(`${ref} de verdadero/falso requiere answer booleano.`);
            }
            break;
        }
        case 'fill_blank':
        case 'short_answer': {
            const ans = assessment.answer;
            const okStr = typeof ans === 'string' && ans.trim() !== '';
            const okArr = Array.isArray(ans) && ans.length > 0 && ans.every(a => typeof a === 'string' && a.trim() !== '');
            if (!okStr && !okArr) {
                errors.push(`${ref} requiere al menos una respuesta esperada (texto o lista).`);
            }
            break;
        }
        case 'matching_pairs': {
            const pairs = assessment.pairs;
            if (!Array.isArray(pairs) || pairs.length < 3) {
                errors.push(`${ref} de asociar pares requiere al menos 3 pares.`);
                break;
            }
            pairs.forEach((p, i) => {
                if (!isPlainObject(p) || strLen(p.left) === 0 || strLen(p.right) === 0) {
                    errors.push(`${ref} tiene el par ${i + 1} incompleto.`);
                }
            });
            break;
        }
        case 'classification': {
            const cats = assessment.categories;
            const items = assessment.items;
            if (!Array.isArray(cats) || cats.length < 2) {
                errors.push(`${ref} de clasificación requiere al menos 2 categorías.`);
                break;
            }
            if (!Array.isArray(items) || items.length < 2) {
                errors.push(`${ref} de clasificación requiere al menos 2 elementos.`);
                break;
            }
            const catIds = cats.map(c => isPlainObject(c) ? String(c.id ?? c.label ?? '') : String(c));
            items.forEach((it, i) => {
                const cat = isPlainObject(it) ? String(it.categoryId ?? it.category ?? '') : '';
                if (cat === '' || !catIds.includes(cat)) {
                    errors.push(`${ref} tiene el elemento ${i + 1} sin categoría válida.`);
                }
            });
            break;
        }
        case 'sequence_order': {
            const items = assessment.items;
            if (!Array.isArray(items) || items.length < 3) {
                errors.push(`${ref} de ordenar requiere al menos 3 elementos.`);
            }
            break;
        }
    }
}

export function isStudyProjectValid(data) {
    return validateStudyProject(data).ok;
}

/** Devuelve true si el proyecto declara la modalidad study_answer. */
export function isStudyAnswerProject(data) {
    return isPlainObject(data)
        && isPlainObject(data.metadata)
        && data.metadata.mode === STUDY_MODE;
}
