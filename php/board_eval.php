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
 * Para opción múltiple / multimedia: ¿el texto elegido (selectedText) coincide
 * con la opción correcta? Devuelve true/false si se puede decidir, o null si no
 * (tipo sin opciones, o selectedText que no empareja con ninguna → fallback).
 */
function triviax_board_choice_is_correct(array $challenge, string $selectedText): ?bool {
    $norm = static function ($s): string {
        $s = (string)$s;
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        return (string)preg_replace('/\s+/u', ' ', trim($s));
    };
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
 * Recalcula veredicto y puntaje de forma autoritativa.
 *
 * @return array{resultado:string, points_delta:int, overridden:bool}
 */
function triviax_board_authoritative_result(
    string $baseProjectsDir, string $slug, string $challengeKey,
    string $challengeType, string $resultado, int $pointsDelta, string $selectedText,
    ?PDO $pdo = null
): array {
    $overridden = false;
    $challenge  = triviax_board_find_challenge($baseProjectsDir, $slug, $challengeKey, $pdo);

    // Solo se intenta degradar un "correct" reclamado, y solo en tipos de opción.
    if ($resultado === 'correct' && $challenge !== null) {
        $type = triviax_normalize_challenge_type($challenge['type'] ?? ($challengeType ?: 'multiple_choice'));
        if (in_array($type, ['multiple_choice', 'media_choice'], true)) {
            if (triviax_board_choice_is_correct($challenge, $selectedText) === false) {
                $resultado  = 'incorrect';
                $overridden = true;
            }
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
