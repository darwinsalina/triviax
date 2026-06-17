<?php
/**
 * TRIVIAX Backend API
 * 
 * PHP version 7+
 * Maneja el listado de proyectos, el parsing de preguntas y guardado de estadísticas de forma segura.
 */

// Habilitar reporte de errores para diagnóstico en el servidor web (evita pantalla en blanco si hay fallos)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Cabeceras de Seguridad y Control de Caché
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Headers: Content-Type");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/php/triviax_core.php';
require_once __DIR__ . '/php/auth.php';

if (triviax_env_bool('APP_DEBUG', false)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

function triviax_api_json($payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function triviax_api_error(string $code, string $message, int $status = 400, array $errors = []): void {
    triviax_api_json([
        'ok' => false,
        'success' => false,
        'code' => $code,
        'message' => $message,
        'error' => $message,
        'errors' => $errors,
    ], $status);
}

function triviax_api_success(array $data = [], string $message = ''): void {
    triviax_api_json(array_merge([
        'ok' => true,
        'success' => true,
        'data' => $data,
        'message' => $message,
        'errors' => [],
    ], $data));
}

function triviax_api_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function triviax_api_require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        triviax_api_error('METHOD_NOT_ALLOWED', 'Método no permitido.', 405);
    }
}

/**
 * Limita por IP la tasa de un endpoint público de la API. Reutiliza la
 * infraestructura de auth.php (tabla rate_limits) y falla en modo abierto si
 * la BD no está disponible, igual que las APIs de actividades.
 * Los límites son generosos para no afectar a una clase tras un mismo IP (NAT
 * escolar) pero sí cortar abuso scriptado.
 */
function triviax_api_throttle(string $scope, int $maxPerWindow, int $windowSeconds, int $blockSeconds = 120): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
    if (!triviax_rate_limit_check($scope, $ip, $maxPerWindow, $windowSeconds)) {
        triviax_api_error('RATE_LIMITED', 'Demasiadas solicitudes. Esperá un momento e intentá de nuevo.', 429);
    }
    triviax_rate_limit_hit($scope, $ip, $windowSeconds, $blockSeconds, $maxPerWindow);
}

function triviax_api_check_maintenance(): void {
    if (triviax_maintenance_mode() && !triviax_es_superadmin()) {
        triviax_api_error('MAINTENANCE_MODE', 'TRIVIAX está en mantenimiento. Intenta nuevamente más tarde.', 503);
    }
}

function triviax_api_normalize_session_state(string $estado): string {
    $map = [
        'pendiente' => 'waiting',
        'activa' => 'active',
        'finalizada' => 'finished',
        'cancelada' => 'cancelled',
        'waiting' => 'waiting',
        'active' => 'active',
        'paused' => 'paused',
        'finished' => 'finished',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ];
    return $map[$estado] ?? $estado;
}

function triviax_api_token_hash(string $playerToken): string {
    return hash('sha256', $playerToken);
}

function triviax_api_validate_player(PDO $pdo, int $sesionId, int $jugadorId, string $playerToken, bool $lock = false): array {
    if ($sesionId <= 0 || $jugadorId <= 0 || strlen($playerToken) < 32) {
        triviax_api_error('UNAUTHORIZED', 'Credenciales de jugador inválidas.', 401);
    }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT * FROM sesion_jugadores WHERE sesion_id = ? AND id = ? AND player_token = ?{$suffix}");
    $stmt->execute([$sesionId, $jugadorId, triviax_api_token_hash($playerToken)]);
    $jugador = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$jugador) {
        triviax_api_error('FORBIDDEN', 'Jugador no autorizado para esta sesión.', 403);
    }
    return $jugador;
}

triviax_api_check_maintenance();

// Asegurar que el directorio de proyectos exista
$baseProjectsDir = __DIR__ . '/proyectos';
if (!is_dir($baseProjectsDir)) {
    @mkdir($baseProjectsDir, 0777, true);
}

/**
 * Valida que una ruta resuelta esté confinada dentro del directorio de proyectos (Previene Path Traversal)
 * @param string $path
 * @return bool
 */
function isSafePath($path) {
    return triviax_is_safe_project_path($path);
    $baseDir = realpath(__DIR__ . '/proyectos');
    if ($baseDir === false) {
        return false;
    }
    
    $realPath = realpath($path);
    if ($realPath === false) {
        // Si el archivo no existe aún (ej: nuevo reporte), validamos su carpeta padre
        $parentDir = realpath(dirname($path));
        return $parentDir !== false && strpos($parentDir, $baseDir) === 0;
    }
    
    return strpos($realPath, $baseDir) === 0;
}

function triviax_read_project_list_metadata($projectPath, $folderName) {
    $metadata = [
        'id' => $folderName,
        'title' => $folderName,
        'author' => '',
        'nivel' => '',
        'date' => '',
        'updated_at' => @filemtime($projectPath) ?: 0
    ];

    $jsonPath = $projectPath . '/proyecto.json';
    if (file_exists($jsonPath)) {
        $rawJson = @file_get_contents($jsonPath);
        $decoded = json_decode($rawJson ?: '', true);
        if (is_array($decoded) && isset($decoded['metadata']) && is_array($decoded['metadata'])) {
            $metadata['title'] = trim((string)($decoded['metadata']['title'] ?? '')) ?: $folderName;
            $metadata['author'] = trim((string)($decoded['metadata']['author'] ?? ''));
            $metadata['nivel'] = trim((string)($decoded['metadata']['nivel'] ?? ''));
            $metadata['date'] = trim((string)($decoded['metadata']['date'] ?? ''));
            $metadata['updated_at'] = @filemtime($jsonPath) ?: $metadata['updated_at'];
            return $metadata;
        }
    }

    $questionsPath = $projectPath . '/preguntas.txt';
    if (!file_exists($questionsPath)) {
        return $metadata;
    }

    $content = @file_get_contents($questionsPath);
    if ($content === false) {
        return $metadata;
    }
    if (substr($content, 0, 3) === pack('CCC', 0xef, 0xbb, 0xbf)) {
        $content = substr($content, 3);
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (preg_match('/^\d+\.\s+/', $trimmed)) {
            break;
        }
        if (preg_match('/^(title|author|nivel|date)\s*:\s*(.*)$/i', $trimmed, $matches)) {
            $key = strtolower($matches[1]);
            $value = trim($matches[2]);
            if ($value !== '') {
                $metadata[$key] = $value;
            }
        }
    }
    $metadata['updated_at'] = @filemtime($questionsPath) ?: $metadata['updated_at'];

    if (trim($metadata['title']) === '') {
        $metadata['title'] = $folderName;
    }

    return $metadata;
}

/**
 * Fusiona el sidecar board.json (si existe) en el objeto board del proyecto.
 * Permite fijar un tablero a la actividad sin depender del formato (proyecto.json
 * o preguntas.txt). El juego lee board.lockedId.
 */
function triviax_merge_board_sidecar($projectPath, array $board) {
    $sidecar = $projectPath . '/board.json';
    if (is_file($sidecar)) {
        $bj = json_decode(@file_get_contents($sidecar), true);
        if (is_array($bj) && !empty($bj['lockedId'])) {
            $board['lockedId'] = (string)$bj['lockedId'];
        }
    }
    return $board;
}

// Acción: Listar tableros de juego personalizados
if ($action === 'list_boards') {
    $boards = [];
    $dir = __DIR__ . '/images/tableros';
    if (is_dir($dir)) {
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $json = json_decode($content, true);
                if (is_array($json) && isset($json['id'])) {
                    $boards[] = $json;
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'boards' => $boards
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Acción: Listar proyectos disponibles
if ($action === 'list') {
    $projects = [];
    
    if (is_dir($baseProjectsDir)) {
        $files = scandir($baseProjectsDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $baseProjectsDir . '/' . $file;
            if (is_dir($path)) {
                // Consideramos un proyecto válido si tiene preguntas.txt o proyecto.json
                if (file_exists($path . '/preguntas.txt') || file_exists($path . '/proyecto.json')) {
                    $projects[] = triviax_read_project_list_metadata($path, $file);
                }
            }
        }
    }

    usort($projects, function ($a, $b) {
        return strcasecmp($a['title'] ?? $a['id'], $b['title'] ?? $b['id']);
    });
    
    echo json_encode([
        'success' => true,
        'projects' => $projects,
        'csrf_token' => triviax_csrf_token()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Acción: Obtener datos de un proyecto (metadata + preguntas parsed)
if ($action === 'get') {
    $project = isset($_GET['project']) ? $_GET['project'] : '';
    
    // Validación de entrada
    if (empty($project) || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Nombre de proyecto inválido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $projectPath = $baseProjectsDir . "/{$project}";
    
    // Validar confinamiento de ruta
    if (!isSafePath($projectPath)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Acceso denegado (Path Traversal detectado).'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $jsonPath = $projectPath . '/proyecto.json';
    $filePath = $projectPath . '/preguntas.txt';

    // 1. SOPORTE DE NUEVO FORMATO: Cargar proyecto.json si existe
    if (file_exists($jsonPath)) {
        $jsonContent = file_get_contents($jsonPath);
        if ($jsonContent === false) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'No se pudo leer el archivo proyecto.json.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $parsedJsonProject = triviax_parse_project_json($jsonContent);
            $boardOut = is_array($parsedJsonProject['board']) ? $parsedJsonProject['board'] : [];
            $boardOut = triviax_merge_board_sidecar($projectPath, $boardOut);
            echo json_encode([
                'success' => true,
                'metadata' => $parsedJsonProject['metadata'],
                'questions' => $parsedJsonProject['challenges'],
                'board' => $boardOut
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 2. RETROCOMPATIBILIDAD: Cargar preguntas.txt clásico
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No se encontró el archivo de preguntas preguntas.txt ni proyecto.json.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $content = file_get_contents($filePath);
    if ($content === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo leer el archivo de preguntas.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $parsedProject = triviax_parse_questions_text($content);
        echo json_encode([
            'success' => true,
            'metadata' => $parsedProject['metadata'],
            'questions' => $parsedProject['questions'],
            'board' => triviax_merge_board_sidecar($projectPath, [])
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
    
    // Quitar BOM UTF-8 si está presente
    if (substr($content, 0, 3) === pack("CCC", 0xef, 0xbb, 0xbf)) {
        $content = substr($content, 3);
    }
    
    $lines = preg_split('/\r\n|\r|\n/', $content);
    
    $metadata = [
        'title' => 'TRIVIAX',
        'author' => '',
        'nivel' => '',
        'obs' => '',
        'date' => '',
        'mail' => '',
        'id' => ''
    ];
    
    $questions = [];
    $currentQuestion = null;
    $parsingQuestionsStarted = false;
    
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trimmed = trim($line);
        
        if ($trimmed === '') {
            continue;
        }
        
        if (preg_match('/^#+\s*/', $trimmed)) {
            continue;
        }
        
        if (!$parsingQuestionsStarted) {
            if (preg_match('/^(title|author|nivel|obs|date|mail|id)\s*:\s*(.*)$/i', $trimmed, $matches)) {
                $key = strtolower($matches[1]);
                $metadata[$key] = trim($matches[2]);
                continue;
            }
        }
        
        if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed, $matches)) {
            $parsingQuestionsStarted = true;
            
            if ($currentQuestion !== null) {
                $error = validateQuestion($currentQuestion);
                if ($error !== null) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $questions[] = $currentQuestion;
            }
            
            $qId = (int)$matches[1];
            $qText = trim($matches[2]);
            
            $currentQuestion = [
                'id' => $qId,
                'text' => $qText,
                'answers' => []
            ];
            continue;
        }
        
        if (strpos($trimmed, '@') === 0) {
            if ($currentQuestion === null) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Se detectó una respuesta sin pregunta asociada en la línea {$lineNumber}."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
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
            continue;
        }
    }
    
    if ($currentQuestion !== null) {
        $error = validateQuestion($currentQuestion);
        if ($error !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $questions[] = $currentQuestion;
    }
    
    if (empty($questions)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'El archivo de preguntas no contiene preguntas válidas.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'metadata' => $metadata,
        'questions' => $questions
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Acción: Enviar reporte de partida por correo al docente
if ($action === 'send_report') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || empty($data['email']) || empty($data['project'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos: falta proyecto o email del docente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $project = isset($data['project']) ? $data['project'] : '';
    if (empty($project) || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Proyecto invÃ¡lido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectPath = $baseProjectsDir . "/{$project}";
    $questionsPath = $projectPath . '/preguntas.txt';
    $jsonProjectPath = $projectPath . '/proyecto.json';
    if (!is_dir($projectPath) || !isSafePath($projectPath) || (!file_exists($questionsPath) && !file_exists($jsonProjectPath))) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Proyecto no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if (file_exists($jsonProjectPath)) {
            $projectParsed = triviax_parse_project_json(file_get_contents($jsonProjectPath));
        } else {
            $projectParsed = triviax_parse_questions_text(file_get_contents($questionsPath));
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No se pudo validar el proyecto del reporte.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $configuredEmail = trim($projectParsed['metadata']['mail'] ?? '');
    if ($configuredEmail === '' || strcasecmp($configuredEmail, trim($data['email'])) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'El email del reporte no coincide con el docente configurado en la actividad.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sesionId = isset($data['sesion_id']) ? (int)$data['sesion_id'] : 0;
    $isDbSession = ($sesionId > 0 && triviax_db_available());

    if ($isDbSession) {
        try {
            $pdo = triviax_db();
            // Verificar que la sesión corresponde al proyecto
            $stmtCheck = $pdo->prepare("SELECT id FROM sesiones WHERE id = ? AND proyecto_id = ?");
            $stmtCheck->execute([$sesionId, $project]);
            if (!$stmtCheck->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'La sesión no coincide con el proyecto especificado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Obtener jugadores desde la BD
            $stmtP = $pdo->prepare("
                SELECT sj.nombre_display AS name,
                       COALESCE(r.puntaje, sj.puntaje) AS score,
                       COALESCE(r.correctas, (SELECT COUNT(*) FROM intentos WHERE sesion_id = sj.sesion_id AND jugador_id = sj.id AND resultado = 'correct')) AS correct,
                       COALESCE(r.incorrectas, (SELECT COUNT(*) FROM intentos WHERE sesion_id = sj.sesion_id AND jugador_id = sj.id AND resultado != 'correct')) AS incorrect
                FROM sesion_jugadores sj
                LEFT JOIN resultados r ON r.sesion_id = sj.sesion_id AND r.jugador_id = sj.id
                WHERE sj.sesion_id = ?
                ORDER BY score DESC, sj.id ASC
            ");
            $stmtP->execute([$sesionId]);
            $data['players'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            // Obtener intentos desde la BD
            $stmtA = $pdo->prepare("
                SELECT prompt_text AS questionText, challenge_type AS challengeType,
                       resultado AS result, nombre_jugador AS playerName, orden_turno
                FROM intentos
                WHERE sesion_id = ?
                ORDER BY orden_turno ASC, id ASC
            ");
            $stmtA->execute([$sesionId]);
            $data['attempts'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error al obtener los datos del reporte desde la base de datos.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!$isDbSession && !empty($project) && preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        if (isSafePath($projectPath)) {
            $reportsDir = $projectPath . '/reportes';
            if (!is_dir($reportsDir)) {
                @mkdir($reportsDir, 0777, true);
            }
            $filename = "reporte_" . date("Ymd_His") . "_" . rand(100, 999) . ".json";
            $reportFile = $reportsDir . "/" . $filename;
            
            // Guardar si la ruta final sigue confinada
            if (isSafePath($reportFile)) {
                @file_put_contents($reportFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Correo electrónico del docente inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Escapar para el correo seguro
    $title = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');
    $date = htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8');
    $time = htmlspecialchars($data['time'], ENT_QUOTES, 'UTF-8');

    $playersHtml = '';
    foreach ($data['players'] as $p) {
        $name = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
        $score = (int)$p['score'];
        $correct = (int)$p['correct'];
        $incorrect = (int)$p['incorrect'];
        $playersHtml .= "<tr>
            <td style='padding: 8px; border: 1px solid #ddd;'>{$name}</td>
            <td style='padding: 8px; border: 1px solid #ddd; text-align: center;'>{$score}</td>
            <td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #10b981;'>{$correct}</td>
            <td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #ef4444;'>{$incorrect}</td>
        </tr>";
    }

    $attemptsHtml = '';
    foreach ($data['attempts'] as $idx => $att) {
        $num = $idx + 1;
        $qText = htmlspecialchars($att['questionText'], ENT_QUOTES, 'UTF-8');
        $pName = htmlspecialchars($att['playerName'], ENT_QUOTES, 'UTF-8');
        $res = htmlspecialchars($att['result'], ENT_QUOTES, 'UTF-8');
        $challengeType = htmlspecialchars(triviax_challenge_type_label($att['challengeType'] ?? 'multiple_choice'), ENT_QUOTES, 'UTF-8');
        
        $resColor = '#f59e0b';
        $resText = 'Incorrecto';
        if ($res === 'correct') {
            $resColor = '#10b981';
            $resText = 'Correcto';
        } elseif ($res === 'timeout') {
            $resColor = '#ef4444';
            $resText = 'Tiempo Agotado';
        }
        
        $attemptsHtml .= "<li style='margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #eee;'>
            <div style='font-size: 14px;'><strong>Pregunta {$num}:</strong> {$qText}</div>
            <div style='font-size: 13px; color: #666;'>Modalidad: <strong>{$challengeType}</strong> - Respondido por: <strong>{$pName}</strong> - Estado: <strong style='color: {$resColor};'>{$resText}</strong></div>
        </li>";
    }

    $message = "
    <html>
    <head>
        <title>Reporte de Partida - TRIVIAX</title>
    </head>
    <body style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); color: white; padding: 24px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h1 style='margin: 0; font-size: 26px; font-weight: 800;'>TRIVIAX</h1>
            <p style='margin: 5px 0 0 0; font-size: 15px;'>Reporte del Proyecto: <strong>{$title}</strong></p>
        </div>
        <div style='padding: 24px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; background: #ffffff;'>
            <p style='margin-top: 0; font-size: 14px; color: #718096;'><strong>Fecha:</strong> {$date} &nbsp;|&nbsp; <strong>Hora:</strong> {$time}</p>
            
            <h2 style='font-size: 18px; color: #2d3748; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-top: 24px;'>Puntuación Final</h2>
            <table style='width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px;'>
                <thead>
                    <tr style='background: #f7fafc; border-bottom: 2px solid #e2e8f0;'>
                        <th style='padding: 10px; text-align: left; font-size: 13px;'>Jugador</th>
                        <th style='padding: 10px; text-align: center; font-size: 13px;'>Puntos</th>
                        <th style='padding: 10px; text-align: center; font-size: 13px;'>Correctas</th>
                        <th style='padding: 10px; text-align: center; font-size: 13px;'>Incorrectas</th>
                    </tr>
                </thead>
                <tbody>
                    {$playersHtml}
                </tbody>
            </table>

            <h2 style='font-size: 18px; color: #2d3748; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-top: 24px;'>Detalle de Respuestas</h2>
            <ul style='list-style: none; padding-left: 0; margin-top: 10px;'>
                {$attemptsHtml}
            </ul>
        </div>
    </body>
    </html>
    ";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: TRIVIAX Juego <noreply@triviax.local>\r\n";

    $mailSent = @mail($email, "TRIVIAX - Reporte: " . $data['title'], $message, $headers);

    if ($mailSent) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo enviar el correo. Configure un servidor SMTP local en php.ini.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Acción: Registrar estadística en tiempo real para una pregunta
if ($action === 'save_stat') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    // Anti-abuso: limita escrituras a stats.json por IP. Cargar db.php hace
    // efectivo el límite cuando hay BD; si no la hay, falla en modo abierto.
    require_once __DIR__ . '/php/db.php';
    triviax_api_throttle('save_stat', 180, 60, 120);

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || empty($data['project']) || !isset($data['questionId']) || empty($data['result'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $project = $data['project'];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Proyecto inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statsPath = $baseProjectsDir . "/{$project}";
    if (!is_dir($statsPath) || !isSafePath($statsPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Proyecto no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statsFile = $statsPath . '/stats.json';
    if (!isSafePath($statsFile)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ruta prohibida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $qId = (string)$data['questionId'];
    if ($qId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $qId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Identificador de desafio invalido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = $data['result'];
    $challengeType = isset($data['challengeType']) && preg_match('/^[a-zA-Z0-9_ -]+$/', $data['challengeType'])
        ? $data['challengeType']
        : 'multiple_choice';
    if (!in_array($result, ['correct', 'incorrect', 'timeout'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Resultado invÃ¡lido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fp = fopen($statsFile, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $size = filesize($statsFile);
            $stats = [];
            if ($size > 0) {
                rewind($fp);
                $content = fread($fp, $size);
                $stats = json_decode($content, true) ?: [];
            }

            if (!isset($stats[$qId])) {
                $stats[$qId] = ['shown' => 0, 'correct' => 0, 'incorrect' => 0];
            }
            $stats[$qId]['type'] = $challengeType;

            $stats[$qId]['shown']++;
            if ($result === 'correct') {
                $stats[$qId]['correct']++;
            } else {
                $stats[$qId]['incorrect']++;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Acción: Restablecer estadísticas de una actividad
if ($action === 'reset_stats') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'MÃ©todo no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    if (!triviax_verify_tkey($data['tkey'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Codigo de acceso docente invÃ¡lido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $project = isset($data['project']) ? $data['project'] : '';
    if (empty($project) || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Proyecto inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statsPath = $baseProjectsDir . "/{$project}";
    if (!isSafePath($statsPath)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ruta denegada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statsFile = $statsPath . '/stats.json';
    if (file_exists($statsFile) && isSafePath($statsFile)) {
        @unlink($statsFile);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// ACCIONES v4.0 — SESIONES DE JUEGO CON BASE DE DATOS
// Todas estas acciones requieren que la BD esté disponible.
// Si no lo está, devuelven error sin afectar el modo legado.
// ══════════════════════════════════════════════════════════════════

// Acción: Unirse a una sesión por código
// El juego llama a esto al inicio de la partida (opcional).
// Registra cada jugador en sesion_jugadores y devuelve sus IDs.
if ($action === 'unirse_sesion') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    require_once __DIR__ . '/php/db.php';

    if (!triviax_db_available()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Base de datos no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Anti-abuso: limita intentos por IP (fuerza bruta de códigos / alta masiva
    // de jugadores). 60/5min tolera una clase entera tras un mismo IP escolar.
    triviax_api_throttle('unirse_sesion', 60, 300, 300);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $codigo    = strtoupper(trim((string)($input['codigo']    ?? '')));
    $jugadores = $input['jugadores'] ?? [];

    if (!preg_match('/^[A-Z0-9]{6}$/', $codigo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Código de sesión inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($jugadores) || count($jugadores) < 1 || count($jugadores) > 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Lista de jugadores inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = triviax_db();

        // Buscar la sesión activa con ese código
        $stmt = $pdo->prepare("
            SELECT id, proyecto_id, nombre, estado, max_jugadores
            FROM sesiones
            WHERE codigo_acceso = ?
            AND estado IN ('pendiente','activa')
        ");
        $stmt->execute([$codigo]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sesion) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o ya finalizada.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar que no supera el máximo
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM sesion_jugadores WHERE sesion_id = ?');
        $stmtCount->execute([$sesion['id']]);
        $yaInscritos = (int)$stmtCount->fetchColumn();

        if ($yaInscritos + count($jugadores) > (int)$sesion['max_jugadores']) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => 'La sesión no tiene lugar para todos los jugadores.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Registrar cada jugador y recopilar sus IDs + tokens.
        // Desde v4.5 se guarda solo el hash del token; el token plano se entrega una vez al navegador.
        $jugadorIds = [];
        $jugadoresSesion = [];
        $stmtIns = $pdo->prepare('
            INSERT INTO sesion_jugadores (sesion_id, usuario_id, nombre_display, player_token, estado, last_seen_at)
            VALUES (?, NULL, ?, ?, \'activo\', NOW())
        ');
        foreach ($jugadores as $nombre) {
            $nombre = mb_substr(trim((string)$nombre), 0, 100);
            if ($nombre === '') {
                $nombre = 'Jugador';
            }
            $token = bin2hex(random_bytes(32));
            $stmtIns->execute([$sesion['id'], $nombre, triviax_api_token_hash($token)]);
            $jugadorId = (int)$pdo->lastInsertId();
            $jugadorIds[] = $jugadorId;
            $jugadoresSesion[] = [
                'jugador_id' => $jugadorId,
                'nombre' => $nombre,
                'player_token' => $token,
            ];
        }

        // Activar la sesión si estaba pendiente
        if ($sesion['estado'] === 'pendiente') {
            $pdo->prepare("UPDATE sesiones SET estado = 'activa', fecha_inicio = NOW() WHERE id = ?")
                ->execute([$sesion['id']]);
        }

        echo json_encode([
            'success'       => true,
            'sesion_id'     => (int)$sesion['id'],
            'jugador_ids'   => $jugadorIds,
            'jugadores'     => $jugadoresSesion,
            'sesion_nombre' => $sesion['nombre'],
            'proyecto_id'   => $sesion['proyecto_id'],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error interno al unirse a la sesión.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Acción: Guardar un intento individual durante la partida
// Se llama en cada respuesta. Fire-and-forget desde el cliente.
if ($action === 'session_state') {
    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $sesionId = (int)($_GET['session_id'] ?? $_GET['sesion_id'] ?? 0);
    $jugadorId = (int)($_GET['jugador_id'] ?? 0);
    $playerToken = trim((string)($_GET['player_token'] ?? ''));
    $pdo = triviax_db();
    triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken);
    $pdo->prepare('UPDATE sesion_jugadores SET last_seen_at = NOW() WHERE sesion_id = ? AND id = ?')
        ->execute([$sesionId, $jugadorId]);

    $stmtS = $pdo->prepare('SELECT * FROM sesiones WHERE id = ?');
    $stmtS->execute([$sesionId]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$sesion) {
        triviax_api_error('SESSION_NOT_FOUND', 'Sesión no encontrada.', 404);
    }

    $stmtJ = $pdo->prepare('SELECT id, nombre_display, posicion, puntaje, estado, last_seen_at FROM sesion_jugadores WHERE sesion_id = ? ORDER BY id ASC');
    $stmtJ->execute([$sesionId]);
    $stmtT = $pdo->prepare('SELECT * FROM sesion_turnos WHERE sesion_id = ? AND turn_number = ? ORDER BY id DESC LIMIT 1');
    $stmtT->execute([$sesionId, (int)($sesion['current_turn_number'] ?? 0)]);
    triviax_api_success([
        'sesion' => [
            'id' => (int)$sesion['id'],
            'estado' => triviax_api_normalize_session_state((string)$sesion['estado']),
            'estado_raw' => $sesion['estado'],
            'current_turn_player_id' => isset($sesion['current_turn_player_id']) ? (int)$sesion['current_turn_player_id'] : null,
            'current_turn_number' => (int)($sesion['current_turn_number'] ?? 0),
            'updated_at' => $sesion['updated_at'] ?? null,
        ],
        'jugadores' => $stmtJ->fetchAll(PDO::FETCH_ASSOC),
        'turno' => $stmtT->fetch(PDO::FETCH_ASSOC) ?: null,
    ]);
}

if ($action === 'start_turn') {
    triviax_api_require_post();
    triviax_verify_csrf_json();
    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $input = triviax_api_input();
    $sesionId = (int)($input['sesion_id'] ?? 0);
    $jugadorId = (int)($input['jugador_id'] ?? 0);
    $playerToken = trim((string)($input['player_token'] ?? ''));
    $pdo = triviax_db();
    try {
        $pdo->beginTransaction();
        $jugador = triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken, true);
        $stmtS = $pdo->prepare('SELECT * FROM sesiones WHERE id = ? FOR UPDATE');
        $stmtS->execute([$sesionId]);
        $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
        if (!$sesion || !in_array($sesion['estado'], ['activa', 'active'], true)) {
            $pdo->rollBack();
            triviax_api_error('SESSION_CLOSED', 'La sesión no está activa.', 409);
        }
        if (!empty($sesion['current_turn_player_id']) && (int)$sesion['current_turn_player_id'] !== $jugadorId) {
            $pdo->rollBack();
            triviax_api_error('TURN_NOT_ACTIVE', 'No es el turno de este jugador.', 409);
        }
        // Expirar turnos huérfanos en pending_roll de este jugador (roll_dice nunca llegó)
        $pdo->prepare('UPDATE sesion_turnos SET estado = \'expired\' WHERE sesion_id = ? AND jugador_id = ? AND estado = \'pending_roll\'')
            ->execute([$sesionId, $jugadorId]);
        $turnNumber = ((int)($sesion['current_turn_number'] ?? 0)) + 1;
        $pdo->prepare('UPDATE sesiones SET current_turn_player_id = ?, current_turn_number = ?, started_at = COALESCE(started_at, NOW()) WHERE id = ?')
            ->execute([$jugadorId, $turnNumber, $sesionId]);
        $pdo->prepare('INSERT INTO sesion_turnos (sesion_id, jugador_id, turn_number, board_position_before, estado) VALUES (?, ?, ?, ?, \'pending_roll\')')
            ->execute([$sesionId, $jugadorId, $turnNumber, (int)($jugador['posicion'] ?? 0)]);
        $turnoId = (int)$pdo->lastInsertId();
        $pdo->commit();
        triviax_api_success(['turno_id' => $turnoId, 'turn_number' => $turnNumber]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        triviax_api_error('SERVER_ERROR', 'No se pudo iniciar el turno.', 500);
    }
}

if ($action === 'roll_dice') {
    triviax_api_require_post();
    triviax_verify_csrf_json();
    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $input = triviax_api_input();
    $sesionId = (int)($input['sesion_id'] ?? 0);
    $jugadorId = (int)($input['jugador_id'] ?? 0);
    $turnoId = (int)($input['turno_id'] ?? 0);
    $playerToken = trim((string)($input['player_token'] ?? ''));
    $diceValue = (int)($input['dice_value'] ?? random_int(1, 6));
    $challengeKey = trim((string)($input['challenge_key'] ?? ''));
    $positionAfter = isset($input['board_position_after']) ? (int)$input['board_position_after'] : null;
    if ($diceValue < 1 || $diceValue > 6) {
        triviax_api_error('VALIDATION_ERROR', 'Valor de dado inválido.', 400);
    }
    $pdo = triviax_db();
    try {
        $pdo->beginTransaction();
        triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken, true);
        $stmtT = $pdo->prepare('SELECT * FROM sesion_turnos WHERE id = ? AND sesion_id = ? AND jugador_id = ? FOR UPDATE');
        $stmtT->execute([$turnoId, $sesionId, $jugadorId]);
        $turno = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$turno || $turno['estado'] !== 'pending_roll') {
            $pdo->rollBack();
            triviax_api_error('TURN_NOT_ACTIVE', 'El turno no está esperando tirada.', 409);
        }
        $estado = $challengeKey !== '' ? 'challenge_assigned' : 'skipped';
        $pdo->prepare('UPDATE sesion_turnos SET dice_value = ?, board_position_after = ?, challenge_key = ?, estado = ? WHERE id = ?')
            ->execute([$diceValue, $positionAfter, $challengeKey !== '' ? $challengeKey : null, $estado, $turnoId]);
        $pdo->commit();
        triviax_api_success(['turno_id' => $turnoId, 'dice_value' => $diceValue, 'estado' => $estado]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        triviax_api_error('SERVER_ERROR', 'No se pudo registrar la tirada.', 500);
    }
}

if ($action === 'submit_answer') {
    triviax_api_require_post();
    triviax_verify_csrf_json();
    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $input = triviax_api_input();
    $sesionId = (int)($input['sesion_id'] ?? 0);
    $jugadorId = (int)($input['jugador_id'] ?? 0);
    $turnoId = (int)($input['turno_id'] ?? 0);
    $playerToken = trim((string)($input['player_token'] ?? ''));
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    $challengeKey = mb_substr(trim((string)($input['challenge_key'] ?? '')), 0, 100);
    $resultado = trim((string)($input['resultado'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $idempotencyKey) || $challengeKey === '' || !in_array($resultado, ['correct','incorrect','timeout'], true)) {
        triviax_api_error('VALIDATION_ERROR', 'Respuesta inválida.', 400);
    }
    $pdo = triviax_db();
    try {
        $pdo->beginTransaction();
        triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken, true);
        $stmtReplay = $pdo->prepare('SELECT id FROM intentos WHERE idempotency_key = ? LIMIT 1');
        $stmtReplay->execute([$idempotencyKey]);
        if ($stmtReplay->fetch()) {
            $pdo->commit();
            triviax_api_success(['cached' => true], 'Respuesta ya registrada.');
        }
        $stmtT = $pdo->prepare('SELECT * FROM sesion_turnos WHERE id = ? AND sesion_id = ? AND jugador_id = ? FOR UPDATE');
        $stmtT->execute([$turnoId, $sesionId, $jugadorId]);
        $turno = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$turno || $turno['estado'] !== 'challenge_assigned' || (string)$turno['challenge_key'] !== $challengeKey) {
            $pdo->rollBack();
            triviax_api_error('TURN_NOT_ACTIVE', 'La respuesta no corresponde al turno activo.', 409);
        }
        $pointsDelta = (int)($input['points_delta'] ?? 0);
        $timeMs = isset($input['time_ms']) ? (int)$input['time_ms'] : null;
        // Posición final: el cliente la envía tras resolver todas las animaciones y casillas especiales
        $boardPositionAfter = isset($input['board_position_after']) ? (int)$input['board_position_after'] : null;
        $pdo->prepare('
            INSERT INTO intentos
                (sesion_id, jugador_id, turno_id, challenge_key, prompt_text, challenge_type,
                 answer_payload, resultado, points_delta, time_ms, idempotency_key,
                 orden_turno, nombre_jugador)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $sesionId,
            $jugadorId,
            $turnoId,
            $challengeKey,
            mb_substr(trim((string)($input['prompt_text'] ?? '')), 0, 1000),
            mb_substr(trim((string)($input['challenge_type'] ?? 'multiple_choice')), 0, 50),
            json_encode($input['answer_payload'] ?? null, JSON_UNESCAPED_UNICODE),
            $resultado,
            $pointsDelta,
            $timeMs,
            $idempotencyKey,
            (int)($turno['turn_number'] ?? 0),
            mb_substr(trim((string)($input['nombre_jugador'] ?? '')), 0, 100),
        ]);
        $pdo->prepare('UPDATE sesion_jugadores SET puntaje = puntaje + ?, posicion = COALESCE(?, posicion), last_seen_at = NOW() WHERE sesion_id = ? AND id = ?')
            ->execute([$pointsDelta, $boardPositionAfter, $sesionId, $jugadorId]);
        $pdo->prepare('UPDATE sesion_turnos SET estado = \'answered\', answered_at = NOW(), board_position_after = COALESCE(?, board_position_after) WHERE id = ?')
            ->execute([$boardPositionAfter, $turnoId]);
        $pdo->commit();
        triviax_api_success(['turno_id' => $turnoId, 'points_delta' => $pointsDelta]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        triviax_api_error('SERVER_ERROR', 'No se pudo registrar la respuesta.', 500);
    }
}

if ($action === 'end_turn') {
    triviax_api_require_post();
    triviax_verify_csrf_json();
    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        triviax_api_error('SERVER_ERROR', 'Base de datos no disponible.', 503);
    }
    $input = triviax_api_input();
    $sesionId = (int)($input['sesion_id'] ?? 0);
    $jugadorId = (int)($input['jugador_id'] ?? 0);
    $turnoId = (int)($input['turno_id'] ?? 0);
    $playerToken = trim((string)($input['player_token'] ?? ''));
    $pdo = triviax_db();
    try {
        $pdo->beginTransaction();
        triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken, true);
        $stmtT = $pdo->prepare('SELECT * FROM sesion_turnos WHERE id = ? AND sesion_id = ? AND jugador_id = ? FOR UPDATE');
        $stmtT->execute([$turnoId, $sesionId, $jugadorId]);
        $turno = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$turno || !in_array($turno['estado'], ['answered', 'skipped', 'expired'], true)) {
            $pdo->rollBack();
            triviax_api_error('TURN_NOT_ACTIVE', 'El turno no puede finalizarse todavía.', 409);
        }
        $stmtNext = $pdo->prepare('SELECT id FROM sesion_jugadores WHERE sesion_id = ? AND estado IN (\'activo\', \'esperando\', \'joined\', \'active\') AND id > ? ORDER BY id ASC LIMIT 1');
        $stmtNext->execute([$sesionId, $jugadorId]);
        $nextId = $stmtNext->fetchColumn();
        if (!$nextId) {
            $stmtNext = $pdo->prepare('SELECT id FROM sesion_jugadores WHERE sesion_id = ? AND estado IN (\'activo\', \'esperando\', \'joined\', \'active\') ORDER BY id ASC LIMIT 1');
            $stmtNext->execute([$sesionId]);
            $nextId = $stmtNext->fetchColumn();
        }
        $pdo->prepare('UPDATE sesion_turnos SET estado = \'completed\' WHERE id = ?')->execute([$turnoId]);
        $pdo->prepare('UPDATE sesiones SET current_turn_player_id = ? WHERE id = ?')->execute([$nextId ?: null, $sesionId]);
        $pdo->commit();
        triviax_api_success(['next_player_id' => $nextId ? (int)$nextId : null]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        triviax_api_error('SERVER_ERROR', 'No se pudo finalizar el turno.', 500);
    }
}

if ($action === 'guardar_intento') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        echo json_encode(['success' => false, 'error' => 'BD no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $sesionId      = (int)($input['sesion_id']      ?? 0);
    $jugadorId     = (int)($input['jugador_id']     ?? 0);
    $challengeKey  = mb_substr(trim((string)($input['challenge_key']  ?? '')), 0, 100);
    $promptText    = mb_substr(trim((string)($input['prompt_text']    ?? '')), 0, 1000);
    $challengeType = mb_substr(trim((string)($input['challenge_type'] ?? 'multiple_choice')), 0, 50);
    $resultado     = trim((string)($input['resultado'] ?? ''));
    $ordenTurno    = (int)($input['orden_turno'] ?? 0);
    $nombreJugador = mb_substr(trim((string)($input['nombre_jugador'] ?? '')), 0, 100);
    $playerToken   = trim((string)($input['player_token'] ?? ''));
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    $answerPayload = $input['answer_payload'] ?? null;

    if ($sesionId <= 0 || $jugadorId <= 0 || $challengeKey === ''
        || !in_array($resultado, ['correct','incorrect','timeout'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos de intento inválidos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = triviax_db();

        // Verificar que el jugador pertenece a la sesión
        if ($idempotencyKey !== '') {
            if (!preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $idempotencyKey)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Clave de idempotencia inválida.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmtReplay = $pdo->prepare('SELECT id FROM intentos WHERE idempotency_key = ? LIMIT 1');
            $stmtReplay->execute([$idempotencyKey]);
            if ($stmtReplay->fetch()) {
                echo json_encode(['success' => true, 'cached' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if ($playerToken !== '') {
            triviax_api_validate_player($pdo, $sesionId, $jugadorId, $playerToken);
        }

        $stmtV = $pdo->prepare('SELECT id FROM sesion_jugadores WHERE id = ? AND sesion_id = ?');
        $stmtV->execute([$jugadorId, $sesionId]);
        if (!$stmtV->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Jugador no pertenece a esta sesión.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare('
            INSERT INTO intentos
                (sesion_id, jugador_id, challenge_key, prompt_text, challenge_type,
                 answer_payload, resultado, points_delta, time_ms, idempotency_key,
                 orden_turno, nombre_jugador)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sesionId, $jugadorId, $challengeKey, $promptText,
            $challengeType,
            $answerPayload !== null ? json_encode($answerPayload, JSON_UNESCAPED_UNICODE) : null,
            $resultado,
            (int)($input['points_delta'] ?? 0),
            isset($input['time_ms']) ? (int)$input['time_ms'] : null,
            $idempotencyKey !== '' ? $idempotencyKey : null,
            $ordenTurno, $nombreJugador
        ]);

        // Actualizar stats_desafios acumulado (igual que save_stat pero en BD)
        $pdo->prepare("
            INSERT INTO stats_desafios (proyecto_id, challenge_key, challenge_type, shown, correct, incorrect)
            SELECT s.proyecto_id, ?, ?, 1,
                   IF(? = 'correct', 1, 0),
                   IF(? != 'correct', 1, 0)
            FROM sesiones s WHERE s.id = ?
            ON DUPLICATE KEY UPDATE
                shown    = shown + 1,
                correct  = correct  + IF(? = 'correct', 1, 0),
                incorrect= incorrect+ IF(? != 'correct', 1, 0)
        ")->execute([
            $challengeKey, $challengeType,
            $resultado, $resultado, $sesionId,
            $resultado, $resultado
        ]);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al guardar el intento.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Acción: Guardar resultados finales al terminar la partida
// Se llama una única vez cuando el juego termina.
if ($action === 'guardar_resultados') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    triviax_verify_csrf_json();

    require_once __DIR__ . '/php/db.php';
    if (!triviax_db_available()) {
        echo json_encode(['success' => false, 'error' => 'BD no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Anti-abuso: limita por IP la enumeración/sobrescritura de sesiones.
    triviax_api_throttle('guardar_resultados', 60, 300, 300);

    $input     = json_decode(file_get_contents('php://input'), true) ?: [];
    $sesionId  = (int)($input['sesion_id']  ?? 0);
    $resultados = $input['resultados'] ?? [];
    $playerToken = trim((string)($input['player_token'] ?? ''));

    if ($sesionId <= 0 || strlen($playerToken) < 32 || !is_array($resultados) || count($resultados) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos de resultados inválidos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = triviax_db();

        // Verificar que la sesión existe y está activa
        $stmtS = $pdo->prepare("SELECT id FROM sesiones WHERE id = ? AND estado = 'activa'");
        $stmtS->execute([$sesionId]);
        if (!$stmtS->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Sesión no encontrada o no activa.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Identidad: el llamante debe poseer un token de jugador de ESTA sesión.
        // Evita que un tercero (con solo el sesion_id) inyecte puntajes o finalice
        // partidas ajenas. El token se guarda hasheado en unirse_sesion.
        $stmtAuth = $pdo->prepare('SELECT 1 FROM sesion_jugadores WHERE sesion_id = ? AND player_token = ? LIMIT 1');
        $stmtAuth->execute([$sesionId, triviax_api_token_hash($playerToken)]);
        if (!$stmtAuth->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No autorizado para esta sesión.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Propiedad: solo se aceptan jugador_id que pertenezcan a la sesión.
        $stmtJ = $pdo->prepare('SELECT id FROM sesion_jugadores WHERE sesion_id = ?');
        $stmtJ->execute([$sesionId]);
        $validJugadorIds = array_map('intval', $stmtJ->fetchAll(PDO::FETCH_COLUMN));

        $stmtR = $pdo->prepare('
            INSERT INTO resultados (sesion_id, jugador_id, puntaje, correctas, incorrectas, posicion)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                puntaje    = VALUES(puntaje),
                correctas  = VALUES(correctas),
                incorrectas= VALUES(incorrectas),
                posicion   = VALUES(posicion)
        ');

        foreach ($resultados as $r) {
            $jugadorId  = (int)($r['jugador_id']  ?? 0);
            $puntaje    = (int)($r['puntaje']      ?? 0);
            $correctas  = (int)($r['correctas']    ?? 0);
            $incorrectas= (int)($r['incorrectas']  ?? 0);
            $posicion   = isset($r['posicion']) ? (int)$r['posicion'] : null;

            if ($jugadorId <= 0 || !in_array($jugadorId, $validJugadorIds, true)) continue;
            $stmtR->execute([$sesionId, $jugadorId, $puntaje, $correctas, $incorrectas, $posicion]);
        }

        // Marcar la sesión como finalizada
        $pdo->prepare("UPDATE sesiones SET estado = 'finalizada', fecha_fin = NOW() WHERE id = ?")
            ->execute([$sesionId]);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al guardar los resultados.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
// MODALIDAD "ESTUDIA Y RESPONDE" (study_*) — handlers en archivo propio
// para no engordar este despachador. Cada handler responde y termina.
// ══════════════════════════════════════════════════════════════════
if (strpos($action, 'study_') === 0) {
    require_once __DIR__ . '/php/study_answer_api.php';
    triviax_study_api_handle($action);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// MODALIDAD "TRIVIAX LOTTO" (lotto_*) — handlers en archivo propio.
// ══════════════════════════════════════════════════════════════════
if (strpos($action, 'lotto_') === 0) {
    require_once __DIR__ . '/php/lotto_api.php';
    triviax_lotto_api_handle($action);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// MODALIDAD "PUZLE" (jigsaw_*) — actividad con imágenes, fuera del tablero.
// ══════════════════════════════════════════════════════════════════
if (strpos($action, 'jigsaw_') === 0) {
    require_once __DIR__ . '/php/jigsaw_api.php';
    triviax_jigsaw_api_handle($action);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// MODALIDAD "ETIQUETAR" (etiquetar_*) — actividad con imágenes.
// ══════════════════════════════════════════════════════════════════
if (strpos($action, 'etiquetar_') === 0) {
    require_once __DIR__ . '/php/etiquetar_api.php';
    triviax_etiquetar_api_handle($action);
    exit;
}

// ══════════════════════════════════════════════════════════════════

http_response_code(400);
echo json_encode([
    'success' => false,
    'error' => 'Acción inválida o faltan parámetros.'
], JSON_UNESCAPED_UNICODE);
exit;

/**
 * Valida la estructura básica de una pregunta del .txt
 */
function validateQuestion($q) {
    $count = count($q['answers']);
    if ($count < 3 || $count > 4) {
        return "La pregunta {$q['id']} tiene {$count} respuestas. Debe tener entre 3 y 4 respuestas.";
    }
    
    $correctCount = 0;
    foreach ($q['answers'] as $ans) {
        if ($ans['correct']) {
            $correctCount++;
        }
    }
    
    if ($correctCount === 0) {
        return "La pregunta {$q['id']} no tiene una respuesta correcta marcada.";
    }
    
    if ($correctCount > 1) {
        return "La pregunta {$q['id']} tiene más de una respuesta correcta.";
    }
    
    return null;
}
