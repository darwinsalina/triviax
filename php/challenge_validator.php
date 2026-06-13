<?php
/**
 * TRIVIAX — php/challenge_validator.php
 * Validador de actividades JSON del lado servidor.
 *
 * Espeja las reglas de js/validators/challengeValidators.js para
 * garantizar que ningún JSON inválido llegue al sistema vía POST.
 *
 * Uso:
 *   require_once __DIR__ . '/challenge_validator.php';
 *   $errors = triviax_validate_project($data);   // array de strings
 *   $ok     = triviax_is_project_valid($data);   // bool
 */

declare(strict_types=1);

// ── Tipos reconocidos ────────────────────────────────────────
const TRIVIAX_KNOWN_TYPES = [
    'multiple_choice', 'true_false', 'matching_pairs', 'sequence_order',
    'classification', 'drag_drop', 'fill_blank', 'fill_blank_select',
    'media_choice', 'image_hotspot', 'code_challenge',
];

// Alias de tipos (deben normalizarse antes de validar)
const TRIVIAX_TYPE_ALIASES = [
    'classification'   => 'drag_drop',
    'fill_blank_select'=> 'fill_blank',
];

function triviax_normalize_type(string $type): string {
    return TRIVIAX_TYPE_ALIASES[$type] ?? $type;
}

// ── Validación de estructura raíz ────────────────────────────
function triviax_validate_project_structure(array $data): array {
    $errors = [];

    // metadata
    if (empty($data['metadata']) || !is_array($data['metadata'])) {
        $errors[] = 'Falta la sección "metadata" en el proyecto.';
    } else {
        if (empty(trim((string)($data['metadata']['title'] ?? '')))) {
            $errors[] = 'metadata.title es obligatorio.';
        }
    }

    // board
    if (empty($data['board']) || !is_array($data['board'])) {
        $errors[] = 'Falta la sección "board" en el proyecto.';
    } else {
        $validBoardTypes = ['serpentine', 'linear', 'circular'];
        $bt = $data['board']['type'] ?? '';
        if ($bt !== '' && !in_array($bt, $validBoardTypes, true)) {
            $errors[] = 'board.type "' . $bt . '" no es un tipo válido. Usa: ' . implode(', ', $validBoardTypes) . '.';
        }
    }

    // challenges
    $challenges = $data['challenges'] ?? $data['questions'] ?? null;
    if (!is_array($challenges)) {
        $errors[] = 'Falta el array "challenges" en el proyecto.';
    } elseif (count($challenges) === 0) {
        $errors[] = 'El proyecto no contiene ningún desafío.';
    }

    return $errors;
}

// ── Detección de IDs duplicados ──────────────────────────────
function triviax_find_duplicate_ids(array $challenges): array {
    $seen  = [];
    $dupes = [];
    foreach ($challenges as $idx => $ch) {
        $id = (string)($ch['id'] ?? '#' . ($idx + 1));
        if (isset($seen[$id])) {
            $dupes[] = 'ID "' . $id . '" duplicado (desafíos ' . ($seen[$id] + 1) . ' y ' . ($idx + 1) . ').';
        } else {
            $seen[$id] = $idx;
        }
    }
    return $dupes;
}

// ── Validación de un desafío ─────────────────────────────────
// Nota: se llama "_errors" (devuelve array) para no colisionar con
// triviax_validate_challenge() de triviax_core.php (lanza excepciones).
function triviax_validate_challenge_errors(array $ch, int $index = 0): array {
    $errors  = [];
    $id      = (string)($ch['id'] ?? '#' . ($index + 1));
    $rawType = (string)($ch['type'] ?? 'multiple_choice');
    $type    = triviax_normalize_type($rawType);
    $prefix  = 'Desafío ' . $id;

    // Tipo reconocido
    if (!in_array($rawType, TRIVIAX_KNOWN_TYPES, true)) {
        $errors[] = $prefix . ': tipo desconocido "' . $rawType . '".';
    }

    // Consigna
    $promptText = trim((string)($ch['prompt']['text'] ?? $ch['text'] ?? ''));
    if ($promptText === '') {
        $errors[] = $prefix . ': falta la consigna o texto principal.';
    }

    // ── Por tipo ──────────────────────────────────────────────
    switch ($type) {

        case 'multiple_choice': {
            $options = $ch['options'] ?? $ch['answers'] ?? [];
            $count   = count($options);
            if ($count < 3 || $count > 4) {
                $errors[] = $prefix . ': opción múltiple requiere entre 3 y 4 opciones (tiene ' . $count . ').';
            }
            $correctCount = 0;
            foreach ($options as $opt) {
                if (!empty($opt['correct'])) $correctCount++;
                if (trim((string)($opt['text'] ?? '')) === '') {
                    $errors[] = $prefix . ': hay una opción con texto vacío.';
                }
            }
            if ($correctCount === 0) {
                $errors[] = $prefix . ': no se marcó ninguna respuesta correcta.';
            } elseif ($correctCount > 1) {
                $errors[] = $prefix . ': tiene ' . $correctCount . ' respuestas correctas; debe ser exactamente 1.';
            }
            break;
        }

        case 'true_false': {
            $hasBool    = isset($ch['answer']['value']) && is_bool($ch['answer']['value']);
            $answers    = $ch['answers'] ?? [];
            $correct    = array_filter($answers, fn($a) => !empty($a['correct']));
            if (!$hasBool && count($correct) !== 1) {
                $errors[] = $prefix . ': verdadero/falso requiere una respuesta booleana (answer.value) o exactamente una opción correcta.';
            }
            break;
        }

        case 'matching_pairs': {
            $pairs = $ch['pairs'] ?? [];
            $cnt   = count($pairs);
            if ($cnt < 2) {
                $errors[] = $prefix . ': asociar pares requiere al menos 2 pares (tiene ' . $cnt . ').';
            } elseif ($cnt > 6) {
                $errors[] = $prefix . ': asociar pares admite máximo 6 pares (tiene ' . $cnt . ').';
            }
            foreach ($pairs as $pi => $pair) {
                if (trim((string)($pair['left']  ?? '')) === '') {
                    $errors[] = $prefix . ': el par ' . ($pi + 1) . ' no tiene texto en el lado izquierdo.';
                }
                if (trim((string)($pair['right'] ?? '')) === '') {
                    $errors[] = $prefix . ': el par ' . ($pi + 1) . ' no tiene texto en el lado derecho.';
                }
            }
            break;
        }

        case 'sequence_order': {
            $items = $ch['items'] ?? [];
            $cnt   = count($items);
            if ($cnt < 3) {
                $errors[] = $prefix . ': ordenar elementos requiere al menos 3 elementos (tiene ' . $cnt . ').';
            } elseif ($cnt > 8) {
                $errors[] = $prefix . ': ordenar elementos admite máximo 8 elementos (tiene ' . $cnt . ').';
            }
            foreach ($items as $item) {
                if (trim((string)$item) === '') {
                    $errors[] = $prefix . ': contiene un elemento vacío.';
                }
            }
            break;
        }

        case 'drag_drop': {
            // Soporta formato classification (categories/items) y drag_drop nativo
            $rawType2 = (string)($ch['type'] ?? '');
            if ($rawType2 === 'classification') {
                $categories = $ch['categories'] ?? [];
                $items      = $ch['items']      ?? [];
                if (count($categories) < 2) {
                    $errors[] = $prefix . ': clasificación requiere al menos 2 categorías (tiene ' . count($categories) . ').';
                }
                foreach ($categories as $ci => $cat) {
                    if (trim((string)($cat['label'] ?? '')) === '') {
                        $errors[] = $prefix . ': la categoría ' . ($ci + 1) . ' no tiene nombre.';
                    }
                }
                $catIds = array_column($categories, 'id');
                foreach ($items as $item) {
                    if (!in_array((string)($item['categoryId'] ?? ''), $catIds, true)) {
                        $errors[] = $prefix . ': el elemento "' . ($item['text'] ?? $item['id'] ?? '') . '" no tiene categoría válida.';
                    }
                }
            } else {
                $draggables = $ch['draggables'] ?? [];
                $dropzones  = $ch['dropzones']  ?? [];
                $answers    = $ch['answer']      ?? [];
                $zoneIds    = array_column($dropzones, 'id');
                if (count($dropzones) < 2) {
                    $errors[] = $prefix . ': drag_drop requiere al menos 2 zonas (tiene ' . count($dropzones) . ').';
                }
                foreach ($draggables as $item) {
                    $itemId = (string)($item['id'] ?? '');
                    if (!in_array((string)($answers[$itemId] ?? ''), $zoneIds, true)) {
                        $errors[] = $prefix . ': el elemento "' . ($item['text'] ?? $itemId) . '" no tiene zona asignada.';
                    }
                }
            }
            break;
        }

        case 'fill_blank': {
            $blanks     = $ch['blanks'] ?? [];
            $promptTxt  = $ch['prompt']['text'] ?? '';
            preg_match_all('/\[(blank\d+)\]/', $promptTxt, $matches);
            $markers = $matches[1] ?? [];
            if (count($markers) === 0) {
                $errors[] = $prefix . ': completar espacios necesita al menos un marcador como [blank1] en el enunciado.';
            }
            foreach ($markers as $marker) {
                if (!isset($blanks[$marker])) {
                    $errors[] = $prefix . ': el enunciado menciona [' . $marker . '] pero no hay configuración para ese espacio.';
                    continue;
                }
                $config  = $blanks[$marker];
                $options = $config['options'] ?? [];
                $correct = $config['correct']  ?? '';
                if (count($options) < 2) {
                    $errors[] = $prefix . ': el espacio [' . $marker . '] necesita al menos 2 opciones.';
                }
                if ($correct === '' || !in_array($correct, $options, true)) {
                    $errors[] = $prefix . ': la respuesta correcta de [' . $marker . '] no está entre sus opciones.';
                }
            }
            break;
        }

        case 'media_choice': {
            $mediaUrl = trim((string)(
                $ch['media']['url'] ?? $ch['mediaUrl'] ?? $ch['url'] ?? ''
            ));
            if ($mediaUrl === '') {
                $errors[] = $prefix . ': falta la URL o ruta del archivo multimedia.';
            }
            $options      = $ch['options'] ?? $ch['answers'] ?? [];
            $correctCount = count(array_filter($options, fn($o) => !empty($o['correct'])));
            if (count($options) < 2) {
                $errors[] = $prefix . ': multimedia requiere al menos 2 opciones.';
            }
            if ($correctCount !== 1) {
                $errors[] = $prefix . ': multimedia debe tener exactamente 1 respuesta correcta (tiene ' . $correctCount . ').';
            }
            break;
        }

        case 'image_hotspot': {
            $imageUrl = trim((string)(
                $ch['image']['url'] ?? $ch['imageUrl'] ?? $ch['url'] ?? ''
            ));
            if ($imageUrl === '') {
                $errors[] = $prefix . ': falta la URL o ruta de la imagen.';
            }
            $zones = $ch['zones'] ?? $ch['hotspots'] ?? $ch['areas'] ?? [];
            if (count($zones) === 0) {
                $errors[] = $prefix . ': zona en imagen requiere al menos una zona definida.';
            }
            $correctZones = array_filter($zones, fn($z) => !empty($z['correct']));
            if (count($correctZones) === 0) {
                $errors[] = $prefix . ': ninguna zona está marcada como correcta.';
            }
            break;
        }

        case 'code_challenge': {
            $expected = trim((string)(
                $ch['answer']['expected'] ?? $ch['expectedOutput'] ?? $ch['solution'] ?? ''
            ));
            $pattern  = trim((string)(
                $ch['answer']['pattern'] ?? $ch['validationPattern'] ?? ''
            ));
            if ($expected === '' && $pattern === '') {
                $errors[] = $prefix . ': desafío de código necesita una respuesta esperada (answer.expected) o un patrón de validación.';
            }
            break;
        }
    }

    return $errors;
}

// ── Validación completa ──────────────────────────────────────
/**
 * Valida el proyecto completo: estructura raíz + IDs únicos + cada desafío.
 * @param  array $data  Proyecto decodificado con json_decode($json, true)
 * @return string[]     Array de mensajes de error (vacío = válido)
 */
function triviax_validate_project(array $data): array {
    $structErrors = triviax_validate_project_structure($data);
    if (!empty($structErrors)) {
        return $structErrors;
    }

    $challenges = $data['challenges'] ?? $data['questions'] ?? [];

    $dupeErrors = triviax_find_duplicate_ids($challenges);

    $challengeErrors = [];
    foreach ($challenges as $idx => $ch) {
        $challengeErrors = array_merge(
            $challengeErrors,
            triviax_validate_challenge_errors($ch, $idx)
        );
    }

    return array_merge($dupeErrors, $challengeErrors);
}

/**
 * @return bool true si el proyecto no tiene errores de validación
 */
function triviax_is_project_valid(array $data): bool {
    return count(triviax_validate_project($data)) === 0;
}
