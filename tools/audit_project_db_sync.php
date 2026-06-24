<?php
/**
 * Auditoría CLI de paridad entre proyectos/* y desafios.data_json.
 * No modifica archivos ni base de datos.
 *
 * Uso: php tools/audit_project_db_sync.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}

require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/project_import.php';
require_once __DIR__ . '/../php/board_eval.php';

$pdo = triviax_db();
$root = dirname(__DIR__) . '/proyectos';
$total = 0;
$synced = 0;
$missing = 0;
$mismatches = [];
$answerMismatchProjects = [];

function triviax_audit_first_diff_path($left, $right, string $path = ''): string {
    if (gettype($left) !== gettype($right)) {
        return $path . ' (tipo distinto)';
    }
    if (!is_array($left)) {
        return $left === $right ? '' : $path;
    }
    foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
        $next = $path . '[' . $key . ']';
        if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) {
            return $next . ' (campo ausente)';
        }
        $diff = triviax_audit_first_diff_path($left[$key], $right[$key], $next);
        if ($diff !== '') {
            return $diff;
        }
    }
    return '';
}

foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $slug) {
    $dir = $root . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($dir)
        || (!is_file($dir . '/proyecto.json') && !is_file($dir . '/preguntas.txt'))) {
        continue;
    }

    $total++;
    try {
        $fileChallenges = triviax_load_project_challenges($dir);
        $dbChallenges = triviax_db_load_challenges($pdo, $slug);
        if ($dbChallenges === null) {
            $missing++;
            $mismatches[] = $slug . ': sin filas en desafios';
            continue;
        }

        if (triviax_project_challenges_fingerprint($fileChallenges)
            === triviax_project_challenges_fingerprint($dbChallenges)) {
            $synced++;
        } else {
            $dbById = [];
            foreach ($dbChallenges as $challenge) {
                $dbById[(string)($challenge['id'] ?? '')] = $challenge;
            }
            $answerDiffs = [];
            foreach ($fileChallenges as $index => $challenge) {
                $id = (string)($challenge['id'] ?? '#' . ($index + 1));
                $dbChallenge = $dbById[$id] ?? null;
                if ($dbChallenge === null
                    || triviax_board_solution_for_client($challenge) !== triviax_board_solution_for_client($dbChallenge)) {
                    $answerDiffs[] = $id;
                }
            }
            if ($answerDiffs !== []) {
                $answerMismatchProjects[] = $slug . ': ' . count($answerDiffs)
                    . ' respuesta(s) diferentes [' . implode(', ', array_slice($answerDiffs, 0, 5)) . ']';
            }
            $mismatches[] = sprintf(
                '%s: archivo=%d, bd=%d (primera diferencia %s)',
                $slug,
                count($fileChallenges),
                count($dbChallenges),
                triviax_audit_first_diff_path($fileChallenges, $dbChallenges)
            );
        }
    } catch (Throwable $e) {
        $mismatches[] = $slug . ': error de lectura (' . $e->getMessage() . ')';
    }
}

echo "Proyectos: {$total}" . PHP_EOL;
echo "En sincronía exacta: {$synced}" . PHP_EOL;
echo "Sin desafíos en BD: {$missing}" . PHP_EOL;
echo 'Desfasados: ' . count($mismatches) . PHP_EOL;
echo 'Con respuestas diferentes: ' . count($answerMismatchProjects) . PHP_EOL;
foreach ($mismatches as $line) {
    echo '- ' . $line . PHP_EOL;
}
foreach ($answerMismatchProjects as $line) {
    echo '! ' . $line . PHP_EOL;
}

exit($mismatches === [] ? 0 : 1);
