<?php
/**
 * TRIVIAX - Eventos en vivo (SSE).
 *
 * Endpoint incremental para reemplazar polling en pantallas de monitoreo.
 * Mantiene los streams cortos para no retener workers de Apache/PHP demasiado
 * tiempo; el navegador reconecta EventSource automaticamente.
 */

require_once __DIR__ . '/php/auth.php';
require_once __DIR__ . '/php/live_session_summary.php';

function triviax_sse_send(string $event, array $payload): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    flush();
}

function triviax_sse_comment(string $message): void {
    echo ': ' . str_replace(["\r", "\n"], ' ', $message) . "\n\n";
    @ob_flush();
    flush();
}

$stream = (string)($_GET['stream'] ?? '');

if ($stream !== 'live_session_summary') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Stream no encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

triviax_requerir_auth(TRIVIAX_ROL_DOCENTE);

$usuario = triviax_usuario_actual();
$sessionId = (int)($_GET['id'] ?? $_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Sesion invalida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = triviax_db();
$sesion = triviax_live_session_load_for_docente($pdo, $sessionId, (int)$usuario['id']);
if (!$sesion) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Sesion no encontrada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ignore_user_abort(true);
@set_time_limit(35);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Evita que la conexion SSE bloquee otras peticiones de la misma sesion.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}

$lastHash = '';
$startedAt = time();
$lastHeartbeat = 0;
$maxSeconds = 25;

triviax_sse_comment('triviax live stream ready');

while (!connection_aborted() && (time() - $startedAt) < $maxSeconds) {
    try {
        $summary = triviax_live_session_summary($pdo, $sesion);
        $hash = hash('sha256', json_encode([
            $summary['session'],
            $summary['totals'],
            $summary['questions'],
        ], JSON_UNESCAPED_UNICODE));

        if ($hash !== $lastHash) {
            $lastHash = $hash;
            triviax_sse_send('summary', $summary);
        } elseif (time() - $lastHeartbeat >= 10) {
            $lastHeartbeat = time();
            triviax_sse_send('heartbeat', ['success' => true, 'ts' => date('c')]);
        }
    } catch (Throwable $e) {
        triviax_sse_send('error', [
            'success' => false,
            'error' => 'No se pudo leer el estado en vivo.',
        ]);
        break;
    }

    usleep(1000000);
}

triviax_sse_comment('stream closing');
