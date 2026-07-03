<?php
/**
 * TRIVIAX v7.0 — Endpoints de políticas de acceso (acceso_*).
 *
 * Incluido desde api.php cuando $action empieza con "acceso_".
 * Gestiona la configuración transversal de visibilidad, plazos,
 * requisitos, evaluación y versionado de cualquier modalidad.
 *
 * Seguridad:
 *  - Lectura/escritura de políticas: docente dueño de la actividad o
 *    superadmin, con CSRF en escrituras.
 *  - acceso_check: público; informa si el usuario actual puede iniciar
 *    una actividad (sin filtrar datos sensibles de la política).
 */

require_once __DIR__ . '/activity_access.php';

function _acceso_require_db(): PDO {
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    return triviax_db();
}

function _acceso_require_docente(): array {
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

/** Valida tipo+ref y exige que el docente sea dueño de la actividad. */
function _acceso_require_own(array $u, string $tipo, string $ref): void {
    if (!isset(triviax_activity_types()[$tipo]) || $ref === '') {
        triviax_api_error('VALIDATION', 'Tipo o referencia de actividad no válidos.', 422);
    }
    if (!triviax_is_activity_owner($u, $tipo, $ref)) {
        triviax_api_error('FORBIDDEN', 'No tienes permiso sobre esta actividad.', 403);
    }
}

/** Versión pública de la política (sin hashes). */
function _acceso_policy_public(array $p): array {
    unset($p['codigo_acceso_hash']);
    $p['tiene_codigo'] = !empty($p['es_default']) ? false : null; // se setea abajo
    return $p;
}

function triviax_acceso_api_handle(string $action): void {
    $pdo = _acceso_require_db();

    switch ($action) {

        // ── Leer la política de una actividad (docente) ──────
        case 'acceso_get': {
            $u = _acceso_require_docente();
            $tipo = (string)($_GET['tipo'] ?? '');
            $ref = (string)($_GET['ref'] ?? '');
            _acceso_require_own($u, $tipo, $ref);
            $policy = triviax_get_access_policy($tipo, $ref, true);
            $tieneCodigo = $policy && !empty($policy['codigo_acceso_hash']);
            $out = _acceso_policy_public($policy ?? triviax_policy_defaults($tipo, $ref));
            $out['tiene_codigo'] = $tieneCodigo;
            // Estado del versionado y de las entregas para la UI
            $version = triviax_get_current_activity_version($tipo, $ref);
            $out['version_actual'] = $version ? [
                'id' => (int)$version['id'],
                'version_num' => (int)$version['version_num'],
                'created_at' => $version['created_at'],
            ] : null;
            $out['tiene_entregas'] = triviax_activity_has_submissions($tipo, $ref);
            triviax_api_success(['politica' => $out]);
            break;
        }

        // ── Guardar la política de una actividad (docente) ───
        case 'acceso_save': {
            triviax_api_require_post();
            $u = _acceso_require_docente();
            triviax_verify_csrf_json();
            $in = triviax_api_input();
            $tipo = (string)($in['tipo'] ?? '');
            $ref = (string)($in['ref'] ?? '');
            _acceso_require_own($u, $tipo, $ref);

            $anterior = triviax_get_access_policy($tipo, $ref, false);

            $data = [];
            if (isset($in['visibilidad']) && in_array($in['visibilidad'], ['publica', 'no_listada', 'restringida'], true)) {
                $data['visibilidad'] = $in['visibilidad'];
            }
            if (isset($in['estado_publicacion']) && in_array($in['estado_publicacion'],
                ['borrador', 'programada', 'abierta', 'cerrada', 'desactivada', 'archivada'], true)) {
                $data['estado_publicacion'] = $in['estado_publicacion'];
            }
            foreach (['abre_at', 'cierra_at'] as $k) {
                if (array_key_exists($k, $in)) {
                    $v = trim((string)$in[$k]);
                    if ($v === '') {
                        $data[$k] = null;
                    } else {
                        $v = str_replace('T', ' ', $v);
                        if (strlen($v) === 16) { $v .= ':00'; }
                        $ts = strtotime($v);
                        if ($ts === false) {
                            triviax_api_error('VALIDATION', "Fecha inválida en {$k}.", 422);
                        }
                        $data[$k] = date('Y-m-d H:i:s', $ts);
                    }
                }
            }
            if (isset($data['abre_at'], $data['cierra_at'])
                && $data['abre_at'] !== null && $data['cierra_at'] !== null
                && $data['cierra_at'] <= $data['abre_at']) {
                triviax_api_error('VALIDATION', 'El cierre debe ser posterior a la apertura.', 422);
            }
            foreach (['requiere_login', 'requiere_email_verificado', 'requiere_validacion_docente', 'evaluativa'] as $k) {
                if (array_key_exists($k, $in)) {
                    $data[$k] = (int)!!$in[$k];
                }
            }
            if (array_key_exists('max_intentos', $in)) {
                $mi = (int)$in['max_intentos'];
                $data['max_intentos'] = $mi > 0 ? min($mi, 999) : null;
            }
            if (isset($in['feedback_policy']) && in_array($in['feedback_policy'], ['inmediato', 'al_final', 'al_cierre', 'nunca'], true)) {
                $data['feedback_policy'] = $in['feedback_policy'];
            }
            if (isset($in['dominios']) && is_array($in['dominios'])) {
                $data['dominios'] = $in['dominios'];
            }
            if (isset($in['grupos']) && is_array($in['grupos'])) {
                $data['grupos'] = $in['grupos'];
            }
            if (isset($in['estudiantes']) && is_array($in['estudiantes'])) {
                $data['estudiantes'] = $in['estudiantes'];
            }
            // Código de acceso: generar/quitar (nunca se recibe en claro desde la UI)
            $codigoNuevo = null;
            if (!empty($in['generar_codigo'])) {
                $codigoNuevo = triviax_generate_secure_code(8);
                $data['codigo_acceso_hash'] = triviax_code_hash($codigoNuevo);
            } elseif (!empty($in['quitar_codigo'])) {
                $data['codigo_acceso_hash'] = null;
            }

            // Bloqueo de sobrescritura evaluativa: si la actividad es (o pasa
            // a ser) evaluativa y ya tiene entregas, la política se puede
            // ajustar, pero el CONTENIDO lo protege cada modalidad al guardar
            // (triviax_guard_evaluative_edit). Aquí solo advertimos.
            $politicaId = triviax_save_access_policy($tipo, $ref, $data, (int)$u['id']);

            // Si la actividad queda evaluativa y abierta, congelar versión.
            $policy = triviax_get_access_policy($tipo, $ref, false);
            $versionInfo = null;
            if ($policy && !empty($policy['evaluativa'])
                && in_array($policy['estado_publicacion'], ['programada', 'abierta'], true)) {
                $snapshot = triviax_build_activity_snapshot($tipo, $ref);
                if ($snapshot !== null) {
                    $versionInfo = triviax_create_activity_version($tipo, $ref, $snapshot, (int)$u['id'], 'Publicación evaluativa');
                }
            }

            // Auditoría de cambios sensibles
            $cambios = [];
            foreach (['visibilidad', 'estado_publicacion', 'abre_at', 'cierra_at', 'evaluativa'] as $k) {
                if (array_key_exists($k, $data) && (!$anterior || ($anterior[$k] ?? null) != $data[$k])) {
                    $cambios[$k] = ['antes' => $anterior[$k] ?? null, 'despues' => $data[$k]];
                }
            }
            triviax_audit_log('acceso_politica_guardada', $tipo, $ref, [
                'politica_id' => $politicaId,
                'cambios' => $cambios,
                'codigo_generado' => $codigoNuevo !== null,
            ]);

            $extra = ['politica_id' => $politicaId];
            if ($codigoNuevo !== null) {
                $extra['codigo'] = $codigoNuevo; // se muestra UNA sola vez
            }
            if ($versionInfo !== null) {
                $extra['version'] = $versionInfo;
            }
            triviax_api_success($extra, 'Política de acceso guardada.');
            break;
        }

        // ── Chequear acceso del usuario actual (público) ─────
        case 'acceso_check': {
            triviax_session_start();
            $tipo = (string)($_GET['tipo'] ?? ($_POST['tipo'] ?? ''));
            $ref = (string)($_GET['ref'] ?? ($_POST['ref'] ?? ''));
            $codigo = (string)($_GET['codigo'] ?? ($_POST['codigo'] ?? ''));
            if (!isset(triviax_activity_types()[$tipo]) || $ref === '') {
                triviax_api_error('VALIDATION', 'Tipo o referencia de actividad no válidos.', 422);
            }
            $u = triviax_usuario_actual();
            $r = triviax_can_start_activity($u, $tipo, $ref, $codigo !== '' ? $codigo : null);
            triviax_api_success([
                'puede_iniciar' => $r['ok'],
                'motivo' => $r['reason'],
                'mensaje' => $r['message'],
                'identidad' => $r['identity'],
                'preview' => $r['preview'],
                'requiere_codigo' => !$r['ok'] && $r['reason'] === 'requiere_codigo',
                'evaluativa' => (int)($r['policy']['evaluativa'] ?? 0),
                'feedback_policy' => $r['policy']['feedback_policy'] ?? 'al_final',
            ]);
            break;
        }

        // ── Listar versiones de una actividad (docente) ──────
        case 'acceso_versiones': {
            $u = _acceso_require_docente();
            $tipo = (string)($_GET['tipo'] ?? '');
            $ref = (string)($_GET['ref'] ?? '');
            _acceso_require_own($u, $tipo, $ref);
            $stmt = $pdo->prepare(
                'SELECT id, version_num, content_hash, created_at, locked_at, notes
                 FROM actividad_versiones
                 WHERE actividad_tipo = ? AND actividad_ref = ?
                 ORDER BY version_num DESC'
            );
            $stmt->execute([$tipo, $ref]);
            triviax_api_success(['versiones' => $stmt->fetchAll()]);
            break;
        }

        // ── Grupos disponibles del docente (para la UI) ──────
        case 'acceso_grupos_disponibles': {
            $u = _acceso_require_docente();
            $stmt = $pdo->prepare(
                "SELECT id, nombre, nivel,
                        (SELECT COUNT(*) FROM grupo_estudiantes ge
                          WHERE ge.grupo_id = grupos.id AND ge.estado <> 'desactivado') AS total
                 FROM grupos WHERE docente_id = ? AND activo = 1 ORDER BY nivel, nombre"
            );
            $stmt->execute([(int)$u['id']]);
            triviax_api_success(['grupos' => $stmt->fetchAll()]);
            break;
        }

        // ── Entregas / resultados transversales (docente) ────
        case 'acceso_entregas': {
            $u = _acceso_require_docente();
            $tipo = (string)($_GET['tipo'] ?? '');
            $ref = (string)($_GET['ref'] ?? '');
            $filtros = [];
            $params = [];
            if ($tipo !== '' && $ref !== '') {
                _acceso_require_own($u, $tipo, $ref);
                $filtros[] = 'ae.actividad_tipo = ? AND ae.actividad_ref = ?';
                $params[] = $tipo;
                $params[] = $ref;
            } else {
                // Sin actividad puntual: solo entregas de actividades del docente
                $filtros[] = 'p.docente_id = ?';
                $params[] = (int)$u['id'];
            }
            if (!empty($_GET['grupo_id'])) {
                $filtros[] = 'ae.grupo_id = ?';
                $params[] = (int)$_GET['grupo_id'];
            }
            if (isset($_GET['evaluativa']) && $_GET['evaluativa'] !== '') {
                $filtros[] = 'ae.evaluativa = ?';
                $params[] = (int)!!$_GET['evaluativa'];
            }
            if (!empty($_GET['version_id'])) {
                $filtros[] = 'ae.version_id = ?';
                $params[] = (int)$_GET['version_id'];
            }
            if (!empty($_GET['desde'])) {
                $filtros[] = 'ae.created_at >= ?';
                $params[] = $_GET['desde'] . ' 00:00:00';
            }
            if (!empty($_GET['hasta'])) {
                $filtros[] = 'ae.created_at <= ?';
                $params[] = $_GET['hasta'] . ' 23:59:59';
            }
            $where = implode(' AND ', $filtros);
            $sql =
                "SELECT ae.id, ae.actividad_tipo, ae.actividad_ref, ae.estado, ae.puntaje,
                        ae.max_puntaje, ae.porcentaje, ae.time_ms, ae.started_at, ae.submitted_at,
                        ae.evaluativa, ae.version_id, ae.grupo_id,
                        u.nombre AS usuario_nombre, u.apellido AS usuario_apellido, u.email AS usuario_email,
                        ge.alias, g.nombre AS grupo_nombre, g.nivel AS grupo_nivel,
                        av.version_num
                 FROM actividad_entregas ae
                 LEFT JOIN actividad_acceso_politicas p ON p.id = ae.politica_id
                 LEFT JOIN usuarios u ON u.id = ae.usuario_id
                 LEFT JOIN grupo_estudiantes ge ON ge.id = ae.grupo_estudiante_id
                 LEFT JOIN grupos g ON g.id = ae.grupo_id
                 LEFT JOIN actividad_versiones av ON av.id = ae.version_id
                 WHERE {$where}
                 ORDER BY ae.created_at DESC
                 LIMIT 1000";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            if (($_GET['format'] ?? '') === 'csv') {
                header_remove('Content-Type');
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="entregas.csv"');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['actividad_tipo', 'actividad_ref', 'estudiante', 'email', 'alias', 'grupo', 'nivel',
                    'estado', 'puntaje', 'max_puntaje', 'porcentaje', 'tiempo_ms', 'evaluativa', 'version', 'entregado'], ';', '"', '\\');
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['actividad_tipo'], $r['actividad_ref'],
                        trim(($r['usuario_apellido'] ?? '') . ', ' . ($r['usuario_nombre'] ?? ''), ', '),
                        $r['usuario_email'], $r['alias'], $r['grupo_nombre'], $r['grupo_nivel'],
                        $r['estado'], $r['puntaje'], $r['max_puntaje'], $r['porcentaje'],
                        $r['time_ms'], $r['evaluativa'], $r['version_num'], $r['submitted_at'],
                    ], ';', '"', '\\');
                }
                fclose($out);
                exit;
            }
            triviax_api_success(['entregas' => $rows]);
            break;
        }

        default:
            triviax_api_error('UNKNOWN_ACTION', 'Acción de acceso desconocida.', 400);
    }
}
