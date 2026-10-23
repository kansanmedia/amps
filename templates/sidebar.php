<?php
// templates/sidebar.php - Streamlined, Intuitive Role-Aware Navigation Hub
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$user = Auth::user();
$role = $user['role'] ?? 'staff';

function navActive(string $path, string $currentUri): string {
    return (str_contains($currentUri, $path)) ? 'active' : '';
}
$sidebarCompanyName = Helper::getCompanyName();
$sidebarCompanyTagline = Helper::getCompanyTagline();
$sidebarCompanyInitials = Helper::getCompanyInitials();
$sidebarCompanyLogo = Helper::getCompanyLogo();
?>
<aside class="app-sidebar">
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="sidebar-brand">
        <?php if ($sidebarCompanyLogo): ?>
            <img src="<?= $sidebarCompanyLogo ?>" alt="<?= htmlspecialchars($sidebarCompanyName) ?>" style="width: 36px; height: 36px; min-width: 36px; object-fit: contain; border-radius: 10px; background: #fff; padding: 2px;">
        <?php else: ?>
            <div class="brand-badge"><?= htmlspecialchars($sidebarCompanyInitials) ?></div>
        <?php endif; ?>
        <div style="overflow: hidden; min-width: 0;">
            <div style="line-height:1.2; font-size:0.96rem; font-weight:700; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;" title="<?= htmlspecialchars($sidebarCompanyName) ?>">
                <?= htmlspecialchars($sidebarCompanyName) ?>
            </div>
            <div style="font-size:0.68rem; color:#94a3b8; font-weight:400; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;" title="<?= htmlspecialchars($sidebarCompanyTagline) ?>">
                <?= htmlspecialchars($sidebarCompanyTagline) ?>
            </div>
        </div>
    </a>

    <div class="sidebar-nav">
        <!-- 1. Executive Hub -->
        <div class="sidebar-heading">Main</div>
        <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="nav-link-custom <?= navActive('/dashboard/', $currentUri) ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <?php if (Auth::isManager()): ?>
        <!-- 2. Operations Hub (Roster + Attendance) -->
        <div class="sidebar-heading mt-3">Daily Operations</div>
        <a href="<?= BASE_URL ?>/modules/mapping/index.php" class="nav-link-custom <?= (navActive('/mapping/', $currentUri) || navActive('/attendance/index', $currentUri)) ? 'active' : '' ?>">
            <i class="bi bi-calendar-check-fill"></i>
            <span>Daily Operations</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/shifts/index.php" class="nav-link-custom <?= navActive('/shifts/', $currentUri) ?>">
            <i class="bi bi-clock-history"></i>
            <span>Shift Timings</span>
            <span class="badge bg-primary-subtle text-primary ms-auto" style="font-size:0.65rem;">Admin</span>
        </a>

        <!-- 3. Workforce & Client Master Hub -->
        <div class="sidebar-heading mt-3">Directory</div>
        <a href="<?= BASE_URL ?>/modules/staff/index.php" class="nav-link-custom <?= (navActive('/staff/', $currentUri) && !str_contains($currentUri, 'my_passbook')) ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i>
            <span>Staff Workforce</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/clients/index.php" class="nav-link-custom <?= (navActive('/clients/', $currentUri) || navActive('/sites/', $currentUri) || navActive('/rates/', $currentUri)) ? 'active' : '' ?>">
            <i class="bi bi-building"></i>
            <span>Clients & Rates</span>
        </a>
        <?php endif; ?>

        <?php if (Auth::isStaff()): ?>
        <div class="sidebar-heading mt-3">My Workspace</div>
        <a href="<?= BASE_URL ?>/modules/attendance/staff_self.php" class="nav-link-custom <?= navActive('/attendance/staff_self', $currentUri) ?>">
            <i class="bi bi-geo-alt-fill"></i>
            <span>My Shifts & Check-in</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/staff/my_passbook.php" class="nav-link-custom <?= navActive('/staff/my_passbook', $currentUri) ?>">
            <i class="bi bi-wallet2"></i>
            <span>My Earnings Passbook</span>
        </a>
        <?php endif; ?>

        <!-- 4. Money & Finance Hub -->
        <?php if (Auth::isAccountant()): ?>
        <div class="sidebar-heading mt-3">Finance & Accounts</div>
        <a href="<?= BASE_URL ?>/modules/billing/index.php" class="nav-link-custom <?= navActive('/billing/', $currentUri) ?>">
            <i class="bi bi-receipt-cutoff"></i>
            <span>Client Invoices</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="nav-link-custom <?= navActive('/payments/', $currentUri) ?>">
            <i class="bi bi-wallet2"></i>
            <span>Client Payments</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="nav-link-custom <?= navActive('/expenses/', $currentUri) ?>">
            <i class="bi bi-journal-minus"></i>
            <span>Expenses</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/pnl.php" class="nav-link-custom <?= (navActive('/reports/', $currentUri) || navActive('/accounting/', $currentUri)) ? 'active' : '' ?>">
            <i class="bi bi-graph-up-arrow text-success"></i>
            <span>Profit & Loss</span>
        </a>
        <?php endif; ?>

        <!-- 5. System Administration -->
        <?php if (Auth::isOwnerAdmin()): ?>
        <div class="sidebar-heading mt-3">System Control</div>
        <a href="<?= BASE_URL ?>/modules/settings/company.php" class="nav-link-custom <?= (navActive('/settings/', $currentUri) || navActive('/audit/', $currentUri) || navActive('/notifications/', $currentUri)) ? 'active' : '' ?>">
            <i class="bi bi-gear-fill"></i>
            <span>Company Settings</span>
        </a>
        <?php endif; ?>
    </div>

    <!-- User Profile Footer in Sidebar -->
    <div style="padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2);">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.85rem; flex-shrink: 0;">
                    <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="overflow-hidden">
                    <div class="text-white text-truncate fw-semibold" style="font-size: 0.85rem;"><?= htmlspecialchars($user['full_name'] ?? 'Guest') ?></div>
                    <div class="text-muted text-truncate" style="font-size: 0.72rem;"><?= ucwords(str_replace('_', ' ', $role)) ?></div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="text-secondary hover-text-white" title="Sign Out">
                <i class="bi bi-box-arrow-right fs-5"></i>
            </a>
        </div>
    </div>
</aside>
