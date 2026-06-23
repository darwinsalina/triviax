<?php
/**
 * TRIVIAX - Chequeo de preparacion para produccion.
 *
 * Uso:
 *   php tools/check_production_readiness.php
 *
 * No imprime secretos; solo presencia/estado.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Este chequeo solo se ejecuta por CLI.';
    exit(1);
}

require_once __DIR__ . '/../php/auth.php';

$checks = [];
$failed = 0;

function readiness_check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failed;
    if (!$ok) {
        $failed++;
    }
    $checks[] = [
        'label' => $label,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function readiness_env_present(string $key): bool {
    return trim(_triviax_env($key, '')) !== '';
}

$appEnv = triviax_app_env();
$isProduction = ($appEnv === 'production' || $appEnv === 'prod');

readiness_check('APP_ENV actual', $appEnv !== '', "actual={$appEnv}");
readiness_check('APP_SECRET presente', readiness_env_present('APP_SECRET'), 'necesario para tokens/firmas');
readiness_check('CSRF_SECRET presente', readiness_env_present('CSRF_SECRET'), 'necesario para formularios y API');
readiness_check('ADMIN_EMAIL presente', readiness_env_present('ADMIN_EMAIL'), 'notificaciones administrativas');
readiness_check('MAIL_FROM presente', readiness_env_present('MAIL_FROM'), 'remitente de correos');

$turnstileSite = readiness_env_present('TURNSTILE_SITE_KEY');
$turnstileSecret = readiness_env_present('TURNSTILE_SECRET_KEY');
readiness_check('TURNSTILE_SITE_KEY configurada o no exigida', !$isProduction || $turnstileSite, 'anti-bot registro docente/estudiante');
readiness_check('TURNSTILE_SECRET_KEY configurada o no exigida', !$isProduction || $turnstileSecret, 'anti-bot registro docente/estudiante');

try {
    readiness_check('Base de datos disponible', triviax_db_available(), 'conexion PDO');
} catch (Throwable $e) {
    readiness_check('Base de datos disponible', false, 'conexion PDO');
}

echo "TRIVIAX - chequeo de produccion\n";
echo "================================\n";
foreach ($checks as $check) {
    echo ($check['ok'] ? '[OK]   ' : '[FAIL] ') . $check['label'];
    if ($check['detail'] !== '') {
        echo ' - ' . $check['detail'];
    }
    echo "\n";
}

if ($failed > 0) {
    echo "\nResultado: {$failed} chequeo(s) requieren atencion.\n";
    exit(1);
}

echo "\nResultado: listo.\n";
