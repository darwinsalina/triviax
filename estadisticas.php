<?php
/**
 * TRIVIAX - Panel de Estadísticas para Docentes
 */

// Reporte de errores: nunca se muestran en producción (evita fuga de rutas,
// credenciales y trazas). Solo se activan en pantalla bajo APP_DEBUG (ver más abajo).
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Cabeceras de seguridad
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/php/triviax_core.php';
require_once __DIR__ . '/php/auth.php';

// Solo en entornos de desarrollo se muestran los errores en pantalla.
if (triviax_env_bool('APP_DEBUG', false)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

if (empty($_SESSION['stats_csrf_token'])) {
    $_SESSION['stats_csrf_token'] = bin2hex(random_bytes(32));
}
$statsCsrfToken = $_SESSION['stats_csrf_token'];

/**
 * Valida confinamiento de ruta (Previene Path Traversal)
 */
function isSafePath($path) {
    return triviax_is_safe_project_path($path);
    $baseDir = realpath(__DIR__ . '/proyectos');
    if ($baseDir === false) {
        return false;
    }
    $realPath = realpath($path);
    if ($realPath === false) {
        $parentDir = realpath(dirname($path));
        return $parentDir !== false && strpos($parentDir, $baseDir) === 0;
    }
    return strpos($realPath, $baseDir) === 0;
}

$projectsDir = __DIR__ . '/proyectos';
$projects = [];

// 1. Escanear proyectos
if (is_dir($projectsDir)) {
    $files = scandir($projectsDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $projectsDir . '/' . $file;
        if (is_dir($path) && (file_exists($path . '/preguntas.txt') || file_exists($path . '/proyecto.json'))) {
            $projects[] = $file;
        }
    }
}

// 2. Determinar proyecto activo
$activeProject = '';
if (isset($_GET['project']) && in_array($_GET['project'], $projects)) {
    $activeProject = $_GET['project'];
} elseif (!empty($projects)) {
    // Intentar seleccionar informatica101 por defecto, o el primero disponible
    if (in_array('informatica101', $projects)) {
        $activeProject = 'informatica101';
    } else {
        $activeProject = $projects[0];
    }
}
// 3. Procesar acción de reinicio o eliminación de reporte si se solicita
if (isset($_GET['reset']) && $_GET['reset'] === '1' && !empty($activeProject) && hash_equals($statsCsrfToken, $_GET['token'] ?? '')) {
    $statsFile = __DIR__ . "/proyectos/{$activeProject}/stats.json";
    if (file_exists($statsFile) && isSafePath($statsFile)) {
        @unlink($statsFile);
    }
    header("Location: estadisticas.php?project=" . urlencode($activeProject));
    exit;
}

if (isset($_GET['delete_report']) && !empty($activeProject) && hash_equals($statsCsrfToken, $_GET['token'] ?? '')) {
    $reportToDelete = $_GET['delete_report'];
    // Validar nombre para evitar path traversal
    if (preg_match('/^reporte_[a-zA-Z0-9_-]+\.json$/', $reportToDelete)) {
        $reportFile = __DIR__ . "/proyectos/{$activeProject}/reportes/{$reportToDelete}";
        if (file_exists($reportFile) && isSafePath($reportFile)) {
            @unlink($reportFile);
        }
    }
    header("Location: estadisticas.php?project=" . urlencode($activeProject));
    exit;
}

// 4. Cargar y parsear datos de la actividad activa
$metadata = [
    'title' => 'TRIVIAX',
    'author' => 'No especificado',
    'nivel' => 'General',
    'obs' => 'Responde correctamente y avanza.',
    'date' => 'Desconocida',
    'mail' => '',
    'id' => ''
];
$questions = [];
$stats = [];
$parsingError = '';

if (!empty($activeProject)) {
    $projectPath = $projectsDir . '/' . $activeProject;
    $questionsFile = $projectPath . '/preguntas.txt';
    $jsonProjectFile = $projectPath . '/proyecto.json';
    $statsFile = $projectPath . '/stats.json';

    // Parsear preguntas
    if (file_exists($jsonProjectFile)) {
        try {
            $parsedProject = triviax_parse_project_json(file_get_contents($jsonProjectFile));
            $metadata = array_merge($metadata, $parsedProject['metadata']);
            $questions = $parsedProject['challenges'];
        } catch (Exception $e) {
            $parsingError = $e->getMessage();
        }
    } elseif (file_exists($questionsFile)) {
        try {
            $parsedProject = triviax_parse_questions_text(file_get_contents($questionsFile));
            $metadata = array_merge($metadata, $parsedProject['metadata']);
            $questions = $parsedProject['questions'];
        } catch (Exception $e) {
            $parsingError = $e->getMessage();
        }

        if (false) {
        $content = file_get_contents($questionsFile);
        
        // Quitar BOM UTF-8 si está presente
        if (substr($content, 0, 3) === pack("CCC", 0xef, 0xbb, 0xbf)) {
            $content = substr($content, 3);
        }
        
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $parsingQuestionsStarted = false;
        $currentQuestion = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            if ($trimmed === '') {
                continue;
            }
            
            // Ignorar comentarios o bloques
            if (preg_match('/^#+\s*/', $trimmed)) {
                continue;
            }
            
            // Metadatos
            if (!$parsingQuestionsStarted) {
                if (preg_match('/^(title|author|nivel|obs|date|mail|id)\s*:\s*(.*)$/i', $trimmed, $matches)) {
                    $key = strtolower($matches[1]);
                    $metadata[$key] = trim($matches[2]);
                    continue;
                }
            }
            
            // Pregunta
            if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed, $matches)) {
                $parsingQuestionsStarted = true;
                if ($currentQuestion !== null) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = [
                    'id' => (int)$matches[1],
                    'text' => trim($matches[2]),
                    'answers' => []
                ];
                continue;
            }
            
            // Respuestas
            if (strpos($trimmed, '@') === 0) {
                if ($currentQuestion !== null) {
                    $ansPart = trim(substr($trimmed, 1));
                    $isCorrect = false;
                    
                    if (strpos($ansPart, '*') === 0) {
                        $isCorrect = true;
                        $ansPart = trim(substr($ansPart, 1));
                    }
                    
                    $currentQuestion['answers'][] = [
                        'text' => $ansPart,
                        'correct' => $isCorrect
                    ];
                }
            }
        }
        if ($currentQuestion !== null) {
            $questions[] = $currentQuestion;
        }
        }
    } else {
        $parsingError = 'No se encontro el archivo de preguntas o proyecto.json para esta actividad.';
    }

    // Cargar estadísticas
    if (file_exists($statsFile)) {
        $statsContent = @file_get_contents($statsFile);
        $stats = json_decode($statsContent, true) ?: [];
    }
}

// 5. Calcular métricas resumidas
$totalQuestionsCount = count($questions);
$totalShown = 0;
$totalCorrect = 0;
$totalIncorrect = 0;

foreach ($stats as $qStat) {
    $totalShown += isset($qStat['shown']) ? (int)$qStat['shown'] : 0;
    $totalCorrect += isset($qStat['correct']) ? (int)$qStat['correct'] : 0;
    $totalIncorrect += isset($qStat['incorrect']) ? (int)$qStat['incorrect'] : 0;
}

$overallSuccessRate = 0;
if ($totalShown > 0) {
    $overallSuccessRate = round(($totalCorrect / $totalShown) * 100);
}
$statsLoadedAt = date('H:i:s');

$questionPerformanceRows = [];
foreach ($questions as $q) {
    $qId = $q['id'];
    $qType = $q['originalType'] ?? ($q['type'] ?? ($stats[$qId]['type'] ?? 'multiple_choice'));
    $qText = triviax_challenge_prompt_text($q);
    $qShown = isset($stats[$qId]['shown']) ? (int)$stats[$qId]['shown'] : 0;
    $qCorrect = isset($stats[$qId]['correct']) ? (int)$stats[$qId]['correct'] : 0;
    $qIncorrect = isset($stats[$qId]['incorrect']) ? (int)$stats[$qId]['incorrect'] : 0;
    $successRate = $qShown > 0 ? round(($qCorrect / $qShown) * 100) : null;

    $questionPerformanceRows[] = [
        'id' => $qId,
        'type' => $qType,
        'text' => $qText,
        'shown' => $qShown,
        'correct' => $qCorrect,
        'incorrect' => $qIncorrect,
        'successRate' => $successRate,
    ];
}

// ── TRIVIAX+ Épica 4: diagnóstico pedagógico por perfiles ────────────
// Agrupa a los estudiantes del proyecto (datos BD de `intentos`) en tres
// perfiles pedagógicos. Nunca rompe la página: sin BD queda vacío.
$diagnosticoPerfiles = null;
$diagnosticoTotal = 0;
$diagnosticoDebiles = [];
if (!empty($activeProject)) {
    try {
        require_once __DIR__ . '/php/db.php';
        require_once __DIR__ . '/php/diagnostico_engine.php';
        if (triviax_db_available()) {
            $pdoDiag = triviax_db();
            $metricasDiag = triviax_diagnostico_metricas_proyecto($pdoDiag, $activeProject);
            $diagnosticoTotal = count($metricasDiag);
            if ($diagnosticoTotal > 0) {
                $diagnosticoPerfiles = triviax_diagnostico_clasificar($metricasDiag);
            }
            $diagnosticoDebiles = triviax_diagnostico_desafios_debiles($pdoDiag, $activeProject, 5);
        }
    } catch (\Throwable $eDiag) {
        $diagnosticoPerfiles = null;
    }
}

usort($questionPerformanceRows, function ($a, $b) {
    $aHasData = $a['shown'] > 0;
    $bHasData = $b['shown'] > 0;
    if ($aHasData !== $bHasData) {
        return $aHasData ? -1 : 1;
    }

    $aRate = $a['successRate'] ?? 101;
    $bRate = $b['successRate'] ?? 101;
    if ($aRate !== $bRate) {
        return $aRate <=> $bRate;
    }

    if ($a['incorrect'] !== $b['incorrect']) {
        return $b['incorrect'] <=> $a['incorrect'];
    }

    if ($a['shown'] !== $b['shown']) {
        return $b['shown'] <=> $a['shown'];
    }

    if ($a['correct'] !== $b['correct']) {
        return $a['correct'] <=> $b['correct'];
    }

    return strnatcasecmp((string)$a['id'], (string)$b['id']);
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIVIAX - Panel de Estadísticas</title>
    
    <!-- Estilos compartidos -->
    <link rel="stylesheet" href="css/styles.css?v=5.0.6">
</head>
<body>
    <div class="stats-layout">
        
        <!-- BARRA NAV SUPERIOR -->
        <header class="stats-navbar">
            <div class="logo-area">
                <h1>TRIVIAX</h1>
                <span>Panel de Estadísticas Docente</span>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <a href="panel/dashboard.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    🏠 Dashboard
                </a>
                <a href="admin.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    ⚙️ Crear Actividad
                </a>
                <a href="index.html" class="btn btn-primary" style="text-decoration: none; padding: 10px 16px;">
                    🎮 Ir al Juego
                </a>
            </div>
        </header>

        <!-- CUERPO PRINCIPAL -->
        <div class="stats-body-container">
            
            <!-- SIDEBAR: LISTA DE ACTIVIDADES -->
            <aside class="stats-sidebar">
                <h2>Actividades Existentes</h2>
                <div class="activity-list">
                    <?php if (empty($projects)): ?>
                        <div style="color: var(--text-muted); font-size: 0.9rem;">No hay proyectos disponibles.</div>
                    <?php else: ?>
                        <?php foreach ($projects as $proj): ?>
                            <a href="estadisticas.php?project=<?php echo urlencode($proj); ?>" class="activity-item-link">
                                <div class="activity-item <?php echo ($proj === $activeProject) ? 'active' : ''; ?>">
                                    <div class="activity-name"><?php echo htmlspecialchars($proj); ?></div>
                                    <div class="activity-meta">Carpeta del proyecto</div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: auto; padding-top: 20px; text-align: center; font-size: 0.75rem; opacity: 0.4; font-weight: 300; border-top: 1px solid var(--border-color);">by Darwin Salina © 2026</div>
            </aside>

            <!-- CONTENIDO PRINCIPAL: METRICAS Y DETALLE -->
            <main class="stats-main-content">
                <?php if (empty($activeProject)): ?>
                    <div class="no-activity-selected">
                        <div class="icon">📁</div>
                        <p>Selecciona una actividad de la barra lateral para ver su reporte.</p>
                    </div>
                <?php else: ?>
                    
                    <!-- Ficha detalles de la actividad -->
                    <div class="activity-details-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">
                        <div>
                            <h2><?php echo htmlspecialchars($metadata['title'] ?: $activeProject); ?></h2>
                            <div class="meta-row">
                                <?php if (!empty($metadata['id'])): ?>
                                    <span><strong>ID:</strong> <?php echo htmlspecialchars($metadata['id']); ?></span>
                                <?php endif; ?>
                                <span><strong>Docente:</strong> <?php echo htmlspecialchars($metadata['author'] ?: '-'); ?></span>
                                <span><strong>Nivel:</strong> <?php echo htmlspecialchars($metadata['nivel'] ?: '-'); ?></span>
                                <span><strong>Fecha:</strong> <?php echo htmlspecialchars($metadata['date'] ?: '-'); ?></span>
                                <?php if (!empty($metadata['mail'])): ?>
                                    <span><strong>Email Docente:</strong> <?php echo htmlspecialchars($metadata['mail']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0;">
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
                                <button type="button" onclick="refreshStatsReport()" class="btn btn-primary btn-refresh-report" style="padding: 10px 16px; font-size: 0.85rem; font-weight: 600;">
                                    🔄 Actualizar datos
                                </button>
                                <button type="button" onclick="printGeneralReport()" class="btn btn-secondary btn-print-report" style="padding: 10px 16px; font-size: 0.85rem; font-weight: 600;">
                                    🖨️ Imprimir Reporte
                                </button>
                            </div>
                            <span style="color: var(--text-muted); font-size: 0.78rem;">Actualizado: <?php echo htmlspecialchars($statsLoadedAt); ?></span>
                        </div>
                    </div>

                    <!-- Fila de Tarjetas de Métricas Generales -->
                    <div class="stats-metrics-row">
                        <div class="stats-metric-card">
                            <span class="card-label">Preguntas en el Banco</span>
                            <span class="card-value"><?php echo $totalQuestionsCount; ?></span>
                        </div>
                        <div class="stats-metric-card">
                            <span class="card-label">Respuestas Totales</span>
                            <span class="card-value"><?php echo $totalShown; ?></span>
                        </div>
                        <div class="stats-metric-card <?php echo ($overallSuccessRate >= 70) ? 'correct-card' : (($overallSuccessRate < 45 && $totalShown > 0) ? 'incorrect-card' : ''); ?>">
                            <span class="card-label">Acierto Promedio</span>
                            <span class="card-value"><?php echo $overallSuccessRate; ?>%</span>
                        </div>
                    </div>

                    <!-- TRIVIAX+ Épica 4: Diagnóstico pedagógico por perfiles -->
                    <div class="stats-table-section" id="diagnostico-section">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                            <h3 style="margin:0;">🧭 Diagnóstico pedagógico (TRIVIAX+)</h3>
                            <button type="button" id="btn-sugerir-refuerzo" class="btn btn-primary" style="padding:10px 16px; font-size:0.85rem; font-weight:600;">
                                🤖 Sugerir actividades de refuerzo
                            </button>
                        </div>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 10px 0 14px;">
                            Agrupamiento automático de estudiantes según sus matrices de acierto-error en las partidas guardadas en base de datos.
                        </p>
                        <?php if ($diagnosticoPerfiles === null): ?>
                            <div style="color: var(--text-muted); text-align: center; padding: 16px;">
                                Todavía no hay partidas con sesión (código de 6 letras) registradas para esta actividad.
                                El diagnóstico se construye con los intentos guardados en base de datos.
                            </div>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
                                <?php foreach ($diagnosticoPerfiles as $grupoDiag): $infoDiag = $grupoDiag['info']; ?>
                                    <div style="background: var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:16px;">
                                        <div style="font-weight:800; color:var(--text-primary); margin-bottom:4px;">
                                            <?php echo $infoDiag['icono']; ?> <?php echo htmlspecialchars($infoDiag['nombre']); ?>
                                            <span style="font-weight:600; color:var(--text-muted);">(<?php echo count($grupoDiag['estudiantes']); ?>)</span>
                                        </div>
                                        <div style="color:var(--text-muted); font-size:0.8rem; margin-bottom:10px;"><?php echo htmlspecialchars($infoDiag['descripcion']); ?></div>
                                        <?php if (empty($grupoDiag['estudiantes'])): ?>
                                            <div style="color:var(--text-muted); font-size:0.85rem;">— Sin estudiantes en este perfil —</div>
                                        <?php else: ?>
                                            <?php foreach ($grupoDiag['estudiantes'] as $estDiag): ?>
                                                <div style="display:flex; justify-content:space-between; gap:8px; padding:5px 0; border-bottom:1px dashed var(--border-color); font-size:0.88rem;">
                                                    <span style="font-weight:600; color:var(--text-secondary);"><?php echo htmlspecialchars($estDiag['nombre']); ?></span>
                                                    <span style="color:var(--text-muted);">
                                                        <?php echo $estDiag['acierto']; ?>% general<?php echo $estDiag['acierto_complejo'] !== null ? ' · ' . $estDiag['acierto_complejo'] . '% aplicación' : ''; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <div style="color:var(--text-secondary); font-size:0.8rem; margin-top:10px;">💡 <?php echo htmlspecialchars($infoDiag['recomendacion']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div id="refuerzo-resultado" style="display:none; margin-top:16px; background: var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:16px;"></div>
                    </div>

                    <!-- Tabla de Detalle por Pregunta -->
                    <div class="stats-table-section">
                        <h3>Desglose de Rendimiento por Pregunta</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 14px;">
                            Ordenado por relevancia pedagógica: primero aparecen las preguntas respondidas con menor porcentaje de acierto.
                        </p>
                        <?php if (!empty($parsingError)): ?>
                            <div style="color: var(--danger); padding: 10px; font-weight: 600;"><?php echo htmlspecialchars($parsingError); ?></div>
                        <?php elseif (empty($questions)): ?>
                            <div style="color: var(--text-muted); text-align: center; padding: 20px;">No se encontraron preguntas en el archivo de este proyecto.</div>
                        <?php else: ?>
                            <table class="stats-data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px; text-align: center;">Nº Pregunta</th>
                                        <th style="width: 140px; text-align: center;">Modalidad</th>
                                        <th>Pregunta</th>
                                        <th style="width: 120px; text-align: center;">Veces Respondida</th>
                                        <th style="width: 100px; text-align: center;">Correctas</th>
                                        <th style="width: 100px; text-align: center;">Incorrectas</th>
                                        <th style="width: 250px;">Porcentaje de Acierto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($questionPerformanceRows as $qRow): 
                                        $qId = $qRow['id'];
                                        $qType = $qRow['type'];
                                        $qText = $qRow['text'];
                                        $qShown = $qRow['shown'];
                                        $qCorrect = $qRow['correct'];
                                        $qIncorrect = $qRow['incorrect'];
                                        $successRate = $qRow['successRate'] ?? 0;

                                        // Definir color del badge e indicador según el porcentaje
                                        $rateClass = 'success-high';
                                        $txtClass = 'txt-success-badge';
                                        if ($qShown > 0) {
                                            if ($successRate < 50) {
                                                $rateClass = 'success-low';
                                                $txtClass = 'txt-danger-badge';
                                            } elseif ($successRate < 75) {
                                                $rateClass = 'success-mid';
                                                $txtClass = 'txt-warning-badge';
                                            }
                                        } else {
                                            $txtClass = 'text-muted';
                                        }
                                    ?>
                                        <tr>
                                            <td style="text-align: center; font-weight: 800;">#<?php echo $qId; ?></td>
                                            <td style="text-align: center; font-weight: 700; color: var(--text-secondary);"><?php echo htmlspecialchars(triviax_challenge_type_label($qType)); ?></td>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($qText); ?></td>
                                            <td style="text-align: center; font-weight: 800; color: var(--text-secondary);"><?php echo $qShown; ?></td>
                                            <td style="text-align: center; font-weight: 800; color: #34d399;"><?php echo $qCorrect; ?></td>
                                            <td style="text-align: center; font-weight: 800; color: #f87171;"><?php echo $qIncorrect; ?></td>
                                            <td>
                                                <div class="stats-progress-container">
                                                    <?php if ($qShown > 0): ?>
                                                        <div class="stats-progress-bar">
                                                            <div class="stats-progress-fill <?php echo $rateClass; ?>" style="width: <?php echo $successRate; ?>%;"></div>
                                                        </div>
                                                        <span class="stats-progress-percent <?php echo $txtClass; ?>"><?php echo $successRate; ?>%</span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">Sin respuestas</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Sección de Reportes de Partidas -->
                    <div class="stats-table-section" style="margin-top: 40px;">
                        <h3>Reportes de Partidas Jugadas</h3>
                        <?php
                        $reportsDir = $projectPath . '/reportes';
                        $reportFiles = [];
                        if (is_dir($reportsDir)) {
                            $files = scandir($reportsDir);
                            foreach ($files as $f) {
                                if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
                                    $reportFiles[] = $f;
                                }
                            }
                        }
                        rsort($reportFiles); // Más recientes primero
                        ?>
                        
                        <?php if (empty($reportFiles)): ?>
                            <div style="color: var(--text-muted); text-align: center; padding: 30px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px dashed var(--border-color);">
                                No se han registrado reportes de partidas jugadas para esta actividad todavía.
                            </div>
                        <?php else: ?>
                            <div class="reports-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 15px;">
                                <?php foreach ($reportFiles as $rf): 
                                    $rfPath = $reportsDir . '/' . $rf;
                                    $rfContent = @file_get_contents($rfPath);
                                    $rfData = json_decode($rfContent, true);
                                    if (!$rfData) continue;
                                    
                                    $rDate = htmlspecialchars($rfData['date'] ?? 'Desconocida');
                                    $rTime = htmlspecialchars($rfData['time'] ?? 'Desconocida');
                                    $rPlayers = $rfData['players'] ?? [];
                                    $winnerName = '-';
                                    if (!empty($rPlayers)) {
                                        $winnerName = htmlspecialchars($rPlayers[0]['name'] ?? 'Jugador');
                                    }
                                ?>
                                    <div class="glass-card" style="padding: 20px; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; border-radius: 12px; background: rgba(17, 24, 39, 0.45);">
                                        <div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                                <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;"><?php echo $rDate; ?> a las <?php echo $rTime; ?></span>
                                                <span class="badge-level" style="background: var(--success); font-size: 0.75rem; padding: 2px 8px; border-radius: 4px;">Finalizada</span>
                                            </div>
                                            <h4 style="margin-bottom: 10px; font-size: 1rem; font-weight: 600; color: var(--text-primary);">Ganador: <span style="color: var(--success); font-weight: 800;"><?php echo $winnerName; ?></span></h4>
                                            
                                            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px;">
                                                <div style="margin-bottom: 6px; font-weight: 600;">Participantes:</div>
                                                <ul style="padding-left: 15px; margin: 0; list-style-type: square;">
                                                    <?php foreach ($rPlayers as $rp): ?>
                                                        <li style="margin-bottom: 3px;"><?php echo htmlspecialchars($rp['name']); ?> (<?php echo (int)$rp['score']; ?> pts, ✅: <?php echo (int)$rp['correct']; ?>, ❌: <?php echo (int)$rp['incorrect']; ?>)</li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; gap: 8px; margin-top: 10px; border-top: 1px solid var(--border-color); padding-top: 12px;">
                                            <button onclick="viewReportDetails(<?php echo htmlspecialchars(json_encode($rfData)); ?>)" class="btn btn-secondary" style="padding: 8px 12px; font-size: 0.75rem; flex: 1; text-transform: none;">
                                                🔍 Ver Detalles
                                            </button>
                                            <a href="estadisticas.php?project=<?php echo urlencode($activeProject); ?>&delete_report=<?php echo urlencode($rf); ?>&token=<?php echo urlencode($statsCsrfToken); ?>" 
                                               onclick="return confirm('¿Seguro de eliminar este reporte de partida?')" 
                                               class="btn btn-outline-danger" style="padding: 8px 12px; font-size: 0.75rem; text-decoration: none; text-transform: none;" title="Eliminar reporte">
                                                🗑️
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Acciones peligrosas (Docente) -->
                    <div class="danger-action-row" style="margin-top: 30px;">
                        <button onclick="confirmReset()" class="btn btn-outline-danger">
                            🗑️ Reiniciar Estadísticas Acumuladas
                        </button>
                    </div>

                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- MODAL DETALLES DEL REPORTE -->
    <div id="modal-report-details" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 1000; align-items: center; justify-content: center;">
        <div class="glass-card" style="width: 650px; max-width: 90%; max-height: 85vh; padding: 25px; display: flex; flex-direction: column; overflow-y: auto; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                <h3 style="font-size: 1.3rem; font-weight: 800; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Reporte Detallado de Partida</h3>
                <div style="display: flex; gap: 8px;">
                    <button onclick="printGameReport()" class="btn btn-primary btn-print-modal" style="padding: 6px 12px; font-size: 0.8rem; text-transform: none;">🖨️ Exportar PDF</button>
                    <button onclick="closeReportDetails()" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; text-transform: none;">Cerrar</button>
                </div>
            </div>
            
            <div id="report-modal-content" style="font-size: 0.9rem; color: var(--text-primary);">
                <!-- Contenido dinámico -->
            </div>
        </div>
    </div>

    <script>
        function confirmReset() {
            if (confirm("¿Estás seguro de que deseas reiniciar las estadísticas acumuladas de esta actividad? Se borrarán todos los contadores de forma permanente.")) {
                window.location.href = "estadisticas.php?project=<?php echo urlencode($activeProject); ?>&reset=1&token=<?php echo urlencode($statsCsrfToken); ?>";
            }
        }

        // Variable global para almacenar el reporte activo
        window.activeReportData = null;

        function viewReportDetails(data) {
            window.activeReportData = data;
            const modal = document.getElementById('modal-report-details');
            const content = document.getElementById('report-modal-content');
            
            let playersHtml = '';
            data.players.forEach((p, idx) => {
                playersHtml += `
                    <tr>
                        <td style="padding: 8px; border-bottom: 1px solid var(--border-color);"><strong>#${idx+1}</strong></td>
                        <td style="padding: 8px; border-bottom: 1px solid var(--border-color); font-weight: 600;">${p.name}</td>
                        <td style="padding: 8px; border-bottom: 1px solid var(--border-color); text-align: center; font-weight: 800;">${p.score} pts</td>
                        <td style="padding: 8px; border-bottom: 1px solid var(--border-color); text-align: center; color: #34d399; font-weight: 800;">${p.correct}</td>
                        <td style="padding: 8px; border-bottom: 1px solid var(--border-color); text-align: center; color: #f87171; font-weight: 800;">${p.incorrect}</td>
                    </tr>
                `;
            });
            
            let attemptsHtml = '';
            if (data.attempts && data.attempts.length > 0) {
                data.attempts.forEach((att, idx) => {
                    let badgeClass = 'attempt-badge attempt-correct';
                    let badgeText = 'Correcto';
                    if (att.result === 'incorrect') {
                        badgeClass = 'attempt-badge attempt-incorrect';
                        badgeText = 'Incorrecto';
                    } else if (att.result === 'timeout') {
                        badgeClass = 'attempt-badge attempt-timeout';
                        badgeText = 'Tiempo Agotado';
                    }
                    attemptsHtml += `
                        <div class="modal-attempt-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 0.8rem;">
                                <span style="font-weight: 600; color: var(--text-muted);">Pregunta #${idx+1} (ID: ${att.questionId})</span>
                                <span class="${badgeClass}">${badgeText}</span>
                            </div>
                            <div style="font-weight: 600; margin-bottom: 6px; color: var(--text-primary);">${att.questionText}</div>
                            <div style="color: var(--text-secondary); font-size: 0.8rem;">Respondido por: <strong>${att.playerName}</strong></div>
                        </div>
                    `;
                });
            } else {
                attemptsHtml = '<p style="color: var(--text-muted); text-align: center; font-style: italic;">No hay registro de preguntas respondidas.</p>';
            }
            
            content.innerHTML = `
                <div style="margin-bottom: 20px; display: flex; gap: 20px; font-size: 0.85rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                    <div><strong>Fecha:</strong> ${data.date}</div>
                    <div><strong>Hora:</strong> ${data.time}</div>
                    <div><strong>Email Docente:</strong> ${data.email || 'No provisto'}</div>
                </div>
                
                <h4 style="margin-bottom: 10px; font-size: 0.95rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Clasificación Final</h4>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 0.85rem; color: var(--text-primary);">
                    <thead>
                        <tr style="text-align: left; opacity: 0.7; border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 8px;">Pos</th>
                            <th style="padding: 8px;">Jugador</th>
                            <th style="padding: 8px; text-align: center;">Puntos</th>
                            <th style="padding: 8px; text-align: center;">Correctas</th>
                            <th style="padding: 8px; text-align: center;">Incorrectas</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${playersHtml}
                    </tbody>
                </table>
                
                <h4 style="margin-bottom: 12px; font-size: 0.95rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Detalle de Respuestas</h4>
                <div class="modal-attempts-scrollable">
                    ${attemptsHtml}
                </div>
            `;
            
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }
        
        function closeReportDetails() {
            document.getElementById('modal-report-details').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        // Cerrar modal al hacer click fuera de la tarjeta
        document.getElementById('modal-report-details').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReportDetails();
            }
        });

        function refreshStatsReport() {
            window.location.reload();
        }

        // Función para imprimir el reporte de la actividad general en pestaña nueva
        function printGeneralReport() {
            const titleElement = document.querySelector('.activity-details-header h2');
            const title = titleElement ? titleElement.innerText : 'Reporte de Actividad';
            
            const metaRowElement = document.querySelector('.activity-details-header .meta-row');
            const metaRow = metaRowElement ? metaRowElement.innerHTML : '';
            
            // Obtener métricas
            const metricCards = document.querySelectorAll('.stats-metric-card');
            let metricsHtml = '';
            metricCards.forEach(card => {
                const label = card.querySelector('.card-label').innerText;
                const value = card.querySelector('.card-value').innerText;
                metricsHtml += `
                    <div class="metric-box">
                        <div class="metric-label">${label}</div>
                        <div class="metric-value">${value}</div>
                    </div>
                `;
            });
            
            // Obtener tabla de desglose
            const dataTable = document.querySelector('.stats-data-table');
            const questionsTableHtml = dataTable ? dataTable.cloneNode(true).outerHTML : '<p>No hay datos disponibles.</p>';
            
            // Obtener reportes de partidas (removiendo botones)
            const reportsGrid = document.querySelector('.reports-grid');
            let reportsHtml = '';
            if (reportsGrid) {
                const reportsGridClone = reportsGrid.cloneNode(true);
                reportsGridClone.querySelectorAll('.btn, button, a').forEach(el => el.remove());
                reportsHtml = `
                    <div class="section-title">Reportes de Partidas Jugadas</div>
                    <div class="reports-list">
                        ${reportsGridClone.innerHTML}
                    </div>
                `;
            }
            
            const printContent = `
                <!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <title>Reporte Actividad - ${title}</title>
                    <style>
                        @page {
                            size: A4 portrait;
                            margin: 20mm 15mm 20mm 15mm;
                        }
                        
                        body {
                            font-family: 'Outfit', sans-serif;
                            color: #0f172a;
                            background: #ffffff;
                            margin: 0;
                            padding: 0;
                            font-size: 10pt;
                            line-height: 1.5;
                        }
                        
                        .report-header {
                            border-bottom: 3px solid #6366f1;
                            padding-bottom: 12px;
                            margin-bottom: 25px;
                        }
                        
                        .report-header h1 {
                            font-size: 24pt;
                            font-weight: 800;
                            margin: 0 0 8px 0;
                            color: #1e1b4b;
                        }
                        
                        .meta-grid {
                            display: grid;
                            grid-template-columns: repeat(2, 1fr);
                            gap: 10px;
                            font-size: 9.5pt;
                            color: #475569;
                        }
                        
                        .meta-grid span {
                            display: block;
                        }
                        
                        .meta-grid strong {
                            color: #0f172a;
                        }
                        
                        .metrics-row {
                            display: flex;
                            gap: 15px;
                            margin-bottom: 30px;
                        }
                        
                        .metric-box {
                            flex: 1;
                            background: #f8fafc;
                            border: 1px solid #e2e8f0;
                            border-radius: 8px;
                            padding: 15px;
                            text-align: center;
                        }
                        
                        .metric-label {
                            font-size: 8pt;
                            font-weight: 800;
                            color: #64748b;
                            text-transform: uppercase;
                            letter-spacing: 0.5px;
                            margin-bottom: 5px;
                        }
                        
                        .metric-value {
                            font-size: 20pt;
                            font-weight: 800;
                            color: #4f46e5;
                        }
                        
                        .section-title {
                            font-size: 13pt;
                            font-weight: 800;
                            color: #1e1b4b;
                            border-bottom: 1px solid #cbd5e1;
                            padding-bottom: 6px;
                            margin-top: 30px;
                            margin-bottom: 15px;
                            text-transform: uppercase;
                            letter-spacing: 0.5px;
                        }
                        
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-bottom: 25px;
                        }
                        
                        th, td {
                            padding: 8px 10px;
                            text-align: left;
                            font-size: 9pt;
                            border-bottom: 1px solid #e2e8f0;
                        }
                        
                        th {
                            background: #f1f5f9;
                            color: #475569;
                            font-weight: 800;
                            border-bottom: 2px solid #cbd5e1;
                        }
                        
                        .stats-progress-container {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                        }
                        
                        .stats-progress-bar {
                            flex: 1;
                            height: 8px;
                            background: #e2e8f0;
                            border-radius: 4px;
                            overflow: hidden;
                            min-width: 80px;
                            border: 1px solid #cbd5e1;
                        }
                        
                        .stats-progress-fill {
                            height: 100%;
                            border-radius: 4px;
                        }
                        
                        .success-high { background: #10b981; }
                        .success-mid { background: #f59e0b; }
                        .success-low { background: #ef4444; }
                        
                        .stats-progress-percent {
                            font-weight: 800;
                            font-size: 8.5pt;
                            min-width: 35px;
                            text-align: right;
                        }
                        
                        .txt-success-badge { color: #059669; font-weight: 800; }
                        .txt-warning-badge { color: #d97706; font-weight: 800; }
                        .txt-danger-badge { color: #dc2626; font-weight: 800; }
                        
                        .reports-list {
                            display: grid;
                            grid-template-columns: repeat(2, 1fr);
                            gap: 15px;
                        }
                        
                        .reports-list .glass-card {
                            background: #f8fafc;
                            border: 1px solid #e2e8f0;
                            border-radius: 8px;
                            padding: 15px;
                        }
                        
                        .reports-list .glass-card h4 {
                            margin: 0 0 8px 0;
                            font-size: 10.5pt;
                            color: #1e1b4b;
                        }
                        
                        .reports-list .glass-card ul {
                            margin: 0;
                            padding-left: 20px;
                            font-size: 8.5pt;
                            color: #475569;
                        }
                        
                        .reports-list .glass-card li {
                            margin-bottom: 4px;
                        }
                        
                        tr, .metric-box, .glass-card {
                            page-break-inside: avoid;
                            break-inside: avoid;
                        }
                        
                        h1, h2, h3, .section-title {
                            page-break-after: avoid;
                            break-after: avoid;
                        }

                        @font-face {
                            font-family: "Luckiest Guy Local";
                            src: url("<?= TRIVIAX_BASE ?>/fonts/LuckiestGuy-Regular.ttf") format("truetype");
                            font-weight: 400;
                            font-style: normal;
                        }

                        .triviax-wordmark {
                            display: inline-flex;
                            align-items: baseline;
                            font-family: "Luckiest Guy Local", "Arial Black", Impact, sans-serif;
                            font-weight: 400;
                            letter-spacing: 0.025em;
                            text-transform: uppercase;
                            vertical-align: baseline;
                        }

                        .triviax-wordmark__main {
                            color: #48d6ff;
                        }

                        .triviax-wordmark__x {
                            color: #fcd360;
                        }
                        
                        .footer {
                            margin-top: 40px;
                            border-top: 1px solid #e2e8f0;
                            padding-top: 10px;
                            text-align: center;
                            font-size: 8pt;
                            color: #94a3b8;
                        }
                    </style>
                </head>
                <body>
                    <div class="report-header">
                        <h1>Reporte de Actividad: ${title}</h1>
                        <div class="meta-grid">
                            ${metaRow}
                        </div>
                    </div>
                    
                    <div class="metrics-row">
                        ${metricsHtml}
                    </div>
                    
                    <div class="section-title">Desglose de Rendimiento por Pregunta</div>
                    ${questionsTableHtml}
                    
                    ${reportsHtml}
                    
                    <div class="footer">
                        Reporte generado por <span class="triviax-wordmark" aria-label="TRIVIAX"><span class="triviax-wordmark__main" aria-hidden="true">TRIVIA</span><span class="triviax-wordmark__x" aria-hidden="true">X</span></span> el ${new Date().toLocaleDateString()} a las ${new Date().toLocaleTimeString().substring(0, 5)}
                    </div>
                    
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() { window.close(); }, 500);
                        }
                    <\/script>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.open();
            printWindow.document.write(printContent);
            printWindow.document.close();
        }

        // Función para imprimir el reporte detallado de una partida en pestaña nueva
        function printGameReport() {
            const data = window.activeReportData;
            if (!data) return;
            
            // Generar tabla de jugadores
            let playersRows = '';
            data.players.forEach((p, idx) => {
                playersRows += `
                    <tr>
                        <td style="text-align: center; font-weight: 800;">#${idx+1}</td>
                        <td style="font-weight: 600;">${p.name}</td>
                        <td style="text-align: center; font-weight: 800; color: #4f46e5;">${p.score} pts</td>
                        <td style="text-align: center; color: #059669; font-weight: 800;">${p.correct}</td>
                        <td style="text-align: center; color: #dc2626; font-weight: 800;">${p.incorrect}</td>
                    </tr>
                `;
            });
            
            // Generar intentos
            let attemptsHtml = '';
            if (data.attempts && data.attempts.length > 0) {
                data.attempts.forEach((att, idx) => {
                    let badgeClass = 'badge-correct';
                    let badgeText = 'Correcto';
                    if (att.result === 'incorrect') {
                        badgeClass = 'badge-incorrect';
                        badgeText = 'Incorrecto';
                    } else if (att.result === 'timeout') {
                        badgeClass = 'badge-timeout';
                        badgeText = 'Tiempo Agotado';
                    }
                    attemptsHtml += `
                        <div class="attempt-card">
                            <div class="attempt-header">
                                <span class="attempt-number">Pregunta #${idx+1} (ID: ${att.questionId})</span>
                                <span class="badge ${badgeClass}">${badgeText}</span>
                            </div>
                            <div class="attempt-question">${att.questionText}</div>
                            <div class="attempt-player">Respondido por: <strong>${att.playerName}</strong></div>
                        </div>
                    `;
                });
            } else {
                attemptsHtml = '<p style="text-align: center; color: #64748b; font-style: italic;">No hay registro de preguntas respondidas.</p>';
            }
            
            const titleElement = document.querySelector('.activity-details-header h2');
            const title = titleElement ? titleElement.innerText : 'Actividad';
            
            const printContent = `
                <!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <title>Reporte de Partida - TRIVIAX</title>
                    <style>
                        @page {
                            size: A4 portrait;
                            margin: 20mm 15mm 20mm 15mm;
                        }
                        
                        body {
                            font-family: 'Outfit', sans-serif;
                            color: #0f172a;
                            background: #ffffff;
                            margin: 0;
                            padding: 0;
                            font-size: 10pt;
                            line-height: 1.5;
                        }
                        
                        .report-header {
                            border-bottom: 3px solid #10b981;
                            padding-bottom: 12px;
                            margin-bottom: 25px;
                        }
                        
                        .report-header h1 {
                            font-size: 22pt;
                            font-weight: 800;
                            margin: 0 0 8px 0;
                            color: #1e1b4b;
                        }
                        
                        .meta-row {
                            display: flex;
                            gap: 20px;
                            font-size: 9.5pt;
                            color: #475569;
                        }
                        
                        .meta-row strong {
                            color: #0f172a;
                        }
                        
                        .section-title {
                            font-size: 12pt;
                            font-weight: 800;
                            color: #1e1b4b;
                            border-bottom: 1px solid #cbd5e1;
                            padding-bottom: 6px;
                            margin-top: 30px;
                            margin-bottom: 15px;
                            text-transform: uppercase;
                            letter-spacing: 0.5px;
                        }
                        
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-bottom: 30px;
                        }
                        
                        th, td {
                            padding: 10px 12px;
                            text-align: left;
                            font-size: 9pt;
                            border-bottom: 1px solid #e2e8f0;
                        }
                        
                        th {
                            background: #f1f5f9;
                            color: #475569;
                            font-weight: 800;
                            border-bottom: 2px solid #cbd5e1;
                        }
                        
                        .attempt-card {
                            background: #f8fafc;
                            border: 1px solid #e2e8f0;
                            border-radius: 8px;
                            padding: 12px;
                            margin-bottom: 12px;
                            page-break-inside: avoid;
                            break-inside: avoid;
                        }
                        
                        .attempt-header {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            margin-bottom: 6px;
                        }
                        
                        .attempt-number {
                            font-weight: 600;
                            color: #64748b;
                            font-size: 8.5pt;
                        }
                        
                        .badge {
                            font-weight: 800;
                            font-size: 7.5pt;
                            padding: 2px 8px;
                            border-radius: 4px;
                            text-transform: uppercase;
                        }
                        
                        .badge-correct {
                            background: #d1fae5;
                            color: #065f46;
                            border: 1px solid #a7f3d0;
                        }
                        
                        .badge-incorrect {
                            background: #fee2e2;
                            color: #991b1b;
                            border: 1px solid #fecaca;
                        }
                        
                        .badge-timeout {
                            background: #fef3c7;
                            color: #92400e;
                            border: 1px solid #fde68a;
                        }
                        
                        .attempt-question {
                            font-weight: 600;
                            font-size: 9.5pt;
                            margin-bottom: 6px;
                            color: #0f172a;
                        }
                        
                        .attempt-player {
                            font-size: 8.5pt;
                            color: #475569;
                        }
                        
                        tr, .attempt-card {
                            page-break-inside: avoid;
                            break-inside: avoid;
                        }
                        
                        h1, h2, h3, .section-title {
                            page-break-after: avoid;
                            break-after: avoid;
                        }

                        @font-face {
                            font-family: "Luckiest Guy Local";
                            src: url("<?= TRIVIAX_BASE ?>/fonts/LuckiestGuy-Regular.ttf") format("truetype");
                            font-weight: 400;
                            font-style: normal;
                        }

                        .triviax-wordmark {
                            display: inline-flex;
                            align-items: baseline;
                            font-family: "Luckiest Guy Local", "Arial Black", Impact, sans-serif;
                            font-weight: 400;
                            letter-spacing: 0.025em;
                            text-transform: uppercase;
                            vertical-align: baseline;
                        }

                        .triviax-wordmark__main {
                            color: #48d6ff;
                        }

                        .triviax-wordmark__x {
                            color: #fcd360;
                        }
                        
                        .footer {
                            margin-top: 40px;
                            border-top: 1px solid #e2e8f0;
                            padding-top: 10px;
                            text-align: center;
                            font-size: 8pt;
                            color: #94a3b8;
                        }
                    </style>
                </head>
                <body>
                    <div class="report-header">
                        <h1>Reporte Detallado de Partida</h1>
                        <div class="meta-row">
                            <div><strong>Actividad:</strong> ${title}</div>
                            <div><strong>Fecha:</strong> ${data.date}</div>
                            <div><strong>Hora:</strong> ${data.time}</div>
                            <div><strong>Email Docente:</strong> ${data.email || 'No provisto'}</div>
                        </div>
                    </div>
                    
                    <div class="section-title">Clasificación Final</div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px;">Pos</th>
                                <th>Jugador</th>
                                <th style="text-align: center; width: 120px;">Puntaje</th>
                                <th style="text-align: center; width: 100px;">Correctas</th>
                                <th style="text-align: center; width: 100px;">Incorrectas</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${playersRows}
                        </tbody>
                    </table>
                    
                    <div class="section-title">Detalle de Respuestas</div>
                    <div class="attempts-container">
                        ${attemptsHtml}
                    </div>
                    
                    <div class="footer">
                        Reporte generado por <span class="triviax-wordmark" aria-label="TRIVIAX"><span class="triviax-wordmark__main" aria-hidden="true">TRIVIA</span><span class="triviax-wordmark__x" aria-hidden="true">X</span></span> el ${new Date().toLocaleDateString()} a las ${new Date().toLocaleTimeString().substring(0, 5)}
                    </div>
                    
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() { window.close(); }, 500);
                        }
                    <\/script>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.open();
            printWindow.document.write(printContent);
            printWindow.document.close();
        }
    </script>
<script>
// TRIVIAX+ Épica 4: sugerencia de actividades de refuerzo con IA
(function () {
    const btn = document.getElementById('btn-sugerir-refuerzo');
    if (!btn) return;
    const panel = document.getElementById('refuerzo-resultado');
    const PROJECT = <?php echo json_encode($activeProject); ?>;
    const CSRF = <?php echo json_encode(triviax_csrf_token()); ?>;

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = String(t ?? '');
        return d.innerHTML;
    }

    btn.addEventListener('click', async () => {
        if (!PROJECT) return;
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = '⏳ Analizando debilidades…';
        panel.style.display = 'block';
        panel.innerHTML = '<span style="color:var(--text-muted);">Preparando la sugerencia de refuerzo…</span>';
        try {
            const res = await fetch('api.php?action=diagnostico_sugerir', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ project: PROJECT })
            });
            const data = await res.json();
            if (!res.ok || data.success === false) {
                throw new Error(data.message || data.error || ('HTTP ' + res.status));
            }
            let html = '';
            if (data.modo === 'ia' && data.proyecto_remedial) {
                const n = (data.proyecto_remedial.challenges || []).length;
                const blob = JSON.stringify(data.proyecto_remedial, null, 2);
                html += `<div style="font-weight:700; color:var(--text-primary); margin-bottom:8px;">✅ Sub-proyecto remedial generado y validado (${n} desafíos)</div>`;
                html += `<p style="color:var(--text-muted); font-size:0.85rem;">Descarga el JSON e impórtalo desde el <a href="admin.php" style="color:#a5b4fc;">Panel de Actividades</a> para crear la actividad de refuerzo.</p>`;
                html += `<button type="button" class="btn btn-primary" id="btn-descargar-refuerzo" style="padding:9px 14px; font-size:0.85rem;">⬇️ Descargar proyecto de refuerzo (.json)</button>`;
                panel.innerHTML = html;
                document.getElementById('btn-descargar-refuerzo').addEventListener('click', () => {
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(new Blob([blob], { type: 'application/json' }));
                    a.download = `refuerzo_${PROJECT}.json`;
                    a.click();
                    URL.revokeObjectURL(a.href);
                });
            } else {
                html += `<div style="font-weight:700; color:var(--text-primary); margin-bottom:8px;">📋 Prompt de refuerzo listo (sin proveedor de IA configurado)</div>`;
                html += `<p style="color:var(--text-muted); font-size:0.85rem;">Copia este prompt en tu chatbot de confianza y usa el JSON resultante en el <a href="admin.php" style="color:#a5b4fc;">Panel de Actividades</a>. Para generación directa, configura LLM_PROVIDER y LLM_API_KEY en triviax.env.</p>`;
                html += `<textarea readonly style="width:100%; min-height:180px; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); border-radius:8px; color:var(--text-secondary); padding:10px; font-size:0.8rem; font-family:monospace;">${esc(data.prompt)}</textarea>`;
                html += `<button type="button" class="btn btn-secondary" id="btn-copiar-prompt" style="margin-top:8px; padding:8px 14px; font-size:0.85rem;">📋 Copiar prompt</button>`;
                panel.innerHTML = html;
                document.getElementById('btn-copiar-prompt').addEventListener('click', (e) => {
                    navigator.clipboard.writeText(data.prompt).then(() => { e.target.textContent = '✅ Copiado'; });
                });
            }
        } catch (err) {
            panel.innerHTML = `<span style="color:#f87171;">⚠️ ${esc(err.message)}</span>`;
        } finally {
            btn.disabled = false;
            btn.textContent = original;
        }
    });
})();
</script>
<script src="<?= TRIVIAX_BASE ?>/js/brand.js?v=5.0.6"></script>
</body>
</html>
