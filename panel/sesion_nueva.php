<?php
/**
 * TRIVIAX v4.0 — Crear nueva sesión de juego
 * El docente elige el proyecto, el tipo de sesión y el máximo de jugadores.
 * El sistema genera automáticamente un código de acceso único de 6 caracteres.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$pdo     = triviax_db();

// ─── Proyectos disponibles ──────────────────────────────────────────
// Proyectos propios del docente + proyectos sin dueño (del sistema/legado)
$stmtP = $pdo->prepare('
    SELECT id, title, nivel FROM proyectos
    WHERE docente_id = ? OR docente_id IS NULL
    ORDER BY title ASC
');
$stmtP->execute([$usuario['id']]);
$proyectos = $stmtP->fetchAll();

$error   = '';
$success = '';

// ─── Procesar formulario ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();

    $nombre       = trim($_POST['nombre']        ?? '');
    $proyectoId   = trim($_POST['proyecto_id']   ?? '');
    $tipo         = trim($_POST['tipo']          ?? 'educativa');
    $maxJugadores = (int)($_POST['max_jugadores'] ?? 4);

    // Validaciones
    if ($nombre === '') {
        $error = 'El nombre de la sesión es obligatorio.';
    } elseif ($proyectoId === '') {
        $error = 'Selecciona un proyecto.';
    } elseif (!in_array($tipo, ['educativa', 'abierta'], true)) {
        $error = 'Tipo de sesión no válido.';
    } elseif ($maxJugadores < 2 || $maxJugadores > 8) {
        $error = 'El máximo de jugadores debe ser entre 2 y 8.';
    } else {
        // Verificar que el proyecto existe
        $stmtVerP = $pdo->prepare('SELECT id FROM proyectos WHERE id = ?');
        $stmtVerP->execute([$proyectoId]);
        if (!$stmtVerP->fetch()) {
            $error = 'El proyecto seleccionado no existe.';
        }
    }

    if ($error === '') {
        // Generar código único de 6 caracteres alfanuméricos en mayúsculas
        $codigo = '';
        $intentos = 0;
        $alfabetoCodigo = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $alfabetoCodigo[random_int(0, strlen($alfabetoCodigo) - 1)];
            }
            $stmtCheck = $pdo->prepare('SELECT id FROM sesiones WHERE codigo_acceso = ?');
            $stmtCheck->execute([$codigo]);
            $intentos++;
        } while ($stmtCheck->fetch() && $intentos < 20);

        if ($intentos >= 20) {
            $error = 'No se pudo generar un código único. Intenta de nuevo.';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO sesiones (proyecto_id, docente_id, nombre, tipo, codigo_acceso, estado, max_jugadores)
                VALUES (?, ?, ?, ?, ?, \'pendiente\', ?)
            ');
            $stmt->execute([$proyectoId, $usuario['id'], $nombre, $tipo, $codigo, $maxJugadores]);
            $nuevaId = (int) $pdo->lastInsertId();

            header('Location: /triviax/panel/sesion_detalle.php?id=' . $nuevaId . '&nueva=1');
            exit;
        }
    }
}

$csrfToken = triviax_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva sesión — TRIVIAX</title>
    <link rel="stylesheet" href="/triviax/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }

        .panel-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 32px;
            background: rgba(9,13,22,0.85);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-logo {
            font-size: 1.5rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
        }

        .topbar-nav {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.2s;
        }

        .topbar-nav a:hover {
            color: var(--text-primary);
            border-color: var(--accent);
        }

        .panel-content {
            flex: 1;
            max-width: 680px;
            width: 100%;
            margin: 0 auto;
            padding: 40px 24px;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        .panel-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .form-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-group input,
        .form-group select {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-family: var(--font-main);
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
        }

        .form-group select option {
            background: #1e293b;
            color: var(--text-primary);
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .form-group .hint {
            color: var(--text-muted);
            font-size: 0.82rem;
            line-height: 1.5;
        }

        .tipo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tipo-opcion {
            position: relative;
        }

        .tipo-opcion input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .tipo-opcion label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 16px;
            background: rgba(255,255,255,0.04);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: none;
            letter-spacing: 0;
        }

        .tipo-opcion input:checked + label {
            border-color: var(--accent);
            background: rgba(99,102,241,0.1);
        }

        .tipo-opcion label:hover {
            border-color: rgba(99,102,241,0.4);
        }

        .tipo-opcion .tipo-nombre {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .tipo-opcion .tipo-desc {
            font-size: 0.82rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        .form-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            border-radius: 10px;
            padding: 10px 14px;
            color: #fca5a5;
            font-size: 0.9rem;
        }

        .btn-crear {
            background: var(--accent-gradient);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-family: var(--font-main);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
        }

        .btn-crear:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-volver {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
            color: var(--text-secondary);
            font-family: var(--font-main);
            font-size: 0.9rem;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: block;
            transition: all 0.2s;
        }

        .btn-volver:hover {
            color: var(--text-primary);
            border-color: rgba(255,255,255,0.15);
        }
    </style>
</head>
<body>
<div class="panel-layout">

    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="/triviax/panel/dashboard.php">← Dashboard</a>
            <a href="/triviax/auth/logout.php">Cerrar sesión</a>
        </div>
    </header>

    <main class="panel-content">

        <div class="panel-title">Nueva sesión de juego</div>

        <div class="form-card">

            <?php if ($error !== ''): ?>
                <div class="form-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (empty($proyectos)): ?>
                <div style="text-align:center;color:var(--text-muted);padding:20px 0;">
                    <strong>No hay proyectos disponibles.</strong><br>
                    <span style="font-size:0.9rem;">Crea un proyecto en el panel de actividades o sube preguntas primero.</span><br><br>
                    <a href="/triviax/admin.php" style="color:#a5b4fc;text-decoration:none;font-weight:600;">Ir al panel de actividades →</a>
                </div>
            <?php else: ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div style="display:flex;flex-direction:column;gap:22px;">

                    <div class="form-group">
                        <label for="nombre">Nombre de la sesión</label>
                        <input type="text" id="nombre" name="nombre"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ej: Informática 7mo — Repaso Unidad 3"
                               required autofocus>
                        <span class="hint">Este nombre verán los estudiantes al unirse.</span>
                    </div>

                    <div class="form-group">
                        <label for="proyecto_id">Proyecto / Actividad</label>
                        <select id="proyecto_id" name="proyecto_id" required>
                            <option value="">— Selecciona un proyecto —</option>
                            <?php foreach ($proyectos as $p): ?>
                                <option value="<?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= ($_POST['proyecto_id'] ?? '') === $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                                    <?= $p['nivel'] ? '— ' . htmlspecialchars($p['nivel'], ENT_QUOTES, 'UTF-8') : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tipo de sesión</label>
                        <div class="tipo-grid">
                            <div class="tipo-opcion">
                                <input type="radio" id="tipo_educativa" name="tipo" value="educativa"
                                    <?= ($_POST['tipo'] ?? 'educativa') === 'educativa' ? 'checked' : '' ?>>
                                <label for="tipo_educativa">
                                    <span class="tipo-nombre">🎓 Educativa</span>
                                    <span class="tipo-desc">Solo estudiantes con el código que tú compartas.</span>
                                </label>
                            </div>
                            <div class="tipo-opcion">
                                <input type="radio" id="tipo_abierta" name="tipo" value="abierta"
                                    <?= ($_POST['tipo'] ?? '') === 'abierta' ? 'checked' : '' ?>>
                                <label for="tipo_abierta">
                                    <span class="tipo-nombre">🌐 Abierta</span>
                                    <span class="tipo-desc">Cualquier usuario puede unirse (torneos, juegos recreativos).</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="max_jugadores">Máximo de jugadores</label>
                        <select id="max_jugadores" name="max_jugadores">
                            <?php foreach ([2,3,4,5,6,7,8] as $n): ?>
                                <option value="<?= $n ?>"
                                    <?= ((int)($_POST['max_jugadores'] ?? 4)) === $n ? 'selected' : '' ?>>
                                    <?= $n ?> jugadores
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-crear">Crear sesión y obtener código</button>
                </div>
            </form>

            <?php endif; ?>

        </div>

        <a href="/triviax/panel/dashboard.php" class="btn-volver">← Volver al panel</a>

    </main>
</div>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
