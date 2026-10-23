<?php
// config/database.php - Hardened Database Configuration & PDO Singleton

declare(strict_types=1);

// Detect production environment for amps.kansan.in
$isAmpsProduction = (isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'amps.kansan.in'))
    || (isset($_SERVER['SERVER_NAME']) && str_contains($_SERVER['SERVER_NAME'], 'amps.kansan.in'))
    || (defined('ROOT_PATH') && str_contains(ROOT_PATH, 'amps.kansan.in'));

if ($isAmpsProduction) {
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'u663133122_amps_db');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'u663133122_amps_user');
    if (!defined('DB_PASS')) define('DB_PASS', (getenv('DB_PASS') !== false && getenv('DB_PASS') !== '') ? getenv('DB_PASS') : 'AmpsKansan@2026Secure');
} else {
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'kansan_manpower');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

class DB {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Security Hardening: Enforce real native prepared statements (disable PDO emulation)
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Security Hardening: Log exact database error privately without exposing paths/creds to users
                error_log("Database connection failure: " . $e->getMessage());
                http_response_code(500);
                die("A secure database connection could not be established. Please contact the administrator.");
            }
        }
        return self::$instance;
    }
}
