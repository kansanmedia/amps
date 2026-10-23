<?php
// modules/dashboard/index.php - Executive Manpower & Financial Operations Command Center
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireLogin();

$user = Auth::user();

// If staff user, redirect to their mobile check-in & passbook portal
if (Auth::isStaff()) {
    Helper::redirect('modules/attendance/staff_self.php');
}

$db = DB::getConnection();
$today = date('Y-m-d');
$selectedDate = !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $today;
$prevDate = date('Y-m-d', strtotime('-1 day', strtotime($selectedDate)));
$nextDate = date('Y-m-d', strtotime('+1 day', strtotime($selectedDate)));
$isToday = ($selectedDate === $today);
$isFutureDate = (strtotime($selectedDate) > strtotime($today));
$monthStart = date('Y-m-01');

// 1. Deployments & Attendance on selected date
$todayDepStmt = $db->prepare("
    SELECT COUNT(d.id) as total_deployed,
           COALESCE(SUM(d.client_rate), 0) as expected_billing,
           COALESCE(SUM(d.staff_rate), 0) as expected_cost
    FROM deployments d
    WHERE d.deployment_date = :dt AND d.status != 'cancelled'
");
$todayDepStmt->execute([':dt' => $selectedDate]);
$todayDeploy = $todayDepStmt->fetch();

$todayAttStmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN status='present' THEN 1 END) as present_count,
        COUNT(CASE WHEN status='half_day' THEN 1 END) as half_day_count,
        COUNT(CASE WHEN status IN ('absent', 'leave') THEN 1 END) as absent_count,
        COALESCE(SUM(final_client_amount), 0) as actual_billing,
        COALESCE(SUM(final_staff_amount), 0) as actual_cost
    FROM attendance
    WHERE attendance_date = :dt
");
$todayAttStmt->execute([':dt' => $selectedDate]);
$todayAtt = $todayAttStmt->fetch();

// Attendance compliance %
$totalSlots = (int)$todayDeploy['total_deployed'];
$attended = (int)$todayAtt['present_count'] + ((int)$todayAtt['half_day_count'] * 0.5);
$attendancePct = $totalSlots > 0 ? round(($attended / $totalSlots) * 100, 1) : 0;

// 2. Month-to-Date Financial Metrics
$mtdRevStmt = $db->query("SELECT COALESCE(SUM(final_client_amount), 0) FROM attendance WHERE attendance_date >= '{$monthStart}' AND status IN ('present', 'half_day')");
$mtdRevenue = (float)$mtdRevStmt->fetchColumn();

$mtdCostStmt = $db->query("SELECT COALESCE(SUM(final_staff_amount), 0) FROM attendance WHERE attendance_date >= '{$monthStart}' AND status IN ('present', 'half_day')");
$mtdStaffCost = (float)$mtdCostStmt->fetchColumn();

$mtdExpStmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= '{$monthStart}'");
$mtdExpenses = (float)$mtdExpStmt->fetchColumn();

$mtdNetProfit = $mtdRevenue - $mtdStaffCost - $mtdExpenses;

// 3. Receivables, Payables, Liquid Capital
$recStmt = $db->query("SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE payment_status != 'paid' AND payment_status != 'cancelled'");
$totalReceivables = (float)$recStmt->fetchColumn();

$payStaffStmt = $db->query("
    SELECT 
        (SELECT COALESCE(SUM(final_staff_amount), 0) FROM attendance WHERE status IN ('present', 'half_day')) -
        (SELECT COALESCE(SUM(amount), 0) FROM staff_payments) as staff_payable
");
$totalStaffPayable = max(0, (float)$payStaffStmt->fetchColumn());

$liquidStmt = $db->query("SELECT COALESCE(SUM(current_balance), 0) FROM financial_accounts WHERE status = 'active'");
$totalLiquidity = (float)$liquidStmt->fetchColumn();

// 4. Active Running Shift Details
$currentTime = date('H:i:s');
$activeShiftStmt = $db->query("SELECT * FROM shifts WHERE status = 'active' ORDER BY start_time ASC");
$allShifts = $activeShiftStmt->fetchAll();
$currentRunningShift = null;
foreach ($allShifts as $sh) {
    if (!$sh['is_overnight']) {
        if ($currentTime >= $sh['start_time'] && $currentTime <= $sh['end_time']) {
            $currentRunningShift = $sh; break;
        }
    } else {
        if ($currentTime >= $sh['start_time'] || $currentTime <= $sh['end_time']) {
            $currentRunningShift = $sh; break;
        }
    }
}

// 5. Last 7 Days Trend Data for Chart.js
$trendDays = [];
$trendBilling = [];
$trendCost = [];
$trendExpense = [];

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trendDays[] = date('d M', strtotime($d));

    $dayStmt = $db->prepare("SELECT COALESCE(SUM(final_client_amount), 0) as rev, COALESCE(SUM(final_staff_amount), 0) as cost FROM attendance WHERE attendance_date = :dt AND status IN ('present', 'half_day')");
    $dayStmt->execute([':dt' => $d]);
    $dayRow = $dayStmt->fetch();

    $trendBilling[] = (float)$dayRow['rev'];
    $trendCost[] = (float)$dayRow['cost'];

    $dayExpStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = :dt");
    $dayExpStmt->execute([':dt' => $d]);
    $trendExpense[] = (float)$dayExpStmt->fetchColumn();
}

// 6. Running Sites & Deployments for selected date
$depStmt = $db->prepare("
    SELECT d.*, c.company_name, c.phone as client_phone,
           s.site_name, s.location_address as site_address, s.contact_person as site_contact, s.contact_phone as site_phone,
           sh.shift_name, sh.shift_code, sh.is_overnight, sh.start_time, sh.end_time,
           stf.full_name as staff_name, stf.staff_code, stf.phone as staff_phone, stf.skill_category,
           a.id as att_id, a.status as att_status, a.hours_worked, a.is_billed, a.is_settled
    FROM deployments d
    JOIN clients c ON d.client_id = c.id
    JOIN sites s ON d.site_id = s.id
    JOIN shifts sh ON d.shift_id = sh.id
    JOIN staff stf ON d.staff_id = stf.id
    LEFT JOIN attendance a ON a.deployment_id = d.id
    WHERE d.deployment_date = :dt AND d.status != 'cancelled'
    ORDER BY s.site_name ASC, sh.start_time ASC, d.id DESC
");
$depStmt->execute([':dt' => $selectedDate]);
$recentDeployments = $depStmt->fetchAll();

// Group deployments by Site
$siteGroups = [];
$totalWorkingStaffCount = 0;
$totalPresentCount = 0;
$totalHalfDayCount = 0;
$totalAbsentCount = 0;
$totalPendingCount = 0;

foreach ($recentDeployments as $rd) {
    $sid = (int)$rd['site_id'];
    if (!isset($siteGroups[$sid])) {
        $siteGroups[$sid] = [
            'site_id' => $sid,
            'client_id' => (int)$rd['client_id'],
            'company_name' => $rd['company_name'],
            'client_phone' => $rd['client_phone'],
            'site_name' => $rd['site_name'],
            'site_address' => $rd['site_address'],
            'site_contact' => $rd['site_contact'],
            'site_phone' => $rd['site_phone'],
            'shifts' => [],
            'staff' => [],
            'total_staff' => 0,
            'present_count' => 0,
            'half_day_count' => 0,
            'absent_count' => 0,
            'pending_count' => 0
        ];
    }

    $siteGroups[$sid]['staff'][] = $rd;
    $siteGroups[$sid]['total_staff']++;
    $totalWorkingStaffCount++;

    $shKey = $rd['shift_id'];
    if (!isset($siteGroups[$sid]['shifts'][$shKey])) {
        $siteGroups[$sid]['shifts'][$shKey] = [
            'shift_name' => $rd['shift_name'],
            'shift_code' => $rd['shift_code'],
            'is_overnight' => $rd['is_overnight'],
            'start_time' => $rd['start_time'],
            'end_time' => $rd['end_time']
        ];
    }

    $st = $rd['att_status'];
    if ($st === 'present') {
        $siteGroups[$sid]['present_count']++;
        $totalPresentCount++;
    } elseif ($st === 'half_day') {
        $siteGroups[$sid]['half_day_count']++;
        $totalHalfDayCount++;
    } elseif ($st === 'absent' || $st === 'leave') {
        $siteGroups[$sid]['absent_count']++;
        $totalAbsentCount++;
    } else {
        $siteGroups[$sid]['pending_count']++;
        $totalPendingCount++;
    }
}

$runningSitesCount = count($siteGroups);

// Dropdown lists for Quick Deploy Modal
$clientsList = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$sitesList = $db->query("SELECT id, client_id, site_name FROM sites WHERE status = 'active' ORDER BY site_name ASC")->fetchAll();
$shiftsList = $allShifts;
$staffList = $db->query("SELECT id, full_name, staff_code, skill_category, phone FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Executive Operations Command Center';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<!-- Header Greeting & Status -->
<!-- Header Greeting & Status -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 dash-header-wrap">
    <div>
        <h4 class="fw-bold mb-0.5 dash-welcome-title">Welcome back, <?= htmlspecialchars($user['full_name']) ?>! 👋</h4>
        <div class="text-muted small dash-welcome-sub d-flex align-items-center flex-wrap gap-1">
            <span>Operations for <strong><?= date('D, d M Y', strtotime($selectedDate)) ?></strong></span>
            <?= $isToday ? '<span class="badge bg-success-subtle text-success py-0.5 px-2" style="font-size: 0.65rem;"><i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> Today Live</span>' : '<span class="badge bg-secondary-subtle text-secondary py-0.5 px-2" style="font-size: 0.65rem;">' . Helper::formatDate($selectedDate) . '</span>' ?>
        </div>
    </div>
</div>

<!-- 4 PRIMARY QUICK ACTION HUBS (Single Line on Both Mobile & Desktop) -->
<div class="row g-1.5 g-md-3 mb-2.5 mb-md-3 quick-actions-row">
    <div class="col-3 col-md-3">
        <a href="<?= BASE_URL ?>/modules/mapping/deploy.php" class="card text-decoration-none border-0 shadow-sm p-1.5 p-md-3 h-100 bg-white transition-hover border-start border-3 border-md-4 border-primary quick-action-card">
            <div class="quick-card-inner gap-2 gap-md-3">
                <div class="rounded-2 bg-primary text-white flex-shrink-0 quick-icon-box">
                    <i class="bi bi-person-plus-fill"></i>
                </div>
                <div class="quick-card-text">
                    <div class="quick-label">1. Schedule</div>
                    <div class="quick-title text-primary">
                        <span class="d-title-desktop">+ Deploy Staff</span>
                        <span class="d-title-mobile">Deploy</span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-3 col-md-3">
        <a href="<?= BASE_URL ?>/modules/mapping/index.php" class="card text-decoration-none border-0 shadow-sm p-1.5 p-md-3 h-100 bg-white transition-hover border-start border-3 border-md-4 border-success quick-action-card">
            <div class="quick-card-inner gap-2 gap-md-3">
                <div class="rounded-2 bg-success text-white flex-shrink-0 quick-icon-box">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <div class="quick-card-text">
                    <div class="quick-label">2. Attendance</div>
                    <div class="quick-title text-success">
                        <span class="d-title-desktop">Daily Roster</span>
                        <span class="d-title-mobile">Roster</span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-3 col-md-3">
        <a href="<?= BASE_URL ?>/modules/billing/generate.php" class="card text-decoration-none border-0 shadow-sm p-1.5 p-md-3 h-100 bg-white transition-hover border-start border-3 border-md-4 border-info quick-action-card">
            <div class="quick-card-inner gap-2 gap-md-3">
                <div class="rounded-2 bg-info text-white flex-shrink-0 quick-icon-box">
                    <i class="bi bi-receipt"></i>
                </div>
                <div class="quick-card-text">
                    <div class="quick-label">3. Invoicing</div>
                    <div class="quick-title text-info">
                        <span class="d-title-desktop">+ Create Bill</span>
                        <span class="d-title-mobile">Invoice</span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-3 col-md-3">
        <a href="<?= BASE_URL ?>/modules/staff/index.php" class="card text-decoration-none border-0 shadow-sm p-1.5 p-md-3 h-100 bg-white transition-hover border-start border-3 border-md-4 border-warning quick-action-card">
            <div class="quick-card-inner gap-2 gap-md-3">
                <div class="rounded-2 bg-warning text-dark flex-shrink-0 quick-icon-box">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <div class="quick-card-text">
                    <div class="quick-label">4. Payroll</div>
                    <div class="quick-title text-dark">
                        <span class="d-title-desktop">Pay Wages</span>
                        <span class="d-title-mobile">Payroll</span>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Active Running Shift Live Banner -->
<div class="custom-card p-2.5 p-md-3 mb-2.5 mb-md-3 shadow-sm live-shift-banner" style="background: linear-gradient(135deg, #1e1b4b, #312e81) !important; color: #ffffff !important; border: none;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 gap-md-3">
        <div class="d-flex align-items-center gap-2.5 gap-md-3">
            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center flex-shrink-0 shift-icon-box" style="width: 38px; height: 38px;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <div class="text-white-50 text-uppercase fw-semibold shift-sub" style="font-size: 0.65rem; letter-spacing: 0.03em;">Current Live Shift</div>
                <?php if ($currentRunningShift): ?>
                    <h6 class="fw-bold mb-0 text-white shift-heading">
                        <?= htmlspecialchars($currentRunningShift['shift_name']) ?> <span class="fw-normal" style="font-size:0.85em;">(<?= date('h:i A', strtotime($currentRunningShift['start_time'])) ?> - <?= date('h:i A', strtotime($currentRunningShift['end_time'])) ?>)</span>
                        <?php if ($currentRunningShift['is_overnight']): ?>
                            <span class="badge bg-warning text-dark ms-1 shift-badge">Night</span>
                        <?php endif; ?>
                    </h6>
                <?php else: ?>
                    <h6 class="fw-bold mb-0 text-white shift-heading">Shift Transition Window (Next starting soon)</h6>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-between justify-content-md-end shift-stats-row mt-1 mt-md-0">
            <div class="shift-stat-box">
                <div class="shift-stat-lbl">Running Sites</div>
                <div class="shift-stat-val text-white"><?= $runningSitesCount ?> <span class="d-none d-sm-inline fw-normal" style="font-size:0.75rem;">Sites</span></div>
            </div>
            <div class="shift-stat-box">
                <div class="shift-stat-lbl">Total Workers</div>
                <div class="shift-stat-val text-white"><?= $totalWorkingStaffCount ?> <span class="d-none d-sm-inline fw-normal" style="font-size:0.75rem;">Staff</span></div>
            </div>
            <div class="shift-stat-box">
                <div class="shift-stat-lbl">Turnout</div>
                <div class="shift-stat-val text-info"><?= $attendancePct ?>%</div>
            </div>
        </div>
    </div>
</div>

<!-- RUNNING SITES & WORKFORCE COMMAND CENTER -->
<div class="custom-card mb-3 mb-md-4 shadow-sm" id="siteOperationsGrid">
    <div class="custom-card-header bg-white py-2 py-md-3 border-bottom site-ops-header">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 w-100">
            <!-- Row 1: Title, Badges & Mobile View Switcher -->
            <div class="d-flex align-items-center justify-content-between w-100 w-md-auto flex-wrap gap-1.5">
                <div class="d-flex align-items-center gap-1.5 flex-wrap">
                    <span class="badge bg-danger p-1 rounded-circle animate-pulse"></span>
                    <h6 class="fw-bold mb-0 text-dark site-ops-title" style="font-size: 0.92rem;">Sites & Workforce</h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0.5 fw-bold site-ops-badge">
                        <?= $runningSitesCount ?> Sites
                    </span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5 fw-bold site-ops-badge">
                        <?= $totalWorkingStaffCount ?> Staff
                    </span>
                </div>

                <!-- View Switcher on Mobile (compact inline with title) -->
                <div class="btn-group btn-group-sm site-ops-mobile-view-switcher" role="group">
                    <button type="button" class="btn btn-xs btn-primary active btn-view-sites py-1 px-2" onclick="switchOpView('sites')" style="font-size: 0.72rem; display: inline-flex; align-items: center; white-space: nowrap; gap: 4px;">
                        <i class="bi bi-buildings"></i> <span>Sites</span>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-secondary btn-view-roster py-1 px-2" onclick="switchOpView('roster')" style="font-size: 0.72rem; display: inline-flex; align-items: center; white-space: nowrap; gap: 4px;">
                        <i class="bi bi-table"></i> <span>Table</span>
                    </button>
                </div>
            </div>

            <!-- Controls: Mobile Ultra-Compact Calendar Strip VS Desktop Full Bar -->
            <div class="site-ops-controls flex-shrink-0">
                <!-- Mobile Ultra-Compact Calendar Bar -->
                <div class="site-ops-mobile-controls align-items-center gap-1 bg-light p-1 rounded-2 border">
                    <a href="?date=<?= $prevDate ?>" class="btn btn-xs btn-white border px-2 py-1 shadow-2xs" title="Previous Day">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <div class="position-relative flex-grow-1 text-center">
                        <div class="btn btn-xs <?= $isToday ? 'btn-primary text-white fw-bold' : 'btn-white border fw-semibold' ?> w-100 py-1 px-2 d-flex align-items-center justify-content-center gap-1 shadow-2xs" style="font-size: 0.74rem;">
                            <i class="bi bi-calendar3"></i>
                            <span><?= $isToday ? 'Today, ' . date('d M', strtotime($selectedDate)) : date('D, d M Y', strtotime($selectedDate)) ?></span>
                            <i class="bi bi-caret-down-fill ms-0.5 opacity-75" style="font-size: 0.55rem;"></i>
                        </div>
                        <input type="date" class="position-absolute top-0 start-0 w-100 h-100 opacity-0" value="<?= $selectedDate ?>" onchange="location.href='?date=' + this.value" style="cursor: pointer;">
                    </div>
                    <a href="?date=<?= $nextDate ?>" class="btn btn-xs btn-white border px-2 py-1 shadow-2xs" title="Next Day">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                    <?php if (!$isToday): ?>
                        <a href="?date=<?= $today ?>" class="btn btn-xs btn-primary py-1 px-1.5 fw-semibold" style="font-size: 0.68rem;" title="Jump to Today">Today</a>
                    <?php endif; ?>
                </div>

                <!-- Desktop Date Navigator Bar -->
                <div class="site-ops-desktop-controls align-items-center gap-2 flex-shrink-0">
                    <div class="d-flex align-items-center gap-1 border rounded-2 p-1 bg-light date-pill-nav shadow-2xs">
                        <a href="?date=<?= $prevDate ?>" class="btn btn-xs btn-outline-secondary px-2 py-1 bg-white text-dark shadow-2xs" title="Previous Day" style="font-size: 0.74rem; height: 30px; display: inline-flex; align-items: center; justify-content: center;">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <a href="?date=<?= $today ?>" class="btn btn-xs <?= $isToday ? 'btn-primary text-white fw-bold' : 'btn-outline-primary bg-white' ?> px-2.5 py-1 shadow-2xs" style="font-size: 0.74rem; height: 30px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                            <i class="bi bi-calendar-check me-1"></i> Today
                        </a>
                        <a href="?date=<?= $nextDate ?>" class="btn btn-xs btn-outline-secondary px-2 py-1 bg-white text-dark shadow-2xs" title="Next Day" style="font-size: 0.74rem; height: 30px; display: inline-flex; align-items: center; justify-content: center;">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <input type="date" class="form-control form-control-sm py-0 px-2 bg-white border fw-semibold text-dark shadow-2xs" value="<?= $selectedDate ?>" onchange="location.href='?date=' + this.value" style="width: 154px; min-width: 154px; font-size: 0.78rem; height: 30px; border-radius: 6px; cursor: pointer;">
                    </div>

                    <!-- Desktop View Switcher -->
                    <div class="btn-group btn-group-sm shadow-2xs flex-shrink-0" role="group">
                        <button type="button" class="btn btn-xs btn-primary active btn-view-sites fw-semibold px-2.5" id="btnViewSites" onclick="switchOpView('sites')" style="font-size: 0.75rem; height: 30px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; gap: 5px;">
                            <i class="bi bi-buildings"></i> <span>Sites (<?= $runningSitesCount ?>)</span>
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-secondary btn-view-roster bg-white fw-semibold px-2.5" id="btnViewRoster" onclick="switchOpView('roster')" style="font-size: 0.75rem; height: 30px; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; gap: 5px;">
                            <i class="bi bi-table"></i> <span>Table</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW 1: RUNNING SITES CARDS GRID (PRIMARY DEFAULT VIEW) -->
    <div id="viewRunningSites" class="p-2 p-md-3 bg-light-subtle">
        <?php if ($runningSitesCount === 0): ?>
            <div class="text-center py-3.5 py-md-5 bg-white rounded-3 border">
                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-muted mb-2 mb-md-3" style="width: 50px; height: 50px;">
                    <i class="bi bi-buildings fs-3 text-secondary"></i>
                </div>
                <h6 class="fw-bold text-dark mb-1" style="font-size: 0.88rem;">No Active Running Sites for <?= Helper::formatDate($selectedDate) ?></h6>
                <p class="text-muted small mb-2.5" style="font-size: 0.76rem;">No staff members have been scheduled for duty on this date yet.</p>
                <a href="<?= BASE_URL ?>/modules/mapping/deploy.php?date=<?= $selectedDate ?>" class="btn btn-primary btn-sm px-3.5 py-1.5 shadow-2xs fw-semibold" style="font-size: 0.78rem;">
                    <i class="bi bi-file-earmark-spreadsheet-fill me-1"></i> Deploy Staff on Excel Sheet
                </a>
            </div>
        <?php else: ?>
            <div class="site-command-grid-6">
                <?php foreach ($siteGroups as $site): 
                    $pPct = $site['total_staff'] > 0 ? round(($site['present_count'] / $site['total_staff']) * 100) : 0;
                    $hPct = $site['total_staff'] > 0 ? round(($site['half_day_count'] / $site['total_staff']) * 100) : 0;
                    $aPct = $site['total_staff'] > 0 ? round(($site['absent_count'] / $site['total_staff']) * 100) : 0;
                ?>
                    <div class="site-command-card site-card-compact" id="site-card-box-<?= $site['site_id'] ?>">
                        <!-- Card Header (Tappable) -->
                        <div class="site-command-header" onclick="toggleSiteDrawer(<?= $site['site_id'] ?>)">
                            <div class="d-flex align-items-start justify-content-between gap-1 mb-1.5">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-truncate fw-bold text-uppercase" 
                                      style="font-size: 0.65rem; max-width: 130px;" 
                                      title="<?= htmlspecialchars($site['company_name']) ?>">
                                    <i class="bi bi-building me-0.5"></i><?= htmlspecialchars($site['company_name']) ?>
                                </span>
                                <span class="badge bg-primary text-white rounded-pill px-2 py-0.5 fw-bold shadow-sm flex-shrink-0" style="font-size: 0.72rem;">
                                    <i class="bi bi-people-fill me-0.5"></i><?= $site['total_staff'] ?>
                                </span>
                            </div>

                            <h6 class="fw-bold text-dark mb-1 text-truncate" title="<?= htmlspecialchars($site['site_name']) ?>" style="font-size: 0.88rem;">
                                <?= htmlspecialchars($site['site_name']) ?>
                            </h6>

                            <?php if (!empty($site['site_address'])): ?>
                                <div class="text-muted text-truncate mb-1" style="font-size: 0.68rem;" title="<?= htmlspecialchars($site['site_address']) ?>">
                                    <i class="bi bi-geo-alt-fill text-danger me-0.5"></i><?= htmlspecialchars($site['site_address']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($site['site_contact'])): ?>
                                <div class="text-secondary text-truncate mb-1" style="font-size: 0.68rem;" title="Contact: <?= htmlspecialchars($site['site_contact']) ?><?php if (!empty($site['site_phone'])) echo ' (' . htmlspecialchars($site['site_phone']) . ')'; ?>">
                                    <i class="bi bi-person-badge text-primary me-0.5"></i><?= htmlspecialchars($site['site_contact']) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Shifts & Status Breakdown Pills -->
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 pt-1.5 mt-1 border-top" style="font-size: 0.68rem;">
                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                    <?php foreach ($site['shifts'] as $sh): ?>
                                        <?= Helper::shiftBadge($sh['shift_name'], $sh['shift_code'], (bool)$sh['is_overnight']) ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="fw-semibold text-dark text-nowrap" id="site-stat-label-<?= $site['site_id'] ?>">
                                    <span class="text-success"><i class="bi bi-check-circle-fill"></i> <?= $site['present_count'] ?>P</span>
                                    <?php if ($site['half_day_count'] > 0): ?>
                                        <span class="text-warning">&bull; <?= $site['half_day_count'] ?>H</span>
                                    <?php endif; ?>
                                    <?php if ($site['absent_count'] > 0): ?>
                                        <span class="text-danger">&bull; <?= $site['absent_count'] ?>A</span>
                                    <?php endif; ?>
                                    <?php if ($site['pending_count'] > 0): ?>
                                        <span class="text-secondary">&bull; <?= $site['pending_count'] ?> Due</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Attendance Progress Bar -->
                            <div class="progress mt-1.5" style="height: 5px;" id="site-progress-<?= $site['site_id'] ?>">
                                <div class="progress-bar bg-success" style="width: <?= $pPct ?>%" title="<?= $site['present_count'] ?> Present"></div>
                                <div class="progress-bar bg-warning" style="width: <?= $hPct ?>%" title="<?= $site['half_day_count'] ?> Half Day"></div>
                                <div class="progress-bar bg-danger" style="width: <?= $aPct ?>%" title="<?= $site['absent_count'] ?> Absent"></div>
                            </div>
                        </div>

                        <!-- Card Action Bar -->
                        <div class="p-2 bg-light border-top d-flex align-items-center justify-content-between gap-1 mt-auto">
                            <button type="button" class="btn btn-sm btn-outline-primary fw-semibold py-1 px-2 flex-grow-1 text-truncate" 
                                    style="font-size: 0.74rem;" 
                                    onclick="toggleSiteDrawer(<?= $site['site_id'] ?>)">
                                <i class="bi bi-chevron-down me-0.5" id="chevron-site-<?= $site['site_id'] ?>"></i> 
                                <span id="label-site-<?= $site['site_id'] ?>">Staff (<?= $site['total_staff'] ?>)</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-success fw-bold py-1 px-2 flex-shrink-0 shadow-sm" 
                                    style="font-size: 0.74rem;" 
                                    onclick="bulkMarkSitePresent(<?= $site['site_id'] ?>, '<?= $selectedDate ?>', this)" 
                                    title="Mark all workers at this venue Present in 1 tap">
                                <i class="bi bi-check-all me-0.5"></i> Present
                            </button>
                        </div>

                        <!-- In-Site Drill-Down: Staff Attendance Drawer -->
                        <div class="site-staff-list p-2" id="site-drawer-<?= $site['site_id'] ?>" style="display: none;">
                            <div class="d-flex flex-column gap-1.5">
                                <?php foreach ($site['staff'] as $stf): 
                                    $cleanPh = preg_replace('/\D/', '', $stf['staff_phone']);
                                    $waMsg = "Namaste {$stf['staff_name']}, Aapki duty scheduled hai:\n📍 Site: {$stf['site_name']} ({$stf['company_name']})\n⏰ Shift: {$stf['shift_name']}";
                                ?>
                                    <div class="staff-touch-item p-2" id="site-stf-row-<?= $stf['id'] ?>">
                                        <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                                            <div class="d-flex align-items-center gap-1.5 text-truncate">
                                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 26px; height: 26px; font-size: 0.72rem;">
                                                    <?= strtoupper(substr($stf['staff_name'], 0, 1)) ?>
                                                </div>
                                                <div class="text-truncate">
                                                    <strong class="text-dark d-block text-truncate" style="font-size: 0.78rem;" title="<?= htmlspecialchars($stf['staff_name']) ?>">
                                                        <?= htmlspecialchars($stf['staff_name']) ?>
                                                    </strong>
                                                    <small class="text-muted d-block text-truncate" style="font-size: 0.65rem;">
                                                        <?= htmlspecialchars($stf['skill_category']) ?>
                                                    </small>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-1 flex-shrink-0">
                                                <?php if (!empty($cleanPh)): ?>
                                                    <a href="tel:<?= $cleanPh ?>" class="btn btn-xs btn-outline-secondary p-0.5 px-1" title="Call">
                                                        <i class="bi bi-telephone-fill text-primary" style="font-size: 0.68rem;"></i>
                                                    </a>
                                                    <a href="https://wa.me/91<?= $cleanPh ?>?text=<?= urlencode($waMsg) ?>" target="_blank" class="btn btn-xs btn-outline-success p-0.5 px-1" title="WhatsApp">
                                                        <i class="bi bi-whatsapp" style="font-size: 0.68rem;"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Touch Attendance Buttons P / H / A -->
                                        <div class="d-flex align-items-center justify-content-between pt-1 border-top">
                                            <div id="status-pill-<?= $stf['id'] ?>" style="font-size: 0.68rem;">
                                                <?= Helper::statusBadge($stf['att_status'] ?? 'scheduled') ?>
                                            </div>

                                            <?php if (!empty($stf['is_billed']) || !empty($stf['is_settled'])): ?>
                                                <span class="badge bg-secondary py-0.5 px-1 text-uppercase" style="font-size: 0.62rem;">
                                                    <i class="bi bi-lock-fill"></i> Lock
                                                </span>
                                            <?php elseif ($isFutureDate): ?>
                                                <span class="badge bg-light text-muted border py-0.5 px-1.5" style="font-size:0.68rem;" title="Future shift: Attendance can only be marked on the shift date"><i class="bi bi-clock me-1 text-primary"></i> Future</span>
                                            <?php else: ?>
                                                <div class="btn-group btn-group-sm btn-group-touch" role="group" id="btn-group-touch-<?= $stf['id'] ?>">
                                                    <button type="button" class="btn <?= ($stf['att_status'] ?? '') === 'present' ? 'btn-success text-white fw-bold' : 'btn-outline-success' ?> py-0.5 px-1.5" 
                                                            title="Mark Present" 
                                                            onclick="quickMarkAttendance(<?= (int)($stf['att_id'] ?? 0) ?>, <?= $stf['id'] ?>, 'present', <?= $site['site_id'] ?>)">
                                                        P
                                                    </button>
                                                    <button type="button" class="btn <?= ($stf['att_status'] ?? '') === 'half_day' ? 'btn-warning text-dark fw-bold' : 'btn-outline-warning' ?> py-0.5 px-1.5" 
                                                            title="Mark Half Day" 
                                                            onclick="quickMarkAttendance(<?= (int)($stf['att_id'] ?? 0) ?>, <?= $stf['id'] ?>, 'half_day', <?= $site['site_id'] ?>)">
                                                        H
                                                    </button>
                                                    <button type="button" class="btn <?= in_array(($stf['att_status'] ?? ''), ['absent', 'leave']) ? 'btn-danger text-white fw-bold' : 'btn-outline-danger' ?> py-0.5 px-1.5" 
                                                            title="Mark Absent" 
                                                            onclick="quickMarkAttendance(<?= (int)($stf['att_id'] ?? 0) ?>, <?= $stf['id'] ?>, 'absent', <?= $site['site_id'] ?>)">
                                                        A
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- VIEW 2: FULL FLAT STAFF ROSTER TABLE (ACCESSIBLE VIA VIEW TOGGLE) -->
    <div id="viewFullRoster" class="d-none">
        <div class="p-3 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="text-muted small">
                Showing all <strong><?= count($recentDeployments) ?></strong> deployments scheduled for <?= Helper::formatDate($selectedDate) ?>.
            </div>
            <input type="text" class="form-control form-control-sm" placeholder="Filter workers, sites, or clients..." data-table-search="#dashboardRosterTable" style="max-width: 260px;">
        </div>
        <div class="table-responsive">
            <table class="table table-custom align-middle mb-0" id="dashboardRosterTable">
                <thead class="table-light">
                    <tr>
                        <th>Shift</th>
                        <th>Client & Venue Site</th>
                        <th>Staff Member</th>
                        <th>Contact & WhatsApp</th>
                        <th>Rates (Client / Staff)</th>
                        <th>Status</th>
                        <th class="text-center" style="min-width: 140px;">1-Click Attendance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentDeployments)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-2 d-block mb-2 text-secondary"></i>
                                No staff scheduled for this date yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentDeployments as $rd): 
                            $cleanPhone = preg_replace('/\D/', '', $rd['staff_phone']);
                            $waText = "Namaste {$rd['staff_name']}, Aapki duty scheduled hai:\n📍 Site: {$rd['site_name']} ({$rd['company_name']})\n⏰ Shift: {$rd['shift_name']}";
                        ?>
                            <tr id="row-deploy-<?= $rd['id'] ?>">
                                <td>
                                    <?= Helper::shiftBadge($rd['shift_name'], $rd['shift_code'], (bool)$rd['is_overnight']) ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($rd['site_name']) ?></div>
                                    <small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($rd['company_name']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($rd['staff_name']) ?></div>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-light text-dark border" style="font-size:0.7rem;"><?= htmlspecialchars($rd['skill_category']) ?></span>
                                        <small class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($rd['staff_code']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <a href="tel:<?= $rd['staff_phone'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Call Worker">
                                            <i class="bi bi-telephone"></i> <?= htmlspecialchars($rd['staff_phone']) ?>
                                        </a>
                                        <?php if ($cleanPhone): ?>
                                            <a href="https://wa.me/91<?= $cleanPhone ?>?text=<?= urlencode($waText) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="Send WhatsApp Details">
                                                <i class="bi bi-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div><small class="text-muted">Bill:</small> <strong class="text-dark"><?= Helper::formatCurrency($rd['client_rate']) ?></strong></div>
                                    <div><small class="text-muted">Pay:</small> <strong class="text-primary"><?= Helper::formatCurrency($rd['staff_rate']) ?></strong></div>
                                </td>
                                <td id="status-badge-container-<?= $rd['id'] ?>">
                                    <?= Helper::statusBadge($rd['att_status'] ?? 'scheduled') ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($rd['is_billed']) || !empty($rd['is_settled'])): ?>
                                        <span class="badge bg-secondary small" title="Locked"><i class="bi bi-lock-fill"></i> Locked</span>
                                    <?php elseif ($isFutureDate): ?>
                                        <span class="badge bg-light text-muted border py-0.5 px-1.5" style="font-size:0.68rem;" title="Future shift: Attendance can only be marked on the shift date"><i class="bi bi-clock me-1 text-primary"></i> Future</span>
                                    <?php else: ?>
                                        <div class="btn-group btn-group-sm" role="group" id="btn-group-<?= $rd['id'] ?>">
                                            <button type="button" class="btn <?= ($rd['att_status'] ?? '') === 'present' ? 'btn-success text-white' : 'btn-outline-success' ?> py-1 px-2 fw-bold" 
                                                    title="Mark Full Day Present" 
                                                    onclick="quickMarkAttendance(<?= (int)($rd['att_id'] ?? 0) ?>, <?= $rd['id'] ?>, 'present', <?= (int)$rd['site_id'] ?>)">
                                                P
                                            </button>
                                            <button type="button" class="btn <?= ($rd['att_status'] ?? '') === 'half_day' ? 'btn-warning text-dark' : 'btn-outline-warning' ?> py-1 px-2 fw-bold" 
                                                    title="Mark Half Day" 
                                                    onclick="quickMarkAttendance(<?= (int)($rd['att_id'] ?? 0) ?>, <?= $rd['id'] ?>, 'half_day', <?= (int)$rd['site_id'] ?>)">
                                                H
                                            </button>
                                            <button type="button" class="btn <?= in_array(($rd['att_status'] ?? ''), ['absent', 'leave']) ? 'btn-danger text-white' : 'btn-outline-danger' ?> py-1 px-2 fw-bold" 
                                                    title="Mark Absent" 
                                                    onclick="quickMarkAttendance(<?= (int)($rd['att_id'] ?? 0) ?>, <?= $rd['id'] ?>, 'absent', <?= (int)$rd['site_id'] ?>)">
                                                A
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- CHARTS SECTION: 7-Day Performance & MTD Breakdown -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="custom-card p-4 h-100 shadow-sm">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-graph-up text-primary me-1"></i> 7-Day Business Trend (Client Billing vs Staff Wages vs Expenses)</h6>
                <span class="badge bg-light text-muted border">Daily Trend</span>
            </div>
            <div style="height: 260px; position: relative;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="custom-card p-4 h-100 shadow-sm">
            <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart text-primary me-1"></i> MTD Cash Outflow Distribution</h6>
            <div style="height: 190px; position: relative;">
                <canvas id="mtdBreakdownChart"></canvas>
            </div>
            <div class="mt-3 pt-2 border-top small">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Direct Staff Wages:</span>
                    <strong class="text-dark"><?= Helper::formatCurrency($mtdStaffCost) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Operational Expenses:</span>
                    <strong class="text-dark"><?= Helper::formatCurrency($mtdExpenses) ?></strong>
                </div>
                <div class="d-flex justify-content-between border-top pt-1">
                    <span class="fw-bold text-dark">Net Retained Profit:</span>
                    <strong class="<?= $mtdNetProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= Helper::formatCurrency($mtdNetProfit) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QUICK DEPLOY MODAL (Fast In-Place Scheduling) -->
<div class="modal fade" id="quickDeployModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="<?= BASE_URL ?>/modules/mapping/deploy.php">
                <?= CSRF::tokenField() ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-people-fill me-2"></i>Quick Bulk Deploy Staff</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase">Deployment Date</label>
                        <input type="date" name="deployment_date" id="modalDepDate" class="form-control" value="<?= $today ?>" required onchange="fetchModalBusyStaff()">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase">Client Company</label>
                        <select name="client_id" id="modalClientId" class="form-select" required onchange="filterModalSites(this.value)">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clientsList as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['company_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase">Venue / Site</label>
                        <select name="site_id" id="modalSiteId" class="form-select" required>
                            <option value="">-- Select Site --</option>
                            <?php foreach ($sitesList as $st): ?>
                                <option value="<?= $st['id'] ?>" data-client="<?= $st['client_id'] ?>"><?= htmlspecialchars($st['site_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase">Shift</label>
                        <select name="shift_id" id="modalShiftSelect" class="form-select" required onchange="fetchModalBusyStaff()">
                            <option value="">-- Select Shift --</option>
                            <?php foreach ($shiftsList as $sh): ?>
                                <option value="<?= $sh['id'] ?>">
                                    <?= htmlspecialchars($sh['shift_name']) ?> (<?= date('h:i A', strtotime($sh['start_time'])) ?> - <?= date('h:i A', strtotime($sh['end_time'])) ?>)
                                    <?= $sh['is_overnight'] ? ' [Night]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Selected Staff Bucket (At the Top) -->
                    <div class="mb-3 p-2.5 bg-light rounded border">
                        <div class="d-flex align-items-center justify-content-between mb-1.5">
                            <div class="d-flex align-items-center gap-1.5">
                                <span class="badge bg-secondary rounded-pill px-2 py-1" id="modalSelectedStaffCount">0 Selected</span>
                                <span class="small fw-semibold text-dark">Selected Staff for Duty</span>
                            </div>
                            <button type="button" class="btn btn-link text-danger p-0 small text-decoration-none d-none" id="modalClearAllBtn" onclick="clearAllModalSelected()">Clear</button>
                        </div>
                        <div id="modalHiddenInputs"></div>
                        <div id="modalSelectedChips" class="d-flex flex-wrap gap-1.5 align-items-center">
                            <div class="text-muted small fst-italic" id="modalEmptySelectedMsg">No staff selected. Choose workers below.</div>
                        </div>
                    </div>

                    <!-- Searchable Available Staff Picker (Below) -->
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label small fw-bold text-uppercase mb-0">Search Available Staff</label>
                            <small class="text-muted"><span id="modalAvailCount">0</span> available</small>
                        </div>
                        <div class="input-group input-group-sm mb-2 shadow-sm">
                            <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" id="modalStaffSearchInput" class="form-control form-control-sm" placeholder="Type name, skill or phone..." oninput="refreshModalStaffDisplay()">
                            <button type="button" class="btn btn-outline-secondary" onclick="clearModalStaffSearch()">Clear</button>
                        </div>
                        <div class="border rounded p-1.5 bg-white shadow-sm" id="modalStaffListContainer" style="max-height: 180px; overflow-y: auto;">
                            <?php foreach ($staffList as $stf): 
                                $mSearch = strtolower($stf['full_name'] . ' ' . $stf['skill_category'] . ' ' . $stf['staff_code'] . ' ' . $stf['phone']);
                            ?>
                                <div class="modal-staff-item p-1.5 mb-1 rounded border d-flex align-items-center justify-content-between transition-hover"
                                     id="modal-staff-card-<?= $stf['id'] ?>"
                                     data-id="<?= $stf['id'] ?>"
                                     data-name="<?= htmlspecialchars($stf['full_name']) ?>"
                                     data-category="<?= htmlspecialchars($stf['skill_category'] ?: 'General Staff') ?>"
                                     data-search="<?= htmlspecialchars($mSearch) ?>">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 26px; height: 26px; font-size: 0.7rem;">
                                            <?= strtoupper(substr($stf['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark small" style="font-size:0.8rem;"><?= htmlspecialchars($stf['full_name']) ?></div>
                                            <span class="badge bg-light text-dark border" style="font-size:0.65rem;"><?= htmlspecialchars($stf['skill_category'] ?: 'Staff') ?></span>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0.5 px-2" style="font-size: 0.75rem;" onclick="selectModalStaff(<?= $stf['id'] ?>)">
                                        + Select
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <div id="modalNoStaffFound" class="text-center py-2 text-muted small d-none">No available staff found.</div>
                            <div id="modalAllStaffBusyMsg" class="text-center py-2 text-muted small d-none">All staff are selected or already on duty.</div>
                        </div>
                        <small class="text-muted mt-1 d-block" style="font-size:0.72rem;">Rates and attendance records are automatically generated.</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="modalSubmitBtn" class="btn btn-primary btn-sm px-4" disabled>
                        <i class="bi bi-check-lg me-1"></i> Deploy Selected Staff (0)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allModalStaffData = <?= json_encode(array_map(function($s) {
    return [
        'id' => (int)$s['id'],
        'name' => $s['full_name'],
        'category' => $s['skill_category'] ?: 'General Staff',
        'code' => $s['staff_code'],
        'phone' => $s['phone'],
        'search' => strtolower($s['full_name'] . ' ' . $s['skill_category'] . ' ' . $s['staff_code'] . ' ' . $s['phone'])
    ];
}, $staffList)) ?>;

let modalSelectedStaffIds = new Set();
let modalBusyStaffIds = new Set();

function selectModalStaff(id) {
    id = parseInt(id);
    if (!id || modalBusyStaffIds.has(id)) return;
    modalSelectedStaffIds.add(id);
    renderModalSelectedChips();
    refreshModalStaffDisplay();
}

function unselectModalStaff(id) {
    id = parseInt(id);
    modalSelectedStaffIds.delete(id);
    renderModalSelectedChips();
    refreshModalStaffDisplay();
}

function clearAllModalSelected() {
    modalSelectedStaffIds.clear();
    renderModalSelectedChips();
    refreshModalStaffDisplay();
}

function renderModalSelectedChips() {
    const container = document.getElementById('modalSelectedChips');
    const hiddenContainer = document.getElementById('modalHiddenInputs');
    const badge = document.getElementById('modalSelectedStaffCount');
    const clearBtn = document.getElementById('modalClearAllBtn');
    const submitBtn = document.getElementById('modalSubmitBtn');

    hiddenContainer.innerHTML = '';

    if (modalSelectedStaffIds.size === 0) {
        container.innerHTML = `<div class="text-muted small fst-italic" id="modalEmptySelectedMsg">No staff selected. Choose workers below.</div>`;
        badge.textContent = '0 Selected';
        badge.className = 'badge bg-secondary rounded-pill px-2 py-1';
        if (clearBtn) clearBtn.classList.add('d-none');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Deploy Selected Staff (0)';
        }
        return;
    }

    badge.textContent = modalSelectedStaffIds.size + ' Selected';
    badge.className = 'badge bg-success rounded-pill px-2 py-1';
    if (clearBtn) clearBtn.classList.remove('d-none');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<i class="bi bi-check-lg me-1"></i> Deploy Selected Staff (${modalSelectedStaffIds.size})`;
    }

    let html = '';
    modalSelectedStaffIds.forEach(id => {
        const staff = allModalStaffData.find(s => s.id === id);
        if (!staff) return;

        hiddenContainer.innerHTML += `<input type="hidden" name="staff_ids[]" value="${id}">`;

        html += `
            <div class="d-inline-flex align-items-center gap-1.5 bg-white border border-primary-subtle shadow-sm rounded-pill px-2.5 py-1">
                <span class="small fw-bold text-dark" style="font-size:0.75rem;">${staff.name}</span>
                <span class="badge bg-primary-subtle text-primary" style="font-size:0.62rem;">${staff.category}</span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1 lh-1" onclick="unselectModalStaff(${id})" title="Remove">
                    <i class="bi bi-x-circle-fill" style="font-size:0.85rem;"></i>
                </button>
            </div>
        `;
    });
    container.innerHTML = html;
}

function refreshModalStaffDisplay() {
    const input = document.getElementById('modalStaffSearchInput');
    const query = input ? input.value.toLowerCase().trim() : '';

    const items = document.querySelectorAll('#modalStaffListContainer .modal-staff-item');
    let visibleCount = 0;
    let availableTotal = 0;

    items.forEach(item => {
        const id = parseInt(item.dataset.id);
        const meta = item.dataset.search || '';

        // Hide if selected
        if (modalSelectedStaffIds.has(id)) {
            item.classList.add('d-none');
            item.style.setProperty('display', 'none', 'important');
            return;
        }

        // Hide if already mapped
        if (modalBusyStaffIds.has(id)) {
            item.classList.add('d-none');
            item.style.setProperty('display', 'none', 'important');
            return;
        }

        availableTotal++;

        if (!query || meta.includes(query)) {
            item.classList.remove('d-none');
            item.style.removeProperty('display');
            visibleCount++;
        } else {
            item.classList.add('d-none');
            item.style.setProperty('display', 'none', 'important');
        }
    });

    const availSpan = document.getElementById('modalAvailCount');
    if (availSpan) availSpan.textContent = availableTotal;

    const noFound = document.getElementById('modalNoStaffFound');
    const allBusy = document.getElementById('modalAllStaffBusyMsg');
    if (noFound) {
        if (query && visibleCount === 0 && availableTotal > 0) noFound.classList.remove('d-none');
        else noFound.classList.add('d-none');
    }
    if (allBusy) {
        if (availableTotal === 0) allBusy.classList.remove('d-none');
        else allBusy.classList.add('d-none');
    }
}

function clearModalStaffSearch() {
    const input = document.getElementById('modalStaffSearchInput');
    if (input) {
        input.value = '';
        refreshModalStaffDisplay();
    }
}

function fetchModalBusyStaff() {
    const dateInput = document.getElementById('modalDepDate');
    const date = dateInput ? dateInput.value : '';
    const shiftSelect = document.getElementById('modalShiftSelect');
    const shiftId = shiftSelect ? shiftSelect.value : '';

    if (!date || !shiftId) {
        modalBusyStaffIds.clear();
        refreshModalStaffDisplay();
        return;
    }

    fetch(`<?= BASE_URL ?>/modules/mapping/ajax_get_rates.php?action=get_busy_staff&date=${date}&shift_id=${shiftId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && Array.isArray(data.busy_staff_ids)) {
                modalBusyStaffIds = new Set(data.busy_staff_ids);

                modalSelectedStaffIds.forEach(id => {
                    if (modalBusyStaffIds.has(id)) {
                        modalSelectedStaffIds.delete(id);
                    }
                });
                renderModalSelectedChips();
                refreshModalStaffDisplay();
            }
        })
        .catch(err => console.error(err));
}

// Filter sites dynamically based on client in modal
function filterModalSites(clientId) {
    const siteSelect = document.getElementById('modalSiteId');
    const options = siteSelect.querySelectorAll('option');
    options.forEach(opt => {
        if (!opt.value) return;
        if (!clientId || opt.getAttribute('data-client') === clientId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    siteSelect.value = '';
}

// Toggle in-site staff drawer (Mobile-friendly accordion)
function toggleSiteDrawer(siteId) {
    const drawer = document.getElementById('site-drawer-' + siteId);
    const chevron = document.getElementById('chevron-site-' + siteId);
    if (!drawer) return;

    if (drawer.style.display === 'none' || !drawer.style.display) {
        drawer.style.display = 'block';
        if (chevron) chevron.className = 'bi bi-chevron-up me-1';
    } else {
        drawer.style.display = 'none';
        if (chevron) chevron.className = 'bi bi-chevron-down me-1';
    }
}

// Switch between Site Cards view and Full Roster view
function switchOpView(view) {
    const sitesContainer = document.getElementById('viewRunningSites');
    const rosterContainer = document.getElementById('viewFullRoster');
    const sitesButtons = document.querySelectorAll('.btn-view-sites');
    const rosterButtons = document.querySelectorAll('.btn-view-roster');

    if (view === 'roster') {
        if (sitesContainer) sitesContainer.classList.add('d-none');
        if (rosterContainer) rosterContainer.classList.remove('d-none');
        sitesButtons.forEach(b => {
            b.classList.remove('btn-primary', 'active');
            b.classList.add('btn-outline-secondary', 'bg-white');
        });
        rosterButtons.forEach(b => {
            b.classList.remove('btn-outline-secondary', 'bg-white');
            b.classList.add('btn-primary', 'active');
        });
    } else {
        if (rosterContainer) rosterContainer.classList.add('d-none');
        if (sitesContainer) sitesContainer.classList.remove('d-none');
        rosterButtons.forEach(b => {
            b.classList.remove('btn-primary', 'active');
            b.classList.add('btn-outline-secondary', 'bg-white');
        });
        sitesButtons.forEach(b => {
            b.classList.remove('btn-outline-secondary', 'bg-white');
            b.classList.add('btn-primary', 'active');
        });
    }
}

// Bulk mark all staff at a site as Present
async function bulkMarkSitePresent(siteId, date, btn) {
    const isFuture = <?= $isFutureDate ? 'true' : 'false' ?>;
    if (isFuture) {
        showToast('Future date ke liye attendance mark nahi ki ja sakti.', 'warning');
        return;
    }
    const confirmed = await customConfirm({
        title: 'Mark All Staff Present',
        message: 'Are you sure you want to mark all scheduled staff at this venue as PRESENT for today?',
        confirmText: 'Mark All Present',
        btnClass: 'btn-success',
        icon: 'bi-check-circle-fill',
        iconBg: '#dcfce7',
        iconColor: '#16a34a'
    });
    if (!confirmed) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Marking...';

    const formData = new FormData();
    formData.append('action', 'mark_site_all_present');
    formData.append('site_id', siteId);
    formData.append('date', date);

    fetch('<?= BASE_URL ?>/modules/attendance/ajax_quick_mark.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = origHtml;

        if (data.success) {
            // Update all staff rows in this site drawer
            if (Array.isArray(data.marked)) {
                data.marked.forEach(item => {
                    const pill = document.getElementById('status-pill-' + item.deployment_id);
                    if (pill) pill.innerHTML = item.badge_html;

                    const rosterPill = document.getElementById('status-badge-container-' + item.deployment_id);
                    if (rosterPill) rosterPill.innerHTML = item.badge_html;

                    // Update touch buttons
                    const btnGroupTouch = document.getElementById('btn-group-touch-' + item.deployment_id);
                    if (btnGroupTouch) {
                        const btns = btnGroupTouch.querySelectorAll('button');
                        btns[0].className = 'btn btn-success text-white py-1 px-2 fw-bold';
                        btns[1].className = 'btn btn-outline-warning py-1 px-2 fw-bold';
                        btns[2].className = 'btn btn-outline-danger py-1 px-2 fw-bold';
                    }

                    const btnGroup = document.getElementById('btn-group-' + item.deployment_id);
                    if (btnGroup) {
                        const btns = btnGroup.querySelectorAll('button');
                        btns[0].className = 'btn btn-success text-white py-1 px-2 fw-bold';
                        btns[1].className = 'btn btn-outline-warning py-1 px-2 fw-bold';
                        btns[2].className = 'btn btn-outline-danger py-1 px-2 fw-bold';
                    }
                });
            }

            // Update site stats label and progress bar to 100% Present
            const statLabel = document.getElementById('site-stat-label-' + siteId);
            if (statLabel) {
                statLabel.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill"></i> ${data.count} Present</span> &bull; <span class="text-muted">100% Marked</span>`;
            }
            const progBar = document.getElementById('site-progress-' + siteId);
            if (progBar) {
                progBar.innerHTML = `<div class="progress-bar bg-success" style="width: 100%" title="${data.count} Present"></div>`;
            }

            // Ensure drawer is opened so user sees results
            const drawer = document.getElementById('site-drawer-' + siteId);
            if (drawer && drawer.style.display === 'none') {
                toggleSiteDrawer(siteId);
            }
            showToast(`${data.count} staff marked Present at this venue.`, 'success');
        } else {
            showToast('Could not update site attendance: ' + data.message, 'danger');
        }
    })
    .catch(err => {
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = origHtml;
        showToast('Network error while marking site attendance.', 'danger');
    });
}

// 1-Click Instant Attendance Toggle via AJAX
async function quickMarkAttendance(attendanceId, deploymentId, status, siteId) {
    const isFuture = <?= $isFutureDate ? 'true' : 'false' ?>;
    if (isFuture && (status === 'present' || status === 'half_day')) {
        showToast('Future date ke liye Present ya Half-Day mark nahi kiya ja sakta.', 'warning');
        return;
    }

    if (status === 'absent') {
        const ok = await window.customConfirm({
            title: 'Mark Staff Absent',
            message: 'Kya aap sach me is worker ko <strong>ABSENT</strong> mark karna chahte hain?',
            confirmText: 'Yes, Mark Absent',
            cancelText: 'Cancel',
            btnClass: 'btn-danger',
            icon: 'bi-exclamation-triangle-fill',
            iconBg: '#fee2e2',
            iconColor: '#dc2626'
        });
        if (!ok) return;
    }

    const pillTouch = document.getElementById('status-pill-' + deploymentId);
    const pillTable = document.getElementById('status-badge-container-' + deploymentId);
    const btnGroupTouch = document.getElementById('btn-group-touch-' + deploymentId);
    const btnGroup = document.getElementById('btn-group-' + deploymentId);

    // Visual feedback
    if (pillTouch) pillTouch.innerHTML = '<span class="spinner-border spinner-border-sm text-primary"></span>';
    if (pillTable) pillTable.innerHTML = '<span class="spinner-border spinner-border-sm text-primary"></span>';

    const formData = new FormData();
    formData.append('attendance_id', attendanceId);
    formData.append('deployment_id', deploymentId);
    formData.append('status', status);

    fetch('<?= BASE_URL ?>/modules/attendance/ajax_quick_mark.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (pillTouch) pillTouch.innerHTML = data.badge_html;
            if (pillTable) pillTable.innerHTML = data.badge_html;

            // Update touch buttons
            if (btnGroupTouch) {
                const btns = btnGroupTouch.querySelectorAll('button');
                btns[0].className = (status === 'present') ? 'btn btn-success text-white py-1 px-2 fw-bold' : 'btn btn-outline-success py-1 px-2 fw-bold';
                btns[1].className = (status === 'half_day') ? 'btn btn-warning text-dark py-1 px-2 fw-bold' : 'btn btn-outline-warning py-1 px-2 fw-bold';
                btns[2].className = (status === 'absent' || status === 'leave') ? 'btn btn-danger text-white py-1 px-2 fw-bold' : 'btn btn-outline-danger py-1 px-2 fw-bold';
            }

            // Update table buttons
            if (btnGroup) {
                const btns = btnGroup.querySelectorAll('button');
                btns[0].className = (status === 'present') ? 'btn btn-success text-white py-1 px-2 fw-bold' : 'btn btn-outline-success py-1 px-2 fw-bold';
                btns[1].className = (status === 'half_day') ? 'btn btn-warning text-dark py-1 px-2 fw-bold' : 'btn btn-outline-warning py-1 px-2 fw-bold';
                btns[2].className = (status === 'absent' || status === 'leave') ? 'btn btn-danger text-white py-1 px-2 fw-bold' : 'btn btn-outline-danger py-1 px-2 fw-bold';
            }

            // Recompute site statistics if siteId was passed
            if (siteId) {
                recalculateSiteStats(siteId);
            }
        } else {
            showToast('Could not update status: ' + data.message, 'danger');
            setTimeout(() => location.reload(), 1200);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error while marking attendance.', 'danger');
        setTimeout(() => location.reload(), 1200);
    });
}

// Recomputes live counts & progress bar for a site after individual status update
function recalculateSiteStats(siteId) {
    const drawer = document.getElementById('site-drawer-' + siteId);
    if (!drawer) return;

    let pCount = 0;
    let hCount = 0;
    let aCount = 0;
    let total = 0;

    const rows = drawer.querySelectorAll('.staff-touch-item');
    rows.forEach(row => {
        total++;
        const pBtn = row.querySelector('.btn-group-touch button:nth-child(1)');
        const hBtn = row.querySelector('.btn-group-touch button:nth-child(2)');
        const aBtn = row.querySelector('.btn-group-touch button:nth-child(3)');

        if (pBtn && pBtn.classList.contains('btn-success')) pCount++;
        else if (hBtn && hBtn.classList.contains('btn-warning')) hCount++;
        else if (aBtn && aBtn.classList.contains('btn-danger')) aCount++;
    });

    const pending = total - (pCount + hCount + aCount);
    const statLabel = document.getElementById('site-stat-label-' + siteId);
    if (statLabel) {
        let txt = `<span class="text-success"><i class="bi bi-check-circle-fill"></i> ${pCount} Present</span>`;
        if (hCount > 0) txt += ` &bull; <span class="text-warning"><i class="bi bi-pie-chart-fill"></i> ${hCount} Half</span>`;
        if (aCount > 0) txt += ` &bull; <span class="text-danger"><i class="bi bi-x-circle-fill"></i> ${aCount} Absent</span>`;
        if (pending > 0) txt += ` &bull; <span class="text-secondary"><i class="bi bi-clock-history"></i> ${pending} Pending</span>`;
        statLabel.innerHTML = txt;
    }

    const progBar = document.getElementById('site-progress-' + siteId);
    if (progBar && total > 0) {
        const pPct = Math.round((pCount / total) * 100);
        const hPct = Math.round((hCount / total) * 100);
        const aPct = Math.round((aCount / total) * 100);
        progBar.innerHTML = `
            <div class="progress-bar bg-success" style="width: ${pPct}%" title="${pCount} Present"></div>
            <div class="progress-bar bg-warning" style="width: ${hPct}%" title="${hCount} Half Day"></div>
            <div class="progress-bar bg-danger" style="width: ${aPct}%" title="${aCount} Absent"></div>
        `;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const qModal = document.getElementById('quickDeployModal');
    if (qModal) {
        qModal.addEventListener('show.bs.modal', () => {
            fetchModalBusyStaff();
            refreshModalStaffDisplay();
        });
    }
    refreshModalStaffDisplay();

    // 7-Day Performance Trend Chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendDays) ?>,
            datasets: [
                {
                    label: 'Client Revenue (₹)',
                    data: <?= json_encode($trendBilling) ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Staff Wages (₹)',
                    data: <?= json_encode($trendCost) ?>,
                    borderColor: '#64748b',
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    tension: 0.3
                },
                {
                    label: 'Expenses (₹)',
                    data: <?= json_encode($trendExpense) ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'transparent',
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: function(val) { return '₹' + val; } }
                }
            }
        }
    });

    // MTD Outflow Distribution Doughnut
    const mtdCtx = document.getElementById('mtdBreakdownChart').getContext('2d');
    new Chart(mtdCtx, {
        type: 'doughnut',
        data: {
            labels: ['Staff Wages', 'Operating Expenses', 'Net Profit'],
            datasets: [{
                data: [<?= $mtdStaffCost ?>, <?= $mtdExpenses ?>, <?= max(0, $mtdNetProfit) ?>],
                backgroundColor: ['#6366f1', '#f59e0b', '#10b981'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            cutout: '70%'
        }
    });
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
