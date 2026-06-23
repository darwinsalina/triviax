<?php
/**
 * TRIVIAX - Conexión a la base de datos (v4.0)
 * Usa las credenciales de C:\wamp64\dbconn\dbkey_triviax.php
 * Detecta automáticamente entorno local vs remoto por hostname.
 */

/**
 * TRIVIAX_BASE — prefijo de URL bajo el que se sirve la app.
 *
 * Se detecta solo comparando la carpeta real de la app contra el
 * DOCUMENT_ROOT del servidor, de modo que la app funcione sin tocar
 * código sin importar dónde se la copie:
 *   · wamp local  →  app en  www/triviax  y docroot  www      →  "/triviax"
 *   · subdominio  →  docroot apunta a la propia carpeta triviax →  ""  (raíz)
 *
 * Úsala SIEMPRE para construir rutas absolutas internas
 * (header('Location: ' . TRIVIAX_BASE . '/...'), href="<?= TRIVIAX_BASE ?>/...").
 */
if (!defined('TRIVIAX_BASE')) {
    // __DIR__ = .../triviax/php  →  dirname = raíz de la app (.../triviax)
    // Normalizar a "/" ANTES de recortar la barra final: en Windows el
    // DOCUMENT_ROOT puede llegar con "\" al final y un rtrim('/') no lo vería.
    $triviaxAppRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $triviaxDocRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    $triviaxBase = '';
    if ($triviaxDocRoot !== '' && strpos($triviaxAppRoot, $triviaxDocRoot) === 0) {
        // Lo que sobra del root de la app por encima del docroot es el prefijo.
        $triviaxBase = rtrim(substr($triviaxAppRoot, strlen($triviaxDocRoot)), '/');
    }
    define('TRIVIAX_BASE', $triviaxBase);
    unset($triviaxAppRoot, $triviaxDocRoot, $triviaxBase);
}

/**
 * Devuelve la conexión PDO singleton.
 * @throws RuntimeException si la conexión falla.
 */
function triviax_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // ── Detección de entorno ────────────────────────────────────────────
    // Compatible con PHP 7.x (no usa str_starts_with)
    $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Quitar el puerto si viene incluido (ej: "localhost:8080")
    $bareHost = strtolower(explode(':', $httpHost)[0]);
    $isLocal  = (
        $bareHost === 'localhost'
        || $bareHost === '127.0.0.1'
        || $bareHost === '::1'
        || strpos($bareHost, '127.')   === 0
        || strpos($bareHost, '192.168.') === 0
        || strpos($bareHost, '10.')    === 0
    );

    // $Server debe estar definido ANTES del require para que dbkey_triviax.php
    // lo use en su condición. El archivo remoto puede redefinirlo por sí solo.
    $Server = $isLocal ? 'Local' : 'Remoto';

    // ── Localizar el archivo de credenciales ────────────────────────────
    // __DIR__ = .../triviax/php  →  ../../../ = C:\wamp64\
    // El archivo está en C:\wamp64\dbconn\dbkey_triviax.php
    $candidates = [
        __DIR__ . '/../../../dbconn/dbkey_triviax.php',        // desde php/ (3 niveles)
        dirname(dirname(__DIR__)) . '/dbconn/dbkey_triviax.php', // desde raíz del proyecto
    ];
    // Fallback vía DOCUMENT_ROOT (resuelve configuraciones de vhost atípicas)
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $dr = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
        $candidates[] = $dr . '/../dbconn/dbkey_triviax.php';
        $candidates[] = $dr . '/../../dbconn/dbkey_triviax.php';
    }

    $dbkeyPath = null;
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real !== false && is_readable($real)) {
            $dbkeyPath = $real;
            break;
        }
    }

    if ($dbkeyPath === null) {
        throw new RuntimeException(
            'No se encontró el archivo de credenciales dbkey_triviax.php. ' .
            'Verifica que exista en C:\\wamp64\\dbconn\\ (o el equivalente en el servidor).'
        );
    }

    require $dbkeyPath;   // require (no _once) para que ejecute en este scope

    $dsn = "mysql:host={$dbhost};dbname={$database};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $dbuser, $dbpass, $options);
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException(
            'No se pudo conectar a la base de datos "' . $database . '". ' .
            'Verifica que MySQL esté corriendo y que las credenciales sean correctas.'
        );
    }
}

/**
 * Verifica si la BD está disponible sin lanzar excepción.
 * Útil para mostrar advertencias sin romper el flujo legado.
 */
function triviax_db_available(): bool {
    try {
        triviax_db();
        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}
