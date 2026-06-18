<?php
/**
 * TRIVIAX — tools/import_projects.php
 * Importa los DESAFÍOS de cada proyecto del filesystem a la tabla `desafios`
 * (área crítica #7). Carga inicial masiva / reconciliación.
 *
 * Para cada proyecto activo: primero sincroniza su metadata en `proyectos`
 * (triviax_sync_single_project) — necesario porque `desafios.proyecto_id` tiene
 * FK a `proyectos.id` — y luego hace el upsert idempotente de sus desafíos
 * (triviax_import_project). No modifica el filesystem.
 *
 * ACCESO: Solo superadmin autenticado (web) o CLI.
 * USO: php tools/import_projects.php   |   navegador logueado como superadmin.
 */

declare(strict_types=1);

// ── Entorno CLI vs web ────────────────────────────────────────
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    session_start();
    require_once __DIR__ . '/../php/auth.php';
    require_once __DIR__ . '/../php/db.php';
    require_once __DIR__ . '/../php/project_sync.php';
    require_once __DIR__ . '/../php/project_import.php';

    if (
        empty($_SESSION['triviax_user_id']) ||
        ($_SESSION['triviax_rol'] ?? '') !== 'superadmin'
    ) {
        http_response_code(403);
        die('Acceso denegado. Solo disponible para superadmin.');
    }
} else {
    require_once __DIR__ . '/../php/auth.php';
    require_once __DIR__ . '/../php/db.php';
    require_once __DIR__ . '/../php/project_sync.php';
    require_once __DIR__ . '/../php/project_import.php';
}

// ── Helpers de salida ─────────────────────────────────────────
function out(string $msg, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        $prefix = match($type) {
            'ok'    => '[OK]   ',
            'warn'  => '[WARN] ',
            'error' => '[ERR]  ',
            'skip'  => '[SKIP] ',
            default => '[INFO] ',
        };
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = match($type) {
            'ok'    => '#10b981',
            'warn'  => '#f59e0b',
            'error' => '#ef4444',
            'skip'  => '#6b7280',
            default => '#a5b4fc',
        };
        echo '<div style="color:' . $color . ';font-family:monospace;font-size:0.9rem;margin:2px 0;">'
            . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

// ── Inicializar BD ────────────────────────────────────────────
$pdo = null;
try {
    $pdo = triviax_db();
} catch (\Exception $e) {
    out('No se pudo conectar a la BD: ' . $e->getMessage(), 'error');
    exit(1);
}

// ── Directorios ───────────────────────────────────────────────
$proyDir = realpath(__DIR__ . '/../proyectos');
if (!$proyDir || !is_dir($proyDir)) {
    out('No se encontró la carpeta proyectos/.', 'error');
    exit(1);
}

// ── Contadores ────────────────────────────────────────────────
$stats = ['projects' => 0, 'imported' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];

// ── Header de salida web ──────────────────────────────────────
if (!$isCli) {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Importar desafíos — TRIVIAX</title>
    <style>
        body { font-family: sans-serif; background:#0a0f1e; color:#e2e8f0; padding:2rem; }
        h1 { color:#a5b4fc; } h2 { color:#818cf8; margin-top:2rem; }
        .summary { background:#1e293b; border-radius:8px; padding:1rem; margin-top:1.5rem; }
        .sum-ok { color:#10b981; } .sum-warn { color:#f59e0b; } .sum-err { color:#ef4444; }
    </style></head><body>
    <h1>📥 Importación de desafíos</h1>
    <p style="color:#64748b;">Filesystem → Base de datos (tabla <code>desafios</code>)</p>
    <h2>Proyectos activos</h2>';
}

// ══════════════════════════════════════════════════════════════
// PROCESAR proyectos activos: sync (FK) + import
// ══════════════════════════════════════════════════════════════
$entries = array_diff(scandir($proyDir), ['.', '..']);
foreach ($entries as $entry) {
    $fullPath = $proyDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($fullPath)) continue;

    // Solo proyectos con contenido jugable.
    if (!is_file($fullPath . '/proyecto.json') && !is_file($fullPath . '/preguntas.txt')) {
        $stats['skipped']++;
        out('"' . $entry . '": sin proyecto.json ni preguntas.txt — omitido', 'skip');
        continue;
    }

    try {
        // 1) Garantizar la fila en `proyectos` (FK de `desafios`).
        $sync = triviax_sync_single_project($pdo, $entry, $fullPath, false);
        if ($sync['action'] === 'error') {
            $stats['errors']++;
            out('"' . $entry . '": sync — ' . $sync['msg'], 'error');
            continue;
        }

        // 2) Importar (upsert) los desafíos.
        $res = triviax_import_project($pdo, $entry, $fullPath);
        if (!$res['ok']) {
            $stats['errors']++;
            out('"' . $entry . '": import — ' . ($res['error'] ?? 'error desconocido'), 'error');
            continue;
        }

        $stats['projects']++;
        $stats['imported'] += $res['imported'];
        $stats['deleted']  += $res['deleted'];
        out(sprintf('"%s": %d desafíos importados, %d eliminados', $entry, $res['imported'], $res['deleted']), 'ok');
    } catch (\Throwable $e) {
        $stats['errors']++;
        out('"' . $entry . '": excepción — ' . $e->getMessage(), 'error');
    }
}

// ══════════════════════════════════════════════════════════════
// RESUMEN FINAL
// ══════════════════════════════════════════════════════════════
if ($isCli) {
    echo PHP_EOL;
    echo '═══════════════════════════════' . PHP_EOL;
    echo ' Importación completada' . PHP_EOL;
    echo '═══════════════════════════════' . PHP_EOL;
    echo ' Proyectos importados: ' . $stats['projects'] . PHP_EOL;
    echo ' Desafíos (upsert):    ' . $stats['imported'] . PHP_EOL;
    echo ' Desafíos eliminados:  ' . $stats['deleted']  . PHP_EOL;
    echo ' Omitidos:             ' . $stats['skipped']  . PHP_EOL;
    echo ' Errores:              ' . $stats['errors']   . PHP_EOL;
    exit($stats['errors'] > 0 ? 1 : 0);
} else {
    echo '<div class="summary">';
    echo '<h2 style="margin-top:0">📊 Resumen</h2>';
    echo '<p class="sum-ok">✔ Proyectos importados: <strong>' . $stats['projects'] . '</strong></p>';
    echo '<p style="color:#a5b4fc">➕ Desafíos (upsert): <strong>' . $stats['imported'] . '</strong></p>';
    echo '<p style="color:#a5b4fc">➖ Desafíos eliminados: <strong>' . $stats['deleted'] . '</strong></p>';
    echo '<p class="sum-warn">⊘ Omitidos (sin archivos): <strong>' . $stats['skipped'] . '</strong></p>';
    if ($stats['errors'] > 0) {
        echo '<p class="sum-err">⚠ Errores: <strong>' . $stats['errors'] . '</strong></p>';
    }
    echo '<hr style="border-color:#334155;margin:1rem 0;">';
    echo '<p style="margin-top:1rem;"><a href="../panel/super.php?tab=projects"
        style="background:#6366f1;color:#fff;padding:8px 18px;border-radius:6px;text-decoration:none;font-weight:700;">
        → Volver al panel superadmin</a></p>';
    echo '</div></body></html>';
}
