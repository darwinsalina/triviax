<?php
/**
 * TRIVIAX — php/project_import.php
 *
 * Importador filesystem → BD de los DESAFÍOS de un proyecto (área crítica #7).
 * Lee proyecto.json / preguntas.txt, normaliza con el parser de triviax_core y
 * hace upsert en la tabla `desafios` (una fila por desafío; el payload completo
 * normalizado va en `data_json`). Habilita la Etapa 2 de #1 (validación
 * server-side de todos los tipos contra la BD) y reportes/estadísticas en BD.
 *
 * Complementa a php/project_sync.php, que sincroniza solo la METADATA del
 * proyecto en la tabla `proyectos`. El proyecto debe existir en `proyectos`
 * (FK) antes de importar sus desafíos.
 *
 * La función de mapeo (triviax_map_challenge_to_row) es pura y unit-testeable
 * sin BD (ver tests/project_import_test.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/triviax_core.php';

/** Tipos válidos del ENUM `desafios.tipo`. */
function triviax_import_tipos_validos(): array {
    return [
        'multiple_choice', 'true_false', 'matching_pairs', 'sequence_order',
        'drag_drop', 'fill_blank', 'media_choice', 'image_hotspot', 'code_challenge',
    ];
}

/** Ordena solo claves asociativas para comparar JSON sin falsos desajustes. */
function triviax_canonicalize_json_value($value) {
    if (!is_array($value)) {
        return $value;
    }
    $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
    foreach ($value as $key => $item) {
        $value[$key] = triviax_canonicalize_json_value($item);
    }
    if (!$isList) {
        ksort($value);
    }
    return $value;
}

function triviax_project_challenges_fingerprint(array $challenges): string {
    return hash('sha256', (string)json_encode(
        triviax_canonicalize_json_value($challenges),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

/**
 * Mapea un desafío (ya normalizado por el parser) a una fila de `desafios`.
 * PURA: no toca BD ni filesystem. El payload completo se conserva en data_json.
 *
 * @return array{challenge_key:string, tipo:string, prompt_text:string,
 *               title:?string, difficulty:int, points:int, time_limit:?int,
 *               data_json:string, orden:int}
 */
function triviax_map_challenge_to_row(array $challenge, int $orden): array {
    $rawType = $challenge['type'] ?? ($challenge['originalType'] ?? 'multiple_choice');
    $tipo = triviax_normalize_challenge_type($rawType);
    if (!in_array($tipo, triviax_import_tipos_validos(), true)) {
        $tipo = 'multiple_choice'; // fallback seguro ante tipos no reconocidos
    }

    $difficulty = 1;
    if (isset($challenge['difficulty']) && is_numeric($challenge['difficulty'])) {
        $difficulty = max(1, min(3, (int)$challenge['difficulty'])); // tinyint 1..3
    }

    $points = 10;
    if (isset($challenge['points']) && is_numeric($challenge['points'])) {
        $points = max(0, min(65535, (int)$challenge['points']));
    }

    $timeLimit = null;
    foreach (['time_limit', 'timeLimit'] as $k) {
        if (isset($challenge[$k]) && is_numeric($challenge[$k])) {
            $timeLimit = max(0, min(65535, (int)$challenge[$k]));
            break;
        }
    }

    $title = null;
    if (isset($challenge['title']) && trim((string)$challenge['title']) !== '') {
        $title = mb_substr(trim((string)$challenge['title']), 0, 255);
    }

    return [
        'challenge_key' => mb_substr((string)($challenge['id'] ?? ''), 0, 100),
        'tipo'          => $tipo,
        'prompt_text'   => triviax_challenge_prompt_text($challenge),
        'title'         => $title,
        'difficulty'    => $difficulty,
        'points'        => $points,
        'time_limit'    => $timeLimit,
        'data_json'     => json_encode($challenge, JSON_UNESCAPED_UNICODE),
        'orden'         => max(0, min(65535, $orden)),
    ];
}

/**
 * Carga y normaliza los desafíos de un proyecto desde el filesystem.
 * @return array lista de desafíos normalizados (puede estar vacía).
 * @throws Exception si el archivo existe pero no es válido.
 */
function triviax_load_project_challenges(string $projectDir): array {
    $jsonPath = $projectDir . '/proyecto.json';
    $txtPath  = $projectDir . '/preguntas.txt';
    if (is_file($jsonPath)) {
        $parsed = triviax_parse_project_json((string)file_get_contents($jsonPath));
        return $parsed['challenges'] ?? [];
    }
    if (is_file($txtPath)) {
        $parsed = triviax_parse_questions_text((string)file_get_contents($txtPath));
        return $parsed['questions'] ?? ($parsed['challenges'] ?? []);
    }
    return [];
}

/**
 * Carga los desafíos de un proyecto DESDE LA BD (tabla `desafios`), en el mismo
 * formato que produce el parser del filesystem: el payload completo de cada
 * desafío se guardó en `data_json` al importar, así que decodificarlo reproduce
 * el desafío normalizado. Orden estable por `orden` (= orden del archivo).
 *
 * @return array|null lista de desafíos, o null si el proyecto no tiene ninguno
 *                    en BD (→ el llamador hace fallback a filesystem).
 */
function triviax_db_load_challenges(PDO $pdo, string $slug): ?array {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT data_json FROM desafios WHERE proyecto_id = ? ORDER BY orden, id');
    $stmt->execute([$slug]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        return null;
    }
    $challenges = [];
    foreach ($rows as $json) {
        $c = json_decode((string)$json, true);
        if (is_array($c)) {
            $challenges[] = $c;
        }
    }
    return $challenges ?: null;
}

/**
 * Busca un desafío puntual por su clave en la BD (tabla `desafios`). Devuelve el
 * desafío normalizado (data_json decodificado) o null si no está importado.
 */
function triviax_db_find_challenge(PDO $pdo, string $slug, string $challengeKey): ?array {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug) || $challengeKey === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT data_json FROM desafios WHERE proyecto_id = ? AND challenge_key = ? LIMIT 1');
    $stmt->execute([$slug, $challengeKey]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        return null;
    }
    $c = json_decode((string)$json, true);
    return is_array($c) ? $c : null;
}

/**
 * Importa (upsert idempotente) los desafíos de un proyecto a la tabla `desafios`.
 * Elimina de la BD los desafíos que ya no están en el archivo. Transaccional.
 * El proyecto debe existir en `proyectos` (FK).
 *
 * @return array{ok:bool, imported:int, deleted:int, slug:string, error?:string}
 */
function triviax_import_project(PDO $pdo, string $slug, string $projectDir): array {
    $out = ['ok' => false, 'imported' => 0, 'deleted' => 0, 'slug' => $slug];

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        $out['error'] = 'Slug inválido.';
        return $out;
    }

    try {
        $challenges = triviax_load_project_challenges($projectDir);
    } catch (\Throwable $e) {
        $out['error'] = 'No se pudo parsear el proyecto: ' . $e->getMessage();
        return $out;
    }

    try {
        $pdo->beginTransaction();

        $upsert = $pdo->prepare(
            'INSERT INTO desafios
                (proyecto_id, challenge_key, tipo, prompt_text, title, difficulty, points, time_limit, data_json, orden)
             VALUES (:p, :k, :t, :pt, :ti, :d, :pts, :tl, :dj, :o)
             ON DUPLICATE KEY UPDATE
                tipo = VALUES(tipo), prompt_text = VALUES(prompt_text), title = VALUES(title),
                difficulty = VALUES(difficulty), points = VALUES(points),
                time_limit = VALUES(time_limit), data_json = VALUES(data_json), orden = VALUES(orden)'
        );

        $keys = [];
        foreach ($challenges as $i => $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $row = triviax_map_challenge_to_row($challenge, $i);
            if ($row['challenge_key'] === '') {
                continue; // sin id no se puede mapear a la clave única
            }
            $upsert->execute([
                ':p'   => $slug,
                ':k'   => $row['challenge_key'],
                ':t'   => $row['tipo'],
                ':pt'  => $row['prompt_text'],
                ':ti'  => $row['title'],
                ':d'   => $row['difficulty'],
                ':pts' => $row['points'],
                ':tl'  => $row['time_limit'],
                ':dj'  => $row['data_json'],
                ':o'   => $row['orden'],
            ]);
            $keys[] = $row['challenge_key'];
            $out['imported']++;
        }

        // Eliminar de la BD los desafíos que ya no están en el archivo.
        if ($keys) {
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $del = $pdo->prepare("DELETE FROM desafios WHERE proyecto_id = ? AND challenge_key NOT IN ($ph)");
            $del->execute(array_merge([$slug], $keys));
        } else {
            $del = $pdo->prepare('DELETE FROM desafios WHERE proyecto_id = ?');
            $del->execute([$slug]);
        }
        $out['deleted'] = $del->rowCount();

        $pdo->commit();
        $out['ok'] = true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage();
    }

    return $out;
}
