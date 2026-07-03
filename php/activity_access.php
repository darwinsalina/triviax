<?php
/**
 * TRIVIAX v7.0 — Capa transversal de acceso, identidad y evaluación.
 *
 * Helpers reutilizables por TODAS las modalidades (tablero, study,
 * lotto, crucigrama, sopa de letras, jigsaw, etiquetar):
 *   · referencia canónica de actividad (tipo + ref)
 *   · generación y verificación de códigos (solo hash en BD)
 *   · normalización de alias de estudiantes
 *   · políticas de acceso (visibilidad, plazos, requisitos, alcance)
 *   · versionado evaluativo (snapshots congelados)
 *   · entregas transversales (actividad_entregas)
 *
 * Regla central: TODAS las validaciones se hacen en servidor. El
 * frontend puede ocultar actividades, pero nunca es la única barrera.
 *
 * Requiere: db.php (PDO) y auth.php (sesión, auditoría, rate limit).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ─────────────────────────────────────────────────────────────
// Tipos de actividad y referencia canónica
// ─────────────────────────────────────────────────────────────

/** Tipos de actividad válidos y su tabla de origen. */
function triviax_activity_types(): array {
    return [
        'proyecto'           => ['table' => 'proyectos',           'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'title'],
        'study_deck'         => ['table' => 'study_decks',         'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
        'lotto_activity'     => ['table' => 'lotto_activities',    'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
        'crossword_project'  => ['table' => 'crossword_projects',  'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
        'wordsearch_project' => ['table' => 'wordsearch_projects', 'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
        'jigsaw_project'     => ['table' => 'jigsaw_projects',     'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
        'etiquetar_project'  => ['table' => 'etiquetar_projects',  'id_col' => 'id',  'owner_col' => 'docente_id', 'title_col' => 'titulo'],
    ];
}

/**
 * Referencia canónica de una actividad. `proyectos.id` es un slug
 * textual; el resto usa enteros — siempre se guarda como string.
 */
function triviax_activity_ref(string $tipo, $id): string {
    if (!isset(triviax_activity_types()[$tipo])) {
        throw new InvalidArgumentException("Tipo de actividad desconocido: {$tipo}");
    }
    return (string)$id;
}

// ─────────────────────────────────────────────────────────────
// Códigos seguros (docente, grupo, acceso a actividad)
// ─────────────────────────────────────────────────────────────

/**
 * Genera un código alfanumérico criptográficamente seguro.
 * Por defecto evita caracteres confusos (O, 0, I, 1, L).
 */
function triviax_generate_secure_code(int $length = 12, bool $avoidConfusing = true): string {
    $alphabet = $avoidConfusing
        ? 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'
        : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max  = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/** Hash de un código compartible. Nunca guardar el texto plano. */
function triviax_code_hash(string $code): string {
    return password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT);
}

/** Verifica un código contra su hash (insensible a mayúsculas). */
function triviax_code_verify(?string $code, ?string $hash): bool {
    if ($code === null || $code === '' || $hash === null || $hash === '') {
        return false;
    }
    return password_verify(strtoupper(trim($code)), $hash);
}

// ─────────────────────────────────────────────────────────────
// Alias de estudiantes
// ─────────────────────────────────────────────────────────────

/**
 * Normaliza texto para alias: minúsculas, sin tildes ni diéresis
 * (conserva la ñ), solo letras [a-zñ] y dígitos.
 */
function triviax_alias_normalize(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ç'=>'c',
    ];
    $text = strtr($text, $map);
    // Conservar solo letras ascii, ñ y dígitos (quita espacios, puntos,
    // apóstrofes, guiones y cualquier otro carácter extraño).
    return preg_replace('/[^a-zñ0-9]/u', '', $text) ?? '';
}

/**
 * Alias base según la regla v7.0: primera letra del primer nombre +
 * apellido completo normalizado (palabras unidas).
 *   Juan Pérez → jperez · José María De León → jdeleon ·
 *   María-José Núñez → mnuñez · Ana Núñez → anuñez
 */
function triviax_alias_base(string $nombre, string $apellido): string {
    // Primer nombre = primer token separado por espacio o guion.
    $tokens = preg_split('/[\s\-]+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $inicial = '';
    foreach ($tokens as $t) {
        $n = triviax_alias_normalize($t);
        if ($n !== '') {
            $inicial = mb_substr($n, 0, 1, 'UTF-8');
            break;
        }
    }
    $ape = triviax_alias_normalize($apellido);
    $base = $inicial . $ape;
    return $base !== '' ? $base : 'estudiante';
}

/**
 * Devuelve un alias único dentro del grupo agregando sufijo numérico
 * correlativo: jperez, jperez2, jperez3…
 * `$existentes` es la lista de alias ya tomados (en minúsculas).
 */
function triviax_alias_make_unique(string $base, array $existentes): string {
    $taken = array_map('mb_strtolower', $existentes);
    if (!in_array(mb_strtolower($base), $taken, true)) {
        return $base;
    }
    $n = 2;
    while (in_array(mb_strtolower($base . $n), $taken, true)) {
        $n++;
    }
    return $base . $n;
}

// ─────────────────────────────────────────────────────────────
// Dominios de email
// ─────────────────────────────────────────────────────────────

/** Normaliza un dominio: sin '@', sin espacios, en minúsculas. */
function triviax_normalize_domain(string $dominio): string {
    return strtolower(trim(str_replace('@', '', $dominio)));
}

/** Dominio del email de un usuario, o null si no hay email válido. */
function triviax_email_domain(?string $email): ?string {
    if ($email === null || strpos($email, '@') === false) {
        return null;
    }
    $parts = explode('@', trim($email));
    $dom = strtolower(end($parts));
    return $dom !== '' ? $dom : null;
}

// ─────────────────────────────────────────────────────────────
// Políticas de acceso
// ─────────────────────────────────────────────────────────────

/**
 * Política por defecto para actividades sin fila propia (compatibilidad
 * con todo lo anterior a v7.0): pública, abierta, sin requisitos.
 */
function triviax_policy_defaults(string $tipo, string $ref): array {
    return [
        'id' => null,
        'actividad_tipo' => $tipo,
        'actividad_ref'  => $ref,
        'docente_id' => null,
        'visibilidad' => 'publica',
        'estado_publicacion' => 'abierta',
        'abre_at' => null,
        'cierra_at' => null,
        'requiere_login' => 0,
        'requiere_email_verificado' => 0,
        'requiere_validacion_docente' => 0,
        'evaluativa' => 0,
        'max_intentos' => null,
        'feedback_policy' => 'al_final',
        'codigo_acceso_hash' => null,
        'dominios' => [],
        'grupos' => [],
        'estudiantes' => [],
        'es_default' => true,
    ];
}

/**
 * Devuelve la política de acceso de una actividad, o null si no tiene.
 * Con $withRelations carga dominios, grupos y estudiantes habilitados.
 */
function triviax_get_access_policy(string $tipo, string $ref, bool $withRelations = true): ?array {
    $pdo = triviax_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM actividad_acceso_politicas WHERE actividad_tipo = ? AND actividad_ref = ? LIMIT 1'
    );
    $stmt->execute([$tipo, (string)$ref]);
    $policy = $stmt->fetch();
    if (!$policy) {
        return null;
    }
    $policy['es_default'] = false;
    if ($withRelations) {
        $stmt = $pdo->prepare('SELECT dominio FROM actividad_acceso_dominios WHERE politica_id = ?');
        $stmt->execute([$policy['id']]);
        $policy['dominios'] = array_column($stmt->fetchAll(), 'dominio');

        $stmt = $pdo->prepare(
            'SELECT g.id AS grupo_id, g.nombre, g.nivel, aag.scope_type, aag.titulo_asignacion, aag.fecha_clase
             FROM actividad_acceso_grupos aag
             JOIN grupos g ON g.id = aag.grupo_id
             WHERE aag.politica_id = ?'
        );
        $stmt->execute([$policy['id']]);
        $policy['grupos'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT id, grupo_estudiante_id, usuario_id, email, estado
             FROM actividad_acceso_estudiantes WHERE politica_id = ?'
        );
        $stmt->execute([$policy['id']]);
        $policy['estudiantes'] = $stmt->fetchAll();
    }
    return $policy;
}

/**
 * Política "efectiva": la guardada o, si no existe, la de compatibilidad
 * (pública/abierta). Nunca devuelve null.
 */
function triviax_get_effective_policy(string $tipo, string $ref, bool $withRelations = true): array {
    return triviax_get_access_policy($tipo, $ref, $withRelations)
        ?? triviax_policy_defaults($tipo, $ref);
}

/**
 * Crea o actualiza la política de una actividad. `$data` acepta las
 * columnas de actividad_acceso_politicas más las claves 'dominios'
 * (array de strings), 'grupos' (array de ['grupo_id','scope_type',...])
 * y 'estudiantes' (array de filas de habilitación).
 * Devuelve el id de la política.
 */
function triviax_save_access_policy(string $tipo, string $ref, array $data, ?int $docenteId = null): int {
    $pdo = triviax_db();
    $cols = [
        'visibilidad', 'estado_publicacion', 'abre_at', 'cierra_at',
        'requiere_login', 'requiere_email_verificado', 'requiere_validacion_docente',
        'evaluativa', 'max_intentos', 'feedback_policy', 'codigo_acceso_hash',
    ];
    $existing = triviax_get_access_policy($tipo, $ref, false);

    $pdo->beginTransaction();
    try {
        if ($existing) {
            $sets = [];
            $vals = [];
            foreach ($cols as $c) {
                if (array_key_exists($c, $data)) {
                    $sets[] = "`$c` = ?";
                    $vals[] = $data[$c];
                }
            }
            if ($sets) {
                $vals[] = $existing['id'];
                $pdo->prepare('UPDATE actividad_acceso_politicas SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute($vals);
            }
            $politicaId = (int)$existing['id'];
        } else {
            $defaults = triviax_policy_defaults($tipo, $ref);
            $vals = [$tipo, (string)$ref, $docenteId];
            $colSql = '';
            $qSql = '';
            foreach ($cols as $c) {
                $colSql .= ", `$c`";
                $qSql   .= ', ?';
                $vals[] = array_key_exists($c, $data) ? $data[$c] : $defaults[$c];
            }
            $pdo->prepare(
                "INSERT INTO actividad_acceso_politicas (actividad_tipo, actividad_ref, docente_id{$colSql})
                 VALUES (?, ?, ?{$qSql})"
            )->execute($vals);
            $politicaId = (int)$pdo->lastInsertId();
        }

        // Relaciones: si vienen definidas, se reemplazan por completo.
        if (array_key_exists('dominios', $data) && is_array($data['dominios'])) {
            $pdo->prepare('DELETE FROM actividad_acceso_dominios WHERE politica_id = ?')->execute([$politicaId]);
            $ins = $pdo->prepare('INSERT INTO actividad_acceso_dominios (politica_id, dominio) VALUES (?, ?)');
            foreach (array_unique(array_filter(array_map('triviax_normalize_domain', $data['dominios']))) as $dom) {
                $ins->execute([$politicaId, $dom]);
            }
        }
        if (array_key_exists('grupos', $data) && is_array($data['grupos'])) {
            $pdo->prepare('DELETE FROM actividad_acceso_grupos WHERE politica_id = ?')->execute([$politicaId]);
            $ins = $pdo->prepare(
                'INSERT INTO actividad_acceso_grupos
                 (politica_id, grupo_id, scope_type, titulo_asignacion, fecha_clase, instrucciones)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($data['grupos'] as $g) {
                $gid = (int)($g['grupo_id'] ?? 0);
                if ($gid <= 0) { continue; }
                $ins->execute([
                    $politicaId, $gid,
                    in_array($g['scope_type'] ?? 'grupo', ['grupo','clase','tarea'], true) ? ($g['scope_type'] ?? 'grupo') : 'grupo',
                    ($g['titulo_asignacion'] ?? null) ?: null,
                    ($g['fecha_clase'] ?? null) ?: null,
                    ($g['instrucciones'] ?? null) ?: null,
                ]);
            }
        }
        if (array_key_exists('estudiantes', $data) && is_array($data['estudiantes'])) {
            $pdo->prepare('DELETE FROM actividad_acceso_estudiantes WHERE politica_id = ?')->execute([$politicaId]);
            $ins = $pdo->prepare(
                'INSERT INTO actividad_acceso_estudiantes
                 (politica_id, grupo_estudiante_id, usuario_id, email, estado) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($data['estudiantes'] as $e) {
                $geId = !empty($e['grupo_estudiante_id']) ? (int)$e['grupo_estudiante_id'] : null;
                $uId  = !empty($e['usuario_id']) ? (int)$e['usuario_id'] : null;
                $mail = !empty($e['email']) ? strtolower(trim($e['email'])) : null;
                if ($geId === null && $uId === null && $mail === null) { continue; }
                $ins->execute([
                    $politicaId, $geId, $uId, $mail,
                    ($e['estado'] ?? 'habilitado') === 'bloqueado' ? 'bloqueado' : 'habilitado',
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $politicaId;
}

// ─────────────────────────────────────────────────────────────
// Identidad y pertenencia
// ─────────────────────────────────────────────────────────────

/** ¿El usuario (fila de sesión) tiene el email verificado? */
function triviax_user_email_verificado(?array $user): bool {
    if (!$user || empty($user['id'])) {
        return false;
    }
    // El login ya exige verificación, pero se consulta la BD por si la
    // sesión es vieja o la cuenta cambió.
    try {
        $stmt = triviax_db()->prepare('SELECT email_verificado FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Membresía del usuario en los grupos habilitados por la política.
 * Devuelve la fila de grupo_estudiantes (con grupo_id) o null.
 * Prefiere membresías 'validado' sobre 'pendiente'/'importado'.
 */
function triviax_resolve_student_group(?array $user, array $policy): ?array {
    if (!$user || empty($user['id']) || empty($policy['grupos'])) {
        return null;
    }
    $grupoIds = array_map(static fn($g) => (int)$g['grupo_id'], $policy['grupos']);
    $in = implode(',', array_fill(0, count($grupoIds), '?'));
    $params = $grupoIds;
    $params[] = (int)$user['id'];
    $params[] = strtolower(trim((string)($user['email'] ?? '')));
    $stmt = triviax_db()->prepare(
        "SELECT * FROM grupo_estudiantes
         WHERE grupo_id IN ($in)
           AND estado NOT IN ('rechazado','desactivado')
           AND (usuario_id = ? OR (email IS NOT NULL AND LOWER(email) = ?))
         ORDER BY FIELD(estado, 'validado', 'pendiente', 'importado'), id
         LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Nivel de identidad del usuario frente a una política:
 * anonimo → login → email_verificado → validado_docente.
 */
function triviax_identity_status(?array $user, ?array $membership = null): string {
    if (!$user || empty($user['id'])) {
        return 'anonimo';
    }
    if ($membership && ($membership['estado'] ?? '') === 'validado') {
        return 'validado_docente';
    }
    if (triviax_user_email_verificado($user)) {
        return 'email_verificado';
    }
    return 'login';
}

/** ¿El usuario es dueño de la política/actividad o superadmin? */
function triviax_is_activity_owner(?array $user, string $tipo, string $ref, ?array $policy = null): bool {
    if (!$user || empty($user['id'])) {
        return false;
    }
    if (($user['rol'] ?? '') === 'superadmin') {
        return true;
    }
    if ($policy && !empty($policy['docente_id']) && (int)$policy['docente_id'] === (int)$user['id']) {
        return true;
    }
    // Consultar la tabla de origen de la modalidad.
    $types = triviax_activity_types();
    if (!isset($types[$tipo])) {
        return false;
    }
    $t = $types[$tipo];
    try {
        $stmt = triviax_db()->prepare(
            "SELECT {$t['owner_col']} FROM {$t['table']} WHERE {$t['id_col']} = ? LIMIT 1"
        );
        $stmt->execute([$ref]);
        $owner = $stmt->fetchColumn();
        return $owner !== false && $owner !== null && (int)$owner === (int)$user['id'];
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// Decisión de acceso
// ─────────────────────────────────────────────────────────────

/**
 * ¿La actividad debe aparecer en listados/catálogos para este usuario?
 * Solo se listan las públicas actualmente abiertas; el dueño y el
 * superadmin ven siempre las suyas.
 */
function triviax_can_view_activity(?array $user, string $tipo, string $ref): bool {
    $policy = triviax_get_access_policy($tipo, $ref, false);
    if ($policy === null) {
        return true; // compatibilidad: sin política = pública abierta
    }
    if (triviax_is_activity_owner($user, $tipo, $ref, $policy)) {
        return true;
    }
    if ($policy['visibilidad'] !== 'publica') {
        return false;
    }
    return triviax_policy_window_open($policy) === 'ok';
}

/**
 * Estado de la ventana de publicación/plazos de una política:
 * 'ok' | 'no_publicada' | 'no_disponible_aun' | 'plazo_finalizado'.
 */
function triviax_policy_window_open(array $policy): string {
    $estado = $policy['estado_publicacion'] ?? 'abierta';
    if (in_array($estado, ['borrador', 'desactivada', 'archivada'], true)) {
        return 'no_publicada';
    }
    if ($estado === 'cerrada') {
        return 'plazo_finalizado';
    }
    $now = date('Y-m-d H:i:s');
    if (!empty($policy['abre_at']) && $now < $policy['abre_at']) {
        return 'no_disponible_aun';
    }
    if (!empty($policy['cierra_at']) && $now > $policy['cierra_at']) {
        return 'plazo_finalizado';
    }
    return 'ok';
}

/**
 * Decide si el usuario puede INICIAR la actividad, evaluando en orden:
 * publicación/plazos → login → email verificado → código de acceso →
 * dominios → grupos/lista → validación docente → máx. de intentos.
 *
 * Devuelve siempre:
 *   ['ok'=>bool, 'reason'=>string, 'message'=>string,
 *    'policy'=>array, 'identity'=>string, 'preview'=>bool,
 *    'grupo_id'=>?int, 'grupo_estudiante_id'=>?int]
 */
function triviax_can_start_activity(?array $user, string $tipo, string $ref, ?string $inputCode = null): array {
    $policy = triviax_get_effective_policy($tipo, $ref, true);
    $membership = triviax_resolve_student_group($user, $policy);
    $identity = triviax_identity_status($user, $membership);

    $result = [
        'ok' => false,
        'reason' => '',
        'message' => '',
        'policy' => $policy,
        'identity' => $identity,
        'preview' => false,
        'grupo_id' => $membership ? (int)$membership['grupo_id'] : null,
        'grupo_estudiante_id' => $membership ? (int)$membership['id'] : null,
    ];

    // Dueño y superadmin previsualizan siempre (sin generar entregas
    // evaluativas: el caller debe respetar 'preview').
    if (!$policy['es_default'] && triviax_is_activity_owner($user, $tipo, $ref, $policy)) {
        $result['ok'] = true;
        $result['reason'] = 'owner_preview';
        $result['preview'] = triviax_policy_window_open($policy) !== 'ok';
        return $result;
    }

    // 1. Publicación y plazos
    $window = triviax_policy_window_open($policy);
    if ($window !== 'ok') {
        $result['reason'] = $window;
        $result['message'] = [
            'no_publicada'       => 'La actividad no está disponible.',
            'no_disponible_aun'  => 'La actividad aún no está disponible.',
            'plazo_finalizado'   => 'El plazo de esta actividad ha finalizado.',
        ][$window];
        return $result;
    }

    // 2. Login
    if (!empty($policy['requiere_login']) && (!$user || empty($user['id']))) {
        $result['reason'] = 'requiere_login';
        $result['message'] = 'Esta actividad requiere iniciar sesión.';
        return $result;
    }

    // 3. Email verificado
    if (!empty($policy['requiere_email_verificado'])
        && !in_array($identity, ['email_verificado', 'validado_docente'], true)) {
        $result['reason'] = 'requiere_email_verificado';
        $result['message'] = 'Esta actividad requiere una cuenta con email verificado.';
        return $result;
    }

    // 4. Código de acceso (con rate limit contra fuerza bruta)
    if (!empty($policy['codigo_acceso_hash'])) {
        $ident = ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $tipo . ':' . $ref;
        if ($inputCode === null || $inputCode === '') {
            $result['reason'] = 'requiere_codigo';
            $result['message'] = 'Esta actividad requiere un código de acceso.';
            return $result;
        }
        if (!triviax_rate_limit_check('act_code', $ident, 10, 600)) {
            $result['reason'] = 'codigo_bloqueado';
            $result['message'] = 'Demasiados intentos. Espera unos minutos e intenta de nuevo.';
            return $result;
        }
        if (!triviax_code_verify($inputCode, $policy['codigo_acceso_hash'])) {
            triviax_rate_limit_hit('act_code', $ident, 600, 600, 10);
            $result['reason'] = 'codigo_invalido';
            $result['message'] = 'El código de acceso no es válido.';
            return $result;
        }
        triviax_rate_limit_clear('act_code', $ident);
    }

    // 5. Dominios permitidos
    if (!empty($policy['dominios'])) {
        $dom = triviax_email_domain($user['email'] ?? null);
        if ($dom === null || !in_array($dom, array_map('triviax_normalize_domain', $policy['dominios']), true)) {
            $result['reason'] = 'dominio_no_permitido';
            $result['message'] = 'Tu cuenta de correo no pertenece a los dominios habilitados.';
            return $result;
        }
    }

    // 6. Habilitación individual (prioridad sobre grupo: bloqueado gana)
    $listaIndividual = $policy['estudiantes'] ?? [];
    $habilitadoIndividual = false;
    if ($listaIndividual) {
        $uid  = $user['id'] ?? null;
        $mail = strtolower(trim((string)($user['email'] ?? '')));
        foreach ($listaIndividual as $e) {
            $match = ($uid && (int)($e['usuario_id'] ?? 0) === (int)$uid)
                || ($mail !== '' && strtolower((string)($e['email'] ?? '')) === $mail)
                || ($membership && (int)($e['grupo_estudiante_id'] ?? 0) === (int)$membership['id']);
            if ($match) {
                if ($e['estado'] === 'bloqueado') {
                    $result['reason'] = 'bloqueado';
                    $result['message'] = 'Tu acceso a esta actividad fue bloqueado por el docente.';
                    return $result;
                }
                $habilitadoIndividual = true;
            }
        }
    }

    // 7. Pertenencia a grupo (si la política define grupos o lista)
    if (!empty($policy['grupos']) || $listaIndividual) {
        $enGrupo = $membership !== null;
        if (!$enGrupo && !$habilitadoIndividual) {
            $result['reason'] = 'fuera_de_grupo';
            $result['message'] = 'No perteneces a los grupos habilitados para esta actividad.';
            return $result;
        }
    }

    // 8. Validación docente
    if (!empty($policy['requiere_validacion_docente']) && $identity !== 'validado_docente') {
        $result['reason'] = 'pendiente_validacion';
        $result['message'] = $membership
            ? 'Tu identidad está pendiente de validación por el docente.'
            : 'Esta actividad requiere validación del docente.';
        return $result;
    }

    // 9. Máximo de intentos (solo con usuario identificado)
    if (!empty($policy['max_intentos']) && !empty($policy['id']) && $user && !empty($user['id'])) {
        $stmt = triviax_db()->prepare(
            "SELECT COUNT(*) FROM actividad_entregas
             WHERE politica_id = ? AND usuario_id = ? AND estado <> 'cancelled'"
        );
        $stmt->execute([(int)$policy['id'], (int)$user['id']]);
        if ((int)$stmt->fetchColumn() >= (int)$policy['max_intentos']) {
            $result['reason'] = 'max_intentos';
            $result['message'] = 'Ya alcanzaste la cantidad máxima de intentos permitidos.';
            return $result;
        }
    }

    $result['ok'] = true;
    $result['reason'] = 'ok';
    return $result;
}

/**
 * Corta la ejecución con JSON 403 si el usuario no puede iniciar la
 * actividad. Para endpoints de juego. Devuelve el resultado de acceso
 * (con policy, identidad y pertenencia) si el acceso es válido.
 */
function triviax_require_activity_access(string $tipo, string $ref, ?string $inputCode = null): array {
    $user = triviax_usuario_actual();
    $acceso = triviax_can_start_activity($user, $tipo, $ref, $inputCode);
    if (!$acceso['ok']) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $acceso['reason'],
            'message' => $acceso['message'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $acceso;
}

// ─────────────────────────────────────────────────────────────
// Versionado evaluativo
// ─────────────────────────────────────────────────────────────

/**
 * Crea un snapshot de versión para una actividad. Si el contenido no
 * cambió respecto de la última versión (mismo hash), devuelve la
 * versión existente sin duplicar.
 * Devuelve ['id'=>int, 'version_num'=>int, 'created'=>bool].
 */
function triviax_create_activity_version(string $tipo, string $ref, array $snapshot, ?int $userId = null, ?string $notes = null): array {
    $pdo = triviax_db();
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar el snapshot de la actividad.');
    }
    $hash = hash('sha256', $json);

    $last = triviax_get_current_activity_version($tipo, $ref);
    if ($last && $last['content_hash'] === $hash) {
        return ['id' => (int)$last['id'], 'version_num' => (int)$last['version_num'], 'created' => false];
    }
    $nextNum = $last ? ((int)$last['version_num'] + 1) : 1;
    $pdo->prepare(
        'INSERT INTO actividad_versiones
         (actividad_tipo, actividad_ref, version_num, content_hash, snapshot_json, created_by, locked_at, notes)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)'
    )->execute([$tipo, (string)$ref, $nextNum, $hash, $json, $userId, $notes]);
    $id = (int)$pdo->lastInsertId();
    triviax_audit_log('actividad_version_creada', $tipo, (string)$ref, [
        'version_id' => $id, 'version_num' => $nextNum, 'content_hash' => $hash,
    ]);
    return ['id' => $id, 'version_num' => $nextNum, 'created' => true];
}

/** Última versión (mayor version_num) de una actividad, o null. */
function triviax_get_current_activity_version(string $tipo, string $ref): ?array {
    $stmt = triviax_db()->prepare(
        'SELECT * FROM actividad_versiones
         WHERE actividad_tipo = ? AND actividad_ref = ?
         ORDER BY version_num DESC LIMIT 1'
    );
    $stmt->execute([$tipo, (string)$ref]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * ¿La actividad tiene entregas/resultados asociados? Se usa para
 * bloquear la sobrescritura silenciosa de actividades evaluativas y la
 * eliminación definitiva.
 */
function triviax_activity_has_submissions(string $tipo, string $ref): bool {
    $pdo = triviax_db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM actividad_entregas
         WHERE actividad_tipo = ? AND actividad_ref = ? AND estado <> 'cancelled'"
    );
    $stmt->execute([$tipo, (string)$ref]);
    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }
    // Fuentes por modalidad (datos anteriores a v7.0 sin fila en entregas)
    $queries = [
        'proyecto' => "SELECT COUNT(*) FROM sesiones s JOIN intentos i ON i.sesion_id = s.id WHERE s.proyecto_id = ?",
        'study_deck' => "SELECT COUNT(*) FROM study_sessions ss JOIN study_attempts sa ON sa.study_session_id = ss.id WHERE ss.deck_id = ?",
        'lotto_activity' => "SELECT COUNT(*) FROM lotto_student_responses WHERE activity_id = ?",
        'jigsaw_project' => "SELECT COUNT(*) FROM jigsaw_sessions WHERE project_id = ? AND estado = 'finished'",
        'etiquetar_project' => "SELECT COUNT(*) FROM etiquetar_sessions WHERE project_id = ? AND estado = 'finished'",
        'crossword_project' => null,
        'wordsearch_project' => null,
    ];
    $sql = $queries[$tipo] ?? null;
    if ($sql === null) {
        return false;
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ref]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Registra una entrega transversal en actividad_entregas.
 * Claves de $data: actividad_tipo, actividad_ref (obligatorias);
 * politica_id, version_id, usuario_id, grupo_id, grupo_estudiante_id,
 * source_table, source_id, estado, puntaje, max_puntaje, porcentaje,
 * time_ms, started_at, submitted_at, evaluativa, summary_json.
 * Devuelve el id de la entrega.
 */
function triviax_record_activity_submission(array $data): int {
    if (empty($data['actividad_tipo']) || !isset($data['actividad_ref'])) {
        throw new InvalidArgumentException('actividad_tipo y actividad_ref son obligatorios.');
    }
    $puntaje = isset($data['puntaje']) ? (float)$data['puntaje'] : null;
    $maxPuntaje = isset($data['max_puntaje']) ? (float)$data['max_puntaje'] : null;
    $porcentaje = $data['porcentaje'] ?? null;
    if ($porcentaje === null && $puntaje !== null && $maxPuntaje !== null && $maxPuntaje > 0) {
        $porcentaje = round($puntaje / $maxPuntaje * 100, 2);
    }
    $summary = $data['summary_json'] ?? null;
    if (is_array($summary)) {
        $summary = json_encode($summary, JSON_UNESCAPED_UNICODE);
    }
    $pdo = triviax_db();
    $pdo->prepare(
        'INSERT INTO actividad_entregas
         (politica_id, version_id, actividad_tipo, actividad_ref, usuario_id, grupo_id,
          grupo_estudiante_id, source_table, source_id, estado, puntaje, max_puntaje,
          porcentaje, time_ms, started_at, submitted_at, evaluativa, summary_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $data['politica_id'] ?? null,
        $data['version_id'] ?? null,
        $data['actividad_tipo'],
        (string)$data['actividad_ref'],
        $data['usuario_id'] ?? null,
        $data['grupo_id'] ?? null,
        $data['grupo_estudiante_id'] ?? null,
        $data['source_table'] ?? null,
        isset($data['source_id']) ? (string)$data['source_id'] : null,
        in_array($data['estado'] ?? 'submitted', ['started','submitted','graded','cancelled'], true)
            ? ($data['estado'] ?? 'submitted') : 'submitted',
        $puntaje,
        $maxPuntaje,
        $porcentaje,
        $data['time_ms'] ?? null,
        $data['started_at'] ?? null,
        $data['submitted_at'] ?? date('Y-m-d H:i:s'),
        (int)($data['evaluativa'] ?? 0),
        $summary,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Sincroniza el estado interno de una modalidad (draft/published/
 * archived o estados lotto) con la política lateral de acceso, creando
 * la política por defecto si no existe. Si la actividad es evaluativa
 * y queda abierta, congela una versión.
 */
function triviax_sync_policy_publication(string $tipo, string $ref, string $estadoInterno, ?int $docenteId = null): void {
    $map = [
        'draft' => 'borrador', 'published' => 'abierta', 'archived' => 'archivada',
        'cancelled' => 'desactivada', 'finished' => 'cerrada',
    ];
    $estado = $map[$estadoInterno] ?? 'abierta';
    try {
        triviax_save_access_policy($tipo, $ref, ['estado_publicacion' => $estado], $docenteId);
        $policy = triviax_get_access_policy($tipo, $ref, false);
        if ($policy && !empty($policy['evaluativa']) && in_array($estado, ['programada', 'abierta'], true)) {
            $snapshot = triviax_build_activity_snapshot($tipo, $ref);
            if ($snapshot !== null) {
                triviax_create_activity_version($tipo, $ref, $snapshot, $docenteId, 'Publicación');
            }
        }
    } catch (Throwable $e) {
        // La sincronización de la política nunca debe romper el flujo original.
    }
}

/** Elimina la capa lateral (política y versiones) de una actividad borrada. */
function triviax_purge_activity_policy(string $tipo, string $ref): void {
    try {
        $pdo = triviax_db();
        $pdo->prepare('DELETE FROM actividad_acceso_politicas WHERE actividad_tipo = ? AND actividad_ref = ?')
            ->execute([$tipo, (string)$ref]);
        $pdo->prepare('DELETE FROM actividad_versiones WHERE actividad_tipo = ? AND actividad_ref = ?')
            ->execute([$tipo, (string)$ref]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Filtra un catálogo de actividades según visibilidad: quedan las que
 * no tienen política (compatibilidad), las públicas actualmente
 * abiertas y las del propio docente. Hace UNA consulta por lote.
 *
 * @param array    $items lista de actividades
 * @param callable $refFn fn($item): string — devuelve la ref del item
 */
function triviax_filter_catalog(?array $user, string $tipo, array $items, callable $refFn): array {
    if (!$items) {
        return $items;
    }
    try {
        $stmt = triviax_db()->prepare(
            'SELECT actividad_ref, docente_id, visibilidad, estado_publicacion, abre_at, cierra_at
             FROM actividad_acceso_politicas WHERE actividad_tipo = ?'
        );
        $stmt->execute([$tipo]);
        $policies = [];
        foreach ($stmt->fetchAll() as $p) {
            $policies[(string)$p['actividad_ref']] = $p;
        }
    } catch (Throwable $e) {
        return $items; // sin BD, comportamiento previo a v7.0
    }
    $userId = $user['id'] ?? null;
    $esSuper = ($user['rol'] ?? '') === 'superadmin';
    return array_values(array_filter($items, static function ($item) use ($policies, $refFn, $userId, $esSuper) {
        $ref = (string)$refFn($item);
        if (!isset($policies[$ref])) {
            return true; // sin política = pública (compatibilidad)
        }
        $p = $policies[$ref];
        if ($esSuper || ($userId !== null && (int)$p['docente_id'] === (int)$userId)) {
            return true; // el dueño ve siempre lo suyo
        }
        if ($p['visibilidad'] !== 'publica') {
            return false;
        }
        return triviax_policy_window_open($p) === 'ok';
    }));
}

/**
 * Construye el snapshot completo de una actividad para el versionado
 * evaluativo: título, configuración, contenido y respuestas correctas,
 * según la modalidad. Devuelve null si la actividad no existe.
 */
function triviax_build_activity_snapshot(string $tipo, string $ref): ?array {
    $pdo = triviax_db();
    $jsonDec = static function ($v) {
        if ($v === null || $v === '') { return null; }
        $d = json_decode((string)$v, true);
        return $d !== null ? $d : (string)$v;
    };
    switch ($tipo) {
        case 'proyecto': {
            $stmt = $pdo->prepare('SELECT * FROM proyectos WHERE id = ? LIMIT 1');
            $stmt->execute([$ref]);
            $p = $stmt->fetch();
            if (!$p) { return null; }
            $stmt = $pdo->prepare('SELECT challenge_key, tipo, prompt_text, title, difficulty, points, time_limit, data_json, orden FROM desafios WHERE proyecto_id = ? ORDER BY orden, id');
            $stmt->execute([$ref]);
            $desafios = array_map(static function ($d) use ($jsonDec) {
                $d['data_json'] = $jsonDec($d['data_json']);
                return $d;
            }, $stmt->fetchAll());
            return [
                'modalidad' => 'proyecto',
                'titulo' => $p['title'], 'nivel' => $p['nivel'], 'obs' => $p['obs'],
                'board_type' => $p['board_type'], 'formato' => $p['formato'],
                'desafios' => $desafios,
            ];
        }
        case 'study_deck': {
            $stmt = $pdo->prepare('SELECT * FROM study_decks WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$ref]);
            $d = $stmt->fetch();
            if (!$d) { return null; }
            $stmt = $pdo->prepare('SELECT card_key, orden, titulo, learning_objective, study_text, key_idea, vocabulary_json, assessment_type, assessment_json, feedback_json, difficulty, cognitive_level, points FROM study_cards WHERE deck_id = ? ORDER BY orden, id');
            $stmt->execute([(int)$ref]);
            $cards = array_map(static function ($c) use ($jsonDec) {
                foreach (['vocabulary_json', 'assessment_json', 'feedback_json'] as $k) {
                    $c[$k] = $jsonDec($c[$k]);
                }
                return $c;
            }, $stmt->fetchAll());
            return [
                'modalidad' => 'study_deck',
                'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'],
                'settings' => $jsonDec($d['settings_json']),
                'cards' => $cards,
            ];
        }
        case 'lotto_activity': {
            $stmt = $pdo->prepare('SELECT * FROM lotto_activities WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$ref]);
            $a = $stmt->fetch();
            if (!$a) { return null; }
            return [
                'modalidad' => 'lotto_activity',
                'titulo' => $a['titulo'], 'descripcion' => $a['descripcion'],
                'nivel' => $a['nivel'], 'grupo' => $a['grupo'],
                'sections_count' => (int)$a['sections_count'],
                'questions_per_student' => (int)$a['questions_per_student'],
                'study_minutes' => (int)$a['study_minutes'],
                'response_minutes' => (int)$a['response_minutes'],
                'draw_mode' => $a['draw_mode'],
                'settings' => $jsonDec($a['settings_json']),
                'rubric' => $jsonDec($a['rubric_json']),
                'generated' => $jsonDec($a['generated_json']),
            ];
        }
        case 'crossword_project':
        case 'wordsearch_project': {
            $table = $tipo === 'crossword_project' ? 'crossword_projects' : 'wordsearch_projects';
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$ref]);
            $p = $stmt->fetch();
            if (!$p) { return null; }
            return [
                'modalidad' => $tipo,
                'titulo' => $p['titulo'],
                'rows' => (int)$p['rows_n'], 'cols' => (int)$p['cols_n'],
                'word_count' => (int)$p['word_count'],
                'puzzle' => $jsonDec($p['puzzle_json']),
            ];
        }
        case 'jigsaw_project': {
            $stmt = $pdo->prepare('SELECT * FROM jigsaw_projects WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$ref]);
            $p = $stmt->fetch();
            if (!$p) { return null; }
            return [
                'modalidad' => 'jigsaw_project',
                'titulo' => $p['titulo'], 'descripcion' => $p['descripcion'],
                'image_path' => $p['image_path'], 'image_w' => (int)$p['image_w'], 'image_h' => (int)$p['image_h'],
                'difficulty_mode' => $p['difficulty_mode'], 'fixed_grid' => $p['fixed_grid'],
                'piece_shape' => $p['piece_shape'], 'play_mode' => $p['play_mode'],
                'show_hint' => $p['show_hint'], 'hint_opacity' => $p['hint_opacity'],
            ];
        }
        case 'etiquetar_project': {
            $stmt = $pdo->prepare('SELECT * FROM etiquetar_projects WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$ref]);
            $p = $stmt->fetch();
            if (!$p) { return null; }
            $stmt = $pdo->prepare('SELECT orden, text, ax, ay, lx, ly FROM etiquetar_labels WHERE project_id = ? ORDER BY orden, id');
            $stmt->execute([(int)$ref]);
            return [
                'modalidad' => 'etiquetar_project',
                'titulo' => $p['titulo'], 'descripcion' => $p['descripcion'],
                'image_path' => $p['image_path'], 'image_w' => (int)$p['image_w'], 'image_h' => (int)$p['image_h'],
                'box' => ['x' => $p['box_x'], 'y' => $p['box_y']],
                'labels' => $stmt->fetchAll(),
            ];
        }
    }
    return null;
}

/**
 * Protección de edición evaluativa: si la actividad es evaluativa y ya
 * tiene entregas, su contenido no debe sobrescribirse en silencio.
 * Los endpoints de guardado de cada modalidad deben llamar a esta
 * función DESPUÉS de guardar contenido; si devuelve una versión nueva,
 * el snapshot anterior queda congelado y las entregas viejas siguen
 * apuntando a su versión original.
 *
 * Devuelve ['protegida'=>bool, 'version'=>?array].
 */
function triviax_snapshot_after_evaluative_edit(string $tipo, string $ref, ?int $userId = null): array {
    $policy = triviax_get_access_policy($tipo, $ref, false);
    if (!$policy || empty($policy['evaluativa'])) {
        return ['protegida' => false, 'version' => null];
    }
    if (!triviax_activity_has_submissions($tipo, $ref)) {
        return ['protegida' => false, 'version' => null];
    }
    $snapshot = triviax_build_activity_snapshot($tipo, $ref);
    if ($snapshot === null) {
        return ['protegida' => true, 'version' => null];
    }
    $version = triviax_create_activity_version($tipo, $ref, $snapshot, $userId, 'Edición con entregas existentes');
    return ['protegida' => true, 'version' => $version];
}

/**
 * ¿Se puede eliminar definitivamente la actividad? Solo si no tiene
 * intentos/resultados/sesiones/entregas asociados; si los tiene, la
 * opción correcta es archivar (estado_publicacion = 'archivada').
 */
function triviax_can_delete_activity(string $tipo, string $ref): bool {
    return !triviax_activity_has_submissions($tipo, $ref);
}

/**
 * Vincula a un estudiante recién registrado con los grupos donde su
 * email fue importado por un docente (queda 'pendiente' de validación)
 * y, si trae un código de inscripción, lo une a ese grupo.
 * Nunca lanza: el registro no debe fallar por esta vinculación.
 * Devuelve ['vinculados'=>int, 'grupo'=>?string].
 */
function triviax_vincular_estudiante_registrado(int $usuarioId, string $email, string $nombre, string $apellido, ?string $codigoGrupo = null): array {
    $out = ['vinculados' => 0, 'grupo' => null];
    try {
        $pdo = triviax_db();
        $email = strtolower(trim($email));

        // 1) Filas importadas con este email → asociar cuenta.
        $stmt = $pdo->prepare(
            "UPDATE grupo_estudiantes
             SET usuario_id = ?, estado = IF(estado = 'importado', 'pendiente', estado)
             WHERE usuario_id IS NULL AND email IS NOT NULL AND LOWER(email) = ?
               AND estado NOT IN ('rechazado','desactivado')"
        );
        $stmt->execute([$usuarioId, $email]);
        $out['vinculados'] = $stmt->rowCount();
        if ($out['vinculados'] > 0) {
            triviax_audit_log('grupo_autovinculo_registro', 'usuarios', (string)$usuarioId, [
                'filas' => $out['vinculados'],
            ]);
        }

        // 2) Código de inscripción opcional → unirse como pendiente.
        $codigoGrupo = strtoupper(trim((string)$codigoGrupo));
        if ($codigoGrupo !== '' && preg_match('/^[A-Z0-9]{6,8}$/', $codigoGrupo)) {
            foreach ($pdo->query("SELECT id, nombre, codigo_inscripcion_hash FROM grupos WHERE activo = 1 AND codigo_inscripcion_hash IS NOT NULL") as $g) {
                if (!triviax_code_verify($codigoGrupo, $g['codigo_inscripcion_hash'])) {
                    continue;
                }
                $grupoId = (int)$g['id'];
                $stmt = $pdo->prepare(
                    'SELECT id FROM grupo_estudiantes
                     WHERE grupo_id = ? AND (usuario_id = ? OR (email IS NOT NULL AND LOWER(email) = ?)) LIMIT 1'
                );
                $stmt->execute([$grupoId, $usuarioId, $email]);
                if (!$stmt->fetchColumn()) {
                    $existentes = [];
                    $st = $pdo->prepare('SELECT alias FROM grupo_estudiantes WHERE grupo_id = ?');
                    $st->execute([$grupoId]);
                    $existentes = array_column($st->fetchAll(), 'alias');
                    $alias = triviax_alias_make_unique(triviax_alias_base($nombre, $apellido), $existentes);
                    $pdo->prepare(
                        "INSERT INTO grupo_estudiantes (grupo_id, usuario_id, email, nombre, apellido, alias, estado, source)
                         VALUES (?, ?, ?, ?, ?, ?, 'pendiente', 'self_join')"
                    )->execute([$grupoId, $usuarioId, $email, $nombre, $apellido, $alias]);
                    triviax_audit_log('grupo_join_registro', 'grupos', (string)$grupoId, ['usuario_id' => $usuarioId]);
                }
                $out['grupo'] = $g['nombre'];
                break;
            }
        }
    } catch (Throwable $e) {
        // silencioso: la vinculación es best-effort
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────
// Código docente secundario (docente_perfiles)
// ─────────────────────────────────────────────────────────────

/**
 * Genera (o regenera) el código docente secundario. Devuelve el código
 * en claro UNA sola vez; en BD queda solo el hash.
 */
function triviax_regenerar_codigo_docente(int $docenteId): string {
    $pdo = triviax_db();
    $codigo = triviax_generate_secure_code(12);
    $hash = triviax_code_hash($codigo);
    $stmt = $pdo->prepare('SELECT usuario_id, codigo_docente_hash FROM docente_perfiles WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$docenteId]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare(
            'UPDATE docente_perfiles
             SET codigo_docente_hash = ?, codigo_docente_rotado_at = NOW()
             WHERE usuario_id = ?'
        )->execute([$hash, $docenteId]);
        $accion = 'codigo_docente_regenerado';
    } else {
        $pdo->prepare(
            'INSERT INTO docente_perfiles (usuario_id, codigo_docente_hash, codigo_docente_generado_at)
             VALUES (?, ?, NOW())'
        )->execute([$docenteId, $hash]);
        $accion = 'codigo_docente_generado';
    }
    triviax_audit_log($accion, 'docente_perfiles', (string)$docenteId);
    return $codigo;
}
