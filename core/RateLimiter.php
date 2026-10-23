<?php
// core/RateLimiter.php - IP & Account-Based Rate Limiting for Authentication

declare(strict_types=1);

class RateLimiter {
    private const MAX_ATTEMPTS = 5;       // 5 attempts max
    private const LOCKOUT_SECONDS = 900;  // 15 minutes lockout

    private static bool $tableChecked = false;

    private static function initTable(): void {
        if (self::$tableChecked) return;
        try {
            $db = DB::getConnection();
            $db->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ip_time (ip_address, attempted_at),
                    INDEX idx_user_time (username, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            self::$tableChecked = true;
        } catch (Exception $e) {
            error_log("RateLimiter table init warning: " . $e->getMessage());
        }
    }

    public static function isLockedOut(string $username, ?string $ip = null): bool {
        self::initTable();
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $cleanUser = trim(strtolower($username));

        try {
            $db = DB::getConnection();
            $windowStart = date('Y-m-d H:i:s', time() - self::LOCKOUT_SECONDS);

            $stmt = $db->prepare("
                SELECT COUNT(id) FROM login_attempts 
                WHERE (ip_address = :ip OR username = :uname) AND attempted_at >= :win
            ");
            $stmt->execute([
                ':ip'    => $ip,
                ':uname' => $cleanUser,
                ':win'   => $windowStart
            ]);
            $count = (int)$stmt->fetchColumn();

            return $count >= self::MAX_ATTEMPTS;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function recordFailedAttempt(string $username, ?string $ip = null): void {
        self::initTable();
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $cleanUser = trim(strtolower($username));

        try {
            $db = DB::getConnection();
            $stmt = $db->prepare("
                INSERT INTO login_attempts (ip_address, username, attempted_at)
                VALUES (:ip, :uname, NOW())
            ");
            $stmt->execute([
                ':ip'    => $ip,
                ':uname' => $cleanUser
            ]);
        } catch (Exception $e) {
            error_log("RateLimiter record error: " . $e->getMessage());
        }
    }

    public static function clearAttempts(string $username, ?string $ip = null): void {
        self::initTable();
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $cleanUser = trim(strtolower($username));

        try {
            $db = DB::getConnection();
            $stmt = $db->prepare("
                DELETE FROM login_attempts 
                WHERE ip_address = :ip OR username = :uname
            ");
            $stmt->execute([
                ':ip'    => $ip,
                ':uname' => $cleanUser
            ]);
        } catch (Exception $e) {
            error_log("RateLimiter clear error: " . $e->getMessage());
        }
    }

    public static function getRemainingLockoutMinutes(string $username, ?string $ip = null): int {
        self::initTable();
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $cleanUser = trim(strtolower($username));

        try {
            $db = DB::getConnection();
            $stmt = $db->prepare("
                SELECT attempted_at FROM login_attempts 
                WHERE (ip_address = :ip OR username = :uname)
                ORDER BY attempted_at DESC LIMIT 1
            ");
            $stmt->execute([':ip' => $ip, ':uname' => $cleanUser]);
            $lastAttempt = $stmt->fetchColumn();

            if ($lastAttempt) {
                $elapsed = time() - strtotime($lastAttempt);
                $remaining = self::LOCKOUT_SECONDS - $elapsed;
                return max(1, (int)ceil($remaining / 60));
            }
        } catch (Exception $e) {
            // ignore
        }
        return 15;
    }
}
