<?php
/**
 * TRIVIAX - Partidas en vivo del docente.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$pdo = triviax_db();

$stmtSesiones = $pdo->prepare('
    SELECT s.id, s.nombre, s.tipo, s.codigo_acceso, s.estado,
           s.fecha_inicio, s.started_at, s.updated_at, s.max_jugadores,
           p.title AS proyecto_title,
           COUNT(DISTINCT sj.id) AS jugadores_inscritos,
           COUNT(DISTINCT i.id) AS respuestas
    FROM sesiones s
    JOIN proyectos p ON p.id = s.proyecto_id
    LEFT JOIN sesion_jugadores sj ON sj.sesion_id = s.id
    LEFT JOIN intentos i ON i.sesion_id = s.id
    WHERE s.docente_id = ?
      AND s.estado IN (\'activa\', \'active\')
    GROUP BY s.id
    ORDER BY COALESCE(s.updated_at, s.fecha_inicio, s.started_at, s.created_at) DESC, s.id DESC
');
$stmtSesiones->execute([$usuario['id']]);
$sesiones = $stmtSesiones->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partidas en vivo - TRIVIAX</title>
    <link rel="stylesheet" href="<?= TRIVIAX_BASE ?>/css/styles.css?v=5.0.6">
    <style>
        body { overflow: auto; }
        .panel-layout { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 32px; background: rgba(9,13,22,0.85);
            border-bottom: 1px solid var(--border-color); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-logo {
            font-size: 1.5rem; font-weight: 800; background: var(--accent-gradient);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; letter-spacing: 2px;
        }
        .topbar-nav { display: flex; align-items: center; gap: 12px; }
        .topbar-nav a {
            color: var(--text-muted); text-decoration: none; font-size: 0.85rem;
            padding: 6px 12px; border: 1px solid var(--border-color);
            border-radius: 8px; transition: all 0.2s;
        }
        .topbar-nav a:hover { color: var(--text-primary); border-color: var(--accent); }
        .panel-content {
            flex: 1; max-width: 1100px; width: 100%; margin: 0 auto;
            padding: 32px 24px; display: flex; flex-direction: column; gap: 24px;
        }
        .panel-title { font-size: 1.6rem; font-weight: 800; color: var(--text-primary); }
        .panel-subtitle { color: var(--text-secondary); line-height: 1.5; max-width: 760px; }
        .live-list {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px; overflow: hidden;
        }
        .live-row {
            display: grid; grid-template-columns: 1.5fr 1.2fr 110px 110px 120px;
            gap: 14px; align-items: center; padding: 16px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .live-row:last-child { border-bottom: 0; }
        .live-head {
            color: var(--text-muted); font-size: 0.78rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .live-name { color: var(--text-primary); font-weight: 800; }
        .live-project { color: var(--text-secondary); font-size: 0.9rem; }
        .live-code {
            display: inline-block; color: var(--text-primary); font-family: monospace;
            font-weight: 800; letter-spacing: 2px; background: rgba(255,255,255,0.06);
            padding: 4px 9px; border-radius: 6px;
        }
        .btn-live {
            justify-self: end; background: var(--accent-gradient); color: #fff;
            border-radius: 10px; padding: 9px 14px; text-decoration: none;
            font-size: 0.85rem; font-weight: 800;
        }
        .empty-state {
            text-align: center; padding: 48px 24px; color: var(--text-muted);
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px;
        }
        @media (max-width: 820px) {
            .topbar { padding: 12px 16px; }
            .panel-content { padding: 20px 14px; }
            .live-head { display: none; }
            .live-row { grid-template-columns: 1fr; gap: 8px; }
            .btn-live { justify-self: start; }
        }
    </style>
</head>
<body>
<div class="panel-layout">
    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="<?= TRIVIAX_BASE ?>/panel/dashboard.php">Dashboard</a>
            <a href="<?= TRIVIAX_BASE ?>/auth/logout.php">Cerrar sesion</a>
        </div>
    </header>

    <main class="panel-content">
        <div>
            <div class="panel-title">Partidas en vivo</div>
            <div class="panel-subtitle">
                Sesiones activas creadas por ti. Selecciona una partida para ver, en tiempo real,
                que preguntas ya fueron respondidas y como viene el grupo.
            </div>
        </div>

        <?php if (empty($sesiones)): ?>
            <div class="empty-state">
                <strong>No hay partidas activas en este momento.</strong>
                <p>Crea o inicia una sesion y aparecera aca cuando los estudiantes comiencen a jugar.</p>
            </div>
        <?php else: ?>
            <div class="live-list">
                <div class="live-row live-head">
                    <div>Partida</div>
                    <div>Actividad</div>
                    <div>Codigo</div>
                    <div>Equipos</div>
                    <div></div>
                </div>
                <?php foreach ($sesiones as $s): ?>
                    <div class="live-row">
                        <div>
                            <div class="live-name"><?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="live-project"><?= (int)$s['respuestas'] ?> respuestas registradas</div>
                        </div>
                        <div class="live-project"><?= htmlspecialchars($s['proyecto_title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div><span class="live-code"><?= htmlspecialchars($s['codigo_acceso'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="live-project"><?= (int)$s['jugadores_inscritos'] ?> / <?= (int)$s['max_jugadores'] ?></div>
                        <a class="btn-live" href="<?= TRIVIAX_BASE ?>/panel/live_session.php?id=<?= (int)$s['id'] ?>">Ver</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
