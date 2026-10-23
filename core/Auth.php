<?php
// core/Auth.php - Role-Based Authentication & Session Manager

declare(strict_types=1);

class Auth {
    /**
     * Authenticate user by username or email
     */
    public static function login(string $usernameOrEmail, string $password): bool {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT u.*, s.full_name as staff_name, s.staff_code, s.skill_category
            FROM users u
            LEFT JOIN staff s ON u.staff_id = s.id
            WHERE (u.username = :uname OR u.email = :uemail) AND u.status = 'active'
            LIMIT 1
        ");
        $cleanUe = trim($usernameOrEmail);
        $stmt->execute([':uname' => $cleanUe, ':uemail' => $cleanUe]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['staff_id'] = $user['staff_id'] ? (int)$user['staff_id'] : null;
            $_SESSION['email'] = $user['email'];

            // Update last login
            $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
            $upd->execute([':id' => $user['id']]);

            // Audit
            Audit::log('LOGIN', 'USERS', (int)$user['id'], null, ['username' => $user['username'], 'role' => $user['role']]);

            return true;
        }

        return false;
    }

    /**
     * Terminate session
     */
    public static function logout(): void {
        if (self::check()) {
            Audit::log('LOGOUT', 'USERS', self::id(), null, ['username' => self::user()['username'] ?? '']);
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
            'staff_id' => $_SESSION['staff_id'] ?? null,
            'email' => $_SESSION['email'] ?? '',
        ];
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): string {
        return $_SESSION['role'] ?? '';
    }

    public static function isOwnerAdmin(): bool {
        return self::role() === 'owner_admin';
    }

    public static function isManager(): bool {
        return in_array(self::role(), ['owner_admin', 'manager'], true);
    }

    public static function isAccountant(): bool {
        return in_array(self::role(), ['owner_admin', 'accountant'], true);
    }

    public static function isStaff(): bool {
        return self::role() === 'staff';
    }

    public static function hasRole(array|string $roles): bool {
        if (!self::check()) return false;
        if (self::isOwnerAdmin()) return true; // Super admin has access everywhere

        $roleList = is_array($roles) ? $roles : [$roles];
        return in_array(self::role(), $roleList, true);
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            Helper::setFlash('warning', 'Please sign in to access this page.');
            Helper::redirect('modules/auth/login.php');
        }
    }

    public static function requireRole(array|string $roles): void {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            Helper::setFlash('danger', 'Access denied. You do not have permission to view this resource.');
            Helper::redirect('modules/dashboard/index.php');
        }
    }

    // --- Checkpoint 9: Multi-Factor Authentication (MFA) Support ---

    public static function isMfaPending(): bool {
        return !empty($_SESSION['mfa_pending']) && !empty($_SESSION['mfa_user']);
    }

    public static function initiateMfa(array $user): string {
        $code = (string)random_int(100000, 999999);
        $_SESSION['mfa_pending'] = true;
        $_SESSION['mfa_user'] = $user;
        $_SESSION['mfa_code'] = $code;
        $_SESSION['mfa_expires'] = time() + 300; // 5 minutes
        return $code;
    }

    public static function verifyMfa(string $code): bool {
        if (!self::isMfaPending()) return false;
        if (time() > ($_SESSION['mfa_expires'] ?? 0)) {
            self::clearMfa();
            return false;
        }

        if (hash_equals((string)$_SESSION['mfa_code'], trim($code))) {
            $user = $_SESSION['mfa_user'];
            self::clearMfa();

            // Establish authenticated session
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['staff_id'] = $user['staff_id'] ? (int)$user['staff_id'] : null;
            $_SESSION['email'] = $user['email'];

            $db = DB::getConnection();
            $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
            $upd->execute([':id' => $user['id']]);

            Audit::log('MFA_LOGIN', 'USERS', (int)$user['id'], null, ['username' => $user['username'], 'role' => $user['role']]);
            return true;
        }
        return false;
    }

    public static function clearMfa(): void {
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_user'], $_SESSION['mfa_code'], $_SESSION['mfa_expires']);
    }
}
