<?php
// templates/navbar.php - Top Application Bar with Active Shift Indicator
$user = Auth::user();

// Determine current running shift based on current time
$currentTime = date('H:i:s');
$db = DB::getConnection();
$activeShiftStmt = $db->query("SELECT * FROM shifts WHERE status = 'active' ORDER BY start_time ASC");
$allShifts = $activeShiftStmt->fetchAll();

$currentRunningShift = null;
foreach ($allShifts as $sh) {
    if (!$sh['is_overnight']) {
        if ($currentTime >= $sh['start_time'] && $currentTime <= $sh['end_time']) {
            $currentRunningShift = $sh;
            break;
        }
    } else {
        // Overnight shift crosses midnight (e.g. 23:00 to 07:00)
        if ($currentTime >= $sh['start_time'] || $currentTime <= $sh['end_time']) {
            $currentRunningShift = $sh;
            break;
        }
    }
}
?>
<div class="app-main">
    <header class="app-topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle" type="button" title="Toggle Sidebar (Collapse / Expand)">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div class="d-none d-sm-block">
                <div class="fw-bold text-dark" style="font-size: 0.95rem;">
                    <i class="bi bi-calendar3 me-1 text-primary"></i> <?= date('l, d M Y') ?>
                </div>
            </div>
            <!-- Active Shift Pill -->
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <?php if ($currentRunningShift): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1.5 rounded-pill d-flex align-items-center gap-1.5 text-truncate" style="font-size: 0.74rem; max-width: 240px;">
                        <span class="spinner-grow spinner-grow-sm text-success flex-shrink-0" style="width: 7px; height: 7px;" role="status"></span>
                        <span class="text-truncate">
                            <strong class="d-none d-sm-inline">Now Running: </strong><?= htmlspecialchars($currentRunningShift['shift_name']) ?> <span class="d-none d-md-inline">(<?= date('h:i A', strtotime($currentRunningShift['start_time'])) ?> - <?= date('h:i A', strtotime($currentRunningShift['end_time'])) ?>)</span>
                        </span>
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-pill" style="font-size: 0.75rem;">
                        <i class="bi bi-pause-circle me-1"></i> <span class="d-none d-sm-inline">Shift </span>Transition
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <?php if (Auth::isManager()): ?>
                <a href="<?= BASE_URL ?>/modules/mapping/deploy.php" class="btn btn-sm btn-primary d-none d-md-inline-flex align-items-center gap-1">
                    <i class="bi bi-plus-circle"></i> Deploy Staff
                </a>
            <?php endif; ?>

            <!-- User Menu -->
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle border d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 26px; height: 26px; font-size: 0.75rem;">
                        <?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?>
                    </div>
                    <span class="fw-semibold text-dark d-none d-md-inline" style="font-size: 0.85rem;"><?= htmlspecialchars($user['full_name'] ?? 'User') ?></span>
                    <span class="badge bg-primary-subtle text-primary" style="font-size: 0.65rem;"><?= ucwords(str_replace('_', ' ', $user['role'] ?? '')) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li class="px-3 py-2 border-bottom">
                        <div class="fw-bold" style="font-size:0.85rem;"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
                        <small class="text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></small>
                    </li>
                    <?php if (Auth::isOwnerAdmin()): ?>
                    <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>/modules/settings/users.php"><i class="bi bi-people-gear me-2 text-primary"></i> Users & Role Permissions</a></li>
                    <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>/modules/settings/company.php"><i class="bi bi-gear me-2 text-secondary"></i> Company Settings</a></li>
                    <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>/modules/audit/index.php"><i class="bi bi-shield-check me-2 text-info"></i> Audit Trail</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item py-2 text-danger" href="<?= BASE_URL ?>/modules/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Sign Out</a></li>
                </ul>
            </div>
        </div>
    </header>

    <main class="page-container">
        <!-- Flash Alerts -->
        <?php if (Helper::hasFlash()): 
            $flash = Helper::getFlash(); ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
                <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : ($flash['type'] === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-info-circle-fill') ?> fs-5"></i>
                <div class="flex-grow-1"><?= htmlspecialchars($flash['message']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
