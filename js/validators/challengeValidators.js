const TYPE_ALIASES = {
    classification: 'drag_drop',
    fill_blank_select: 'fill_blank'
};

export const CHALLENGE_TYPE_LABELS = {
    multiple_choice: 'Opcion multiple',
    true_false: 'Verdadero/Falso',
    matching_pairs: 'Asociar pares',
    sequence_order: 'Ordenar elementos',
    drag_drop: 'Clasificar elementos',
    classification: 'Clasificar elementos',
    fill_blank: 'Completar espacios',
    fill_blank_select: 'Completar espacios',
    media_choice: 'Multimedia con opciones',
    image_hotspot: 'Identificar zona en imagen',
    code_challenge: 'Desafio de codigo'
};

export function normalizeChallengeType(type = 'multiple_choice') {
    return TYPE_ALIASES[type] || type || 'multiple_choice';
}

export function getChallengeTypeLabel(type = 'multiple_choice') {
    return CHALLENGE_TYPE_LABELS[type] || CHALLENGE_TYPE_LABELS[normalizeChallengeType(type)] || type;
}

function toSimpleId(value, fallback) {
    const raw = String(value || '').trim();
    if (!raw) return fallback;
    const normalized = raw.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const id = normalized.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    return id || fallback;
}

function normalizeChoiceOptions(options = []) {
    return (Array.isArray(options) ? options : []).map(option => {
        if (typeof option === 'object' && option !== null) {
            return {
                text: String(option.text || option.label || option.value || '').trim(),
                correct: Boolean(option.correct)
            };
        }
        return { text: String(option).trim(), correct: false };
    });
}

export function normalizeChallenge(challenge) {
    const originalType = challenge?.type || 'multiple_choice';
    const type = normalizeChallengeType(originalType);
    const normalized = {
        ...challenge,
        originalType,
        type,
        prompt: challenge.prompt || { text: challenge.text || 'Responde el siguiente desafio.' }
    };

    if (type === 'sequence_order' && Array.isArray(challenge.items) && typeof challenge.items[0] === 'object') {
        const orderedItems = [...challenge.items]
            .sort((a, b) => Number(a.order || 0) - Number(b.order || 0))
            .map(item => String(item.text || item.label || '').trim())
            .filter(Boolean);
        normalized.items = orderedItems;
        normalized.answer = { order: orderedItems };
    }

    if (originalType === 'classification') {
        const itemsFromNestedCategories = [];
        const categories = (challenge.categories || []).map((category, idx) => {
            const label = typeof category === 'object' && category !== null
                ? String(category.label || category.name || category.text || category.id || '').trim()
                : String(category).trim();
            const id = typeof category === 'object' && category !== null
                ? toSimpleId(category.id || label, `cat_${idx + 1}`)
                : toSimpleId(label, `cat_${idx + 1}`);
            if (typeof category === 'object' && category !== null && Array.isArray(category.items)) {
                category.items.forEach((nestedItem) => {
                    const text = typeof nestedItem === 'object' && nestedItem !== null
                        ? String(nestedItem.text || nestedItem.label || nestedItem.value || '').trim()
                        : String(nestedItem).trim();
                    if (text) {
                        itemsFromNestedCategories.push({
                            id: toSimpleId(
                                typeof nestedItem === 'object' && nestedItem !== null ? nestedItem.id || text : text,
                                `item_${itemsFromNestedCategories.length + 1}`
                            ),
                            text,
                            categoryId: id
                        });
                    }
                });
            }
            return { id, label };
        }).filter(category => category.label);
        const categoryByLabel = new Map();
        categories.forEach(category => {
            categoryByLabel.set(category.label, category.id);
            categoryByLabel.set(category.id, category.id);
        });
        const sourceItems = Array.isArray(challenge.items) ? challenge.items : itemsFromNestedCategories;
        const normalizedItems = sourceItems.map((item, idx) => {
            if (typeof item !== 'object' || item === null) {
                return {
                    id: toSimpleId(item, `item_${idx + 1}`),
                    text: String(item || '').trim(),
                    categoryId: ''
                };
            }
            const itemId = toSimpleId(item.id || item.text, `item_${idx + 1}`);
            const categoryValue = String(item.categoryId || item.category || '');
            return {
                id: itemId,
                text: item.text,
                categoryId: categoryByLabel.get(categoryValue) || toSimpleId(categoryValue, '')
            };
        }).filter(item => String(item.text || '').trim());
        normalized.categories = categories;
        normalized.items = normalizedItems;
        normalized.dropzones = categories;
        normalized.draggables = normalizedItems.map(item => ({
            id: item.id,
            text: item.text
        }));
        normalized.answer = {};
        normalizedItems.forEach(item => {
            normalized.answer[item.id] = item.categoryId;
        });
    }

    if (type === 'fill_blank') {
        if (Array.isArray(challenge.blanks)) {
            normalized.blanks = {};
            challenge.blanks.forEach((blankConfig, idx) => {
                if (!blankConfig || typeof blankConfig !== 'object') return;
                const blankId = String(blankConfig.id || blankConfig.name || `blank${idx + 1}`).trim();
                const options = Array.isArray(blankConfig.options)
                    ? blankConfig.options.map(option => String(option))
                    : [];
                const correct = blankConfig.correct ?? blankConfig.answer ?? blankConfig.value;
                if (blankId && options.length > 0 && correct !== undefined && correct !== null) {
                    normalized.blanks[blankId] = {
                        options,
                        correct: String(correct)
                    };
                }
            });
        }

        const options = normalizeChoiceOptions(challenge.options || challenge.answers || []);
        const correctOption = options.find(option => option.correct)?.text;
        if (!normalized.blanks && options.length >= 2 && correctOption) {
            let promptText = String(normalized.prompt?.text || '');
            if (!promptText.includes('[blank1]')) {
                const replaced = promptText.replace(/_{2,}|…|\.\.\./u, '[blank1]');
                promptText = replaced === promptText ? `${promptText} [blank1]` : replaced;
            }
            normalized.prompt = { ...(normalized.prompt || {}), text: promptText };
            normalized.blanks = {
                blank1: {
                    options: options.map(option => option.text),
                    correct: correctOption
                }
            };
            delete normalized.options;
            delete normalized.answers;
        }
    }

    return normalized;
}

export function normalizeProjectChallenges(challenges = []) {
    return challenges.map(normalizeChallenge);
}

// ── Tipos reconocidos ─────────────────────────────────────────
const KNOWN_TYPES = new Set([
    'multiple_choice', 'true_false', 'matching_pairs', 'sequence_order',
    'classification', 'drag_drop', 'fill_blank', 'fill_blank_select',
    'media_choice', 'image_hotspot', 'code_challenge'
]);

// ── Instrucciones por tipo (para mostrar al estudiante) ───────
export const CHALLENGE_INSTRUCTIONS = {
    multiple_choice:  'Elige la respuesta correcta.',
    true_false:       'Indica si la afirmación es verdadera o falsa.',
    classification:   'Clasifica cada elemento en la categoría correcta.',
    drag_drop:        'Clasifica cada elemento en la categoría correcta.',
    sequence_order:   'Ordena los elementos de principio a fin.',
    matching_pairs:   'Une cada concepto con su pareja correcta.',
    image_hotspot:    'Haz clic en la zona correcta de la imagen.',
    fill_blank:       'Completa el espacio en blanco.',
    fill_blank_select:'Completa el espacio en blanco.',
    media_choice:     'Observa el contenido y elige la respuesta correcta.',
    code_challenge:   'Escribe la respuesta o fragmento de código solicitado.',
};

export function getChallengeInstruction(type) {
    const t = normalizeChallengeType(type || '');
    return CHALLENGE_INSTRUCTIONS[t] || CHALLENGE_INSTRUCTIONS[type] || '';
}

// ── Validación de un desafío individual ──────────────────────
export function validateChallenge(challenge, index = 0) {
    const errors = [];
    challenge = normalizeChallenge(challenge);
    const id = challenge?.id ?? `#${index + 1}`;
    const rawType = challenge?.originalType || challenge?.type || 'multiple_choice';
    const type = normalizeChallengeType(rawType);
    const prefix = `Desafío ${id}`;

    if (!challenge || typeof challenge !== 'object') {
        return [`${prefix}: debe ser un objeto.`];
    }

    // Tipo reconocido
    if (!KNOWN_TYPES.has(rawType)) {
        errors.push(`${prefix}: tipo desconocido "${rawType}". Los tipos válidos son: ${[...KNOWN_TYPES].join(', ')}.`);
    }

    // Consigna obligatoria
    const promptText = challenge.prompt?.text || challenge.text || '';
    if (String(promptText).trim() === '') {
        errors.push(`${prefix}: falta la consigna o texto principal.`);
    }

    // ── Validaciones por tipo ─────────────────────────────────
    if (type === 'multiple_choice') {
        const options = challenge.options || challenge.answers || [];
        // El servidor SANEA las respuestas correctas antes de enviar el desafío
        // al jugador (api.php?action=get). Este validador corre sobre esos datos
        // saneados, así que la corrección solo se exige cuando esa información
        // está presente (datos completos: editor/servidor). El veredicto real es
        // autoritativo del servidor (board_eval).
        const hasCorrectInfo = options.some(o => 'correct' in o)
            || (challenge.answer && 'correctOptionId' in challenge.answer);
        const correctCount = options.filter(o =>
            o.correct || (challenge.answer && challenge.answer.correctOptionId === o.id)
        ).length;
        if (options.length < 3 || options.length > 4) {
            errors.push(`${prefix}: opción múltiple requiere entre 3 y 4 opciones (tiene ${options.length}).`);
        } else {
            options.forEach((o, oi) => {
                if (!String(o.text || '').trim()) {
                    errors.push(`${prefix}: la opción ${oi + 1} está vacía.`);
                }
            });
        }
        if (hasCorrectInfo && correctCount === 0) {
            errors.push(`${prefix}: no se marcó ninguna respuesta correcta.`);
        } else if (hasCorrectInfo && correctCount > 1) {
            errors.push(`${prefix}: tiene ${correctCount} respuestas marcadas como correctas; debe ser exactamente 1.`);
        }

    } else if (type === 'true_false') {
        const hasBooleanAnswer = typeof challenge.answer?.value === 'boolean';
        const answers = challenge.answers || [];
        const correctAnswers = answers.filter(a => a.correct);
        // answer.value se sanea para el jugador; solo validar si hay datos de respuesta.
        const answerInfoPresent = challenge.answer !== undefined
            || answers.some(a => 'correct' in a);
        if (answerInfoPresent && !hasBooleanAnswer && correctAnswers.length !== 1) {
            errors.push(`${prefix}: verdadero/falso requiere una respuesta booleana (true/false) o exactamente una opción correcta.`);
        }

    } else if (type === 'matching_pairs') {
        const pairs = challenge.pairs || [];
        if (pairs.length < 2) {
            errors.push(`${prefix}: asociar pares requiere al menos 2 pares (tiene ${pairs.length}).`);
        } else if (pairs.length > 6) {
            errors.push(`${prefix}: asociar pares admite máximo 6 pares (tiene ${pairs.length}).`);
        }
        pairs.forEach((pair, pi) => {
            if (!String(pair.left || '').trim()) {
                errors.push(`${prefix}: el par ${pi + 1} no tiene texto en el lado izquierdo.`);
            }
            if (!String(pair.right || '').trim()) {
                errors.push(`${prefix}: el par ${pi + 1} no tiene texto en el lado derecho.`);
            }
        });

    } else if (type === 'sequence_order') {
        const items = challenge.items || [];
        const order = challenge.answer?.order || items;
        if (items.length < 3) {
            errors.push(`${prefix}: ordenar elementos requiere al menos 3 elementos (tiene ${items.length}).`);
        } else if (items.length > 8) {
            errors.push(`${prefix}: ordenar elementos admite máximo 8 elementos (tiene ${items.length}).`);
        }
        items.forEach(item => {
            if (!String(item || '').trim()) {
                errors.push(`${prefix}: contiene un elemento vacío.`);
            }
        });
        if (order.length > 0 && order.length !== items.length) {
            errors.push(`${prefix}: el orden correcto tiene ${order.length} elementos pero el listado tiene ${items.length}.`);
        }

    } else if (rawType === 'classification') {
        const categories = challenge.categories || [];
        const items = challenge.items || [];
        const categoryIds = new Set(categories.map(c => String(c.id)));
        if (categories.length < 2) {
            errors.push(`${prefix}: clasificación requiere al menos 2 categorías (tiene ${categories.length}).`);
        }
        categories.forEach((c, ci) => {
            if (!String(c.label || '').trim()) {
                errors.push(`${prefix}: la categoría ${ci + 1} no tiene nombre.`);
            }
        });
        if (items.length < 1) {
            errors.push(`${prefix}: clasificación debe tener al menos 1 elemento para clasificar.`);
        }
        items.forEach(item => {
            if (!String(item.text || '').trim()) {
                errors.push(`${prefix}: hay un elemento sin texto.`);
            }
            // categoryId se sanea para el jugador (normalizeChallenge lo deja como
            // cadena vacía); validar pertenencia solo cuando viene con valor.
            const categoryId = String(item.categoryId || '').trim();
            if (categoryId !== '' && !categoryIds.has(categoryId)) {
                errors.push(`${prefix}: el elemento "${item.text || item.id}" no pertenece a ninguna categoría válida.`);
            }
        });

    } else if (type === 'drag_drop') {
        const draggables = challenge.draggables || [];
        const dropzones  = challenge.dropzones  || [];
        const answers    = challenge.answer      || {};
        const zoneIds    = new Set(dropzones.map(z => String(z.id)));
        if (dropzones.length < 2) {
            errors.push(`${prefix}: clasificar (drag_drop) requiere al menos 2 zonas (tiene ${dropzones.length}).`);
        }
        if (draggables.length < 1) {
            errors.push(`${prefix}: clasificar (drag_drop) requiere al menos 1 elemento arrastrable.`);
        }
        // El mapa elemento→zona (answer) se sanea para el jugador (normalizeChallenge
        // lo deja con valores vacíos); validar solo cuando trae asignaciones reales.
        const answerInfoPresent = challenge.answer
            && Object.values(challenge.answer).some(v => String(v || '').trim() !== '');
        if (answerInfoPresent) {
            draggables.forEach(item => {
                if (!zoneIds.has(String(answers[item.id] || ''))) {
                    errors.push(`${prefix}: el elemento "${item.text || item.id}" no tiene zona de destino asignada.`);
                }
            });
        }

    } else if (type === 'fill_blank') {
        const blanks  = challenge.blanks || {};
        const promptTxt = String(challenge.prompt?.text || '');
        const markers = [...promptTxt.matchAll(/\[(blank\d+)\]/g)].map(m => m[1]);
        if (markers.length === 0) {
            errors.push(`${prefix}: completar espacios necesita al menos un marcador como [blank1] en el enunciado.`);
        }
        markers.forEach(marker => {
            const config = blanks[marker];
            if (!config) {
                errors.push(`${prefix}: el enunciado menciona [${marker}] pero no hay configuración para ese espacio.`);
                return;
            }
            if (!Array.isArray(config.options) || config.options.length < 2) {
                errors.push(`${prefix}: el espacio [${marker}] necesita al menos 2 opciones.`);
            }
            // config.correct se sanea para el jugador; validar solo si está presente.
            if ('correct' in config && !config.options?.includes(config.correct)) {
                errors.push(`${prefix}: la respuesta correcta de [${marker}] no está entre sus opciones.`);
            }
        });

    } else if (type === 'media_choice') {
        // Archivo o URL multimedia
        const mediaUrl = challenge.media?.url || challenge.mediaUrl || challenge.url || '';
        if (!String(mediaUrl).trim()) {
            errors.push(`${prefix}: falta la URL o ruta del archivo multimedia.`);
        }
        // Opciones igual que multiple_choice
        const options = challenge.options || challenge.answers || [];
        const hasCorrectInfo = options.some(o => 'correct' in o);
        const correctCount = options.filter(o => o.correct).length;
        if (options.length < 2) {
            errors.push(`${prefix}: multimedia con opciones requiere al menos 2 opciones (tiene ${options.length}).`);
        }
        // La marca de acierto se sanea para el jugador; validar solo si está presente.
        if (hasCorrectInfo && correctCount !== 1) {
            errors.push(`${prefix}: debe tener exactamente 1 respuesta correcta (tiene ${correctCount}).`);
        }

    } else if (type === 'image_hotspot') {
        const imageUrl = challenge.image?.url || challenge.imageUrl || challenge.url || '';
        if (!String(imageUrl).trim()) {
            errors.push(`${prefix}: falta la URL o ruta de la imagen.`);
        }
        // Zonas de respuesta
        const zones = challenge.zones || challenge.hotspots || challenge.areas || [];
        const hasCorrectInfo = zones.some(z => 'correct' in z);
        const correctZones = zones.filter(z => z.correct);
        if (zones.length === 0) {
            errors.push(`${prefix}: zona en imagen requiere al menos una zona o coordenadas definidas.`);
        }
        // La marca de zona correcta se sanea para el jugador; validar solo si está presente.
        if (hasCorrectInfo && correctZones.length === 0) {
            errors.push(`${prefix}: zona en imagen requiere que al menos una zona esté marcada como correcta.`);
        }

    } else if (type === 'code_challenge') {
        const expected = challenge.answer?.expected
                      || challenge.expectedOutput
                      || challenge.solution
                      || '';
        const pattern  = challenge.answer?.pattern || challenge.validationPattern || '';
        // El esquema Parsons (challenge.lines) y la respuesta esperada se sanean
        // para el jugador; solo exigir respuesta esperada/patrón si no hay otra
        // forma de evaluación presente.
        const hasLinesSchema = Array.isArray(challenge.lines);
        if (!hasLinesSchema && challenge.answer !== undefined
            && !String(expected).trim() && !String(pattern).trim()) {
            errors.push(`${prefix}: desafío de código necesita una respuesta esperada (answer.expected) o un patrón de validación.`);
        }
    }

    return errors;
}

// ── Validación de la estructura raíz del proyecto ────────────
export function validateProjectStructure(projectData) {
    const errors = [];

    if (!projectData || typeof projectData !== 'object') {
        return ['El JSON del proyecto no es un objeto válido.'];
    }

    // metadata
    const meta = projectData.metadata;
    if (!meta || typeof meta !== 'object') {
        errors.push('Falta la sección "metadata" en el proyecto.');
    } else {
        if (!String(meta.title || '').trim()) {
            errors.push('metadata.title es obligatorio.');
        }
    }

    // board
    const board = projectData.board;
    if (!board || typeof board !== 'object') {
        errors.push('Falta la sección "board" en el proyecto.');
    } else {
        const validBoardTypes = ['serpentine', 'linear', 'circular'];
        if (board.type && !validBoardTypes.includes(board.type)) {
            errors.push(`board.type "${board.type}" no es un tipo válido. Usa: ${validBoardTypes.join(', ')}.`);
        }
    }

    // challenges
    const challenges = projectData.challenges || projectData.questions;
    if (!Array.isArray(challenges)) {
        errors.push('Falta el array "challenges" en el proyecto.');
    } else if (challenges.length === 0) {
        errors.push('El proyecto no contiene ningún desafío.');
    }

    return errors;
}

// ── Detección de IDs duplicados ───────────────────────────────
export function findDuplicateIds(challenges = []) {
    const seen = new Map();
    const dupes = [];
    challenges.forEach((ch, idx) => {
        const id = ch?.id ?? `#${idx + 1}`;
        if (seen.has(id)) {
            dupes.push(`ID "${id}" duplicado (desafíos ${seen.get(id) + 1} y ${idx + 1}).`);
        } else {
            seen.set(id, idx);
        }
    });
    return dupes;
}

// ── Validación completa del proyecto ─────────────────────────
/**
 * Valida el proyecto completo: estructura raíz + IDs duplicados + cada desafío.
 * Devuelve array de strings de error (vacío = válido).
 */
export function validateProjectData(projectData) {
    // 1. Estructura raíz
    const structErrors = validateProjectStructure(projectData);
    if (structErrors.length > 0) return structErrors;

    const challenges = projectData.challenges || projectData.questions || [];

    // 2. IDs duplicados
    const dupeErrors = findDuplicateIds(challenges);

    // 3. Cada desafío
    const challengeErrors = challenges.flatMap((ch, idx) => validateChallenge(ch, idx));

    return [...dupeErrors, ...challengeErrors];
}

/**
 * Validación rápida: devuelve true si el proyecto es válido, false si no.
 */
export function isProjectValid(projectData) {
    return validateProjectData(projectData).length === 0;
}
