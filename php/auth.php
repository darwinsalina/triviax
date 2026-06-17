<?php
/**
 * TRIVIAX - Sistema de autenticación v4.1
 * Maneja login, logout, registro, verificación de email y sesión PHP.
 *
 * Dependencias: php/db.php, php/triviax_core.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/triviax_core.php';

// ─────────────────────────────────────────────
// Constantes de roles
// ─────────────────────────────────────────────
define('TRIVIAX_ROL_DOCENTE',     'docente');
define('TRIVIAX_ROL_ESTUDIANTE',  'estudiante');
define('TRIVIAX_ROL_SUPERADMIN',  'superadmin');

// Email del administrador — se lee del .env; fallback al valor original
// (Nota: _triviax_env() se define más abajo en este mismo archivo, pero como
//  PHP las funciones son globales en el archivo, es accesible desde aquí
//  siempre que este archivo sea incluido como un todo.)
// Para evitar la dependencia de orden, usamos define() con un valor fijo
// conocido y luego ofrecemos un getter que sí usa el .env.
define('TRIVIAX_ADMIN_EMAIL', 'saltmine.development@gmail.com');
define('TRIVIAX_FROM_EMAIL',  'triviax@darwin.edu.uy');
define('TRIVIAX_FROM_NAME',   'TRIVIAX');

/**
 * Devuelve el email del administrador, preferentemente desde triviax.env.
 * Usar esta función en vez de la constante cuando se quiera que sea configurable.
 */
function triviax_admin_email(): string {
    return _triviax_env('ADMIN_EMAIL', TRIVIAX_ADMIN_EMAIL);
}

/**
 * Devuelve la dirección remitente, preferentemente desde triviax.env.
 */
function triviax_from_email(): string {
    return _triviax_env('MAIL_FROM', TRIVIAX_FROM_EMAIL);
}

// ─────────────────────────────────────────────
// LECTOR DE VARIABLES DE ENTORNO (triviax.env)
// ─────────────────────────────────────────────

/**
 * Parsea el archivo triviax.env y devuelve todas las variables como array.
 * Las líneas que empiecen con # son comentarios y se ignoran.
 * Caché estática para no leer el archivo más de una vez por request.
 */
function _triviax_env_all(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $candidates = [
        __DIR__ . '/../../../dbconn/triviax.env',
        dirname(dirname(__DIR__)) . '/dbconn/triviax.env',
    ];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $dr = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
        $candidates[] = $dr . '/../dbconn/triviax.env';
        $candidates[] = $dr . '/../../dbconn/triviax.env';
    }
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real !== false && is_readable($real)) {
            $vars = [];
            foreach (file($real, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $eq = strpos($line, '=');
                if ($eq === false) continue;
                $key   = trim(substr($line, 0, $eq));
                $value = trim(substr($line, $eq + 1));
                if ($key !== '') {
                    $vars[$key] = $value;
                }
            }
            $cache = $vars;
            return $cache;
        }
    }
    $cache = [];
    return $cache;
}

/**
 * Lee una variable del archivo triviax.env.
 * Si no existe, devuelve $default.
 * NUNCA usar como fallback en producción contraseñas hardcodeadas —
 * el fallback sólo es para entorno de desarrollo local.
 */
function _triviax_env(string $key, string $default = ''): string {
    $all = _triviax_env_all();
    return $all[$key] ?? $all[strtolower($key)] ?? $all[strtoupper($key)] ?? $default;
}

function triviax_env_bool(string $key, bool $default = false): bool {
    $value = strtolower(trim(_triviax_env($key, $default ? 'true' : 'false')));
    return in_array($value, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
}

function triviax_app_env(): string {
    return strtolower(trim(_triviax_env('APP_ENV', _triviax_es_local() ? 'local' : 'production')));
}

function triviax_maintenance_mode(): bool {
    return triviax_env_bool('MAINTENANCE_MODE', false);
}

/**
 * Lee la clave tkey (código de habilitación legado).
 * Se mantiene por compatibilidad con v3.x y como alias de TEACHER_INVITE_CODE.
 * NO usar como clave de cifrado ni como contraseña de admin.
 */
function _triviax_tkey(): string {
    // Preferir TEACHER_INVITE_CODE; si no existe, usar tkey como fallback
    $invite = _triviax_env('TEACHER_INVITE_CODE');
    if ($invite !== '') return $invite;
    $tkey = _triviax_env('tkey', 'lkjh1234'); // fallback local SOLO
    return $tkey;
}

/**
 * @deprecated v4.4 — Las contraseñas reversibles están obsoletas.
 * Las funciones se mantienen SÓLO por si algún proyecto legado las llama.
 * No almacenar ni mostrar su resultado en la UI.
 *
 * Cifra una contraseña en texto plano usando AES-256-CBC con APP_SECRET.
 */
function triviax_encrypt_password(string $password): string {
    // Usa APP_SECRET, separado de tkey, para no tener una sola clave maestra
    $secret = _triviax_env('APP_SECRET', _triviax_env('tkey', 'lkjh1234'));
    $key = substr(hash('sha256', $secret, true), 0, 32);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

/**
 * @deprecated v4.4 — Ver triviax_encrypt_password().
 * Descifra. Devuelve '' si falla o si el cifrado fue con clave diferente.
 */
function triviax_decrypt_password(string $encrypted): string {
    if ($encrypted === '') return '';
    $secret = _triviax_env('APP_SECRET', _triviax_env('tkey', 'lkjh1234'));
    $key  = substr(hash('sha256', $secret, true), 0, 32);
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) <= 16) return '';
    $iv  = substr($data, 0, 16);
    $enc = substr($data, 16);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($dec !== false) ? $dec : '';
}

// ─────────────────────────────────────────────
// Inicio de sesión PHP seguro
// ─────────────────────────────────────────────
function triviax_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $sessionName = _triviax_env('SESSION_NAME', 'SESSID');
        if ($sessionName !== '') {
            session_name($sessionName);
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (!empty($_SESSION['triviax_usuario'])) {
        $role = $_SESSION['triviax_usuario']['rol'] ?? '';
        $limits = [
            TRIVIAX_ROL_SUPERADMIN => 1800,
            TRIVIAX_ROL_DOCENTE => 3600,
            TRIVIAX_ROL_ESTUDIANTE => 7200,
        ];
        $ttl = $limits[$role] ?? 3600;
        $last = (int)($_SESSION['triviax_last_activity'] ?? time());
        if ((time() - $last) > $ttl) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
            }
            session_destroy();
            session_start();
        }
        $_SESSION['triviax_last_activity'] = time();
    }
}

// ─────────────────────────────────────────────
// HELPERS INTERNOS — Email y URL base
// ─────────────────────────────────────────────

/**
 * Devuelve true si la petición viene de un entorno local (WAMP/XAMPP/localhost).
 * Compatible con PHP 7.x.
 */
function _triviax_es_local(): bool {
    $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $bareHost = strtolower(explode(':', $httpHost)[0]);
    return (
        $bareHost === 'localhost'
        || $bareHost === '127.0.0.1'
        || $bareHost === '::1'
        || strpos($bareHost, '127.')    === 0
        || strpos($bareHost, '192.168.') === 0
        || strpos($bareHost, '10.')     === 0
    );
}

/**
 * Construye la URL base de la aplicación (ej: http://localhost/triviax).
 */
function _triviax_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/triviax';
}

/**
 * Envía un email usando mail().
 *
 * Detalles de implementación:
 *  · From usa TRIVIAX_FROM_EMAIL (dominio real del servidor) para que SPF pase.
 *  · El cuerpo HTML se codifica en base64 con chunk_split() (76 chars/línea)
 *    según RFC 2045 — sin esto algunos MTA rechazan el mensaje silenciosamente.
 *  · El parámetro -f establece el "envelope sender" (Return-Path), necesario
 *    para que la verificación SPF funcione correctamente.
 *  · Si mail() falla, escribe un log detallado para depuración.
 *
 * @param string $to       Destinatario
 * @param string $subject  Asunto
 * @param string $bodyHtml Cuerpo HTML
 * @param string $bodyText Texto plano de respaldo (para el log)
 * @return bool
 */
function _triviax_enviar_email(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool {
    $fromEmail = TRIVIAX_FROM_EMAIL;
    $fromFull  = TRIVIAX_FROM_NAME . ' <' . $fromEmail . '>';

    // ── Codificación del cuerpo ────────────────────────────────────────
    // chunk_split() agrega "\r\n" cada 76 chars, cumpliendo RFC 2045 §6.8.
    // Sin esto algunos servidores de correo rechazan el mensaje.
    $bodyEncoded = chunk_split(base64_encode($bodyHtml));

    // ── Asunto RFC 2047 (soporta UTF-8 / tildes / caracteres especiales) ──
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // ── Cabeceras ─────────────────────────────────────────────────────
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: '     . $fromFull,
        'Reply-To: ' . TRIVIAX_ADMIN_EMAIL,
        'Return-Path: <' . $fromEmail . '>',
        'X-Mailer: TRIVIAX-PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    ]);

    // ── Envío ─────────────────────────────────────────────────────────
    // El 5.º parámetro (-f) fija el "envelope from" para que el MTA local
    // ponga el Return-Path correcto y los registros SPF del dominio pasen.
    $ok = mail($to, $subjectEncoded, $bodyEncoded, $headers, '-f' . $fromEmail);

    // ── Log cuando falla ───────────────────────────────────────────────
    if (!$ok) {
        $lastError = error_get_last();
        $errMsg    = $lastError ? $lastError['message'] : 'sin detalles';

        $logCandidates = [
            dirname(realpath(__DIR__ . '/../../../dbconn/dbkey_triviax.php') ?: __DIR__) . '/mail_pending.log',
            __DIR__ . '/../logs/mail_pending.log',
            __DIR__ . '/mail_pending.log',
        ];
        $logPath = null;
        foreach ($logCandidates as $lc) {
            $dir = dirname($lc);
            if (is_dir($dir) && is_writable($dir)) {
                $logPath = $lc;
                break;
            }
        }
        if ($logPath !== null) {
            $entrada = sprintf(
                "[%s] FALLO\nPara: %s\nAsunto: %s\nFrom: %s\nError PHP: %s\n---\n%s\n%s\n\n",
                date('Y-m-d H:i:s'),
                $to,
                $subject,
                $fromFull,
                $errMsg,
                $bodyText ?: strip_tags($bodyHtml),
                str_repeat('=', 60)
            );
            @file_put_contents($logPath, $entrada, FILE_APPEND | LOCK_EX);
        }
    }

    return $ok;
}

/**
 * Variante de _triviax_enviar_email() con un archivo adjunto (multipart/mixed).
 * Mantiene las mismas decisiones de SPF/encoding que la versión sin adjunto.
 */
function _triviax_enviar_email_adjunto(
    string $to,
    string $subject,
    string $bodyHtml,
    string $attachNombre,
    string $attachContenido,
    string $attachMime = 'text/markdown'
): bool {
    $fromEmail = TRIVIAX_FROM_EMAIL;
    $fromFull  = TRIVIAX_FROM_NAME . ' <' . $fromEmail . '>';
    $boundary  = 'TRIVIAX-' . bin2hex(random_bytes(16));

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'From: '     . $fromFull,
        'Reply-To: ' . TRIVIAX_ADMIN_EMAIL,
        'Return-Path: <' . $fromEmail . '>',
        'X-Mailer: TRIVIAX-PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    ]);

    $cuerpo = '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($bodyHtml))
        . '--' . $boundary . "\r\n"
        . 'Content-Type: ' . $attachMime . '; charset=UTF-8; name="' . $attachNombre . "\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $attachNombre . "\"\r\n\r\n"
        . chunk_split(base64_encode($attachContenido))
        . '--' . $boundary . "--\r\n";

    return mail($to, $subjectEncoded, $cuerpo, $headers, '-f' . $fromEmail);
}

/**
 * Envía al docente recién verificado la Guía del Docente:
 * cuerpo de bienvenida con enlace al visor del panel + PDF estable adjunto.
 * Best effort: nunca interrumpe el flujo de verificación.
 */
function _triviax_email_guia_docente(string $emailDestino, string $nombre): void {
    require_once __DIR__ . '/guia_docente_lib.php';
    $guiaMd = triviax_guia_docente_md();
    $guiaPdfPath = triviax_guia_docente_pdf_path();
    $guiaPdf = is_readable($guiaPdfPath) ? file_get_contents($guiaPdfPath) : false;
    if ($guiaMd === '' && $guiaPdf === false) {
        return;
    }

    $urlGuia      = _triviax_base_url() . '/panel/guia_docente.php';
    $urlDashboard = _triviax_base_url() . '/panel/dashboard.php';
    $subject      = 'TRIVIAX — Tu Guía del Docente';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body ' .
        'style="margin:0;padding:0;background:#090d16;font-family:system-ui,sans-serif;color:#e5e7eb;">' .
        '<div style="max-width:520px;margin:40px auto;background:rgba(255,255,255,0.05);' .
        'border:1px solid rgba(99,102,241,0.3);border-radius:16px;padding:40px 36px;">' .

        '<h1 style="margin:0 0 4px;font-size:2rem;font-weight:800;letter-spacing:2px;' .
        'background:linear-gradient(135deg,#6366f1,#a855f7);-webkit-background-clip:text;' .
        '-webkit-text-fill-color:transparent;background-clip:text;">TRIVIAX</h1>' .
        '<p style="margin:0 0 28px;color:#9ca3af;font-size:0.9rem;">Juego educativo</p>' .

        '<h2 style="margin:0 0 16px;font-size:1.3rem;color:#f9fafb;">¡Bienvenido/a, ' .
        htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '!</h2>' .

        '<p style="margin:0 0 12px;line-height:1.7;color:#d1d5db;">' .
        'Tu cuenta docente ya está activa. Te adjuntamos la <strong>Guía del Docente de TRIVIAX</strong>, ' .
        'con todo lo necesario para crear actividades, abrir sesiones de juego y seguir el progreso de tus estudiantes.' .
        '</p>' .
        '<p style="margin:0 0 28px;line-height:1.7;color:#d1d5db;">' .
        'También puedes consultarla en línea desde tu panel en cualquier momento:' .
        '</p>' .

        '<div style="text-align:center;margin-bottom:28px;">' .
        '<a href="' . htmlspecialchars($urlGuia, ENT_QUOTES, 'UTF-8') . '" ' .
        'style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#6366f1,#a855f7);' .
        'color:#fff;font-weight:700;font-size:1rem;text-decoration:none;border-radius:10px;' .
        'letter-spacing:0.03em;">📘 Ver la Guía del Docente</a>' .
        '</div>' .

        '<hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:0 0 20px;">' .
        '<p style="margin:0;color:#6b7280;font-size:0.78rem;">' .
        'Tu panel docente: <a href="' . htmlspecialchars($urlDashboard, ENT_QUOTES, 'UTF-8') . '" ' .
        'style="color:#6366f1;">' . htmlspecialchars($urlDashboard, ENT_QUOTES, 'UTF-8') . '</a>' .
        '</p>' .

        '</div></body></html>';

    _triviax_enviar_email_adjunto(
        $emailDestino,
        $subject,
        $html,
        $guiaPdf === false ? 'GUIA_DOCENTE_TRIVIAX.md' : 'GUIA_DOCENTE_TRIVIAX.pdf',
        $guiaPdf === false ? $guiaMd : $guiaPdf,
        $guiaPdf === false ? 'text/markdown' : 'application/pdf'
    );
}

/**
 * Envía el email de verificación de cuenta al nuevo usuario.
 */
function _triviax_email_verificacion(string $emailDestino, string $nombre, string $token, string $rol): void {
    $url     = _triviax_base_url() . '/auth/verificar.php?token=' . urlencode($token);
    $rolTxt  = $rol === TRIVIAX_ROL_DOCENTE ? 'docente' : 'estudiante';
    $subject = 'TRIVIAX — Confirma tu cuenta';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body ' .
        'style="margin:0;padding:0;background:#090d16;font-family:system-ui,sans-serif;color:#e5e7eb;">' .
        '<div style="max-width:520px;margin:40px auto;background:rgba(255,255,255,0.05);' .
        'border:1px solid rgba(99,102,241,0.3);border-radius:16px;padding:40px 36px;">' .

        '<h1 style="margin:0 0 4px;font-size:2rem;font-weight:800;letter-spacing:2px;' .
        'background:linear-gradient(135deg,#6366f1,#a855f7);-webkit-background-clip:text;' .
        '-webkit-text-fill-color:transparent;background-clip:text;">TRIVIAX</h1>' .
        '<p style="margin:0 0 28px;color:#9ca3af;font-size:0.9rem;">Juego educativo</p>' .

        '<h2 style="margin:0 0 16px;font-size:1.3rem;color:#f9fafb;">¡Hola, ' .
        htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '!</h2>' .

        '<p style="margin:0 0 12px;line-height:1.7;color:#d1d5db;">' .
        'Tu cuenta de <strong>' . $rolTxt . '</strong> en TRIVIAX fue creada correctamente.' .
        '</p>' .
        '<p style="margin:0 0 28px;line-height:1.7;color:#d1d5db;">' .
        'Para activarla y poder iniciar sesión, haz clic en el botón:' .
        '</p>' .

        '<div style="text-align:center;margin-bottom:28px;">' .
        '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" ' .
        'style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#6366f1,#a855f7);' .
        'color:#fff;font-weight:700;font-size:1rem;text-decoration:none;border-radius:10px;' .
        'letter-spacing:0.03em;">✔ Verificar mi cuenta</a>' .
        '</div>' .

        '<p style="margin:0 0 8px;color:#9ca3af;font-size:0.82rem;line-height:1.6;">' .
        'Si el botón no funciona, copia y pega este enlace en tu navegador:</p>' .
        '<p style="margin:0 0 24px;font-size:0.78rem;color:#6366f1;word-break:break-all;">' .
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>' .

        '<hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:0 0 20px;">' .
        '<p style="margin:0;color:#6b7280;font-size:0.78rem;">' .
        '⏱ Este enlace expira en <strong>24 horas</strong>. Si no creaste esta cuenta, ignora este mensaje.' .
        '</p>' .

        '</div></body></html>';

    $text = "Hola {$nombre},\n\nConfirmá tu cuenta de {$rolTxt} en TRIVIAX:\n\n{$url}\n\n"
          . "Este enlace expira en 24 horas.\n";

    _triviax_enviar_email($emailDestino, $subject, $html, $text);
}

/**
 * Envía una notificación al administrador cuando se registra un nuevo usuario.
 */
function _triviax_email_notif_admin(string $emailNuevo, string $nombre, string $apellido, string $rol): void {
    $rolTxt  = $rol === TRIVIAX_ROL_DOCENTE ? 'DOCENTE 🎓' : 'Estudiante';
    $subject = 'TRIVIAX — Nuevo registro: ' . $nombre . ' ' . $apellido . ' (' . $rolTxt . ')';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body ' .
        'style="margin:0;padding:0;background:#090d16;font-family:system-ui,sans-serif;color:#e5e7eb;">' .
        '<div style="max-width:520px;margin:40px auto;background:rgba(255,255,255,0.05);' .
        'border:1px solid rgba(99,102,241,0.3);border-radius:16px;padding:36px;">' .

        '<h1 style="margin:0 0 4px;font-size:1.8rem;font-weight:800;letter-spacing:2px;' .
        'background:linear-gradient(135deg,#6366f1,#a855f7);-webkit-background-clip:text;' .
        '-webkit-text-fill-color:transparent;background-clip:text;">TRIVIAX</h1>' .
        '<p style="margin:0 0 24px;color:#9ca3af;font-size:0.85rem;">Notificación del sistema</p>' .

        '<h2 style="margin:0 0 20px;font-size:1.15rem;color:#f9fafb;">🔔 Nuevo usuario registrado</h2>' .

        '<table style="width:100%;border-collapse:collapse;font-size:0.92rem;">' .
        '<tr><td style="padding:8px 12px;color:#9ca3af;width:40%;">Nombre</td>' .
        '<td style="padding:8px 12px;color:#f9fafb;font-weight:600;">' .
        htmlspecialchars($nombre . ' ' . $apellido, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
        '<tr style="background:rgba(255,255,255,0.03);">' .
        '<td style="padding:8px 12px;color:#9ca3af;">Email</td>' .
        '<td style="padding:8px 12px;color:#a5b4fc;">' .
        htmlspecialchars($emailNuevo, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
        '<tr><td style="padding:8px 12px;color:#9ca3af;">Rol</td>' .
        '<td style="padding:8px 12px;color:#f9fafb;">' . $rolTxt . '</td></tr>' .
        '<tr style="background:rgba(255,255,255,0.03);">' .
        '<td style="padding:8px 12px;color:#9ca3af;">Fecha</td>' .
        '<td style="padding:8px 12px;color:#f9fafb;">' . date('d/m/Y H:i:s') . '</td></tr>' .
        '</table>' .

        '<hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:24px 0 16px;">' .
        '<p style="margin:0;color:#6b7280;font-size:0.78rem;">' .
        'El usuario debe verificar su email antes de poder iniciar sesión.' .
        '</p>' .
        '</div></body></html>';

    $text = "Nuevo usuario registrado en TRIVIAX:\n"
          . "Nombre: {$nombre} {$apellido}\nEmail: {$emailNuevo}\nRol: {$rolTxt}\n"
          . 'Fecha: ' . date('d/m/Y H:i:s') . "\n";

    _triviax_enviar_email(TRIVIAX_ADMIN_EMAIL, $subject, $html, $text);
}

// ─────────────────────────────────────────────
// REGISTRO DE USUARIO
// ─────────────────────────────────────────────

/**
 * Registra un nuevo usuario (docente o estudiante).
 * La cuenta queda INACTIVA hasta que el usuario verifique su email.
 *
 * @return array  ['ok' => bool, 'error' => string|null, 'id' => int|null]
 */
function triviax_registrar_usuario(string $nombre, string $apellido, string $email, string $password, string $rol): array {
    $nombre   = trim($nombre);
    $apellido = trim($apellido);
    $email    = trim(strtolower($email));
    $rol      = trim($rol);

    // Validaciones básicas
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre es obligatorio.', 'id' => null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo electrónico no es válido.', 'id' => null];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.', 'id' => null];
    }
    if (!in_array($rol, [TRIVIAX_ROL_DOCENTE, TRIVIAX_ROL_ESTUDIANTE], true)) {
        return ['ok' => false, 'error' => 'Rol no válido.', 'id' => null];
    }

    try {
        $pdo = triviax_db();

        // Purgar registros que nunca se verificaron y cuyo token ya expiró.
        // Libera esos emails para un nuevo intento de registro y evita que
        // registros abandonados (o de bots) acumulen filas muertas.
        try {
            $pdo->exec('DELETE FROM usuarios
                         WHERE email_verificado = 0 AND activo = 0
                           AND token_expira IS NOT NULL AND token_expira < NOW()');
        } catch (\Throwable $e) {
            // La limpieza nunca debe impedir un registro nuevo.
        }

        // Verificar email duplicado
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'Ya existe una cuenta con ese correo electrónico.', 'id' => null, 'local_preview' => null];
        }

        $hash   = password_hash($password, PASSWORD_DEFAULT);
        $token  = bin2hex(random_bytes(32));                      // 64 chars hex
        $expira = date('Y-m-d H:i:s', time() + 86400);           // +24 horas

        $stmt = $pdo->prepare('
            INSERT INTO usuarios
                (nombre, apellido, email, password_hash, rol,
                 activo, email_verificado, token_verificacion, token_expira)
            VALUES (?, ?, ?, ?, ?,  0, 0, ?, ?)
        ');
        $stmt->execute([$nombre, $apellido, $email, $hash, $rol, $token, $expira]);
        $id = (int) $pdo->lastInsertId();

        if (_triviax_es_local()) {
            // ── MODO LOCAL ───────────────────────────────────────────────────
            // En lugar de mail(), el navegador va a una página que simula
            // el email con el enlace clicable. No se necesita SMTP configurado.
            $localPreview = '/triviax/auth/mail_preview.php?' . http_build_query([
                'token'    => $token,
                'email'    => $email,
                'nombre'   => $nombre,
                'apellido' => $apellido,
                'rol'      => $rol,
            ]);
        } else {
            // ── MODO REMOTO ──────────────────────────────────────────────────
            // Envío real por email (no bloquea el flujo aunque falle)
            _triviax_email_verificacion($email, $nombre, $token, $rol);
            _triviax_email_notif_admin($email, $nombre, $apellido, $rol);
            $localPreview = null;
        }

        return ['ok' => true, 'error' => null, 'id' => $id, 'local_preview' => $localPreview];

    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'No hay conexión con la base de datos.', 'id' => null, 'local_preview' => null];
    } catch (\PDOException $e) {
        return ['ok' => false, 'error' => 'Error al registrar el usuario.', 'id' => null, 'local_preview' => null];
    }
}

// ─────────────────────────────────────────────
// VERIFICACIÓN DE TOKEN DE EMAIL
// ─────────────────────────────────────────────

/**
 * Verifica el token recibido por email y activa la cuenta del usuario.
 *
 * @param  string $token  Token de 64 chars recibido por GET
 * @return array  ['ok' => bool, 'error' => string|null, 'usuario' => array|null]
 */
function triviax_verificar_token_email(string $token): array {
    $token = trim($token);

    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return ['ok' => false, 'error' => 'El enlace de verificación no es válido.', 'usuario' => null];
    }

    try {
        $pdo = triviax_db();

        $stmt = $pdo->prepare('
            SELECT id, nombre, apellido, email, rol, token_expira
            FROM usuarios
            WHERE token_verificacion = ? AND email_verificado = 0
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return [
                'ok'      => false,
                'error'   => 'Este enlace ya fue usado o no existe. Si ya verificaste tu cuenta, puedes iniciar sesión.',
                'usuario' => null,
            ];
        }

        // Verificar expiración
        if ($usuario['token_expira'] !== null && strtotime($usuario['token_expira']) < time()) {
            return [
                'ok'      => false,
                'error'   => 'Este enlace expiró (tenía validez de 24 horas). Regístrate nuevamente.',
                'usuario' => null,
            ];
        }

        // Activar cuenta
        $upd = $pdo->prepare('
            UPDATE usuarios
               SET activo = 1, email_verificado = 1,
                   token_verificacion = NULL, token_expira = NULL
             WHERE id = ?
        ');
        $upd->execute([$usuario['id']]);

        $datos = [
            'id'       => (int) $usuario['id'],
            'nombre'   => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'email'    => $usuario['email'],
            'rol'      => $usuario['rol'],
        ];

        // Docente verificado → recibe la Guía del Docente por email.
        // Solo en modo remoto: en local no hay MTA y el flujo usa mail_preview.
        if ($usuario['rol'] === TRIVIAX_ROL_DOCENTE && !_triviax_es_local()) {
            try {
                _triviax_email_guia_docente($usuario['email'], $usuario['nombre']);
            } catch (\Throwable $e) {
                // Best effort: la verificación nunca falla por el email de la guía.
            }
        }

        return ['ok' => true, 'error' => null, 'usuario' => $datos];

    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'No hay conexión con la base de datos.', 'usuario' => null];
    } catch (\PDOException $e) {
        return ['ok' => false, 'error' => 'Error interno al verificar el token.', 'usuario' => null];
    }
}

// ─────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────

/**
 * Autentica un usuario por email y contraseña.
 * Requiere que la cuenta esté verificada (email_verificado = 1).
 *
 * @return array ['ok' => bool, 'error' => string|null, 'usuario' => array|null]
 */
function triviax_rate_limit_enabled(): bool {
    return triviax_env_bool('RATE_LIMIT_ENABLED', true);
}

function triviax_rate_limit_check(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): bool {
    if (!triviax_rate_limit_enabled()) {
        return true;
    }
    try {
        $pdo = triviax_db();
        $stmt = $pdo->prepare(
            'SELECT attempts, first_attempt_at, blocked_until
             FROM rate_limits WHERE scope = ? AND identifier = ? LIMIT 1'
        );
        $stmt->execute([$scope, $identifier]);
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }
        if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > time()) {
            return false;
        }
        if (strtotime($row['first_attempt_at']) < (time() - $windowSeconds)) {
            return true;
        }
        return ((int)$row['attempts']) < $maxAttempts;
    } catch (\Throwable $e) {
        return true;
    }
}

function triviax_rate_limit_hit(string $scope, string $identifier, int $windowSeconds, int $blockSeconds, int $blockAfter = 5): void {
    if (!triviax_rate_limit_enabled()) {
        return;
    }
    try {
        $pdo = triviax_db();
        $now = date('Y-m-d H:i:s');
        $blockedUntil = date('Y-m-d H:i:s', time() + $blockSeconds);
        $stmt = $pdo->prepare(
            'SELECT attempts, first_attempt_at FROM rate_limits WHERE scope = ? AND identifier = ? LIMIT 1'
        );
        $stmt->execute([$scope, $identifier]);
        $row = $stmt->fetch();
        if (!$row || strtotime($row['first_attempt_at']) < (time() - $windowSeconds)) {
            $pdo->prepare(
                'INSERT INTO rate_limits (scope, identifier, attempts, first_attempt_at, last_attempt_at, blocked_until)
                 VALUES (?, ?, 1, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE attempts = 1, first_attempt_at = VALUES(first_attempt_at),
                   last_attempt_at = VALUES(last_attempt_at), blocked_until = NULL'
            )->execute([$scope, $identifier, $now, $now]);
            return;
        }
        $attempts = ((int)$row['attempts']) + 1;
        // La comparación se hace en PHP (numérica). Hacerla en SQL con
        // parámetros ligados como strings comparaba lexicográficamente
        // ('7' >= '60' == true), bloqueando muchísimo antes de blockAfter
        // cuando este tiene más de un dígito.
        if ($attempts >= $blockAfter) {
            $pdo->prepare(
                'UPDATE rate_limits SET attempts = ?, last_attempt_at = ?, blocked_until = ?
                 WHERE scope = ? AND identifier = ?'
            )->execute([$attempts, $now, $blockedUntil, $scope, $identifier]);
        } else {
            $pdo->prepare(
                'UPDATE rate_limits SET attempts = ?, last_attempt_at = ?
                 WHERE scope = ? AND identifier = ?'
            )->execute([$attempts, $now, $scope, $identifier]);
        }
    } catch (\Throwable $e) {
        return;
    }
}

function triviax_rate_limit_clear(string $scope, string $identifier): void {
    try {
        triviax_db()->prepare('DELETE FROM rate_limits WHERE scope = ? AND identifier = ?')
            ->execute([$scope, $identifier]);
    } catch (\Throwable $e) {
        return;
    }
}

// ─────────────────────────────────────────────
// ANTI-ABUSO EN REGISTRO
// Capas: rate limit por IP + honeypot + tiempo mínimo + Cloudflare Turnstile.
// Turnstile es opcional: si no hay claves en triviax.env, esa capa se omite.
// Además, en modo local SIEMPRE se omite (no valida localhost), salvo que se
// fuerce con TURNSTILE_FORCE_LOCAL=true. Claves: TURNSTILE_SITE_KEY y
// TURNSTILE_SECRET_KEY.
// ─────────────────────────────────────────────

function triviax_turnstile_site_key(): string {
    return _triviax_env('TURNSTILE_SITE_KEY');
}

function triviax_turnstile_enabled(): bool {
    // En modo local nunca se usa Cloudflare: el widget no puede validar
    // localhost/IPs privadas y daría error. Se puede forzar para pruebas
    // locales con TURNSTILE_FORCE_LOCAL=true en triviax.env.
    if (_triviax_es_local() && !triviax_env_bool('TURNSTILE_FORCE_LOCAL', false)) {
        return false;
    }
    return triviax_turnstile_site_key() !== ''
        && _triviax_env('TURNSTILE_SECRET_KEY') !== '';
}

/**
 * Verifica el token de Turnstile contra la API de Cloudflare.
 * Si Cloudflare no responde (caída de red), deja pasar pero lo audita:
 * un atacante no puede provocar ese fallo desde afuera.
 */
function _triviax_turnstile_verificar(string $token): bool {
    $secret = _triviax_env('TURNSTILE_SECRET_KEY');
    if ($secret === '') {
        return true; // Turnstile no configurado: capa desactivada
    }
    if ($token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $respuesta = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $respuesta = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ]]);
        $respuesta = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    }

    if ($respuesta === false) {
        triviax_audit_log('turnstile_sin_respuesta', 'registro', $_SERVER['REMOTE_ADDR'] ?? null);
        return true;
    }

    $datos = json_decode($respuesta, true);
    return is_array($datos) && !empty($datos['success']);
}

/**
 * Genera los campos ocultos anti-bot para insertar dentro del <form> de
 * registro: honeypot, sello de tiempo firmado y widget de Turnstile.
 */
function triviax_registro_campos_antibot(): string {
    $ts     = (string) time();
    $secret = _triviax_env('APP_SECRET', _triviax_env('tkey', 'lkjh1234'));
    $firma  = hash_hmac('sha256', $ts, $secret);

    // Honeypot: invisible para personas, tentador para bots que llenan todo.
    $html = '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">'
          . '<label for="web_url">Sitio web</label>'
          . '<input type="text" id="web_url" name="web_url" tabindex="-1" autocomplete="off" value="">'
          . '</div>'
          . '<input type="hidden" name="form_ts" value="'
          . htmlspecialchars($ts . '.' . $firma, ENT_QUOTES, 'UTF-8') . '">';

    if (triviax_turnstile_enabled()) {
        $html .= '<div class="cf-turnstile" style="margin:4px auto 0;" data-sitekey="'
               . htmlspecialchars(triviax_turnstile_site_key(), ENT_QUOTES, 'UTF-8')
               . '" data-theme="dark" data-language="es"></div>'
               . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    return $html;
}

/**
 * Valida todas las capas anti-bot de un POST de registro.
 * Llamar después de triviax_verificar_csrf() y antes de registrar.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function triviax_registro_antibot_check(): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'local';

    // 1) Rate limit por IP: máx. 8 intentos por hora, luego bloqueo de 1 hora
    if (!triviax_rate_limit_check('registro', $ip, 8, 3600)) {
        triviax_audit_log('registro_rate_limited', 'registro', $ip);
        return ['ok' => false, 'error' => 'Demasiados intentos de registro desde esta conexión. Espera una hora e intenta nuevamente.'];
    }
    triviax_rate_limit_hit('registro', $ip, 3600, 3600, 8);

    // 2) Honeypot: si el campo invisible viene con contenido, es un bot
    if (trim($_POST['web_url'] ?? '') !== '') {
        triviax_audit_log('registro_honeypot', 'registro', $ip);
        return ['ok' => false, 'error' => 'No se pudo procesar el registro. Intenta nuevamente.'];
    }

    // 3) Tiempo mínimo: una persona tarda más de 3 segundos en llenar el
    //    formulario. La marca viene firmada para que no se pueda fabricar.
    $campo = trim($_POST['form_ts'] ?? '');
    $secret = _triviax_env('APP_SECRET', _triviax_env('tkey', 'lkjh1234'));
    $partes = explode('.', $campo, 2);
    $tsOk = count($partes) === 2
        && ctype_digit($partes[0])
        && hash_equals(hash_hmac('sha256', $partes[0], $secret), $partes[1])
        && (time() - (int)$partes[0]) >= 3
        && (time() - (int)$partes[0]) <= 21600; // formulario de más de 6 h: recargar
    if (!$tsOk) {
        triviax_audit_log('registro_ts_invalido', 'registro', $ip);
        return ['ok' => false, 'error' => 'El formulario expiró o se envió demasiado rápido. Recarga la página e intenta nuevamente.'];
    }

    // 4) Turnstile (si está configurado)
    if (triviax_turnstile_enabled() && !_triviax_turnstile_verificar(trim($_POST['cf-turnstile-response'] ?? ''))) {
        triviax_audit_log('registro_turnstile_fallo', 'registro', $ip);
        return ['ok' => false, 'error' => 'No pudimos confirmar que no eres un robot. Recarga la página e intenta nuevamente.'];
    }

    return ['ok' => true, 'error' => ''];
}

function triviax_audit_log(string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void {
    try {
        $pdo = triviax_db();
        $user = $_SESSION['triviax_usuario'] ?? null;
        $pdo->prepare(
            'INSERT INTO audit_log
             (user_id, rol, action, entity_type, entity_id, ip_address, user_agent, details_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $user['id'] ?? null,
            $user['rol'] ?? null,
            $action,
            $entityType,
            $entityId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (\Throwable $e) {
        return;
    }
}

function triviax_login(string $email, string $password): array {
    $email = trim(strtolower($email));
    $rateIdentifier = $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Correo electrónico no válido.', 'usuario' => null];
    }
    if (trim($password) === '') {
        return ['ok' => false, 'error' => 'La contraseña no puede estar vacía.', 'usuario' => null];
    }

    try {
        $pdo  = triviax_db();
        if (!triviax_rate_limit_check('login', $rateIdentifier, 5, 600)) {
            triviax_audit_log('login_rate_limited', 'usuario', $email);
            return ['ok' => false, 'error' => 'Demasiados intentos. Espera unos minutos e intenta nuevamente.', 'usuario' => null];
        }
        $stmt = $pdo->prepare('
            SELECT id, nombre, apellido, email, password_hash, rol, activo, email_verificado
            FROM usuarios
            WHERE email = ?
        ');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            // Mitigación de timing attack: siempre verificamos un hash falso
            password_verify($password, '$2y$10$invalido');
            triviax_rate_limit_hit('login', $rateIdentifier, 600, 600, 5);
            triviax_audit_log('login_failed', 'usuario', $email);
            return ['ok' => false, 'error' => 'Correo o contraseña incorrectos.', 'usuario' => null];
        }

        if (!password_verify($password, $usuario['password_hash'])) {
            triviax_rate_limit_hit('login', $rateIdentifier, 600, 600, 5);
            triviax_audit_log('login_failed', 'usuario', (string)$usuario['id']);
            return ['ok' => false, 'error' => 'Correo o contraseña incorrectos.', 'usuario' => null];
        }

        // Verificar que el email fue confirmado
        // (columna puede no existir antes de correr la migración v4.1)
        $verificado = isset($usuario['email_verificado']) ? (bool)$usuario['email_verificado'] : true;
        if (!$verificado) {
            return [
                'ok'      => false,
                'error'   => 'Tu cuenta aún no fue verificada. Revisa tu correo y haz clic en el enlace de confirmación.',
                'usuario' => null,
            ];
        }

        if (!$usuario['activo']) {
            return ['ok' => false, 'error' => 'Esta cuenta está desactivada. Contacta al administrador.', 'usuario' => null];
        }

        // Regenerar session ID para prevenir session fixation
        triviax_session_start();
        session_regenerate_id(true);

        $datosUsuario = [
            'id'       => (int) $usuario['id'],
            'nombre'   => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'email'    => $usuario['email'],
            'rol'      => $usuario['rol'],
        ];

        $_SESSION['triviax_usuario'] = $datosUsuario;
        $_SESSION['triviax_last_activity'] = time();
        triviax_rate_limit_clear('login', $rateIdentifier);
        triviax_audit_log('login_success', 'usuario', (string)$datosUsuario['id']);

        // Superadmin: determinar redirect especial
        $redirect = null;
        if ($datosUsuario['rol'] === TRIVIAX_ROL_SUPERADMIN) {
            $redirect = '/triviax/panel/super.php';
        }

        return ['ok' => true, 'error' => null, 'usuario' => $datosUsuario, 'redirect' => $redirect];

    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'No hay conexión con la base de datos.', 'usuario' => null];
    } catch (\PDOException $e) {
        return ['ok' => false, 'error' => 'Error interno al iniciar sesión.', 'usuario' => null];
    }
}

// ─────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────

/**
 * Cierra la sesión del usuario actual.
 */
function triviax_logout(): void {
    triviax_session_start();
    unset($_SESSION['triviax_usuario']);
    session_destroy();
}

// ─────────────────────────────────────────────
// VERIFICACIÓN DE SESIÓN
// ─────────────────────────────────────────────

/**
 * Devuelve los datos del usuario autenticado, o null si no hay sesión.
 */
function triviax_usuario_actual(): ?array {
    triviax_session_start();
    return $_SESSION['triviax_usuario'] ?? null;
}

/** Verifica si hay un usuario autenticado. */
function triviax_esta_autenticado(): bool {
    return triviax_usuario_actual() !== null;
}

/** Verifica si el usuario actual es docente. */
function triviax_es_docente(): bool {
    $u = triviax_usuario_actual();
    return $u !== null && $u['rol'] === TRIVIAX_ROL_DOCENTE;
}

/** Verifica si el usuario actual es estudiante. */
function triviax_es_estudiante(): bool {
    $u = triviax_usuario_actual();
    return $u !== null && $u['rol'] === TRIVIAX_ROL_ESTUDIANTE;
}

/**
 * Redirige al login si el usuario no está autenticado.
 * Opcionalmente exige un rol específico.
 *
 * @param string|null $rolRequerido 'docente' | 'estudiante' | null (cualquiera)
 */
function triviax_requerir_auth(?string $rolRequerido = null): void {
    triviax_session_start();
    $usuario = triviax_usuario_actual();

    if ($usuario === null) {
        $redir = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: /triviax/auth/login.php?redir=' . $redir);
        exit;
    }

    if ($rolRequerido !== null && $usuario['rol'] !== $rolRequerido) {
        header('Location: /triviax/auth/login.php?error=acceso_denegado');
        exit;
    }
}

/**
 * Reenvía el email de verificación a un usuario que aún no validó su cuenta.
 * Genera un token nuevo (válido 24 h) y lo guarda en DB.
 * En local devuelve la URL de preview; en remoto envía el email real.
 *
 * @return array ['ok' => bool, 'error' => string|null, 'local_preview' => string|null]
 */
function triviax_reenviar_verificacion(int $userId): array {
    try {
        $pdo  = triviax_db();
        $stmt = $pdo->prepare(
            'SELECT id, nombre, apellido, email, rol, email_verificado FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch();

        if (!$u) {
            return ['ok' => false, 'error' => 'Usuario no encontrado.', 'local_preview' => null];
        }
        if ((bool)$u['email_verificado']) {
            return ['ok' => false, 'error' => 'Esta cuenta ya está verificada.', 'local_preview' => null];
        }

        $token  = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', time() + 86400); // +24 h

        $pdo->prepare(
            'UPDATE usuarios SET token_verificacion = ?, token_expira = ? WHERE id = ?'
        )->execute([$token, $expira, $userId]);

        if (_triviax_es_local()) {
            $localPreview = '/triviax/auth/mail_preview.php?' . http_build_query([
                'token'    => $token,
                'email'    => $u['email'],
                'nombre'   => $u['nombre'],
                'apellido' => $u['apellido'],
                'rol'      => $u['rol'],
            ]);
            return ['ok' => true, 'error' => null, 'local_preview' => $localPreview];
        }

        _triviax_email_verificacion($u['email'], $u['nombre'], $token, $u['rol']);
        return ['ok' => true, 'error' => null, 'local_preview' => null];

    } catch (\Exception $e) {
        return ['ok' => false, 'error' => 'Error al procesar la solicitud.', 'local_preview' => null];
    }
}

/** Verifica si el usuario actual es superadmin. */
function triviax_es_superadmin(): bool {
    $u = triviax_usuario_actual();
    return $u !== null && $u['rol'] === TRIVIAX_ROL_SUPERADMIN;
}

/**
 * Redirige al login si el usuario no es superadmin.
 * Debe llamarse al inicio de las páginas del panel superadmin.
 */
function triviax_requerir_superadmin(): void {
    triviax_session_start();
    $usuario = triviax_usuario_actual();
    if ($usuario === null) {
        header('Location: /triviax/auth/login.php');
        exit;
    }
    if ($usuario['rol'] !== TRIVIAX_ROL_SUPERADMIN) {
        header('Location: /triviax/auth/login.php?error=acceso_denegado');
        exit;
    }
}

// ─────────────────────────────────────────────
// RECUPERACIÓN DE CONTRASEÑA
// ─────────────────────────────────────────────

/**
 * Inicia el flujo de recuperación: genera token, lo guarda en DB y
 * (en local) devuelve la URL de preview; (en remoto) envía el email real.
 *
 * Solo funciona si la cuenta existe, está activa y tiene el email verificado.
 *
 * @return array ['ok' => bool, 'error' => string|null, 'local_preview' => string|null]
 */
function triviax_solicitar_reset_password(string $email): array {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo electrónico no es válido.', 'local_preview' => null];
    }

    try {
        $pdo  = triviax_db();
        $stmt = $pdo->prepare(
            'SELECT id, nombre FROM usuarios WHERE email = ? AND activo = 1 AND email_verificado = 1'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return ['ok' => false, 'error' => 'No existe ninguna cuenta activa con ese correo.', 'local_preview' => null];
        }

        $token  = bin2hex(random_bytes(32));                    // 64 chars hex
        $expira = date('Y-m-d H:i:s', time() + 3600);          // expira en 1 hora

        $pdo->prepare('UPDATE usuarios SET reset_token = ?, reset_expira = ? WHERE id = ?')
            ->execute([$token, $expira, $usuario['id']]);

        if (_triviax_es_local()) {
            $localPreview = '/triviax/auth/mail_preview.php?' . http_build_query([
                'type'   => 'reset',
                'token'  => $token,
                'email'  => $email,
                'nombre' => $usuario['nombre'],
            ]);
        } else {
            _triviax_email_reset_password($email, $usuario['nombre'], $token);
            $localPreview = null;
        }

        return ['ok' => true, 'error' => null, 'local_preview' => $localPreview];

    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'No hay conexión con la base de datos.', 'local_preview' => null];
    } catch (\PDOException $e) {
        return ['ok' => false, 'error' => 'Error al procesar la solicitud.', 'local_preview' => null];
    }
}

/**
 * Valida un token de reset y devuelve el usuario asociado si es vigente.
 *
 * @return array ['ok' => bool, 'error' => string|null, 'usuario' => array|null]
 */
function triviax_verificar_reset_token(string $token): array {
    $token = trim($token);
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return ['ok' => false, 'error' => 'El enlace no es válido.', 'usuario' => null];
    }

    try {
        $pdo  = triviax_db();
        $stmt = $pdo->prepare(
            'SELECT id, nombre, email, reset_expira FROM usuarios WHERE reset_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return ['ok' => false, 'error' => 'Este enlace no existe o ya fue usado.', 'usuario' => null];
        }
        if ($usuario['reset_expira'] !== null && strtotime($usuario['reset_expira']) < time()) {
            return ['ok' => false, 'error' => 'Este enlace expiró (válido por 1 hora). Solicitá uno nuevo.', 'usuario' => null];
        }

        return ['ok' => true, 'error' => null, 'usuario' => $usuario];

    } catch (\Exception $e) {
        return ['ok' => false, 'error' => 'Error interno al validar el enlace.', 'usuario' => null];
    }
}

/**
 * Aplica la nueva contraseña e invalida el token de reset.
 *
 * @return array ['ok' => bool, 'error' => string|null]
 */
function triviax_ejecutar_reset_password(string $token, string $nuevaPassword): array {
    $token = trim($token);
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return ['ok' => false, 'error' => 'Token inválido.'];
    }
    if (strlen(trim($nuevaPassword)) < 6) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.'];
    }

    try {
        $pdo  = triviax_db();
        $stmt = $pdo->prepare(
            'SELECT id, reset_expira FROM usuarios WHERE reset_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return ['ok' => false, 'error' => 'Este enlace no existe o ya fue usado.'];
        }
        if ($usuario['reset_expira'] !== null && strtotime($usuario['reset_expira']) < time()) {
            return ['ok' => false, 'error' => 'Este enlace expiró. Solicitá uno nuevo.'];
        }

        $plain = trim($nuevaPassword);
        $hash  = password_hash($plain, PASSWORD_DEFAULT);
        $pdo->prepare(
            'UPDATE usuarios SET password_hash = ?,
             reset_token = NULL, reset_expira = NULL WHERE id = ?'
        )->execute([$hash, $usuario['id']]);

        return ['ok' => true, 'error' => null];

    } catch (\Exception $e) {
        return ['ok' => false, 'error' => 'Error al actualizar la contraseña.'];
    }
}

/**
 * Genera el HTML del email de restablecimiento (modo producción).
 */
function _triviax_email_reset_password(string $emailDestino, string $nombre, string $token): void {
    $url     = _triviax_base_url() . '/auth/reset_password.php?token=' . urlencode($token);
    $subject = 'TRIVIAX — Restablecé tu contraseña';

    $html =
        '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body ' .
        'style="margin:0;padding:0;background:#090d16;font-family:system-ui,sans-serif;color:#e5e7eb;">' .
        '<div style="max-width:520px;margin:40px auto;background:rgba(255,255,255,0.05);' .
        'border:1px solid rgba(99,102,241,0.3);border-radius:16px;padding:40px 36px;">' .

        '<h1 style="margin:0 0 4px;font-size:2rem;font-weight:800;letter-spacing:2px;' .
        'background:linear-gradient(135deg,#6366f1,#a855f7);-webkit-background-clip:text;' .
        '-webkit-text-fill-color:transparent;background-clip:text;">TRIVIAX</h1>' .
        '<p style="margin:0 0 28px;color:#9ca3af;font-size:0.9rem;">Juego educativo</p>' .

        '<h2 style="margin:0 0 16px;font-size:1.3rem;color:#f9fafb;">¡Hola, ' .
        htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '!</h2>' .

        '<p style="margin:0 0 12px;line-height:1.7;color:#d1d5db;">' .
        'Recibimos una solicitud para restablecer la contraseña de tu cuenta en TRIVIAX.' .
        '</p>' .
        '<p style="margin:0 0 28px;line-height:1.7;color:#d1d5db;">' .
        'Haz clic en el botón para crear una nueva contraseña:' .
        '</p>' .

        '<div style="text-align:center;margin-bottom:28px;">' .
        '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" ' .
        'style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#6366f1,#a855f7);' .
        'color:#fff;font-weight:700;font-size:1rem;text-decoration:none;border-radius:10px;' .
        'letter-spacing:0.03em;">🔑 Crear nueva contraseña</a>' .
        '</div>' .

        '<p style="margin:0 0 8px;color:#9ca3af;font-size:0.82rem;line-height:1.6;">' .
        'Si el botón no funciona, copia y pega este enlace en tu navegador:</p>' .
        '<p style="margin:0 0 24px;font-size:0.78rem;color:#6366f1;word-break:break-all;">' .
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>' .

        '<hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:0 0 20px;">' .
        '<p style="margin:0;color:#6b7280;font-size:0.78rem;">' .
        '⏱ Este enlace expira en <strong>1 hora</strong>. ' .
        'Si no solicitaste este cambio, ignora este mensaje — tu contraseña no cambiará.' .
        '</p>' .
        '</div></body></html>';

    $text = "Hola {$nombre},\n\nRestablecé tu contraseña en TRIVIAX:\n\n{$url}\n\n"
          . "Este enlace expira en 1 hora.\n"
          . "Si no solicitaste el cambio, ignora este mensaje.\n";

    _triviax_enviar_email($emailDestino, $subject, $html, $text);
}

// ─────────────────────────────────────────────
// TOKEN CSRF
// ─────────────────────────────────────────────

/**
 * Genera (o recupera) el token CSRF de la sesión actual.
 * Siempre retorna la misma cadena dentro de un mismo request
 * (se rota SOLO en logout o en rotación manual).
 */
function triviax_csrf_token(): string {
    triviax_session_start();
    if (empty($_SESSION['triviax_csrf'])) {
        $_SESSION['triviax_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['triviax_csrf'];
}

/**
 * Devuelve un input hidden con el token CSRF listo para insertar en un form.
 * Uso: <?= triviax_csrf_input() ?>
 */
function triviax_csrf_input(): string {
    $token = htmlspecialchars(triviax_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verifica el token CSRF enviado en un formulario POST.
 * Termina la ejecución con 403 si el token no es válido.
 * Para formularios HTML normales.
 */
function triviax_verificar_csrf(): void {
    triviax_session_start();
    $token    = $_POST['csrf_token'] ?? '';
    $esperado = $_SESSION['triviax_csrf'] ?? '';

    if ($esperado === '' || !hash_equals($esperado, $token)) {
        http_response_code(403);
        die('Token de seguridad inválido. Recargá la página e intenta de nuevo.');
    }
}

/**
 * Alias semántico de triviax_verificar_csrf().
 * Para usar en endpoints que documentan explícitamente "o falla".
 */
function triviax_verify_csrf_or_fail(): void {
    triviax_verificar_csrf();
}

/**
 * Verifica CSRF para endpoints JSON (API/AJAX).
 * Si falla, responde con JSON 403 y termina la ejecución.
 * Acepta el token en POST o en el header X-CSRF-Token.
 */
function triviax_verify_csrf_json(): void {
    triviax_session_start();
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (is_array($data)) {
            $token = $data['csrf_token'] ?? '';
        }
    }
    if ($token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_Token'] ?? '';
    }
    $esperado = $_SESSION['triviax_csrf'] ?? '';

    if ($esperado === '' || !hash_equals($esperado, $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
