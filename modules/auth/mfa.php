<?php
// modules/auth/mfa.php - Two-Factor Authentication Verification Point
require_once dirname(__DIR__, 2) . '/config/config.php';

if (Auth::check()) {
    Helper::redirect('modules/dashboard/index.php');
}

if (!Auth::isMfaPending()) {
    Helper::redirect('modules/auth/login.php');
}

$error = '';
$user = $_SESSION['mfa_user'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $code = trim($_POST['mfa_code'] ?? '');

    if (empty($code)) {
        $error = 'Please enter the 6-digit verification code.';
    } elseif (Auth::verifyMfa($code)) {
        Helper::setFlash('success', 'Two-factor verification successful. Welcome back!');
        Helper::redirect('modules/dashboard/index.php');
    } else {
        $error = 'Invalid or expired verification code. Please check and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication | <?= APP_NAME ?></title>
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
        .mfa-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        .mfa-header {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            padding: 2rem 2rem 1.5rem;
            color: #ffffff;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="mfa-card">
    <div class="mfa-header">
        <div class="d-inline-flex align-items-center justify-content-center bg-white text-info rounded-circle mb-2" style="width: 52px; height: 52px; font-size: 1.6rem;">
            <i class="bi bi-shield-lock-fill text-primary"></i>
        </div>
        <h5 class="fw-bold mb-1">Two-Factor Authentication</h5>
        <p class="text-white-50 small mb-0">Enter the 6-digit security code for <?= htmlspecialchars($user['username'] ?? 'User') ?></p>
    </div>

    <div class="p-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger small py-2 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= CSRF::tokenField() ?>

            <div class="mb-3 text-center">
                <label class="form-label small fw-semibold text-secondary">6-Digit Verification Code</label>
                <input type="text" name="mfa_code" id="mfa_code" maxlength="6" pattern="[0-9]{6}" class="form-control text-center fs-4 fw-bold letter-spacing-2" placeholder="000000" autofocus required>
                <div class="form-text text-muted small mt-1">Code expires in 5 minutes.</div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-2">
                <i class="bi bi-check2-circle me-1"></i> Verify & Sign In
            </button>

            <div class="text-center">
                <a href="<?= BASE_URL ?>/modules/auth/login.php" class="text-decoration-none small text-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Cancel & Return to Login
                </a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
