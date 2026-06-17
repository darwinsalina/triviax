<?php
/**
 * TRIVIAX - Panel de Administración Docente
 * Permite crear nuevas actividades, subir preguntas y fondos,
 * y copiar el prompt de IA para generar baterías de preguntas.
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

// Conexión BD y helper de sync (opcionales: el panel funciona sin BD)
$pdo       = null;
$docenteId = (int)($_SESSION['triviax_user_id'] ?? 0) ?: null;
try {
    require_once __DIR__ . '/php/db.php';
    require_once __DIR__ . '/php/project_sync.php';
    $pdo = triviax_db();
} catch (\Throwable $_bdErr) {
    $pdo = null;
}

// Iniciar sesión para validar CSRF (triviax_requerir_auth ya inicia sesión,
// pero lo dejamos por compatibilidad con el resto del archivo)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generar Token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Cambiar zona horaria para la fecha automática
date_default_timezone_set('America/Argentina/Buenos_Aires');

$errorMsg = '';
$successMsg = '';
$createdActivityName = '';
$formValues = [
    'title' => '',
    'author' => '',
    'nivel' => '',
    'obs' => '',
    'mail' => '',
    'activity_name' => '',
    'questions_text' => '',
    'activity_json' => '',
    'board_locked_id' => ''
];

function triviax_prepare_ai_json_text($content) {
    $content = trim((string)$content);
    if ($content === '') {
        return '';
    }

    $hadCodeFence = false;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $matches)) {
        $content = trim($matches[1]);
        $hadCodeFence = true;
    }

    if ($hadCodeFence || preg_match('/^\s*[\{\[]/', $content)) {
        $firstBrace = strpos($content, '{');
        $firstBracket = strpos($content, '[');
        $starts = array_filter([$firstBrace, $firstBracket], function($pos) { return $pos !== false; });
        if (!empty($starts)) {
            $start = min($starts);
            $lastBrace = strrpos($content, '}');
            $lastBracket = strrpos($content, ']');
            $end = max($lastBrace === false ? -1 : $lastBrace, $lastBracket === false ? -1 : $lastBracket);
            if ($end >= $start) {
                $content = substr($content, $start, $end - $start + 1);
            }
        }
    }

    return trim($content);
}

function triviax_decode_ai_project_json($content) {
    $jsonText = triviax_prepare_ai_json_text($content);
    if ($jsonText === '' || !preg_match('/^\s*[\{\[]/', $jsonText)) {
        return null;
    }

    $decoded = json_decode($jsonText, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new Exception('El texto pegado parece JSON, pero no es valido: ' . json_last_error_msg());
    }

    return $decoded;
}

function triviax_is_list_array($value) {
    if (!is_array($value)) {
        return false;
    }
    if ($value === []) {
        return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function triviax_generate_unique_activity_folder($title) {
    $baseName = sanitizeActivityName($title);
    if ($baseName === '') {
        $baseName = 'actividad';
    }

    $baseName = substr($baseName, 0, 42);
    $baseName = trim($baseName, '_-');
    if ($baseName === '') {
        $baseName = 'actividad';
    }

    for ($i = 0; $i < 25; $i++) {
        $suffix = date('ymd') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $candidate = $baseName . '_' . $suffix;
        $folderPath = __DIR__ . '/proyectos/' . $candidate;
        if (!is_dir($folderPath)) {
            return $candidate;
        }
    }

    return 'actividad_' . date('ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function triviax_build_project_from_ai_json($decodedProject, $metadata) {
    $sourceMetadata = $decodedProject['metadata'] ?? [];
    $challenges = $decodedProject['challenges'] ?? ($decodedProject['questions'] ?? null);

    if ($challenges === null && triviax_is_list_array($decodedProject)) {
        $challenges = $decodedProject;
    }

    $project = [
        'metadata' => array_merge(
            [
                'title' => $metadata['title'],
                'author' => $metadata['author'],
                'nivel' => $metadata['nivel'],
                'obs' => $metadata['obs'],
                'date' => date("d/m/Y"),
                'mail' => $metadata['mail'],
                'background' => 'fondo.jpg'
            ],
            is_array($sourceMetadata) ? $sourceMetadata : [],
            [
                'title' => $metadata['title'],
                'author' => $metadata['author'],
                'nivel' => $metadata['nivel'],
                'obs' => $metadata['obs'],
                'date' => date("d/m/Y"),
                'mail' => $metadata['mail'],
                'background' => 'fondo.jpg'
            ]
        ),
        'board' => $decodedProject['board'] ?? ['type' => 'serpentine'],
        'challenges' => is_array($challenges) ? $challenges : []
    ];

    $project = triviax_normalize_project_json_data($project);
    triviax_validate_project_json_data($project);
    return $project;
}

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

// Procesar el POST de creación de actividad
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_activity') {
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMsg = 'Error de seguridad: Token CSRF inválido o vencido.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $nivel = trim($_POST['nivel'] ?? '');
        $obs = trim($_POST['obs'] ?? '');
        $mail = trim($_POST['mail'] ?? '');
        $activityName = '';
        
        $questionsText = trim($_POST['questions_text'] ?? '');
        $activityJsonText = trim($_POST['activity_json'] ?? '');
        $formValues = [
            'title' => $title,
            'author' => $author,
            'nivel' => $nivel,
            'obs' => $obs,
            'mail' => $mail,
            'activity_name' => '',
            'questions_text' => $questionsText,
            'activity_json' => $activityJsonText,
            'board_locked_id' => trim($_POST['board_locked_id'] ?? '')
        ];
    
    // Validaciones obligatorias
    if (empty($title) || empty($author)) {
        $errorMsg = 'El título de la actividad y el nombre del autor son obligatorios.';
    } elseif (empty($questionsText) && empty($activityJsonText)) {
        $errorMsg = 'Debes ingresar el banco de preguntas o crear una actividad mixta desde el formulario.';
    } else {
        // Generar nombre de la carpeta si no se especificó
        $activityName = triviax_generate_unique_activity_folder($title);
        
        $folderPath = __DIR__ . '/proyectos/' . $activityName;
        
        if (!isSafePath($folderPath)) {
            $errorMsg = 'Nombre de carpeta de actividad prohibido (Path Traversal detectado).';
        } elseif (is_dir($folderPath)) {
            $errorMsg = "La actividad '{$activityName}' ya existe. Elige otro nombre de carpeta.";
        } else {
            $parsedMixedProject = null;
            $metadataForProject = compact('title', 'author', 'nivel', 'obs', 'mail');
            try {
                if (!empty($activityJsonText)) {
                    $decodedMixedProject = triviax_decode_ai_project_json($activityJsonText);
                    if ($decodedMixedProject === null) {
                        throw new Exception('La actividad mixta generada no contiene JSON valido.');
                    }
                    $parsedMixedProject = triviax_build_project_from_ai_json($decodedMixedProject, $metadataForProject);
                } elseif (!empty($questionsText)) {
                    $decodedFromTextarea = triviax_decode_ai_project_json($questionsText);
                    if ($decodedFromTextarea !== null) {
                        $parsedMixedProject = triviax_build_project_from_ai_json($decodedFromTextarea, $metadataForProject);
                        $activityJsonText = json_encode($parsedMixedProject, JSON_UNESCAPED_UNICODE);
                        $questionsText = '';
                        $formValues['questions_text'] = '';
                        $formValues['activity_json'] = $activityJsonText;
                    }
                }
            } catch (Exception $e) {
                $errorMsg = 'Error de formato en la actividad generada por IA: ' . $e->getMessage();
            }

            // Leer preguntas de la caja de texto
            $rawQuestions = $questionsText;
            
            // Limpiar cabeceras anteriores si las tuviera
            $cleanedQuestions = $parsedMixedProject === null ? triviax_clean_uploaded_questions($rawQuestions) : '';
            
            // Validar la estructura del banco de preguntas
            $parserError = null;
            if ($parsedMixedProject === null && empty($errorMsg)) {
                try {
                    triviax_parse_questions_text($cleanedQuestions);
                } catch (Exception $e) {
                    $parserError = $e->getMessage();
                }
            }
            
            if ($parserError || !empty($errorMsg)) {
                if ($parserError) {
                    $errorMsg = "Error de formato en las preguntas: " . $parserError;
                }
            } else {
                // Intentar crear la carpeta
                if (!mkdir($folderPath, 0777, true)) {
                    $errorMsg = 'No se pudo crear el directorio de la actividad en el servidor. Verifique los permisos de escritura.';
                } else {
                    // Armar la cabecera
                    $date = date("d/m/Y");
                    if ($parsedMixedProject !== null) {
                        file_put_contents(
                            $folderPath . '/proyecto.json',
                            json_encode($parsedMixedProject, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                    } else {
                        $header = "TITLE: " . $title . "\r\n";
                        $header .= "AUTHOR: " . $author . "\r\n";
                        if (!empty($nivel)) $header .= "NIVEL: " . $nivel . "\r\n";
                        if (!empty($obs)) $header .= "OBS: " . $obs . "\r\n";
                        $header .= "DATE: " . $date . "\r\n";
                        if (!empty($mail)) $header .= "MAIL: " . $mail . "\r\n";
                        $header .= "\r\n"; // Fila en blanco
                        
                        $fullContent = $header . $cleanedQuestions;
                        
                        // Escribir preguntas.txt
                        file_put_contents($folderPath . '/preguntas.txt', $fullContent);
                    }
                    
                    // Procesar la imagen de fondo
                    $bgUploaded = false;
                    if (isset($_FILES['background_file']) && $_FILES['background_file']['error'] === UPLOAD_ERR_OK) {
                        $imgTmp = $_FILES['background_file']['tmp_name'];
                        $bgFile = optimizeAndSaveBackgroundToJpg($imgTmp, $folderPath);
                        if ($bgFile !== false) {
                            $bgUploaded = true;
                        }
                    }
                    
                    // Si no se subió una imagen, usar la predeterminada del sistema
                    if (!$bgUploaded) {
                        if (file_exists(__DIR__ . '/images/background.jpg')) {
                            @copy(__DIR__ . '/images/background.jpg', $folderPath . '/fondo.jpg');
                        }
                    }

                    // Tablero fijado por la actividad (opcional)
                    triviax_guardar_tablero_actividad($folderPath, $_POST['board_locked_id'] ?? '');

                    // Notificar al administrador configurado (ADMIN_EMAIL en triviax.env)
                    $mailTo = triviax_admin_email();
                    $mailSubject = 'TRIVIAX - Nueva actividad creada: ' . $title;
                    $mailMessage = "Se ha creado una nueva actividad en TRIVIAX.\r\n\r\n";
                    $mailMessage .= "Detalles:\r\n";
                    $mailMessage .= "----------------------------------------\r\n";
                    $mailMessage .= "Título: {$title}\r\n";
                    $mailMessage .= "Autor: {$author}\r\n";
                    $mailMessage .= "Nivel/Curso: {$nivel}\r\n";
                    $mailMessage .= "Observaciones: {$obs}\r\n";
                    $mailMessage .= "Fecha: {$date}\r\n";
                    $mailMessage .= "Email Autor: {$mail}\r\n";
                    $mailMessage .= "Carpeta Destino: proyectos/{$activityName}/\r\n";
                    
                    $mailHeaders = "From: TRIVIAX Plataforma <noreply@triviax.local>\r\n" .
                                   "Reply-To: " . (!empty($mail) ? $mail : 'noreply@triviax.local') . "\r\n" .
                                   "X-Mailer: PHP/" . phpversion();
                    
                    @mail($mailTo, $mailSubject, $mailMessage, $mailHeaders);
                    
                    $successMsg = "¡La actividad se ha creado correctamente!";
                    $createdActivityName = $activityName;

                    // Registrar la nueva actividad en la BD
                    if ($pdo !== null) {
                        try {
                            triviax_sync_single_project($pdo, $activityName, $folderPath, false, $docenteId);
                        } catch (\Throwable $_) {}
                    }
                }
            }
        }
    }
}
}

// ──────────────────────────────────────────────────────────────
// Sincronización masiva filesystem → BD
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_all_projects') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($pdo === null) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Base de datos no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $proyDir  = __DIR__ . '/proyectos';
    $trashDir = __DIR__ . '/trash';
    $result   = triviax_sync_all_projects($pdo, $proyDir, $trashDir, $docenteId);

    echo json_encode(['success' => true, 'stats' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// Procesar el POST de eliminación de actividad (mover a papelera)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_activity') {
    header("Content-Type: application/json; charset=utf-8");
    
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Error de seguridad: Token CSRF inválido o vencido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $project = trim($_POST['project'] ?? '');
    if (empty($project) || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nombre de proyecto inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $projectPath = __DIR__ . '/proyectos/' . $project;
    if (!isSafePath($projectPath) || !is_dir($projectPath) || realpath($projectPath) === realpath(__DIR__ . '/proyectos')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ruta de proyecto inválida o prohibida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Papelera en triviax/trash/ (fuera de proyectos/)
    $trashBaseDir = __DIR__ . '/trash';
    if (!is_dir($trashBaseDir)) {
        @mkdir($trashBaseDir, 0777, true);
    }
    $trashPath = $trashBaseDir . '/' . $project;
    // Si ya existe en papelera, agregar sufijo de fecha para no pisar
    if (is_dir($trashPath)) {
        $trashPath = $trashBaseDir . '/' . $project . '_' . date('Ymd_His');
    }

    // Validar confinamiento de la ruta de destino
    $baseTrashReal = realpath($trashBaseDir);
    if ($baseTrashReal === false || strpos(realpath(dirname($trashPath)) ?: $trashBaseDir, $baseTrashReal) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ruta de papelera inválida o prohibida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (@rename($projectPath, $trashPath)) {
        // Marcar en BD como papelera (sin bloquear la respuesta si falla)
        if ($pdo !== null) {
            try { triviax_mark_project_trashed($pdo, $project); } catch (\Throwable $_) {}
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo trasladar la carpeta del proyecto a la papelera.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ──────────────────────────────────────────────────────────────
// Guardar edición de actividad existente
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_activity') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $project = trim($_POST['project'] ?? '');
    if (empty($project) || !preg_match('/^[a-zA-Z0-9_-]+$/', $project)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nombre de proyecto inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectPath = __DIR__ . '/proyectos/' . $project;
    if (!isSafePath($projectPath) || !is_dir($projectPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Proyecto no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $title   = trim($_POST['title']   ?? '');
    $author  = trim($_POST['author']  ?? '');
    $nivel   = trim($_POST['nivel']   ?? '');
    $obs     = trim($_POST['obs']     ?? '');
    $mail    = trim($_POST['mail']    ?? '');
    $jsonStr = trim($_POST['activity_json'] ?? '');

    if (empty($title) || empty($author)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El título y el autor son obligatorios.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded) || !isset($decoded['challenges'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON de actividad inválido o sin array "challenges".'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validar estructura de los challenges con el validador central
    require_once __DIR__ . '/php/challenge_validator.php';
    // Construir un proyecto temporal con la metadata necesaria para el validador
    $tempProject = [
        'metadata'   => ['title' => $title],
        'board'      => ['type'  => 'serpentine'],
        'challenges' => $decoded['challenges'],
    ];
    $validationErrors = triviax_validate_project($tempProject);
    if (!empty($validationErrors)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => 'La actividad tiene errores de validación.',
            'details' => $validationErrors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Leer proyecto existente para preservar campos no editables
    $jsonPath = $projectPath . '/proyecto.json';
    $existing = [];
    if (file_exists($jsonPath)) {
        $existing = json_decode(file_get_contents($jsonPath), true) ?? [];
    }

    $updated = [
        'metadata' => array_merge(
            $existing['metadata'] ?? [],
            [
                'title'      => $title,
                'author'     => $author,
                'nivel'      => $nivel,
                'obs'        => $obs,
                'mail'       => $mail,
                'date'       => $existing['metadata']['date'] ?? date('d/m/Y'),
                'background' => $existing['metadata']['background'] ?? 'fondo.jpg',
            ]
        ),
        'board'      => $existing['board'] ?? ['type' => 'serpentine'],
        'challenges' => $decoded['challenges'],
    ];

    $saved = file_put_contents(
        $jsonPath,
        json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    if ($saved === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo escribir el archivo del proyecto.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Tablero fijado por la actividad (solo si el formulario lo envía).
    if (isset($_POST['board_locked_id'])) {
        triviax_guardar_tablero_actividad($projectPath, $_POST['board_locked_id']);
    }

    // Sincronizar metadatos actualizados con la BD
    if ($pdo !== null) {
        try { triviax_sync_single_project($pdo, $project, $projectPath, false); } catch (\Throwable $_) {}
    }

    echo json_encode(['success' => true, 'title' => $title], JSON_UNESCAPED_UNICODE);
    exit;
}

// Optimizar y guardar imagen a formato JPG exclusivamente
/**
 * Lista los tableros personalizados disponibles (archivos JSON del generador
 * visual en images/tableros/). Devuelve [{id, label}, ...].
 */
function triviax_listar_tableros_disponibles() {
    $out = [];
    $dir = __DIR__ . '/images/tableros';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') as $file) {
            $j = json_decode(@file_get_contents($file), true);
            if (is_array($j) && !empty($j['id'])) {
                $out[] = ['id' => (string)$j['id'], 'label' => (string)($j['label'] ?? $j['id'])];
            }
        }
    }
    return $out;
}

/**
 * Persiste (o elimina) el sidecar board.json que fija el tablero de la actividad.
 * Lo lee api.php al servir el proyecto y lo consume el juego (board.lockedId).
 * Valida que el id corresponda a un tablero existente.
 */
function triviax_guardar_tablero_actividad($folderPath, $lockedBoardIdRaw) {
    $lockedBoardId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$lockedBoardIdRaw);
    $sidecar = $folderPath . '/board.json';
    if ($lockedBoardId !== '' && in_array($lockedBoardId, array_column(triviax_listar_tableros_disponibles(), 'id'), true)) {
        file_put_contents($sidecar, json_encode(['lockedId' => $lockedBoardId], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } elseif (is_file($sidecar)) {
        @unlink($sidecar); // se volvió a "estándar": quitar el bloqueo
    }
}

function optimizeAndSaveBackgroundToJpg($sourcePath, $destFolder) {
    $destPath = $destFolder . '/fondo.jpg';
    
    if (extension_loaded('gd')) {
        $info = getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }
        
        $mime = $info['mime'];
        $image = null;
        
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($sourcePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($sourcePath);
                break;
        }
        
        if ($image) {
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);
            
            // Redimensionar proporcionalmente si supera 1920x1080
            $maxWidth = 1920;
            $maxHeight = 1080;
            $width = $origWidth;
            $height = $origHeight;
            
            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $width = round($width * $ratio);
                $height = round($height * $ratio);
                
                $resizedImage = imagecreatetruecolor($width, $height);
                
                // Lienzo blanco de fondo para aplanar transparencias
                $white = imagecolorallocate($resizedImage, 255, 255, 255);
                imagefill($resizedImage, 0, 0, $white);
                
                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
                imagedestroy($image);
                $image = $resizedImage;
            } else {
                // Aplanar transparencias de la imagen original a fondo blanco
                $flattenedImage = imagecreatetruecolor($origWidth, $origHeight);
                $white = imagecolorallocate($flattenedImage, 255, 255, 255);
                imagefill($flattenedImage, 0, 0, $white);
                imagecopy($flattenedImage, $image, 0, 0, 0, 0, $origWidth, $origHeight);
                imagedestroy($image);
                $image = $flattenedImage;
            }
            
            // Guardar como JPG con calidad 80
            $success = @imagejpeg($image, $destPath, 80);
            imagedestroy($image);
            
            if ($success) {
                return 'fondo.jpg';
            }
        }
    }
    
    // Fallback si GD no está cargado
    if (@move_uploaded_file($sourcePath, $destPath)) {
        return 'fondo.jpg';
    }
    
    return false;
}

// Sanitizar nombre de carpeta
function sanitizeActivityName($name) {
    $name = trim((string)$name);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($converted !== false) {
            $name = $converted;
        }
    }
    $name = preg_replace('/[^a-zA-Z0-9]+/', '_', $name);
    $name = trim($name, '_');
    $name = strtolower($name);
    return $name;
}

// Quitar metadatos del archivo de preguntas subido
function cleanUploadedQuestions($content) {
    if (substr($content, 0, 3) === pack("CCC", 0xef, 0xbb, 0xbf)) {
        $content = substr($content, 3);
    }
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $cleanedLines = [];
    $firstQuestionFound = false;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!$firstQuestionFound) {
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(title|author|nivel|obs|date|mail|id)\s*:\s*(.*)$/i', $trimmed)) {
                continue;
            }
            if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed)) {
                $firstQuestionFound = true;
            }
        }
        $cleanedLines[] = $line;
    }
    return implode("\r\n", $cleanedLines);
}

// Validar consistencia de preguntas
function validateUploadedQuestions($content) {
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $questions = [];
    $currentQuestion = null;
    $parsingQuestionsStarted = false;
    
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^#+\s*/', $trimmed)) {
            continue;
        }
        
        if (preg_match('/^(\d+)\.\s*(.*)$/', $trimmed, $matches)) {
            $parsingQuestionsStarted = true;
            if ($currentQuestion !== null) {
                $err = checkQuestion($currentQuestion);
                if ($err) return $err;
                $questions[] = $currentQuestion;
            }
            $currentQuestion = [
                'id' => (int)$matches[1],
                'text' => trim($matches[2]),
                'answers' => []
            ];
            continue;
        }
        
        if (strpos($trimmed, '@') === 0) {
            if ($currentQuestion === null) {
                return "Se detectó una respuesta sin pregunta asociada en la línea {$lineNumber}.";
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
        }
    }
    
    if ($currentQuestion !== null) {
        $err = checkQuestion($currentQuestion);
        if ($err) return $err;
        $questions[] = $currentQuestion;
    }
    
    if (empty($questions)) {
        return "El archivo no contiene preguntas válidas.";
    }
    
    return null;
}

function checkQuestion($q) {
    $count = count($q['answers']);
    if ($count < 3 || $count > 4) {
        return "La pregunta {$q['id']} tiene {$count} opciones de respuesta. Debe tener entre 3 y 4.";
    }
    $correctCount = 0;
    foreach ($q['answers'] as $ans) {
        if ($ans['correct']) {
            $correctCount++;
        }
    }
    if ($correctCount === 0) {
        return "La pregunta {$q['id']} no tiene una respuesta correcta marcada (usa el asterisco, ej: @ *Respuesta correcta).";
    }
    if ($correctCount > 1) {
        return "La pregunta {$q['id']} tiene más de una respuesta correcta marcada.";
    }
    return null;
}

// Cargar proyectos existentes para el listado
$projectsDir = __DIR__ . '/proyectos';
$projectsList = [];
if (is_dir($projectsDir)) {
    $dirs = scandir($projectsDir);
    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;
        $pPath = $projectsDir . '/' . $d;
        $qFile = $pPath . '/preguntas.txt';
        $jsonFile = $pPath . '/proyecto.json';
        if (is_dir($pPath) && (file_exists($qFile) || file_exists($jsonFile))) {
            // Leer metadatos del proyecto
            $pMeta = ['title' => $d, 'author' => '-', 'nivel' => 'General'];
            if (file_exists($jsonFile)) {
                try {
                    $parsedProject = triviax_parse_project_json(file_get_contents($jsonFile));
                    $pMeta = array_merge($pMeta, $parsedProject['metadata']);
                } catch (Exception $e) {
                    $pMeta['title'] = $d;
                }
            } else {
                $pContent = @file_get_contents($qFile);
                if ($pContent) {
                $pLines = preg_split('/\r\n|\r|\n/', $pContent);
                foreach ($pLines as $pLine) {
                    if (preg_match('/^(title|author|nivel)\s*:\s*(.*)$/i', trim($pLine), $m)) {
                        $pMeta[strtolower($m[1])] = trim($m[2]);
                    }
                }
                }
            }
            $projectsList[] = [
                'folder' => $d,
                'title' => $pMeta['title'],
                'author' => $pMeta['author'],
                'nivel' => $pMeta['nivel']
            ];
        }
    }
}

// Prompt para el docente (se coloca en el textarea del panel)
$aiPromptText = "Actúa como un redactor de contenido educativo especializado en exámenes y cuestionarios para estudiantes de 12 años.
A partir del documento de estudio que te aportaré como fuente de conocimiento, genera una batería de preguntas de opción múltiple respetando estrictamente el siguiente formato de texto plano:

1. Cada pregunta debe comenzar con un número, un punto y un espacio, seguido por el texto de la pregunta (por ejemplo: \"1. ¿Cómo se define formalmente a la informática?\").
2. Cada opción de respuesta debe comenzar con una arroba (@) y un espacio (por ejemplo: \"@ Opción de respuesta\").
3. La respuesta correcta debe identificarse agregando un asterisco (*) inmediatamente después del marcador @ (por ejemplo: \"@ *Esta es la respuesta correcta\").
4. Las respuestas incorrectas no deben llevar ningún asterisco (por ejemplo: \"@ Esta es una respuesta incorrecta\").
5. Cada pregunta debe tener exactamente 3 o 4 respuestas en total.
6. Debe haber exactamente una sola respuesta correcta por cada pregunta.
7. No dejes líneas en blanco dentro de una pregunta (entre la pregunta y sus respuestas). Deja una línea en blanco únicamente entre preguntas.
8. No incluyes textos introductorios, explicaciones o saludos. Genera únicamente el listado de preguntas y respuestas en formato de texto plano limpio.
9. Genera un mínimo de 30 preguntas y un máximo de 100 preguntas basadas estrictamente en la fuente provista.

Ejemplo del formato esperado:
1. ¿Cuál es el objeto de estudio de la informática?
@ *El tratamiento automático de la información mediante dispositivos digitales.
@ El diseño exterior y fabricación de carcasas de computadoras.
@ La reparación artesanal de cables de red y conectores.

2. ¿Qué invento del siglo XV multiplicó los libros y difundió masivamente el conocimiento?
@ El telégrafo eléctrico
@ *La imprenta
@ El teléfono analógico
@ La televisión de tubo

Aquí está el documento de estudio:";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIVIAX - Administración Docente</title>
    <link rel="stylesheet" href="css/styles.css?v=5.0.6">
    <style>
        body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            min-height: 100vh;
            background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.1) 0%, transparent 40%),
                        radial-gradient(circle at bottom left, rgba(168, 85, 247, 0.08) 0%, transparent 40%),
                        var(--bg-dark);
        }
        .admin-layout {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding: 20px;
            overflow-x: hidden;
        }
        .admin-navbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background: rgba(17, 24, 39, 0.45);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            margin-bottom: 25px;
        }
        .admin-navbar h1 {
            font-size: 1.6rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .admin-container {
            width: 1000px;
            max-width: calc(100vw - 40px);
            margin: 0 auto 40px auto;
        }
        .admin-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-family: var(--font-main);
            font-weight: 600;
            font-size: 0.95rem;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .tab-btn:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.03);
        }
        .tab-btn.active {
            color: var(--text-primary);
            background: rgba(99, 102, 241, 0.15);
            border-bottom: 2px solid var(--accent);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;    /* evita que la columna del grid se desborde */
        }
        .form-group-full {
            grid-column: span 2;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;    /* evita desbordamiento en grid */
        }
        @media (max-width: 768px) {
            .form-group-full {
                grid-column: span 1;
            }
        }
        .form-control {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            padding: 12px 16px;
            font-family: var(--font-main);
            font-size: 0.95rem;
            outline: none;
            width: 100%;        /* select/input llenan la columna del grid */
            max-width: 100%;    /* impide desbordamiento horizontal */
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px var(--border-glow);
        }
        .form-control::placeholder {
            color: var(--text-muted);
        }
        .form-control:disabled {
            background: rgba(255, 255, 255, 0.01);
            color: var(--text-muted);
            cursor: not-allowed;
        }
        select.form-control option,
        .form-control option {
            background: #0f172a;
            color: #f8fafc;
        }
        .form-help {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }
        .prompt-container {
            background: rgba(17, 24, 39, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            position: relative;
            margin-top: 15px;
            overflow-x: auto;
        }
        .prompt-text {
            width: 100%;
            max-width: 100%;
            height: 380px;
            max-height: min(52vh, 420px);
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            color: #38bdf8;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            line-height: 1.5;
            padding: 15px;
            resize: none;
            outline: none;
        }
        #tab-bg-prompt .prompt-text,
        #tab-prompt .prompt-text {
            min-height: 260px;
        }
        #tab-prompt .glass-card,
        #tab-bg-prompt .glass-card {
            padding: clamp(18px, 2.4vw, 30px) !important;
        }
        #tab-prompt .prompt-text {
            height: min(42vh, 340px);
            min-height: 220px;
        }
        .ai-help-grid {
            margin-top: 22px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .ai-help-card {
            background: rgba(255,255,255,0.02);
            padding: 18px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        @media (max-height: 760px) {
            .prompt-text {
                height: 280px !important;
                max-height: 42vh;
            }
            .glass-card {
                padding: 22px !important;
            }
        }
        @media (max-height: 820px), (max-width: 780px) {
            #tab-prompt .prompt-text {
                height: min(36vh, 280px) !important;
                min-height: 180px;
            }
            .ai-help-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .ai-help-card {
                padding: 14px;
            }
        }
        @media (max-width: 640px) {
            .prompt-container {
                padding: 14px;
            }
            .copy-btn {
                position: static;
                width: 100%;
                margin-bottom: 12px;
            }
        }
        .copy-btn {
            position: absolute;
            top: 35px;
            right: 35px;
            background: var(--accent-gradient);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: opacity 0.3s;
        }
        .copy-btn:hover {
            opacity: 0.9;
        }
        @media (max-width: 640px) {
            .copy-btn {
                position: static;
                width: 100%;
                margin-bottom: 12px;
            }
        }
        .activity-card {
            background: rgba(17, 24, 39, 0.45);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.3s, border-color 0.3s;
        }
        .activity-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.15);
        }
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        /* Estilos del Asistente (Wizard) */
        .wizard-step {
            animation: fadeIn 0.4s ease;
        }
        
        .wizard-progress {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            background: rgba(255, 255, 255, 0.02);
            padding: 15px 25px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        
        .progress-line {
            position: absolute;
            top: calc(50% - 10px);
            left: 50px;
            right: 50px;
            height: 2px;
            background: rgba(255, 255, 255, 0.05);
            z-index: 1;
        }
        
        .progress-line-fill {
            position: absolute;
            top: calc(50% - 10px);
            left: 50px;
            width: 0%;
            height: 2px;
            background: var(--accent-gradient);
            z-index: 2;
            transition: width 0.3s ease;
        }
        
        .progress-step {
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 80px;
        }
        
        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg-dark);
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.85rem;
            color: var(--text-muted);
            transition: all 0.3s ease;
        }
        
        .progress-step.active .step-num {
            border-color: var(--accent);
            color: var(--text-primary);
            box-shadow: 0 0 12px var(--accent-glow);
            background: rgba(99, 102, 241, 0.1);
        }
        
        .progress-step.completed .step-num {
            background: var(--success-gradient);
            border-color: var(--success);
            color: white;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }
        
        .step-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 6px;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .progress-step.active .step-label {
            color: var(--text-primary);
        }
        
        .progress-step.completed .step-label {
            color: var(--success);
        }
        
        .method-cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        @media (max-width: 768px) {
            .method-cards-grid {
                grid-template-columns: 1fr;
            }
            .progress-line, .progress-line-fill {
                display: none;
            }
        }
        
        .method-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
        }
        
        .method-card:hover {
            background: rgba(99, 102, 241, 0.06);
            border-color: var(--accent);
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.12);
        }
        
        .method-card-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        
        .method-card h4 {
            font-size: 1.15rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
        .method-card p {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.5;
            flex-grow: 1;
            margin-bottom: 15px;
        }
        
        /* Estilos del Formulario Manual */
        .manual-q-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s;
        }
        
        .manual-q-card:focus-within {
            border-color: rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.03);
        }
        
        .manual-q-header {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .manual-ans-row {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 12px;
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            padding: 10px 16px;
            transition: all 0.25s ease;
        }
        
        .manual-ans-row:focus-within {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.1);
        }
        
        .manual-ans-row.is-correct {
            background: rgba(16, 185, 129, 0.06) !important;
            border-color: var(--success) !important;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.1);
        }
        
        .manual-ans-row input[type="text"] {
            flex-grow: 1;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-family: var(--font-main);
            font-size: 0.95rem;
            padding: 4px 0;
        }
        
        .correct-toggle-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }
        
        .correct-radio {
            appearance: none;
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            border: 2px solid var(--text-muted);
            border-radius: 50%;
            outline: none;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .correct-radio:checked {
            border-color: var(--success);
            background-color: var(--success);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
        }
        
        .correct-radio::after {
            content: "✓";
            color: white;
            font-size: 13px;
            font-weight: 800;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .correct-radio:checked::after {
            opacity: 1;
        }
        
        .correct-label-hint {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            transition: color 0.25s;
        }
        
        .manual-ans-row.is-correct .correct-label-hint {
            color: var(--success);
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- BARRA NAV SUPERIOR -->
        <header class="admin-navbar">
            <div class="logo-area">
                <h1>TRIVIAX</h1>
                <span style="font-size: 0.85rem; opacity: 0.7; color: var(--text-secondary);">Administración Docente</span>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <a href="panel/dashboard.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    🏠 Dashboard
                </a>
                <a href="docs/GUIA_DOCENTE_TRIVIAX.pdf" target="_blank" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    📖 Guía Docente
                </a>
                <a href="estadisticas.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    📊 Ver Estadísticas
                </a>
                <a href="panel/jigsaw.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    🧩 Puzle
                </a>
                <a href="panel/etiquetar.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    🏷️ Etiquetar
                </a>
                <a href="panel/generador_tableros.php" class="btn btn-secondary" style="text-decoration: none; padding: 10px 16px;">
                    🗺️ Tableros
                </a>
                <a href="index.html" class="btn btn-primary" style="text-decoration: none; padding: 10px 16px;">
                    🎮 Ir al Juego
                </a>
            </div>
        </header>

        <!-- CONTENIDO PRINCIPAL -->
        <div class="admin-container">
            <div class="admin-tabs">
                <button class="tab-btn active" onclick="switchTab('tab-create')">🆕 Crear Nueva Actividad</button>
                <button class="tab-btn" onclick="switchTab('tab-prompt')">🤖 Prompt de IA para Preguntas</button>
                <button class="tab-btn" onclick="switchTab('tab-bg-prompt')">🎨 Prompt de IA para Fondo</button>
                <button class="tab-btn" onclick="switchTab('tab-list')">📁 Actividades Existentes</button>
            </div>

            <!-- ALERTAS -->
            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger">
                    <span>⚠️</span>
                    <div><strong>No se pudo crear la actividad:</strong> <?php echo htmlspecialchars($errorMsg); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMsg)): ?>
                <div class="alert alert-success">
                    <span>✅</span>
                    <div>
                        <strong>¡Éxito!</strong> <?php echo htmlspecialchars($successMsg); ?>
                        <div style="margin-top: 10px; display:flex; flex-wrap:wrap; gap:8px;">
                            <a href="index.html?project=<?php echo urlencode($createdActivityName); ?>" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.8rem; text-transform: none; text-decoration: none; box-shadow: none;">
                                🎮 Jugar Ahora
                            </a>
                            <a href="estadisticas.php?project=<?php echo urlencode($createdActivityName); ?>" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.8rem; text-transform: none; text-decoration: none;">
                                📊 Ver Estadísticas
                            </a>
                            <a href="admin.php" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.8rem; text-transform: none; text-decoration: none; background: rgba(99,102,241,0.15); border-color: var(--accent);">
                                ➕ Crear Nueva Actividad
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 1: CREAR ACTIVIDAD -->
            <div id="tab-create" class="tab-content active">
                <div class="glass-card" style="padding: 30px;">
                    <!-- Barra de Progreso del Asistente -->
                    <div class="wizard-progress">
                        <div class="progress-line"></div>
                        <div class="progress-line-fill" id="progress-line-fill"></div>
                        
                        <div class="progress-step active" id="prog-step-1">
                            <div class="step-num">1</div>
                            <span class="step-label">Ficha Técnica</span>
                        </div>
                        <div class="progress-step" id="prog-step-2">
                            <div class="step-num">2</div>
                            <span class="step-label">Método</span>
                        </div>
                        <div class="progress-step" id="prog-step-3">
                            <div class="step-num">3</div>
                            <span class="step-label">Carga/Config</span>
                        </div>
                        <div class="progress-step" id="prog-step-4">
                            <div class="step-num">4</div>
                            <span class="step-label">Preguntas</span>
                        </div>
                    </div>

                    <form id="create-activity-form" action="admin.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create_activity">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="activity_json" id="activity_json" value="<?php echo htmlspecialchars($formValues['activity_json'], ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- PASO 1: METADATOS Y IMAGEN -->
                        <div id="step-1" class="wizard-step">
                            <h2 style="margin-bottom: 10px; font-size: 1.4rem; font-weight: 800; color: var(--accent);">Ficha Técnica e Imagen de Fondo</h2>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                                Rellena los datos de la actividad y sube opcionalmente una imagen para ambientar el fondo del tablero.
                            </p>
                            
                            <div class="form-grid">
                                <!-- TITLE (Obligatorio) -->
                                <div class="form-group">
                                    <label for="title">Título del Juego/Actividad <span style="color:var(--danger)">*</span></label>
                                    <input type="text" name="title" id="title" class="form-control" placeholder="Ej. Historia Universal I" value="<?php echo htmlspecialchars($formValues['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <span class="form-help">El nombre público que verán los jugadores.</span>
                                </div>

                                <!-- AUTHOR (Obligatorio) -->
                                <div class="form-group">
                                    <label for="author">Nombre del Autor/Docente <span style="color:var(--danger)">*</span></label>
                                    <input type="text" name="author" id="author" class="form-control" placeholder="Ej. Prof. Luis Darwin Salina" value="<?php echo htmlspecialchars($formValues['author'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <span class="form-help">Identificación del creador del contenido.</span>
                                </div>

                                <!-- NIVEL (Opcional) -->
                                <div class="form-group">
                                    <label for="nivel">Nivel / Curso al que se aplicará</label>
                                    <input type="text" name="nivel" id="nivel" class="form-control" placeholder="Ej. S1, Primer Año, 12 Años" value="<?php echo htmlspecialchars($formValues['nivel'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="form-help">Grupo o clase recomendado para la actividad.</span>
                                </div>

                                <!-- MAIL (Opcional) -->
                                <div class="form-group">
                                    <label for="mail">Email del Autor (Opcional)</label>
                                    <input type="email" name="mail" id="mail" class="form-control" placeholder="docente@ejemplo.com" value="<?php echo htmlspecialchars($formValues['mail'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="form-help">Recibirá reportes de partida al finalizar cada juego.</span>
                                </div>

                                <!-- OBS (Opcional) -->
                                <div class="form-group-full">
                                    <label for="obs">Comentarios u observaciones para los jugadores (Opcional)</label>
                                    <textarea name="obs" id="obs" rows="3" class="form-control" placeholder="Comentarios o indicaciones que aparecerán en la pantalla de inicio del juego..." style="resize: vertical;"><?php echo htmlspecialchars($formValues['obs'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>

                                <!-- DATE (Automática, no modificable) -->
                                <div class="form-group">
                                    <label for="date_display">Fecha de Creación (Automática)</label>
                                    <input type="text" id="date_display" class="form-control" value="<?php echo date("d/m/Y"); ?>" disabled style="opacity: 0.6;">
                                </div>

                                <!-- Tablero del juego (Opcional) -->
                                <div class="form-group-full">
                                    <label for="board_locked_id">Tablero del juego</label>
                                    <select name="board_locked_id" id="board_locked_id" class="form-control" onchange="onBoardLockChange()">
                                        <option value="">Tableros estándar — el jugador elige (Oca, Monopoly, Circular)</option>
                                        <?php foreach (triviax_listar_tableros_disponibles() as $b): ?>
                                            <option value="<?php echo htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo (($formValues['board_locked_id'] ?? '') === $b['id']) ? ' selected' : ''; ?>>
                                                Tablero fijo: <?php echo htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="form-help">Si eliges un tablero personalizado, la actividad se jugará <strong>solo</strong> en ese tablero (los jugadores no podrán cambiarlo) y no necesitas subir fondo: el tablero ya trae su propia imagen.</span>
                                </div>

                                <!-- Archivo de Imagen de Fondo (Opcional) -->
                                <div class="form-group-full" id="bg-upload-group">
                                    <label for="background_file">Imagen de Fondo (Opcional)</label>
                                    <input type="file" name="background_file" id="background_file" accept="image/*" class="form-control" style="padding: 8px 12px;">
                                    <span class="form-help">Imagen para ambientar el juego. Se recomienda JPG/PNG horizontal (16:9) menor a 1MB. Si se omite, se usará el fondo espacial predeterminado.</span>
                                </div>
                                <script>
                                    function onBoardLockChange() {
                                        var sel = document.getElementById('board_locked_id');
                                        var bg  = document.getElementById('bg-upload-group');
                                        if (sel && bg) { bg.style.display = sel.value ? 'none' : ''; }
                                    }
                                    document.addEventListener('DOMContentLoaded', onBoardLockChange);
                                </script>
                            </div>

                            <div style="margin-top: 30px; display: flex; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 20px;">
                                <button type="button" class="btn btn-primary" onclick="goToStep(2)" style="padding: 14px 28px;">
                                    Siguiente: Método de Carga ➔
                                </button>
                            </div>
                        </div>

                        <!-- PASO 2: ELEGIR MÉTODO -->
                        <div id="step-2" class="wizard-step" style="display: none;">
                            <h2 style="margin-bottom: 10px; font-size: 1.4rem; font-weight: 800; color: var(--accent);">Elegir Método de Carga de Preguntas</h2>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                                Selecciona cómo deseas proveer las preguntas para tu nuevo juego.
                            </p>
                            
                            <div class="method-cards-grid">
                                <div class="method-card" onclick="selectLoadMethod('ia')">
                                    <div class="method-card-icon">🤖</div>
                                    <h4>Asistente Inteligente con IA</h4>
                                    <p>Ideal para crear cuestionarios grandes rápidamente. Copia y pega el listado de preguntas generado por una IA (como ChatGPT o Gemini) usando nuestro prompt estructurado.</p>
                                    <button type="button" class="btn btn-secondary btn-sm" style="margin-top: 15px; font-size: 0.75rem; pointer-events: none;">Elegir Asistente de IA</button>
                                </div>
                                
                                <div class="method-card" onclick="selectLoadMethod('manual')">
                                    <div class="method-card-icon">✍️</div>
                                    <h4>Formulario de Carga Manual</h4>
                                    <p>Arma tu set de preguntas de forma tradicional e interactiva. Introduce las preguntas, respuestas y marca la respuesta correcta mediante un formulario visual.</p>
                                    <button type="button" class="btn btn-secondary btn-sm" style="margin-top: 15px; font-size: 0.75rem; pointer-events: none;">Elegir Carga Manual</button>
                                </div>
                            </div>
                            
                            <div style="margin-top: 30px; display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="goToStep(1)" style="padding: 14px 28px;">
                                    ⬅ Volver
                                </button>
                            </div>
                        </div>

                        <!-- PASO 3-IA: PEGAR TEXTO -->
                        <div id="step-3-ia" class="wizard-step" style="display: none;">
                            <h2 style="margin-bottom: 10px; font-size: 1.4rem; font-weight: 800; color: var(--accent);">Cargar Cuestionario desde IA</h2>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 20px;">
                                Pega aquí el banco de preguntas generado. Asegúrate de respetar el formato estructurado.
                            </p>
                            
                            <div class="form-group-full">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:6px;">
                                    <label for="questions_text" style="margin:0;">Banco de Preguntas (Copiar y Pegar) <span style="color:var(--danger)">*</span></label>
                                    <button type="button" onclick="document.getElementById('questions_text').value=''; document.getElementById('questions_text').focus();" style="background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); color:#fca5a5; border-radius:7px; padding:5px 12px; font-size:0.78rem; font-weight:700; cursor:pointer; white-space:nowrap; transition:background 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.22)'" onmouseout="this.style.background='rgba(239,68,68,0.12)'">
                                        🗑️ Limpiar texto
                                    </button>
                                </div>
                                <textarea name="questions_text" id="questions_text" rows="12" class="form-control" style="font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; resize: vertical;" placeholder="1. ¿Cómo se define formalmente a la informática?&#10;@ *La ciencia que estudia el tratamiento automático de la información mediante dispositivos digitales.&#10;@ El estudio exclusivo del diseño de piezas físicas de una computadora.&#10;@ La técnica de reparar cables de red y conectores telefónicos.&#10;&#10;2. ¿Qué invento del siglo XV multiplicó los libros?&#10;@ El telégrafo eléctrico&#10;@ *La imprenta&#10;@ El teléfono analógico"><?php echo htmlspecialchars($formValues['questions_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <span class="form-help">Cada opción debe iniciar con <strong>@</strong> y la correcta con <strong>@ *</strong>. Deja una línea en blanco únicamente entre preguntas.</span>
                            </div>
                            
                            <div style="margin-top: 30px; display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="goToStep(2)" style="padding: 14px 28px;">
                                    ⬅ Volver
                                </button>
                                <button type="submit" class="btn btn-primary" style="padding: 14px 28px;">
                                    🚀 Crear Actividad
                                </button>
                            </div>
                        </div>

                        <!-- PASO 3-MANUAL-CONFIG: CONFIGURAR CANTIDAD DE PREGUNTAS Y RESPUESTAS -->
                        <div id="step-3-manual-config" class="wizard-step" style="display: none;">
                            <h2 style="margin-bottom: 10px; font-size: 1.4rem; font-weight: 800; color: var(--accent);">Configurar Formulario Manual</h2>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                                Configura la cantidad de preguntas que tendrá el juego y la cantidad de opciones de respuestas por cada una.
                            </p>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="manual_activity_type">Modalidad manual <span style="color:var(--danger)">*</span></label>
                                    <select id="manual_activity_type" class="form-control" onchange="updateManualTypeOptions()">
                                        <option value="multiple_choice" selected>Opción múltiple</option>
                                        <option value="matching_pairs">Asociar pares</option>
                                        <option value="sequence_order">Ordenar elementos</option>
                                    </select>
                                    <span class="form-help">Las modalidades mixtas se guardan como proyecto.json.</span>
                                </div>
                                <div class="form-group">
                                    <label for="manual_qty">Cantidad de preguntas a crear <span style="color:var(--danger)">*</span></label>
                                    <input type="number" id="manual_qty" class="form-control" min="1" max="100" value="5" required>
                                    <span class="form-help" id="manual_qty_help">Indica cuántas preguntas tendrá este juego.</span>
                                </div>
                                <div class="form-group" id="manual_opts_group">
                                    <label for="manual_opts_qty">Cantidad de opciones de respuesta <span style="color:var(--danger)">*</span></label>
                                    <select id="manual_opts_qty" class="form-control">
                                        <option value="3" selected>3 opciones de respuesta por pregunta</option>
                                        <option value="4">4 opciones de respuesta por pregunta</option>
                                    </select>
                                    <span class="form-help">El juego permite 3 o 4 respuestas posibles para cada pregunta.</span>
                                </div>
                                <div class="form-group" id="manual_pairs_group" style="display: none;">
                                    <label for="manual_pairs_qty">Pares por desafío <span style="color:var(--danger)">*</span></label>
                                    <select id="manual_pairs_qty" class="form-control">
                                        <option value="2">2 pares</option>
                                        <option value="3" selected>3 pares</option>
                                        <option value="4">4 pares</option>
                                        <option value="5">5 pares</option>
                                        <option value="6">6 pares</option>
                                    </select>
                                    <span class="form-help">Cada par tendrá un elemento izquierdo y su pareja correcta.</span>
                                </div>
                                <div class="form-group" id="manual_sequence_group" style="display: none;">
                                    <label for="manual_sequence_qty">Elementos por secuencia <span style="color:var(--danger)">*</span></label>
                                    <select id="manual_sequence_qty" class="form-control">
                                        <option value="3" selected>3 elementos</option>
                                        <option value="4">4 elementos</option>
                                        <option value="5">5 elementos</option>
                                        <option value="6">6 elementos</option>
                                        <option value="7">7 elementos</option>
                                        <option value="8">8 elementos</option>
                                    </select>
                                    <span class="form-help">Escríbelos en el orden correcto; el juego los mezclará al mostrar.</span>
                                </div>
                            </div>
                            
                            <div style="margin-top: 30px; display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="goToStep(2)" style="padding: 14px 28px;">
                                    ⬅ Volver
                                </button>
                                <button type="button" class="btn btn-primary" onclick="initManualFormCreation()" style="padding: 14px 28px;">
                                    Generar Formulario ➔
                                </button>
                            </div>
                        </div>

                        <!-- PASO 4: FORMULARIO DINÁMICO DE CARGA -->
                        <div id="step-4-manual-form" class="wizard-step" style="display: none;">
                            <h2 style="margin-bottom: 10px; font-size: 1.4rem; font-weight: 800; color: var(--accent);">Cargar Cuestionario Manualmente</h2>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                                Completa el texto de cada pregunta y sus opciones. Selecciona el círculo verde de la derecha para indicar cuál es la **respuesta correcta**.
                            </p>
                            
                            <div id="dynamic-questions-container" style="display: flex; flex-direction: column; gap: 25px; margin-bottom: 30px;">
                                <!-- Se inyectará dinámicamente mediante JS -->
                            </div>
                            
                            <div style="margin-top: 30px; display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="goToStep('manual-config')" style="padding: 14px 28px;">
                                    ⬅ Volver
                                </button>
                                <button type="button" class="btn btn-primary" onclick="submitManualForm()" style="padding: 14px 28px;">
                                    🚀 Confirmar y Crear Juego
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 2: PROMPT DE IA -->
            <div id="tab-prompt" class="tab-content">
                <div class="glass-card" style="padding: 30px;">
                    <h2 style="margin-bottom: 10px; font-size: 1.5rem; font-weight: 800;">Generación Inteligente con IA</h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 20px;">
                        Puede utilizar el siguiente prompt estructurado para solicitarle a una Inteligencia Artificial (como ChatGPT, Claude o Gemini) que redacte un set completo de preguntas en base a un documento o apunte escolar de estudio que usted le provea.
                    </p>

                    <div class="form-grid" style="margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="prompt_activity_mode">Modalidad de actividades</label>
                            <select id="prompt_activity_mode" class="form-control" onchange="updateQuestionPrompt()">
                                <option value="multiple_choice_txt" selected>Solo opción múltiple (preguntas.txt)</option>
                                <option value="matching_pairs_json">Solo asociar pares (proyecto.json)</option>
                                <option value="sequence_order_json">Solo ordenar elementos (proyecto.json)</option>
                                <option value="classification_json">Solo clasificar elementos (proyecto.json)</option>
                                <option value="fill_blank_select_json">Solo completar espacios (proyecto.json)</option>
                                <option value="mixed_json">Actividad mixta: varias modalidades (proyecto.json)</option>
                            </select>
                            <span class="form-help">El formato cambia según lo que TRIVIAX puede interpretar.</span>
                        </div>
                        <div class="form-group">
                            <label for="prompt_question_count">Cantidad aproximada de desafíos</label>
                            <input type="number" id="prompt_question_count" class="form-control" min="5" max="100" value="30" oninput="updateQuestionPrompt()">
                            <span class="form-help">Para actividades mixtas conviene empezar con 20 a 40 desafíos.</span>
                        </div>
                    </div>

                    <div class="prompt-container">
                        <div style="margin-bottom: 10px; font-weight: 600; color: var(--text-primary);">Instrucciones para copiar el Prompt:</div>
                        <button class="copy-btn" id="btn-copy-prompt" onclick="copyPromptToClipboard()">Copiar Prompt</button>
                        <textarea class="prompt-text" id="ai-prompt-box" readonly><?php echo htmlspecialchars($aiPromptText); ?></textarea>
                    </div>

                    <div class="ai-help-grid">
                        <div class="ai-help-card">
                            <h4 style="color: var(--accent); margin-bottom: 8px; font-size: 0.95rem; text-transform: uppercase;">¿Cómo usarlo?</h4>
                            <ol style="padding-left: 18px; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6;">
                                <li style="margin-bottom: 8px;">Haga clic en el botón <strong>Copiar Prompt</strong> superior.</li>
                                <li style="margin-bottom: 8px;">Pegue el prompt en su IA de preferencia (ChatGPT, Gemini, etc.).</li>
                                <li style="margin-bottom: 8px;">Pegue su texto, PDF o resumen escolar a continuación.</li>
                                <li style="margin-bottom: 8px;">Copie el listado de preguntas que la IA haya generado.</li>
                                <li>Pegue el texto copiado en el campo de texto de la pestaña <strong>"Crear Nueva Actividad"</strong> para guardar el proyecto.</li>
                            </ol>
                        </div>

                        <div class="ai-help-card">
                            <h4 style="color: var(--success); margin-bottom: 8px; font-size: 0.95rem; text-transform: uppercase;">Consejos sobre la imagen de fondo</h4>
                            <ul style="padding-left: 18px; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6; list-style-type: square;">
                                <li style="margin-bottom: 8px;"><strong>Relación de aspecto:</strong> Use imágenes apaisadas u horizontales en proporción 16:9 o 16:10.</li>
                                <li style="margin-bottom: 8px;"><strong>Resolución recomendada:</strong> 1920 x 1080 o 1280 x 720 píxeles.</li>
                                <li style="margin-bottom: 8px;"><strong>Optimización:</strong> Asegúrese de que no supere 1MB para que la carga en equipos escolares sea instantánea.</li>
                                <li><strong>Predeterminada:</strong> Si no se proporciona una imagen, la plataforma aplicará automáticamente el fondo espacial predeterminado.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: PROMPT DE IA PARA IMAGEN DE FONDO -->
            <div id="tab-bg-prompt" class="tab-content">
                <div class="glass-card" style="padding: 30px;">
                    <h2 style="margin-bottom: 10px; font-size: 1.5rem; font-weight: 800;">Generador de Prompt para Fondo con IA</h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                        Configure los parámetros de su actividad para generar un prompt detallado. Copie este prompt y péguelo en la IA generadora de imágenes de su preferencia (como Midjourney, DALL-E, ChatGPT o Gemini) junto con su documento de referencia para obtener el fondo perfecto.
                    </p>

                    <div class="form-grid" style="margin-bottom: 25px;">
                        <!-- Tema principal (Obligatorio) -->
                        <div class="form-group">
                            <label for="bg_theme">Tema principal de la actividad</label>
                            <input type="text" id="bg_theme" class="form-control" placeholder="Ej. El Imperio Romano, La Célula y sus Organelas" oninput="updateBgPrompt()">
                            <span class="form-help">Tema general en el que se basará la ambientación del fondo.</span>
                        </div>

                        <!-- Unidades temáticas (Opcional) -->
                        <div class="form-group">
                            <label for="bg_units">Unidades Temáticas / Capítulos específicos (Opcional)</label>
                            <input type="text" id="bg_units" class="form-control" placeholder="Ej. Unidad 2: Mitología, Cap. 3: Expansión Territorial" oninput="updateBgPrompt()">
                            <span class="form-help">Si corresponde, detalle las unidades para una ambientación más específica.</span>
                        </div>

                        <!-- Estilo visual -->
                        <div class="form-group">
                            <label for="bg_style">Estilo Artístico / Estética del fondo</label>
                            <select id="bg_style" class="form-control" onchange="updateBgPrompt()">
                                <option value="ilustracion_educativa">Ilustración educativa moderna (Estilo vectorial plano, limpio y colorido)</option>
                                <option value="fantasia_medieval">Fantasía épica y medieval (Estilo RPG, misterioso e ilustrativo)</option>
                                <option value="tecnologico_futurista">Tecnológico y futurista (Acentos de neón, cibernético y sci-fi)</option>
                                <option value="historico_vintage">Histórico y vintage (Estilo mapa antiguo, pergaminos y detalles clásicos)</option>
                                <option value="dibujo_infantil">Dibujo animado infantil (Alegre, simplificado, caricaturesco y muy colorido)</option>
                                <option value="espacial_cosmos">Cosmos y astronomía (Espacio exterior, galaxias, nebulosas y estrellas)</option>
                            </select>
                            <span class="form-help">Determina el acabado artístico de la imagen generada.</span>
                        </div>

                        <!-- Paleta de colores -->
                        <div class="form-group">
                            <label for="bg_color">Paleta de Colores predominante</label>
                            <select id="bg_color" class="form-control" onchange="updateBgPrompt()">
                                <option value="oscura_contrastante">Oscura y contrastante (Recomendado para resaltar casillas y fichas brillantes)</option>
                                <option value="tonos_pastel">Pasteles suaves (Tonos claros y relajantes, baja saturación)</option>
                                <option value="colores_vivos">Colores vivos y alegres (Saturado, dinámico y festivo)</option>
                                <option value="tonos_tierra">Tonos tierra y cálidos (Marrón, ocre, pergamino envejecido, beige)</option>
                                <option value="azules_grises">Azules y grises tecnológicos (Fresco, digital y sobrio)</option>
                            </select>
                            <span class="form-help">Garantiza el correcto contraste y legibilidad con los elementos del juego.</span>
                        </div>
                    </div>

                    <div class="prompt-container">
                        <div style="margin-bottom: 10px; font-weight: 600; color: var(--text-primary);">Prompt de Fondo Generado:</div>
                        <button class="copy-btn" id="btn-copy-bg-prompt" onclick="copyBgPromptToClipboard()">Copiar Prompt de Fondo</button>
                        <textarea class="prompt-text" id="ai-bg-prompt-box" readonly style="height: 320px;"></textarea>
                    </div>

                    <!-- Instrucciones de Uso detalladas -->
                    <div style="margin-top: 30px; background: rgba(99, 102, 241, 0.08); padding: 25px; border-radius: 12px; border: 1px solid rgba(99, 102, 241, 0.25);">
                        <h4 style="color: var(--accent); margin-bottom: 12px; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                            <span>💡</span> Instrucciones importantes para el docente:
                        </h4>
                        <ol style="padding-left: 20px; font-size: 0.9rem; color: var(--text-secondary); line-height: 1.7;">
                            <li style="margin-bottom: 8px;">
                                Configure los campos de arriba (tema, estilo y colores) para adaptar el prompt de fondo a sus necesidades.
                            </li>
                            <li style="margin-bottom: 8px;">
                                Haga clic en <strong>"Copiar Prompt de Fondo"</strong> para copiar el texto generado.
                            </li>
                            <li style="margin-bottom: 8px;">
                                Vaya a la herramienta de Inteligencia Artificial de su elección (como <strong>ChatGPT Plus</strong>, <strong>Gemini Advanced</strong>, <strong>Claude</strong>, o generadores dedicados como Midjourney o Bing Image Creator).
                            </li>
                            <li style="margin-bottom: 8px;">
                                **Péguelo en la caja de conversación** y, muy importante: **adjunte el documento o material didáctico de referencia** (el PDF, Word o resumen que usará para las preguntas) e indique las **unidades temáticas** en la interfaz de la IA, si corresponde. Esto guiará a la IA a capturar los elementos visuales clave de ese texto específico.
                            </li>
                            <li>
                                Descargue la imagen generada por la IA (en formato horizontal) y súbala en la pestaña <strong>"Crear Nueva Actividad"</strong> como la <em>Imagen de Fondo</em> para su set.
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- TAB 3: LISTADO DE ACTIVIDADES -->
            <div id="tab-list" class="tab-content">
                <div class="glass-card" style="padding: 30px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
                        <h2 style="font-size: 1.5rem; font-weight: 800; margin:0;">Actividades Creadas</h2>
                        <?php if ($pdo !== null): ?>
                        <button id="btn-sync-projects" onclick="syncAllProjects()"
                                class="btn btn-secondary"
                                style="padding:8px 18px;font-size:0.9rem;display:flex;align-items:center;gap:6px;">
                            <span id="sync-icon">🔄</span> Sincronizar con BD
                        </button>
                        <?php endif; ?>
                    </div>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 25px;">
                        A continuación se listan todos los proyectos guardados en el servidor local. Puede hacer clic para jugar directamente o para ver su reporte estadístico.
                    </p>
                    <div id="sync-result" style="display:none;margin-bottom:18px;padding:12px 16px;border-radius:8px;font-size:0.88rem;font-family:monospace;background:#1e293b;border:1px solid #334155;"></div>

                    <?php if (empty($projectsList)): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted); font-style: italic;">
                            No hay actividades registradas en el directorio. ¡Crea una en la primera pestaña!
                        </div>
                    <?php else: ?>
                        <div class="activity-grid">
                            <?php foreach ($projectsList as $p): ?>
                                <div class="activity-card">
                                    <div>
                                        <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--text-primary); margin-bottom: 6px;"><?php echo htmlspecialchars($p['title']); ?></h3>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 12px; font-family: monospace;">Carpeta: proyectos/<?php echo htmlspecialchars($p['folder']); ?>/</div>
                                        
                                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 5px;"><strong>Autor:</strong> <?php echo htmlspecialchars($p['author']); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px;"><strong>Nivel:</strong> <?php echo htmlspecialchars($p['nivel']); ?></div>
                                    </div>
                                    
                                    <div style="display: flex; gap: 6px; border-top: 1px solid var(--border-color); padding-top: 15px; flex-wrap:wrap;">
                                        <a href="index.html?project=<?php echo urlencode($p['folder']); ?>" class="btn btn-primary" style="flex: 1; min-width:70px; padding: 8px 10px; font-size: 0.75rem; text-transform: none; text-decoration: none;">
                                            🎮 Jugar
                                        </a>
                                        <button type="button" class="btn btn-secondary" style="flex:1; min-width:70px; padding: 8px 10px; font-size: 0.75rem;" onclick="openEditPanel('<?php echo htmlspecialchars($p['folder'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>')">
                                            ✏️ Editar
                                        </button>
                                        <a href="estadisticas.php?project=<?php echo urlencode($p['folder']); ?>" class="btn btn-secondary" style="flex: 1; min-width:70px; padding: 8px 10px; font-size: 0.75rem; text-transform: none; text-decoration: none;">
                                            📊 Stats
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" style="padding: 8px 10px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; min-width: 36px;" onclick="deleteProject('<?php echo htmlspecialchars($p['folder'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>')" title="Mover a Papelera">
                                            🗑️
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ════════════════════════════════════════════════════════
                         PANEL DE EDICIÓN DE ACTIVIDAD
                         ════════════════════════════════════════════════════════ -->
                    <div id="edit-panel" style="display:none; margin-top:32px;">
                        <div class="glass-card" style="padding:30px;">

                            <!-- Encabezado -->
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; border-bottom:1px solid var(--border-color); padding-bottom:16px;">
                                <div>
                                    <h2 style="margin:0; font-size:1.3rem; font-weight:800; color:var(--accent);">✏️ Editando actividad</h2>
                                    <p id="edit-project-slug-label" style="margin:4px 0 0; font-size:0.8rem; color:var(--text-muted); font-family:monospace;"></p>
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <button type="button" id="ep-save-btn-top" onclick="saveEditedActivity()" style="background:var(--accent-gradient); border:none; color:#fff; border-radius:8px; padding:8px 18px; font-weight:700; cursor:pointer; font-size:0.85rem; font-family:var(--font-main);">💾 Guardar cambios</button>
                                    <button type="button" onclick="closeEditPanel()" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:var(--text-secondary); border-radius:8px; padding:8px 14px; cursor:pointer; font-size:0.85rem; font-weight:600;">✕ Cerrar</button>
                                </div>
                            </div>

                            <!-- Cargando… -->
                            <div id="edit-loading" style="text-align:center; padding:40px; color:var(--text-muted);">
                                <div style="font-size:1.5rem; margin-bottom:10px;">⏳</div>
                                Cargando datos del proyecto…
                            </div>

                            <!-- Contenido editable (se muestra tras cargar) -->
                            <div id="edit-body" style="display:none;">

                                <!-- FICHA TÉCNICA -->
                                <h3 style="font-size:1rem; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:16px;">📋 Ficha Técnica</h3>
                                <div class="form-grid" style="margin-bottom:28px;">
                                    <div class="form-group">
                                        <label>Título <span style="color:var(--danger)">*</span></label>
                                        <input type="text" id="ep-title" class="form-control" placeholder="Título del juego" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Autor <span style="color:var(--danger)">*</span></label>
                                        <input type="text" id="ep-author" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Nivel / Curso</label>
                                        <input type="text" id="ep-nivel" class="form-control" placeholder="Ej. S1, Primer Año">
                                    </div>
                                    <div class="form-group">
                                        <label>Email del autor</label>
                                        <input type="email" id="ep-mail" class="form-control" placeholder="docente@ejemplo.com">
                                    </div>
                                    <div class="form-group-full">
                                        <label>Observaciones / Consigna para jugadores</label>
                                        <textarea id="ep-obs" rows="2" class="form-control" style="resize:vertical;" placeholder="Instrucciones que verán los jugadores al iniciar…"></textarea>
                                    </div>
                                    <div class="form-group-full">
                                        <label for="ep-board-locked">Tablero del juego</label>
                                        <select id="ep-board-locked" class="form-control">
                                            <option value="">Tableros estándar — el jugador elige (Oca, Monopoly, Circular)</option>
                                            <?php foreach (triviax_listar_tableros_disponibles() as $b): ?>
                                                <option value="<?php echo htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8'); ?>">Tablero fijo: <?php echo htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="form-help">Si fijas un tablero personalizado, la actividad se jugará solo en ese tablero y usará su propia imagen (no hace falta fondo).</span>
                                    </div>
                                </div>

                                <!-- LISTA DE DESAFÍOS -->
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                    <h3 style="font-size:1rem; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em; margin:0;">🎯 Desafíos (<span id="ep-ch-count">0</span>)</h3>
                                    <button type="button" onclick="showAddChallengeModal()" style="background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.3); color:#a5b4fc; border-radius:8px; padding:7px 14px; font-size:0.82rem; font-weight:700; cursor:pointer;">
                                        ➕ Agregar desafío
                                    </button>
                                </div>

                                <div id="ep-challenges-list" style="display:flex; flex-direction:column; gap:10px; margin-bottom:28px;">
                                    <!-- se llena con JS -->
                                </div>

                                <!-- GUARDAR -->
                                <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid var(--border-color); padding-top:20px;">
                                    <button type="button" onclick="closeEditPanel()" style="background:transparent; border:1px solid var(--border-color); color:var(--text-secondary); border-radius:9px; padding:12px 24px; font-weight:600; cursor:pointer;">Cancelar</button>
                                    <button type="button" id="ep-save-btn" onclick="saveEditedActivity()" style="background:var(--accent-gradient); border:none; color:#fff; border-radius:9px; padding:12px 28px; font-weight:700; cursor:pointer; font-family:var(--font-main);">💾 Guardar cambios</button>
                                </div>

                            </div><!-- /edit-body -->
                        </div><!-- /glass-card -->
                    </div><!-- /edit-panel -->

                </div>
            </div>
        </div>

        <!-- MODAL: AGREGAR DESAFÍO -->
        <div id="add-ch-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9000; align-items:center; justify-content:center;">
            <div style="background:#0d1423; border:1px solid var(--border-color); border-radius:16px; padding:28px; width:520px; max-width:94vw; max-height:90vh; overflow-y:auto;">
                <h3 style="margin:0 0 18px; font-size:1.1rem; font-weight:800;">➕ Nuevo desafío</h3>

                <div class="form-group" style="margin-bottom:16px;">
                    <label>Tipo de desafío</label>
                    <select id="add-ch-type" class="form-control" onchange="renderAddChallengeForm()">
                        <option value="multiple_choice">Opción múltiple</option>
                        <option value="true_false">Verdadero / Falso</option>
                        <option value="matching_pairs">Asociar pares</option>
                        <option value="sequence_order">Ordenar elementos</option>
                        <option value="classification">Clasificar elementos</option>
                        <option value="fill_blank_select">Completar espacios</option>
                    </select>
                </div>

                <div id="add-ch-form-body" style="margin-bottom:20px;"></div>

                <div style="display:flex; gap:10px; justify-content:flex-end; border-top:1px solid var(--border-color); padding-top:16px;">
                    <button type="button" onclick="document.getElementById('add-ch-modal').style.display='none'" style="background:transparent; border:1px solid var(--border-color); color:var(--text-secondary); border-radius:8px; padding:10px 20px; cursor:pointer; font-weight:600;">Cancelar</button>
                    <button type="button" onclick="confirmAddChallenge()" style="background:var(--accent-gradient); border:none; color:#fff; border-radius:8px; padding:10px 22px; font-weight:700; cursor:pointer;">Agregar</button>
                </div>
            </div>
        </div>

        <footer style="margin-top: auto; padding: 20px; text-align: center; font-size: 0.85rem; opacity: 0.5; font-weight: 300;">
            by Darwin Salina © 2026
        </footer>
    </div>

    <!-- SCRIPTS JS -->
    <script>
        // Navegación entre pestañas
        function switchTab(tabId) {
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            // Desactivar todos los botones
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            // Activar pestaña actual
            document.getElementById(tabId).classList.add('active');
            // Activar botón correspondiente
            const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').includes(tabId));
            if (btn) btn.classList.add('active');
        }

        // Copiar el Prompt al portapapeles
        function copyPromptToClipboard() {
            const textarea = document.getElementById('ai-prompt-box');
            textarea.select();
            textarea.setSelectionRange(0, 99999); // Para móviles
            
            try {
                navigator.clipboard.writeText(textarea.value).then(() => {
                    const btn = document.getElementById('btn-copy-prompt');
                    btn.innerText = '¡Copiado! ✓';
                    btn.style.background = 'var(--success-gradient)';
                    setTimeout(() => {
                        btn.innerText = 'Copiar Prompt';
                        btn.style.background = 'var(--accent-gradient)';
                    }, 2500);
                });
            } catch (err) {
                // Fallback clásico
                document.execCommand('copy');
                const btn = document.getElementById('btn-copy-prompt');
                btn.innerText = '¡Copiado! ✓';
                setTimeout(() => {
                    btn.innerText = 'Copiar Prompt';
                }, 2500);
            }
        }

        function updateQuestionPrompt() {
            const modeSelect = document.getElementById('prompt_activity_mode');
            const qtyInput = document.getElementById('prompt_question_count');
            const output = document.getElementById('ai-prompt-box');
            if (!modeSelect || !qtyInput || !output) return;

            const mode = modeSelect.value;
            const qty = Math.max(5, Math.min(100, parseInt(qtyInput.value, 10) || 30));

            const baseIntro = `Actúa como un diseñador de actividades educativas para estudiantes de aproximadamente 12 años.
A partir del documento de estudio que te aportaré como fuente de conocimiento, genera una actividad para TRIVIAX, un juego de tablero educativo donde cada casilla muestra un desafío breve.

Reglas generales:
- Usa español claro, escolar y adecuado para 12 años.
- Basa todos los desafíos estrictamente en la fuente provista.
- No incluyas explicaciones fuera del formato solicitado.
- Genera aproximadamente ${qty} desafíos.
- Evita contenidos ambiguos, duplicados o imposibles de corregir automáticamente.`;

            const txtPrompt = `${baseIntro}

Formato obligatorio: preguntas.txt clásico de TRIVIAX, solo opción múltiple.

Cada pregunta debe cumplir:
1. Comenzar con número, punto y espacio: "1. Texto de la pregunta".
2. Tener 3 o 4 respuestas.
3. Cada respuesta debe comenzar con "@ ".
4. La única respuesta correcta debe comenzar con "@ *".
5. No mostrar más de una respuesta correcta.
6. No usar encabezados JSON, tablas ni comentarios.
7. Dejar una línea en blanco entre preguntas.

Ejemplo:
1. ¿Cuál es la función principal de la CPU?
@ *Procesar instrucciones.
@ Imprimir documentos.
@ Mostrar imágenes en pantalla.

Aquí está el documento de estudio:`;

            const jsonSchemas = {
                matching_pairs_json: `Usa exclusivamente desafíos "matching_pairs" para asociar pares.
Cada desafío debe tener entre 2 y 6 pares:
{
  "id": "mp_001",
  "type": "matching_pairs",
  "title": "Relaciona conceptos",
  "prompt": { "text": "Une cada término con su definición." },
  "timeLimitSeconds": 30,
  "points": 10,
  "difficulty": 1,
  "pairs": [
    { "left": "CPU", "right": "Procesa instrucciones" },
    { "left": "RAM", "right": "Memoria temporal" }
  ]
}`,
                sequence_order_json: `Usa exclusivamente desafíos "sequence_order" para ordenar elementos.
Cada desafío debe tener entre 3 y 8 elementos y answer.order debe repetir el orden correcto:
{
  "id": "seq_001",
  "type": "sequence_order",
  "title": "Ordena el proceso",
  "prompt": { "text": "Ordena los pasos correctos." },
  "timeLimitSeconds": 35,
  "points": 10,
  "difficulty": 1,
  "items": ["Paso 1", "Paso 2", "Paso 3"],
  "answer": { "order": ["Paso 1", "Paso 2", "Paso 3"] }
}`,
                classification_json: `Usa exclusivamente desafíos "classification" para clasificar elementos.
Cada desafío debe tener al menos 2 categorías y al menos 4 elementos:
{
  "id": "class_001",
  "type": "classification",
  "title": "Clasifica elementos",
  "prompt": { "text": "Clasifica cada elemento según corresponda." },
  "timeLimitSeconds": 35,
  "points": 10,
  "difficulty": 1,
  "categories": [
    { "id": "hardware", "label": "Hardware" },
    { "id": "software", "label": "Software" }
  ],
  "items": [
    { "id": "teclado", "text": "Teclado", "categoryId": "hardware" },
    { "id": "sistema", "text": "Sistema operativo", "categoryId": "software" }
  ]
}`,
                fill_blank_select_json: `Usa exclusivamente desafíos "fill_blank_select" para completar espacios con desplegables.
Cada marcador [blankN] debe existir en blanks, con al menos 2 opciones y una correcta:
{
  "id": "blank_001",
  "type": "fill_blank_select",
  "title": "Completa la frase",
  "prompt": { "text": "La [blank1] procesa instrucciones." },
  "timeLimitSeconds": 30,
  "points": 10,
  "difficulty": 1,
  "blanks": {
    "blank1": {
      "options": ["CPU", "RAM", "Monitor"],
      "correct": "CPU"
    }
  }
}`
            };

            if (mode === 'multiple_choice_txt') {
                output.value = txtPrompt;
                return;
            }

            const mixedInstruction = mode === 'mixed_json'
                ? `Genera una actividad mixta combinando estos tipos en proporción equilibrada: multiple_choice, true_false, matching_pairs, sequence_order, classification y fill_blank_select.
Para multiple_choice usa options con 3 o 4 opciones y una sola correct:true.
Para true_false usa answer.value con true o false.
Para classification usa SIEMPRE categories como lista de objetos { "id", "label" } e items como lista separada de objetos { "id", "text", "categoryId" }. No pongas items dentro de cada categoria.
Para fill_blank_select usa SIEMPRE blanks como objeto: { "blank1": { "options": [...], "correct": "..." } }. No uses blanks como arreglo.
Ejemplo classification valido: { "id":"class_001", "type":"classification", "prompt":{"text":"Clasifica."}, "categories":[{"id":"cat_a","label":"Categoria A"},{"id":"cat_b","label":"Categoria B"}], "items":[{"id":"item_1","text":"Elemento 1","categoryId":"cat_a"},{"id":"item_2","text":"Elemento 2","categoryId":"cat_b"},{"id":"item_3","text":"Elemento 3","categoryId":"cat_a"},{"id":"item_4","text":"Elemento 4","categoryId":"cat_b"}], "timeLimitSeconds":35, "points":10, "difficulty":1 }.
Ejemplo fill_blank_select valido: { "id":"blank_001", "type":"fill_blank_select", "prompt":{"text":"La [blank1] completa la frase."}, "blanks":{"blank1":{"options":["opcion correcta","distractor"],"correct":"opcion correcta"}}, "timeLimitSeconds":30, "points":10, "difficulty":1 }.
Para las demás modalidades respeta los esquemas de ejemplo de abajo.`
                : jsonSchemas[mode];

            output.value = `${baseIntro}

Formato obligatorio: devuelve únicamente un JSON válido para TRIVIAX, sin Markdown, sin comentarios y sin texto antes ni después.

Estructura raíz:
{
  "metadata": {
    "title": "Título sugerido",
    "author": "",
    "nivel": "",
    "obs": "Tira el dado y resuelve los desafíos.",
    "date": "",
    "background": "fondo.jpg"
  },
  "board": { "type": "serpentine" },
  "challenges": []
}

${mixedInstruction}

Validaciones obligatorias:
- Cada id debe ser único.
- Cada desafío debe tener prompt.text.
- Usa timeLimitSeconds entre 20 y 40 según dificultad.
- Usa points: 10 y difficulty: 1 salvo que haya una razón pedagógica clara.
- No inventes campos incompatibles con los ejemplos.

Aquí está el documento de estudio:`;
        }

        // Generar prompt de fondo dinámicamente
        function addSelectOptionIfMissing(selectId, value, label) {
            const select = document.getElementById(selectId);
            if (!select || Array.from(select.options).some(option => option.value === value)) return;
            const option = document.createElement('option');
            option.value = value;
            option.text = label;
            select.appendChild(option);
        }

        function ensurePromptSelectVariants() {
            addSelectOptionIfMissing('bg_style', 'acuarela_editorial', 'Acuarela editorial (Ilustración suave, textura de papel y detalles delicados)');
            addSelectOptionIfMissing('bg_style', 'isometrico_3d', 'Isométrico 3D educativo (Objetos limpios, profundidad suave y estilo maqueta)');
            addSelectOptionIfMissing('bg_style', 'collage_papel', 'Collage de papel recortado (Capas artesanales, texturas escolares y composición lúdica)');
            addSelectOptionIfMissing('bg_color', 'alto_contraste_calido', 'Alto contraste cálido (Borgoña, dorado, coral y sombras profundas)');
            addSelectOptionIfMissing('bg_color', 'naturaleza_fresca', 'Naturaleza fresca (Verdes, celestes, blanco suave y acentos solares)');
            addSelectOptionIfMissing('bg_color', 'monocromo_acento', 'Monocromo con acento (Grises claros/oscuros con un único color destacado)');
        }

        function updateBgPrompt() {
            const themeInput = document.getElementById('bg_theme').value.trim();
            const unitsInput = document.getElementById('bg_units').value.trim();
            const styleSelect = document.getElementById('bg_style');
            const styleText = styleSelect.options[styleSelect.selectedIndex].text;
            const colorSelect = document.getElementById('bg_color');
            const colorText = colorSelect.options[colorSelect.selectedIndex].text;

            const theme = themeInput || "[Definir tema principal, ej: Historia Universal o La Célula]";
            const units = unitsInput ? ` (enfocado específicamente en: ${unitsInput})` : "";

            const stylePrompts = {
                'ilustracion_educativa': 'Ilustración digital de estilo plano y vectorial, moderna, limpia, con bordes definidos, colores vibrantes y amigable para un contexto educativo.',
                'fantasia_medieval': 'Fantasía medieval épica, mística y aventurera, estilo concept art para videojuegos RPG, con iluminación ambiental mágica.',
                'tecnologico_futurista': 'Estilo tecnológico, cibernético y de ciencia ficción, con detalles digitales, circuitos sutiles y acentos de luces de neón modernas.',
                'historico_vintage': 'Estilo histórico y vintage, imitando un mapa antiguo en pergamino envejecido, con texturas clásicas, ilustraciones náuticas, brújulas o grabados retro.',
                'dibujo_infantil': 'Dibujo animado infantil, sumamente alegre, caricaturesco, simplificado, con personajes o formas divertidas y colores muy saturados.',
                'espacial_cosmos': 'Fondo espacial de ciencia ficción y astronomía, mostrando el cosmos profundo con galaxias en espiral, nebulosas coloridas de polvo estelar y estrellas lejanas.'
            };

            const colorPrompts = {
                'oscura_contrastante': 'Paleta de colores oscura y de alto contraste (predominando negros, grises profundos y tonos nocturnos con pequeños toques de color neón luminosos en bordes), ideal para que resalten elementos superpuestos brillantes.',
                'tonos_pastel': 'Paleta de colores pasteles, muy suaves, de baja saturación y alto brillo (cremas, lavandas, verdes agua, celestes apagados), que transmitan calma y no saturen visualmente.',
                'colores_vivos': 'Paleta de colores vivos, alegres, enérgicos y altamente saturados, combinando tonos complementarios para un aspecto dinámico y festivo.',
                'tonos_tierra': 'Paleta de colores tierra y texturas cálidas, dominada por tonos ocre, beige, siena, marrón cuero y pergamino envejecido.',
                'azules_grises': 'Paleta de colores tecnológicos basados en tonos fríos de azul cobalto, grises metalizados, turquesas y cian.'
            };

            const chosenStyle = stylePrompts[styleSelect.value] || styleText;
            const chosenColor = colorPrompts[colorSelect.value] || colorText;

            let prompt = `Actúa como un diseñador gráfico profesional y experto en la generación de imágenes con Inteligencia Artificial.
Tu tarea es generar una imagen de fondo (background) que represente visualmente la temática de estudio proporcionada en el documento de referencia adjunto, centrándote en el tema: "${theme}"${units}.

Esta imagen de fondo se utilizará en la interfaz web de un videojuego educativo de tablero llamado TRIVIAX. Por lo tanto, debe cumplir estrictamente con las siguientes especificaciones técnicas y de diseño:

1. Orientación y Relación de Aspecto: Orientación horizontal (landscape), relación de aspecto 16:9 estricta (resolución recomendada de renderizado: 1920x1080 píxeles).
2. Composición para Tablero: La imagen debe ser una composición de fondo equilibrada. El centro y la mayor parte del lienzo deben ser limpios, con un contraste sutil o texturas suaves y sin sobrecarga de detalles, de modo que se pueda dibujar por encima un camino de 50 casillas y fichas de juego de colores brillantes (rojo, azul, verde, amarillo) sin perder legibilidad. Los elementos visuales más delatados o representativos deben ubicarse preferentemente en los márgenes, esquinas o bordes laterales de la imagen.
3. Sin Elementos de Interfaz ni Texto: La imagen NO debe incluir ningún tipo de texto, letras, números, símbolos, botones, ni casillas de tablero dibujadas. La IA debe abstenerse por completo de integrar estos elementos.
4. Estilo Artístico: ${chosenStyle}
5. Paleta de Colores: ${chosenColor}
6. Contenido Conceptual: Inspírate en los conceptos clave descritos en el documento adjunto para crear ilustraciones ambientales o alegorías temáticas en las esquinas o laterales del fondo, dejando libre la zona de juego central.`;

            document.getElementById('ai-bg-prompt-box').value = prompt;
        }

        // Copiar el Prompt de Fondo al portapapeles
        function copyBgPromptToClipboard() {
            const textarea = document.getElementById('ai-bg-prompt-box');
            textarea.select();
            textarea.setSelectionRange(0, 99999);
            
            try {
                navigator.clipboard.writeText(textarea.value).then(() => {
                    const btn = document.getElementById('btn-copy-bg-prompt');
                    btn.innerText = '¡Copiado! ✓';
                    btn.style.background = 'var(--success-gradient)';
                    setTimeout(() => {
                        btn.innerText = 'Copiar Prompt de Fondo';
                        btn.style.background = 'var(--accent-gradient)';
                    }, 2500);
                });
            } catch (err) {
                document.execCommand('copy');
                const btn = document.getElementById('btn-copy-bg-prompt');
                btn.innerText = '¡Copiado! ✓';
                setTimeout(() => {
                    btn.innerText = 'Copiar Prompt de Fondo';
                }, 2500);
            }
        }

        // Inicializar prompt de fondo al cargar la página
        updateQuestionPrompt();
        ensurePromptSelectVariants();
        updateBgPrompt();

        // ==========================================
        // LÓGICA DEL ASISTENTE (WIZARD) DE CREACIÓN
        // ==========================================
        let currentWizardStep = 1;
        let selectedQuestionsMethod = ''; // 'ia' o 'manual'
        const shouldRestoreQuestionStep = <?php echo (!empty($errorMsg) && (!empty($formValues['questions_text']) || !empty($formValues['activity_json']))) ? 'true' : 'false'; ?>;

        window.goToStep = function(step) {
            // Validación del Paso 1: Ficha técnica básica obligatoria
            if (step === 2 && currentWizardStep === 1) {
                const title = document.getElementById('title');
                const author = document.getElementById('author');
                if (!title.reportValidity() || !author.reportValidity()) {
                    return;
                }
            }
            
            // Ocultar todos los contenedores de paso
            document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');
            
            // Determinar a qué paso navegar
            if (typeof step === 'number') {
                currentWizardStep = step;
                if (step === 3) {
                    if (selectedQuestionsMethod === 'ia') {
                        document.getElementById('step-3-ia').style.display = 'block';
                    } else {
                        document.getElementById('step-3-manual-config').style.display = 'block';
                    }
                } else {
                    document.getElementById('step-' + step).style.display = 'block';
                }
            } else if (step === 'manual-config') {
                currentWizardStep = 3;
                document.getElementById('step-3-manual-config').style.display = 'block';
            } else if (step === 'manual-form') {
                currentWizardStep = 4;
                document.getElementById('step-4-manual-form').style.display = 'block';
            }
            
            updateWizardProgress();
        };

        window.selectLoadMethod = function(method) {
            selectedQuestionsMethod = method;
            const textarea = document.getElementById('questions_text');
            
            if (method === 'ia') {
                textarea.required = true;
                window.goToStep(3);
            } else {
                textarea.required = false;
                window.goToStep(3);
            }
        };

        function updateWizardProgress() {
            // Actualizar los estados activos y completados de los círculos indicadores
            for (let i = 1; i <= 4; i++) {
                const stepEl = document.getElementById('prog-step-' + i);
                if (!stepEl) continue;
                
                stepEl.classList.remove('active', 'completed');
                
                if (i === currentWizardStep) {
                    stepEl.classList.add('active');
                } else if (i < currentWizardStep) {
                    stepEl.classList.add('completed');
                }
            }
            
            // Actualizar el porcentaje de llenado de la barra de progreso
            const fillEl = document.getElementById('progress-line-fill');
            if (fillEl) {
                let percent = 0;
                if (currentWizardStep === 2) percent = 33;
                else if (currentWizardStep === 3) percent = 66;
                else if (currentWizardStep === 4) percent = 100;
                fillEl.style.width = percent + '%';
            }
        }

        // Generación del Formulario Dinámico Manual
        window.updateManualTypeOptions = function() {
            const type = document.getElementById('manual_activity_type').value;
            document.getElementById('manual_opts_group').style.display = type === 'multiple_choice' ? 'flex' : 'none';
            document.getElementById('manual_pairs_group').style.display = type === 'matching_pairs' ? 'flex' : 'none';
            document.getElementById('manual_sequence_group').style.display = type === 'sequence_order' ? 'flex' : 'none';
            const qtyHelp = document.getElementById('manual_qty_help');
            if (qtyHelp) {
                qtyHelp.innerText = type === 'multiple_choice'
                    ? 'Indica cuántas preguntas tendrá este juego.'
                    : 'Indica cuántos desafíos tendrá esta actividad mixta.';
            }
        };

        window.initManualFormCreation = function() {
            const qtyInput = document.getElementById('manual_qty');
            const qty = parseInt(qtyInput.value);
            const activityType = document.getElementById('manual_activity_type').value;
            const optsQty = parseInt(document.getElementById('manual_opts_qty').value);
            const pairQty = parseInt(document.getElementById('manual_pairs_qty').value);
            const sequenceQty = parseInt(document.getElementById('manual_sequence_qty').value);
            
            if (isNaN(qty) || qty < 1 || qty > 100) {
                alert('Por favor, ingresa una cantidad de preguntas válida (entre 1 y 100).');
                qtyInput.focus();
                return;
            }
            
            const container = document.getElementById('dynamic-questions-container');
            if (container.children.length > 0) {
                if (!confirm('Si continúas, se borrarán las preguntas actuales de la lista. ¿Deseas generar una nueva lista vacía?')) {
                    return;
                }
            }
            
            generateManualForm(qty, optsQty, activityType, pairQty, sequenceQty);
            window.goToStep('manual-form');
        };

        function generateManualForm(qty, optsQty, activityType, pairQty, sequenceQty) {
            const container = document.getElementById('dynamic-questions-container');
            container.innerHTML = ''; // Limpiar previo
            
            for (let q = 1; q <= qty; q++) {
                const card = document.createElement('div');
                card.className = 'manual-q-card';
                card.setAttribute('data-question-index', q);
                card.setAttribute('data-activity-type', activityType);
                
                // Cabecera
                const header = document.createElement('div');
                header.className = 'manual-q-header';
                const typeLabel = {
                    multiple_choice: 'Pregunta',
                    matching_pairs: 'Asociación',
                    sequence_order: 'Ordenamiento'
                }[activityType] || 'Desafío';
                header.innerHTML = `
                    <span>${typeLabel} ${q} de ${qty}</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">* Obligatorio</span>
                `;
                card.appendChild(header);
                
                // Enunciado
                const qGroup = document.createElement('div');
                qGroup.className = 'form-group';
                qGroup.style.marginBottom = '20px';
                qGroup.innerHTML = `
                    <label>Consigna <span style="color:var(--danger)">*</span></label>
                    <input type="text" class="form-control manual-q-text" placeholder="Escribe la consigna ${q}..." required>
                `;
                card.appendChild(qGroup);

                if (activityType === 'matching_pairs') {
                    const pairLabel = document.createElement('label');
                    pairLabel.innerHTML = 'Pares correctos <span style="color:var(--danger)">*</span>';
                    pairLabel.style.display = 'block';
                    pairLabel.style.marginBottom = '12px';
                    pairLabel.style.fontSize = '0.9rem';
                    pairLabel.style.fontWeight = '600';
                    card.appendChild(pairLabel);

                    const pairsContainer = document.createElement('div');
                    pairsContainer.style.display = 'flex';
                    pairsContainer.style.flexDirection = 'column';
                    pairsContainer.style.gap = '10px';

                    for (let p = 1; p <= pairQty; p++) {
                        const pairRow = document.createElement('div');
                        pairRow.className = 'manual-ans-row manual-pair-row';
                        pairRow.innerHTML = `
                            <span style="font-weight: 800; color: var(--text-secondary); font-size: 0.95rem; flex-shrink: 0; width: 28px;">${p})</span>
                            <input type="text" class="manual-pair-left" placeholder="Elemento izquierdo..." required>
                            <span style="color: var(--text-muted); font-weight: 800;">→</span>
                            <input type="text" class="manual-pair-right" placeholder="Pareja correcta..." required>
                        `;
                        pairsContainer.appendChild(pairRow);
                    }

                    card.appendChild(pairsContainer);
                    container.appendChild(card);
                    continue;
                }

                if (activityType === 'sequence_order') {
                    const sequenceLabel = document.createElement('label');
                    sequenceLabel.innerHTML = 'Elementos en orden correcto <span style="color:var(--danger)">*</span>';
                    sequenceLabel.style.display = 'block';
                    sequenceLabel.style.marginBottom = '12px';
                    sequenceLabel.style.fontSize = '0.9rem';
                    sequenceLabel.style.fontWeight = '600';
                    card.appendChild(sequenceLabel);

                    const sequenceContainer = document.createElement('div');
                    sequenceContainer.style.display = 'flex';
                    sequenceContainer.style.flexDirection = 'column';
                    sequenceContainer.style.gap = '10px';

                    for (let s = 1; s <= sequenceQty; s++) {
                        const sequenceRow = document.createElement('div');
                        sequenceRow.className = 'manual-ans-row manual-sequence-row';
                        sequenceRow.innerHTML = `
                            <span style="font-weight: 800; color: var(--text-secondary); font-size: 0.95rem; flex-shrink: 0; width: 28px;">${s})</span>
                            <input type="text" class="manual-sequence-item" placeholder="Elemento ${s} en el orden correcto..." required>
                        `;
                        sequenceContainer.appendChild(sequenceRow);
                    }

                    card.appendChild(sequenceContainer);
                    container.appendChild(card);
                    continue;
                }
                
                // Respuestas
                const ansLabel = document.createElement('label');
                ansLabel.innerHTML = 'Opciones de respuesta <span style="color:var(--danger)">*</span> <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary); margin-left: 10px;">(Selecciona el botón verde para marcar la correcta)</span>';
                ansLabel.style.display = 'block';
                ansLabel.style.marginBottom = '12px';
                ansLabel.style.fontSize = '0.9rem';
                ansLabel.style.fontWeight = '600';
                card.appendChild(ansLabel);
                
                const answersContainer = document.createElement('div');
                answersContainer.style.display = 'flex';
                answersContainer.style.flexDirection = 'column';
                answersContainer.style.gap = '10px';
                
                for (let o = 1; o <= optsQty; o++) {
                    const ansRow = document.createElement('div');
                    ansRow.className = 'manual-ans-row';
                    ansRow.id = `q-${q}-ans-row-${o}`;
                    
                    const charCode = String.fromCharCode(64 + o); // A, B, C, D
                    
                    ansRow.innerHTML = `
                        <span style="font-weight: 800; color: var(--text-secondary); font-size: 0.95rem; flex-shrink: 0; width: 20px;">${charCode})</span>
                        <input type="text" class="manual-ans-text" placeholder="Escribe la opción de respuesta ${charCode}..." required>
                        <label class="correct-toggle-container">
                            <input type="radio" name="correct_ans_${q}" id="q-${q}-correct-${o}" value="${o}" class="correct-radio" required onchange="onCorrectAnswerChange(${q}, ${o}, ${optsQty})">
                            <span class="correct-label-hint">Correcta</span>
                        </label>
                    `;
                    answersContainer.appendChild(ansRow);
                }
                
                card.appendChild(answersContainer);
                container.appendChild(card);
            }
        }

        // Estilos para la respuesta correcta seleccionada
        window.onCorrectAnswerChange = function(qIdx, oIdx, totalOpts) {
            for (let o = 1; o <= totalOpts; o++) {
                const row = document.getElementById(`q-${qIdx}-ans-row-${o}`);
                if (row) {
                    if (o === oIdx) {
                        row.classList.add('is-correct');
                    } else {
                        row.classList.remove('is-correct');
                    }
                }
            }
        };

        // Compilar y enviar formulario
        window.submitManualForm = function() {
            const form = document.getElementById('create-activity-form');
            const cards = document.querySelectorAll('.manual-q-card');
            const activityType = document.getElementById('manual_activity_type').value;
            let isValid = true;
            let firstInvalidEl = null;

            if (activityType === 'matching_pairs' || activityType === 'sequence_order') {
                const challenges = [];

                for (let card of cards) {
                    const qIdx = card.getAttribute('data-question-index');
                    const qTextInput = card.querySelector('.manual-q-text');
                    const qText = qTextInput.value.trim();

                    if (qText === '') {
                        isValid = false;
                        qTextInput.style.borderColor = 'var(--danger)';
                        if (!firstInvalidEl) firstInvalidEl = qTextInput;
                    } else {
                        qTextInput.style.borderColor = 'var(--border-color)';
                    }

                    if (activityType === 'matching_pairs') {
                        const pairs = [];
                        card.querySelectorAll('.manual-pair-row').forEach(row => {
                            const leftInput = row.querySelector('.manual-pair-left');
                            const rightInput = row.querySelector('.manual-pair-right');
                            const left = leftInput.value.trim();
                            const right = rightInput.value.trim();

                            if (left === '' || right === '') {
                                isValid = false;
                                row.style.borderColor = 'var(--danger)';
                                if (!firstInvalidEl) firstInvalidEl = left === '' ? leftInput : rightInput;
                            } else {
                                row.style.borderColor = 'var(--border-color)';
                            }

                            pairs.push({ left, right });
                        });

                        challenges.push({
                            id: `mp_${String(qIdx).padStart(3, '0')}`,
                            type: 'matching_pairs',
                            title: `Asociacion ${qIdx}`,
                            prompt: { text: qText },
                            timeLimitSeconds: 30,
                            points: 10,
                            difficulty: 1,
                            pairs
                        });
                    } else {
                        const items = [];
                        card.querySelectorAll('.manual-sequence-item').forEach(input => {
                            const itemText = input.value.trim();
                            if (itemText === '') {
                                isValid = false;
                                input.closest('.manual-ans-row').style.borderColor = 'var(--danger)';
                                if (!firstInvalidEl) firstInvalidEl = input;
                            } else {
                                input.closest('.manual-ans-row').style.borderColor = 'var(--border-color)';
                            }
                            items.push(itemText);
                        });

                        challenges.push({
                            id: `seq_${String(qIdx).padStart(3, '0')}`,
                            type: 'sequence_order',
                            title: `Ordenamiento ${qIdx}`,
                            prompt: { text: qText },
                            timeLimitSeconds: 35,
                            points: 10,
                            difficulty: 1,
                            items,
                            answer: { order: items }
                        });
                    }
                }

                if (!isValid) {
                    alert('Por favor, completa todos los campos obligatorios antes de crear la actividad.');
                    if (firstInvalidEl) {
                        firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        if (typeof firstInvalidEl.focus === 'function') firstInvalidEl.focus();
                    }
                    return;
                }

                document.getElementById('questions_text').value = '';
                document.getElementById('activity_json').value = JSON.stringify({ challenges });
                form.submit();
                return;
            }
            
            document.getElementById('activity_json').value = '';

            // Validaciones locales
            for (let card of cards) {
                const qIdx = card.getAttribute('data-question-index');
                const qText = card.querySelector('.manual-q-text');
                const ansTexts = card.querySelectorAll('.manual-ans-text');
                const correctRadio = card.querySelector(`input[name="correct_ans_${qIdx}"]:checked`);
                
                // Validar texto de pregunta
                if (qText.value.trim() === '') {
                    isValid = false;
                    qText.style.borderColor = 'var(--danger)';
                    if (!firstInvalidEl) firstInvalidEl = qText;
                } else {
                    qText.style.borderColor = 'var(--border-color)';
                }
                
                // Validar respuestas completadas
                ansTexts.forEach(ansInput => {
                    if (ansInput.value.trim() === '') {
                        isValid = false;
                        ansInput.closest('.manual-ans-row').style.borderColor = 'var(--danger)';
                        if (!firstInvalidEl) firstInvalidEl = ansInput;
                    } else {
                        ansInput.closest('.manual-ans-row').style.borderColor = 'var(--border-color)';
                    }
                });
                
                // Validar que se seleccionó una correcta
                if (!correctRadio) {
                    isValid = false;
                    card.querySelectorAll('.correct-radio').forEach(r => r.style.borderColor = 'var(--danger)');
                    if (!firstInvalidEl) firstInvalidEl = card;
                } else {
                    card.querySelectorAll('.correct-radio').forEach(r => r.style.borderColor = 'var(--text-muted)');
                }
            }
            
            if (!isValid) {
                alert('Por favor, completa todas las preguntas, sus respuestas y selecciona cuál es la correcta para cada una.');
                if (firstInvalidEl) {
                    firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (typeof firstInvalidEl.focus === 'function') firstInvalidEl.focus();
                }
                return;
            }
            
            // Compilar al formato plano de TRIVIAX
            let textOutput = '';
            
            cards.forEach(card => {
                const qIdx = card.getAttribute('data-question-index');
                const qText = card.querySelector('.manual-q-text').value.trim();
                const ansInputs = card.querySelectorAll('.manual-ans-text');
                const correctRadioVal = parseInt(card.querySelector(`input[name="correct_ans_${qIdx}"]:checked`).value);
                
                textOutput += `${qIdx}. ${qText}\r\n`;
                
                ansInputs.forEach((ansInput, aIdx) => {
                    const optionIndex = aIdx + 1;
                    const isCorrect = (optionIndex === correctRadioVal);
                    const prefix = isCorrect ? '@ *' : '@ ';
                    textOutput += `${prefix}${ansInput.value.trim()}\r\n`;
                });
                
                textOutput += '\r\n'; // Salto de línea entre preguntas
            });
            
            // Asignar al campo oculto/textarea
            const textarea = document.getElementById('questions_text');
            textarea.value = textOutput;
            
            // Enviar formulario final al PHP
            form.submit();
        };

                window.deleteProject = function(folderName, title) {
            if (!confirm(`¿Estás seguro de que deseas trasladar la actividad "${title}" (carpeta: proyectos/${folderName}/) a la papelera?\n\nLa actividad dejará de estar disponible para jugar, pero sus archivos no se eliminarán de forma definitiva.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_activity');
            formData.append('project', folderName);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.error || 'Error desconocido.'); });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('La actividad se ha trasladado a la papelera con éxito.');
                    window.location.reload();
                } else {
                    alert('Error al trasladar la actividad: ' + (data.error || 'Error desconocido.'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ocurrió un error de red o de servidor al procesar la solicitud: ' + error.message);
            });
        };

        if (shouldRestoreQuestionStep) {
            selectedQuestionsMethod = 'ia';
            window.goToStep(3);
            const textarea = document.getElementById('questions_text');
            if (textarea && textarea.value.trim() !== '') {
                textarea.focus();
            }
        }

        // ══════════════════════════════════════════════════════════════
        //  SISTEMA DE EDICIÓN DE ACTIVIDADES
        // ══════════════════════════════════════════════════════════════

        // Estado interno del editor
        const _ep = {
            project: '',          // slug de la carpeta
            challenges: [],       // copia mutable del array de challenges
            expandedIdx: -1,      // índice del challenge con editor abierto
        };

        // Tipos conocidos y sus etiquetas
        const CH_TYPE_LABELS = {
            multiple_choice:  'Opción múltiple',
            true_false:       'Verdadero/Falso',
            matching_pairs:   'Asociar pares',
            sequence_order:   'Ordenar elementos',
            classification:   'Clasificar elementos',
            fill_blank:       'Completar espacios',
            fill_blank_select:'Completar espacios',
            drag_drop:        'Clasificar (drag_drop)',
            media_choice:     'Multimedia',
            image_hotspot:    'Zona en imagen',
            code_challenge:   'Código',
        };

        const CH_TYPE_COLORS = {
            multiple_choice: '#6366f1', true_false: '#10b981',
            matching_pairs: '#f59e0b', sequence_order: '#3b82f6',
            classification: '#a855f7', fill_blank: '#ec4899',
            fill_blank_select: '#ec4899', drag_drop: '#a855f7',
        };

        // ── Abrir panel ──────────────────────────────────────────────
        window.openEditPanel = function(folder, title) {
            _ep.project    = folder;
            _ep.challenges = [];
            _ep.expandedIdx = -1;

            switchTab('tab-list');

            const panel = document.getElementById('edit-panel');
            panel.style.display = 'block';
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

            document.getElementById('edit-project-slug-label').textContent = 'proyectos/' + folder + '/';
            document.getElementById('edit-loading').style.display = 'block';
            document.getElementById('edit-body').style.display    = 'none';

            // Cargar datos del proyecto
            fetch('api.php?action=get&project=' + encodeURIComponent(folder))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Error al cargar.');
                    populateEditForm(data);
                })
                .catch(err => {
                    document.getElementById('edit-loading').innerHTML =
                        '<span style="color:#f87171">⚠️ ' + err.message + '</span>';
                });
        };

        function populateEditForm(data) {
            const meta = data.metadata || {};
            document.getElementById('ep-title').value  = meta.title  || '';
            document.getElementById('ep-author').value = meta.author || '';
            document.getElementById('ep-nivel').value  = meta.nivel  || '';
            document.getElementById('ep-mail').value   = meta.mail   || '';
            document.getElementById('ep-obs').value    = meta.obs    || '';
            const epBoard = document.getElementById('ep-board-locked');
            if (epBoard) epBoard.value = (data.board && data.board.lockedId) ? data.board.lockedId : '';

            // Normalizar challenges (soporta formato json y txt parseado)
            const raw = data.questions || [];
            _ep.challenges = raw.map((ch, i) => normalizeChallengeForEditor(ch, i));

            renderChallengeList();

            document.getElementById('edit-loading').style.display = 'none';
            document.getElementById('edit-body').style.display    = 'block';
        }

        // Convierte el formato txt (id/text/answers) al formato json challenge
        function normalizeChallengeForEditor(ch, idx) {
            if (ch.type) return ch;  // ya es formato json
            // Es formato txt (id, text, answers[])
            const opts = (ch.answers || []).map((a, ai) => ({
                id: String.fromCharCode(97 + ai),
                text: a.text,
                correct: !!a.correct,
            }));
            return {
                id: 'mc_' + String(idx + 1).padStart(3, '0'),
                type: 'multiple_choice',
                title: 'Pregunta ' + (idx + 1),
                prompt: { text: ch.text || '' },
                timeLimitSeconds: 20,
                points: 10,
                difficulty: 1,
                options: opts,
            };
        }

        // ── Cerrar panel ─────────────────────────────────────────────
        window.closeEditPanel = function() {
            document.getElementById('edit-panel').style.display = 'none';
            _ep.project = '';
            _ep.challenges = [];
        };

        // ── Renderizar lista de challenges ───────────────────────────
        function renderChallengeList() {
            const list = document.getElementById('ep-challenges-list');
            document.getElementById('ep-ch-count').textContent = _ep.challenges.length;

            if (_ep.challenges.length === 0) {
                list.innerHTML = '<p style="color:var(--text-muted); font-style:italic; text-align:center; padding:24px 0;">No hay desafíos. Agrega uno con el botón de arriba.</p>';
                return;
            }

            list.innerHTML = '';
            _ep.challenges.forEach((ch, idx) => {
                const card = document.createElement('div');
                card.id = 'ep-ch-card-' + idx;
                card.style.cssText = 'background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:12px; overflow:hidden;';

                const type  = ch.type || 'multiple_choice';
                const color = CH_TYPE_COLORS[type] || '#6366f1';
                const label = CH_TYPE_LABELS[type] || type;
                const promptText = (ch.prompt?.text || ch.text || '(sin enunciado)').substring(0, 90);

                card.innerHTML = `
                    <div id="ep-ch-header-${idx}" style="display:flex; align-items:center; gap:12px; padding:12px 16px; cursor:pointer;"
                         onclick="toggleChallengeEditor(${idx})" title="Clic para expandir/colapsar">
                        <span id="ep-ch-chev-${idx}" style="flex-shrink:0; color:var(--text-muted); font-size:0.7rem; transition:transform 0.2s;">▶</span>
                        <span style="flex-shrink:0; min-width:28px; height:28px; display:flex; align-items:center; justify-content:center;
                               background:rgba(99,102,241,0.12); border-radius:50%; font-size:0.78rem; font-weight:800; color:#a5b4fc;">${idx + 1}</span>
                        <span style="flex-shrink:0; background:${color}22; border:1px solid ${color}55; color:${color}; border-radius:20px;
                               padding:2px 10px; font-size:0.72rem; font-weight:700;">${label}</span>
                        <span style="flex:1; color:var(--text-secondary); font-size:0.88rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                              title="${escHtml(promptText)}">${escHtml(promptText)}</span>
                        <div style="display:flex; gap:5px; flex-shrink:0;" onclick="event.stopPropagation()">
                            <button title="Subir" onclick="moveChallenge(${idx},-1)" style="${chBtnStyle('#6366f1')}">↑</button>
                            <button title="Bajar" onclick="moveChallenge(${idx},1)"  style="${chBtnStyle('#6366f1')}">↓</button>
                            <button title="Eliminar" onclick="deleteChallenge(${idx})" style="${chBtnStyle('#ef4444')}">🗑️</button>
                        </div>
                    </div>
                    <div id="ep-ch-editor-${idx}" style="display:none; border-top:1px solid var(--border-color); padding:18px 18px 14px;">
                        ${buildChallengeEditorHTML(ch, idx)}
                    </div>`;
                list.appendChild(card);
            });
        }

        function chBtnStyle(color) {
            return `background:${color}18; border:1px solid ${color}40; color:${color}; border-radius:6px; padding:4px 8px; font-size:0.78rem; cursor:pointer;`;
        }
        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Toggle editor inline ──────────────────────────────────────
        window.toggleChallengeEditor = function(idx) {
            const el = document.getElementById('ep-ch-editor-' + idx);
            if (!el) return;
            const open = el.style.display !== 'none';
            // Cerrar todos
            document.querySelectorAll('[id^="ep-ch-editor-"]').forEach(e => e.style.display = 'none');
            document.querySelectorAll('[id^="ep-ch-chev-"]').forEach(c => c.style.transform = '');
            if (!open) {
                el.style.display = 'block';
                const chev = document.getElementById('ep-ch-chev-' + idx);
                if (chev) chev.style.transform = 'rotate(90deg)';
            }
        };

        // Colapsa el editor de un desafío (vuelve a la vista de un renglón)
        window.collapseChallengeEditor = function(idx) {
            const el = document.getElementById('ep-ch-editor-' + idx);
            if (el) el.style.display = 'none';
            const chev = document.getElementById('ep-ch-chev-' + idx);
            if (chev) chev.style.transform = '';
        };

        // ── Constructores de formularios por tipo ─────────────────────
        function buildChallengeEditorHTML(ch, idx) {
            const type = ch.type || 'multiple_choice';
            switch (type) {
                case 'multiple_choice': return buildMCEditor(ch, idx);
                case 'true_false':      return buildTFEditor(ch, idx);
                case 'matching_pairs':  return buildMPEditor(ch, idx);
                case 'sequence_order':  return buildSOEditor(ch, idx);
                case 'classification':  return buildClassEditor(ch, idx);
                case 'fill_blank':
                case 'fill_blank_select': return buildFBEditor(ch, idx);
                default:                return buildRawEditor(ch, idx);
            }
        }

        function editorPromptRow(idx, val) {
            return `<div style="margin-bottom:12px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:5px;">Enunciado / Consigna <span style="color:var(--danger)">*</span></label>
                <input type="text" id="ep-prompt-${idx}" class="form-control" value="${escHtml(val)}" placeholder="Texto de la pregunta o consigna…">
            </div>`;
        }

        function applyBtn(idx, label) {
            return `<div style="margin-top:14px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="collapseChallengeEditor(${idx})"
                    title="Cierra el editor sin aplicar los cambios pendientes"
                    style="background:transparent; border:1px solid var(--border-color); color:var(--text-secondary); border-radius:8px; padding:9px 16px; font-weight:600; cursor:pointer; font-size:0.88rem;">
                    ▲ Colapsar
                </button>
                <button type="button" onclick="saveChallengeEdit(${idx})"
                    style="background:var(--accent-gradient); border:none; color:#fff; border-radius:8px; padding:9px 20px; font-weight:700; cursor:pointer; font-size:0.88rem;">
                    ✔ ${label || 'Aplicar cambios'}
                </button>
            </div>`;
        }

        // Opción múltiple
        function buildMCEditor(ch, idx) {
            const opts = ch.options || ch.answers || [];
            let rows = opts.map((o, oi) => `
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                    <input type="radio" name="ep-correct-${idx}" value="${oi}" ${o.correct ? 'checked' : ''} style="cursor:pointer;" title="Correcta">
                    <input type="text" id="ep-opt-${idx}-${oi}" class="form-control" value="${escHtml(o.text || '')}" placeholder="Opción ${oi+1}…" style="flex:1;">
                    ${opts.length > 2 ? `<button onclick="removeMCOption(${idx},${oi})" style="background:transparent;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;" title="Quitar opción">×</button>` : ''}
                </div>`).join('');
            return editorPromptRow(idx, ch.prompt?.text || '') +
                `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); margin-bottom:8px; display:block;">
                    Opciones <span style="font-size:0.75rem; font-weight:400; color:var(--text-muted);">(· marca la correcta con el círculo)</span>
                </label>
                <div id="ep-opts-${idx}">${rows}</div>
                ${opts.length < 4 ? `<button onclick="addMCOption(${idx})" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:5px 12px;font-size:0.78rem;cursor:pointer;margin-bottom:10px;">+ Opción</button>` : ''}
                ${applyBtn(idx)}`;
        }

        window.addMCOption = function(idx) {
            const ch = _ep.challenges[idx];
            const opts = ch.options || ch.answers || [];
            opts.push({ id: String.fromCharCode(97 + opts.length), text: '', correct: false });
            ch.options = opts;
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.removeMCOption = function(idx, oi) {
            const ch = _ep.challenges[idx];
            const opts = ch.options || ch.answers || [];
            if (opts.length <= 2) return;
            opts.splice(oi, 1);
            ch.options = opts;
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        // Verdadero / Falso
        function buildTFEditor(ch, idx) {
            const correct = ch.answer?.value ?? (ch.answers?.find(a=>a.correct)?.text?.toLowerCase() === 'verdadero');
            return editorPromptRow(idx, ch.prompt?.text || '') +
                `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Respuesta correcta</label>
                <div style="display:flex; gap:16px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:var(--text-primary);">
                        <input type="radio" name="ep-tf-${idx}" id="ep-tf-${idx}-t" value="true"  ${correct  ? 'checked' : ''} style="cursor:pointer;"> Verdadero
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:var(--text-primary);">
                        <input type="radio" name="ep-tf-${idx}" id="ep-tf-${idx}-f" value="false" ${!correct ? 'checked' : ''} style="cursor:pointer;"> Falso
                    </label>
                </div>
                ${applyBtn(idx)}`;
        }

        // Asociar pares
        function buildMPEditor(ch, idx) {
            const pairs = ch.pairs || [];
            let rows = pairs.map((p, pi) => `
                <div id="ep-pair-${idx}-${pi}" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <input type="text" id="ep-pair-l-${idx}-${pi}" class="form-control" value="${escHtml(p.left||'')}" placeholder="Izquierda…" style="flex:1;">
                    <span style="color:var(--text-muted); font-weight:700; flex-shrink:0;">→</span>
                    <input type="text" id="ep-pair-r-${idx}-${pi}" class="form-control" value="${escHtml(p.right||'')}" placeholder="Derecha…" style="flex:1;">
                    ${pairs.length > 2 ? `<button onclick="removePair(${idx},${pi})" style="background:transparent;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;" title="Quitar par">×</button>` : ''}
                </div>`).join('');
            return editorPromptRow(idx, ch.prompt?.text || '') +
                `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); margin-bottom:8px; display:block;">Pares correctos</label>
                <div id="ep-pairs-${idx}">${rows}</div>
                <button onclick="addPair(${idx})" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:5px 12px;font-size:0.78rem;cursor:pointer;margin-bottom:10px;">+ Par</button>
                ${applyBtn(idx)}`;
        }

        window.addPair = function(idx) {
            const ch = _ep.challenges[idx];
            ch.pairs = ch.pairs || [];
            ch.pairs.push({ left: '', right: '' });
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.removePair = function(idx, pi) {
            const ch = _ep.challenges[idx];
            if ((ch.pairs||[]).length <= 2) return;
            ch.pairs.splice(pi, 1);
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        // Ordenar elementos
        function buildSOEditor(ch, idx) {
            const items = ch.items || ch.answer?.order || [];
            let rows = items.map((it, ii) => `
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <span style="font-size:0.8rem; color:var(--text-muted); font-weight:700; width:24px; text-align:right; flex-shrink:0;">${ii+1})</span>
                    <input type="text" id="ep-seq-${idx}-${ii}" class="form-control" value="${escHtml(String(it||''))}" placeholder="Elemento ${ii+1}…" style="flex:1;">
                    ${items.length > 2 ? `<button onclick="removeSeqItem(${idx},${ii})" style="background:transparent;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;" title="Quitar">×</button>` : ''}
                </div>`).join('');
            return editorPromptRow(idx, ch.prompt?.text || '') +
                `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); margin-bottom:8px; display:block;">Elementos en orden correcto</label>
                <div id="ep-seqitems-${idx}">${rows}</div>
                <button onclick="addSeqItem(${idx})" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:5px 12px;font-size:0.78rem;cursor:pointer;margin-bottom:10px;">+ Elemento</button>
                ${applyBtn(idx)}`;
        }

        window.addSeqItem = function(idx) {
            const ch = _ep.challenges[idx];
            ch.items = ch.items || [];
            ch.items.push('');
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.removeSeqItem = function(idx, ii) {
            const ch = _ep.challenges[idx];
            if ((ch.items||[]).length <= 2) return;
            ch.items.splice(ii, 1);
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        // Clasificación
        function buildClassEditor(ch, idx) {
            const cats  = ch.categories || [];
            const items = ch.items || [];
            let catOpts = cats.map(c => `<option value="${escHtml(c.id)}">${escHtml(c.label)}</option>`).join('');

            let catRows = cats.map((c, ci) => `
                <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                    <input type="text" id="ep-cat-label-${idx}-${ci}" class="form-control" value="${escHtml(c.label||'')}" placeholder="Nombre de categoría…" style="flex:1;">
                    ${cats.length > 2 ? `<button onclick="removeCat(${idx},${ci})" style="background:transparent;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;">×</button>` : ''}
                </div>`).join('');

            let itemRows = items.map((it, ii) => `
                <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                    <input type="text" id="ep-cl-item-${idx}-${ii}" class="form-control" value="${escHtml(it.text||'')}" placeholder="Elemento…" style="flex:1;">
                    <select id="ep-cl-cat-${idx}-${ii}" class="form-control" style="max-width:160px;">${catOpts}</select>
                    ${items.length > 2 ? `<button onclick="removeClItem(${idx},${ii})" style="background:transparent;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;">×</button>` : ''}
                </div>`).join('');

            // Pre-seleccionar categoría actual
            const html = editorPromptRow(idx, ch.prompt?.text || '') +
                `<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:4px;">
                    <div>
                        <label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Categorías</label>
                        <div id="ep-cats-${idx}">${catRows}</div>
                        <button onclick="addCat(${idx})" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:5px 12px;font-size:0.78rem;cursor:pointer;">+ Categoría</button>
                    </div>
                    <div>
                        <label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Elementos</label>
                        <div id="ep-clitems-${idx}">${itemRows}</div>
                        <button onclick="addClItem(${idx})" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:5px 12px;font-size:0.78rem;cursor:pointer;">+ Elemento</button>
                    </div>
                </div>
                ${applyBtn(idx)}`;

            // Necesitamos seleccionar la categoría correcta después de renderizar
            setTimeout(() => {
                items.forEach((it, ii) => {
                    const sel = document.getElementById(`ep-cl-cat-${idx}-${ii}`);
                    if (sel && it.categoryId) sel.value = it.categoryId;
                });
            }, 0);
            return html;
        }

        window.addCat = function(idx) {
            const ch = _ep.challenges[idx];
            ch.categories = ch.categories || [];
            const newId = 'cat_' + ch.categories.length;
            ch.categories.push({ id: newId, label: '' });
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.removeCat = function(idx, ci) {
            const ch = _ep.challenges[idx];
            if ((ch.categories||[]).length <= 2) return;
            ch.categories.splice(ci, 1);
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.addClItem = function(idx) {
            const ch = _ep.challenges[idx];
            ch.items = ch.items || [];
            const firstCatId = ch.categories?.[0]?.id || '';
            ch.items.push({ id: 'item_' + ch.items.length, text: '', categoryId: firstCatId });
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        window.removeClItem = function(idx, ii) {
            const ch = _ep.challenges[idx];
            if ((ch.items||[]).length <= 2) return;
            ch.items.splice(ii, 1);
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        // Completar espacios
        function buildFBEditor(ch, idx) {
            const blanks = ch.blanks || {};
            const blankIds = Object.keys(blanks);
            let blankRows = blankIds.map(bid => {
                const b = blanks[bid];
                const optsHtml = (b.options||[]).map((o,oi) =>
                    `<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
                        <input type="radio" name="ep-fb-correct-${idx}-${bid}" value="${oi}" ${b.correct===o?'checked':''}>
                        <input type="text" id="ep-fb-opt-${idx}-${bid}-${oi}" class="form-control" value="${escHtml(o)}" style="flex:1;" placeholder="Opción…">
                    </div>`).join('');
                return `<div style="background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-bottom:10px;">
                    <div style="font-size:0.8rem; font-weight:700; color:#a5b4fc; margin-bottom:8px;">[${bid}]</div>
                    <div>${optsHtml}</div>
                    <button onclick="addFBOption(${idx},'${bid}')" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.25);color:#a5b4fc;border-radius:6px;padding:4px 10px;font-size:0.75rem;cursor:pointer;">+ Opción</button>
                </div>`;
            }).join('');
            return editorPromptRow(idx, ch.prompt?.text || '') +
                `<p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:10px;">Usa <code>[blank1]</code>, <code>[blank2]</code>… en el enunciado. Marca la opción correcta con el círculo.</p>
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Espacios en blanco</label>
                <div id="ep-blanks-${idx}">${blankRows}</div>
                ${applyBtn(idx)}`;
        }

        window.addFBOption = function(idx, bid) {
            const ch = _ep.challenges[idx];
            ch.blanks = ch.blanks || {};
            ch.blanks[bid] = ch.blanks[bid] || { options: [], correct: '' };
            ch.blanks[bid].options.push('');
            document.getElementById('ep-ch-editor-' + idx).innerHTML = buildChallengeEditorHTML(ch, idx);
        };

        // Raw JSON (para tipos no soportados visualmente)
        function buildRawEditor(ch, idx) {
            const json = JSON.stringify(ch, null, 2);
            return `<p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:10px;">
                        Editor JSON avanzado — modificá directamente el objeto del desafío.
                    </p>
                    <textarea id="ep-raw-${idx}" class="form-control" rows="10"
                        style="font-family:'Courier New',monospace; font-size:0.82rem; resize:vertical;">${escHtml(json)}</textarea>
                    ${applyBtn(idx, 'Aplicar JSON')}`;
        }

        // ── Guardar edición de un challenge ──────────────────────────
        window.saveChallengeEdit = function(idx) {
            const ch   = _ep.challenges[idx];
            const type = ch.type || 'multiple_choice';

            // Actualizar prompt
            const promptEl = document.getElementById('ep-prompt-' + idx);
            if (promptEl) ch.prompt = { text: promptEl.value.trim() };

            switch (type) {
                case 'multiple_choice': {
                    const opts = ch.options || ch.answers || [];
                    const checkedRadio = document.querySelector(`input[name="ep-correct-${idx}"]:checked`);
                    const correctIdx   = checkedRadio ? parseInt(checkedRadio.value) : 0;
                    opts.forEach((o, oi) => {
                        const inp = document.getElementById(`ep-opt-${idx}-${oi}`);
                        if (inp) o.text = inp.value.trim();
                        o.correct = (oi === correctIdx);
                    });
                    ch.options = opts;
                    break;
                }
                case 'true_false': {
                    const radio = document.querySelector(`input[name="ep-tf-${idx}"]:checked`);
                    ch.answer = { value: radio ? radio.value === 'true' : true };
                    break;
                }
                case 'matching_pairs': {
                    const pairs = ch.pairs || [];
                    pairs.forEach((p, pi) => {
                        const l = document.getElementById(`ep-pair-l-${idx}-${pi}`);
                        const r = document.getElementById(`ep-pair-r-${idx}-${pi}`);
                        if (l) p.left  = l.value.trim();
                        if (r) p.right = r.value.trim();
                    });
                    ch.pairs = pairs;
                    break;
                }
                case 'sequence_order': {
                    const items = ch.items || [];
                    const newItems = items.map((_, ii) => {
                        const inp = document.getElementById(`ep-seq-${idx}-${ii}`);
                        return inp ? inp.value.trim() : _;
                    });
                    ch.items = newItems;
                    ch.answer = { order: newItems };
                    break;
                }
                case 'classification': {
                    const cats  = ch.categories || [];
                    const items = ch.items || [];
                    cats.forEach((c, ci) => {
                        const inp = document.getElementById(`ep-cat-label-${idx}-${ci}`);
                        if (inp) c.label = inp.value.trim();
                    });
                    const newAnswer = {};
                    items.forEach((it, ii) => {
                        const textInp = document.getElementById(`ep-cl-item-${idx}-${ii}`);
                        const catSel  = document.getElementById(`ep-cl-cat-${idx}-${ii}`);
                        if (textInp) it.text = textInp.value.trim();
                        if (catSel)  it.categoryId = catSel.value;
                        newAnswer[it.id] = it.categoryId;
                    });
                    ch.categories = cats;
                    ch.items = items;
                    ch.answer = newAnswer;
                    break;
                }
                case 'fill_blank':
                case 'fill_blank_select': {
                    const blanks = ch.blanks || {};
                    Object.keys(blanks).forEach(bid => {
                        const b = blanks[bid];
                        const opts = b.options || [];
                        const newOpts = opts.map((o, oi) => {
                            const inp = document.getElementById(`ep-fb-opt-${idx}-${bid}-${oi}`);
                            return inp ? inp.value.trim() : o;
                        });
                        const radio = document.querySelector(`input[name="ep-fb-correct-${idx}-${bid}"]:checked`);
                        const correctIdx = radio ? parseInt(radio.value) : 0;
                        b.options = newOpts;
                        b.correct = newOpts[correctIdx] || '';
                    });
                    ch.blanks = blanks;
                    break;
                }
                default: {
                    // Raw JSON
                    const rawEl = document.getElementById('ep-raw-' + idx);
                    if (rawEl) {
                        try {
                            _ep.challenges[idx] = JSON.parse(rawEl.value);
                        } catch (e) {
                            alert('JSON inválido: ' + e.message);
                            return;
                        }
                    }
                }
            }

            // Re-renderizar la lista para reflejar el nuevo enunciado y
            // colapsar la pregunta (vuelve a su visualización de un renglón)
            renderChallengeList();
            // Flash verde de confirmación sobre la cabecera del card
            const header = document.getElementById('ep-ch-header-' + idx);
            if (header) {
                header.style.background = 'rgba(16,185,129,0.18)';
                setTimeout(() => { header.style.background = ''; }, 900);
            }
        };

        // ── Mover challenge ────────────────────────────────────────────
        window.moveChallenge = function(idx, dir) {
            const arr = _ep.challenges;
            const newIdx = idx + dir;
            if (newIdx < 0 || newIdx >= arr.length) return;
            [arr[idx], arr[newIdx]] = [arr[newIdx], arr[idx]];
            renderChallengeList();
        };

        // ── Eliminar challenge ─────────────────────────────────────────
        window.deleteChallenge = function(idx) {
            if (!confirm('¿Eliminar este desafío? Esta acción no puede deshacerse hasta que guardes los cambios.')) return;
            _ep.challenges.splice(idx, 1);
            renderChallengeList();
        };

        // ── Agregar challenge — Modal ──────────────────────────────────
        window.showAddChallengeModal = function() {
            const modal = document.getElementById('add-ch-modal');
            modal.style.display = 'flex';
            renderAddChallengeForm();
        };

        window.renderAddChallengeForm = function() {
            const type = document.getElementById('add-ch-type').value;
            const body = document.getElementById('add-ch-form-body');
            const promptField = `<div class="form-group" style="margin-bottom:12px;">
                <label>Enunciado / Consigna <span style="color:var(--danger)">*</span></label>
                <input type="text" id="add-ch-prompt" class="form-control" placeholder="Texto del desafío…">
            </div>`;

            let extra = '';
            if (type === 'multiple_choice') {
                extra = `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Opciones (marca la correcta):</label>
                ${[1,2,3].map(i=>`<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <input type="radio" name="add-ch-correct" value="${i-1}" ${i===1?'checked':''}>
                    <input type="text" id="add-ch-opt-${i-1}" class="form-control" placeholder="Opción ${i}…">
                </div>`).join('')}`;
            } else if (type === 'true_false') {
                extra = `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Respuesta correcta:</label>
                <div style="display:flex;gap:16px;">
                    <label><input type="radio" name="add-ch-tf" value="true" checked> Verdadero</label>
                    <label><input type="radio" name="add-ch-tf" value="false"> Falso</label>
                </div>`;
            } else if (type === 'matching_pairs') {
                extra = `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Pares (al menos 2):</label>
                ${[0,1,2].map(i=>`<div style="display:flex;gap:8px;margin-bottom:8px;">
                    <input type="text" id="add-mp-l-${i}" class="form-control" placeholder="Izquierda ${i+1}…" style="flex:1;">
                    <span style="color:var(--text-muted);font-weight:700;line-height:42px;">→</span>
                    <input type="text" id="add-mp-r-${i}" class="form-control" placeholder="Derecha ${i+1}…" style="flex:1;">
                </div>`).join('')}`;
            } else if (type === 'sequence_order') {
                extra = `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">Elementos en orden correcto:</label>
                ${[0,1,2].map(i=>`<div style="display:flex;gap:8px;margin-bottom:8px;">
                    <span style="color:var(--text-muted);font-weight:700;width:20px;line-height:42px;">${i+1})</span>
                    <input type="text" id="add-so-${i}" class="form-control" placeholder="Elemento ${i+1}…">
                </div>`).join('')}`;
            } else if (type === 'classification') {
                extra = `<label style="font-size:0.82rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:8px;">2 categorías y al menos 4 elementos — agrégalos tras crear:</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <input type="text" id="add-cl-cat0" class="form-control" placeholder="Categoría A…">
                    <input type="text" id="add-cl-cat1" class="form-control" placeholder="Categoría B…">
                </div>`;
            } else if (type === 'fill_blank_select') {
                extra = `<p style="font-size:0.8rem; color:var(--text-muted);">Escribe el enunciado con <code>[blank1]</code> y edita los espacios en blanco desde el editor de desafíos.</p>`;
            }

            body.innerHTML = promptField + extra;
        };

        window.confirmAddChallenge = function() {
            const type   = document.getElementById('add-ch-type').value;
            const prompt = (document.getElementById('add-ch-prompt')?.value || '').trim();
            if (!prompt) { alert('El enunciado es obligatorio.'); return; }

            // Generar un ID único (evita duplicados tras eliminar desafíos intermedios)
            const idPrefix = type.replace('_','-').substring(0,4);
            const usedIds  = new Set(_ep.challenges.map(c => String(c.id || '')));
            let idNum = _ep.challenges.length + 1;
            let newId = idPrefix + '_' + String(idNum).padStart(3,'0');
            while (usedIds.has(newId)) {
                idNum++;
                newId = idPrefix + '_' + String(idNum).padStart(3,'0');
            }
            let newCh    = { id: newId, type, prompt: { text: prompt }, timeLimitSeconds: 20, points: 10, difficulty: 1 };

            if (type === 'multiple_choice') {
                const radio = document.querySelector('input[name="add-ch-correct"]:checked');
                const ci    = radio ? parseInt(radio.value) : 0;
                newCh.options = [0,1,2].map(i => ({
                    id: String.fromCharCode(97+i),
                    text: document.getElementById('add-ch-opt-'+i)?.value.trim() || '',
                    correct: i === ci,
                }));
            } else if (type === 'true_false') {
                const tfR = document.querySelector('input[name="add-ch-tf"]:checked');
                newCh.answer = { value: tfR ? tfR.value === 'true' : true };
            } else if (type === 'matching_pairs') {
                newCh.pairs = [0,1,2].map(i => ({
                    left: document.getElementById('add-mp-l-'+i)?.value.trim() || '',
                    right: document.getElementById('add-mp-r-'+i)?.value.trim() || '',
                })).filter(p => p.left || p.right);
            } else if (type === 'sequence_order') {
                const items = [0,1,2].map(i => document.getElementById('add-so-'+i)?.value.trim() || '').filter(Boolean);
                newCh.items = items;
                newCh.answer = { order: items };
            } else if (type === 'classification') {
                const c0 = document.getElementById('add-cl-cat0')?.value.trim() || 'Categoría A';
                const c1 = document.getElementById('add-cl-cat1')?.value.trim() || 'Categoría B';
                newCh.categories = [{ id:'cat_a', label:c0 }, { id:'cat_b', label:c1 }];
                newCh.items = [];
                newCh.answer = {};
            } else if (type === 'fill_blank_select') {
                newCh.blanks = { blank1: { options: ['', ''], correct: '' } };
            }

            _ep.challenges.push(newCh);
            renderChallengeList();

            document.getElementById('add-ch-modal').style.display = 'none';

            // Auto-abrir el editor del nuevo challenge
            setTimeout(() => {
                const newIdx = _ep.challenges.length - 1;
                toggleChallengeEditor(newIdx);
                document.getElementById('ep-ch-editor-' + newIdx)?.scrollIntoView({ behavior:'smooth', block:'center' });
            }, 80);
        };

        // ── Guardar actividad completa ─────────────────────────────────
        window.saveEditedActivity = function() {
            const title  = document.getElementById('ep-title').value.trim();
            const author = document.getElementById('ep-author').value.trim();
            if (!title || !author) {
                alert('El título y el autor son obligatorios.');
                return;
            }
            if (_ep.challenges.length === 0) {
                alert('La actividad debe tener al menos un desafío.');
                return;
            }

            const btns = ['ep-save-btn', 'ep-save-btn-top']
                .map(id => document.getElementById(id))
                .filter(Boolean);
            const setSaveBtns = (disabled, text, bg) => btns.forEach(b => {
                b.disabled = disabled;
                b.textContent = text;
                b.style.background = bg || '';
            });
            setSaveBtns(true, '⏳ Guardando…');

            const payload = new FormData();
            payload.append('action',        'save_activity');
            payload.append('csrf_token',    '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');
            payload.append('project',       _ep.project);
            payload.append('title',         title);
            payload.append('author',        author);
            payload.append('nivel',         document.getElementById('ep-nivel').value.trim());
            payload.append('obs',           document.getElementById('ep-obs').value.trim());
            payload.append('mail',          document.getElementById('ep-mail').value.trim());
            payload.append('activity_json', JSON.stringify({ challenges: _ep.challenges }));
            payload.append('board_locked_id', document.getElementById('ep-board-locked')?.value || '');

            fetch('admin.php', { method:'POST', body: payload })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        setSaveBtns(true, '✔ ¡Guardado!', 'var(--success-gradient)');
                        setTimeout(() => {
                            // Volver al listado de actividades con datos frescos
                            closeEditPanel();
                            window.scrollTo(0, 0);
                            window.location.reload();
                        }, 1000);
                    } else {
                        alert('Error al guardar: ' + (data.error || 'Error desconocido.'));
                        setSaveBtns(false, '💾 Guardar cambios');
                    }
                })
                .catch(err => {
                    alert('Error de red: ' + err.message);
                    setSaveBtns(false, '💾 Guardar cambios');
                });
        };

        // ── Sincronización masiva filesystem → BD ─────────────
        async function syncAllProjects() {
            const btn  = document.getElementById('btn-sync-projects');
            const icon = document.getElementById('sync-icon');
            const out  = document.getElementById('sync-result');

            btn.disabled = true;
            icon.textContent = '⏳';
            out.style.display = 'none';

            const fd = new FormData();
            fd.append('action',     'sync_all_projects');
            fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

            try {
                const res  = await fetch('admin.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    const s = data.stats;
                    const lines = [
                        `<strong style="color:#a5b4fc">Sincronización completada</strong>`,
                        `✅ Nuevas en BD: <strong>${s.new}</strong>`,
                        `🔄 Actualizadas: <strong>${s.updated}</strong>`,
                        `⊘ Sin cambios / omitidas: <strong>${s.skipped}</strong>`,
                        s.trash ? `🗑 En papelera: <strong>${s.trash}</strong>` : '',
                        s.errors ? `<span style="color:#ef4444">⚠ Errores: <strong>${s.errors}</strong></span>` : '',
                    ].filter(Boolean);

                    // Detalle por proyecto
                    const details = (s.log || []).filter(l => l.type !== 'skip').map(l => {
                        const color = l.type === 'new' ? '#10b981' : l.type === 'error' ? '#ef4444' : '#a5b4fc';
                        return `<div style="color:${color};margin-top:2px;">${escHtml(l.msg)}</div>`;
                    }).join('');

                    out.innerHTML = lines.join('<br>') + (details ? '<hr style="border-color:#334155;margin:8px 0;">' + details : '');
                    out.style.background = '#1e293b';
                } else {
                    out.innerHTML = `<span style="color:#ef4444">Error: ${escHtml(data.error)}</span>`;
                }
            } catch (err) {
                out.innerHTML = `<span style="color:#ef4444">Error de red: ${escHtml(err.message)}</span>`;
            }

            out.style.display = 'block';
            icon.textContent = '🔄';
            btn.disabled = false;
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    </script>
<script src="/triviax/js/brand.js?v=5.0.6"></script>
</body>
</html>
