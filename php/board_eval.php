<?php
/**
 * TRIVIAX — php/board_eval.php
 *
 * Evaluación AUTORITATIVA (server-side) del veredicto y el puntaje del tablero
 * clásico, sin confiar en el cliente (área crítica #1). Re-parsea el proyecto
 * del filesystem (no requiere el importador a BD).
 *
 * Cobertura ETAPA 1:
 *   · Puntos: se acotan al rango legítimo derivado de la fórmula real
 *     (js/engines/scoringEngine.js) en TODOS los tipos → mata el exploit de
 *     puntos arbitrarios.
 *   · Veredicto: para opción múltiple / multimedia, si el cliente afirma
 *     "correct" pero el texto elegido (selectedText) coincide con una opción
 *     incorrecta, se degrada a "incorrect" y se anulan los puntos.
 *
 * Fallback SEGURO: si no se reconoce el tipo, no se halla el desafío o no se
 * empareja la opción elegida, se respeta el veredicto del cliente (no se rompe
 * el juego). Tipos estructurados (asociar/ordenar/clasificar/completar/hotspot/
 * código) quedan como ETAPA 2 (requieren que el cliente envíe la respuesta
 * cruda estructurada, lo que toca cada renderer).
 *
 * Depende de php/triviax_core.php (triviax_parse_*, triviax_normalize_challenge_type).
 */

declare(strict_types=1);

require_once __DIR__ . '/triviax_core.php';
require_once __DIR__ . '/project_import.php'; // #7: lectura del desafío desde BD

/** Constantes de puntaje — espejo de js/config.js. */
if (!defined('TRIVIAX_POINTS_CORRECT'))   define('TRIVIAX_POINTS_CORRECT', 10);
if (!defined('TRIVIAX_POINTS_INCORRECT')) define('TRIVIAX_POINTS_INCORRECT', 5);

/**
 * Carga un desafío del proyecto por su id/clave, o null.
 *
 * #7: si se pasa $pdo y el proyecto está importado, el desafío se lee de la BD
 * (fuente autoritativa). Si no, fallback al filesystem, confinado a
 * $baseProjectsDir (anti path traversal).
 */
function triviax_board_find_challenge(string $baseProjectsDir, string $slug, string $challengeKey, ?PDO $pdo = null): ?array {
    if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        return null;
    }
    // 1) Preferir la BD (importador #7) cuando esté disponible.
    if ($pdo !== null) {
        try {
            $fromDb = triviax_db_find_challenge($pdo, $slug, $challengeKey);
            if ($fromDb !== null) {
                return $fromDb;
            }
        } catch (\Throwable $e) {
            // fallback silencioso a filesystem
        }
    }
    // 2) Fallback: filesystem.
    $base = realpath($baseProjectsDir);
    $projectPath = realpath($baseProjectsDir . '/' . $slug);
    if ($base === false || $projectPath === false || strpos($projectPath, $base) !== 0 || !is_dir($projectPath)) {
        return null;
    }
    try {
        $jsonPath = $projectPath . '/proyecto.json';
        $txtPath  = $projectPath . '/preguntas.txt';
        $challenges = [];
        if (is_file($jsonPath)) {
            $parsed = triviax_parse_project_json((string)file_get_contents($jsonPath));
            $challenges = $parsed['challenges'] ?? [];
        } elseif (is_file($txtPath)) {
            $parsed = triviax_parse_questions_text((string)file_get_contents($txtPath));
            $challenges = $parsed['questions'] ?? ($parsed['challenges'] ?? []);
        }
        foreach ($challenges as $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === $challengeKey) {
                return $c;
            }
        }
    } catch (\Throwable $e) {
        return null; // fallback seguro: el llamador respeta el veredicto del cliente
    }
    return null;
}

/**
 * Normaliza texto para comparaciones tolerantes (trim, colapso de espacios,
 * NFC). Espejo del criterio del cliente al emparejar opciones/respuestas.
 */
function triviax_board_norm_text($s): string {
    $s = (string)$s;
    if (class_exists('Normalizer')) {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
    }
    return (string)preg_replace('/\s+/u', ' ', trim($s));
}

/**
 * Para opción múltiple / multimedia: ¿el texto elegido (selectedText) coincide
 * con la opción correcta? Devuelve true/false si se puede decidir, o null si no
 * (tipo sin opciones, o selectedText que no empareja con ninguna → fallback).
 */
function triviax_board_choice_is_correct(array $challenge, string $selectedText): ?bool {
    $norm = 'triviax_board_norm_text';
    $sel = $norm($selectedText);
    if ($sel === '') {
        return null;
    }
    $options = $challenge['options'] ?? ($challenge['answers'] ?? []);
    if (!is_array($options) || count($options) === 0) {
        return null;
    }
    $correctOptionId = $challenge['answer']['correctOptionId'] ?? null;
    $matchedChosen   = false;
    $chosenIsCorrect = false;
    foreach ($options as $opt) {
        if (!is_array($opt)) {
            continue;
        }
        $text = $norm($opt['text'] ?? ($opt['label'] ?? ($opt['value'] ?? '')));
        if ($text === '') {
            continue;
        }
        $isCorrect = !empty($opt['correct'])
            || ($correctOptionId !== null && isset($opt['id']) && $opt['id'] === $correctOptionId);
        if ($text === $sel) {
            $matchedChosen   = true;
            $chosenIsCorrect = $isCorrect;
        }
    }
    return $matchedChosen ? $chosenIsCorrect : null;
}

/**
 * Evalúa AUTORITATIVAMENTE la respuesta cruda del estudiante contra el desafío,
 * para TODOS los tipos (cierre de #1 Etapa 2). Es el espejo server-side de la
 * lógica de acierto de los renderers (js/activityRenderers/activityRendererRegistry.js).
 *
 * Contrato de $raw (lo que el cliente debe enviar en answer_payload.raw):
 *   · multiple_choice / media_choice → { optionId } (o { optionText })
 *   · true_false                     → { boolValue: bool }
 *   · sequence_order                 → { order: [texto, …] }
 *   · matching_pairs                 → { pairs: { leftText: rightText, … } }
 *   · drag_drop (classification)     → { placements: { itemId: categoryId, … } }
 *   · fill_blank (fill_blank_select) → { blanks: { blankKey: valor, … } }
 *   · image_hotspot                  → { point: { x, y } }  (porcentajes 0..100)
 *   · code_challenge                 → { lines: [texto, …] }
 *
 * @return bool|null  true/false si se puede decidir; null si no (→ se respeta al cliente).
 */
function triviax_board_grade_answer(array $challenge, array $raw): ?bool {
    if (!$raw) {
        return null;
    }
    $type = triviax_normalize_challenge_type($challenge['type'] ?? '');

    switch ($type) {
        case 'multiple_choice':
        case 'media_choice':
            $options = $challenge['options'] ?? ($challenge['answers'] ?? []);
            if (!is_array($options) || count($options) === 0) {
                return null;
            }
            $correctId = $challenge['answer']['correctOptionId'] ?? null;
            if (isset($raw['optionId']) && $raw['optionId'] !== '') {
                foreach ($options as $o) {
                    if (is_array($o) && (string)($o['id'] ?? '') === (string)$raw['optionId']) {
                        return !empty($o['correct'])
                            || ($correctId !== null && ($o['id'] ?? null) === $correctId);
                    }
                }
                return null; // id no reconocido → no decidir
            }
            if (isset($raw['optionText'])) {
                return triviax_board_choice_is_correct($challenge, (string)$raw['optionText']);
            }
            return null;

        case 'true_false':
            if (!isset($challenge['answer']['value']) || !array_key_exists('boolValue', $raw)) {
                return null;
            }
            return ((bool)$challenge['answer']['value']) === ((bool)$raw['boolValue']);

        case 'sequence_order':
            $expected = $challenge['answer']['order'] ?? ($challenge['items'] ?? null);
            if (!is_array($expected) || !isset($raw['order']) || !is_array($raw['order'])) {
                return null;
            }
            if (count($expected) !== count($raw['order'])) {
                return false;
            }
            foreach (array_values($expected) as $i => $v) {
                if (triviax_board_norm_text($v) !== triviax_board_norm_text($raw['order'][$i] ?? '')) {
                    return false;
                }
            }
            return true;

        case 'matching_pairs':
            $pairs = $challenge['pairs'] ?? null;
            if (!is_array($pairs) || count($pairs) === 0 || !isset($raw['pairs']) || !is_array($raw['pairs'])) {
                return null;
            }
            // raw['pairs']: mapa { leftText: rightText } o lista [{left,right}].
            $userMap = [];
            if (array_is_list($raw['pairs'])) {
                foreach ($raw['pairs'] as $p) {
                    if (is_array($p) && isset($p['left'])) {
                        $userMap[triviax_board_norm_text($p['left'])] = triviax_board_norm_text($p['right'] ?? '');
                    }
                }
            } else {
                foreach ($raw['pairs'] as $l => $r) {
                    $userMap[triviax_board_norm_text($l)] = triviax_board_norm_text($r);
                }
            }
            if (count($userMap) !== count($pairs)) {
                return false;
            }
            foreach ($pairs as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $l = triviax_board_norm_text($p['left'] ?? '');
                $r = triviax_board_norm_text($p['right'] ?? '');
                if (!array_key_exists($l, $userMap) || $userMap[$l] !== $r) {
                    return false;
                }
            }
            return true;

        case 'drag_drop': // classification
            $items = $challenge['items'] ?? null;
            if (!is_array($items) || count($items) === 0 || !isset($raw['placements']) || !is_array($raw['placements'])) {
                return null;
            }
            $expected = [];
            foreach ($items as $it) {
                if (is_array($it) && isset($it['id'])) {
                    $expected[(string)$it['id']] = (string)($it['categoryId'] ?? '');
                }
            }
            if (count($expected) === 0 || count($raw['placements']) !== count($expected)) {
                return false;
            }
            foreach ($expected as $id => $cat) {
                if ((string)($raw['placements'][$id] ?? '') !== $cat) {
                    return false;
                }
            }
            return true;

        case 'fill_blank': // fill_blank_select
            $blanks = $challenge['blanks'] ?? null;
            if (!is_array($blanks) || count($blanks) === 0 || !isset($raw['blanks']) || !is_array($raw['blanks'])) {
                return null;
            }
            foreach ($blanks as $key => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $correct = triviax_board_norm_text($def['correct'] ?? '');
                $given   = triviax_board_norm_text($raw['blanks'][$key] ?? '');
                if ($given === '' || $given !== $correct) {
                    return false;
                }
            }
            return true;

        case 'image_hotspot':
            $h = $challenge['answer']['hotspot'] ?? null;
            if (!is_array($h) || !isset($raw['point']['x'], $raw['point']['y'])) {
                return null;
            }
            $x = (float)$raw['point']['x'];
            $y = (float)$raw['point']['y'];
            return $x >= (float)($h['xMin'] ?? 0) && $x <= (float)($h['xMax'] ?? 100)
                && $y >= (float)($h['yMin'] ?? 0) && $y <= (float)($h['yMax'] ?? 100);

        case 'code_challenge':
            $expected = $challenge['answer']['lines'] ?? ($challenge['lines'] ?? null);
            if (!is_array($expected) || !isset($raw['lines']) || !is_array($raw['lines'])) {
                return null;
            }
            if (count($expected) !== count($raw['lines'])) {
                return false;
            }
            foreach (array_values($expected) as $i => $v) {
                if (triviax_board_norm_text($v) !== triviax_board_norm_text($raw['lines'][$i] ?? '')) {
                    return false;
                }
            }
            return true;
    }

    return null; // tipo no cubierto → respetar al cliente
}

/**
 * Devuelve la SOLUCIÓN del desafío para el feedback al alumno (se revela recién
 * DESPUÉS de responder). Es lo que el cliente necesita para mostrar "la
 * respuesta correcta era…" cuando `action=get` ya no expone las respuestas.
 *
 * @return array estructura por tipo con la clave 'type' + los datos de solución.
 */
function triviax_board_solution_for_client(array $challenge): array {
    $type = triviax_normalize_challenge_type($challenge['type'] ?? '');

    switch ($type) {
        case 'multiple_choice':
        case 'media_choice':
            $options   = $challenge['options'] ?? ($challenge['answers'] ?? []);
            $correctId = $challenge['answer']['correctOptionId'] ?? null;
            $text = '';
            $id   = $correctId;
            foreach ($options as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $isC = !empty($o['correct']) || ($correctId !== null && ($o['id'] ?? null) === $correctId);
                if ($isC) {
                    $text = (string)($o['text'] ?? '');
                    $id   = $o['id'] ?? $id;
                    break;
                }
            }
            return ['type' => $type, 'correctOptionId' => $id, 'correctText' => $text];

        case 'true_false':
            return ['type' => $type, 'value' => (bool)($challenge['answer']['value'] ?? false)];

        case 'sequence_order':
            return ['type' => $type, 'order' => array_values($challenge['answer']['order'] ?? ($challenge['items'] ?? []))];

        case 'matching_pairs':
            $pairs = [];
            foreach (($challenge['pairs'] ?? []) as $p) {
                if (is_array($p)) {
                    $pairs[] = ['left' => (string)($p['left'] ?? ''), 'right' => (string)($p['right'] ?? '')];
                }
            }
            return ['type' => $type, 'pairs' => $pairs];

        case 'drag_drop': // classification
            $items = [];
            foreach (($challenge['items'] ?? []) as $it) {
                if (is_array($it) && isset($it['id'])) {
                    $items[] = [
                        'id'         => (string)$it['id'],
                        'text'       => (string)($it['text'] ?? ''),
                        'categoryId' => (string)($it['categoryId'] ?? ''),
                    ];
                }
            }
            return ['type' => $type, 'items' => $items, 'categories' => $challenge['categories'] ?? []];

        case 'fill_blank': // fill_blank_select
            $blanks = [];
            foreach (($challenge['blanks'] ?? []) as $k => $def) {
                if (is_array($def)) {
                    $blanks[$k] = (string)($def['correct'] ?? '');
                }
            }
            return ['type' => $type, 'blanks' => $blanks];

        case 'image_hotspot':
            return ['type' => $type, 'hotspot' => $challenge['answer']['hotspot'] ?? null];

        case 'code_challenge':
            return ['type' => $type, 'lines' => array_values($challenge['answer']['lines'] ?? ($challenge['lines'] ?? []))];
    }

    return ['type' => $type];
}

/**
 * Sanea un desafío para enviarlo al cliente (`action=get`) SIN filtrar la
 * respuesta correcta (cierre de la fuga #1, paso 6.3c). El veredicto ya es
 * autoritativo en el servidor (endpoints `grade`/`submit_answer`), así que el
 * cliente no necesita —ni debe— recibir la solución.
 *
 * Estrategia por tipo (normalizado con triviax_normalize_challenge_type):
 *   · Siempre: se quita `correct` de cada opción de `options[]`/`answers[]`.
 *   · choice/media: se borra `answer.correctOptionId`.
 *   · true_false: se borra `answer.value`.
 *   · sequence_order: se borra `answer.order` y se baraja `items` (su orden filtra).
 *   · matching_pairs: se DESACOPLA barajando la columna derecha entre entradas
 *     (la asociación left↔right ES la respuesta). El servidor evalúa contra los
 *     pares ORIGINALES (recarga el desafío), no contra esto.
 *   · drag_drop (classification): se quita `categoryId` de cada item y se barajan.
 *   · fill_blank: se quita `correct` de cada blank (se conservan sus `options`).
 *   · image_hotspot: se borra `answer.hotspot`.
 *   · code_challenge: se borra `answer.lines` y se baraja `lines`.
 *   · Al final, si `answer` quedó vacío, se elimina.
 *
 * Función PURA (no toca BD ni filesystem). Pensada para `array_map`.
 */
function triviax_board_sanitize_challenge_for_client(array $c): array {
    $type = triviax_normalize_challenge_type($c['type'] ?? '');

    // Siempre: quitar la marca de acierto de las opciones (cubre choice/media/
    // true_false/txt clásico, que llevan options[] o answers[]).
    foreach (['options', 'answers'] as $optKey) {
        if (isset($c[$optKey]) && is_array($c[$optKey])) {
            foreach ($c[$optKey] as &$opt) {
                if (is_array($opt)) {
                    unset($opt['correct']);
                }
            }
            unset($opt);
        }
    }

    switch ($type) {
        case 'multiple_choice':
        case 'media_choice':
            unset($c['answer']['correctOptionId']);
            break;

        case 'true_false':
            unset($c['answer']['value']);
            break;

        case 'sequence_order':
            unset($c['answer']['order']);
            if (isset($c['items']) && is_array($c['items'])) {
                shuffle($c['items']);
            }
            break;

        case 'matching_pairs':
            // Barajar SOLO la columna derecha entre las entradas para romper la
            // asociación posicional pairs[i].left↔pairs[i].right (la respuesta).
            if (isset($c['pairs']) && is_array($c['pairs']) && count($c['pairs']) > 1) {
                $rights = [];
                foreach ($c['pairs'] as $p) {
                    $rights[] = is_array($p) ? ($p['right'] ?? null) : null;
                }
                $orig = $rights;
                shuffle($rights);
                if ($rights === $orig) { // quedó idéntico → forzar desorden
                    [$rights[0], $rights[1]] = [$rights[1], $rights[0]];
                }
                $i = 0;
                foreach ($c['pairs'] as &$p) {
                    if (is_array($p)) {
                        $p['right'] = $rights[$i];
                    }
                    $i++;
                }
                unset($p);
            }
            break;

        case 'drag_drop': // classification
            if (isset($c['items']) && is_array($c['items'])) {
                foreach ($c['items'] as &$it) {
                    if (is_array($it)) {
                        unset($it['categoryId']);
                    }
                }
                unset($it);
                shuffle($c['items']);
            }
            break;

        case 'fill_blank': // fill_blank_select
            if (isset($c['blanks']) && is_array($c['blanks'])) {
                foreach ($c['blanks'] as &$b) {
                    if (is_array($b)) {
                        unset($b['correct']);
                    }
                }
                unset($b);
            }
            break;

        case 'image_hotspot':
            unset($c['answer']['hotspot']);
            break;

        case 'code_challenge':
            unset($c['answer']['lines']);
            if (isset($c['lines']) && is_array($c['lines'])) {
                shuffle($c['lines']);
            }
            break;
    }

    if (isset($c['answer']) && is_array($c['answer']) && count($c['answer']) === 0) {
        unset($c['answer']);
    }

    return $c;
}

/**
 * Recalcula veredicto y puntaje de forma autoritativa.
 *
 * @return array{resultado:string, points_delta:int, overridden:bool}
 */
function triviax_board_authoritative_result(
    string $baseProjectsDir, string $slug, string $challengeKey,
    string $challengeType, string $resultado, int $pointsDelta, string $selectedText,
    ?PDO $pdo = null, ?array $raw = null
): array {
    $overridden = false;
    $challenge  = triviax_board_find_challenge($baseProjectsDir, $slug, $challengeKey, $pdo);

    // Solo se intenta DEGRADAR un "correct" reclamado (anti-inflación de puntaje).
    if ($resultado === 'correct' && $challenge !== null) {
        // 1) Respuesta cruda estructurada → veredicto autoritativo de TODOS los tipos.
        $verdict = (is_array($raw) && $raw) ? triviax_board_grade_answer($challenge, $raw) : null;
        // 2) Fallback Etapa 1 (sin cruda): opción múltiple/multimedia desde selectedText.
        if ($verdict === null) {
            $type = triviax_normalize_challenge_type($challenge['type'] ?? ($challengeType ?: 'multiple_choice'));
            if (in_array($type, ['multiple_choice', 'media_choice'], true)) {
                $verdict = triviax_board_choice_is_correct($challenge, $selectedText);
            }
        }
        if ($verdict === false) {
            $resultado  = 'incorrect';
            $overridden = true;
        }
    }

    // Techo de puntos según dificultad (con bono de velocidad +50%).
    $difficulty = 1;
    if ($challenge !== null && isset($challenge['difficulty']) && is_numeric($challenge['difficulty'])) {
        $difficulty = max(1, min(10, (int)$challenge['difficulty']));
    }
    if ($overridden) {
        $pointsDelta = 0; // se anula la ganancia indebida
    } elseif ($resultado === 'correct') {
        $maxCorrect  = (int)ceil(TRIVIAX_POINTS_CORRECT * 1.5 * $difficulty);
        $pointsDelta = max(0, min($pointsDelta, $maxCorrect));
    } else { // incorrect | timeout
        $pointsDelta = max(-TRIVIAX_POINTS_INCORRECT, min(0, $pointsDelta));
    }

    return ['resultado' => $resultado, 'points_delta' => $pointsDelta, 'overridden' => $overridden];
}
