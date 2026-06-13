<?php
/**
 * Utilidades compartidas de TRIVIAX.
 */

function triviax_projects_dir() {
    return realpath(__DIR__ . '/../proyectos') ?: (__DIR__ . '/../proyectos');
}

function triviax_is_safe_project_path($path) {
    $baseDir = realpath(__DIR__ . '/../proyectos');
    if ($baseDir === false) {
        return false;
    }

    $realPath = realpath($path);
    if ($realPath === false) {
        $parentDir = realpath(dirname($path));
        return $parentDir !== false && strpos($parentDir . DIRECTORY_SEPARATOR, $baseDir . DIRECTORY_SEPARATOR) === 0;
    }

    return $realPath === $baseDir || strpos($realPath . DIRECTORY_SEPARATOR, $baseDir . DIRECTORY_SEPARATOR) === 0;
}

if (!function_exists('triviax_is_list_array')) {
    function triviax_is_list_array($value) {
        if (!is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }
}

function triviax_env_path() {
    $candidates = [];

    $explicitPath = getenv('TRIVIAX_ENV_PATH');
    if (is_string($explicitPath) && trim($explicitPath) !== '') {
        $candidates[] = trim($explicitPath);
    }

    $projectRoot = dirname(__DIR__);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], "/\\") : '';

    $candidates[] = $projectRoot . '/../../dbconn/triviax.env';
    $candidates[] = __DIR__ . '/../../../dbconn/triviax.env';
    $candidates[] = $projectRoot . '/../dbconn/triviax.env';
    $candidates[] = $projectRoot . '/dbconn/triviax.env';

    if ($documentRoot !== '') {
        $candidates[] = $documentRoot . '/../dbconn/triviax.env';
        $candidates[] = $documentRoot . '/../../dbconn/triviax.env';
        $candidates[] = $documentRoot . '/dbconn/triviax.env';
    }

    foreach (array_unique($candidates) as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_file($real) && is_readable($real)) {
            return $real;
        }
    }

    return $candidates[0];
}

function triviax_load_env() {
    $envPath = triviax_env_path();
    if (!is_file($envPath) || !is_readable($envPath)) {
        return [];
    }

    $vars = [];
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }

        $key = strtolower(trim(substr($trimmed, 0, $pos)));
        $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);
        $value = trim(substr($trimmed, $pos + 1));
        $value = trim($value, "\"'");
        if ($key !== '') {
            $vars[$key] = $value;
        }
    }

    return $vars;
}

function triviax_get_tkey() {
    $env = triviax_load_env();
    return $env['tkey'] ?? '';
}

function triviax_access_config_status() {
    $path = triviax_env_path();
    $env = triviax_load_env();

    return [
        'path' => $path,
        'exists' => is_file($path),
        'readable' => is_readable($path),
        'has_tkey' => isset($env['tkey']) && trim((string)$env['tkey']) !== ''
    ];
}

function triviax_verify_tkey($candidate) {
    $expected = triviax_get_tkey();
    if ($expected === '' || !is_string($candidate)) {
        return false;
    }
    return hash_equals($expected, trim($candidate));
}

function triviax_require_panel_access($panelTitle) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['triviax_tkey_ok'])) {
        return;
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
        if (triviax_verify_tkey($_POST['access_code'])) {
            $_SESSION['triviax_tkey_ok'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }
        $status = triviax_access_config_status();
        $error = $status['has_tkey']
            ? 'Codigo de acceso incorrecto.'
            : 'No se encontro la configuracion de acceso docente. Verifica el archivo dbconn/triviax.env y la variable tkey.';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Acceso docente - TRIVIAX</title><link rel="stylesheet" href="css/styles.css"></head>';
    echo '<body><div id="app-container"><section class="game-screen active" style="align-items:center;justify-content:center;">';
    echo '<form method="POST" class="glass-card" style="width:420px;max-width:92%;padding:28px;display:flex;flex-direction:column;gap:16px;">';
    echo '<h1 class="logo-text" style="font-size:2rem;">TRIVIAX</h1>';
    echo '<h2 style="font-size:1.2rem;">' . htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') . '</h2>';
    echo '<p style="color:var(--text-secondary);line-height:1.4;">Ingresa el codigo de acceso docente para continuar.</p>';
    if ($error !== '') {
        echo '<div style="color:var(--danger);font-weight:700;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '<input type="password" name="access_code" autocomplete="current-password" autofocus required class="form-control" style="padding:12px;border-radius:8px;">';
    echo '<button type="submit" class="btn btn-primary">Ingresar</button>';
    echo '<a href="index.html" class="btn btn-secondary" style="text-decoration:none;text-align:center;">Volver al juego</a>';
    echo '</form></section></div></body></html>';
    exit;
}

function triviax_parse_questions_text($content, $strict = true) {
    if ($content === false || $content === null || trim($content) === '') {
        throw new Exception('El archivo de preguntas esta vacio.');
    }

    if (substr($content, 0, 3) === pack('CCC', 0xef, 0xbb, 0xbf)) {
        $content = substr($content, 3);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $metadata = [
        'title' => 'TRIVIAX',
        'author' => '',
        'nivel' => '',
        'obs' => '',
        'date' => '',
        'mail' => '',
        'id' => ''
    ];

    $questions = [];
    $currentQuestion = null;
    $parsingQuestionsStarted = false;

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trimmed = trim($line);

        if ($trimmed === '' || preg_match('/^#+\s*/', $trimmed)) {
            continue;
        }

        if (!$parsingQuestionsStarted && preg_match('/^(title|author|nivel|obs|date|mail|id)\s*:\s*(.*)$/i', $trimmed, $matches)) {
            $metadata[strtolower($matches[1])] = trim($matches[2]);
            continue;
        }

        if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed, $matches)) {
            $parsingQuestionsStarted = true;
            if ($currentQuestion !== null) {
                triviax_validate_question($currentQuestion, $strict);
                $questions[] = $currentQuestion;
            }

            $currentQuestion = [
                'id' => (int)$matches[1],
                'text' => trim($matches[2]),
                'answers' => []
            ];
            continue;
        }

        if (strpos($trimmed, '@') === 0) {
            if ($currentQuestion === null) {
                throw new Exception("Se detecto una respuesta sin pregunta asociada en la linea {$lineNumber}.");
            }

            $answerText = trim(substr($trimmed, 1));
            $isCorrect = false;
            if (strpos($answerText, '*') === 0) {
                $isCorrect = true;
                $answerText = trim(substr($answerText, 1));
            }

            $currentQuestion['answers'][] = [
                'text' => $answerText,
                'correct' => $isCorrect
            ];
        }
    }

    if ($currentQuestion !== null) {
        triviax_validate_question($currentQuestion, $strict);
        $questions[] = $currentQuestion;
    }

    if (empty($questions)) {
        throw new Exception('El archivo de preguntas no contiene preguntas validas.');
    }

    return [
        'metadata' => $metadata,
        'questions' => $questions
    ];
}

function triviax_normalize_challenge_type($type) {
    $type = $type ?: 'multiple_choice';
    $aliases = [
        'classification' => 'drag_drop',
        'fill_blank_select' => 'fill_blank'
    ];
    return $aliases[$type] ?? $type;
}

function triviax_challenge_type_label($type) {
    $labels = [
        'multiple_choice' => 'Opcion multiple',
        'true_false' => 'Verdadero/Falso',
        'matching_pairs' => 'Asociar pares',
        'sequence_order' => 'Ordenar elementos',
        'drag_drop' => 'Clasificar elementos',
        'classification' => 'Clasificar elementos',
        'fill_blank' => 'Completar espacios',
        'fill_blank_select' => 'Completar espacios',
        'media_choice' => 'Multimedia con opciones',
        'image_hotspot' => 'Identificar zona en imagen',
        'code_challenge' => 'Desafio de codigo'
    ];
    return $labels[$type] ?? ($labels[triviax_normalize_challenge_type($type)] ?? $type);
}

function triviax_challenge_prompt_text($challenge) {
    if (isset($challenge['prompt']) && is_array($challenge['prompt']) && isset($challenge['prompt']['text'])) {
        return trim((string)$challenge['prompt']['text']);
    }
    return trim((string)($challenge['text'] ?? ''));
}

function triviax_simple_id($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value));
    $value = trim($value, '_');
    return $value !== '' ? $value : $fallback;
}

function triviax_normalize_choice_options($options) {
    if (!is_array($options)) {
        return [];
    }
    return array_map(function ($option) {
        if (is_array($option)) {
            return [
                'text' => trim((string)($option['text'] ?? $option['label'] ?? $option['value'] ?? '')),
                'correct' => !empty($option['correct'])
            ];
        }
        return [
            'text' => trim((string)$option),
            'correct' => false
        ];
    }, $options);
}

function triviax_normalize_challenge_data($challenge, $index = 0) {
    if (!is_array($challenge)) {
        return $challenge;
    }

    $rawType = $challenge['type'] ?? 'multiple_choice';
    $type = triviax_normalize_challenge_type($rawType);
    $challenge['prompt'] = $challenge['prompt'] ?? ['text' => (string)($challenge['text'] ?? '')];

    if ($type === 'sequence_order') {
        $items = $challenge['items'] ?? [];
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            usort($items, function ($a, $b) {
                return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
            });
            $texts = array_map(function ($item) {
                return trim((string)($item['text'] ?? $item['label'] ?? ''));
            }, $items);
            $texts = array_values(array_filter($texts, function($text) { return $text !== ''; }));
            $challenge['items'] = $texts;
            $challenge['answer'] = ['order' => $texts];
        }
    }

    if ($rawType === 'classification') {
        $categories = $challenge['categories'] ?? [];
        $normalizedCategories = [];
        $itemsFromNestedCategories = [];
        foreach ($categories as $catIndex => $category) {
            if (is_array($category)) {
                $label = trim((string)($category['label'] ?? $category['name'] ?? $category['text'] ?? $category['id'] ?? ''));
                $id = triviax_simple_id($category['id'] ?? $label, 'cat_' . ($catIndex + 1));
            } else {
                $label = trim((string)$category);
                $id = triviax_simple_id($label, 'cat_' . ($catIndex + 1));
            }
            if ($label !== '') {
                $normalizedCategories[] = ['id' => $id, 'label' => $label];
                if (is_array($category) && isset($category['items']) && is_array($category['items'])) {
                    foreach ($category['items'] as $nestedItemIndex => $nestedItem) {
                        $text = is_array($nestedItem)
                            ? trim((string)($nestedItem['text'] ?? $nestedItem['label'] ?? $nestedItem['value'] ?? ''))
                            : trim((string)$nestedItem);
                        if ($text !== '') {
                            $itemsFromNestedCategories[] = [
                                'id' => triviax_simple_id(
                                    is_array($nestedItem) ? ($nestedItem['id'] ?? $text) : $text,
                                    'item_' . (count($itemsFromNestedCategories) + 1)
                                ),
                                'text' => $text,
                                'categoryId' => $id
                            ];
                        }
                    }
                }
            }
        }
        $categoryByLabel = [];
        foreach ($normalizedCategories as $category) {
            $categoryByLabel[$category['label']] = $category['id'];
            $categoryByLabel[$category['id']] = $category['id'];
        }

        $normalizedItems = [];
        $sourceItems = $challenge['items'] ?? $itemsFromNestedCategories;
        foreach ($sourceItems as $itemIndex => $item) {
            if (!is_array($item)) {
                $text = trim((string)$item);
                if ($text === '') {
                    continue;
                }
                $normalizedItems[] = [
                    'id' => triviax_simple_id($text, 'item_' . ($itemIndex + 1)),
                    'text' => $text,
                    'categoryId' => ''
                ];
                continue;
            }
            $text = trim((string)($item['text'] ?? $item['label'] ?? ''));
            $categoryValue = (string)($item['categoryId'] ?? $item['category'] ?? '');
            $categoryId = $categoryByLabel[$categoryValue] ?? triviax_simple_id($categoryValue, '');
            if ($text !== '') {
                $normalizedItems[] = [
                    'id' => triviax_simple_id($item['id'] ?? $text, 'item_' . ($itemIndex + 1)),
                    'text' => $text,
                    'categoryId' => $categoryId
                ];
            }
        }

        $challenge['categories'] = $normalizedCategories;
        $challenge['items'] = $normalizedItems;
    }

    if ($type === 'fill_blank') {
        $promptText = triviax_challenge_prompt_text($challenge);
        $options = triviax_normalize_choice_options($challenge['options'] ?? $challenge['answers'] ?? []);
        $correctOption = null;
        foreach ($options as $option) {
            if (!empty($option['correct'])) {
                $correctOption = $option['text'];
                break;
            }
        }

        if (isset($challenge['blanks']) && triviax_is_list_array($challenge['blanks'])) {
            $normalizedBlanks = [];
            foreach ($challenge['blanks'] as $blankIndex => $blankConfig) {
                if (!is_array($blankConfig)) {
                    continue;
                }
                $blankId = trim((string)($blankConfig['id'] ?? $blankConfig['name'] ?? ('blank' . ($blankIndex + 1))));
                $blankOptions = $blankConfig['options'] ?? [];
                $blankCorrect = $blankConfig['correct'] ?? $blankConfig['answer'] ?? $blankConfig['value'] ?? null;
                if ($blankId !== '' && is_array($blankOptions) && $blankCorrect !== null) {
                    $normalizedBlanks[$blankId] = [
                        'options' => array_values(array_map('strval', $blankOptions)),
                        'correct' => (string)$blankCorrect
                    ];
                }
            }
            $challenge['blanks'] = $normalizedBlanks;
        }

        if (!isset($challenge['blanks']) && count($options) >= 2 && $correctOption !== null) {
            if (strpos($promptText, '[blank1]') === false) {
                $promptText = preg_replace('/_{2,}|…|\.\.\./u', '[blank1]', $promptText, 1, $replacements);
                if (empty($replacements)) {
                    $promptText .= ' [blank1]';
                }
                $challenge['prompt']['text'] = $promptText;
            }
            $challenge['blanks'] = [
                'blank1' => [
                    'options' => array_values(array_map(function($option) { return $option['text']; }, $options)),
                    'correct' => $correctOption
                ]
            ];
            unset($challenge['options'], $challenge['answers']);
        }
    }

    return $challenge;
}

function triviax_normalize_project_json_data($data) {
    if (!is_array($data)) {
        return $data;
    }
    $challenges = $data['challenges'] ?? ($data['questions'] ?? []);
    if (is_array($challenges)) {
        $data['challenges'] = array_map('triviax_normalize_challenge_data', $challenges, array_keys($challenges));
        unset($data['questions']);
    }
    return $data;
}

function triviax_validate_challenge($challenge, $index = 0) {
    if (!is_array($challenge)) {
        throw new Exception('El desafio #' . ($index + 1) . ' debe ser un objeto.');
    }

    $id = $challenge['id'] ?? ('#' . ($index + 1));
    $prefix = "El desafio {$id}";
    $rawType = $challenge['type'] ?? 'multiple_choice';
    $type = triviax_normalize_challenge_type($rawType);

    if (triviax_challenge_prompt_text($challenge) === '') {
        throw new Exception("{$prefix} no tiene consigna o texto principal.");
    }

    if ($type === 'multiple_choice') {
        $options = $challenge['options'] ?? ($challenge['answers'] ?? []);
        if (!is_array($options) || count($options) < 3 || count($options) > 4) {
            throw new Exception("{$prefix} de opcion multiple debe tener entre 3 y 4 opciones.");
        }
        $correctCount = 0;
        foreach ($options as $option) {
            if (!empty($option['correct'])) {
                $correctCount++;
            } elseif (isset($challenge['answer']['correctOptionId'], $option['id']) && $challenge['answer']['correctOptionId'] === $option['id']) {
                $correctCount++;
            }
        }
        if ($correctCount !== 1) {
            throw new Exception("{$prefix} debe tener una unica respuesta correcta.");
        }
    } elseif ($type === 'true_false') {
        $hasBooleanAnswer = isset($challenge['answer']['value']) && is_bool($challenge['answer']['value']);
        $correctCount = 0;
        foreach (($challenge['answers'] ?? []) as $answer) {
            if (!empty($answer['correct'])) {
                $correctCount++;
            }
        }
        if (!$hasBooleanAnswer && $correctCount !== 1) {
            throw new Exception("{$prefix} verdadero/falso requiere una respuesta booleana o una opcion correcta unica.");
        }
    } elseif ($type === 'matching_pairs') {
        $pairs = $challenge['pairs'] ?? [];
        if (!is_array($pairs) || count($pairs) < 2 || count($pairs) > 6) {
            throw new Exception("{$prefix} de asociar pares requiere entre 2 y 6 pares.");
        }
        foreach ($pairs as $pairIndex => $pair) {
            if (trim((string)($pair['left'] ?? '')) === '' || trim((string)($pair['right'] ?? '')) === '') {
                throw new Exception("{$prefix} tiene lados vacios en el par " . ($pairIndex + 1) . '.');
            }
        }
    } elseif ($type === 'sequence_order') {
        $items = $challenge['items'] ?? [];
        $order = $challenge['answer']['order'] ?? $items;
        if (!is_array($items) || count($items) < 3 || count($items) > 8) {
            throw new Exception("{$prefix} de ordenar elementos requiere entre 3 y 8 elementos.");
        }
        foreach ($order as $item) {
            if (!in_array($item, $items, true)) {
                throw new Exception("{$prefix} incluye en el orden correcto un elemento que no existe.");
            }
        }
    } elseif ($rawType === 'classification') {
        $categories = $challenge['categories'] ?? [];
        $items = $challenge['items'] ?? [];
        if (!is_array($categories) || count($categories) < 2) {
            throw new Exception("{$prefix} de clasificacion requiere al menos 2 categorias.");
        }
        if (!is_array($items) || count($items) < 4) {
            throw new Exception("{$prefix} de clasificacion requiere al menos 4 elementos.");
        }
        $categoryIds = [];
        foreach ($categories as $category) {
            $categoryIds[] = (string)($category['id'] ?? '');
        }
        foreach ($items as $item) {
            if (!in_array((string)($item['categoryId'] ?? ''), $categoryIds, true)) {
                throw new Exception("{$prefix} tiene un elemento sin categoria valida.");
            }
        }
    } elseif ($type === 'drag_drop') {
        $draggables = $challenge['draggables'] ?? [];
        $dropzones = $challenge['dropzones'] ?? [];
        $answer = $challenge['answer'] ?? [];
        if (!is_array($dropzones) || count($dropzones) < 2) {
            throw new Exception("{$prefix} de clasificar elementos requiere al menos 2 categorias.");
        }
        if (!is_array($draggables) || count($draggables) < 4) {
            throw new Exception("{$prefix} de clasificar elementos requiere al menos 4 elementos.");
        }
        $zoneIds = [];
        foreach ($dropzones as $zone) {
            $zoneIds[] = (string)($zone['id'] ?? '');
        }
        foreach ($draggables as $item) {
            $itemId = (string)($item['id'] ?? '');
            if (!in_array((string)($answer[$itemId] ?? ''), $zoneIds, true)) {
                throw new Exception("{$prefix} tiene un elemento sin respuesta valida.");
            }
        }
    } elseif ($type === 'fill_blank') {
        $promptText = triviax_challenge_prompt_text($challenge);
        preg_match_all('/\[(blank\d+)\]/', $promptText, $matches);
        $markers = $matches[1] ?? [];
        $blanks = $challenge['blanks'] ?? [];
        if (count($markers) === 0) {
            throw new Exception("{$prefix} de completar espacios requiere marcadores como [blank1].");
        }
        foreach ($markers as $marker) {
            $blank = $blanks[$marker] ?? null;
            if (!is_array($blank)) {
                throw new Exception("{$prefix} no define {$marker}.");
            }
            $options = $blank['options'] ?? [];
            $correct = $blank['correct'] ?? null;
            if (!is_array($options) || count($options) < 2 || !in_array($correct, $options, true)) {
                throw new Exception("{$prefix} tiene una configuracion invalida para {$marker}.");
            }
        }
    }
}

function triviax_validate_project_json_data($data) {
    $data = triviax_normalize_project_json_data($data);
    if (!is_array($data)) {
        throw new Exception('El archivo proyecto.json no contiene JSON valido.');
    }
    if (!isset($data['challenges']) || !is_array($data['challenges']) || count($data['challenges']) === 0) {
        throw new Exception('El archivo proyecto.json no contiene desafios validos.');
    }
    foreach ($data['challenges'] as $index => $challenge) {
        triviax_validate_challenge($challenge, $index);
    }
}

function triviax_parse_project_json($content) {
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('El archivo proyecto.json no contiene JSON valido: ' . json_last_error_msg());
    }
    $data = triviax_normalize_project_json_data($data);
    triviax_validate_project_json_data($data);

    $metadataDefaults = [
        'title' => 'TRIVIAX',
        'author' => '',
        'nivel' => '',
        'obs' => '',
        'date' => '',
        'mail' => '',
        'id' => ''
    ];

    return [
        'metadata' => array_merge($metadataDefaults, $data['metadata'] ?? []),
        'challenges' => $data['challenges'],
        'board' => $data['board'] ?? []
    ];
}

function triviax_validate_question($question, $strict = true) {
    $count = count($question['answers']);
    if ($count < 3 || $count > 4) {
        throw new Exception("La pregunta {$question['id']} tiene {$count} respuestas. Debe tener entre 3 y 4 respuestas.");
    }

    $correctCount = 0;
    foreach ($question['answers'] as $answer) {
        if (!empty($answer['correct'])) {
            $correctCount++;
        }
    }

    if ($correctCount === 0) {
        throw new Exception("La pregunta {$question['id']} no tiene una respuesta correcta marcada.");
    }

    if ($correctCount > 1) {
        throw new Exception("La pregunta {$question['id']} tiene mas de una respuesta correcta.");
    }
}

function triviax_clean_uploaded_questions($content) {
    if (substr($content, 0, 3) === pack('CCC', 0xef, 0xbb, 0xbf)) {
        $content = substr($content, 3);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $cleanedLines = [];
    $firstQuestionFound = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!$firstQuestionFound) {
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(title|author|nivel|obs|date|mail|id)\s*:\s*(.*)$/i', $trimmed)) {
                continue;
            }
            if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed)) {
                $firstQuestionFound = true;
            }
        }
        $cleanedLines[] = $line;
    }

    return implode("\r\n", $cleanedLines);
}
