<?php
/**
 * TRIVIAX v4.0 — Detalle de sesión
 * Muestra el código de acceso, los jugadores inscritos,
 * el ranking final y los intentos registrados.
 */

require_once __DIR__ . '/../php/auth.php';
triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$pdo     = triviax_db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /triviax/panel/dashboard.php');
    exit;
}

require_once __DIR__ . '/../php/triviax_core.php';

// Handler for AJAX real-time updates
if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_stats') {
    header('Content-Type: application/json');
    $stmtPlayers = $pdo->prepare('
        SELECT sj.nombre_display 
        FROM sesion_jugadores sj 
        WHERE sj.sesion_id = ? 
        ORDER BY sj.id ASC
    ');
    $stmtPlayers->execute([$id]);
    $playersList = $stmtPlayers->fetchAll(PDO::FETCH_COLUMN);
    
    $stmtAttempts = $pdo->prepare('
        SELECT i.nombre_jugador, i.challenge_key, i.resultado 
        FROM intentos i 
        WHERE i.sesion_id = ? 
        ORDER BY i.id ASC
    ');
    $stmtAttempts->execute([$id]);
    $attemptsList = $stmtAttempts->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'players' => $playersList,
        'attempts' => $attemptsList
    ]);
    exit;
}

function get_session_questions($proyectoId) {
    $baseProjectsDir = __DIR__ . '/../proyectos';
    $projectPath = $baseProjectsDir . '/' . $proyectoId;
    $questions = [];

    if (file_exists($projectPath . '/proyecto.json')) {
        $jsonContent = file_get_contents($projectPath . '/proyecto.json');
        if ($jsonContent !== false) {
            try {
                $parsedJsonProject = triviax_parse_project_json($jsonContent);
                $questions = $parsedJsonProject['challenges'] ?? $parsedJsonProject['questions'] ?? [];
            } catch (Exception $e) {
                // Ignore
            }
        }
    } elseif (file_exists($projectPath . '/preguntas.txt')) {
        $content = file_get_contents($projectPath . '/preguntas.txt');
        if ($content !== false) {
            try {
                $res = triviax_parse_questions_text($content, false);
                $questions = $res['questions'] ?? [];
            } catch (Exception $e) {
                // Ignore
            }
        }
    }
    
    $normalized = [];
    foreach ($questions as $q) {
        $qId = $q['id'] ?? '';
        $text = '';
        if (isset($q['prompt']['text'])) {
            $text = $q['prompt']['text'];
        } elseif (isset($q['text'])) {
            $text = $q['text'];
        } elseif (isset($q['question'])) {
            $text = $q['question'];
        }
        $normalized[] = [
            'id' => (string)$qId,
            'text' => $text
        ];
    }
    return $normalized;
}

// Cargar sesión (solo la del docente autenticado)
$stmtS = $pdo->prepare('
    SELECT s.*, p.title AS proyecto_title, p.nivel AS proyecto_nivel
    FROM sesiones s
    JOIN proyectos p ON p.id = s.proyecto_id
    WHERE s.id = ? AND s.docente_id = ?
');
$stmtS->execute([$id, $usuario['id']]);
$sesion = $stmtS->fetch();

if (!$sesion) {
    header('Location: /triviax/panel/dashboard.php');
    exit;
}

// Jugadores inscritos
$stmtJ = $pdo->prepare('
    SELECT sj.id, sj.nombre_display, sj.joined_at,
           u.email AS email_usuario,
           r.puntaje, r.correctas, r.incorrectas, r.posicion
    FROM sesion_jugadores sj
    LEFT JOIN usuarios u ON u.id = sj.usuario_id
    LEFT JOIN resultados r ON r.jugador_id = sj.id
    WHERE sj.sesion_id = ?
    ORDER BY r.posicion ASC, sj.joined_at ASC
');
$stmtJ->execute([$id]);
$jugadores = $stmtJ->fetchAll();

// Intentos (últimos 100)
$stmtI = $pdo->prepare('
    SELECT i.nombre_jugador, i.prompt_text, i.challenge_type,
           i.resultado, i.orden_turno
    FROM intentos i
    WHERE i.sesion_id = ?
    ORDER BY i.orden_turno ASC
    LIMIT 100
');
$stmtI->execute([$id]);
$intentos = $stmtI->fetchAll();

$questionsList = get_session_questions($sesion['proyecto_id']);

// ── Enlace para compartir con los estudiantes ───────────────────────
// El código real (6 caracteres) viaja incrustado en la posición 13 (índice 12)
// de una cadena envuelta en relleno aleatorio, solo para no exhibirlo en crudo.
// NO es una medida de seguridad: la protección real es que la sesión esté activa.
$triviax_relleno = static function (int $n): string {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= $abc[random_int(0, 61)]; }
    return $s;
};
$shareToken = $triviax_relleno(12) . $sesion['codigo_acceso'] . $triviax_relleno(8);
$scheme   = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Base de la app derivada de la ruta del propio script: /triviax/panel/… → /triviax
$appBase  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/triviax/panel/x'))), '/');
$shareUrl = $scheme . '://' . $host . $appBase . '/?' . $shareToken;

// Acciones rápidas sobre el estado
$csrfToken = triviax_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    triviax_verificar_csrf();
    $accion = $_POST['accion'] ?? '';

    $estadosValidos = ['activar' => 'activa', 'finalizar' => 'finalizada', 'cancelar' => 'cancelada'];
    if (isset($estadosValidos[$accion])) {
        $nuevoEstado = $estadosValidos[$accion];
        $campo = $nuevoEstado === 'activa' ? ', fecha_inicio = NOW()' : ($nuevoEstado === 'finalizada' ? ', fecha_fin = NOW()' : '');
        $stmtU = $pdo->prepare("UPDATE sesiones SET estado = ? {$campo} WHERE id = ? AND docente_id = ?");
        $stmtU->execute([$nuevoEstado, $id, $usuario['id']]);
        header('Location: /triviax/panel/sesion_detalle.php?id=' . $id);
        exit;
    }
}

$esNueva = isset($_GET['nueva']);

$estadoColor = [
    'pendiente'  => '#f59e0b',
    'activa'     => '#10b981',
    'finalizada' => '#6366f1',
    'cancelada'  => '#6b7280'
];
$estadoLabel = [
    'pendiente'  => 'Pendiente',
    'activa'     => 'Activa',
    'finalizada' => 'Finalizada',
    'cancelada'  => 'Cancelada'
];
$resultadoColor = [
    'correct'   => '#10b981',
    'incorrect' => '#ef4444',
    'timeout'   => '#f59e0b'
];
$resultadoLabel = [
    'correct'   => 'Correcto',
    'incorrect' => 'Incorrecto',
    'timeout'   => 'Tiempo agotado'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($sesion['nombre'], ENT_QUOTES, 'UTF-8') ?> — TRIVIAX</title>
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

        .topbar-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.2s;
            margin-left: 8px;
        }

        .topbar-nav a:hover {
            color: var(--text-primary);
            border-color: var(--accent);
        }

        .panel-content {
            max-width: 960px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        /* ── Código destacado ── */
        .codigo-hero {
            background: linear-gradient(135deg, rgba(99,102,241,0.15) 0%, rgba(168,85,247,0.15) 100%);
            border: 1px solid rgba(99,102,241,0.3);
            border-radius: 16px;
            padding: 28px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .codigo-info h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        .codigo-display {
            font-family: monospace;
            font-size: 3rem;
            font-weight: 900;
            letter-spacing: 10px;
            color: var(--text-primary);
            background: rgba(255,255,255,0.06);
            padding: 10px 24px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: background 0.2s;
            user-select: all;
        }

        .codigo-display:hover {
            background: rgba(255,255,255,0.1);
        }

        .codigo-hint {
            color: var(--text-muted);
            font-size: 0.82rem;
            margin-top: 6px;
        }

        .codigo-estado {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .badge-estado-grande {
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .acciones-estado {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn-accion {
            padding: 8px 16px;
            border-radius: 8px;
            font-family: var(--font-main);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity 0.2s;
        }

        .btn-accion:hover { opacity: 0.85; }

        .card-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
        }

        .card-section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-section-header span {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.data-table th {
            text-align: left;
            padding: 10px 16px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
        }

        table.data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:hover td { background: rgba(255,255,255,0.02); }

        .badge-resultado {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .posicion-medal {
            font-size: 1.2rem;
        }

        .nuevo-banner {
            background: linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(5,150,105,0.1) 100%);
            border: 1px solid rgba(16,185,129,0.3);
            border-radius: 12px;
            padding: 14px 20px;
            color: #6ee7b7;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .empty-list {
            padding: 28px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="panel-layout">

    <header class="topbar">
        <div class="topbar-logo">TRIVIAX</div>
        <div class="topbar-nav">
            <a href="/triviax/panel/dashboard.php">← Panel</a>
            <a href="/triviax/auth/logout.php">Cerrar sesión</a>
        </div>
    </header>

    <main class="panel-content">

        <?php if ($esNueva): ?>
            <div class="nuevo-banner">
                ✅ Sesión creada correctamente. Comparte el código con tus estudiantes para que puedan unirse.
            </div>
        <?php endif; ?>

        <div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary);">
                <?= htmlspecialchars($sesion['nombre'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="color:var(--text-secondary);font-size:0.9rem;margin-top:4px;">
                <?= htmlspecialchars($sesion['proyecto_title'], ENT_QUOTES, 'UTF-8') ?>
                <?= $sesion['proyecto_nivel'] ? '— ' . htmlspecialchars($sesion['proyecto_nivel'], ENT_QUOTES, 'UTF-8') : '' ?>
            </div>
        </div>

        <!-- Código de acceso -->
        <div class="codigo-hero">
            <div class="codigo-info">
                <h2>Código de acceso</h2>
                <div class="codigo-display" title="Clic para copiar" onclick="copiarCodigo(this)">
                    <?= htmlspecialchars($sesion['codigo_acceso'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="codigo-hint">Los estudiantes ingresan este código al unirse a la sesión.</div>
            </div>

            <div class="codigo-estado">
                <?php
                    $estado = $sesion['estado'];
                    $color  = $estadoColor[$estado] ?? '#6b7280';
                    $label  = $estadoLabel[$estado] ?? $estado;
                ?>
                <span class="badge-estado-grande" style="background:<?= $color ?>22;color:<?= $color ?>;">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </span>

                <div class="acciones-estado">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($estado === 'pendiente'): ?>
                            <button type="submit" name="accion" value="activar"
                                    class="btn-accion" style="background:#10b981;color:#fff;">
                                ▶ Iniciar sesión
                            </button>
                        <?php endif; ?>
                        <?php if ($estado === 'activa'): ?>
                            <button type="submit" name="accion" value="finalizar"
                                    class="btn-accion" style="background:#6366f1;color:#fff;">
                                ■ Finalizar
                            </button>
                        <?php endif; ?>
                        <?php if (in_array($estado, ['pendiente', 'activa'])): ?>
                            <button type="submit" name="accion" value="cancelar"
                                    class="btn-accion" style="background:rgba(239,68,68,0.15);color:#fca5a5;border:1px solid rgba(239,68,68,0.3);"
                                    onclick="return confirm('¿Cancelar esta sesión?')">
                                Cancelar
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <div style="color:var(--text-muted);font-size:0.82rem;text-align:right;">
                    Máx. <?= (int)$sesion['max_jugadores'] ?> jugadores
                    · <?= $sesion['tipo'] === 'abierta' ? 'Abierta' : 'Educativa' ?>
                </div>
            </div>
        </div>

        <!-- Enlace para compartir -->
        <div class="card-section" style="padding:20px;">
            <div style="font-size:0.85rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">
                🔗 Enlace para compartir
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="text" id="share-link-input" readonly
                       value="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>"
                       onclick="this.select()"
                       style="flex:1;min-width:240px;background:rgba(255,255,255,0.05);border:1px solid var(--border-color);border-radius:10px;padding:11px 14px;color:var(--text-primary);font-family:monospace;font-size:0.9rem;outline:none;">
                <button type="button" class="btn-accion" style="background:var(--accent-gradient);color:#fff;" onclick="copiarEnlace(this)">📋 Copiar enlace</button>
            </div>
            <div class="codigo-hint" style="margin-top:10px;">
                Pega este enlace en Google Classroom. Al abrirlo, el estudiante entra directo a esta partida.
                El código (<strong><?= htmlspecialchars($sesion['codigo_acceso'], ENT_QUOTES, 'UTF-8') ?></strong>)
                queda incrustado en el enlace; si alguien no puede abrirlo, alcanza con que ingrese ese código a mano.
            </div>
        </div>

        <!-- Planilla en Tiempo Real -->
        <div class="card-section" id="planilla-section">
            <div class="card-section-header">
                📊 Planilla de respuestas en vivo
                <span id="planilla-status-badge" style="font-size:0.8rem; color:#10b981; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:8px; height:8px; background-color:#10b981; border-radius:50%; animation: pulse 1.5s infinite;"></span>
                    Sincronizando...
                </span>
            </div>
            <div style="padding: 20px; overflow-x: auto;">
                <div class="grid-stats-summary" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 10px; display: flex; flex-direction: column; gap: 4px; min-width: 140px;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Respuestas Correctas</span>
                        <strong id="live-total-correct" style="font-size: 1.4rem; color: #10b981;">0</strong>
                    </div>
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 10px; display: flex; flex-direction: column; gap: 4px; min-width: 140px;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Respuestas Incorrectas</span>
                        <strong id="live-total-incorrect" style="font-size: 1.4rem; color: #ef4444;">0</strong>
                    </div>
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 10px; display: flex; flex-direction: column; gap: 4px; min-width: 140px;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700;">Tiempos Agotados</span>
                        <strong id="live-total-timeout" style="font-size: 1.4rem; color: #f59e0b;">0</strong>
                    </div>
                </div>

                <div id="planilla-grid-container" style="display: block; width: 100%;">
                    <div style="text-align: center; color: var(--text-muted); padding: 20px;">Cargando planilla de respuestas...</div>
                </div>
            </div>
        </div>

        <!-- Jugadores -->
        <div class="card-section">
            <div class="card-section-header">
                Jugadores inscritos
                <span><?= count($jugadores) ?> / <?= (int)$sesion['max_jugadores'] ?></span>
            </div>
            <?php if (empty($jugadores)): ?>
                <div class="empty-list">Todavía no se unió ningún jugador.</div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Pos.</th>
                            <th>Jugador</th>
                            <th>Cuenta</th>
                            <th>Puntaje</th>
                            <th>Correctas</th>
                            <th>Incorrectas</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jugadores as $j): ?>
                        <tr>
                            <td>
                                <?php if ($j['posicion'] === null): ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php elseif ($j['posicion'] == 1): ?>
                                    <span class="posicion-medal">🥇</span>
                                <?php elseif ($j['posicion'] == 2): ?>
                                    <span class="posicion-medal">🥈</span>
                                <?php elseif ($j['posicion'] == 3): ?>
                                    <span class="posicion-medal">🥉</span>
                                <?php else: ?>
                                    <?= (int)$j['posicion'] ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($j['nombre_display'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td style="color:var(--text-secondary);font-size:0.85rem;">
                                <?= $j['email_usuario']
                                    ? htmlspecialchars($j['email_usuario'], ENT_QUOTES, 'UTF-8')
                                    : '<em style="color:var(--text-muted);">Invitado</em>' ?>
                            </td>
                            <td><strong><?= $j['puntaje'] !== null ? (int)$j['puntaje'] : '—' ?></strong></td>
                            <td style="color:#10b981;"><?= $j['correctas'] !== null ? (int)$j['correctas'] : '—' ?></td>
                            <td style="color:#ef4444;"><?= $j['incorrectas'] !== null ? (int)$j['incorrectas'] : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Intentos -->
        <?php if (!empty($intentos)): ?>
        <div class="card-section">
            <div class="card-section-header">
                Historial de respuestas
                <span>Últimos <?= count($intentos) ?></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Jugador</th>
                        <th>Pregunta</th>
                        <th>Tipo</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($intentos as $i): ?>
                    <tr>
                        <td style="color:var(--text-muted);"><?= (int)$i['orden_turno'] ?></td>
                        <td><?= htmlspecialchars($i['nombre_jugador'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="color:var(--text-secondary);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= htmlspecialchars(mb_substr($i['prompt_text'], 0, 80), ENT_QUOTES, 'UTF-8') ?>
                            <?= mb_strlen($i['prompt_text']) > 80 ? '…' : '' ?>
                        </td>
                        <td style="color:var(--text-muted);font-size:0.82rem;">
                            <?= htmlspecialchars($i['challenge_type'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php
                                $r = $i['resultado'];
                                $rc = $resultadoColor[$r] ?? '#6b7280';
                                $rl = $resultadoLabel[$r] ?? $r;
                            ?>
                            <span class="badge-resultado" style="background:<?= $rc ?>22;color:<?= $rc ?>;">
                                <?= htmlspecialchars($rl, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
function copiarCodigo(el) {
    const texto = el.textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(texto).then(() => {
            const orig = el.textContent;
            el.textContent = '¡Copiado!';
            setTimeout(() => { el.textContent = orig; }, 1500);
        });
    }
}

function copiarEnlace(btn) {
    const input = document.getElementById('share-link-input');
    if (!input) return;
    input.select();
    const done = () => {
        const orig = btn.textContent;
        btn.textContent = '✔ ¡Copiado!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(input.value).then(done, () => { document.execCommand('copy'); done(); });
    } else {
        document.execCommand('copy');
        done();
    }
}

// Planilla en vivo
const sessionQuestions = <?= json_encode($questionsList, JSON_UNESCAPED_UNICODE) ?>;
const sessionId = <?= $id ?>;

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function renderPlanilla(players, attempts) {
    const container = document.getElementById('planilla-grid-container');
    if (!container) return;

    if (players.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 20px;">Esperando que se unan jugadores para mostrar la planilla...</div>';
        return;
    }

    let html = `<table class="data-table" style="text-align: center;">`;
    
    // Header
    html += `<thead><tr>`;
    html += `<th style="text-align: left; min-width: 120px;">Jugador</th>`;
    sessionQuestions.forEach((q, idx) => {
        html += `<th title="${escapeHtml(q.text)}" style="text-align: center; cursor: help; position: relative;">`;
        html += `P${idx + 1}`;
        html += `</th>`;
    });
    html += `<th style="text-align: center; min-width: 80px;">Correctas</th>`;
    html += `<th style="text-align: center; min-width: 80px;">Incorrectas</th>`;
    html += `</tr></thead>`;

    // Body
    html += `<tbody>`;
    
    let totalCorrect = 0;
    let totalIncorrect = 0;
    let totalTimeout = 0;

    players.forEach(playerName => {
        let playerCorrect = 0;
        let playerIncorrect = 0;
        
        html += `<tr>`;
        html += `<td style="text-align: left;"><strong>${escapeHtml(playerName)}</strong></td>`;
        
        sessionQuestions.forEach(q => {
            const attempt = attempts.find(a => a.nombre_jugador === playerName && String(a.challenge_key) === String(q.id));
            
            if (attempt) {
                const res = attempt.resultado;
                if (res === 'correct') {
                    playerCorrect++;
                    totalCorrect++;
                    html += `<td style="background: rgba(16,185,129,0.15); color: #10b981; font-weight: 700; text-align: center;" title="Correcto">✓</td>`;
                } else if (res === 'timeout') {
                    playerIncorrect++;
                    totalTimeout++;
                    html += `<td style="background: rgba(245,158,11,0.15); color: #f59e0b; font-weight: 700; text-align: center;" title="Tiempo Agotado">🕒</td>`;
                } else {
                    playerIncorrect++;
                    totalIncorrect++;
                    html += `<td style="background: rgba(239,68,68,0.15); color: #ef4444; font-weight: 700; text-align: center;" title="Incorrecto">✗</td>`;
                }
            } else {
                html += `<td style="background: rgba(255,255,255,0.01); color: var(--text-muted); text-align: center;">—</td>`;
            }
        });
        
        html += `<td style="color: #10b981; font-weight: 700; text-align: center;">${playerCorrect}</td>`;
        html += `<td style="color: #ef4444; font-weight: 700; text-align: center;">${playerIncorrect}</td>`;
        html += `</tr>`;
    });

    html += `</tbody></table>`;
    container.innerHTML = html;

    document.getElementById('live-total-correct').innerText = totalCorrect;
    document.getElementById('live-total-incorrect').innerText = totalIncorrect;
    document.getElementById('live-total-timeout').innerText = totalTimeout;
}

async function fetchLiveStats() {
    try {
        const response = await fetch(`sesion_detalle.php?id=${sessionId}&ajax=live_stats`);
        if (!response.ok) throw new Error('Network error');
        const data = await response.json();
        if (data.success) {
            renderPlanilla(data.players, data.attempts);
            
            const badge = document.getElementById('planilla-status-badge');
            if (badge) {
                badge.innerHTML = `
                    <span style="display:inline-block; width:8px; height:8px; background-color:#10b981; border-radius:50%; animation: pulse 1.5s infinite;"></span>
                    Sincronizando...
                `;
            }
        }
    } catch (e) {
        console.warn('Error fetching live stats:', e);
        const badge = document.getElementById('planilla-status-badge');
        if (badge) {
            badge.innerHTML = '<span style="color:#ef4444;">⚠️ Desconectado</span>';
        }
    }
}

// Add animation keyframes style
const styleSheet = document.createElement("style");
styleSheet.textContent = `
@keyframes pulse {
    0% { opacity: 0.4; }
    50% { opacity: 1; }
    100% { opacity: 0.4; }
}
`;
document.head.appendChild(styleSheet);

// Start polling every 3 seconds
setInterval(fetchLiveStats, 3000);
fetchLiveStats();
</script>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
