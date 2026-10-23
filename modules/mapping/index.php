<?php
// modules/mapping/index.php - Authentic Full-Width Excel Daily Staff Mapping Roster (Client-Wise Shift Matrix)
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/DeploymentService.php';
require_once ROOT_PATH . '/services/AttendanceService.php';
Auth::requireRole(['owner_admin', 'manager']);

$db = DB::getConnection();
$today = date('Y-m-d');
$selectedDate = !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $today;
$selectedShiftId = !empty($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;
$prevDate = date('Y-m-d', strtotime('-1 day', strtotime($selectedDate)));
$nextDate = date('Y-m-d', strtotime('+1 day', strtotime($selectedDate)));
$isToday = ($selectedDate === $today);
$isPastDate = (strtotime($selectedDate) < strtotime($today));
$isFutureDate = (strtotime($selectedDate) > strtotime($today));

// 1. Fetch active shifts
$shifts = $db->query("SELECT id, shift_name, shift_code, start_time, end_time, is_overnight, duration_hours FROM shifts WHERE status = 'active' ORDER BY start_time ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch all active sites and clients
$allSites = $db->query("
    SELECT s.id as site_id, s.site_name, s.client_id, c.company_name, c.client_code
    FROM sites s
    JOIN clients c ON s.client_id = c.id
    WHERE s.status = 'active' AND c.status = 'active'
    ORDER BY c.company_name ASC, s.site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch deployments for selected date & shift filter
$sql = "
    SELECT d.id as deploy_id, d.deployment_date, d.shift_id, d.client_id, d.site_id, d.staff_id,
           d.client_rate, d.staff_rate, d.status as deploy_status,
           c.company_name, s.site_name,
           sh.shift_name, sh.shift_code, sh.start_time, sh.end_time, sh.is_overnight,
           stf.full_name as staff_name, stf.staff_code, stf.phone as staff_phone, stf.skill_category,
           stf.staff_group,
           sc.category_name,
           a.id as att_id, a.status as att_status, a.hours_worked
    FROM deployments d
    JOIN clients c ON d.client_id = c.id
    JOIN sites s ON d.site_id = s.id
    JOIN shifts sh ON d.shift_id = sh.id
    JOIN staff stf ON d.staff_id = stf.id
    LEFT JOIN staff_categories sc ON stf.category_id = sc.id
    LEFT JOIN attendance a ON a.deployment_id = d.id
    WHERE d.deployment_date = :dt AND d.status != 'cancelled'
      AND (a.status IS NULL OR a.status NOT IN ('absent', 'leave'))
";
$params = [':dt' => $selectedDate];
if ($selectedShiftId > 0) {
    $sql .= " AND d.shift_id = :shid";
    $params[':shid'] = $selectedShiftId;
}
$sql .= " ORDER BY c.company_name ASC, s.site_name ASC, sh.start_time ASC, stf.full_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$deployments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Pre-group deployments by staff_id for Multi-Shift calculation
$staffDeploymentsMap = [];
foreach ($deployments as $d) {
    $stfId = (int)$d['staff_id'];
    $staffDeploymentsMap[$stfId][] = $d;
}

// 5. Compute accurate status colors & badges
$nowTs = time();
$scheduledCount = 0;
$ongoingCount = 0;
$completedCount = 0;
$siteDeployments = [];
$deployedSiteIds = [];

foreach ($deployments as $dep) {
    $attSt = strtolower($dep['att_status'] ?? 'scheduled');
    $startTimeStr = $dep['start_time'];
    $endTimeStr = $dep['end_time'];
    $isOvernight = (bool)$dep['is_overnight'];

    // Shift start & end timestamps
    $shiftStartToday = strtotime($selectedDate . ' ' . $startTimeStr);
    $shiftEndToday = strtotime($selectedDate . ' ' . $endTimeStr);
    if ($isOvernight && $shiftEndToday <= $shiftStartToday) {
        $shiftEndToday = strtotime($selectedDate . ' ' . $endTimeStr . ' +1 day');
    }

    // Accurate status logic - single solid color per venue/shift status:
    // Yellow = Pending Attendance (Scheduled) | Green = Present/Ongoing | Blue = Completed | Amber = Half-Day | Red = Absent
    if ($attSt === 'scheduled' || empty($attSt)) {
        // Scheduled: Yellow cell with Scheduled badge
        $shState = 'scheduled';
        $statusClass = 'status-deployed';
        $badgeText = 'Scheduled';
        $scheduledCount++;
    } elseif ($attSt === 'half_day') {
        // Half-Day: Amber/Orange cell with Half-Day badge
        $shState = 'half_day';
        $statusClass = 'status-half-day';
        $badgeText = 'Half-Day';
        $ongoingCount++;
    } elseif ($attSt === 'present') {
        // Present: If shift end time passed, it is Completed (Blue)
        if ($isPastDate || ($isToday && $nowTs >= $shiftEndToday)) {
            $shState = 'completed';
            $statusClass = 'status-completed';
            $badgeText = 'Completed';
            $completedCount++;
        } else {
            // Present and shift is ongoing/today (Green)
            $shState = 'ongoing';
            $statusClass = 'status-ongoing';
            $badgeText = 'Present';
            $ongoingCount++;
        }
    } elseif ($attSt === 'absent') {
        // Absent: Red cell with Absent badge
        $shState = 'absent';
        $statusClass = 'status-absent';
        $badgeText = 'Absent';
    } else {
        $shState = 'scheduled';
        $statusClass = 'status-deployed';
        $badgeText = ucfirst($attSt);
        $scheduledCount++;
    }

    // In this Duty Roster page: NO 3-color diagonal gradient.
    // Each cell is 100% filled with a single solid color representing its venue attendance status so pending venues are immediately obvious.
    $stfId = (int)$dep['staff_id'];
    $multiShiftList = $staffDeploymentsMap[$stfId] ?? [];
    $isMultiShift = count($multiShiftList) > 1;

    $dep['computed_state']    = $shState;
    $dep['status_class']      = $statusClass;
    $dep['badge_text']        = $badgeText;
    $dep['is_multi_shift']    = $isMultiShift;
    $dep['multi_shift_count'] = count($multiShiftList);
    $dep['gradient_css']      = '';

    $sid = (int)$dep['site_id'];
    $shid = (int)$dep['shift_id'];
    $siteDeployments[$sid][$shid][] = $dep;
    $deployedSiteIds[$sid] = true;
}

$totalDeployedCount = count($deployments);
$totalVenuesWithDeployment = count($deployedSiteIds);

// Order client sites: venues with deployed staff first, followed by alphabetical
usort($allSites, function($a, $b) use ($deployedSiteIds) {
    $aDep = !empty($deployedSiteIds[$a['site_id']]);
    $bDep = !empty($deployedSiteIds[$b['site_id']]);
    if ($aDep && !$bDep) return -1;
    if (!$aDep && $bDep) return 1;
    return strcmp($a['company_name'], $b['company_name']);
});

// 6. Build Columns Structure: Shift Subheadings, Staff Cells, and 1 Empty Spacer between shifts
$columnsData = [];
$maxItemsCount = 0;

foreach ($allSites as $st) {
    $sid = (int)$st['site_id'];
    $siteShifts = $siteDeployments[$sid] ?? [];
    $colItems = [];
    $isFirstShiftInCol = true;
    $staffCountInSite = 0;

    foreach ($shifts as $sh) {
        $shid = (int)$sh['id'];
        if ($selectedShiftId > 0 && $selectedShiftId !== $shid) {
            continue;
        }

        $staffInShift = $siteShifts[$shid] ?? [];
        if (!empty($staffInShift)) {
            $staffCountInSite += count($staffInShift);

            // "pahle shift ka user cell khatam hote hi ek cell chhodkar usme dusre shift ka subheding lagega"
            if (!$isFirstShiftInCol) {
                $colItems[] = [
                    'type'     => 'spacer',
                    'site_id'  => $sid,
                    'shift_id' => $shid
                ];
            }
            $isFirstShiftInCol = false;

            // Shift Subheading Cell
            $shTimeFormatted = date('h:i A', strtotime($sh['start_time'])) . ' - ' . date('h:i A', strtotime($sh['end_time']));
            $colItems[] = [
                'type'       => 'shift_header',
                'site_id'    => $sid,
                'client_id'  => (int)$st['client_id'],
                'shift_id'   => $shid,
                'shift_name' => $sh['shift_name'],
                'shift_code' => $sh['shift_code'],
                'time_range' => $shTimeFormatted,
                'count'      => count($staffInShift),
                'deploy_ids' => array_map(function($x) { return (int)$x['deploy_id']; }, $staffInShift)
            ];

            // Staff Cells for this shift
            foreach ($staffInShift as $stf) {
                $colItems[] = [
                    'type'              => 'staff',
                    'deploy_id'         => (int)$stf['deploy_id'],
                    'staff_id'          => (int)$stf['staff_id'],
                    'staff_name'        => $stf['staff_name'],
                    'staff_code'        => $stf['staff_code'],
                    'category'          => $stf['category_name'] ?: ($stf['skill_category'] ?: 'Staff'),
                    'phone'             => $stf['staff_phone'],
                    'client_id'         => (int)$st['client_id'],
                    'site_id'           => $sid,
                    'shift_id'          => $shid,
                    'client_name'       => $stf['company_name'],
                    'site_name'         => $stf['site_name'],
                    'shift_name'        => $stf['shift_name'],
                    'shift_time'        => $shTimeFormatted,
                    'att_status'        => $stf['att_status'] ?: 'scheduled',
                    'computed_state'    => $stf['computed_state'],
                    'status_class'      => $stf['status_class'],
                    'badge_text'        => $stf['badge_text'],
                    'is_multi_shift'    => $stf['is_multi_shift'],
                    'multi_shift_count' => $stf['multi_shift_count'],
                    'gradient_css'      => $stf['gradient_css'],
                    'client_rate'       => (float)$stf['client_rate'],
                    'staff_rate'        => (float)$stf['staff_rate']
                ];
            }
        }
    }

    if (empty($colItems)) {
        $colItems[] = [
            'type'      => 'empty_client',
            'site_id'   => $sid,
            'client_id' => (int)$st['client_id'],
            'text'      => 'No staff mapped'
        ];
    }

    $columnsData[] = [
        'site_id'      => $sid,
        'client_id'    => (int)$st['client_id'],
        'client_name'  => $st['company_name'],
        'site_name'    => $st['site_name'],
        'has_deployed' => !empty($deployedSiteIds[$sid]),
        'staff_count'  => $staffCountInSite,
        'items'        => $colItems
    ];

    if (count($colItems) > $maxItemsCount) {
        $maxItemsCount = count($colItems);
    }
}

// 7. Prepare WhatsApp Roster Message Text
$waMessage = "📋 *" . Helper::getCompanyName() . " Deployment Roster*\n";
$waMessage .= "📅 *Date:* " . date('d M Y (l)', strtotime($selectedDate)) . "\n";
if ($selectedShiftId > 0) {
    $activeShName = '';
    foreach ($shifts as $s) if ((int)$s['id'] === $selectedShiftId) $activeShName = $s['shift_name'];
    if ($activeShName) $waMessage .= "🕒 *Shift:* " . $activeShName . "\n";
}
$waMessage .= "━━━━━━━━━━━━━━━━━━━━━━\n";

if (!empty($deployments)) {
    $curSite = null;
    $curShift = null;
    foreach ($deployments as $d) {
        if ($d['site_id'] !== $curSite || $d['shift_id'] !== $curShift) {
            $curSite = $d['site_id'];
            $curShift = $d['shift_id'];
            $shTime = date('h:i A', strtotime($d['start_time'])) . ' - ' . date('h:i A', strtotime($d['end_time']));
            $waMessage .= "\n📍 *{$d['company_name']}* - {$d['site_name']}\n";
            $waMessage .= "🕒 Shift: {$d['shift_name']} ({$shTime})\n";
        }
        $cat = $d['category_name'] ?: ($d['skill_category'] ?: 'Staff');
        $stBadge = strtoupper($d['att_status'] ?? 'SCHEDULED');
        $waMessage .= "• {$d['staff_name']} ({$cat}) - [{$stBadge}] - Ph: {$d['staff_phone']}\n";
    }
    $waMessage .= "\n━━━━━━━━━━━━━━━━━━━━━━\n_Please report in clean uniform 15 mins prior to shift start._";
} else {
    $waMessage .= "\nNo staff scheduled yet for this date.";
}

$pageTitle = 'Daily Staff Mapping Roster | ' . Helper::getCompanyName();
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<script>
    // Enable flush full-page Excel view
    document.body.classList.add('deploy-page-view');
</script>

<!-- ROSTER ROOT CONTAINER -->
<div class="px-1 py-1">

    <!-- 1. Unified 1-Line Excel Ribbon Toolstrip -->
    <div class="deploy-unified-toolstrip bg-white px-2.5 py-1.5 rounded-3 border shadow-2xs mb-2 d-flex align-items-center justify-content-between gap-2 flex-nowrap overflow-x-auto">
        
        <!-- Left Group: Sidebar Toggle + Operations Hub Nav Buttons + Date Navigator + Shift Dropdown + Multi-Select + Keyboard Actions -->
        <div class="d-flex align-items-center gap-2 flex-nowrap">
            <!-- Sidebar Toggle Button -->
            <button type="button" class="btn btn-xs btn-outline-secondary bg-white text-secondary rounded-2 px-2.5 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-semibold" id="pageSidebarToggle" onclick="toggleSidebarMenu()" title="Toggle Admin Sidebar (Hide/Show Menu)" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-layout-sidebar-inset"></i>
                <span id="sidebarToggleText" class="d-none d-sm-inline">Hide Sidebar</span>
            </button>

            <div class="vr mx-1 opacity-25" style="height: 18px;"></div>

            <!-- Operations Hub Navigation Buttons -->
            <div class="d-flex align-items-center gap-1 flex-nowrap">
                <a href="<?= BASE_URL ?>/modules/mapping/deploy.php" class="btn btn-xs btn-outline-secondary bg-white text-dark py-1 px-2 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-file-earmark-spreadsheet text-primary"></i> <span>Excel Deploy</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/mapping/index.php" class="btn btn-xs btn-primary active py-1 px-2 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-calendar-check-fill text-white"></i> <span>Duty Roster</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/shifts/index.php" class="btn btn-xs btn-outline-secondary bg-white text-dark py-1 px-2 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-clock-history text-info"></i> <span>Shift Rules</span>
                </a>
            </div>

            <div class="vr mx-1 opacity-25" style="height: 18px;"></div>

            <!-- Date Navigator with Flatpickr -->
            <div class="d-flex align-items-center gap-1 flex-nowrap">
                <button type="button" onclick="navigateRosterDay(-1)" class="btn btn-xs btn-white border rounded-2 px-2 py-1 shadow-2xs d-flex align-items-center justify-content-center" style="font-size: 0.72rem; height: 28px; width: 28px;" title="Previous Day">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="position-relative d-inline-flex align-items-center">
                    <button type="button" id="rosterDatePickerBtn" onclick="openRosterDatePicker()" class="btn btn-xs <?= $isToday ? 'btn-primary text-white fw-bold shadow-xs' : 'btn-white border text-dark fw-semibold shadow-2xs' ?> rounded-2 px-2 py-1 d-flex align-items-center gap-1.5 text-nowrap" style="font-size: 0.74rem; height: 28px;" title="Click to open calendar">
                        <i class="bi bi-calendar3 <?= $isToday ? 'text-white' : 'text-primary' ?>"></i>
                        <span id="currentDateLabel"><?= $isToday ? 'Today, ' . date('d M Y', strtotime($selectedDate)) : date('D, d M Y', strtotime($selectedDate)) ?></span>
                        <i class="bi bi-chevron-down opacity-75 ms-0.5" style="font-size: 0.65rem;"></i>
                    </button>
                    <input type="date" id="rosterDatePicker" value="<?= $selectedDate ?>" onchange="changeRosterDate(this.value)" style="position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; left: 0; bottom: 0;">
                </div>
                <button type="button" onclick="navigateRosterDay(1)" class="btn btn-xs btn-white border rounded-2 px-2 py-1 shadow-2xs d-flex align-items-center justify-content-center" style="font-size: 0.72rem; height: 28px; width: 28px;" title="Next Day">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php if (!$isToday): ?>
                    <button type="button" onclick="changeRosterDate('<?= $today ?>')" class="btn btn-xs btn-outline-primary bg-primary-subtle text-primary border-primary-subtle rounded-2 px-2 py-1 fw-bold text-nowrap shadow-2xs d-flex align-items-center gap-1" style="font-size: 0.70rem; height: 28px;" title="Jump to Today">
                        <i class="bi bi-arrow-counterclockwise"></i> Today
                    </button>
                <?php endif; ?>
            </div>

            <!-- Shift Dropdown -->
            <select id="rosterShiftSelect" class="form-select form-select-sm rounded-2 shadow-2xs fw-semibold py-1 px-2 text-nowrap" style="font-size: 0.73rem; width: auto; height: 28px;" onchange="changeRosterShift(this.value)">
                <option value="0">All Shifts</option>
                <?php foreach ($shifts as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= $selectedShiftId === (int)$sh['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sh['shift_name']) ?> (<?= date('h:i A', strtotime($sh['start_time'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="vr mx-1 opacity-25" style="height: 18px;"></div>

            <!-- Multi-Select Toggle Button (Supports Mouse Drag to Select) -->
            <button type="button" class="btn btn-xs btn-outline-primary bg-white rounded-2 px-2 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-semibold" id="toggleMultiSelectRosterBtn" onclick="toggleMultiSelectRoster()" title="Multi-Select Mode: Click or drag across cells to select staff" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-check2-square"></i> <span id="multiSelectBtnText">Multi-Select</span>
            </button>

            <!-- Shift-Wise Collapse All / Expand All Toggle -->
            <button type="button" class="btn btn-xs btn-outline-secondary bg-white rounded-2 px-2 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-semibold" id="btnToggleAllShiftsCollapse" onclick="toggleAllShiftsCollapse()" title="Collapse or Expand all shift sections across all client columns" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-arrows-collapse" id="allShiftsIcon"></i> <span id="allShiftsText">Collapse Shifts</span>
            </button>

            <!-- Active Selection Counter & Clear (Visible only when staff are selected) -->
            <div class="d-none align-items-center gap-1.5 flex-nowrap" id="rosterSelectionIndicator">
                <span class="badge bg-primary px-2 py-1 fw-bold font-monospace shadow-2xs" id="ribbonSelectedCountBadge" style="font-size: 0.70rem;">0 Selected</span>
                <button type="button" class="btn btn-xs btn-outline-secondary bg-white py-1 px-1.5 rounded-2 shadow-2xs" id="btnClearRosterSelection" onclick="clearRosterSelection()" title="Clear Selection (Esc)" style="height: 28px; font-size: 0.70rem;">
                    <i class="bi bi-x-lg"></i> Clear
                </button>
            </div>
        </div>

        <!-- Right Group: WhatsApp + Export CSV + Venue Filter + Search Filter -->
        <div class="d-flex align-items-center gap-1.5 flex-nowrap ms-auto">
            <!-- Copy/Send WhatsApp Roster Button -->
            <button type="button" class="btn btn-xs btn-outline-success bg-white text-success rounded-2 px-2 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1 fw-semibold" onclick="openWhatsAppModal()" title="View and Copy / Send Roster on WhatsApp" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-whatsapp"></i> <span class="d-none d-lg-inline">WhatsApp</span>
            </button>

            <!-- Export to CSV / Excel Button -->
            <button type="button" class="btn btn-xs btn-outline-secondary bg-white text-dark rounded-2 px-2 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1 fw-semibold" onclick="exportRosterCSV()" title="Export current Duty Roster to CSV file" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-file-earmark-excel text-success"></i> <span class="d-none d-lg-inline">Export</span>
            </button>

            <!-- Venue Visibility Toggle -->
            <button type="button" class="btn btn-xs btn-outline-secondary bg-white rounded-2 px-2 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1 fw-semibold" id="toggleVenueFilterBtn" onclick="toggleVenueFilter()" style="font-size: 0.72rem; height: 28px;" title="Toggle showing all client venues or only venues with mapped staff">
                <i class="bi bi-funnel-fill text-primary"></i> <span id="venueFilterBtnText">Deployed (<?= $totalVenuesWithDeployment ?>)</span>
            </button>

            <!-- Search Client or Staff Live Filter -->
            <div class="position-relative" style="width: 160px;">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-muted" style="font-size: 0.70rem; pointer-events: none;"></i>
                <input type="text" id="rosterSearchInput" class="form-control form-control-sm ps-4 pe-4 py-1 rounded-2" placeholder="Search staff..." oninput="applyRosterFilter()" style="font-size: 0.72rem; height: 28px;">
                <button type="button" class="btn btn-link text-muted position-absolute top-50 end-0 translate-middle-y me-1 p-0 d-none" id="clearRosterSearchBtn" onclick="clearRosterSearch()" style="font-size: 0.75rem; text-decoration: none; line-height: 1;" title="Clear filter">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- 2. MAIN WORKSPACE: 100% FULL-WIDTH AUTHENTIC EXCEL GRID CANVAS -->
    <div class="excel-deploy-layout">
        <div class="excel-matrix-canvas w-100" id="excelMatrixCanvas">
            
            <!-- Excel Sheet Status & Legend/KPI Bar -->
            <div class="excel-sheet-header-bar">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-dark text-white px-2 py-1 fw-bold d-flex align-items-center gap-1" style="font-size: 0.70rem;">
                        <i class="bi bi-people-fill text-info"></i> <span id="kpiTotalStaff"><?= $totalDeployedCount ?></span> Mapped Staff
                    </span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fw-bold" style="font-size: 0.70rem;">
                        <i class="bi bi-building-fill me-1"></i> <span id="kpiTotalVenues"><?= $totalVenuesWithDeployment ?></span> Active Venues
                    </span>
                    <span class="text-muted small d-none d-xl-inline" style="font-size: 0.70rem;">
                        <i class="bi bi-keyboard text-primary me-1"></i> Shortcuts: <strong>P</strong> (Present) &bull; <strong>H</strong> (Half-Day) &bull; <strong>A</strong> (Absent) &bull; <strong>U</strong> (Unassign) &bull; Drag on another staff to <strong>Swap Venues</strong>
                    </span>
                </div>
                
                <!-- Status Indicators with Cell Color Swatches & Live Counts -->
                <div class="d-flex align-items-center gap-2 flex-nowrap">
                    <!-- Scheduled / Upcoming (Yellow) -->
                    <div class="legend-kpi-pill legend-pill-deployed" title="Scheduled / Upcoming Shift (Pending Attendance)">
                        <span class="legend-color-box" style="background:#fde047; border:1px solid #ca8a04;"></span>
                        <span class="text-dark fw-semibold">Scheduled:</span>
                        <strong class="text-dark" id="kpiScheduledCount"><?= $scheduledCount ?></strong>
                    </div>

                    <!-- Ongoing / Present (Green) -->
                    <div class="legend-kpi-pill legend-pill-present" title="Ongoing / Present Shift">
                        <span class="legend-color-box" style="background:#16a34a; border:1px solid #15803d;"></span>
                        <span class="text-dark fw-semibold">Present:</span>
                        <strong class="text-dark" id="kpiOngoingCount"><?= $ongoingCount ?></strong>
                    </div>

                    <!-- Completed (Blue) -->
                    <div class="legend-kpi-pill legend-pill-completed" title="Completed Shift">
                        <span class="legend-color-box" style="background:#2563eb; border:1px solid #1d4ed8;"></span>
                        <span class="text-dark fw-semibold">Completed:</span>
                        <strong class="text-dark" id="kpiCompletedCount"><?= $completedCount ?></strong>
                    </div>
                </div>
            </div>

            <!-- Authentic Excel Dense Spreadsheet Table -->
            <div class="excel-table-scroll" id="excelTableScroll" style="height: calc(100vh - 195px); overflow: auto;">
                <table class="excel-dense-table" id="excelRosterGridTable">
                    <thead>
                        <tr id="excelRosterHeaderRow">
                            <!-- Populated dynamically via JS -->
                        </tr>
                    </thead>
                    <tbody id="excelRosterBody">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<!-- 3. WHATSAPP ROSTER SHARE MODAL -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-success text-white py-2 px-3">
                <h6 class="modal-title fw-bold d-flex align-items-center gap-1.5" style="font-size: 0.88rem;">
                    <i class="bi bi-whatsapp"></i> Daily Deployment Roster (WhatsApp Format)
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="text-muted small mb-2">Formatted schedule for <strong><?= date('l, d M Y', strtotime($selectedDate)) ?></strong> ready to share:</p>
                <textarea id="whatsappTextarea" class="form-control font-monospace" rows="12" style="font-size: 0.78rem;" readonly><?= htmlspecialchars($waMessage) ?></textarea>
            </div>
            <div class="modal-footer bg-light border-0 py-2 px-3 d-flex justify-content-between">
                <span class="text-muted small" style="font-size: 0.72rem;"><i class="bi bi-check2-all me-1"></i> <?= $totalDeployedCount ?> Staff Mapped</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-sm btn-success fw-bold d-flex align-items-center gap-1" onclick="copyWhatsAppText()">
                        <i class="bi bi-clipboard-check"></i> Copy Text
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4. TOAST NOTIFICATION -->
<div class="toast-container matrix-toast-container">
    <div id="rosterToast" class="toast align-items-center text-white border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-semibold py-2 px-3" id="rosterToastBody" style="font-size: 0.78rem;">
                Action completed.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- JAVASCRIPT ENGINE FOR CLIENT-WISE EXCEL DUTY ROSTER -->
<script>
const rosterRawColumns = <?= json_encode($columnsData) ?>;
let currentRosterDate = '<?= $selectedDate ?>';
let currentRosterShiftId = <?= $selectedShiftId ?>;
let onlyDeployedVenues = true;
let rosterFpInstance = null;
let multiSelectMode = false;
let selectedDeployIds = new Set();
let draggedDeployId = null;
let draggedItemData = null;
let isMouseDragSelecting = false;
let collapsedShifts = new Set(); // Stores siteId_shiftId for collapsed shifts

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    updateSidebarToggleText();
    renderClientRosterGrid();
    initRosterDatePicker();

    // Mouseup on window ends mouse drag selection
    window.addEventListener('mouseup', () => {
        if (isMouseDragSelecting) {
            isMouseDragSelecting = false;
            const table = document.getElementById('excelRosterGridTable');
            if (table) table.classList.remove('is-drag-selecting');
            updateSelectionUI();
        }
    });

    // Keyboard Shortcuts: P (Present), H (Half-Day), A (Absent), U (Unassign), Esc (Clear)
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        const key = e.key.toLowerCase();
        if (key === 'escape') {
            clearRosterSelection();
        } else if (selectedDeployIds.size > 0) {
            if (key === 'p') {
                e.preventDefault();
                submitBulkAttendance('present');
            } else if (key === 'h') {
                e.preventDefault();
                submitBulkAttendance('half_day');
            } else if (key === 'a') {
                e.preventDefault();
                submitBulkAttendance('absent');
            } else if (key === 'u' || key === 'delete' || key === 'backspace') {
                e.preventDefault();
                submitBulkUnassign();
            }
        }
    });
});

window.addEventListener('load', () => {
    initRosterDatePicker();
});

// Helper: Escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// Helper: Find item by deployId
function findDeployItemById(deployId) {
    for (const col of (rosterRawColumns || [])) {
        for (const it of col.items) {
            if (it.type === 'staff' && it.deploy_id === deployId) {
                return it;
            }
        }
    }
    return null;
}

// 1. Sidebar Toggle
function toggleSidebarMenu() {
    if (window.innerWidth >= 992) {
        document.body.classList.toggle('sidebar-collapsed');
        const isCollapsed = document.body.classList.contains('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');
    } else {
        const sidebar = document.querySelector('.app-sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (sidebar) sidebar.classList.toggle('show');
        if (overlay) overlay.classList.toggle('show');
    }
    updateSidebarToggleText();
}

function updateSidebarToggleText() {
    const isCollapsed = document.body.classList.contains('sidebar-collapsed');
    const textEl = document.getElementById('sidebarToggleText');
    if (textEl) {
        textEl.innerText = isCollapsed ? 'Show Sidebar' : 'Hide Sidebar';
    }
}

// 2. Date Navigation with Flatpickr & Prev/Next Day
function initRosterDatePicker() {
    const btn = document.getElementById('rosterDatePickerBtn');
    if (typeof flatpickr === 'function' && btn && !rosterFpInstance) {
        try {
            rosterFpInstance = flatpickr(btn, {
                defaultDate: currentRosterDate,
                dateFormat: 'Y-m-d',
                disableMobile: true,
                onChange: function(selectedDates, dateStr) {
                    if (dateStr && dateStr !== currentRosterDate) {
                        changeRosterDate(dateStr);
                    }
                }
            });
        } catch (e) {
            console.warn('Flatpickr init error:', e);
        }
    }
}

function openRosterDatePicker() {
    if (rosterFpInstance) {
        rosterFpInstance.open();
        return;
    }
    const picker = document.getElementById('rosterDatePicker');
    if (picker) {
        if (typeof picker.showPicker === 'function') {
            try {
                picker.showPicker();
            } catch (e) {
                picker.focus();
                picker.click();
            }
        } else {
            picker.focus();
            picker.click();
        }
    }
}

function navigateRosterDay(offsetDays) {
    let baseDate = currentRosterDate || '<?= $selectedDate ?>';
    const parts = baseDate.split('-');
    if (parts.length === 3) {
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const d = parseInt(parts[2], 10);
        const dt = new Date(y, m, d);
        dt.setDate(dt.getDate() + offsetDays);
        const nextY = dt.getFullYear();
        const nextM = String(dt.getMonth() + 1).padStart(2, '0');
        const nextD = String(dt.getDate()).padStart(2, '0');
        changeRosterDate(`${nextY}-${nextM}-${nextD}`);
    }
}

function changeRosterDate(newDate) {
    if (!newDate) return;
    const shiftId = currentRosterShiftId || 0;
    window.location.href = `index.php?date=${encodeURIComponent(newDate)}${shiftId ? '&shift_id=' + shiftId : ''}`;
}

function changeRosterShift(shiftId) {
    currentRosterShiftId = parseInt(shiftId) || 0;
    const dateParam = currentRosterDate || '<?= $selectedDate ?>';
    window.location.href = `index.php?date=${encodeURIComponent(dateParam)}${currentRosterShiftId ? '&shift_id=' + currentRosterShiftId : ''}`;
}

// 3. Shift Collapse / Accordion Management
function toggleShiftCollapse(key) {
    if (collapsedShifts.has(key)) {
        collapsedShifts.delete(key);
    } else {
        collapsedShifts.add(key);
    }
    renderClientRosterGrid();
}

function toggleAllShiftsCollapse() {
    // If some or all collapsed, expand all; otherwise collapse all
    const allKeys = [];
    (rosterRawColumns || []).forEach(col => {
        col.items.forEach(it => {
            if (it.type === 'shift_header') {
                allKeys.push(`${col.site_id}_${it.shift_id}`);
            }
        });
    });

    if (collapsedShifts.size > 0) {
        collapsedShifts.clear();
        document.getElementById('allShiftsText').innerText = 'Collapse Shifts';
        document.getElementById('allShiftsIcon').className = 'bi bi-arrows-collapse';
    } else {
        allKeys.forEach(k => collapsedShifts.add(k));
        document.getElementById('allShiftsText').innerText = 'Expand Shifts';
        document.getElementById('allShiftsIcon').className = 'bi bi-arrows-expand';
    }
    renderClientRosterGrid();
}

// 4. Render 100% Full-Width Authentic Excel Grid with Client Columns & Shift Groupings
function renderClientRosterGrid() {
    const thead = document.getElementById('excelRosterHeaderRow');
    const tbody = document.getElementById('excelRosterBody');
    const q = (document.getElementById('rosterSearchInput').value || '').toLowerCase().trim();

    // Filter columns based on search and visibility toggle
    let displayCols = rosterRawColumns || [];

    if (onlyDeployedVenues) {
        displayCols = displayCols.filter(col => col.has_deployed);
    }

    if (q) {
        displayCols = displayCols.filter(col => {
            const matchClient = col.client_name.toLowerCase().includes(q) || col.site_name.toLowerCase().includes(q);
            const matchStaff = col.items.some(it => it.type === 'staff' && (
                it.staff_name.toLowerCase().includes(q) || 
                it.staff_code.toLowerCase().includes(q) || 
                it.category.toLowerCase().includes(q)
            ));
            return matchClient || matchStaff;
        });
    }

    if (displayCols.length === 0) {
        thead.innerHTML = `<th class="excel-group-th text-center py-3">No matching client venues</th>`;
        tbody.innerHTML = `<tr><td class="text-center py-5 text-muted fst-italic">No deployments found matching current filter.</td></tr>`;
        return;
    }

    // Generate Header: Red bold Client Names with count pills + Drag drop zone
    let thHtml = '';
    displayCols.forEach(col => {
        thHtml += `
            <th class="excel-group-th" 
                style="min-width: 175px; max-width: 220px;" 
                title="${escapeHtml(col.client_name)} - ${escapeHtml(col.site_name)} (${col.staff_count} Staff)\n(Drag scheduled staff here to move venue)"
                data-site-id="${col.site_id}"
                data-client-id="${col.client_id}"
                ondragover="handleClientDragOver(event)"
                ondragleave="handleClientDragLeave(event)"
                ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id})">
                <div class="d-flex align-items-center justify-content-center gap-1 flex-nowrap">
                    <span class="group-title-text text-truncate" style="max-width: 140px;">${escapeHtml(col.client_name)}</span>
                    <span class="group-count-pill">${col.staff_count}</span>
                </div>
                ${col.site_name && col.site_name !== col.client_name ? `<div class="text-muted fw-normal text-truncate" style="font-size: 0.62rem; text-transform: none; max-width: 170px; margin: 0 auto;">${escapeHtml(col.site_name)}</div>` : ''}
            </th>
        `;
    });
    thead.innerHTML = thHtml;

    // Filter items inside columns if shift is collapsed
    const renderedCols = displayCols.map(col => {
        const filteredItems = [];
        let currentShiftKey = null;
        let isCurrentShiftCollapsed = false;

        col.items.forEach(it => {
            if (it.type === 'shift_header') {
                currentShiftKey = `${col.site_id}_${it.shift_id}`;
                isCurrentShiftCollapsed = collapsedShifts.has(currentShiftKey);
                filteredItems.push({
                    ...it,
                    is_collapsed: isCurrentShiftCollapsed,
                    collapse_key: currentShiftKey
                });
            } else if (it.type === 'staff') {
                if (!isCurrentShiftCollapsed) {
                    filteredItems.push(it);
                }
            } else if (it.type === 'spacer') {
                if (!isCurrentShiftCollapsed) {
                    filteredItems.push(it);
                }
            } else {
                filteredItems.push(it);
            }
        });

        return {
            ...col,
            items: filteredItems
        };
    });

    // Calculate maximum rows needed
    let maxItems = 0;
    renderedCols.forEach(col => {
        if (col.items.length > maxItems) maxItems = col.items.length;
    });
    const totalRows = Math.max(maxItems + 2, 12);

    // Build rows
    let rowsHtml = '';
    for (let r = 0; r < totalRows; r++) {
        rowsHtml += '<tr>';
        for (let c = 0; c < renderedCols.length; c++) {
            const col = renderedCols[c];
            if (r < col.items.length) {
                const item = col.items[r];
                if (item.type === 'shift_header') {
                    // Shift Subheading Cell with Collapse Chevron
                    const collapseKey = item.collapse_key;
                    const isCol = item.is_collapsed;
                    const chevronClass = isCol ? 'collapsed' : '';
                    const labelText = isCol ? `${escapeHtml(item.shift_name)} (${item.count} Staff Hidden)` : escapeHtml(item.shift_name);

                    rowsHtml += `
                        <td class="excel-shift-subheading-td" 
                            title="${escapeHtml(item.shift_name)} (${escapeHtml(item.time_range)})\n(Click to ${isCol ? 'Expand' : 'Collapse'} shift • Drag scheduled staff here)"
                            data-site-id="${col.site_id}"
                            data-client-id="${col.client_id}"
                            data-shift-id="${item.shift_id}"
                            ondragover="handleClientDragOver(event)"
                            ondragleave="handleClientDragLeave(event)"
                            ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id}, ${item.shift_id})"
                            onclick="toggleShiftCollapse('${collapseKey}')">
                            <div class="d-flex align-items-center justify-content-between px-1.5 w-100">
                                <span class="fw-bold text-truncate d-flex align-items-center gap-1">
                                    <i class="bi bi-chevron-down shift-collapse-icon ${chevronClass}"></i>
                                    <span>${labelText}</span>
                                </span>
                                <span class="badge ${isCol ? 'bg-primary text-white' : 'bg-white bg-opacity-75 text-dark'} fw-bold border">${item.count}</span>
                            </div>
                        </td>
                    `;
                } else if (item.type === 'spacer') {
                    rowsHtml += `
                        <td class="excel-shift-spacer-td" 
                            data-site-id="${col.site_id}"
                            data-client-id="${col.client_id}"
                            ondragover="handleClientDragOver(event)"
                            ondragleave="handleClientDragLeave(event)"
                            ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id})"
                            title="Shift Gap"></td>
                    `;
                } else if (item.type === 'staff') {
                    // Staff Cell with solid status color (Yellow for Pending/Scheduled, Green for Present, Blue for Completed, Amber for Half-Day, Red for Absent)
                    const isSelected = selectedDeployIds.has(item.deploy_id);
                    const selClass = isSelected ? 'is-selected' : '';
                    const isYellow = (item.status_class === 'status-deployed' || item.status_class === 'status-scheduled');
                    const badgeClass = isYellow ? 'bg-black bg-opacity-10 text-dark border border-warning' : 'bg-black bg-opacity-25 text-white';
                    const multiBadgeClass = isYellow ? 'bg-black bg-opacity-15 text-dark' : 'bg-white bg-opacity-25 text-white';
                    const multiBadge = item.is_multi_shift ? `<span class="badge ${multiBadgeClass} font-monospace ms-0.5" style="font-size:0.52rem;" title="Mapped across ${item.multi_shift_count} shifts">${item.multi_shift_count}S</span>` : '';

                    rowsHtml += `
                        <td class="excel-staff-td"
                            data-site-id="${col.site_id}"
                            data-client-id="${col.client_id}"
                            ondragover="handleClientDragOver(event)"
                            ondragleave="handleClientDragLeave(event)"
                            ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id}, ${item.shift_id})">
                            <div class="excel-staff-cell ${item.status_class} ${selClass}" 
                                id="cell-deploy-${item.deploy_id}"
                                data-deploy-id="${item.deploy_id}"
                                title="👤 Staff: ${escapeHtml(item.staff_name)} (${escapeHtml(item.category)})\n📍 Venue: ${escapeHtml(item.client_name)} (${escapeHtml(item.site_name)})\n🕒 Shift: ${escapeHtml(item.shift_name)} (${escapeHtml(item.shift_time)})\n📌 Status: ${item.badge_text}\n(Click/Drag to select • Drag onto another staff to SWAP venues)"
                                draggable="${!multiSelectMode}"
                                ondragstart="handleRosterDragStart(event, ${item.deploy_id})"
                                ondragend="handleRosterDragEnd(event)"
                                ondragover="handleStaffDragOver(event, ${item.deploy_id})"
                                ondragleave="handleStaffDragLeave(event, ${item.deploy_id})"
                                ondrop="handleStaffDropSwap(event, ${item.deploy_id})"
                                onmousedown="handleStaffMouseDown(event, ${item.deploy_id})"
                                onmouseenter="handleStaffMouseEnter(event, ${item.deploy_id})"
                                onclick="handleRosterCellClick(event, ${item.deploy_id})">
                                ${isSelected ? '<span class="selection-check-icon"><i class="bi bi-check-lg"></i></span>' : ''}
                                <span class="text-truncate flex-grow-1 fw-semibold">${escapeHtml(item.staff_name)}</span>
                                ${multiBadge}
                                <span class="badge ${badgeClass} font-monospace ms-1 flex-shrink-0" style="font-size:0.56rem;">${item.badge_text}</span>
                            </div>
                        </td>
                    `;
                } else if (item.type === 'empty_client') {
                    rowsHtml += `
                        <td class="excel-empty-cell text-center text-muted fst-italic" 
                            data-site-id="${col.site_id}"
                            data-client-id="${col.client_id}"
                            ondragover="handleClientDragOver(event)"
                            ondragleave="handleClientDragLeave(event)"
                            ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id})"
                            style="font-size:0.68rem; vertical-align:middle;">No staff mapped</td>
                    `;
                }
            } else {
                // Empty Excel cell
                rowsHtml += `
                    <td class="excel-empty-cell"
                        data-site-id="${col.site_id}"
                        data-client-id="${col.client_id}"
                        ondragover="handleClientDragOver(event)"
                        ondragleave="handleClientDragLeave(event)"
                        ondrop="handleClientDrop(event, ${col.site_id}, ${col.client_id})"></td>
                `;
            }
        }
        rowsHtml += '</tr>';
    }

    tbody.innerHTML = rowsHtml;
}

// 5. Multi-Select Toggle & Mouse Drag-to-Select
function toggleMultiSelectRoster() {
    multiSelectMode = !multiSelectMode;
    const btn = document.getElementById('toggleMultiSelectRosterBtn');
    const txt = document.getElementById('multiSelectBtnText');
    const tableScroll = document.getElementById('excelTableScroll');

    if (multiSelectMode) {
        btn.classList.add('active', 'btn-primary');
        btn.classList.remove('btn-outline-primary', 'bg-white');
        txt.innerText = 'Multi-Select (ON)';
        tableScroll.classList.add('multi-select-active');
        showToast('Multi-Select ON: Click or drag mouse across cells to select. Press P/H/A for attendance.', 'info');
    } else {
        btn.classList.remove('active', 'btn-primary');
        btn.classList.add('btn-outline-primary', 'bg-white');
        txt.innerText = 'Multi-Select';
        tableScroll.classList.remove('multi-select-active');
    }
    renderClientRosterGrid();
}

// Mouse Drag-to-Select Handlers
function handleStaffMouseDown(e, deployId) {
    if (e.button !== 0) return; // Only left click

    if (multiSelectMode) {
        isMouseDragSelecting = true;
        const table = document.getElementById('excelRosterGridTable');
        if (table) table.classList.add('is-drag-selecting');

        // Toggle or add
        if (!selectedDeployIds.has(deployId)) {
            selectedDeployIds.add(deployId);
        } else if (!e.shiftKey && !e.ctrlKey) {
            selectedDeployIds.delete(deployId);
        }
        updateSelectionUI();
    }
}

function handleStaffMouseEnter(e, deployId) {
    if (multiSelectMode && isMouseDragSelecting) {
        if (!selectedDeployIds.has(deployId)) {
            selectedDeployIds.add(deployId);
            updateSelectionUI();
        }
    }
}

function handleRosterCellClick(e, deployId) {
    e.stopPropagation();

    if (!multiSelectMode && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
        // Single selection
        if (selectedDeployIds.has(deployId) && selectedDeployIds.size === 1) {
            selectedDeployIds.clear();
        } else {
            selectedDeployIds.clear();
            selectedDeployIds.add(deployId);
        }
    } else if (!isMouseDragSelecting) {
        // Click toggle
        if (selectedDeployIds.has(deployId)) {
            selectedDeployIds.delete(deployId);
        } else {
            selectedDeployIds.add(deployId);
        }
    }

    updateSelectionUI();
}

function clearRosterSelection() {
    selectedDeployIds.clear();
    updateSelectionUI();
}

function updateSelectionUI() {
    const indicator = document.getElementById('rosterSelectionIndicator');
    const badge = document.getElementById('ribbonSelectedCountBadge');
    const totalSelected = selectedDeployIds.size;

    if (totalSelected > 0) {
        if (badge) badge.innerText = `${totalSelected} Selected`;
        if (indicator) {
            indicator.classList.remove('d-none');
            indicator.classList.add('d-flex');
        }
    } else {
        if (indicator) {
            indicator.classList.add('d-none');
            indicator.classList.remove('d-flex');
        }
    }

    // Update cells DOM directly for instantaneous speed
    document.querySelectorAll('.excel-staff-cell').forEach(el => {
        const did = parseInt(el.getAttribute('data-deploy-id'), 10);
        if (selectedDeployIds.has(did)) {
            el.classList.add('is-selected');
            if (!el.querySelector('.selection-check-icon')) {
                const check = document.createElement('span');
                check.className = 'selection-check-icon';
                check.innerHTML = '<i class="bi bi-check-lg"></i>';
                el.prepend(check);
            }
        } else {
            el.classList.remove('is-selected');
            const check = el.querySelector('.selection-check-icon');
            if (check) check.remove();
        }
    });
}

// 6. Bulk Attendance Status Execution (Triggered via Keyboard P/H/A or Ribbon Buttons)
async function submitBulkAttendance(status) {
    if (selectedDeployIds.size === 0) {
        showToast('Please select at least 1 staff member first.', 'warning');
        return;
    }
    const deployIds = Array.from(selectedDeployIds);

    const isFuture = <?= $isFutureDate ? 'true' : 'false' ?>;
    if (isFuture && (status === 'present' || status === 'half_day')) {
        showToast('Cannot mark Present for future date. Attendance can only be confirmed on or before today.', 'danger');
        return;
    }

    if (status === 'absent') {
        const confirmed = await window.customConfirm({
            title: 'Mark Staff Absent & Free Up?',
            message: `Kya aap in <strong>${deployIds.length}</strong> staff ko <strong>Absent</strong> mark karke free up (unassign) karna chahte hain? Inhe venue se hata diya jayega aur ye fir se deploy ke liye uplabdh ho jayenge.`,
            confirmText: 'Yes, Mark Absent',
            cancelText: 'Cancel',
            btnClass: 'btn-danger',
            icon: 'bi-x-circle-fill',
            iconBg: '#fee2e2',
            iconColor: '#dc2626'
        });
        if (!confirmed) return;
    }

    showToast(`Updating attendance for ${deployIds.length} staff...`, 'info');

    const formData = new FormData();
    formData.append('action', 'bulk_update_attendance');
    formData.append('deploy_ids', deployIds.join(','));
    formData.append('status', status);
    formData.append('date', currentRosterDate);

    try {
        const res = await fetch('ajax_matrix.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json());

        if (res.success) {
            showToast(res.message, 'success');
            clearRosterSelection();
            setTimeout(() => { window.location.reload(); }, 400);
        } else {
            showToast(res.message || 'Failed to update attendance.', 'danger');
        }
    } catch (e) {
        showToast('Network error updating attendance.', 'danger');
    }
}

// 7. Bulk Unassign from Venue
async function submitBulkUnassign() {
    if (selectedDeployIds.size === 0) {
        showToast('Please select at least 1 staff member to unassign.', 'warning');
        return;
    }
    const deployIds = Array.from(selectedDeployIds);

    const confirmed = await window.customConfirm({
        title: 'Unassign Selected Staff?',
        message: `Are you sure you want to remove <strong>${deployIds.length}</strong> staff member(s) from their assigned venue? They will be returned to their pool.`,
        confirmText: 'Yes, Unassign Staff',
        cancelText: 'Cancel',
        btnClass: 'btn-danger',
        icon: 'bi-person-x-fill',
        iconBg: '#fee2e2',
        iconColor: '#dc2626'
    });
    if (!confirmed) return;

    showToast(`Unassigning ${deployIds.length} staff...`, 'info');

    let successCount = 0;
    for (const did of deployIds) {
        const formData = new FormData();
        formData.append('action', 'unassign_staff');
        formData.append('deploy_id', did);
        formData.append('reason', 'Unassigned via Excel Duty Roster');
        try {
            await fetch('ajax_matrix.php', {
                method: 'POST',
                body: formData
            });
            successCount++;
        } catch(e) {}
    }

    showToast(`Successfully unassigned ${successCount} staff!`, 'warning');
    clearRosterSelection();
    setTimeout(() => { window.location.reload(); }, 400);
}

// 8. Drag-and-Drop: Venue Move & STAFF SWAP FEATURE
function handleRosterDragStart(e, deployId) {
    if (multiSelectMode) {
        e.preventDefault();
        return;
    }

    const item = findDeployItemById(deployId);
    if (!item) return;

    draggedDeployId = deployId;
    draggedItemData = item;

    e.dataTransfer.setData('text/plain', String(deployId));
    e.dataTransfer.effectAllowed = 'move';
    e.target.classList.add('dragging');
}

function handleRosterDragEnd(e) {
    e.target.classList.remove('dragging');
    document.querySelectorAll('.drag-over-client, .drag-swap-target').forEach(el => {
        el.classList.remove('drag-over-client', 'drag-swap-target');
    });
    draggedDeployId = null;
    draggedItemData = null;
}

function handleClientDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const targetEl = e.currentTarget;
    if (!targetEl.classList.contains('drag-over-client')) {
        targetEl.classList.add('drag-over-client');
    }
}

function handleClientDragLeave(e) {
    e.currentTarget.classList.remove('drag-over-client');
}

function handleClientDrop(e, targetSiteId, targetClientId, targetShiftId = 0) {
    e.preventDefault();
    document.querySelectorAll('.drag-over-client, .drag-swap-target').forEach(el => {
        el.classList.remove('drag-over-client', 'drag-swap-target');
    });

    if (!draggedDeployId || !draggedItemData) return;

    const item = draggedItemData;
    if (item.site_id === targetSiteId) {
        return; // Already on this site
    }

    // Check attendance status: If ALREADY PRESENT, cannot move!
    if (item.att_status === 'present' || item.att_status === 'half_day') {
        showToast(`Staff '${item.staff_name}' already present on ${item.site_name}. Cannot move a present staff to another site.`, 'danger');
        return;
    }

    // Scheduled staff: Execute move
    executeMoveStaffVenue(item.deploy_id, targetClientId, targetSiteId, targetShiftId || item.shift_id);
}

// Staff Swap Handlers (Hover over another staff during drag)
function handleStaffDragOver(e, targetDeployId) {
    if (!draggedDeployId || draggedDeployId === targetDeployId) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';

    const cell = document.getElementById(`cell-deploy-${targetDeployId}`);
    if (cell && !cell.classList.contains('drag-swap-target')) {
        cell.classList.add('drag-swap-target');
    }
}

function handleStaffDragLeave(e, targetDeployId) {
    const cell = document.getElementById(`cell-deploy-${targetDeployId}`);
    if (cell) cell.classList.remove('drag-swap-target');
}

async function handleStaffDropSwap(e, targetDeployId) {
    e.preventDefault();
    e.stopPropagation();
    document.querySelectorAll('.drag-swap-target, .drag-over-client').forEach(el => el.classList.remove('drag-swap-target', 'drag-over-client'));

    if (!draggedDeployId || draggedDeployId === targetDeployId) return;

    const sourceItem = draggedItemData || findDeployItemById(draggedDeployId);
    const targetItem = findDeployItemById(targetDeployId);

    if (!sourceItem || !targetItem) return;

    // Validation: Check if either staff is already marked Present
    if (sourceItem.att_status === 'present' || sourceItem.att_status === 'half_day') {
        showToast(`Cannot swap: '${sourceItem.staff_name}' is already marked Present on ${sourceItem.site_name}.`, 'danger');
        return;
    }
    if (targetItem.att_status === 'present' || targetItem.att_status === 'half_day') {
        showToast(`Cannot swap: '${targetItem.staff_name}' is already marked Present on ${targetItem.site_name}.`, 'danger');
        return;
    }

    // Confirm Swap
    const confirmed = await window.customConfirm({
        title: '⇄ Swap Staff Venues?',
        message: `Kya aap <strong>${escapeHtml(sourceItem.staff_name)}</strong> (${escapeHtml(sourceItem.client_name)}) aur <strong>${escapeHtml(targetItem.staff_name)}</strong> (${escapeHtml(targetItem.client_name)}) ke venues ko aapas me swap (adla-badli) karna chahte hain?`,
        confirmText: 'Yes, Swap Venues',
        cancelText: 'Cancel',
        btnClass: 'btn-primary',
        icon: 'bi-arrow-left-right',
        iconBg: '#ede9fe',
        iconColor: '#7c3aed'
    });

    if (!confirmed) return;

    showToast(`Swapping ${sourceItem.staff_name} and ${targetItem.staff_name}...`, 'info');

    const formData = new FormData();
    formData.append('action', 'swap_staff');
    formData.append('deploy_id1', sourceItem.deploy_id);
    formData.append('deploy_id2', targetItem.deploy_id);

    try {
        const res = await fetch('ajax_matrix.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json());

        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => { window.location.reload(); }, 400);
        } else {
            showToast(res.message || 'Swap failed.', 'danger');
        }
    } catch (err) {
        showToast('Network error during staff swap.', 'danger');
    }
}

async function executeMoveStaffVenue(deployId, targetClientId, targetSiteId, targetShiftId, forceMove = 0) {
    const formData = new FormData();
    formData.append('action', 'move_staff_venue');
    formData.append('deploy_id', deployId);
    formData.append('target_client_id', targetClientId);
    formData.append('target_site_id', targetSiteId);
    formData.append('target_shift_id', targetShiftId || 0);
    if (forceMove) {
        formData.append('force_move', '1');
    }

    try {
        const res = await fetch('ajax_matrix.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json());

        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => { window.location.reload(); }, 400);
        } else if (res.conflict_type === 'warning_can_move') {
            const confirmed = await window.customConfirm({
                title: 'Shift Staff to New Venue?',
                message: res.message,
                confirmText: 'Yes, Shift Venue',
                cancelText: 'Cancel',
                btnClass: 'btn-warning text-dark',
                icon: 'bi-arrow-left-right',
                iconBg: '#fef3c7',
                iconColor: '#d97706'
            });
            if (confirmed) {
                executeMoveStaffVenue(deployId, targetClientId, targetSiteId, targetShiftId, 1);
            }
        } else if (res.conflict_type === 'already_present') {
            showToast(res.message, 'danger');
        } else {
            showToast(res.message || 'Could not shift staff venue.', 'danger');
        }
    } catch (err) {
        showToast('Network error while moving staff venue.', 'danger');
    }
}

// 9. WhatsApp Sharing
function openWhatsAppModal() {
    const modalEl = document.getElementById('whatsappModal');
    if (modalEl) {
        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
    }
}

function copyWhatsAppText() {
    const ta = document.getElementById('whatsappTextarea');
    if (ta) {
        ta.select();
        ta.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(ta.value).then(() => {
            showToast('Deployment Roster copied to clipboard!', 'success');
        }).catch(() => {
            document.execCommand('copy');
            showToast('Deployment Roster copied!', 'success');
        });
    }
}

// 10. Export Roster to CSV
function exportRosterCSV() {
    let rows = [];
    rows.push(['Date', currentRosterDate]);
    rows.push(['Client Name', 'Site Name', 'Shift', 'Staff Code', 'Staff Name', 'Category', 'Phone', 'Attendance Status']);

    (rosterRawColumns || []).forEach(col => {
        col.items.forEach(it => {
            if (it.type === 'staff') {
                rows.push([
                    `"${(col.client_name || '').replace(/"/g, '""')}"`,
                    `"${(col.site_name || '').replace(/"/g, '""')}"`,
                    `"${(it.shift_name || '').replace(/"/g, '""')}"`,
                    `"${it.staff_code || ''}"`,
                    `"${(it.staff_name || '').replace(/"/g, '""')}"`,
                    `"${it.category || ''}"`,
                    `"${it.phone || ''}"`,
                    `"${it.badge_text || ''}"`
                ]);
            }
        });
    });

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + rows.map(e => e.join(',')).join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `Duty_Roster_${currentRosterDate}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Duty Roster exported to CSV successfully!', 'success');
}

// 11. Venue Filter & Search Filter
function toggleVenueFilter() {
    onlyDeployedVenues = !onlyDeployedVenues;
    const btn = document.getElementById('toggleVenueFilterBtn');
    const txt = document.getElementById('venueFilterBtnText');
    if (onlyDeployedVenues) {
        if (btn) btn.classList.remove('active', 'btn-primary');
        if (txt) txt.innerText = `Deployed (${<?= $totalVenuesWithDeployment ?>})`;
    } else {
        if (btn) btn.classList.add('active', 'btn-primary');
        if (txt) txt.innerText = `All Venues (${<?= count($allSites) ?>})`;
    }
    renderClientRosterGrid();
}

function applyRosterFilter() {
    const input = document.getElementById('rosterSearchInput');
    const clearBtn = document.getElementById('clearRosterSearchBtn');
    if (input && clearBtn) {
        clearBtn.classList.toggle('d-none', !input.value || input.value.trim().length === 0);
    }
    renderClientRosterGrid();
}

function clearRosterSearch() {
    const input = document.getElementById('rosterSearchInput');
    if (input) {
        input.value = '';
        applyRosterFilter();
        input.focus();
    }
}

// 12. Toast System
function showToast(message, type = 'success') {
    const toastEl = document.getElementById('rosterToast');
    const toastBody = document.getElementById('rosterToastBody');
    if (!toastEl || !toastBody) return;

    toastEl.className = 'toast align-items-center text-white border-0 shadow-lg';
    if (type === 'success') toastEl.classList.add('bg-success');
    else if (type === 'danger') toastEl.classList.add('bg-danger');
    else if (type === 'warning') toastEl.classList.add('bg-warning', 'text-dark');
    else if (type === 'info') toastEl.classList.add('bg-info', 'text-dark');
    else toastEl.classList.add('bg-primary');

    toastBody.innerHTML = message;
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3500 });
    bsToast.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
