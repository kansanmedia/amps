<?php
// modules/auth/login.php - Secure User Login
require_once dirname(__DIR__, 2) . '/config/config.php';

if (Auth::check()) {
    Helper::redirect('modules/dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Security Hardening: Checkpoint 7 - Rate Limiting & Brute-Force Lockout
    if (!empty($username) && RateLimiter::isLockedOut($username, $ip)) {
        $lockMinutes = RateLimiter::getRemainingLockoutMinutes($username, $ip);
        $error = "Security Lockout: Too many failed login attempts. For your security, this account/IP is locked. Please try again in {$lockMinutes} minute(s).";
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        if (Auth::login($username, $password)) {
            RateLimiter::clearAttempts($username, $ip);
            Helper::setFlash('success', 'Welcome back, ' . htmlspecialchars(Auth::user()['full_name']) . '!');
            Helper::redirect('modules/dashboard/index.php');
        } else {
            RateLimiter::recordFailedAttempt($username, $ip);
            $error = 'Invalid credentials or inactive account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            max-width: 440px;
            width: 100%;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            padding: 2rem 2rem 1.5rem;
            color: #ffffff;
            text-align: center;
        }
        .role-pill-btn {
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .role-pill-btn:hover {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <?php
        $loginCompName = Helper::getCompanyName();
        $loginCompTagline = Helper::getCompanyTagline();
        $loginCompInitials = Helper::getCompanyInitials();
        $loginCompLogo = Helper::getCompanyLogo();
        ?>
        <?php if ($loginCompLogo): ?>
            <img src="<?= $loginCompLogo ?>" alt="<?= htmlspecialchars($loginCompName) ?>" class="mb-2" style="max-height: 52px; max-width: 140px; object-fit: contain; background: #fff; padding: 4px; border-radius: 8px;">
        <?php else: ?>
            <div class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-3 mb-2" style="width: 52px; height: 52px; font-size: 1.35rem; font-weight: 800; letter-spacing: -0.5px;">
                <?= htmlspecialchars($loginCompInitials) ?>
            </div>
        <?php endif; ?>
        <h4 class="fw-bold mb-1"><?= htmlspecialchars($loginCompName) ?></h4>
        <p class="text-white-50 small mb-0"><?= htmlspecialchars($loginCompTagline) ?></p>
    </div>

    <div class="p-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 small py-2">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (Helper::hasFlash()): 
            $flash = Helper::getFlash(); ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> small py-2">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= CSRF::tokenField() ?>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" id="username" class="form-control border-start-0" placeholder="admin" required autofocus>
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label small fw-semibold text-secondary">Password</label>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control border-start-0" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3">
                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Dashboard
            </button>
        </form>

        <!-- Quick Role Switch (Enabled only in Development / Test Mode) -->
        <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
        <div class="pt-3 border-top">
            <div class="alert alert-warning py-1.5 px-2 small mb-2 text-center" style="font-size:0.72rem;">
                <i class="bi bi-shield-exclamation me-1"></i> <strong>Dev Environment Notice:</strong> Change default credentials before production deploy.
            </div>
            <div class="text-muted small fw-semibold mb-2 text-center">Quick Role Switch (Demo Credentials):</div>
            <div class="d-flex flex-wrap gap-1.5 justify-content-center">
                <button type="button" class="role-pill-btn" onclick="fillCreds('admin', 'admin123')">
                    <strong>Admin</strong> (admin)
                </button>
                <button type="button" class="role-pill-btn" onclick="fillCreds('manager', 'manager123')">
                    <strong>Manager</strong> (manager)
                </button>
                <button type="button" class="role-pill-btn" onclick="fillCreds('accountant', 'accountant123')">
                    <strong>Accountant</strong> (accountant)
                </button>
                <button type="button" class="role-pill-btn" onclick="fillCreds('staff1', 'staff123')">
                    <strong>Staff</strong> (staff1)
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function fillCreds(user, pass) {
    document.getElementById('username').value = user;
    document.getElementById('password').value = pass;
}
</script>
</body>
</html>
