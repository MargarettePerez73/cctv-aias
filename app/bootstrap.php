<?php
declare(strict_types=1);

require __DIR__ . '/helpers.php';
require __DIR__ . '/services.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/permissions.php';
require __DIR__ . '/layout.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$configFile = getenv('CCTV_CONFIG') ?: __DIR__ . '/config.php';
$config = require (file_exists($configFile) ? $configFile : __DIR__ . '/config.example.php');
$config += [
    'storage_path' => dirname(__DIR__) . '/storage',
    'timezone' => 'Asia/Manila',
    'session_name' => 'cctv_session',
];
date_default_timezone_set($config['timezone']);

ini_set('session.gc_maxlifetime', '43200');
session_name($config['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']),
    'samesite' => 'Strict',
]);
session_start();

$GLOBALS['__old_input'] = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);

/**
 * Connects to MySQL and applies pending migrations. Pages show a friendly
 * screen and the API returns JSON when the database is unavailable.
 */
function connect(array $config, bool $json = false): PDO {
    try {
        $db = database($config);
        run_migrations($db);
        return $db;
    } catch (PDOException $error) {
        error_log($error->getMessage());
        $message = str_contains($error->getMessage(), "doesn't exist") || str_contains($error->getMessage(), 'Unknown database')
            ? 'The cctv_media database or its tables are missing. Import database/schema.sql in phpMyAdmin.'
            : 'Cannot connect to MySQL. Start MySQL in the XAMPP Control Panel and check app/config.php.';
        if ($json) json_out(['message' => $message], 503);
        render_error_page('Database unavailable', $message, 503);
    }
}
