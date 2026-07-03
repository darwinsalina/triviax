<?php
/**
 * TRIVIAX v7.0 — Endpoints de grupos de estudiantes (grp_*).
 *
 * Incluido desde api.php cuando $action empieza con "grp_".
 * Usa los helpers de api.php (triviax_api_*), de php/auth.php (CSRF,
 * rate limit, sesión, roles, auditoría) y de php/activity_access.php
 * (alias, códigos con hash).
 *
 * Seguridad:
 *  - Docente: sesión rol docente/superadmin + CSRF + propiedad del grupo.
 *  - Estudiante: grp_join requiere sesión (cualquier rol estudiante).
 *  - Códigos de inscripción: solo hash en BD; se muestran una única vez.
 */

require_once __DIR__ . '/activity_access.php';

function _grp_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _grp_require_docente(): array {
    triviax_session_start();
    $u = triviax_usuario_actual();
    if ($u === null) {
        triviax_api_error('UNAUTHORIZED', 'Necesitas iniciar sesión.', 401);
    }
    if (!in_array($u['rol'], [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_SUPERADMIN], true)) {
        triviax_api_error('FORBIDDEN', 'Acceso solo para docentes.', 403);
    }
    return $u;
}

/** Carga un grupo verificando propiedad (dueño o superadmin). */
function _grp_load_own(PDO $pdo, int $id, array $docente): array {
    $stmt = $pdo->prepare('SELECT * FROM grupos WHERE id = ?');
    $stmt->execute([$id]);
    $g = $stmt->fetch();
    if (!$g) {
        triviax_api_error('NOT_FOUND', 'El grupo no existe.', 404);
    }
    if ((int)$g['docente_id'] !== (int)$docente['id'] && $docente['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        triviax_api_error('FORBIDDEN', 'No tienes permiso sobre este grupo.', 403);
    }
    return $g;
}

/** Alias existentes de un grupo (para resolver duplicados). */
function _grp_alias_existentes(PDO $pdo, int $grupoId): array {
    $stmt = $pdo->prepare('SELECT alias FROM grupo_estudiantes WHERE grupo_id = ?');
    $stmt->execute([$grupoId]);
    return array_column($stmt->fetchAll(), 'alias');
}

/**
 * Parsea contenido CSV a filas [nombre, apellido, email].
 * Detecta separador (; o ,), cabecera y orden de columnas; tolera BOM
 * y archivos en Latin-1. Devuelve ['rows'=>[], 'errors'=>[]].
 */
function _grp_parse_csv(string $content): array {
    // BOM y codificación
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    }
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return ['rows' => [], 'errors' => [['line' => 0, 'error' => 'El archivo está vacío.']]];
    }

    // Separador más frecuente en la primera línea con datos
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

    // Cabecera: mapear columnas por nombre; si no hay, orden posicional
    $col = ['nombre' => 0, 'apellido' => 1, 'email' => 2];
    $first = array_map(static fn($c) => triviax_alias_normalize((string)$c), str_getcsv($lines[0], $sep, '"', '\\'));
    $hasHeader = false;
    foreach ($first as $i => $h) {
        if (in_array($h, ['nombre', 'nombres', 'firstname', 'name'], true)) { $col['nombre'] = $i; $hasHeader = true; }
        if (in_array($h, ['apellido', 'apellidos', 'lastname', 'surname'], true)) { $col['apellido'] = $i; $hasHeader = true; }
        if (in_array($h, ['email', 'correo', 'mail', 'correoelectronico'], true)) { $col['email'] = $i; $hasHeader = true; }
    }
    if ($hasHeader) {
        array_shift($lines);
    }

    $rows = [];
    $errors = [];
    foreach ($lines as $idx => $line) {
        $lineNum = $idx + ($hasHeader ? 2 : 1);
        $cells = str_getcsv($line, $sep, '"', '\\');
        $nombre = trim((string)($cells[$col['nombre']] ?? ''));
        $apellido = trim((string)($cells[$col['apellido']] ?? ''));
        $email = strtolower(trim((string)($cells[$col['email']] ?? '')));
        if ($nombre === '' && $apellido === '') {
            $errors[] = ['line' => $lineNum, 'error' => 'Fila sin nombre ni apellido.'];
            continue;
        }
        if ($nombre === '' || $apellido === '') {
            $errors[] = ['line' => $lineNum, 'error' => 'Falta nombre o apellido.'];
            continue;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['line' => $lineNum, 'error' => "Email inválido: {$email}"];
            $email = '';
        }
        $rows[] = [
            'nombre' => mb_substr($nombre, 0, 100, 'UTF-8'),
            'apellido' => mb_substr($apellido, 0, 100, 'UTF-8'),
            'email' => $email !== '' ? mb_substr($email, 0, 255, 'UTF-8') : null,
            'line' => $lineNum,
        ];
    }
    return ['rows' => $rows, 'errors' => $errors];
}

/** Fila pública de estudiante (sin datos internos innecesarios). */
function _grp_student_public(array $e): array {
    return [
        'id' => (int)$e['id'],
        'grupo_id' => (int)$e['grupo_id'],
        'usuario_id' => $e['usuario_id'] !== null ? (int)$e['usuario_id'] : null,
        'nombre' => $e['nombre'],
        'apellido' => $e['apellido'],
        'email' => $e['email'],
        'alias' => $e['alias'],
        'estado' => $e['estado'],
        'source' => $e['source'],
        'validated_at' => $e['validated_at'],
        'created_at' => $e['created_at'],
    ];
}

function triviax_grp_api_handle(string $action): void {
    $pdo = _grp_require_db();

    switch ($action) {

        // ── Listado de grupos del docente ────────────────────
        case 'grp_list': {
            $u = _grp_require_docente();
            $stmt = $pdo->prepare(
                "SELECT g.*, i.nombre AS institucion_nombre,
                        (SELECT COUNT(*) FROM grupo_estudiantes ge
                          WHERE ge.grupo_id = g.id AND ge.estado <> 'desactivado') AS total_estudiantes,
                        (SELECT COUNT(*) FROM grupo_estudiantes ge
                          WHERE ge.grupo_id = g.id AND ge.estado = 'pendiente') AS pendientes
                 FROM grupos g
                 LEFT JOIN instituciones i ON i.id = g.institucion_id
                 WHERE g.docente_id = ?
                 ORDER BY g.activo DESC, g.nivel, g.nombre"
            );
            $stmt->execute([(int)$u['id']]);
            $grupos = array_map(static function ($g) {
                unset($g['codigo_inscripcion_hash']);
                $g['tiene_codigo'] = false; // se recalcula abajo
                return $g;
            }, $stmt->fetchAll());
            // marcar si tienen código sin exponer el hash
            $ids = array_column($grupos, 'id');
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT id FROM grupos WHERE id IN ($in) AND codigo_inscripcion_hash IS NOT NULL");
                $st->execute($ids);
                $conCodigo = array_flip(array_column($st->fetchAll(), 'id'));
                foreach ($grupos as &$g) {
                    $g['tiene_codigo'] = isset($conCodigo[$g['id']]);
                }
                unset($g);
            }
            triviax_api_success(['grupos' => $grupos]);
            break;
        }

        // ── Crear grupo ──────────────────────────────────────
        case 'grp_create': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $nombre = trim((string)($in['nombre'] ?? ''));
            $nivel = trim((string)($in['nivel'] ?? ''));
            $descripcion = trim((string)($in['descripcion'] ?? ''));
            $institucionId = !empty($in['institucion_id']) ? (int)$in['institucion_id'] : null;
            if ($nombre === '' || mb_strlen($nombre) > 120) {
                triviax_api_error('VALIDATION', 'El nombre del grupo es obligatorio (máx. 120 caracteres).', 422);
            }
            try {
                $pdo->prepare(
                    'INSERT INTO grupos (docente_id, institucion_id, nivel, nombre, descripcion)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([(int)$u['id'], $institucionId, $nivel !== '' ? $nivel : null, $nombre, $descripcion !== '' ? $descripcion : null]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    triviax_api_error('DUPLICATE', 'Ya tienes un grupo con ese nivel y nombre.', 409);
                }
                throw $e;
            }
            $grupoId = (int)$pdo->lastInsertId();
            triviax_audit_log('grupo_creado', 'grupos', (string)$grupoId, ['nombre' => $nombre, 'nivel' => $nivel]);
            triviax_api_success(['grupo_id' => $grupoId], 'Grupo creado.');
            break;
        }

        // ── Editar grupo ─────────────────────────────────────
        case 'grp_update': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $g = _grp_load_own($pdo, (int)($in['grupo_id'] ?? 0), $u);
            $nombre = trim((string)($in['nombre'] ?? $g['nombre']));
            $nivel = trim((string)($in['nivel'] ?? (string)$g['nivel']));
            $descripcion = trim((string)($in['descripcion'] ?? (string)$g['descripcion']));
            $activo = isset($in['activo']) ? (int)!!$in['activo'] : (int)$g['activo'];
            if ($nombre === '' || mb_strlen($nombre) > 120) {
                triviax_api_error('VALIDATION', 'El nombre del grupo es obligatorio (máx. 120 caracteres).', 422);
            }
            try {
                $pdo->prepare(
                    'UPDATE grupos SET nombre = ?, nivel = ?, descripcion = ?, activo = ? WHERE id = ?'
                )->execute([$nombre, $nivel !== '' ? $nivel : null, $descripcion !== '' ? $descripcion : null, $activo, (int)$g['id']]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    triviax_api_error('DUPLICATE', 'Ya tienes un grupo con ese nivel y nombre.', 409);
                }
                throw $e;
            }
            triviax_audit_log('grupo_editado', 'grupos', (string)$g['id'], ['nombre' => $nombre, 'activo' => $activo]);
            triviax_api_success([], 'Grupo actualizado.');
            break;
        }

        // ── Regenerar código de inscripción ──────────────────
        case 'grp_regen_code': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $g = _grp_load_own($pdo, (int)($in['grupo_id'] ?? 0), $u);
            $codigo = triviax_generate_secure_code(8);
            $pdo->prepare(
                'UPDATE grupos SET codigo_inscripcion_hash = ?, codigo_generado_at = NOW() WHERE id = ?'
            )->execute([triviax_code_hash($codigo), (int)$g['id']]);
            triviax_audit_log('grupo_codigo_regenerado', 'grupos', (string)$g['id']);
            // El código viaja UNA sola vez; no vuelve a poder consultarse.
            triviax_api_success(['codigo' => $codigo], 'Código regenerado. Guárdalo: no se volverá a mostrar.');
            break;
        }

        // ── Estudiantes de un grupo ──────────────────────────
        case 'grp_students': {
            $u = _grp_require_docente();
            $g = _grp_load_own($pdo, (int)($_GET['grupo_id'] ?? 0), $u);
            $stmt = $pdo->prepare(
                'SELECT * FROM grupo_estudiantes WHERE grupo_id = ? ORDER BY apellido, nombre'
            );
            $stmt->execute([(int)$g['id']]);
            triviax_api_success([
                'grupo' => ['id' => (int)$g['id'], 'nombre' => $g['nombre'], 'nivel' => $g['nivel']],
                'estudiantes' => array_map('_grp_student_public', $stmt->fetchAll()),
            ]);
            break;
        }

        // ── Alta manual de estudiante ────────────────────────
        case 'grp_student_add': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $g = _grp_load_own($pdo, (int)($in['grupo_id'] ?? 0), $u);
            $nombre = trim((string)($in['nombre'] ?? ''));
            $apellido = trim((string)($in['apellido'] ?? ''));
            $email = strtolower(trim((string)($in['email'] ?? '')));
            if ($nombre === '' || $apellido === '') {
                triviax_api_error('VALIDATION', 'Nombre y apellido son obligatorios.', 422);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                triviax_api_error('VALIDATION', 'El email no es válido.', 422);
            }
            $alias = trim((string)($in['alias'] ?? ''));
            $existentes = _grp_alias_existentes($pdo, (int)$g['id']);
            if ($alias === '') {
                $alias = triviax_alias_make_unique(triviax_alias_base($nombre, $apellido), $existentes);
            } elseif (in_array(mb_strtolower($alias), array_map('mb_strtolower', $existentes), true)) {
                triviax_api_error('DUPLICATE', 'Ese alias ya existe en el grupo.', 409);
            }
            // Vincular usuario registrado si el email coincide
            $usuarioId = null;
            if ($email !== '') {
                $st = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND rol = 'estudiante' LIMIT 1");
                $st->execute([$email]);
                $usuarioId = $st->fetchColumn() ?: null;
            }
            try {
                $pdo->prepare(
                    "INSERT INTO grupo_estudiantes (grupo_id, usuario_id, email, nombre, apellido, alias, estado, source)
                     VALUES (?, ?, ?, ?, ?, ?, 'importado', 'manual')"
                )->execute([(int)$g['id'], $usuarioId, $email !== '' ? $email : null, $nombre, $apellido, $alias]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    triviax_api_error('DUPLICATE', 'Ese email o alias ya existe en el grupo.', 409);
                }
                throw $e;
            }
            triviax_audit_log('grupo_estudiante_agregado', 'grupo_estudiantes', (string)$pdo->lastInsertId(), [
                'grupo_id' => (int)$g['id'], 'alias' => $alias,
            ]);
            triviax_api_success(['alias' => $alias], 'Estudiante agregado.');
            break;
        }

        // ── Validar / rechazar / desactivar estudiante ───────
        case 'grp_student_estado': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $estId = (int)($in['estudiante_id'] ?? 0);
            $nuevo = (string)($in['estado'] ?? '');
            if (!in_array($nuevo, ['validado', 'rechazado', 'desactivado', 'pendiente'], true)) {
                triviax_api_error('VALIDATION', 'Estado no válido.', 422);
            }
            $stmt = $pdo->prepare('SELECT ge.*, g.docente_id FROM grupo_estudiantes ge JOIN grupos g ON g.id = ge.grupo_id WHERE ge.id = ?');
            $stmt->execute([$estId]);
            $est = $stmt->fetch();
            if (!$est) {
                triviax_api_error('NOT_FOUND', 'El estudiante no existe.', 404);
            }
            if ((int)$est['docente_id'] !== (int)$u['id'] && $u['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
                triviax_api_error('FORBIDDEN', 'No tienes permiso sobre este grupo.', 403);
            }
            $esValidacion = ($nuevo === 'validado');
            $pdo->prepare(
                'UPDATE grupo_estudiantes SET estado = ?, validated_by = ?, validated_at = ? WHERE id = ?'
            )->execute([
                $nuevo,
                $esValidacion ? (int)$u['id'] : $est['validated_by'],
                $esValidacion ? date('Y-m-d H:i:s') : $est['validated_at'],
                $estId,
            ]);
            triviax_audit_log('grupo_estudiante_' . $nuevo, 'grupo_estudiantes', (string)$estId, [
                'grupo_id' => (int)$est['grupo_id'], 'alias' => $est['alias'],
            ]);
            triviax_api_success([], 'Estado actualizado.');
            break;
        }

        // ── Previsualización de importación CSV ──────────────
        case 'grp_import_preview': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $grupoId = (int)($_POST['grupo_id'] ?? 0);
            $g = _grp_load_own($pdo, $grupoId, $u);

            $content = '';
            $filename = null;
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                if ((int)$_FILES['file']['size'] > 1024 * 1024) {
                    triviax_api_error('VALIDATION', 'El archivo supera 1 MB.', 422);
                }
                $filename = (string)$_FILES['file']['name'];
                if (preg_match('/\.xlsx?$/i', $filename)) {
                    triviax_api_error('VALIDATION',
                        'Formato Excel no soportado directamente. Guarda la planilla como CSV (Archivo → Guardar como → CSV) y vuelve a subirla.', 422);
                }
                $content = (string)file_get_contents($_FILES['file']['tmp_name']);
            } else {
                $in = triviax_api_input();
                $content = (string)($in['csv_text'] ?? '');
            }
            if (trim($content) === '') {
                triviax_api_error('VALIDATION', 'No se recibió ningún archivo ni texto CSV.', 422);
            }

            $parsed = _grp_parse_csv($content);
            $existentes = _grp_alias_existentes($pdo, (int)$g['id']);
            $st = $pdo->prepare('SELECT LOWER(email) FROM grupo_estudiantes WHERE grupo_id = ? AND email IS NOT NULL');
            $st->execute([(int)$g['id']]);
            $emailsGrupo = array_flip(array_column($st->fetchAll(PDO::FETCH_NUM), 0));

            $preview = [];
            $vistos = [];
            foreach ($parsed['rows'] as $row) {
                $alias = triviax_alias_make_unique(triviax_alias_base($row['nombre'], $row['apellido']), $existentes);
                $existentes[] = $alias;
                $dup = false;
                $motivo = '';
                if ($row['email'] !== null) {
                    if (isset($emailsGrupo[$row['email']])) {
                        $dup = true; $motivo = 'El email ya existe en el grupo.';
                    } elseif (isset($vistos[$row['email']])) {
                        $dup = true; $motivo = 'Email repetido dentro del archivo.';
                    }
                    $vistos[$row['email']] = true;
                }
                $preview[] = [
                    'nombre' => $row['nombre'],
                    'apellido' => $row['apellido'],
                    'email' => $row['email'],
                    'alias' => $alias,
                    'line' => $row['line'],
                    'duplicado' => $dup,
                    'motivo' => $motivo,
                ];
            }
            triviax_api_success([
                'preview' => $preview,
                'errores' => $parsed['errors'],
                'filename' => $filename,
                'total' => count($preview),
            ]);
            break;
        }

        // ── Confirmar importación ────────────────────────────
        case 'grp_import_confirm': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $g = _grp_load_own($pdo, (int)($in['grupo_id'] ?? 0), $u);
            $rows = $in['rows'] ?? [];
            if (!is_array($rows) || !$rows) {
                triviax_api_error('VALIDATION', 'No hay filas para importar.', 422);
            }
            if (count($rows) > 500) {
                triviax_api_error('VALIDATION', 'Máximo 500 estudiantes por importación.', 422);
            }

            $existentes = _grp_alias_existentes($pdo, (int)$g['id']);
            $errors = [];
            $imported = 0;
            $ins = $pdo->prepare(
                "INSERT INTO grupo_estudiantes (grupo_id, usuario_id, email, nombre, apellido, alias, estado, source)
                 VALUES (?, ?, ?, ?, ?, ?, 'importado', 'csv')"
            );
            $findUser = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND rol = 'estudiante' LIMIT 1");

            $pdo->beginTransaction();
            try {
                foreach ($rows as $i => $row) {
                    $nombre = trim((string)($row['nombre'] ?? ''));
                    $apellido = trim((string)($row['apellido'] ?? ''));
                    $email = strtolower(trim((string)($row['email'] ?? '')));
                    $alias = trim((string)($row['alias'] ?? ''));
                    if ($nombre === '' || $apellido === '') {
                        $errors[] = ['row' => $i, 'error' => 'Falta nombre o apellido.'];
                        continue;
                    }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = ['row' => $i, 'error' => "Email inválido: {$email}"];
                        continue;
                    }
                    if ($alias === '') {
                        $alias = triviax_alias_base($nombre, $apellido);
                    } else {
                        $alias = mb_substr($alias, 0, 80, 'UTF-8');
                    }
                    $alias = triviax_alias_make_unique($alias, $existentes);
                    $usuarioId = null;
                    if ($email !== '') {
                        $findUser->execute([$email]);
                        $usuarioId = $findUser->fetchColumn() ?: null;
                    }
                    try {
                        $ins->execute([(int)$g['id'], $usuarioId, $email !== '' ? $email : null,
                            mb_substr($nombre, 0, 100, 'UTF-8'), mb_substr($apellido, 0, 100, 'UTF-8'), $alias]);
                        $existentes[] = $alias;
                        $imported++;
                    } catch (PDOException $e) {
                        if ((string)$e->getCode() === '23000') {
                            $errors[] = ['row' => $i, 'error' => "Duplicado en el grupo (email o alias): {$alias}"];
                        } else {
                            throw $e;
                        }
                    }
                }
                $pdo->prepare(
                    'INSERT INTO grupo_importaciones
                     (grupo_id, docente_id, filename, mime_type, total_rows, imported_rows, error_rows, errors_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int)$g['id'], (int)$u['id'],
                    isset($in['filename']) ? mb_substr((string)$in['filename'], 0, 255) : null,
                    'text/csv',
                    count($rows), $imported, count($errors),
                    $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            triviax_audit_log('grupo_importacion', 'grupos', (string)$g['id'], [
                'total' => count($rows), 'importados' => $imported, 'errores' => count($errors),
            ]);
            triviax_api_success([
                'importados' => $imported,
                'errores' => $errors,
            ], "Importación completa: {$imported} estudiantes.");
            break;
        }

        // ── Exportar lista (CSV) ─────────────────────────────
        case 'grp_export': {
            $u = _grp_require_docente();
            $g = _grp_load_own($pdo, (int)($_GET['grupo_id'] ?? 0), $u);
            $stmt = $pdo->prepare(
                'SELECT nombre, apellido, email, alias, estado FROM grupo_estudiantes
                 WHERE grupo_id = ? ORDER BY apellido, nombre'
            );
            $stmt->execute([(int)$g['id']]);
            $rows = $stmt->fetchAll();
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="grupo_' . (int)$g['id'] . '_estudiantes.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
            fputcsv($out, ['nombre', 'apellido', 'email', 'alias', 'estado'], ';', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, [$r['nombre'], $r['apellido'], $r['email'], $r['alias'], $r['estado']], ';', '"', '\\');
            }
            fclose($out);
            exit;
        }

        // ── Unirse a un grupo con código (estudiante) ────────
        case 'grp_join': {
            triviax_api_require_post();
            triviax_session_start();
            $u = triviax_usuario_actual();
            if ($u === null) {
                triviax_api_error('UNAUTHORIZED', 'Necesitas iniciar sesión para unirte a un grupo.', 401);
            }
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $codigo = strtoupper(trim((string)($in['codigo'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{6,8}$/', $codigo)) {
                triviax_api_error('VALIDATION', 'El código debe tener entre 6 y 8 caracteres alfanuméricos.', 422);
            }
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
            if (!triviax_rate_limit_check('grp_join', $ip, 12, 600)) {
                triviax_api_error('RATE_LIMITED', 'Demasiados intentos. Espera unos minutos.', 429);
            }

            // El código se guarda como hash: verificar contra los grupos activos.
            $grupoMatch = null;
            foreach ($pdo->query("SELECT id, docente_id, nombre, nivel, codigo_inscripcion_hash FROM grupos WHERE activo = 1 AND codigo_inscripcion_hash IS NOT NULL") as $g) {
                if (triviax_code_verify($codigo, $g['codigo_inscripcion_hash'])) {
                    $grupoMatch = $g;
                    break;
                }
            }
            if ($grupoMatch === null) {
                triviax_rate_limit_hit('grp_join', $ip, 600, 600, 12);
                triviax_api_error('NOT_FOUND', 'El código no corresponde a ningún grupo activo.', 404);
            }
            triviax_rate_limit_clear('grp_join', $ip);

            $grupoId = (int)$grupoMatch['id'];
            $email = strtolower(trim((string)$u['email']));

            // ¿Ya figura (importado por el docente) con ese email o usuario?
            $stmt = $pdo->prepare(
                'SELECT * FROM grupo_estudiantes
                 WHERE grupo_id = ? AND (usuario_id = ? OR (email IS NOT NULL AND LOWER(email) = ?)) LIMIT 1'
            );
            $stmt->execute([$grupoId, (int)$u['id'], $email]);
            $fila = $stmt->fetch();
            if ($fila) {
                if (in_array($fila['estado'], ['rechazado', 'desactivado'], true)) {
                    triviax_api_error('FORBIDDEN', 'Tu acceso a este grupo fue dado de baja por el docente.', 403);
                }
                // Vincular la cuenta si faltaba y pasar importado → pendiente
                $pdo->prepare(
                    "UPDATE grupo_estudiantes
                     SET usuario_id = ?, email = COALESCE(email, ?),
                         estado = IF(estado = 'importado', 'pendiente', estado)
                     WHERE id = ?"
                )->execute([(int)$u['id'], $email, (int)$fila['id']]);
                triviax_audit_log('grupo_join_vinculado', 'grupo_estudiantes', (string)$fila['id'], ['grupo_id' => $grupoId]);
                triviax_api_success([
                    'grupo' => ['id' => $grupoId, 'nombre' => $grupoMatch['nombre'], 'nivel' => $grupoMatch['nivel']],
                    'estado' => $fila['estado'] === 'importado' ? 'pendiente' : $fila['estado'],
                ], 'Te uniste al grupo.');
            }

            // Alta nueva por auto-inscripción
            $existentes = _grp_alias_existentes($pdo, $grupoId);
            $alias = triviax_alias_make_unique(
                triviax_alias_base((string)$u['nombre'], (string)($u['apellido'] ?? '')), $existentes
            );
            $pdo->prepare(
                "INSERT INTO grupo_estudiantes (grupo_id, usuario_id, email, nombre, apellido, alias, estado, source)
                 VALUES (?, ?, ?, ?, ?, ?, 'pendiente', 'self_join')"
            )->execute([$grupoId, (int)$u['id'], $email, (string)$u['nombre'], (string)($u['apellido'] ?? ''), $alias]);
            triviax_audit_log('grupo_join', 'grupo_estudiantes', (string)$pdo->lastInsertId(), ['grupo_id' => $grupoId]);
            triviax_api_success([
                'grupo' => ['id' => $grupoId, 'nombre' => $grupoMatch['nombre'], 'nivel' => $grupoMatch['nivel']],
                'estado' => 'pendiente', 'alias' => $alias,
            ], 'Te uniste al grupo. El docente debe validar tu identidad.');
            break;
        }

        // ── Mis grupos (estudiante) ──────────────────────────
        case 'grp_mis_grupos': {
            triviax_session_start();
            $u = triviax_usuario_actual();
            if ($u === null) {
                triviax_api_error('UNAUTHORIZED', 'Necesitas iniciar sesión.', 401);
            }
            $stmt = $pdo->prepare(
                'SELECT ge.estado, ge.alias, g.id AS grupo_id, g.nombre, g.nivel
                 FROM grupo_estudiantes ge JOIN grupos g ON g.id = ge.grupo_id
                 WHERE (ge.usuario_id = ? OR (ge.email IS NOT NULL AND LOWER(ge.email) = ?)) AND g.activo = 1
                 ORDER BY g.nivel, g.nombre'
            );
            $stmt->execute([(int)$u['id'], strtolower(trim((string)$u['email']))]);
            triviax_api_success(['grupos' => $stmt->fetchAll()]);
            break;
        }

        // ── Código docente secundario ────────────────────────
        case 'grp_codigo_docente_regen': {
            triviax_api_require_post();
            $u = _grp_require_docente();
            triviax_verify_csrf_json();
            $codigo = triviax_regenerar_codigo_docente((int)$u['id']);
            triviax_api_success(['codigo' => $codigo],
                'Código docente generado. Guárdalo: no se volverá a mostrar.');
            break;
        }

        default:
            triviax_api_error('UNKNOWN_ACTION', 'Acción de grupos desconocida.', 400);
    }
}
