<?php
/**
 * TRIVIAX v4.3 — Panel Superadmin
 * Acceso exclusivo para saltmine.development@gmail.com (rol superadmin).
 *
 * Funcionalidades:
 *   · Ver todos los usuarios (filtros: rol, estado de validación)
 *   · Ver/cambiar contraseñas de usuarios
 *   · Ver todos los proyectos del filesystem (la BD de proyectos puede estar vacía)
 *   · Renombrar, enviar a papelera (trash/) o eliminar definitivamente
 *   · Gestionar papelera: restaurar o eliminar para siempre
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_superadmin();

$pdo     = triviax_db();
$usuario = triviax_usuario_actual();

// ──────────────────────────────────────────────────────────────────
// HELPERS
// ──────────────────────────────────────────────────────────────────

/**
 * Lee el metadata de un proyecto desde su carpeta.
 * Soporta proyecto.json (nuevo formato) y preguntas.txt (legado).
 */
function _super_project_meta(string $folderPath, string $slug): array {
    $default = [
        'title'  => $slug,
        'author' => '',
        'nivel'  => '',
        'fecha'  => '',
        'mail'   => '',
    ];
    $jsonPath = $folderPath . '/proyecto.json';
    if (is_file($jsonPath)) {
        $data = @json_decode(file_get_contents($jsonPath), true);
        if (is_array($data) && isset($data['metadata'])) {
            $m = $data['metadata'];
            return [
                'title'  => $m['title']  ?? $slug,
                'author' => $m['author'] ?? '',
                'nivel'  => $m['nivel']  ?? '',
                'fecha'  => $m['date']   ?? '',
                'mail'   => $m['mail']   ?? '',
            ];
        }
    }
    // preguntas.txt — no tiene encabezado estructurado; usamos slug como título
    return $default;
}

/**
 * Borra una carpeta y todo su contenido de forma recursiva.
 */
function _super_rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? _super_rmdir_recursive($path) : @unlink($path);
    }
    @rmdir($dir);
}

// ──────────────────────────────────────────────────────────────────
// DATOS BASE — docentes indexados por email (para lookup rápido)
// ──────────────────────────────────────────────────────────────────

$docentesPorEmail = [];
$docentesPorId    = [];
try {
    $rows = $pdo->query(
        "SELECT id, nombre, apellido, email FROM usuarios WHERE rol IN ('docente','superadmin')"
    )->fetchAll();
    foreach ($rows as $d) {
        $docentesPorEmail[strtolower($d['email'])] = $d;
        $docentesPorId[(int)$d['id']]              = $d;
    }
} catch (\Exception $e) { /* sin DB, mostramos igual */ }

// ──────────────────────────────────────────────────────────────────
// PROCESAMIENTO DE ACCIONES POST
// ──────────────────────────────────────────────────────────────────

$actionMsg = '';
$actionErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $action = trim($_POST['action'] ?? '');
    $tab    = trim($_POST['tab']    ?? 'users');

    // ── Cambiar estado activo de usuario ──────────────────────
    if ($action === 'toggle_activo') {
        $uid   = (int)($_POST['uid']          ?? 0);
        $nuevo = (int)($_POST['nuevo_activo'] ?? 0);
        if ($uid > 0) {
            $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ? AND rol != 'superadmin'")
                ->execute([$nuevo, $uid]);
            $actionMsg = $nuevo ? 'Usuario activado.' : 'Usuario desactivado.';
        }
    }

    // ── Reenviar email de verificación ───────────────────────
    elseif ($action === 'resend_verification') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            $res = triviax_reenviar_verificacion($uid);
            if ($res['ok']) {
                if (!empty($res['local_preview'])) {
                    // Local: redirigir al simulador de email
                    header('Location: ' . $res['local_preview']);
                    exit;
                }
                $actionMsg = 'Email de verificación reenviado correctamente.';
            } else {
                $actionErr = $res['error'];
            }
        }
    }

    // ── Cambiar contraseña de usuario ─────────────────────────
    // SEGURIDAD: sólo se actualiza password_hash (bcrypt).
    // password_encrypted está obsoleto y NO se escribe más.
    elseif ($action === 'set_password') {
        $uid     = (int)(  $_POST['uid']          ?? 0);
        $newpass = trim(   $_POST['new_password'] ?? '');
        if ($uid > 0 && strlen($newpass) >= 6) {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $pdo->prepare(
                "UPDATE usuarios SET password_hash = ?
                  WHERE id = ? AND rol != 'superadmin'"
            )->execute([$hash, $uid]);
            $actionMsg = 'Contraseña actualizada correctamente.';
        } else {
            $actionErr = 'La contraseña debe tener al menos 6 caracteres.';
        }
    }

    // ── Renombrar proyecto ────────────────────────────────────
    elseif ($action === 'rename_project') {
        $slug   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['slug']   ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $fromTr = (int)($_POST['from_trash'] ?? 0);

        if ($slug !== '' && $titulo !== '') {
            $baseDir  = $fromTr
                ? (__DIR__ . '/../trash/' . $slug)
                : (__DIR__ . '/../proyectos/' . $slug);
            $jsonPath = $baseDir . '/proyecto.json';

            // Actualizar proyecto.json si existe
            if (is_file($jsonPath)) {
                $data = @json_decode(file_get_contents($jsonPath), true);
                if (is_array($data)) {
                    $data['metadata']['title'] = $titulo;
                    file_put_contents($jsonPath,
                        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
            // Actualizar BD si el registro existe
            try {
                $pdo->prepare('UPDATE proyectos SET title = ? WHERE id = ?')
                    ->execute([$titulo, $slug]);
            } catch (\Exception $e) { /* sin BD no importa */ }

            $actionMsg = 'Proyecto renombrado a "' . htmlspecialchars($titulo) . '".';
        }
    }

    // ── Enviar proyecto a papelera ────────────────────────────
    elseif ($action === 'trash_project') {
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['slug'] ?? '');
        if ($slug !== '') {
            $proyDir  = __DIR__ . '/../proyectos/';
            $src      = $proyDir . $slug;
            $trashDir = __DIR__ . '/../trash';
            if (!is_dir($trashDir)) {
                mkdir($trashDir, 0755, true);
            }
            $dst = $trashDir . '/' . $slug;

            if (is_dir($dst)) {
                $actionErr = 'Ya existe una carpeta con ese nombre en la papelera.';
            } elseif (!is_dir($src)) {
                $actionErr = 'No se encontró la carpeta del proyecto.';
            } elseif (rename($src, $dst)) {
                try {
                    $pdo->prepare(
                        'UPDATE proyectos SET in_trash = 1, trashed_at = NOW() WHERE id = ?'
                    )->execute([$slug]);
                } catch (\Exception $e) { /* columna puede no existir */ }
                $actionMsg = 'Proyecto enviado a la papelera.';
            } else {
                $actionErr = 'No se pudo mover la carpeta del proyecto.';
            }
        }
    }

    // ── Restaurar proyecto desde papelera ─────────────────────
    elseif ($action === 'restore_project') {
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['slug'] ?? '');
        if ($slug !== '') {
            $proyDir = __DIR__ . '/../proyectos/';
            $src     = __DIR__ . '/../trash/' . $slug;
            $dst     = $proyDir . $slug;

            if (is_dir($dst)) {
                $actionErr = 'Ya existe una carpeta activa con ese nombre.';
            } elseif (!is_dir($src)) {
                $actionErr = 'No se encontró la carpeta en la papelera.';
            } elseif (rename($src, $dst)) {
                try {
                    $pdo->prepare(
                        'UPDATE proyectos SET in_trash = 0, trashed_at = NULL WHERE id = ?'
                    )->execute([$slug]);
                } catch (\Exception $e) { /* columna puede no existir */ }
                $actionMsg = 'Proyecto restaurado correctamente.';
            } else {
                $actionErr = 'No se pudo restaurar la carpeta del proyecto.';
            }
        }
    }

    // ── Eliminar definitivamente ──────────────────────────────
    elseif ($action === 'delete_project') {
        $slug    = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['slug'] ?? '');
        $fromTr  = (int)($_POST['from_trash'] ?? 0);
        if ($slug !== '') {
            $baseDir = $fromTr
                ? (__DIR__ . '/../trash/' . $slug)
                : (__DIR__ . '/../proyectos/' . $slug);
            _super_rmdir_recursive($baseDir);
            try {
                $pdo->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$slug]);
            } catch (\Exception $e) { /* sin BD ok */ }
            $actionMsg = 'Proyecto eliminado definitivamente.';
        }
    }

    // Redirect POST → GET
    $loc = TRIVIAX_BASE . '/panel/super.php?tab=' . urlencode($tab);
    if ($actionMsg !== '') $loc .= '&msg=' . urlencode($actionMsg);
    if ($actionErr !== '') $loc .= '&err=' . urlencode($actionErr);
    header('Location: ' . $loc);
    exit;
}

// Recuperar mensajes de redirect
if (isset($_GET['msg'])) $actionMsg = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
if (isset($_GET['err'])) $actionErr = htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8');

$activeTab = $_GET['tab'] ?? 'users';

// ──────────────────────────────────────────────────────────────────
// DATOS — Usuarios
// ──────────────────────────────────────────────────────────────────

$filtroUsuario = $_GET['filtro'] ?? 'todos';

$whereUsuario = "WHERE rol != 'superadmin'";
switch ($filtroUsuario) {
    case 'docentes':    $whereUsuario = "WHERE rol = 'docente'"; break;
    case 'estudiantes': $whereUsuario = "WHERE rol = 'estudiante'"; break;
    case 'validados':   $whereUsuario = "WHERE rol != 'superadmin' AND email_verificado = 1"; break;
    case 'sin_validar': $whereUsuario = "WHERE rol != 'superadmin' AND email_verificado = 0"; break;
}

$usuarios = [];
try {
    // SEGURIDAD [v4.4]: password_encrypted eliminado del SELECT — nunca se expone en UI
    $usuarios = $pdo->query(
        "SELECT id, nombre, apellido, email, rol, activo, email_verificado,
                created_at
         FROM usuarios
         $whereUsuario
         ORDER BY created_at DESC"
    )->fetchAll();
} catch (\Exception $e) { /* manejar sin BD */ }

// ──────────────────────────────────────────────────────────────────
// DATOS — Proyectos activos (filesystem como ground truth)
// ──────────────────────────────────────────────────────────────────

$proyBaseDir  = __DIR__ . '/../proyectos/';
$proyTrashDir = __DIR__ . '/../trash/';

/**
 * Escanea una carpeta y devuelve todos los proyectos encontrados,
 * enriquecidos con datos del docente (si se puede hacer lookup por email).
 */
function _super_scan_projects(string $dir, array $docentesPorEmail): array {
    $list = [];
    if (!is_dir($dir)) {
        return $list;
    }
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir . $item;
        if (!is_dir($path)) {
            continue;
        }
        $meta    = _super_project_meta($path, $item);
        $docente = null;
        if ($meta['mail'] !== '') {
            $docente = $docentesPorEmail[strtolower($meta['mail'])] ?? null;
        }
        $list[] = [
            'slug'             => $item,
            'title'            => $meta['title'],
            'autor'            => $meta['author'],
            'nivel'            => $meta['nivel'],
            'fecha'            => $meta['fecha'],
            'mail_proyecto'    => $meta['mail'],
            'docente_nombre'   => $docente['nombre']   ?? '',
            'docente_apellido' => $docente['apellido'] ?? '',
            'docente_email'    => $docente['email']    ?? ($meta['mail'] ?: '—'),
        ];
    }
    // Ordenar: primero con docente, luego por título
    usort($list, function($a, $b) {
        $da = $a['docente_email'];
        $db = $b['docente_email'];
        if ($da !== $db) {
            return strcmp($da, $db);
        }
        return strcmp($a['title'], $b['title']);
    });
    return $list;
}

$proyectosActivos = _super_scan_projects($proyBaseDir, $docentesPorEmail);

// Agrupar por docente (clave = email del proyecto)
$porDocente = [];
foreach ($proyectosActivos as $proy) {
    $groupKey = $proy['docente_email'];
    if (!isset($porDocente[$groupKey])) {
        $porDocente[$groupKey] = [
            'nombre'    => $proy['docente_nombre'],
            'apellido'  => $proy['docente_apellido'],
            'email'     => $proy['docente_email'],
            'proyectos' => [],
        ];
    }
    $porDocente[$groupKey]['proyectos'][] = $proy;
}

// ──────────────────────────────────────────────────────────────────
// DATOS — Papelera (filesystem trash/)
// ──────────────────────────────────────────────────────────────────

$papelera = _super_scan_projects($proyTrashDir, $docentesPorEmail);
// Agregar trashed_at desde DB si está disponible
$dbTrashedAt = [];
try {
    $rows = $pdo->query("SELECT id, trashed_at FROM proyectos WHERE in_trash = 1")->fetchAll();
    foreach ($rows as $r) {
        $dbTrashedAt[$r['id']] = $r['trashed_at'];
    }
} catch (\Exception $e) { /* columna puede no existir */ }
foreach ($papelera as &$tp) {
    $tp['trashed_at'] = $dbTrashedAt[$tp['slug']] ?? null;
}
unset($tp);

// ── CSRF ─────────────────────────────────────────────────────────
$csrf = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Superadmin — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }

        .super-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ── */
        .super-header {
            background: rgba(10, 14, 26, 0.95);
            border-bottom: 1px solid rgba(239, 68, 68, 0.25);
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 60px;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(12px);
        }
        .super-header-brand { display: flex; align-items: center; gap: 12px; }
        .super-header-brand .logo {
            font-size: 1.4rem; font-weight: 800; letter-spacing: 2px;
            background: var(--accent-gradient);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .super-badge {
            background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.4);
            color: #fca5a5; font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.1em;
            padding: 3px 10px; border-radius: 20px;
        }
        .super-header-user { display: flex; align-items: center; gap: 16px; font-size: 0.85rem; color: var(--text-secondary); }
        .super-header-user a { color: #fca5a5; text-decoration: none; font-weight: 600; font-size: 0.82rem; }
        .super-header-user a:hover { color: #ef4444; }

        /* ── Tabs ── */
        .super-tabs {
            display: flex; gap: 0;
            border-bottom: 1px solid var(--border-color);
            padding: 0 28px;
            background: rgba(0,0,0,0.2);
        }
        .super-tab {
            padding: 14px 24px; font-size: 0.88rem; font-weight: 600;
            color: var(--text-muted); text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: color 0.2s, border-color 0.2s;
        }
        .super-tab:hover { color: var(--text-secondary); }
        .super-tab.active { color: var(--text-primary); border-bottom-color: #6366f1; }

        /* ── Contenido ── */
        .super-content {
            flex: 1; padding: 28px;
            max-width: 1200px; width: 100%; margin: 0 auto;
        }

        /* ── Alertas ── */
        .super-alert {
            padding: 12px 18px; border-radius: 10px;
            font-size: 0.9rem; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .super-alert.success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }
        .super-alert.error   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.3);  color: #fca5a5; }

        /* ── Filtros ── */
        .filter-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-btn {
            padding: 7px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
            text-decoration: none; border: 1px solid var(--border-color);
            color: var(--text-secondary); background: rgba(255,255,255,0.03);
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .filter-btn:hover { background: rgba(255,255,255,0.07); color: var(--text-primary); }
        .filter-btn.active { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.4); color: #a5b4fc; }

        /* ── Tabla de usuarios ── */
        .super-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color); }
        .super-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .super-table th {
            background: rgba(255,255,255,0.04); color: var(--text-muted);
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap;
        }
        .super-table td {
            padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.04);
            color: var(--text-secondary); vertical-align: middle;
        }
        .super-table tr:last-child td { border-bottom: none; }
        .super-table tr:hover td     { background: rgba(255,255,255,0.02); }
        .super-table td.col-name  { color: var(--text-primary); font-weight: 600; }
        .super-table td.col-email { color: #a5b4fc; font-size: 0.82rem; }

        /* ── Badges ── */
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 6px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
        }
        .badge-docente    { background: rgba(99,102,241,0.15); color: #a5b4fc; }
        .badge-estudiante { background: rgba(16,185,129,0.12); color: #6ee7b7; }
        .badge-activo     { background: rgba(16,185,129,0.12); color: #6ee7b7; }
        .badge-inactivo   { background: rgba(107,114,128,0.15); color: #9ca3af; }
        .badge-yes        { background: rgba(16,185,129,0.08); color: #86efac; }
        .badge-no         { background: rgba(245,158,11,0.12); color: #fcd34d; }

        /* ── Ver contraseña ── */
        .pass-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .pass-text {
            font-family: monospace; font-size: 0.85rem; color: #fbbf24;
            background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.2);
            border-radius: 6px; padding: 2px 8px; display: none;
        }
        .pass-mask { color: var(--text-muted); font-size: 0.82rem; letter-spacing: 2px; }
        .btn-eye {
            background: none; border: none; cursor: pointer; font-size: 1rem;
            color: var(--text-muted); padding: 2px 4px; border-radius: 4px; transition: color 0.15s;
        }
        .btn-eye:hover { color: #fbbf24; }

        /* ── Form inline de contraseña ── */
        .pass-form { display: none; margin-top: 8px; gap: 6px; align-items: center; }
        .pass-form.open { display: flex; }
        .pass-form input {
            flex: 1; min-width: 0;
            background: rgba(255,255,255,0.06); border: 1px solid var(--border-color);
            border-radius: 7px; padding: 6px 10px; color: var(--text-primary);
            font-size: 0.85rem; font-family: var(--font-main); outline: none;
        }
        .pass-form input:focus { border-color: rgba(99,102,241,0.5); box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }

        /* ── Botones pequeños ── */
        .action-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .btn-sm {
            padding: 5px 10px; border-radius: 7px; font-size: 0.78rem; font-weight: 600;
            cursor: pointer; border: 1px solid transparent;
            transition: opacity 0.15s, transform 0.1s;
            text-decoration: none; display: inline-block; white-space: nowrap;
        }
        .btn-sm:hover  { opacity: 0.85; transform: translateY(-1px); }
        .btn-sm:active { transform: translateY(0); }
        .btn-on      { background: rgba(16,185,129,0.15);  border-color: rgba(16,185,129,0.35);  color: #6ee7b7; }
        .btn-off     { background: rgba(107,114,128,0.15); border-color: rgba(107,114,128,0.3);  color: #9ca3af; }
        .btn-danger  { background: rgba(239,68,68,0.12);   border-color: rgba(239,68,68,0.35);   color: #fca5a5; }
        .btn-warn    { background: rgba(245,158,11,0.12);  border-color: rgba(245,158,11,0.3);   color: #fcd34d; }
        .btn-neutral { background: rgba(255,255,255,0.05); border-color: var(--border-color);    color: var(--text-secondary); }
        .btn-primary { background: rgba(99,102,241,0.15);  border-color: rgba(99,102,241,0.35);  color: #a5b4fc; }

        /* ── Proyectos: accordeon por docente ── */
        .section-count {
            font-size: 0.73rem; font-weight: 600; color: var(--text-muted);
            background: rgba(255,255,255,0.06); border: 1px solid var(--border-color);
            border-radius: 20px; padding: 2px 9px; margin-left: 8px;
        }
        .docente-block { margin-bottom: 16px; border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; }
        .docente-header {
            background: rgba(255,255,255,0.04); padding: 12px 18px;
            display: flex; align-items: center; justify-content: space-between;
            cursor: pointer; user-select: none;
        }
        .docente-header:hover { background: rgba(255,255,255,0.06); }
        .docente-info .dname { color: var(--text-primary); font-weight: 600; font-size: 0.9rem; }
        .docente-info .demail { color: #a5b4fc; font-size: 0.8rem; margin-left: 8px; }
        .docente-arrow { color: var(--text-muted); font-size: 0.8rem; transition: transform 0.2s; }
        .docente-block.open .docente-arrow { transform: rotate(180deg); }
        .docente-projects { display: none; border-top: 1px solid var(--border-color); }
        .docente-block.open .docente-projects { display: block; }

        /* ── Fila de proyecto ── */
        .project-row {
            display: flex; align-items: center; gap: 12px; padding: 10px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.04); flex-wrap: wrap;
        }
        .project-row:last-child { border-bottom: none; }
        .project-title-col { flex: 1; min-width: 180px; }
        .project-title-text { font-weight: 600; color: var(--text-primary); font-size: 0.88rem; }
        .project-slug  { font-size: 0.72rem; color: var(--text-muted); font-family: monospace; display: block; margin-top: 2px; }
        .project-meta  { font-size: 0.78rem; color: var(--text-muted); white-space: nowrap; }

        /* ── Form inline de rename ── */
        .rename-form {
            display: none; width: 100%; padding: 10px 18px; gap: 8px;
            background: rgba(99,102,241,0.05); border-top: 1px solid rgba(99,102,241,0.15);
            align-items: center;
        }
        .rename-form.open { display: flex; }
        .rename-form input {
            flex: 1; min-width: 0;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(99,102,241,0.3);
            border-radius: 7px; padding: 7px 12px; color: var(--text-primary);
            font-size: 0.88rem; font-family: var(--font-main); outline: none;
        }
        .rename-form input:focus { border-color: rgba(99,102,241,0.6); box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }

        /* ── Estado vacío ── */
        .empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted); font-size: 0.9rem; }
        .empty-icon  { font-size: 2.5rem; margin-bottom: 12px; display: block; }

        @media (max-width: 700px) {
            .super-content  { padding: 16px; }
            .super-tabs     { padding: 0 12px; }
            .super-header   { padding: 0 16px; }
            .super-tab      { padding: 12px 14px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
<div class="super-layout">

<!-- ══ HEADER ══════════════════════════════════════════════════════ -->
<header class="super-header">
    <div class="super-header-brand">
        <span class="logo">TRIVIAX</span>
        <span class="super-badge">⚡ Superadmin</span>
    </div>
    <div class="super-header-user">
        <span><?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?></span>
        <a href="<?= TRIVIAX_BASE ?>/panel/test_mail.php" style="color:var(--text-muted)" title="Diagnóstico de correo">📧 Test mail</a>
        <a href="<?= TRIVIAX_BASE ?>/auth/logout.php">Cerrar sesión</a>
    </div>
</header>

<!-- ══ TABS ════════════════════════════════════════════════════════ -->
<nav class="super-tabs">
    <a href="?tab=users"    class="super-tab <?= $activeTab === 'users'    ? 'active' : '' ?>">👥 Usuarios</a>
    <a href="?tab=projects" class="super-tab <?= $activeTab === 'projects' ? 'active' : '' ?>">
        📁 Proyectos<?= count($proyectosActivos) > 0 ? ' (' . count($proyectosActivos) . ')' : '' ?>
    </a>
    <a href="?tab=trash"    class="super-tab <?= $activeTab === 'trash'    ? 'active' : '' ?>">
        🗑 Papelera<?= count($papelera) > 0 ? ' (' . count($papelera) . ')' : '' ?>
    </a>
    <a href="<?= TRIVIAX_BASE ?>/panel/generador_tableros.php" class="super-tab">🗺️ Tableros</a>
</nav>

<!-- ══ CONTENIDO ═══════════════════════════════════════════════════ -->
<main class="super-content">

<?php if ($actionMsg !== ''): ?>
    <div class="super-alert success">✅ <?= $actionMsg ?></div>
<?php endif; ?>
<?php if ($actionErr !== ''): ?>
    <div class="super-alert error">❌ <?= $actionErr ?></div>
<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
         TAB: USUARIOS
         ════════════════════════════════════════════════════ */
if ($activeTab === 'users'): ?>

    <div class="filter-row">
    <?php foreach ([
        'todos'       => 'Todos',
        'docentes'    => 'Docentes',
        'estudiantes' => 'Estudiantes',
        'validados'   => 'Validados',
        'sin_validar' => 'Sin validar',
    ] as $key => $label): ?>
        <a href="?tab=users&filtro=<?= urlencode($key) ?>"
           class="filter-btn <?= $filtroUsuario === $key ? 'active' : '' ?>">
            <?= $label ?><?= $key === 'todos' ? ' <small style="opacity:.6">(' . count($usuarios) . ')</small>' : '' ?>
        </a>
    <?php endforeach; ?>
    </div>

    <?php if (count($usuarios) === 0): ?>
        <div class="empty-state"><span class="empty-icon">🔍</span>No hay usuarios que coincidan.</div>
    <?php else: ?>
    <div class="super-table-wrap">
        <table class="super-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Email verificado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem"><?= $u['id'] ?></td>
                <td class="col-name"><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="col-email"><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <span class="badge badge-<?= $u['rol'] ?>">
                        <?= $u['rol'] === 'docente' ? '🎓 Docente' : '🧑 Estudiante' ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $u['activo'] ? 'badge-activo' : 'badge-inactivo' ?>">
                        <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
                    </span>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span class="badge <?= $u['email_verificado'] ? 'badge-yes' : 'badge-no' ?>">
                            <?= $u['email_verificado'] ? '✔ Sí' : '⏳ No' ?>
                        </span>
                        <?php if (!$u['email_verificado']): ?>
                        <form method="POST" action="?tab=users&filtro=<?= urlencode($filtroUsuario) ?>" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action"     value="resend_verification">
                            <input type="hidden" name="uid"        value="<?= $u['id'] ?>">
                            <input type="hidden" name="tab"        value="users">
                            <button type="submit" class="btn-sm btn-primary"
                                    title="Reenviar email de confirmación a <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>">
                                📧 Reenviar
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="action-row">
                        <form method="POST" action="?tab=users&filtro=<?= urlencode($filtroUsuario) ?>" style="display:inline">
                            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action"       value="toggle_activo">
                            <input type="hidden" name="uid"          value="<?= $u['id'] ?>">
                            <input type="hidden" name="nuevo_activo" value="<?= $u['activo'] ? 0 : 1 ?>">
                            <input type="hidden" name="tab"          value="users">
                            <button type="submit" class="btn-sm <?= $u['activo'] ? 'btn-off' : 'btn-on' ?>">
                                <?= $u['activo'] ? '🔒 Desactivar' : '🔓 Activar' ?>
                            </button>
                        </form>
                        <button class="btn-sm btn-warn" onclick="openPF(<?= $u['id'] ?>)" title="Cambiar contraseña">🔑 Pass</button>
                    </div>
                    <!-- Formulario oculto de cambio de contraseña — sin mostrar la actual -->
                    <form method="POST" action="?tab=users&filtro=<?= urlencode($filtroUsuario) ?>"
                          class="pass-form" id="pf-<?= $u['id'] ?>">
                        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action"       value="set_password">
                        <input type="hidden" name="uid"          value="<?= $u['id'] ?>">
                        <input type="hidden" name="tab"          value="users">
                        <input type="password" name="new_password" placeholder="Nueva contraseña (mín. 6)" autocomplete="new-password">
                        <button type="submit" class="btn-sm btn-primary">Guardar</button>
                        <button type="button" class="btn-sm btn-neutral" onclick="closePF(<?= $u['id'] ?>)">✕</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php /* ════════════════════════════════════════════════════
         TAB: PROYECTOS
         ════════════════════════════════════════════════════ */
elseif ($activeTab === 'projects'): ?>

    <?php if (count($proyectosActivos) === 0): ?>
        <div class="empty-state">
            <span class="empty-icon">📭</span>
            No se encontraron proyectos en la carpeta <code>proyectos/</code>.
        </div>
    <?php else: ?>

    <?php foreach ($porDocente as $groupEmail => $docData): ?>
        <?php $blockId = 'db-' . md5($groupEmail); ?>
        <div class="docente-block open" id="<?= $blockId ?>">
            <div class="docente-header" onclick="toggleDocente('<?= $blockId ?>')">
                <div class="docente-info">
                    <?php if ($docData['nombre'] !== ''): ?>
                        <span class="dname"><?= htmlspecialchars($docData['nombre'] . ' ' . $docData['apellido'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="demail"><?= htmlspecialchars($docData['email'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                        <span class="dname"><?= htmlspecialchars($docData['email'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span class="section-count"><?= count($docData['proyectos']) ?> proyecto<?= count($docData['proyectos']) !== 1 ? 's' : '' ?></span>
                </div>
                <span class="docente-arrow">▼</span>
            </div>
            <div class="docente-projects">
                <?php foreach ($docData['proyectos'] as $proy):
                    $slug = htmlspecialchars($proy['slug'], ENT_QUOTES, 'UTF-8');
                    $titleEsc = htmlspecialchars($proy['title'], ENT_QUOTES, 'UTF-8');
                    $titleJs  = htmlspecialchars(addslashes($proy['title']), ENT_QUOTES, 'UTF-8');
                ?>
                <div id="pr-<?= $slug ?>">
                    <div class="project-row">
                        <div class="project-title-col">
                            <span class="project-title-text">📂 <?= $titleEsc ?></span>
                            <span class="project-slug"><?= $slug ?></span>
                        </div>
                        <?php if ($proy['nivel']): ?><span class="project-meta"><?= htmlspecialchars($proy['nivel'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <?php if ($proy['fecha']): ?><span class="project-meta"><?= htmlspecialchars($proy['fecha'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <div class="action-row">
                            <button class="btn-sm btn-neutral"
                                    onclick="openRename('<?= $slug ?>', '<?= $titleJs ?>', 0)">
                                ✏️ Renombrar
                            </button>
                            <form method="POST" action="?tab=projects" style="display:inline"
                                  onsubmit="return confirm('¿Enviar «<?= $titleJs ?>» a la papelera?\nPodrás restaurarlo después.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action"     value="trash_project">
                                <input type="hidden" name="slug"       value="<?= $slug ?>">
                                <input type="hidden" name="tab"        value="projects">
                                <button type="submit" class="btn-sm btn-warn">🗑 Papelera</button>
                            </form>
                            <form method="POST" action="?tab=projects" style="display:inline"
                                  onsubmit="return confirm('⚠️ ¿Eliminar DEFINITIVAMENTE «<?= $titleJs ?>»?\n\nEsta acción no se puede deshacer.')">
                                <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action"      value="delete_project">
                                <input type="hidden" name="slug"        value="<?= $slug ?>">
                                <input type="hidden" name="from_trash"  value="0">
                                <input type="hidden" name="tab"         value="projects">
                                <button type="submit" class="btn-sm btn-danger">💥 Eliminar</button>
                            </form>
                        </div>
                    </div>
                    <form method="POST" action="?tab=projects"
                          class="rename-form" id="rf-<?= $slug ?>">
                        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action"      value="rename_project">
                        <input type="hidden" name="slug"        value="<?= $slug ?>">
                        <input type="hidden" name="from_trash"  value="0">
                        <input type="hidden" name="tab"         value="projects">
                        <input type="text"   name="titulo" id="rfi-<?= $slug ?>"
                               placeholder="Nuevo título del proyecto">
                        <button type="submit" class="btn-sm btn-primary">Guardar</button>
                        <button type="button" class="btn-sm btn-neutral"
                                onclick="closeRename('<?= $slug ?>')">✕</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php endif; ?>

<?php /* ════════════════════════════════════════════════════
         TAB: PAPELERA
         ════════════════════════════════════════════════════ */
elseif ($activeTab === 'trash'): ?>

    <?php if (count($papelera) === 0): ?>
        <div class="empty-state">
            <span class="empty-icon">🗑</span>
            La papelera está vacía.
        </div>
    <?php else: ?>
    <div class="super-table-wrap">
        <table class="super-table">
            <thead>
                <tr>
                    <th>Proyecto</th>
                    <th>Slug / Carpeta</th>
                    <th>Docente / Autor</th>
                    <th>Enviado a papelera</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($papelera as $tp):
                $slug    = htmlspecialchars($tp['slug'], ENT_QUOTES, 'UTF-8');
                $titleEsc = htmlspecialchars($tp['title'], ENT_QUOTES, 'UTF-8');
                $titleJs  = htmlspecialchars(addslashes($tp['title']), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td style="font-weight:600;color:var(--text-primary)">
                    <?= $titleEsc ?>
                    <div style="margin-top:4px">
                        <button class="btn-sm btn-neutral"
                                onclick="openRename('<?= $slug ?>', '<?= $titleJs ?>', 1)"
                                style="font-size:0.72rem;padding:3px 8px">✏️ Renombrar</button>
                    </div>
                    <form method="POST" action="?tab=trash"
                          class="rename-form" id="rf-<?= $slug ?>" style="margin-top:6px;width:auto;min-width:280px">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action"     value="rename_project">
                        <input type="hidden" name="slug"       value="<?= $slug ?>">
                        <input type="hidden" name="from_trash" value="1">
                        <input type="hidden" name="tab"        value="trash">
                        <input type="text"   name="titulo" id="rfi-<?= $slug ?>" placeholder="Nuevo título">
                        <button type="submit" class="btn-sm btn-primary">Guardar</button>
                        <button type="button" class="btn-sm btn-neutral" onclick="closeRename('<?= $slug ?>')">✕</button>
                    </form>
                </td>
                <td style="font-size:0.78rem;color:var(--text-muted);font-family:monospace"><?= $slug ?></td>
                <td style="color:#a5b4fc;font-size:0.85rem">
                    <?php
                    $dname = trim($tp['docente_nombre'] . ' ' . $tp['docente_apellido']);
                    echo $dname !== ''
                        ? htmlspecialchars($dname, ENT_QUOTES, 'UTF-8')
                        : '<span style="color:var(--text-muted)">—</span>';
                    ?>
                </td>
                <td style="color:var(--text-muted);font-size:0.82rem">
                    <?= $tp['trashed_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($tp['trashed_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                </td>
                <td>
                    <div class="action-row">
                        <form method="POST" action="?tab=trash" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action"     value="restore_project">
                            <input type="hidden" name="slug"       value="<?= $slug ?>">
                            <input type="hidden" name="tab"        value="trash">
                            <button type="submit" class="btn-sm btn-on">♻️ Restaurar</button>
                        </form>
                        <form method="POST" action="?tab=trash" style="display:inline"
                              onsubmit="return confirm('⚠️ ¿Eliminar DEFINITIVAMENTE «<?= $titleJs ?>»?\n\nSe borrará para siempre. NO se puede deshacer.')">
                            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action"      value="delete_project">
                            <input type="hidden" name="slug"        value="<?= $slug ?>">
                            <input type="hidden" name="from_trash"  value="1">
                            <input type="hidden" name="tab"         value="trash">
                            <button type="submit" class="btn-sm btn-danger">💥 Eliminar para siempre</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php endif; ?>

</main>
</div>

<script>
// ── Ver / ocultar contraseña ─────────────────────────────
function togglePass(uid) {
    const mask = document.getElementById('mask-' + uid);
    const pass = document.getElementById('pass-' + uid);
    const vis  = pass.style.display === 'inline';
    pass.style.display = vis ? 'none'   : 'inline';
    mask.style.display = vis ? 'inline' : 'none';
}

// ── Formulario de cambio de contraseña ──────────────────
function openPF(uid) {
    document.querySelectorAll('.pass-form.open').forEach(f => {
        if (f.id !== 'pf-' + uid) f.classList.remove('open');
    });
    const pf = document.getElementById('pf-' + uid);
    pf.classList.toggle('open');
    if (pf.classList.contains('open')) pf.querySelector('input[name="new_password"]').focus();
}
function closePF(uid) {
    document.getElementById('pf-' + uid).classList.remove('open');
}

// ── Accordion de docentes ────────────────────────────────
function toggleDocente(id) {
    document.getElementById(id).classList.toggle('open');
}

// ── Rename inline ────────────────────────────────────────
function openRename(slug, currentTitle, fromTrash) {
    document.querySelectorAll('.rename-form.open').forEach(f => f.classList.remove('open'));
    const rf  = document.getElementById('rf-' + slug);
    const inp = document.getElementById('rfi-' + slug);
    if (!rf || !inp) return;
    rf.classList.add('open');
    inp.value = currentTitle;
    inp.focus();
    inp.select();
}
function closeRename(slug) {
    const rf = document.getElementById('rf-' + slug);
    if (rf) rf.classList.remove('open');
}
</script>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
