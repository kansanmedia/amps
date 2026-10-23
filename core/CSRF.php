<?php
// core/CSRF.php - Cross-Site Request Forgery Protection

declare(strict_types=1);

class CSRF {
    public static function generateToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function getToken(): string {
        return self::generateToken();
    }

    public static function tokenField(): string {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function field(): string {
        return self::tokenField();
    }

    public static function validate(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function validateRequest(): void {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
        if (!self::validate($token)) {
            http_response_code(403);
            die("Security validation failed (Invalid CSRF Token). Please refresh the page and try again.");
        }
    }
}
