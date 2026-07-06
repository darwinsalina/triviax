<?php
/**
 * TRIVIAX+ — Motor del Marketplace educativo (Épica 3).
 *
 * Publicación y clonación de actividades de tablero entre docentes sin
 * romper el aislamiento original: la clonación duplica la fila de
 * `proyectos`, sus `desafios` y la carpeta pública del proyecto
 * (proyecto.json / fondo.jpg / preguntas.txt), reasignando el docente y
 * dejando registro del origen (`clonado_desde_id`). Los datos privados del
 * proyecto original (reportes/, stats.json, estadísticas, versionado
 * evaluativo) NUNCA se copian ni se tocan.
 */

/** true si la migración 6.9 está aplicada (columna es_publico existe). */
function triviax_marketplace_available(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $pdo->query('SELECT es_publico FROM proyectos LIMIT 1');
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/** Slug seguro a partir de un título (minúsculas, sin acentos, guiones bajos). */
function triviax_marketplace_slugify(string $title): string {
    $slug = mb_strtolower(trim($title), 'UTF-8');
    $mapa = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];
    $slug = strtr($slug, $mapa);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim((string)$slug, '_');
    if ($slug === '') {
        $slug = 'actividad';
    }
    return mb_substr($slug, 0, 60);
}

/**
 * Identificador único para un proyecto clonado, con la convención del
 * importador: slug_YYMMDD_hex6. Cabe siempre en VARCHAR(100).
 */
function triviax_marketplace_new_project_id(string $title, ?string $fecha = null, ?string $suffix = null): string {
    $fecha  = $fecha ?: date('ymd');
    $suffix = $suffix ?: substr(bin2hex(random_bytes(4)), 0, 6);
    return triviax_marketplace_slugify($title) . '_' . $fecha . '_' . $suffix;
}

/**
 * Lista de actividades públicas del marketplace con filtros indexados.
 * $filtros: ['q' => texto, 'nivel' => string, 'orden' => 'recientes'|'descargas']
 */
function triviax_marketplace_list(PDO $pdo, array $filtros = [], int $viewerId = 0): array {
    $where  = 'WHERE p.es_publico = 1 AND p.in_trash = 0';
    $params = [];

    $q = trim((string)($filtros['q'] ?? ''));
    if ($q !== '') {
        $where .= ' AND (p.title LIKE ? OR p.author LIKE ? OR p.obs LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $nivel = trim((string)($filtros['nivel'] ?? ''));
    if ($nivel !== '') {
        $where .= ' AND p.nivel = ?';
        $params[] = $nivel;
    }
    $orden = ($filtros['orden'] ?? '') === 'descargas'
        ? 'p.descargas_count DESC, p.updated_at DESC'
        : 'p.updated_at DESC';

    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.author, p.nivel, p.obs, p.docente_id,
               p.descargas_count, p.clonado_desde_id, p.updated_at,
               COALESCE(u.nombre, '') AS docente_nombre,
               COALESCE(u.apellido, '') AS docente_apellido,
               (SELECT COUNT(*) FROM desafios d WHERE d.proyecto_id = p.id) AS num_desafios
        FROM proyectos p
        LEFT JOIN usuarios u ON u.id = p.docente_id
        {$where}
        ORDER BY {$orden}
        LIMIT 100
    ");
    $stmt->execute($params);

    return array_map(static function (array $row) use ($viewerId): array {
        $row['descargas_count'] = (int)$row['descargas_count'];
        $row['num_desafios']    = (int)$row['num_desafios'];
        $row['es_mio']          = $viewerId > 0 && (int)$row['docente_id'] === $viewerId;
        unset($row['docente_id']);
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Niveles distintos disponibles entre las actividades públicas (para filtros). */
function triviax_marketplace_levels(PDO $pdo): array {
    $stmt = $pdo->query("SELECT DISTINCT nivel FROM proyectos WHERE es_publico = 1 AND in_trash = 0 AND nivel <> '' ORDER BY nivel");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Publica o despublica un proyecto propio.
 * Devuelve ['ok' => bool, 'error' => code|null].
 */
function triviax_marketplace_publish(PDO $pdo, string $projectId, int $docenteId, bool $publico, bool $esSuperadmin = false): array {
    $stmt = $pdo->prepare('SELECT docente_id, in_trash FROM proyectos WHERE id = ?');
    $stmt->execute([$projectId]);
    $proy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proy || (int)$proy['in_trash'] === 1) {
        return ['ok' => false, 'error' => 'NOT_FOUND'];
    }
    if (!$esSuperadmin && (int)$proy['docente_id'] !== $docenteId) {
        return ['ok' => false, 'error' => 'FORBIDDEN'];
    }
    $pdo->prepare('UPDATE proyectos SET es_publico = ? WHERE id = ?')
        ->execute([$publico ? 1 : 0, $projectId]);
    return ['ok' => true, 'error' => null];
}

/**
 * Clona un proyecto público del marketplace al catálogo del docente.
 *
 * - Fila de `proyectos` duplicada vía INSERT ... SELECT con id nuevo,
 *   docente reasignado, clonado_desde_id al original y contadores en cero.
 * - Filas de `desafios` duplicadas vía INSERT ... SELECT (versionado
 *   evaluativo del original intacto: no se toca actividad_versiones).
 * - Carpeta pública copiada (sin reportes/ ni stats.json).
 * - descargas_count del original incrementado.
 *
 * Devuelve ['ok' => bool, 'error' => code|null, 'nuevo_id' => string|null].
 */
function triviax_marketplace_clone(PDO $pdo, string $projectId, int $docenteId, string $baseProjectsDir): array {
    $stmt = $pdo->prepare('SELECT * FROM proyectos WHERE id = ? AND es_publico = 1 AND in_trash = 0');
    $stmt->execute([$projectId]);
    $origen = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$origen) {
        return ['ok' => false, 'error' => 'NOT_FOUND', 'nuevo_id' => null];
    }

    $nuevoId = triviax_marketplace_new_project_id((string)$origen['title']);
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        // Duplicar el proyecto reasignando docente y linaje.
        $pdo->prepare("
            INSERT INTO proyectos
                (id, docente_id, title, author, nivel, obs, mail, fecha, board_type, formato,
                 token_mode, token_set_id, es_publico, clonado_desde_id, descargas_count)
            SELECT ?, ?, title, author, nivel, obs, '', CURDATE(), board_type, formato,
                   token_mode, token_set_id, 0, ?, 0
            FROM proyectos WHERE id = ?
        ")->execute([$nuevoId, $docenteId, $projectId, $projectId]);

        // Duplicar los desafíos manteniendo claves, orden y datos canónicos.
        $pdo->prepare('
            INSERT INTO desafios
                (proyecto_id, challenge_key, tipo, prompt_text, title, difficulty, points, time_limit, data_json, orden)
            SELECT ?, challenge_key, tipo, prompt_text, title, difficulty, points, time_limit, data_json, orden
            FROM desafios WHERE proyecto_id = ?
        ')->execute([$nuevoId, $projectId]);

        $pdo->prepare('UPDATE proyectos SET descargas_count = descargas_count + 1 WHERE id = ?')
            ->execute([$projectId]);

        // Carpeta pública del proyecto (necesaria para action=get y el fondo).
        triviax_marketplace_copy_project_dir($baseProjectsDir, $projectId, $nuevoId);

        if ($ownTx) {
            $pdo->commit();
        }
        return ['ok' => true, 'error' => null, 'nuevo_id' => $nuevoId];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Limpieza de una carpeta a medio copiar
        $destino = $baseProjectsDir . '/' . $nuevoId;
        if (is_dir($destino)) {
            triviax_marketplace_rrmdir($destino);
        }
        return ['ok' => false, 'error' => 'SERVER_ERROR', 'nuevo_id' => null];
    }
}

/**
 * Copia la parte pública de la carpeta de un proyecto. Excluye datos
 * privados: reportes/ y stats.json. Si el origen no tiene carpeta (proyecto
 * 100% BD) no copia nada y no es error.
 */
function triviax_marketplace_copy_project_dir(string $baseProjectsDir, string $origenId, string $destinoId): void {
    $origen  = $baseProjectsDir . '/' . $origenId;
    $destino = $baseProjectsDir . '/' . $destinoId;
    if (!is_dir($origen)) {
        return;
    }
    if (!is_dir($destino) && !@mkdir($destino, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta del clon.');
    }
    $excluidos = ['reportes', 'stats.json'];
    foreach (scandir($origen) ?: [] as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $excluidos, true)) {
            continue;
        }
        $src = $origen . '/' . $item;
        $dst = $destino . '/' . $item;
        if (is_dir($src)) {
            triviax_marketplace_copy_tree($src, $dst);
        } else {
            if (!@copy($src, $dst)) {
                throw new RuntimeException("No se pudo copiar {$item}.");
            }
        }
    }
}

/** Copia recursiva simple (subcarpetas públicas como media/). */
function triviax_marketplace_copy_tree(string $src, string $dst): void {
    if (!is_dir($dst) && !@mkdir($dst, 0775, true)) {
        throw new RuntimeException('No se pudo crear una subcarpeta del clon.');
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            triviax_marketplace_copy_tree($s, $d);
        } elseif (!@copy($s, $d)) {
            throw new RuntimeException("No se pudo copiar {$item}.");
        }
    }
}

/** Borrado recursivo (solo para limpiar clones fallidos). */
function triviax_marketplace_rrmdir(string $dir): void {
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            triviax_marketplace_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
