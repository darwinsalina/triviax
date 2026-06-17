<?php
/**
 * TRIVIAX — php/project_sync.php
 * Funciones compartidas para sincronizar proyectos del filesystem con la tabla `proyectos`.
 * Usadas por tools/sync_projects.php y admin.php.
 */

declare(strict_types=1);

/**
 * Extrae metadata de una carpeta de proyecto (proyecto.json o preguntas.txt).
 */
function triviax_extract_project_meta(string $dir, bool $hasJson): array {
    $defaults = [
        'title'      => basename($dir),
        'author'     => '',
        'nivel'      => '',
        'obs'        => '',
        'mail'       => '',
        'board_type' => 'serpentine',
    ];

    if ($hasJson) {
        $raw = @file_get_contents($dir . '/proyecto.json');
        if ($raw !== false) {
            $data = @json_decode($raw, true);
            if (is_array($data)) {
                $m = $data['metadata'] ?? [];
                return [
                    'title'      => trim($m['title']  ?? $defaults['title']),
                    'author'     => trim($m['author'] ?? ''),
                    'nivel'      => trim($m['nivel']  ?? ''),
                    'obs'        => trim($m['obs']    ?? ''),
                    'mail'       => trim($m['mail']   ?? ''),
                    'board_type' => trim($data['board']['type'] ?? 'serpentine'),
                ];
            }
        }
    }

    // Formato txt: leer cabecera TITLE/AUTHOR/etc.
    $hasTxt = is_file($dir . '/preguntas.txt');
    if ($hasTxt) {
        $raw = @file_get_contents($dir . '/preguntas.txt', false, null, 0, 512);
        if ($raw !== false) {
            $lines = explode("\n", $raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'TITLE:') === 0)  $defaults['title']  = trim(substr($line, 6));
                if (stripos($line, 'AUTHOR:') === 0) $defaults['author'] = trim(substr($line, 7));
                if (stripos($line, 'NIVEL:') === 0)  $defaults['nivel']  = trim(substr($line, 6));
                if (stripos($line, 'OBS:') === 0)    $defaults['obs']    = trim(substr($line, 4));
                if (stripos($line, 'MAIL:') === 0)   $defaults['mail']   = trim(substr($line, 5));
            }
        }
    }

    return $defaults;
}

/**
 * Sincroniza una carpeta de proyecto individual con la tabla `proyectos`.
 *
 * @return array ['action' => 'new'|'updated'|'skip'|'error', 'slug' => string, 'msg' => string]
 */
function triviax_sync_single_project(
    PDO    $pdo,
    string $slug,
    string $dir,
    bool   $inTrash    = false,
    ?int   $docenteId  = null
): array {
    $result = ['action' => 'skip', 'slug' => $slug, 'msg' => ''];

    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
        $result['action'] = 'error';
        $result['msg']    = 'Slug inválido: "' . $slug . '"';
        return $result;
    }

    $hasJson = is_file($dir . '/proyecto.json');
    $hasTxt  = is_file($dir . '/preguntas.txt');

    if (!$hasJson && !$hasTxt) {
        $result['action'] = 'skip';
        $result['msg']    = '"' . $slug . '": sin proyecto.json ni preguntas.txt — omitido';
        return $result;
    }

    $formato   = $hasJson ? 'json' : 'txt';
    $meta      = triviax_extract_project_meta($dir, $hasJson);
    $trashVal  = $inTrash ? 1 : 0;
    $trashedAt = $inTrash ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare('SELECT id, docente_id FROM proyectos WHERE id = ?');
    $stmt->execute([$slug]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare(
            'UPDATE proyectos SET
                title      = ?,
                author     = ?,
                nivel      = ?,
                obs        = ?,
                mail       = ?,
                formato    = ?,
                in_trash   = ?,
                trashed_at = ?,
                docente_id = COALESCE(docente_id, ?),
                updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $meta['title'], $meta['author'], $meta['nivel'], $meta['obs'], $meta['mail'],
            $formato, $trashVal, $trashedAt,
            $docenteId,
            $slug,
        ]);
        $result['action'] = 'updated';
        $result['msg']    = '"' . $slug . '" [' . $formato . '] actualizado' . ($inTrash ? ' (papelera)' : '');
    } else {
        $pdo->prepare(
            'INSERT INTO proyectos
                (id, docente_id, title, author, nivel, obs, mail, board_type, formato,
                 in_trash, trashed_at, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $slug, $docenteId,
            $meta['title'], $meta['author'], $meta['nivel'], $meta['obs'], $meta['mail'],
            $meta['board_type'], $formato,
            $trashVal, $trashedAt,
        ]);
        $result['action'] = 'new';
        $result['msg']    = '"' . $slug . '" [' . $formato . '] registrado' . ($inTrash ? ' (papelera)' : '');
    }

    return $result;
}

/**
 * Determina si un docente puede gestionar (editar/borrar) un proyecto clásico.
 *
 * Reglas:
 *  - El superadmin siempre puede.
 *  - El dueño registrado (proyectos.docente_id) puede.
 *  - Si el proyecto no tiene dueño registrado (docente_id NULL, propio del
 *    modelo filesystem-first heredado) o aún no está en la BD, se permite, para
 *    no romper proyectos antiguos. La auth de docente ya es requisito previo.
 *  - Si pertenece a OTRO docente, se deniega.
 *  - Ante error de consulta se deniega (modo seguro), salvo superadmin.
 *
 * @param PDO|null $pdo        Conexión (null = panel sin BD: no hay datos de propiedad).
 * @param string   $slug       Id/carpeta del proyecto.
 * @param int|null $docenteId  Docente autenticado.
 */
function triviax_docente_puede_gestionar_proyecto(?PDO $pdo, string $slug, ?int $docenteId): bool {
    if (function_exists('triviax_es_superadmin') && triviax_es_superadmin()) {
        return true;
    }
    if ($pdo === null) {
        return true; // sin BD no hay propiedad que verificar
    }
    try {
        $stmt = $pdo->prepare('SELECT docente_id FROM proyectos WHERE id = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return true; // aún no registrado (solo filesystem)
        }
        $owner = isset($row['docente_id']) && $row['docente_id'] !== null ? (int)$row['docente_id'] : null;
        if ($owner === null) {
            return true; // sin dueño registrado (heredado)
        }
        return $docenteId !== null && $owner === $docenteId;
    } catch (\Throwable $e) {
        return false; // modo seguro: ante error, denegar
    }
}

/**
 * Marca un proyecto como en papelera en la BD (cuando se mueve su carpeta a trash/).
 */
function triviax_mark_project_trashed(PDO $pdo, string $slug): void {
    $pdo->prepare(
        'UPDATE proyectos SET in_trash = 1, trashed_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([$slug]);
}

/**
 * Sincroniza TODAS las carpetas de proyectos/ (y opcionalmente trash/) con la BD.
 *
 * @return array ['new' => int, 'updated' => int, 'skipped' => int, 'errors' => int, 'trash' => int, 'log' => array]
 */
function triviax_sync_all_projects(
    PDO    $pdo,
    string $proyDir,
    string $trashDir  = '',
    ?int   $docenteId = null
): array {
    $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trash' => 0, 'log' => []];

    if (!$proyDir || !is_dir($proyDir)) {
        $stats['log'][] = ['type' => 'error', 'msg' => 'Carpeta proyectos/ no encontrada.'];
        return $stats;
    }

    foreach (array_diff(scandir($proyDir), ['.', '..']) as $entry) {
        $fullPath = $proyDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($fullPath)) continue;
        try {
            $r = triviax_sync_single_project($pdo, $entry, $fullPath, false, $docenteId);
            $stats['log'][] = ['type' => $r['action'], 'msg' => $r['msg']];
            match ($r['action']) {
                'new'     => $stats['new']++,
                'updated' => $stats['updated']++,
                'skip'    => $stats['skipped']++,
                'error'   => $stats['errors']++,
                default   => null,
            };
        } catch (\Throwable $e) {
            $stats['errors']++;
            $stats['log'][] = ['type' => 'error', 'msg' => '"' . $entry . '": ' . $e->getMessage()];
        }
    }

    if ($trashDir && is_dir($trashDir)) {
        foreach (array_diff(scandir($trashDir), ['.', '..']) as $entry) {
            $fullPath = $trashDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($fullPath)) continue;
            try {
                $r = triviax_sync_single_project($pdo, $entry, $fullPath, true, $docenteId);
                $stats['log'][] = ['type' => $r['action'], 'msg' => $r['msg']];
                if (in_array($r['action'], ['new', 'updated'], true)) $stats['trash']++;
                match ($r['action']) {
                    'new'     => $stats['new']++,
                    'updated' => $stats['updated']++,
                    'skip'    => $stats['skipped']++,
                    'error'   => $stats['errors']++,
                    default   => null,
                };
            } catch (\Throwable $e) {
                $stats['errors']++;
                $stats['log'][] = ['type' => 'error', 'msg' => '"' . $entry . '": ' . $e->getMessage()];
            }
        }
    }

    return $stats;
}
