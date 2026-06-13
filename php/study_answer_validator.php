<?php
/**
 * TRIVIAX — Validador PHP de la modalidad "Estudia y responde" (study_answer).
 *
 * Espejo en servidor de js/validators/studyAnswerValidator.js.
 * Es la AUTORIDAD de validación: nada se guarda/publica sin pasar por aquí.
 *
 * Funciones públicas:
 *   triviax_study_validate_project(array $data): array  // ['ok'=>bool,'errors'=>[],'warnings'=>[]]
 *   triviax_study_is_project_valid(array $data): bool
 *   triviax_study_normalize_card(array $card, int $i): array  // forma canónica para BD
 *
 * Reglas y límites: docs/ESTUDIA_Y_RESPONDE.md §10–§11.
 */

if (!defined('STUDY_MODE')) {
    define('STUDY_MODE', 'study_answer');
}
if (!defined('STUDY_BOARD_TYPE')) {
    define('STUDY_BOARD_TYPE', 'study_deck');
}

function triviax_study_allowed_types(): array {
    return [
        'multiple_choice', 'true_false', 'fill_blank', 'short_answer',
        'matching_pairs', 'classification', 'sequence_order',
    ];
}
function triviax_study_difficulties(): array { return ['baja', 'media', 'alta']; }
function triviax_study_cognitive_levels(): array { return ['recordar', 'comprender', 'aplicar', 'analizar']; }

function triviax_study_limits(): array {
    return [
        'cardsMin' => 1,
        'cardsRecommendedMin' => 3,
        'cardsMax' => 30,
        'studyTextMax' => 900,
    ];
}

function _study_is_assoc(array $a): bool {
    if ($a === []) return true;
    return array_keys($a) !== range(0, count($a) - 1);
}
function _study_strlen($v): int {
    return is_string($v) ? mb_strlen(trim($v)) : 0;
}

/**
 * Valida un proyecto study_answer completo.
 * @return array ['ok'=>bool, 'errors'=>string[], 'warnings'=>string[]]
 */
function triviax_study_validate_project($data): array {
    $errors = [];
    $warnings = [];

    if (!is_array($data)) {
        return ['ok' => false, 'errors' => ['El proyecto no es un objeto JSON válido.'], 'warnings' => $warnings];
    }

    $metadata = $data['metadata'] ?? null;
    $board = $data['board'] ?? null;
    $sa = $data['studyAnswer'] ?? null;

    if (!is_array($metadata) || ($metadata['mode'] ?? null) !== STUDY_MODE) {
        $errors[] = 'metadata.mode debe ser "' . STUDY_MODE . '".';
    }
    if (!is_array($board) || ($board['type'] ?? null) !== STUDY_BOARD_TYPE) {
        $errors[] = 'board.type debe ser "' . STUDY_BOARD_TYPE . '".';
    }
    if (!is_array($sa)) {
        $errors[] = 'Falta el bloque "studyAnswer".';
        return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }
    if (empty($sa['version'])) {
        $errors[] = 'Falta studyAnswer.version.';
    }

    $settings = is_array($sa['settings'] ?? null) ? $sa['settings'] : [];
    if (!is_array($sa['settings'] ?? null)) {
        $errors[] = 'Falta studyAnswer.settings.';
    }

    $allowed = (is_array($settings['allowedQuestionTypes'] ?? null) && count($settings['allowedQuestionTypes']))
        ? $settings['allowedQuestionTypes']
        : triviax_study_allowed_types();

    $orderMode = $settings['orderMode'] ?? 'progressive';

    $cards = $sa['cards'] ?? null;
    if (!is_array($cards) || ($cards !== [] && _study_is_assoc($cards))) {
        $errors[] = 'studyAnswer.cards debe ser un arreglo.';
        return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }

    $limits = triviax_study_limits();
    if (count($cards) < $limits['cardsMin']) {
        $errors[] = 'El mazo debe tener al menos una carta.';
    }
    if (count($cards) > $limits['cardsMax']) {
        $errors[] = 'El mazo supera el máximo de ' . $limits['cardsMax'] . ' cartas (tiene ' . count($cards) . ').';
    }
    if (count($cards) > 0 && count($cards) < $limits['cardsRecommendedMin']) {
        $warnings[] = 'Se recomiendan al menos ' . $limits['cardsRecommendedMin'] . ' cartas (hay ' . count($cards) . ').';
    }

    $seenIds = [];
    $seenOrders = [];

    foreach ($cards as $i => $card) {
        $ref = (is_array($card) && !empty($card['id'])) ? ('La carta ' . $card['id']) : ('La carta #' . ($i + 1));

        if (!is_array($card)) {
            $errors[] = "{$ref} no es un objeto válido.";
            continue;
        }

        $id = trim((string)($card['id'] ?? ''));
        if ($id === '') {
            $errors[] = 'La carta #' . ($i + 1) . ' no tiene id.';
        } elseif (isset($seenIds[$id])) {
            $errors[] = "Hay un id de carta duplicado: {$id}.";
        } else {
            $seenIds[$id] = true;
        }

        if ($orderMode === 'progressive') {
            if (!isset($card['order'])) {
                $errors[] = "{$ref} no tiene \"order\" y el mazo es progresivo.";
            } elseif (isset($seenOrders[(string)$card['order']])) {
                $errors[] = "{$ref} repite el valor de \"order\" ({$card['order']}).";
            } else {
                $seenOrders[(string)$card['order']] = true;
            }
        }

        $stLen = _study_strlen($card['studyText'] ?? '');
        if ($stLen === 0) {
            $errors[] = "{$ref} no tiene texto de estudio.";
        } elseif ($stLen > $limits['studyTextMax']) {
            $errors[] = "{$ref} supera el máximo de {$limits['studyTextMax']} caracteres (tiene {$stLen}).";
        }

        if (isset($card['difficulty']) && !in_array($card['difficulty'], triviax_study_difficulties(), true)) {
            $errors[] = "{$ref} tiene una dificultad inválida: {$card['difficulty']}.";
        }
        if (isset($card['cognitiveLevel']) && !in_array($card['cognitiveLevel'], triviax_study_cognitive_levels(), true)) {
            $errors[] = "{$ref} tiene un nivel cognitivo inválido: {$card['cognitiveLevel']}.";
        }
        if (isset($card['tags']) && !is_array($card['tags'])) {
            $errors[] = "{$ref} tiene \"tags\" que no es un arreglo.";
        }
        if (isset($card['points']) && (!is_numeric($card['points']) || $card['points'] < 0)) {
            $errors[] = "{$ref} tiene \"points\" inválido.";
        }

        $fb = $card['feedback'] ?? null;
        if (!is_array($fb) || _study_strlen($fb['explanation'] ?? '') === 0) {
            $warnings[] = "{$ref} no tiene feedback.explanation (recomendado).";
        }

        _study_validate_assessment($card['assessment'] ?? null, $ref, $allowed, $errors);
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
}

function _study_validate_assessment($assessment, string $ref, array $allowed, array &$errors): void {
    if (!is_array($assessment)) {
        $errors[] = "{$ref} no tiene bloque de evaluación (assessment).";
        return;
    }
    $type = $assessment['type'] ?? null;
    if (!in_array($type, triviax_study_allowed_types(), true)) {
        $errors[] = "{$ref} usa un tipo de evaluación desconocido o no soportado: " . (is_string($type) ? $type : 'desconocido') . '.';
        return;
    }
    if (!in_array($type, $allowed, true)) {
        $errors[] = "{$ref} usa un tipo de evaluación no permitido por el docente: {$type}.";
        return;
    }
    if (_study_strlen($assessment['prompt'] ?? '') === 0) {
        $errors[] = "{$ref} no tiene consigna (prompt).";
    }

    switch ($type) {
        case 'multiple_choice':
            $opts = $assessment['options'] ?? null;
            if (!is_array($opts) || count($opts) < 2 || count($opts) > 6) {
                $errors[] = "{$ref} de opción múltiple debe tener entre 2 y 6 opciones.";
                break;
            }
            $ans = $assessment['answer'] ?? null;
            $optStrings = array_map('strval', $opts);
            $okStr = is_string($ans) && in_array($ans, $optStrings, true);
            $okIdx = is_int($ans) && $ans >= 0 && $ans < count($opts);
            if (!$okStr && !$okIdx) {
                $errors[] = "{$ref} de opción múltiple no tiene una respuesta correcta válida.";
            }
            break;

        case 'true_false':
            if (!is_bool($assessment['answer'] ?? null)) {
                $errors[] = "{$ref} de verdadero/falso requiere answer booleano.";
            }
            break;

        case 'fill_blank':
        case 'short_answer':
            $ans = $assessment['answer'] ?? null;
            $okStr = is_string($ans) && trim($ans) !== '';
            $okArr = is_array($ans) && count($ans) > 0;
            if ($okArr) {
                foreach ($ans as $a) {
                    if (!is_string($a) || trim($a) === '') { $okArr = false; break; }
                }
            }
            if (!$okStr && !$okArr) {
                $errors[] = "{$ref} requiere al menos una respuesta esperada (texto o lista).";
            }
            break;

        case 'matching_pairs':
            $pairs = $assessment['pairs'] ?? null;
            if (!is_array($pairs) || count($pairs) < 3) {
                $errors[] = "{$ref} de asociar pares requiere al menos 3 pares.";
                break;
            }
            foreach ($pairs as $j => $p) {
                if (!is_array($p) || _study_strlen($p['left'] ?? '') === 0 || _study_strlen($p['right'] ?? '') === 0) {
                    $errors[] = "{$ref} tiene el par " . ($j + 1) . ' incompleto.';
                }
            }
            break;

        case 'classification':
            $cats = $assessment['categories'] ?? null;
            $items = $assessment['items'] ?? null;
            if (!is_array($cats) || count($cats) < 2) {
                $errors[] = "{$ref} de clasificación requiere al menos 2 categorías.";
                break;
            }
            if (!is_array($items) || count($items) < 2) {
                $errors[] = "{$ref} de clasificación requiere al menos 2 elementos.";
                break;
            }
            $catIds = [];
            foreach ($cats as $c) {
                $catIds[] = is_array($c) ? (string)($c['id'] ?? $c['label'] ?? '') : (string)$c;
            }
            foreach ($items as $j => $it) {
                $cat = is_array($it) ? (string)($it['categoryId'] ?? $it['category'] ?? '') : '';
                if ($cat === '' || !in_array($cat, $catIds, true)) {
                    $errors[] = "{$ref} tiene el elemento " . ($j + 1) . ' sin categoría válida.';
                }
            }
            break;

        case 'sequence_order':
            $items = $assessment['items'] ?? null;
            if (!is_array($items) || count($items) < 3) {
                $errors[] = "{$ref} de ordenar requiere al menos 3 elementos.";
            }
            break;
    }
}

function triviax_study_is_project_valid($data): bool {
    return triviax_study_validate_project($data)['ok'];
}

/**
 * Normaliza una carta a la forma canónica que se guarda en study_cards.
 * Conserva la respuesta correcta (assessment.answer) SOLO para almacenamiento server-side.
 */
function triviax_study_normalize_card($card, int $i): array {
    $assessment = is_array($card['assessment'] ?? null) ? $card['assessment'] : [];
    return [
        'card_key'              => trim((string)($card['id'] ?? ('sa' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT)))),
        'orden'                 => (int)($card['order'] ?? ($i + 1)),
        'titulo'                => mb_substr(trim((string)($card['title'] ?? 'Carta')), 0, 200),
        'learning_objective'    => trim((string)($card['learningObjective'] ?? '')),
        'study_text'            => trim((string)($card['studyText'] ?? '')),
        'key_idea'              => trim((string)($card['keyIdea'] ?? '')),
        'vocabulary'            => is_array($card['vocabulary'] ?? null) ? $card['vocabulary'] : [],
        'assessment_type'       => (string)($assessment['type'] ?? 'multiple_choice'),
        'assessment'            => $assessment,
        'feedback'              => is_array($card['feedback'] ?? null) ? $card['feedback'] : [],
        'difficulty'            => in_array($card['difficulty'] ?? '', triviax_study_difficulties(), true) ? $card['difficulty'] : 'media',
        'cognitive_level'       => in_array($card['cognitiveLevel'] ?? '', triviax_study_cognitive_levels(), true) ? $card['cognitiveLevel'] : 'comprender',
        'tags'                  => is_array($card['tags'] ?? null) ? array_values($card['tags']) : [],
        'points'                => (int)($card['points'] ?? 100),
        'estimated_read_time'   => isset($card['estimatedReadTime']) ? (int)$card['estimatedReadTime'] : null,
        'estimated_answer_time' => isset($card['estimatedAnswerTime']) ? (int)$card['estimatedAnswerTime'] : null,
    ];
}
