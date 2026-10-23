<?php
// config/config.php - Central Application Configuration

declare(strict_types=1);

// Set default timezone to Indian Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

// App Environment
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Security Hardening: Send standard HTTP Security Headers
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(self), camera=(), microphone=()");
}

// Start secure session if not active with SameSite & Strict Mode
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

define('ROOT_PATH', dirname(__DIR__));

// Load .env file if present
$envFile = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            if (!array_key_exists($k, $_SERVER) && !array_key_exists($k, $_ENV)) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}

// Load config.local.php override if present
if (file_exists(ROOT_PATH . '/config.local.php')) {
    require_once ROOT_PATH . '/config.local.php';
}

// App Constants
define('APP_NAME', 'Kansan Manpower Management');
define('APP_VERSION', '1.0.0');

// Base URL calculation
if (!defined('BASE_URL')) {
    if (getenv('BASE_URL')) {
        define('BASE_URL', rtrim(getenv('BASE_URL'), '/'));
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $baseDir = rtrim($scriptName, '/');
        
        $projectFolder = 'Kansan Man Power Management';
        $pos = strpos($baseDir, $projectFolder);
        if ($pos !== false) {
            $basePath = substr($baseDir, 0, $pos + strlen($projectFolder));
        } else {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: ''), '/') : '';
            $appRoot = rtrim(str_replace('\\', '/', realpath(ROOT_PATH) ?: ''), '/');
            if (!empty($docRoot) && !empty($appRoot) && str_starts_with($appRoot, $docRoot)) {
                $sub = substr($appRoot, strlen($docRoot));
                $basePath = !empty($sub) ? '/' . trim($sub, '/') : '';
            } else {
                $basePath = '';
            }
        }
        define('BASE_URL', $protocol . $host . $basePath);
    }
}

// Upload paths
define('UPLOAD_DIR', ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);

// Include Core Dependencies
require_once __DIR__ . '/database.php';
require_once ROOT_PATH . '/core/Security.php';
require_once ROOT_PATH . '/core/RateLimiter.php';
require_once ROOT_PATH . '/core/Helper.php';
require_once ROOT_PATH . '/core/CSRF.php';
require_once ROOT_PATH . '/core/Auth.php';
require_once ROOT_PATH . '/core/Audit.php';
