<?php
/**
 * TRIVIAX — tools/sync_projects.php
 * Sincroniza las carpetas de proyectos del filesystem con la tabla `proyectos` en BD.
 *
 * ACCESO: Solo superadmin autenticado.
 * SEGURIDAD: No modifica el filesystem. Solo lee carpetas y actualiza BD.
 * COMPATIBILIDAD: Soporta proyectos json (proyecto.json) y legados (preguntas.txt).
 *
 * USO: Acceder desde el navegador estando logueado como superadmin,
 *      o ejecutar desde CLI: php tools/sync_projects.php
 */

declare(strict_types=1);

// ── Entorno CLI vs web ────────────────────────────────────────
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Protección web: solo superadmin
    session_start();
    require_once __DIR__ . '/../php/auth.php';
    require_once __DIR__ . '/../php/db.php';
    require_once __DIR__ . '/../php/project_sync.php';

    if (
        empty($_SESSION['triviax_user_id']) ||
        ($_SESSION['triviax_rol'] ?? '') !== 'superadmin'
    ) {
        http_response_code(403);
        die('Acceso denegado. Solo disponible para superadmin.');
    }
} else {
    // CLI: carga directa
    require_once __DIR__ . '/../php/auth.php';
    require_once __DIR__ . '/../php/db.php';
    require_once __DIR__ . '/../php/project_sync.php';
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
$proyDir   = realpath(__DIR__ . '/../proyectos');
$trashDir  = realpath(__DIR__ . '/../trash');

if (!$proyDir || !is_dir($proyDir)) {
    out('No se encontró la carpeta proyectos/.', 'error');
    exit(1);
}

// ── Contadores ────────────────────────────────────────────────
$stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trash' => 0];

// ── Header de salida web ──────────────────────────────────────
if (!$isCli) {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Sync Proyectos — TRIVIAX</title>
    <style>
        body { font-family: sans-serif; background:#0a0f1e; color:#e2e8f0; padding:2rem; }
        h1 { color:#a5b4fc; } h2 { color:#818cf8; margin-top:2rem; }
        .summary { background:#1e293b; border-radius:8px; padding:1rem; margin-top:1.5rem; }
        .sum-ok { color:#10b981; } .sum-warn { color:#f59e0b; } .sum-err { color:#ef4444; }
    </style></head><body>
    <h1>🔄 Sincronización de proyectos</h1>
    <p style="color:#64748b;">Filesystem → Base de datos</p>
    <h2>Proyectos activos</h2>';
}

// Las funciones sync_project_folder y extract_metadata fueron movidas a
// php/project_sync.php como triviax_sync_single_project y triviax_extract_project_meta.

// ══════════════════════════════════════════════════════════════
// PROCESAR proyectos activos
// ══════════════════════════════════════════════════════════════
$entries = array_diff(scandir($proyDir), ['.', '..']);
foreach ($entries as $entry) {
    $fullPath = $proyDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($fullPath)) continue;

    try {
        $r = triviax_sync_single_project($pdo, $entry, $fullPath, false);
        if ($r['action'] === 'new')         { $stats['new']++;     out($r['msg'], 'ok'); }
        elseif ($r['action'] === 'updated') { $stats['updated']++; out($r['msg'], 'info'); }
        elseif ($r['action'] === 'skip')    { $stats['skipped']++; out($r['msg'], 'skip'); }
        elseif ($r['action'] === 'error')   { $stats['errors']++;  out($r['msg'], 'error'); }
    } catch (\Exception $e) {
        $stats['errors']++;
        out('"' . $entry . '": excepción — ' . $e->getMessage(), 'error');
    }
}

// ══════════════════════════════════════════════════════════════
// PROCESAR papelera
// ══════════════════════════════════════════════════════════════
if (!$isCli) echo '<h2>Proyectos en papelera</h2>';
else echo PHP_EOL . '[INFO] Papelera:' . PHP_EOL;

if ($trashDir && is_dir($trashDir)) {
    $trashEntries = array_diff(scandir($trashDir), ['.', '..']);
    foreach ($trashEntries as $entry) {
        $fullPath = $trashDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($fullPath)) continue;

        try {
            $r = triviax_sync_single_project($pdo, $entry, $fullPath, true);
            if ($r['action'] === 'new')         { $stats['new']++;     $stats['trash']++; out($r['msg'], 'ok'); }
            elseif ($r['action'] === 'updated') { $stats['updated']++; $stats['trash']++; out($r['msg'], 'info'); }
            elseif ($r['action'] === 'skip')    { $stats['skipped']++; out($r['msg'], 'skip'); }
            elseif ($r['action'] === 'error')   { $stats['errors']++;  out($r['msg'], 'error'); }
        } catch (\Exception $e) {
            $stats['errors']++;
            out('"' . $entry . '": excepción — ' . $e->getMessage(), 'error');
        }
    }
} else {
    out('Carpeta trash/ no encontrada o vacía.', 'skip');
}

// ══════════════════════════════════════════════════════════════
// RESUMEN FINAL
// ══════════════════════════════════════════════════════════════
$total = $stats['new'] + $stats['updated'] + $stats['skipped'];

if ($isCli) {
    echo PHP_EOL;
    echo '═══════════════════════════════' . PHP_EOL;
    echo ' Sincronización completada' . PHP_EOL;
    echo '═══════════════════════════════' . PHP_EOL;
    echo ' Nuevos en BD:     ' . $stats['new']     . PHP_EOL;
    echo ' Actualizados:     ' . $stats['updated'] . PHP_EOL;
    echo ' Omitidos:         ' . $stats['skipped'] . PHP_EOL;
    echo ' En papelera:      ' . $stats['trash']   . PHP_EOL;
    echo ' Errores:          ' . $stats['errors']  . PHP_EOL;
    echo ' Total procesados: ' . $total            . PHP_EOL;
    exit($stats['errors'] > 0 ? 1 : 0);
} else {
    echo '<div class="summary">';
    echo '<h2 style="margin-top:0">📊 Resumen</h2>';
    echo '<p class="sum-ok">✔ Nuevos registrados en BD: <strong>' . $stats['new']     . '</strong></p>';
    echo '<p style="color:#a5b4fc">↻ Actualizados: <strong>'      . $stats['updated'] . '</strong></p>';
    echo '<p class="sum-warn">⊘ Omitidos (sin archivos): <strong>' . $stats['skipped'] . '</strong></p>';
    echo '<p style="color:#a5b4fc">🗑 En papelera: <strong>'       . $stats['trash']   . '</strong></p>';
    if ($stats['errors'] > 0) {
        echo '<p class="sum-err">⚠ Errores: <strong>' . $stats['errors'] . '</strong></p>';
    }
    echo '<hr style="border-color:#334155;margin:1rem 0;">';
    echo '<p style="color:#64748b;font-size:0.85rem;">Total carpetas procesadas: ' . $total . '</p>';
    echo '<p style="margin-top:1rem;"><a href="../panel/super.php?tab=projects"
        style="background:#6366f1;color:#fff;padding:8px 18px;border-radius:6px;text-decoration:none;font-weight:700;">
        → Volver al panel superadmin</a></p>';
    echo '</div></body></html>';
}
