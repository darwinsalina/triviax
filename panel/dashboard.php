<?php
/**
 * TRIVIAX v4.0 — Dashboard del docente
 * Lista sus sesiones, permite crear nuevas y acceder a los reportes.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$pdo     = triviax_db();

// ─── Sesiones del docente ───────────────────────────────────────────
$stmtSesiones = $pdo->prepare('
    SELECT s.id, s.nombre, s.tipo, s.codigo_acceso, s.estado,
           s.fecha_inicio, s.max_jugadores,
           p.title AS proyecto_title,
           COUNT(DISTINCT sj.id) AS jugadores_inscritos
    FROM sesiones s
    JOIN proyectos p ON p.id = s.proyecto_id
    LEFT JOIN sesion_jugadores sj ON sj.sesion_id = s.id
    WHERE s.docente_id = ?
    GROUP BY s.id
    ORDER BY s.id DESC
    LIMIT 50
');
$stmtSesiones->execute([$usuario['id']]);
$sesiones = $stmtSesiones->fetchAll();

// ─── Proyectos del docente ──────────────────────────────────────────
$stmtProyectos = $pdo->prepare('
    SELECT id, title, nivel FROM proyectos
    WHERE docente_id = ?
    ORDER BY title ASC
');
$stmtProyectos->execute([$usuario['id']]);
$proyectos = $stmtProyectos->fetchAll();

// ─── Estadísticas rápidas ───────────────────────────────────────────
$stmtStats = $pdo->prepare('
    SELECT
        COUNT(DISTINCT s.id)  AS total_sesiones,
        COUNT(DISTINCT sj.id) AS total_jugadores,
        SUM(r.correctas)      AS total_correctas
    FROM sesiones s
    LEFT JOIN sesion_jugadores sj ON sj.sesion_id = s.id
    LEFT JOIN resultados r ON r.sesion_id = s.id
    WHERE s.docente_id = ?
');
$stmtStats->execute([$usuario['id']]);
$stats = $stmtStats->fetch();

$tipoLabel = ['educativa' => 'Educativa', 'abierta' => 'Abierta'];
$estadoLabel = [
    'pendiente'  => 'Pendiente',
    'activa'     => 'Activa',
    'finalizada' => 'Finalizada',
    'cancelada'  => 'Cancelada'
];
$estadoColor = [
    'pendiente'  => '#f59e0b',
    'activa'     => '#10b981',
    'finalizada' => '#6366f1',
    'cancelada'  => '#6b7280'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel docente — TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }

        .panel-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Top bar ── */
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

        .topbar-usuario {
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .topbar-usuario strong {
            color: var(--text-primary);
        }

        .topbar-usuario a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.2s;
        }

        .topbar-usuario a:hover {
            color: var(--text-primary);
            border-color: var(--accent);
        }

        /* ── Contenido ── */
        .panel-content {
            flex: 1;
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px;
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .panel-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .panel-title span {
            color: var(--text-secondary);
            font-weight: 400;
            font-size: 1rem;
        }

        /* ── Stats rápidas ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px 24px;
            text-align: center;
        }

        .stat-card .stat-num {
            font-size: 2.4rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card .stat-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 4px;
        }

        /* ── Sección ── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .btn-nueva {
            background: var(--accent-gradient);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-family: var(--font-main);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.2s, transform 0.15s;
        }

        .btn-nueva:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* ── Tabla de sesiones ── */
        .sesiones-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sesiones-table th {
            text-align: left;
            padding: 10px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
        }

        .sesiones-table td {
            padding: 14px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .sesiones-table tr:last-child td {
            border-bottom: none;
        }

        .sesiones-table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        .badge-estado {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .badge-tipo {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(99,102,241,0.15);
            color: #a5b4fc;
        }

        .codigo-acceso {
            font-family: monospace;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 3px;
            color: var(--text-primary);
            background: rgba(255,255,255,0.06);
            padding: 3px 10px;
            border-radius: 6px;
        }

        .link-detalle {
            color: #a5b4fc;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: color 0.2s;
        }

        .link-detalle:hover {
            color: var(--text-primary);
        }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
        }

        .empty-state p {
            font-size: 0.95rem;
            margin-top: 8px;
        }

        /* ── Links panel legado ── */
        .panel-legado {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-legado {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px 18px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .btn-legado:hover {
            color: var(--text-primary);
            border-color: rgba(255,255,255,0.15);
        }

        @media (max-width: 700px) {
            .stats-grid { grid-template-columns: 1fr; }
            .topbar { padding: 12px 16px; }
            .panel-content { padding: 20px 14px; }
            .sesiones-table th:nth-child(4),
            .sesiones-table td:nth-child(4) { display: none; }
        }
    </style>
</head>
<body>
<div class="panel-layout">

    <!-- Top bar -->
    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-usuario">
            Hola, <strong><?= htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
            <a href="<?= TRIVIAX_BASE ?>/panel/guia_docente.php">📘 Guía del docente</a>
            <a href="<?= TRIVIAX_BASE ?>/auth/logout.php">Cerrar sesión</a>
        </div>
    </header>

    <main class="panel-content">

        <div>
            <div class="panel-title">
                Panel docente <span>/ <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <!-- Stats rápidas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num"><?= (int)($stats['total_sesiones'] ?? 0) ?></div>
                <div class="stat-label">Sesiones creadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= (int)($stats['total_jugadores'] ?? 0) ?></div>
                <div class="stat-label">Jugadores totales</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= (int)($stats['total_correctas'] ?? 0) ?></div>
                <div class="stat-label">Respuestas correctas</div>
            </div>
        </div>

        <!-- Sesiones -->
        <section>
            <div class="section-header">
                <h2>Mis sesiones de juego</h2>
                <a href="<?= TRIVIAX_BASE ?>/panel/sesion_nueva.php" class="btn-nueva">+ Nueva sesión</a>
            </div>

            <?php if (empty($sesiones)): ?>
                <div class="empty-state">
                    <strong>Todavía no creaste ninguna sesión</strong>
                    <p>Crea tu primera sesión para obtener un código y compartirlo con tus estudiantes.</p>
                </div>
            <?php else: ?>
                <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;overflow:hidden;">
                    <table class="sesiones-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Proyecto</th>
                                <th>Código</th>
                                <th>Tipo</th>
                                <th>Jugadores</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sesiones as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="color:var(--text-secondary);">
                                    <?= htmlspecialchars($s['proyecto_title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="codigo-acceso"><?= htmlspecialchars($s['codigo_acceso'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <span class="badge-tipo">
                                        <?= htmlspecialchars($tipoLabel[$s['tipo']] ?? $s['tipo'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?= (int)$s['jugadores_inscritos'] ?> / <?= (int)$s['max_jugadores'] ?>
                                </td>
                                <td>
                                    <?php
                                        $estado = $s['estado'];
                                        $color  = $estadoColor[$estado] ?? '#6b7280';
                                        $label  = $estadoLabel[$estado] ?? $estado;
                                    ?>
                                    <span class="badge-estado" style="background:<?= $color ?>22;color:<?= $color ?>;">
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= TRIVIAX_BASE ?>/panel/sesion_detalle.php?id=<?= (int)$s['id'] ?>" class="link-detalle">
                                        Ver →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Catálogo docente de modalidades y herramientas -->
        <section>
            <div class="section-header">
                <h2>Catálogo de actividades</h2>
            </div>
            <div class="panel-legado">
                <a href="<?= TRIVIAX_BASE ?>/index.html" class="btn-legado">🎮 Ir al juego</a>
                <a href="<?= TRIVIAX_BASE ?>/panel/live_sessions.php" class="btn-legado">Ver partidas en vivo</a>
                <a href="<?= TRIVIAX_BASE ?>/panel/study_answer.php" class="btn-legado">Estudia y responde</a>
                <a href="<?= TRIVIAX_BASE ?>/panel/lotto.php" class="btn-legado">TRIVIAX Lotto</a>
                <a href="<?= TRIVIAX_BASE ?>/panel/jigsaw.php" class="btn-legado">🧩 Puzle</a>
                <a href="<?= TRIVIAX_BASE ?>/panel/etiquetar.php" class="btn-legado">🏷️ Etiquetar</a>
                <a href="<?= TRIVIAX_BASE ?>/admin.php" class="btn-legado">⚙️ Panel de Actividades</a>
                <a href="<?= TRIVIAX_BASE ?>/estadisticas.php" class="btn-legado">📊 Estadísticas</a>
            </div>
        </section>

    </main>
</div>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
