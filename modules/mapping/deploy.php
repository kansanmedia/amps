<?php
// modules/mapping/deploy.php - Authentic Excel-Sheet Staff Deployment Grid (85%) with 15% Single-Column Client Accordion
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/DeploymentService.php';
Auth::requireRole(['owner_admin', 'manager']);

$db = DB::getConnection();
$today = date('Y-m-d');
$selectedDate = !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $today;
$selectedShiftId = !empty($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;
$prevDate = date('Y-m-d', strtotime('-1 day', strtotime($selectedDate)));
$nextDate = date('Y-m-d', strtotime('+1 day', strtotime($selectedDate)));
$isToday = ($selectedDate === $today);

// Fetch master options
$categories = $db->query("SELECT id, category_name, category_code FROM staff_categories ORDER BY category_name ASC")->fetchAll();
$shifts = $db->query("SELECT id, shift_name, shift_code, start_time, end_time, is_overnight, duration_hours FROM shifts WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$sites = $db->query("SELECT s.id, s.client_id, s.site_name, c.company_name FROM sites s JOIN clients c ON s.client_id = c.id WHERE s.status = 'active' AND c.status = 'active' ORDER BY c.company_name ASC, s.site_name ASC")->fetchAll();

$pageTitle = 'Excel Staff Deployment Sheet';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<script>
    // Ensure flush layout and bottom gap elimination
    document.body.classList.add('deploy-page-view');
</script>

<style>
.excel-search-result-cell {
    border-left: 3px solid #4f46e5 !important;
    background-color: #fdfefe;
}
.excel-search-result-cell:hover {
    background-color: #f0f4ff !important;
}
</style>

<!-- MATRIX ROOT CONTAINER -->
<div class="px-1 py-1">
    
    <!-- 1. Unified 1-Line Excel Ribbon Toolstrip: All Nav Tabs, Buttons, Date, Shift & Filters Aligned in 1 Row -->
    <div class="deploy-unified-toolstrip bg-white px-2.5 py-1.5 rounded-3 border shadow-2xs mb-2 d-flex align-items-center justify-content-between gap-2.5 flex-nowrap overflow-x-auto">
        
        <!-- Left Group: Sidebar Toggle + Operations Hub Nav Buttons + Date Navigator + Shift Dropdown + Add Group -->
        <div class="d-flex align-items-center gap-2.5 flex-nowrap">
            <!-- Sidebar Toggle Button -->
            <button type="button" class="btn btn-xs btn-outline-secondary bg-white text-secondary rounded-2 px-2.5 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-semibold" id="pageSidebarToggle" onclick="toggleSidebarMenu()" title="Toggle Admin Sidebar (Hide/Show Menu)" style="font-size: 0.72rem; height: 28px;">
                <i class="bi bi-layout-sidebar-inset"></i>
                <span id="sidebarToggleText" class="d-none d-sm-inline">Hide Sidebar</span>
            </button>

            <div class="vr mx-1 opacity-25" style="height: 18px;"></div>

            <!-- Operations Hub Navigation Buttons (Clean Spaced Buttons) -->
            <div class="d-flex align-items-center gap-2 flex-nowrap">
                <a href="<?= BASE_URL ?>/modules/mapping/deploy.php" class="btn btn-xs btn-primary active py-1 px-2.5 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-file-earmark-spreadsheet-fill text-white"></i> <span>Excel Deploy</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/mapping/index.php" class="btn btn-xs btn-outline-secondary bg-white text-dark py-1 px-2.5 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-calendar-check text-primary"></i> <span>Duty Roster</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/shifts/index.php" class="btn btn-xs btn-outline-secondary bg-white text-dark py-1 px-2.5 rounded-2 fw-semibold text-nowrap d-flex align-items-center gap-1.5 shadow-2xs" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-clock-history text-info"></i> <span>Shift Rules</span>
                </a>
            </div>

            <div class="vr mx-1 opacity-25" style="height: 18px;"></div>

            <!-- Date Navigator (Clean & Spaced) -->
            <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                <button type="button" onclick="navigateMatrixDay(-1)" class="btn btn-xs btn-white border rounded-2 px-2 py-1 shadow-2xs d-flex align-items-center justify-content-center" style="font-size: 0.72rem; height: 28px; width: 28px;" title="Previous Day">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="position-relative d-inline-flex align-items-center">
                    <button type="button" id="matrixDatePickerBtn" onclick="openMatrixDatePicker()" class="btn btn-xs <?= $isToday ? 'btn-primary text-white fw-bold shadow-xs' : 'btn-white border text-dark fw-semibold shadow-2xs' ?> rounded-2 px-2.5 py-1 d-flex align-items-center gap-1.5 text-nowrap" style="font-size: 0.74rem; height: 28px;" title="Click to open calendar">
                        <i class="bi bi-calendar3 <?= $isToday ? 'text-white' : 'text-primary' ?>"></i>
                        <span id="currentDateLabel"><?= $isToday ? 'Today, ' . date('d M Y', strtotime($selectedDate)) : date('D, d M Y', strtotime($selectedDate)) ?></span>
                        <i class="bi bi-chevron-down opacity-75 ms-0.5" style="font-size: 0.65rem;"></i>
                    </button>
                    <input type="date" id="matrixDatePicker" value="<?= $selectedDate ?>" onchange="changeMatrixDate(this.value)" style="position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; left: 0; bottom: 0;">
                </div>
                <button type="button" onclick="navigateMatrixDay(1)" class="btn btn-xs btn-white border rounded-2 px-2 py-1 shadow-2xs d-flex align-items-center justify-content-center" style="font-size: 0.72rem; height: 28px; width: 28px;" title="Next Day">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php if (!$isToday): ?>
                    <button type="button" onclick="changeMatrixDate('<?= $today ?>')" class="btn btn-xs btn-outline-primary bg-primary-subtle text-primary border-primary-subtle rounded-2 px-2.5 py-1 fw-bold text-nowrap shadow-2xs d-flex align-items-center gap-1" style="font-size: 0.70rem; height: 28px;" title="Jump to Today">
                        <i class="bi bi-arrow-counterclockwise"></i> Today
                    </button>
                <?php endif; ?>
            </div>

            <!-- Shift Dropdown (Compact) -->
            <select id="matrixShiftSelect" class="form-select form-select-sm rounded-2 shadow-2xs fw-semibold py-1 px-2.5 text-nowrap" style="font-size: 0.73rem; width: auto; height: 28px;" onchange="changeMatrixShift(this.value)">
                <option value="0">All Shifts</option>
                <?php foreach ($shifts as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= $selectedShiftId === (int)$sh['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sh['shift_name']) ?> (<?= date('h:i A', strtotime($sh['start_time'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Staff Pool / Groups Action Buttons -->
            <div class="d-flex align-items-center gap-2 flex-nowrap">
                <button type="button" class="btn btn-xs btn-outline-danger bg-white rounded-2 px-2.5 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-bold" onclick="promptAddNewGroup()" title="Add New Staff Pool / Group" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-plus-circle-fill text-danger"></i> <span>+ Group</span>
                </button>
                <button type="button" class="btn btn-xs btn-outline-secondary bg-white text-secondary rounded-2 px-2.5 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-semibold" onclick="openManageGroupsModal()" title="Manage, Rename, or Delete Staff Pools & Groups" style="font-size: 0.72rem; height: 28px;">
                    <i class="bi bi-gear-fill text-secondary"></i> <span>Manage Groups</span>
                </button>
            </div>
        </div>

        <!-- Right Group: Clean Search Filter (Aligned & Compact with Match Counter) -->
        <div class="d-flex align-items-center gap-2 flex-nowrap ms-auto">
            <button type="button" class="btn btn-xs btn-primary active rounded-2 px-2.5 py-1 shadow-2xs text-nowrap d-flex align-items-center gap-1.5 fw-bold" id="toggleMultiSelectBtn" onclick="toggleMultiSelectMode()" style="font-size: 0.72rem; height: 28px;" title="Multi-Select Mode: Click or drag to select cells">
                <i class="bi bi-check2-square"></i> <span id="multiSelectBtnText">Multi-Select (ON)</span>
            </button>
            <span id="searchMatchCountBadge" class="badge bg-primary text-white rounded-2 px-2.5 py-1 shadow-2xs fw-bold text-nowrap d-none align-items-center gap-1.5" style="font-size: 0.72rem; height: 28px;" title="Total Search Matches">
                <i class="bi bi-funnel-fill"></i> <span id="searchMatchCountNum">0</span> Found
            </span>
            <div class="position-relative" style="width: 185px;">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-muted" style="font-size: 0.72rem; pointer-events: none;"></i>
                <input type="text" id="staffFilterSearch" class="form-control form-control-sm ps-4 pe-4 py-1 rounded-2" placeholder="Filter staff... (Press F)" title="Press 'F' key anywhere to search staff" oninput="applyFilters()" style="font-size: 0.73rem; height: 28px;">
                <button type="button" class="btn btn-link text-muted position-absolute top-50 end-0 translate-middle-y me-1 p-0 d-none" id="clearStaffSearchBtn" onclick="clearStaffSearch()" style="font-size: 0.75rem; text-decoration: none; line-height: 1;" title="Clear filter">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        </div>
    </div>


    <!-- 2. MAIN WORKSPACE: 82% EXCEL GRID (LEFT) + 18% CLIENT ACCORDION STACK (RIGHT) -->
    <div class="excel-deploy-layout">
        
        <!-- ========================================================= -->
        <!-- 82% LEFT: AUTHENTIC MULTI-COLUMN EXCEL SPREADSHEET CANVAS -->
        <!-- ========================================================= -->
        <div class="excel-matrix-canvas" id="excelMatrixCanvas">
            
            <!-- Excel Sheet Status & Unified Legend/KPI Bar (Perfect Alignment) -->
            <div class="excel-sheet-header-bar">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-dark text-white px-2 py-1 fw-bold d-flex align-items-center gap-1" style="font-size: 0.70rem;" id="excelStaffCountBadge">
                        <i class="bi bi-people-fill text-info"></i> <span id="kpiTotalStaff">--</span> Staff
                    </span>
                    <span class="text-muted small d-none d-lg-inline" style="font-size: 0.70rem;" id="excelSearchStatusNotice">
                        <i class="bi bi-info-circle text-primary me-1"></i> Drag staff to right to deploy &bull; Drag across columns to shift group
                    </span>
                </div>
                
                <!-- Unified Status Indicators with Cell Color Swatches & Live Counts -->
                <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                    <!-- Ready / Available -->
                    <div class="legend-kpi-pill legend-pill-ready" title="Available & Ready for Deployment (Normal White)">
                        <span class="legend-color-box bg-white border"></span>
                        <span class="text-muted fw-semibold">Ready:</span>
                        <strong class="text-dark" id="kpiReadyStaff">--</strong>
                    </div>

                    <!-- Scheduled / Upcoming (Yellow) -->
                    <div class="legend-kpi-pill legend-pill-deployed" title="Scheduled / Upcoming Shift (Yellow)">
                        <span class="legend-color-box" style="background:#fde047; border:1px solid #ca8a04;"></span>
                        <span class="text-dark fw-semibold">Scheduled:</span>
                        <strong class="text-dark" id="kpiDeployedStaff">--</strong>
                    </div>

                    <!-- Ongoing / Present (Green) -->
                    <div class="legend-kpi-pill legend-pill-present" title="Currently Ongoing / Present at Venue (Green)">
                        <span class="legend-color-box" style="background:#16a34a; border:1px solid #15803d;"></span>
                        <span class="text-success fw-semibold">Ongoing:</span>
                        <strong class="text-success" id="kpiPresentStaff">0</strong>
                    </div>

                    <!-- Completed (Blue) -->
                    <div class="legend-kpi-pill legend-pill-completed" title="Shift Completed / Finished (Blue)">
                        <span class="legend-color-box" style="background:#2563eb; border:1px solid #1d4ed8;"></span>
                        <span class="text-primary fw-semibold">Completed:</span>
                        <strong class="text-primary" id="kpiCompletedStaff">0</strong>
                    </div>

                    <!-- Multi-Shift (Diagonal Split Swatch) -->
                    <div class="legend-kpi-pill legend-pill-multishift" title="Multi-Shift Staff (Divided Diagonally: Blue / Green / Yellow)">
                        <span class="legend-color-box" style="background: linear-gradient(135deg, #2563eb 0%, #2563eb 33%, #16a34a 33%, #16a34a 66%, #fde047 66%, #fde047 100%); border:1px solid #94a3b8;"></span>
                        <span class="text-secondary fw-semibold">Multi-Shift:</span>
                        <strong class="text-dark" id="kpiMultiShiftStaff">0</strong>
                    </div>

                    <!-- On Leave -->
                    <div class="legend-kpi-pill legend-pill-leave" title="On Leave (Chhutti - Pink)">
                        <span class="legend-color-box" style="background:#fbcfe8; border:1px solid #db2777;"></span>
                        <span class="text-danger fw-semibold">Leave:</span>
                        <strong class="text-danger" id="kpiLeaveStaff">--</strong>
                    </div>
                </div>
            </div>

            <!-- Scrollable Excel Grid Matrix -->
            <div class="excel-table-scroll">
                <table class="excel-dense-table" id="excelMatrixTable">
                    <thead>
                        <tr id="excelMatrixHeaderRow">
                            <!-- Dynamic Excel Group Headers generated by JS -->
                        </tr>
                    </thead>
                    <tbody id="excelMatrixBody">
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
                                <div>Loading Excel workforce sheet...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

</div>

<!-- 3. FLOATING CELL ACTION MENU (CONTEXT POPOVER ON CELL CLICK - DISABLED PER USER REQUEST) -->
<div class="cell-action-popover" id="cellActionPopover" style="display: none !important;">
    <div class="d-flex align-items-center justify-content-between border-bottom pb-1.5 mb-2">
        <div class="overflow-hidden">
            <div class="fw-bold text-dark text-truncate" id="popoverStaffName" style="font-size: 0.85rem;">Staff Name</div>
            <div class="text-muted" style="font-size: 0.68rem;" id="popoverStaffMeta">STF-001 &bull; Category</div>
        </div>
        <button type="button" class="btn-close" style="font-size: 0.65rem;" onclick="closeCellPopover()"></button>
    </div>

    <!-- Assigned Shifts Section (Dynamic List with Badges & Unassign Buttons) -->
    <div class="mb-2 d-none" id="popoverUnassignSection">
        <label class="form-label mb-1 text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">Assigned Shifts</label>
        <div id="popoverShiftsList" class="d-flex flex-column gap-1 mb-1.5" style="max-height: 140px; overflow-y: auto;">
            <!-- Populated dynamically via JS -->
        </div>
    </div>

    <!-- Quick Venue Assign Section -->
    <div class="mb-2" id="popoverAssignSection">
        <label class="form-label mb-1 text-uppercase fw-bold text-muted" style="font-size: 0.65rem;" id="popoverAssignTitle">Deploy to Venue</label>
        <div class="d-flex flex-column gap-1 mb-1.5">
            <select id="popoverShiftSelect" class="form-select form-select-sm" style="font-size: 0.74rem;">
                <?php foreach ($shifts as $sh): ?>
                    <option value="<?= $sh['id'] ?>">
                        <?= htmlspecialchars($sh['shift_name']) ?> (<?= date('h:i A', strtotime($sh['start_time'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="popoverSiteSelect" class="form-select form-select-sm" style="font-size: 0.74rem;">
                <option value="">-- Choose Venue --</option>
                <?php foreach ($sites as $st): ?>
                    <option value="<?= $st['id'] ?>" data-client="<?= $st['client_id'] ?>">
                        <?= htmlspecialchars($st['company_name']) ?> - <?= htmlspecialchars($st['site_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="btn btn-primary btn-sm w-100 py-1 fw-bold" style="font-size: 0.74rem;" onclick="submitPopoverAssign()">
            <i class="bi bi-check-lg me-1"></i> <span id="popoverAssignBtnText">Deploy Staff</span>
        </button>
    </div>

    <!-- Shift Staff Group / Pool Section -->
    <div class="mb-2 pt-1.5 border-top">
        <label class="form-label mb-1 text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">
            <i class="bi bi-folder-symlink me-1 text-primary"></i>Shift Staff Pool / Group
        </label>
        <div class="input-group input-group-sm">
            <select id="popoverGroupSelect" class="form-select form-select-sm" style="font-size: 0.72rem;">
                <!-- Populated dynamically -->
            </select>
            <button type="button" class="btn btn-outline-primary btn-sm py-0.5 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="submitPopoverShiftGroup()">
                Move
            </button>
        </div>
    </div>

    <hr class="my-1.5">

    <!-- 1-Click Leave & Availability Actions -->
    <div class="d-flex flex-column gap-1">
        <button type="button" class="btn btn-warning btn-sm py-1 text-dark fw-bold" style="font-size: 0.72rem;" onclick="openLeaveModalFromPopover()">
            <i class="bi bi-calendar-event me-1"></i> 🏖️ 10 Days Leave / Chhutti...
        </button>
        <button type="button" class="btn btn-light btn-sm py-1 border text-dark fw-semibold" style="font-size: 0.72rem;" id="popoverToggleAvailBtn" onclick="submitPopoverToggleAvail()">
            <i class="bi bi-pause-circle me-1"></i> 1-Click Toggle Unavailable
        </button>
    </div>
</div>

<!-- 5. AUTHENTIC RIGHT-CLICK EXCEL CONTEXT MENU (CHANGE GROUP & QUICK ACTIONS) -->
<div id="excelRightClickMenu" class="excel-rightclick-menu">
    <div class="excel-rc-header" id="rcStaffTitle">Staff Name</div>
    
    <!-- Change Group Submenu -->
    <div class="excel-rc-has-submenu">
        <div class="excel-rc-item">
            <span><i class="bi bi-folder-symlink text-primary me-2"></i>Change Group</span>
            <i class="bi bi-chevron-right text-muted" style="font-size: 0.65rem;"></i>
        </div>
        <div class="excel-rc-submenu" id="rcGroupsSubmenu">
            <!-- Dynamic list of groups -->
        </div>
    </div>

    <div class="excel-rc-divider"></div>

    <div class="excel-rc-item" onclick="openPopoverFromRC()">
        <span><i class="bi bi-building text-info me-2"></i>Deploy to Client Venue...</span>
    </div>
    
    <div class="excel-rc-item" onclick="openLeaveFromRC()">
        <span><i class="bi bi-calendar-event text-warning me-2"></i>🏖️ 10 Days Leave / Chhutti</span>
    </div>

    <div class="excel-rc-divider"></div>

    <div class="excel-rc-item rc-danger" onclick="toggleAvailFromRC()">
        <span id="rcToggleAvailText"><i class="bi bi-pause-circle me-2"></i>Toggle Availability</span>
    </div>
</div>

<!-- 5B. GROUP COLUMN HEADER CONTEXT MENU (RENAME & DELETE) -->
<div id="groupHeaderContextMenu" class="excel-rightclick-menu" style="display: none; position: fixed; z-index: 1070; min-width: 175px;">
    <div class="excel-rc-header" id="ghMenuTitle">GROUP NAME</div>
    <div class="excel-rc-item" onclick="handleGhSelectAll()">
        <i class="bi bi-check2-all text-primary me-2"></i><span>Select All Ready</span>
    </div>
    <div class="excel-rc-item" onclick="handleGhRename()">
        <i class="bi bi-pencil-square text-primary me-2"></i><span>Rename Group</span>
    </div>
    <div class="excel-rc-item rc-danger" id="ghMenuDeleteBtn" onclick="handleGhDelete()">
        <i class="bi bi-trash3 text-danger me-2"></i><span>Delete Group</span>
    </div>
    <div class="excel-rc-divider"></div>
    <div class="excel-rc-item" onclick="closeGhMenu(); openManageGroupsModal();">
        <i class="bi bi-collection text-muted me-2"></i><span>Manage All Groups...</span>
    </div>
</div>

<!-- 4. SMART MULTI-DAY LEAVE MODAL (E.G. 10 DINO KI CHHUTTI) -->
<div class="modal fade" id="staffLeaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark py-2.5">
                <h6 class="modal-title fw-bold mb-0">
                    <i class="bi bi-calendar2-range-fill me-2"></i>Staff Leave / Chhutti Manager
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="staffLeaveForm" onsubmit="handleLeaveFormSubmit(event)">
                <input type="hidden" id="leaveStaffId" name="staff_id">
                <input type="hidden" id="existingLeaveId" name="existing_leave_id">

                <div class="modal-body p-3">
                    <!-- Staff Header Card -->
                    <div class="d-flex align-items-center gap-2.5 p-2 bg-light rounded border mb-2.5">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold flex-shrink-0" id="leaveStaffInitial" style="width: 36px; height: 36px; font-size: 0.95rem;">
                            R
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-bold text-dark text-truncate" id="leaveStaffName">Staff Name</div>
                            <div class="d-flex align-items-center gap-2 text-muted" style="font-size: 0.72rem;">
                                <span id="leaveStaffCode">STF-001</span> &bull;
                                <span class="badge bg-secondary-subtle text-secondary" id="leaveStaffCategory">General</span>
                            </div>
                        </div>
                    </div>

                    <!-- Active Leave Notice (If Already on Leave) -->
                    <div id="activeLeaveAlert" class="alert alert-warning py-1.5 px-2.5 small mb-2.5 d-none">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <span id="activeLeaveAlertText">Staff is already on approved leave.</span>
                            </div>
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1.5" onclick="cancelCurrentActiveLeave()">
                                Cancel Leave
                            </button>
                        </div>
                    </div>

                    <!-- 1-Click Duration Presets -->
                    <div class="mb-2.5">
                        <label class="form-label small fw-bold text-uppercase text-dark mb-1" style="font-size: 0.68rem;">
                            ⚡ Quick Leave Presets (1-Click Duration)
                        </label>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2" onclick="setLeavePreset(1)">Today (1d)</button>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2" onclick="setLeavePreset(3)">3 Days</button>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2" onclick="setLeavePreset(7)">7 Days (1 Week)</button>
                            <button type="button" class="btn btn-warning btn-xs py-1 px-2.5 fw-bold shadow-2xs" onclick="setLeavePreset(10)">
                                🏖️ 10 Days (10 Dino ki Chhutti)
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2" onclick="setLeavePreset(15)">15 Days</button>
                        </div>
                    </div>

                    <!-- Date Range Inputs -->
                    <div class="row g-2 mb-2.5">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" style="font-size: 0.72rem;">Start Date *</label>
                            <input type="date" id="leaveStartDate" name="start_date" class="form-control form-control-sm" required onchange="calculateLeaveDuration()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" style="font-size: 0.72rem;">End Date *</label>
                            <input type="date" id="leaveEndDate" name="end_date" class="form-control form-control-sm" required onchange="calculateLeaveDuration()">
                        </div>
                        <div class="col-12">
                            <div class="text-primary small fw-bold" id="leaveDurationBadge" style="font-size: 0.74rem;">
                                Duration: 10 Day(s) Leave
                            </div>
                        </div>
                    </div>

                    <!-- Leave Type & Reason -->
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" style="font-size: 0.72rem;">Leave Category</label>
                        <select id="leaveTypeSelect" name="leave_type" class="form-select form-select-sm" style="font-size: 0.74rem;">
                            <option value="vacation">🏖️ Vacation / Holiday (Chhutti)</option>
                            <option value="casual">Casual Leave</option>
                            <option value="sick">Medical / Sick Leave</option>
                            <option value="emergency">Family / Emergency</option>
                            <option value="unavailable">Temporary Unavailable</option>
                        </select>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-semibold" style="font-size: 0.72rem;">Remarks / Note (Optional)</label>
                        <input type="text" id="leaveReasonInput" name="reason" class="form-control form-control-sm" placeholder="e.g. Village function, personal trip, exams..." style="font-size: 0.74rem;">
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-xs btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning fw-bold px-3" id="btnSaveLeave">
                        <i class="bi bi-check2-circle me-1"></i> Save Staff Leave
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 4. FLOATING ACTION BAR FOR EXCEL MULTI-STAFF SELECTION & BATCH DEPLOYMENT -->
<div class="matrix-floating-bar" id="matrixFloatingBar">
    <div class="selected-count-badge">
        <i class="bi bi-check2-circle"></i> <span id="floatingSelectedCount">0</span> Selected
    </div>
    <button type="button" class="btn-clear-selection" onclick="clearStaffSelection()" title="Clear selection">
        <i class="bi bi-x-lg me-1"></i> Clear
    </button>
    <button type="button" class="btn-deploy-batch" onclick="openBatchDeployModal()" title="Deploy Selected Staff to Client Venue">
        <i class="bi bi-rocket-takeoff-fill"></i> Deploy to Venue
    </button>
</div>

<!-- 5. SEARCHABLE CLIENT VENUE SELECTION MODAL -->
<div class="modal fade" id="batchDeployModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-2.5 px-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-building-check fs-5"></i>
                    <div>
                        <h6 class="modal-title fw-bold mb-0">Deploy Selected Staff</h6>
                        <small class="text-white-50" style="font-size: 0.68rem;">Map multiple staff to client venue & shift</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Selected Staff Preview Badges -->
                <div class="mb-2.5">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label text-uppercase text-muted fw-bold mb-0" style="font-size: 0.65rem;">
                            Selected Staff (<span id="batchModalStaffCount">0</span>)
                        </label>
                        <span class="text-primary small fw-semibold" style="font-size: 0.65rem;">Ready to Deploy</span>
                    </div>
                    <div class="d-flex flex-wrap gap-1 p-2 bg-light border rounded" id="batchModalStaffChips" style="max-height: 80px; overflow-y: auto;">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Shift Selector -->
                <div class="mb-2.5">
                    <label class="form-label text-uppercase text-muted fw-bold mb-1" style="font-size: 0.65rem;">Select Shift *</label>
                    <select id="batchModalShiftSelect" class="form-select form-select-sm" style="font-size: 0.78rem;">
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $selectedShiftId == $sh['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sh['shift_name']) ?> (<?= date('h:i A', strtotime($sh['start_time'])) ?> - <?= date('h:i A', strtotime($sh['end_time'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Venue Search & Quick Add Header -->
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label text-uppercase text-muted fw-bold mb-0" style="font-size: 0.65rem;">
                            Choose Target Client Venue *
                        </label>
                        <button type="button" class="btn btn-xs btn-outline-primary py-0.5 px-2 fw-bold d-flex align-items-center gap-1 shadow-2xs" id="btnToggleQuickAddVenue" onclick="toggleQuickClientVenueForm()" style="font-size: 0.70rem;">
                            <i class="bi bi-plus-circle-fill"></i>
                            <span id="quickAddVenueBtnText">+ Quick Add Client & Venue</span>
                        </button>
                    </div>

                    <!-- Collapsible Quick Add Client & Venue Form Card -->
                    <div id="quickClientVenueCollapse" class="card border border-primary-subtle bg-primary-subtle bg-opacity-10 shadow-2xs mb-2 p-2.5 rounded-3 d-none">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-1.5 mb-2">
                            <span class="fw-bold text-dark small" style="font-size: 0.76rem;">
                                <i class="bi bi-building-add text-primary me-1"></i> Quick Add Client & Venue / Site
                            </span>
                            <button type="button" class="btn-close" style="font-size: 0.60rem;" onclick="toggleQuickClientVenueForm(false)"></button>
                        </div>

                        <!-- Client Selection / New Client Toggle -->
                        <div class="mb-2">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label small fw-bold text-muted mb-0" style="font-size: 0.68rem;">Client / Parent Company *</label>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="checkbox" id="quickIsNewClient" onchange="toggleQuickNewClientInput(this.checked)" style="cursor: pointer;">
                                    <label class="form-check-label text-primary fw-semibold" for="quickIsNewClient" style="font-size: 0.68rem; cursor: pointer;">
                                        + New Client
                                    </label>
                                </div>
                            </div>
                            <!-- Existing Client Dropdown -->
                            <div id="quickExistingClientWrapper">
                                <select id="quickSelectClient" class="form-select form-select-sm" style="font-size: 0.75rem;">
                                    <option value="">-- Choose Existing Client --</option>
                                    <?php foreach ($clients as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['company_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- New Client Input (shown when checkbox checked) -->
                            <div id="quickNewClientWrapper" class="d-none">
                                <input type="text" id="quickNewClientName" class="form-control form-control-sm" placeholder="e.g. Hotel Maurya / Rahul Caterer" style="font-size: 0.75rem;">
                            </div>
                        </div>

                        <!-- Venue Name & Rate -->
                        <div class="row g-2 mb-2">
                            <div class="col-8">
                                <label class="form-label small fw-bold text-muted mb-0.5" style="font-size: 0.68rem;">Venue / Site Name *</label>
                                <input type="text" id="quickSiteName" class="form-control form-control-sm" placeholder="e.g. Main Banquet Hall / Lawn" style="font-size: 0.75rem;">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted mb-0.5" style="font-size: 0.68rem;">Client Rate (₹)</label>
                                <input type="number" id="quickClientRate" class="form-control form-control-sm" placeholder="850" value="850" style="font-size: 0.75rem;">
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted mb-0.5" style="font-size: 0.68rem;">Location / Address</label>
                            <input type="text" id="quickSiteAddress" class="form-control form-control-sm" placeholder="e.g. Patna, Bihar" value="Patna, Bihar" style="font-size: 0.75rem;">
                        </div>

                        <!-- Action buttons -->
                        <div class="d-flex align-items-center justify-content-end gap-1.5 pt-1 border-top">
                            <button type="button" class="btn btn-xs btn-light border text-secondary py-1 px-2.5" onclick="toggleQuickClientVenueForm(false)">
                                Cancel
                            </button>
                            <button type="button" class="btn btn-xs btn-primary fw-bold py-1 px-3 d-flex align-items-center gap-1 shadow-2xs" id="btnSubmitQuickClientVenue" onclick="submitQuickAddClientSite()">
                                <i class="bi bi-check2-circle"></i> Save & Select Venue
                            </button>
                        </div>
                    </div>

                    <!-- Venue Search Input -->
                    <div class="position-relative mb-2">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2.5 text-muted" style="font-size: 0.75rem;"></i>
                        <input type="text" id="modalVenueSearch" class="form-control form-control-sm ps-4 pe-4 py-1" placeholder="Search by hotel, hall, company..." oninput="filterModalVenues()" style="font-size: 0.76rem; border-radius: 6px;">
                        <button type="button" class="btn btn-link text-muted position-absolute top-50 end-0 translate-middle-y me-1 p-0 d-none" id="clearModalVenueSearchBtn" onclick="clearModalVenueSearch()" style="font-size: 0.75rem; text-decoration: none;">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <!-- Scrollable Venue Selection Cards -->
                <div class="venue-select-list" id="modalVenueList" style="max-height: 240px; overflow-y: auto;">
                    <!-- Rendered by JS -->
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3">
                <button type="button" class="btn btn-xs btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-success fw-bold px-3 d-flex align-items-center gap-1.5" id="btnSubmitBatchDeploy" onclick="submitBatchDeploy()" disabled>
                    <i class="bi bi-check2-circle"></i> Deploy <span id="batchBtnCount">0</span> Staff Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MANAGE STAFF GROUPS / POOLS MODAL -->
<div class="modal fade" id="manageGroupsModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-dark text-white py-2.5 px-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-collection-fill text-warning"></i>
                    <h6 class="modal-title fw-bold mb-0" style="font-size: 0.90rem;">Staff Pools & Groups Management</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2.5">
                    <span class="text-muted small fw-semibold" style="font-size: 0.76rem;">All active workforce pools configured in the sheet:</span>
                    <button type="button" class="btn btn-xs btn-outline-danger px-2 py-1 fw-bold" onclick="promptAddNewGroup();" style="font-size: 0.72rem;">
                        <i class="bi bi-plus-lg me-1"></i> Add Pool
                    </button>
                </div>
                <div class="table-responsive border rounded" style="max-height: 340px; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.78rem;">
                        <thead class="table-light text-muted sticky-top">
                            <tr>
                                <th class="ps-3 py-1.5">Group / Pool Name</th>
                                <th class="text-center py-1.5" style="width: 80px;">Staff</th>
                                <th class="text-end pe-3 py-1.5" style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="manageGroupsTableBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
                <div class="mt-2 text-muted small" style="font-size: 0.70rem;">
                    <i class="bi bi-info-circle me-1"></i> Deleting a group will safely reassign its members to the active staff pool.
                </div>
            </div>
            <div class="modal-footer bg-light border-0 py-2 px-3">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- QUICK ADD STAFF MODAL (TRIGGERED FROM BLANK EXCEL CELL CLICK) -->
<div class="modal fade" id="quickAddStaffModal" tabindex="-1" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-primary text-white py-2 px-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-plus-fill fs-5"></i>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" style="font-size: 0.90rem;">Quick Add Staff Member</h6>
                        <small class="text-white-50" style="font-size: 0.68rem;">Instantly add staff to column group</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddStaffForm" onsubmit="submitQuickAddStaff(event)">
                <input type="hidden" id="quickStaffGroupInput" name="staff_group">
                <div class="modal-body p-3">
                    <!-- Target Group Display Banner -->
                    <div class="d-flex align-items-center justify-content-between p-2 bg-light border rounded-3 mb-2.5">
                        <span class="text-muted small fw-semibold" style="font-size: 0.74rem;">Target Column Group:</span>
                        <span class="badge bg-danger text-white px-2.5 py-1 fw-bold font-monospace" id="quickStaffGroupBadge" style="font-size: 0.78rem;">
                            PATNA BOY
                        </span>
                    </div>

                    <!-- Staff Full Name -->
                    <div class="mb-2.5">
                        <label class="form-label small fw-bold text-dark mb-1" style="font-size: 0.72rem;">Full Name *</label>
                        <input type="text" id="quickStaffNameInput" class="form-control form-control-sm" placeholder="e.g. Rahul Kumar, Ajay Kumar" required style="font-size: 0.80rem;">
                    </div>

                    <!-- Phone Number (10 digits) -->
                    <div class="mb-2.5">
                        <label class="form-label small fw-bold text-dark mb-1" style="font-size: 0.72rem;">Mobile Phone (10 Digits) *</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted" style="font-size: 0.74rem;">+91</span>
                            <input type="tel" id="quickStaffPhoneInput" class="form-control form-control-sm" placeholder="9876543210" maxlength="10" required style="font-size: 0.80rem;">
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.65rem;">Unique mobile number required for ledger & attendance</div>
                    </div>

                    <!-- Category / Role Dropdown -->
                    <div class="mb-2.5">
                        <label class="form-label small fw-bold text-dark mb-1" style="font-size: 0.72rem;">Category / Role</label>
                        <select id="quickStaffCategorySelect" class="form-select form-select-sm" style="font-size: 0.78rem;">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['category_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Daily Payout Rate -->
                    <div class="mb-1">
                        <label class="form-label small fw-bold text-dark mb-1" style="font-size: 0.72rem;">Shift Payout Rate (₹/shift)</label>
                        <div class="input-group input-group-sm" style="max-width: 180px;">
                            <span class="input-group-text bg-light text-muted" style="font-size: 0.74rem;">₹</span>
                            <input type="number" id="quickStaffRateInput" class="form-control form-control-sm font-monospace fw-bold" value="500" min="0" step="50" style="font-size: 0.80rem;">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-3 border-0">
                    <button type="button" class="btn btn-xs btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3 d-flex align-items-center gap-1.5" id="btnSubmitQuickStaff">
                        <i class="bi bi-person-check-fill"></i> Add to Group Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Custom Modals & Toasts are loaded globally from templates/footer.php -->

<!-- TOAST CONTAINER FOR REAL-TIME FEEDBACK -->
<div class="toast-container matrix-toast-container">
    <div id="matrixToast" class="toast align-items-center text-white border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-semibold py-2 px-3" id="matrixToastBody" style="font-size: 0.78rem;">
                Action completed.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- JAVASCRIPT ENGINE FOR EXCEL MATRIX & 15% CLIENT ACCORDION -->
<script>
let currentMatrixDate = '<?= $selectedDate ?>';
let currentMatrixShiftId = <?= $selectedShiftId ?>;
let matrixData = { staff: [], sites: [], shifts: [], groups: [] };
let activeGroupsList = [];
let activeLeaveModal = null;
let activeBatchDeployModal = null;
let multiSelectMode = true; // Enabled by default per user request
let selectedStaffIds = new Set();
let selectedVenueSiteId = null;
let selectedVenueClientId = null;
let draggedStaffId = null;
let draggedSourceGroup = null;
let activePopoverStaffId = null;
let rcActiveStaffId = null;
let expandedSiteId = null;

document.addEventListener('DOMContentLoaded', () => {
    activeLeaveModal = new bootstrap.Modal(document.getElementById('staffLeaveModal'));
    activeBatchDeployModal = new bootstrap.Modal(document.getElementById('batchDeployModal'));
    
    // Ensure canvas has multi-select styling active
    const canvas = document.getElementById('excelMatrixCanvas');
    if (canvas) canvas.classList.add('multi-select-active');

    // Close popover, right-click context menu, and group header menu when clicking outside
    document.addEventListener('click', (e) => {
        const popover = document.getElementById('cellActionPopover');
        if (popover && !popover.contains(e.target) && !e.target.closest('.excel-staff-cell')) {
            closeCellPopover();
        }
        const rcMenu = document.getElementById('excelRightClickMenu');
        if (rcMenu && !rcMenu.contains(e.target)) {
            closeContextMenu();
        }
        const ghMenu = document.getElementById('groupHeaderContextMenu');
        if (ghMenu && !ghMenu.contains(e.target) && !e.target.closest('.group-header-menu-btn')) {
            closeGhMenu();
        }

        // When Multi-Select mode is OFF and user clicks outside cells or actions, deselect cells (Excel-like)
        if (!multiSelectMode && selectedStaffIds.size > 0) {
            if (!e.target.closest('.excel-staff-cell') && 
                !e.target.closest('#matrixFloatingBar') && 
                !e.target.closest('.modal') && 
                !e.target.closest('#toggleMultiSelectBtn') &&
                !e.target.closest('.group-header-menu-btn') &&
                !e.target.closest('.btn-batch-deploy')) {
                clearStaffSelection();
            }
        }
    });

    // Global keyboard shortcuts (F: activate search filter, Escape: blur/clear)
    document.addEventListener('keydown', (e) => {
        const activeEl = document.activeElement;
        const isInput = activeEl && (
            activeEl.tagName === 'INPUT' || 
            activeEl.tagName === 'TEXTAREA' || 
            activeEl.tagName === 'SELECT' || 
            activeEl.isContentEditable
        );

        // Press 'F' or 'f' to immediately focus & select filter/search bar (unless already typing in another input)
        if ((e.key === 'f' || e.key === 'F') && !e.altKey) {
            if (!isInput || e.ctrlKey || e.metaKey) {
                e.preventDefault();
                const searchInput = document.getElementById('staffFilterSearch');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
                return;
            }
        }

        // Close menus or clear selection on Escape key
        if (e.key === 'Escape') {
            closeContextMenu();
            closeCellPopover();
            closeGhMenu();
            const searchInput = document.getElementById('staffFilterSearch');
            if (activeEl === searchInput) {
                searchInput.blur();
            }
            if (selectedStaffIds.size > 0) {
                clearStaffSelection();
            }
        }
    });

    // Global drag safety net: ensures no stuck outline on abnormal drag abort or quick mouse release
    window.addEventListener('dragend', clearAllDragStates);
    window.addEventListener('mouseup', () => {
        clearAllDragStates();
        if (isMouseDraggingSelection) {
            isMouseDraggingSelection = false;
            updateFloatingBar();
        }
    });
    window.addEventListener('pointerup', clearAllDragStates);

    updateSidebarToggleText();
    loadMatrixData();
    initMatrixDatePicker();
});

window.addEventListener('load', () => {
    initMatrixDatePicker();
});

// Group Header Floating Context Menu Controller
let selectedGhGroupName = null;
let selectedGhCount = 0;

function openGroupHeaderMenu(e, grpName, count) {
    e.preventDefault();
    e.stopPropagation();
    closeCellPopover();
    closeContextMenu();

    selectedGhGroupName = grpName;
    selectedGhCount = count;

    const menu = document.getElementById('groupHeaderContextMenu');
    document.getElementById('ghMenuTitle').innerText = grpName;
    
    const delBtn = document.getElementById('ghMenuDeleteBtn');
    if (delBtn) {
        delBtn.style.display = (grpName.toUpperCase() === 'GENERAL POOL') ? 'none' : 'flex';
    }

    menu.style.display = 'block';
    const rect = e.currentTarget.getBoundingClientRect();
    menu.style.top = `${rect.bottom + 4}px`;
    menu.style.left = `${Math.min(rect.left, window.innerWidth - 185)}px`;
}

function closeGhMenu() {
    const menu = document.getElementById('groupHeaderContextMenu');
    if (menu) menu.style.display = 'none';
}

function handleGhSelectAll() {
    const grp = selectedGhGroupName;
    closeGhMenu();
    if (!grp || !matrixData || !matrixData.staff) return;

    const groupStaff = matrixData.staff.filter(s => {
        const sg = s.group || 'GENERAL POOL';
        return sg.toUpperCase() === grp.toUpperCase() && !s.is_on_leave && !s.is_deployed;
    });

    if (groupStaff.length === 0) {
        showToast(`No ready staff to select in ${grp}.`, 'warning');
        return;
    }

    const allSelected = groupStaff.every(s => selectedStaffIds.has(s.id));
    if (allSelected) {
        groupStaff.forEach(s => unselectStaff(s.id));
        showToast(`Deselected ready staff in ${grp}`, 'info');
    } else {
        groupStaff.forEach(s => selectStaff(s.id));
        showToast(`Selected ${groupStaff.length} ready staff in ${grp}`, 'success');
    }
    updateFloatingBar();
}

function handleGhHeaderClick(e, grpName) {
    if (e.ctrlKey || e.metaKey || multiSelectMode) {
        e.preventDefault();
        e.stopPropagation();
        selectedGhGroupName = grpName;
        handleGhSelectAll();
    }
}

function handleGhRename() {
    const target = selectedGhGroupName;
    closeGhMenu();
    if (target) {
        promptRenameGroup(target);
    }
}

function handleGhDelete() {
    const target = selectedGhGroupName;
    const cnt = selectedGhCount;
    closeGhMenu();
    if (target) {
        confirmDeleteGroup(target, cnt);
    }
}

// Custom Enterprise Confirm Popup (replaces native window.confirm)
function customConfirm(opts) {
    if (typeof window.customConfirm === 'function') {
        return window.customConfirm(opts);
    }
    return Promise.resolve(confirm(opts.message || 'Are you sure?'));
}

// Custom Enterprise Prompt Popup (replaces native window.prompt)
function customPrompt(opts) {
    if (typeof window.customPrompt === 'function') {
        return window.customPrompt(opts);
    }
    return Promise.resolve(prompt(opts.label || 'Enter value:', opts.defaultValue || ''));
}

// 1. Sidebar Toggle Feature
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

// 2. Fetch Matrix Data via AJAX
function loadMatrixData() {
    const url = `<?= BASE_URL ?>/modules/mapping/ajax_matrix.php?action=get_matrix_data&date=${currentMatrixDate}&shift_id=${currentMatrixShiftId}`;
    
    fetch(url)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                showToast(res.message || 'Error loading matrix data.', 'danger');
                return;
            }
            matrixData = res;
            if (res.groups && res.groups.length > 0) {
                activeGroupsList = res.groups;
            }
            updateKpiCounters(res.counts);
            renderExcelMatrix(res.staff);
            renderClientAccordion(res.sites, res.staff);
        })
        .catch(err => {
            console.error('Matrix load error:', err);
            showToast('Network error loading matrix.', 'danger');
        });
}

// 3. Update Top KPI Badges
function updateKpiCounters(counts) {
    if (!counts) return;
    const total = counts.total || 0;
    const ready = counts.ready || 0;
    const deployed = counts.deployed || 0;
    const ongoing = counts.ongoing || counts.present || 0;
    const completed = counts.completed || 0;
    const multiShift = counts.multi_shift || 0;
    const leave = counts.on_leave || 0;

    const elTotal = document.getElementById('kpiTotalStaff');
    if (elTotal) elTotal.innerText = total;

    const elReady = document.getElementById('kpiReadyStaff');
    if (elReady) elReady.innerText = ready;

    const elDeployed = document.getElementById('kpiDeployedStaff');
    if (elDeployed) elDeployed.innerText = deployed;

    const elPresent = document.getElementById('kpiPresentStaff');
    if (elPresent) elPresent.innerText = ongoing;

    const elCompleted = document.getElementById('kpiCompletedStaff');
    if (elCompleted) elCompleted.innerText = completed;

    const elMultiShift = document.getElementById('kpiMultiShiftStaff');
    if (elMultiShift) elMultiShift.innerText = multiShift;

    const elLeave = document.getElementById('kpiLeaveStaff');
    if (elLeave) elLeave.innerText = leave;

    // Update Mobile Tab Badges
    const elMobStaff = document.getElementById('mobStaffCountBadge');
    if (elMobStaff) elMobStaff.innerText = total;
    const elMobVenue = document.getElementById('mobVenueCountBadge');
    if (elMobVenue && matrixData.sites) elMobVenue.innerText = matrixData.sites.length;
}

// Populate Group Options in Popover
function populateGroupOptions(selectedGroup = '') {
    const popSelect = document.getElementById('popoverGroupSelect');
    if (!popSelect) return;
    let optHtml = '';
    (activeGroupsList || []).forEach(g => {
        if (g.group_name.toUpperCase() === 'GENERAL POOL') return;
        const isSel = (g.group_name.toUpperCase() === (selectedGroup || '').toUpperCase());
        optHtml += `<option value="${escapeHtml(g.group_name)}" ${isSel ? 'selected' : ''}>${escapeHtml(g.group_name)}</option>`;
    });
    popSelect.innerHTML = optHtml;
}

// 4. Render Authentic Multi-Column Excel Spreadsheet Grid (Partitioned by Group Pools)
function renderExcelMatrix(staffList) {
    const theadRow = document.getElementById('excelMatrixHeaderRow');
    const tbody = document.getElementById('excelMatrixBody');

    // Filter out GENERAL POOL from active groups
    activeGroupsList = (activeGroupsList || []).filter(g => g.group_name.toUpperCase() !== 'GENERAL POOL');

    // Groups fallback
    if (!activeGroupsList || activeGroupsList.length === 0) {
        activeGroupsList = [
            { group_name: 'MAURYA REGULAR' },
            { group_name: 'PATNA BOY' },
            { group_name: 'VILLAGE BOY' },
            { group_name: 'PATNA EMERGENCY' },
            { group_name: 'NEW BOY LIST' },
            { group_name: 'UT TEAM' },
            { group_name: 'NEW BOY TEAM' },
            { group_name: 'UMRAO SPECIAL' },
            { group_name: 'WHITE PALACE SPECIAL' },
            { group_name: 'MAURYA HOLDING BOYS' },
            { group_name: 'UT HOLDING TEAM' },
            { group_name: 'U.T. MAURYA TEAM' },
            { group_name: 'VILLAGE EMERGENCY' },
            { group_name: 'PATNA HOLDING BOYS' },
            { group_name: 'VILLAGE HOLDING BOYS' }
        ];
    }

    // Filter staff according to live search filter if active
    const searchInput = document.getElementById('staffFilterSearch');
    const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
    let displayList = staffList || [];
    if (q) {
        displayList = displayList.filter(s => 
            (s.name && s.name.toLowerCase().includes(q)) || 
            (s.code && s.code.toLowerCase().includes(q)) || 
            (s.phone && s.phone.toLowerCase().includes(q)) ||
            (s.category && s.category.toLowerCase().includes(q)) ||
            (s.group && s.group.toLowerCase().includes(q))
        );
    }

    // Update Live Search Match Count Badges & Status Notice
    const countBadge = document.getElementById('searchMatchCountBadge');
    const countNum = document.getElementById('searchMatchCountNum');
    const totalStaffCount = staffList ? staffList.length : 0;
    const staffBadge = document.getElementById('excelStaffCountBadge');
    const statusNotice = document.getElementById('excelSearchStatusNotice');

    if (q) {
        if (countBadge && countNum) {
            countBadge.classList.remove('d-none');
            countBadge.classList.add('d-inline-flex');
            countNum.innerText = displayList.length;
            if (displayList.length === 0) {
                countBadge.className = 'badge bg-danger text-white rounded-2 px-2.5 py-1 shadow-2xs fw-bold text-nowrap d-inline-flex align-items-center gap-1.5';
            } else {
                countBadge.className = 'badge bg-primary text-white rounded-2 px-2.5 py-1 shadow-2xs fw-bold text-nowrap d-inline-flex align-items-center gap-1.5';
            }
        }
        if (staffBadge) {
            staffBadge.innerHTML = `<i class="bi bi-search text-warning"></i> <span>${displayList.length}</span> Matches`;
        }
        if (statusNotice) {
            statusNotice.innerHTML = `<span class="text-primary fw-semibold"><i class="bi bi-funnel-fill me-1"></i>Found <strong>${displayList.length}</strong> staff matching "<strong>${escapeHtml(q)}</strong>" in 1 place. <a href="javascript:void(0)" onclick="clearStaffSearch()" class="ms-1 text-danger fw-bold text-decoration-underline">Clear Search (Show All)</a></span>`;
        }
    } else {
        if (countBadge) {
            countBadge.classList.remove('d-inline-flex');
            countBadge.classList.add('d-none');
        }
        if (staffBadge) {
            staffBadge.innerHTML = `<i class="bi bi-people-fill text-info"></i> <span id="kpiTotalStaff">${totalStaffCount}</span> Staff`;
        }
        if (statusNotice) {
            statusNotice.innerHTML = `<i class="bi bi-info-circle text-primary me-1"></i> Drag staff to right to deploy &bull; Drag across columns to shift group`;
        }
    }

    // Empty state if query returned 0 matches
    if (q && displayList.length === 0) {
        theadRow.innerHTML = `
            <th class="excel-group-th text-center py-2" style="background:#fee2e2 !important; color:#991b1b !important;">
                <i class="bi bi-search me-1"></i> 0 Matches Found
            </th>
        `;
        tbody.innerHTML = `
            <tr>
                <td class="text-center py-5 bg-white">
                    <div class="text-danger mb-2" style="font-size: 2.2rem;"><i class="bi bi-person-x-fill"></i></div>
                    <h6 class="fw-bold text-dark mb-1">No staff members found matching "${escapeHtml(q)}"</h6>
                    <p class="text-muted small mb-3">0 staff found out of ${totalStaffCount} total staff. Check spelling or try a different keyword.</p>
                    <button type="button" class="btn btn-sm btn-outline-primary px-3 py-1.5 fw-semibold rounded-2" onclick="clearStaffSearch()">
                        <i class="bi bi-x-circle me-1"></i> Clear Search Filter (Show All)
                    </button>
                </td>
            </tr>
        `;
        return;
    }

    // Map staff into groups (excluding GENERAL POOL)
    let activeGroupNames = activeGroupsList
        .map(g => g.group_name.toUpperCase())
        .filter(g => g !== 'GENERAL POOL');

    const groupsMap = {};
    activeGroupNames.forEach(gn => {
        groupsMap[gn] = [];
    });

    displayList.forEach(stf => {
        const grp = (stf.group || '').toUpperCase();
        if (grp && grp !== 'GENERAL POOL') {
            if (!groupsMap[grp]) {
                groupsMap[grp] = [];
                activeGroupNames.push(grp);
            }
            groupsMap[grp].push(stf);
        }
    });

    // When searching: ONLY show groups that have matching staff (hide all 0-count columns so user never has to slide horizontally!)
    if (q) {
        activeGroupNames = activeGroupNames.filter(gn => (groupsMap[gn] || []).length > 0);
    }

    // Generate Excel Header row
    let thHtml = '';

    // If searching, add unified Column 0: "🎯 ALL SEARCH RESULTS (${displayList.length})"
    if (q) {
        thHtml += `
            <th class="excel-group-th" 
                style="background: #eef2ff !important; border-bottom: 2.5px solid #4f46e5 !important; min-width: 195px;" 
                title="All ${displayList.length} search matches in 1 place. Click 'Select All' to select them all at once.">
                <div class="d-flex align-items-center justify-content-between px-1 flex-nowrap">
                    <div class="d-flex align-items-center gap-1 cursor-pointer" onclick="selectAllSearchMatches()" style="cursor: pointer;" title="Click to select/deselect all search matches">
                        <i class="bi bi-search text-primary fw-bold" style="font-size:0.75rem;"></i>
                        <span class="fw-bold text-primary" style="font-size: 0.74rem; letter-spacing: 0.3px;">ALL SEARCH RESULTS</span>
                        <span class="badge bg-primary text-white rounded-pill px-1.5 py-0.5" style="font-size: 0.65rem;">${displayList.length}</span>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1.5 fw-bold rounded-1" onclick="selectAllSearchMatches()" title="Select/Deselect All Matches" style="font-size: 0.62rem; height: 20px;">
                        Select All
                    </button>
                </div>
            </th>
        `;
    }

    activeGroupNames.forEach(grpName => {
        const count = (groupsMap[grpName] || []).length;
        thHtml += `
            <th class="excel-group-th" 
                data-group="${escapeHtml(grpName)}" 
                title="Group: ${escapeHtml(grpName)} (${count} Staff) - Click title or 3-dots to Select All"
                ondragover="handleGroupDragOver(event)" 
                ondragleave="handleGroupDragLeave(event)" 
                ondrop="handleGroupDrop(event, '${escapeHtml(grpName)}')">
                <div class="d-flex align-items-center justify-content-center gap-1 flex-nowrap position-relative">
                    <span class="group-title-text" onclick="handleGhHeaderClick(event, '${escapeHtml(grpName)}')" style="cursor:pointer;" title="Click in Multi-Select mode (or Ctrl+Click) to select all ready staff in this group">${escapeHtml(grpName)}</span>
                    <span class="group-count-pill" onclick="handleGhHeaderClick(event, '${escapeHtml(grpName)}')" style="cursor:pointer;" title="Click in Multi-Select mode to select ready staff">${count}</span>
                    <button class="btn btn-link p-0 group-header-menu-btn" type="button" title="Group Options: Select All Ready, Rename or Delete" onclick="openGroupHeaderMenu(event, '${escapeHtml(grpName)}', ${count})">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                </div>
            </th>
        `;
    });
    theadRow.innerHTML = thHtml;

    // Calculate row count needed
    let maxStaffInAnyGroup = 0;
    activeGroupNames.forEach(grpName => {
        const len = groupsMap[grpName].length;
        if (len > maxStaffInAnyGroup) maxStaffInAnyGroup = len;
    });

    const maxRowsRequired = q ? Math.max(displayList.length, maxStaffInAnyGroup) : maxStaffInAnyGroup;
    const totalRows = Math.max(maxRowsRequired + (q ? 1 : 2), (q ? 8 : 14));

    // Build rows
    let rowsHtml = '';
    for (let r = 0; r < totalRows; r++) {
        rowsHtml += '<tr>';
        
        // If searching: Render Column 0 (Unified All Search Matches)
        if (q) {
            if (r < displayList.length) {
                const stf = displayList[r];
                rowsHtml += renderStaffCellHtml(stf, stf.group || 'GENERAL POOL', true);
            } else {
                rowsHtml += `<td class="excel-empty-cell" style="background: #fafafa;"></td>`;
            }
        }

        for (let c = 0; c < activeGroupNames.length; c++) {
            const grpName = activeGroupNames[c];
            const grpStaff = groupsMap[grpName];
            if (r < grpStaff.length) {
                const stf = grpStaff[r];
                rowsHtml += renderStaffCellHtml(stf, grpName, false);
            } else {
                rowsHtml += `
                    <td class="excel-empty-cell" 
                        data-group="${escapeHtml(grpName)}"
                        title="Click to quick add staff to ${escapeHtml(grpName)}"
                        onclick="handleEmptyCellClick(event, '${escapeHtml(grpName)}')"
                        ondragover="handleGroupDragOver(event)" 
                        ondragleave="handleGroupDragLeave(event)" 
                        ondrop="handleGroupDrop(event, '${escapeHtml(grpName)}')">
                    </td>
                `;
            }
        }
        rowsHtml += '</tr>';
    }

    tbody.innerHTML = rowsHtml;
}

// Helper to render individual Excel staff cell
function renderStaffCellHtml(stf, grpName, isUnifiedSearchCol = false) {
    const isLeave = stf.is_on_leave;
    const isDeployed = stf.is_deployed;
    const isSelected = selectedStaffIds.has(stf.id);
    const targetGroup = grpName || stf.group || 'GENERAL POOL';
    const shiftCount = stf.shift_count || (stf.shifts ? stf.shifts.length : 0);

    let statusClass = 'status-available';
    let iconBadge = '';
    let cellTitle = '';
    let customStyle = '';

    if (isSelected) {
        statusClass += ' is-selected';
    }

    if (isLeave) {
        statusClass = 'status-leave';
        const leaveDays = stf.leave_info ? stf.leave_info.days : 1;
        iconBadge = `<span class="badge bg-danger-subtle text-danger py-0 px-1" style="font-size:0.6rem;">Leave ${leaveDays}d</span>`;
        cellTitle = `👤 ${stf.name} (${stf.category})\n🏖️ Status: On Leave (${leaveDays}d)\n👥 Group: ${targetGroup}\n(Right-Click to change group)`;
    } else if (shiftCount >= 2) {
        // Multi-shift: divided diagonally across shifts (Blue / Green / Yellow)
        statusClass = 'status-multi-shift';
        customStyle = (stf.gradient_css || '') + '; color: #ffffff !important;';
        iconBadge = `<span class="badge bg-black bg-opacity-75 text-white fw-bold py-0 px-1 border-0 shadow-2xs font-monospace" style="font-size:0.56rem;">${shiftCount}S</span>`;
        
        const shiftLines = (stf.shifts || []).map((sh, idx) => {
            const icon = (sh.state === 'completed') ? '🔵 Completed' : ((sh.state === 'ongoing') ? '🟢 Ongoing (Present)' : '🟡 Scheduled');
            return `${idx + 1}. [${icon}] ${sh.shift_name}: ${sh.site_name} (${sh.client_name})`;
        }).join('\n');

        cellTitle = `👤 Staff: ${stf.name} (${stf.category})\n👥 Group: ${targetGroup}\n━━━━━━━━━━━━━━━━━━━━━━\n📋 ${shiftCount} Shifts Assigned Today:\n${shiftLines}\n━━━━━━━━━━━━━━━━━━━━━━\n(Click to view/manage shifts • Right-Click to change group)`;
    } else if (shiftCount === 1) {
        const sh = (stf.shifts && stf.shifts[0]) ? stf.shifts[0] : (stf.deploy_info || {});
        const clientName = sh.client_name || 'Client';
        const siteName = sh.site_name || 'Site';
        const shiftName = sh.shift_name || 'Shift';

        if (sh.state === 'completed' || stf.status_type === 'completed') {
            statusClass = 'status-completed';
            customStyle = 'color: #ffffff !important;';
            iconBadge = `<span class="badge bg-white text-primary fw-bold py-0 px-1 border-0 shadow-2xs" style="font-size:0.56rem;">Completed</span>`;
            cellTitle = `👤 Staff: ${stf.name} (${stf.category})\n🔵 Status: Shift COMPLETED at ${siteName}\n🕒 Shift: ${shiftName}\n🏢 Client: ${clientName}\n👥 Group: ${targetGroup}\n(Click to view/manage • Right-Click to change group)`;
        } else if (sh.state === 'ongoing' || stf.status_type === 'ongoing' || stf.status_type === 'present') {
            statusClass = 'status-present';
            customStyle = 'color: #ffffff !important;';
            iconBadge = `<span class="badge bg-white text-success fw-bold py-0 px-1 border-0 shadow-2xs" style="font-size:0.56rem;">Present</span>`;
            cellTitle = `👤 Staff: ${stf.name} (${stf.category})\n🟢 Status: ONGOING (Present) at ${siteName}\n🕒 Shift: ${shiftName}\n🏢 Client: ${clientName}\n👥 Group: ${targetGroup}\n(Click to view/manage • Right-Click to change group)`;
        } else {
            statusClass = 'status-deployed';
            iconBadge = '';
            cellTitle = `👤 Staff: ${stf.name} (${stf.category})\n🟡 Status: SCHEDULED at ${siteName} (Pending Attendance)\n🕒 Shift: ${shiftName}\n🏢 Client: ${clientName}\n👥 Group: ${targetGroup}\n(Click to view/manage • Right-Click to change group)`;
        }
    } else {
        cellTitle = `👤 ${stf.name} (${stf.category})\n✅ Status: Ready / Available\n👥 Group: ${targetGroup}\n(Drag to client or another group)`;
    }

    const checkIcon = isSelected ? '<span class="selection-check-icon"><i class="bi bi-check-lg"></i></span>' : '';
    const cellId = isUnifiedSearchCol ? `search-cell-staff-${stf.id}` : `cell-staff-${stf.id}`;
    const groupPill = isUnifiedSearchCol ? `<span class="badge bg-dark bg-opacity-10 text-dark border px-1 ms-1 text-truncate" style="font-size:0.58rem; max-width:85px;" title="Group: ${escapeHtml(targetGroup)}">${escapeHtml(targetGroup)}</span>` : '';

    return `
        <td class="excel-staff-td" 
            data-group="${escapeHtml(targetGroup)}"
            ondragover="handleGroupDragOver(event)" 
            ondragleave="handleGroupDragLeave(event)" 
            ondrop="handleGroupDrop(event, '${escapeHtml(targetGroup)}')">
            <div class="excel-staff-cell ${statusClass} ${isUnifiedSearchCol ? 'excel-search-result-cell' : ''}" 
                 id="${cellId}"
                 style="${customStyle}"
                 data-staff-id="${stf.id}"
                 data-staff-group="${escapeHtml(targetGroup)}"
                 title="${escapeHtml(cellTitle)}"
                 draggable="${!isLeave && !multiSelectMode}"
                 ondragstart="handleCellDragStart(event, ${stf.id}, '${escapeHtml(targetGroup)}')"
                 ondragend="handleCellDragEnd(event)"
                 ondragover="event.preventDefault()"
                 onmousedown="handleStaffCellMouseDown(event, ${stf.id})"
                 onmouseenter="handleStaffCellMouseEnter(event, ${stf.id})"
                 onclick="handleStaffCellClick(event, ${stf.id})"
                 oncontextmenu="handleCellContextMenu(event, ${stf.id})">
                ${checkIcon}
                <span class="text-truncate flex-grow-1" style="color: inherit !important;">${escapeHtml(stf.name)}</span>
                ${groupPill}
                ${iconBadge}
            </div>
        </td>
    `;
}

// 5. Render Right 18% Client Venues Panel
function renderClientAccordion(sitesList, staffList) {
    const container = document.getElementById('clientCardsContainer');
    if (!container) return;
    const countBadge = document.getElementById('clientListCountBadge');
    if (countBadge) countBadge.innerText = `${sitesList ? sitesList.length : 0} Venues`;

    if (!sitesList || sitesList.length === 0) {
        container.innerHTML = `<div class="text-center py-4 text-muted small fst-italic">No client venues configured.</div>`;
        return;
    }

    let html = '';
    sitesList.forEach(site => {
        const isExpanded = (expandedSiteId === site.id);
        const deployedList = site.deployed_staff || [];
        const depCount = deployedList.length;
        const hasDeployed = depCount > 0;

        // Build list of mapped staff items inside expanded body
        const staffChips = deployedList.map(ds => {
            const sObj = staffList.find(s => s.id === ds.staff_id);
            const sName = sObj ? sObj.name : `Staff #${ds.staff_id}`;
            const sCat = sObj ? sObj.category : 'Staff';
            const isPres = (ds.att_status === 'present' || ds.att_status === 'half_day');
            const attBadge = isPres
                ? '<span class="badge bg-success text-white py-0 px-1 font-monospace" style="font-size:0.56rem;">Present</span>'
                : '<span class="badge bg-warning-subtle text-warning-emphasis py-0 px-1 font-monospace border border-warning-subtle" style="font-size:0.56rem;">Mapped</span>';
            return `
                <div class="client-staff-item ${isPres ? 'border-success-subtle bg-success-subtle bg-opacity-10' : ''}">
                    <div class="d-flex align-items-center gap-1.5 overflow-hidden">
                        <span class="staff-name">${escapeHtml(sName)}</span>
                        ${attBadge}
                    </div>
                    <button type="button" class="client-staff-unassign" onclick="unassignStaff(event, ${ds.deploy_id})" title="Unassign ${escapeHtml(sName)}">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            `;
        }).join('');

        html += `
            <div class="client-accordion-card ${hasDeployed ? 'has-deployed' : ''} ${isExpanded ? 'is-expanded' : ''}" 
                 id="client-card-${site.id}"
                 data-site-id="${site.id}"
                 data-client-id="${site.client_id}"
                 title="${escapeHtml(site.site_name)} (${escapeHtml(site.client_name)})"
                 ondragover="handleCardDragOver(event, ${site.id})"
                 ondragleave="handleCardDragLeave(event, ${site.id})"
                 ondrop="handleCardDrop(event, ${site.id}, ${site.client_id})">
                
                <!-- Card Header (Click to expand/collapse) -->
                <div class="client-card-header" onclick="toggleClientAccordion(${site.id})">
                    <div class="client-info-col overflow-hidden pe-1 flex-grow-1">
                        <div class="client-card-title text-truncate">${escapeHtml(site.site_name)}</div>
                        <div class="client-card-sub text-truncate">${escapeHtml(site.client_name)}</div>
                    </div>
                    <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                        <span class="client-count-pill ${hasDeployed ? 'active' : 'empty'}">
                            ${hasDeployed ? '<i class="bi bi-person-fill"></i> ' + depCount : '0'}
                        </span>
                        <i class="bi bi-chevron-down client-chevron"></i>
                    </div>
                </div>

                <!-- Accordion Body (Mapped Employees) -->
                <div class="client-accordion-body">
                    ${hasDeployed ? staffChips : '<div class="client-empty-drop-hint"><i class="bi bi-arrow-down-circle me-1"></i> Drop staff here to assign</div>'}
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// 6. Accordion Toggle
function toggleClientAccordion(siteId) {
    if (expandedSiteId === siteId) {
        expandedSiteId = null; // collapse
    } else {
        expandedSiteId = siteId; // expand this one
    }
    renderClientAccordion(matrixData.sites, matrixData.staff);
}

// Helper to create high-visibility floating drag preview badge
function createDragGhost(name, group, isBatch = false, count = 1) {
    let ghost = document.getElementById('matrixDragGhost');
    if (!ghost) {
        ghost = document.createElement('div');
        ghost.id = 'matrixDragGhost';
        ghost.style.position = 'fixed';
        ghost.style.top = '-1000px';
        ghost.style.left = '-1000px';
        ghost.style.zIndex = '999999';
        ghost.style.pointerEvents = 'none';
        ghost.style.background = '#2563eb';
        ghost.style.color = '#ffffff';
        ghost.style.padding = '5px 12px';
        ghost.style.borderRadius = '6px';
        ghost.style.fontSize = '12px';
        ghost.style.fontWeight = 'bold';
        ghost.style.boxShadow = '0 6px 16px rgba(37,99,235,0.4)';
        ghost.style.display = 'flex';
        ghost.style.alignItems = 'center';
        ghost.style.gap = '6px';
        document.body.appendChild(ghost);
    }
    if (isBatch && count > 1) {
        ghost.innerHTML = `<i class="bi bi-people-fill"></i> <span>Deploying ${count} Selected Staff</span> <small style="opacity:0.85;font-size:10px;">(${escapeHtml(name)} + ${count - 1} more)</small>`;
    } else {
        ghost.innerHTML = `<i class="bi bi-person-fill"></i> <span>${escapeHtml(name)}</span> <small style="opacity:0.85;font-size:10px;">[${escapeHtml(group)}]</small>`;
    }
    return ghost;
}

// 7. Drag & Drop: Staff cell can be dropped on:
// A) Right Client Card (to deploy to venue)
// B) Another Group Column / Cell (to shift staff group)
function handleCellDragStart(e, staffId, sourceGroup) {
    if (multiSelectMode) {
        e.preventDefault();
        return false;
    }
    draggedStaffId = staffId;
    draggedSourceGroup = sourceGroup;

    e.dataTransfer.effectAllowed = 'copyMove';
    e.dataTransfer.setData('text/plain', String(staffId));

    // Attach high-visibility drag ghost preview for laptops & high-DPI displays
    const staff = matrixData.staff ? matrixData.staff.find(s => s.id === staffId) : null;
    const staffName = staff ? staff.name : 'Staff';
    const isBatch = selectedStaffIds.size > 1 && selectedStaffIds.has(staffId);
    const ghost = createDragGhost(staffName, sourceGroup, isBatch, selectedStaffIds.size);
    if (e.dataTransfer.setDragImage) {
        e.dataTransfer.setDragImage(ghost, 25, 15);
    }

    // Defer cell style dimming to next animation frame so Chrome captures drag image FIRST
    requestAnimationFrame(() => {
        if (draggedStaffId === staffId) {
            const cell = document.getElementById(`cell-staff-${staffId}`);
            if (cell) cell.classList.add('dragging');
        }
    });

    closeCellPopover();
    closeContextMenu();
}

function handleCellDragEnd(e) {
    clearAllDragStates();
}

function clearAllDragStates() {
    draggedStaffId = null;
    draggedSourceGroup = null;
    document.querySelectorAll('.excel-staff-cell.dragging').forEach(c => c.classList.remove('dragging'));
    document.querySelectorAll('.client-accordion-card.drag-over').forEach(c => c.classList.remove('drag-over'));
    document.querySelectorAll('.drag-over-group, .drag-over-cell').forEach(el => el.classList.remove('drag-over-group', 'drag-over-cell'));
}

// Drag over right client card
function handleCardDragOver(e, siteId) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    const card = document.getElementById(`client-card-${siteId}`);
    if (card) card.classList.add('drag-over');
}

function handleCardDragLeave(e, siteId) {
    const card = document.getElementById(`client-card-${siteId}`);
    if (card && (!e.relatedTarget || !card.contains(e.relatedTarget))) {
        card.classList.remove('drag-over');
    }
}

// Drop on client card to deploy
function handleCardDrop(e, siteId, clientId) {
    e.preventDefault();
    document.querySelectorAll('.excel-staff-cell.dragging').forEach(c => c.classList.remove('dragging'));
    document.querySelectorAll('.client-accordion-card.drag-over').forEach(c => c.classList.remove('drag-over'));
    
    let staffId = draggedStaffId;
    const plainData = e.dataTransfer.getData('text/plain');
    if (plainData) {
        const parsed = parseInt(plainData, 10);
        if (!isNaN(parsed) && parsed > 0) staffId = parsed;
    }

    if (!staffId || !siteId) return;

    const shiftId = currentMatrixShiftId > 0 ? currentMatrixShiftId : (matrixData.shifts[0] ? matrixData.shifts[0].id : 1);
    
    // Batch deploy if multiple staff are selected and this dragged staff is part of the selection!
    if (selectedStaffIds.size > 0 && selectedStaffIds.has(staffId)) {
        assignBatchStaffDirect(Array.from(selectedStaffIds), clientId, siteId, shiftId);
    } else {
        assignStaffDirect(staffId, clientId, siteId, shiftId);
    }
}

// Drag over ANY group column header OR ANY cell (occupied or empty)
function handleGroupDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const target = e.currentTarget;
    if (target.tagName === 'TH') {
        target.classList.add('drag-over-group');
    } else {
        target.classList.add('drag-over-cell');
    }
}

function handleGroupDragLeave(e) {
    const target = e.currentTarget;
    if (e.relatedTarget && target.contains(e.relatedTarget)) {
        return;
    }
    if (target.tagName === 'TH') {
        target.classList.remove('drag-over-group');
    } else {
        target.classList.remove('drag-over-cell');
    }
}

// Drop on ANY element (header, occupied cell, or empty cell) in a group column to shift staff
function handleGroupDrop(e, targetGroup) {
    e.preventDefault();
    document.querySelectorAll('.excel-staff-cell.dragging').forEach(c => c.classList.remove('dragging'));
    document.querySelectorAll('.drag-over-group, .drag-over-cell').forEach(el => {
        el.classList.remove('drag-over-group', 'drag-over-cell');
    });
    
    let staffId = draggedStaffId;
    let sourceGroup = draggedSourceGroup;
    const plainData = e.dataTransfer.getData('text/plain');
    if (plainData) {
        const parsed = parseInt(plainData, 10);
        if (!isNaN(parsed) && parsed > 0) staffId = parsed;
    }

    if (!staffId || !targetGroup) return;

    if (sourceGroup && sourceGroup.toUpperCase() === targetGroup.toUpperCase()) {
        // Dropped in same group, no change needed
        return;
    }

    shiftStaffGroup(staffId, targetGroup);
}

// 8. Direct AJAX Assignment (Deploy to Venue)
function assignStaffDirect(staffId, clientId, siteId, shiftId, forceMove = 0) {
    const formData = new FormData();
    formData.append('action', 'assign_staff');
    formData.append('staff_id', staffId);
    formData.append('client_id', clientId);
    formData.append('site_id', siteId);
    formData.append('shift_id', shiftId);
    formData.append('date', currentMatrixDate);
    if (forceMove) {
        formData.append('force_move', '1');
    }

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(async res => {
        if (res.success) {
            showToast(res.message, 'success');
            expandedSiteId = siteId;
            loadMatrixData();
        } else if (res.conflict_type === 'warning_can_move') {
            // Staff is mapped to another venue, but NOT yet marked present
            const confirmed = await window.customConfirm({
                title: 'Re-Map Staff Venue?',
                message: `Staff <strong>${res.staff_name}</strong> pehle se <strong>${res.current_venue}</strong> par mapped hai.<br><br>Kya aap is staff ko dusre venue site (<strong>${res.target_venue}</strong>) par map karna chahte hain?`,
                confirmText: 'Yes, Map to ' + (res.target_site_name || 'New Venue'),
                cancelText: 'Cancel',
                btnClass: 'btn-warning text-dark',
                icon: 'bi-arrow-left-right',
                iconBg: '#fef3c7',
                iconColor: '#d97706'
            });

            if (confirmed) {
                assignStaffDirect(staffId, clientId, siteId, shiftId, 1);
            }
        } else if (res.conflict_type === 'already_present') {
            // Staff is ALREADY PRESENT! Show direct message as requested
            showToast(res.message, 'danger');
        } else {
            showToast(res.message || 'Deployment could not be completed.', 'danger');
        }
    })
    .catch(err => {
        showToast('Network error during deployment.', 'danger');
    });
}

// 8B. Direct AJAX Batch Assignment (Deploy Multiple Staff to Venue)
function assignBatchStaffDirect(staffIds, clientId, siteId, shiftId, forceMove = 0) {
    if (!staffIds || staffIds.length === 0 || !clientId || !siteId) return;

    showToast(`Deploying ${staffIds.length} staff to venue...`, 'info');

    const formData = new FormData();
    formData.append('action', 'assign_batch_staff');
    formData.append('staff_ids', staffIds.join(','));
    formData.append('client_id', clientId);
    formData.append('site_id', siteId);
    formData.append('shift_id', shiftId || 1);
    formData.append('date', currentMatrixDate);
    if (forceMove) {
        formData.append('force_move', '1');
    }

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            clearStaffSelection();
            expandedSiteId = siteId;
            loadMatrixData();
        } else {
            showToast(res.message || 'Batch deployment could not be completed.', 'danger');
        }
    })
    .catch(err => {
        showToast('Network error during batch deployment.', 'danger');
    });
}

// 9. Unassign Staff
async function unassignStaff(e, deployId) {
    if (e) e.stopPropagation();
    const confirmed = await customConfirm({
        title: 'Unassign Staff Member',
        message: 'Are you sure you want to unassign this staff member from this venue? They will be returned to their pool as ready.',
        confirmText: 'Unassign Staff',
        btnClass: 'btn-danger',
        icon: 'bi-person-x-fill',
        iconBg: '#fee2e2',
        iconColor: '#dc2626'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'unassign_staff');
    formData.append('deploy_id', deployId);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'warning');
            loadMatrixData();
        } else {
            showToast(res.message, 'danger');
        }
    })
    .catch(err => {
        showToast('Network error unassigning staff.', 'danger');
    });
}

// 10. Click Popover Action Manager (Disabled per user request)
function openCellPopover(e, staffId) {
    if (e && e.stopPropagation) e.stopPropagation();
    return; // Popup removed per user request
}

function closeCellPopover() {
    const popover = document.getElementById('cellActionPopover');
    if (popover) {
        popover.classList.remove('show');
        popover.style.display = 'none';
    }
    activePopoverStaffId = null;
}

function submitPopoverAssign() {
    if (!activePopoverStaffId) return;
    const siteSelect = document.getElementById('popoverSiteSelect');
    const siteId = parseInt(siteSelect.value);
    const clientId = siteSelect.options[siteSelect.selectedIndex]?.getAttribute('data-client');
    const shiftSelect = document.getElementById('popoverShiftSelect');
    const shiftId = shiftSelect && shiftSelect.value ? parseInt(shiftSelect.value) : (currentMatrixShiftId > 0 ? currentMatrixShiftId : (matrixData.shifts[0] ? matrixData.shifts[0].id : 1));

    if (!siteId || !clientId) {
        showToast('Please choose a venue from the list.', 'warning');
        return;
    }

    assignStaffDirect(activePopoverStaffId, clientId, siteId, shiftId);
    closeCellPopover();
}

function submitPopoverUnassign() {
    if (!activePopoverStaffId) return;
    const staff = matrixData.staff.find(s => s.id === activePopoverStaffId);
    if (!staff || !staff.deploy_info) return;

    unassignStaff(null, staff.deploy_info.deploy_id);
    closeCellPopover();
}

function submitPopoverShiftGroup() {
    if (!activePopoverStaffId) return;
    const grpSelect = document.getElementById('popoverGroupSelect');
    const targetGroup = grpSelect.value;
    if (!targetGroup) return;

    shiftStaffGroup(activePopoverStaffId, targetGroup);
    closeCellPopover();
}

function submitPopoverToggleAvail() {
    if (!activePopoverStaffId) return;
    const sId = activePopoverStaffId;
    closeCellPopover();
    toggleStaffAvailability(sId);
}

function openLeaveModalFromPopover() {
    if (!activePopoverStaffId) return;
    const sId = activePopoverStaffId;
    closeCellPopover();
    openLeaveModal(sId);
}

// =========================================================================
// 11. AUTHENTIC RIGHT-CLICK EXCEL CONTEXT MENU (CHANGE GROUP & ACTIONS)
// =========================================================================
function handleCellContextMenu(e, staffId) {
    e.preventDefault();
    e.stopPropagation();
    closeCellPopover();

    const staff = matrixData.staff.find(s => s.id === staffId);
    if (!staff) return;

    rcActiveStaffId = staffId;
    const rcMenu = document.getElementById('excelRightClickMenu');
    document.getElementById('rcStaffTitle').innerText = `${staff.name} [${staff.group || 'GENERAL POOL'}]`;

    // Populate Group submenu
    const subMenu = document.getElementById('rcGroupsSubmenu');
    let subHtml = '';
    const currentGrpUpper = (staff.group || 'GENERAL POOL').toUpperCase();

    activeGroupsList.forEach(g => {
        const isCurrent = (g.group_name.toUpperCase() === currentGrpUpper);
        subHtml += `
            <div class="excel-rc-item ${isCurrent ? 'fw-bold text-primary' : ''}" onclick="shiftStaffFromRC('${escapeHtml(g.group_name)}')">
                <span>${escapeHtml(g.group_name)}</span>
                ${isCurrent ? '<i class="bi bi-check2 text-primary"></i>' : ''}
            </div>
        `;
    });
    subMenu.innerHTML = subHtml;

    // Toggle availability text
    const toggleText = document.getElementById('rcToggleAvailText');
    if (toggleText) {
        toggleText.innerHTML = staff.is_on_leave ? '<i class="bi bi-play-circle me-2 text-success"></i>Mark Available (Resume)' : '<i class="bi bi-pause-circle me-2 text-danger"></i>Toggle Unavailable';
    }

    // Position menu at cursor
    let x = e.clientX;
    let y = e.clientY;
    if (x + 220 > window.innerWidth) x = window.innerWidth - 230;
    if (y + 240 > window.innerHeight) y = window.innerHeight - 250;

    rcMenu.style.left = `${x}px`;
    rcMenu.style.top = `${y}px`;
    rcMenu.style.display = 'block';
}

function closeContextMenu() {
    const rcMenu = document.getElementById('excelRightClickMenu');
    if (rcMenu) rcMenu.style.display = 'none';
    rcActiveStaffId = null;
}

function shiftStaffFromRC(targetGroup) {
    if (!rcActiveStaffId) return;
    shiftStaffGroup(rcActiveStaffId, targetGroup);
    closeContextMenu();
}

function openPopoverFromRC() {
    if (!rcActiveStaffId) return;
    const staffId = rcActiveStaffId;
    closeContextMenu();
    const cell = document.getElementById(`cell-staff-${staffId}`);
    if (cell) {
        cell.click();
    }
}

function openLeaveFromRC() {
    if (!rcActiveStaffId) return;
    const staffId = rcActiveStaffId;
    closeContextMenu();
    openLeaveModal(staffId);
}

function toggleAvailFromRC() {
    if (!rcActiveStaffId) return;
    const staffId = rcActiveStaffId;
    closeContextMenu();
    toggleStaffAvailability(staffId);
}

// Shift Staff Group AJAX API
function shiftStaffGroup(staffId, targetGroup) {
    const formData = new FormData();
    formData.append('action', 'shift_staff_group');
    formData.append('staff_id', staffId);
    formData.append('target_group', targetGroup);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            loadMatrixData();
        } else {
            showToast(res.message || 'Could not shift group.', 'danger');
        }
    })
    .catch(err => {
        showToast('Network error shifting group.', 'danger');
    });
}

async function promptAddNewGroup() {
    const name = await customPrompt({
        title: 'Add New Staff Pool / Group',
        label: 'Staff Pool / Group Name *',
        placeholder: 'e.g. LEMON TREE SPECIAL, HOLIDAY INN BOYS',
        help: 'This group will instantly appear as an Excel column in the workforce matrix.',
        confirmText: 'Create Group'
    });
    if (!name || !name.trim()) return;

    const formData = new FormData();
    formData.append('action', 'add_staff_group');
    formData.append('group_name', name.trim());

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            loadMatrixData().then(() => {
                const modalEl = document.getElementById('manageGroupsModal');
                if (modalEl && modalEl.classList.contains('show')) {
                    renderManageGroupsTable();
                }
            });
        } else {
            showToast(res.message || 'Could not create group.', 'danger');
        }
    })
    .catch(err => {
        showToast('Network error creating group.', 'danger');
    });
}

// Rename / Modify Staff Group (Replaces with Custom Prompt Modal)
async function promptRenameGroup(oldName) {
    const newName = await customPrompt({
        title: 'Rename Staff Pool / Group',
        label: 'Updated Group Name *',
        placeholder: 'Enter new pool name...',
        defaultValue: oldName,
        help: 'All staff currently in this group will be updated to the new name immediately.',
        confirmText: 'Save New Name'
    });
    if (!newName || !newName.trim()) return;
    const trimmedNew = newName.trim().toUpperCase();
    if (trimmedNew === oldName.toUpperCase()) return;

    const formData = new FormData();
    formData.append('action', 'rename_staff_group');
    formData.append('old_group_name', oldName);
    formData.append('new_group_name', trimmedNew);

    try {
        const res = await fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json());

        if (res.success) {
            showToast(res.message, 'success');
            await loadMatrixData();
            const modalEl = document.getElementById('manageGroupsModal');
            if (modalEl && modalEl.classList.contains('show')) {
                renderManageGroupsTable();
            }
        } else {
            showToast(res.message || 'Could not rename group.', 'danger');
        }
    } catch (err) {
        showToast('Network error renaming group.', 'danger');
    }
}

// Delete Staff Group (Replaces with Custom Confirm Modal)
async function confirmDeleteGroup(groupName, count) {
    if (groupName.toUpperCase() === 'GENERAL POOL') {
        showToast('Default GENERAL POOL cannot be deleted.', 'warning');
        return;
    }

    const staffNote = count > 0 
        ? `<br><br><span class="text-warning-emphasis fw-semibold"><i class="bi bi-info-circle me-1"></i> Note: All ${count} staff member(s) in this pool will be moved to <strong>GENERAL POOL</strong>.</span>` 
        : '';

    const confirmed = await customConfirm({
        title: 'Delete Staff Group',
        message: `Are you sure you want to delete pool <strong>&ldquo;${escapeHtml(groupName)}&rdquo;</strong>?${staffNote}`,
        confirmText: 'Delete Pool',
        btnClass: 'btn-danger',
        icon: 'bi-trash3-fill',
        iconBg: '#fee2e2',
        iconColor: '#dc2626'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'delete_staff_group');
    formData.append('group_name', groupName);

    try {
        const res = await fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json());

        if (res.success) {
            showToast(res.message, 'success');
            await loadMatrixData();
            const modalEl = document.getElementById('manageGroupsModal');
            if (modalEl && modalEl.classList.contains('show')) {
                renderManageGroupsTable();
            }
        } else {
            showToast(res.message || 'Could not delete group.', 'danger');
        }
    } catch (err) {
        showToast('Network error deleting group.', 'danger');
    }
}

// Manage Pools / Groups Modal Controller
let activeManageGroupsModal = null;
function openManageGroupsModal() {
    renderManageGroupsTable();
    if (!activeManageGroupsModal) {
        activeManageGroupsModal = new bootstrap.Modal(document.getElementById('manageGroupsModal'));
    }
    activeManageGroupsModal.show();
}

function renderManageGroupsTable() {
    const tbody = document.getElementById('manageGroupsTableBody');
    if (!tbody) return;

    // Calculate staff count per group from current matrix data
    const countsMap = {};
    (matrixData.staff || []).forEach(s => {
        const grp = (s.group || '').toUpperCase();
        if (grp && grp !== 'GENERAL POOL') {
            countsMap[grp] = (countsMap[grp] || 0) + 1;
        }
    });

    const groups = (activeGroupsList || []).filter(g => g.group_name.toUpperCase() !== 'GENERAL POOL');
    let html = '';

    groups.forEach(g => {
        const gName = g.group_name.toUpperCase();
        const count = countsMap[gName] || 0;

        html += `
            <tr>
                <td class="ps-3 fw-bold text-dark">
                    <i class="bi bi-folder2 text-primary me-1.5"></i>
                    ${escapeHtml(gName)}
                </td>
                <td class="text-center font-monospace fw-semibold">
                    <span class="badge bg-light text-dark border px-2">${count}</span>
                </td>
                <td class="text-end pe-3">
                    <button type="button" class="btn btn-xs btn-outline-primary py-0.5 px-2 me-1" onclick="promptRenameGroup('${escapeHtml(gName)}')" title="Rename ${escapeHtml(gName)}">
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-danger py-0.5 px-2" onclick="confirmDeleteGroup('${escapeHtml(gName)}', ${count})" title="Delete ${escapeHtml(gName)}">
                        <i class="bi bi-trash3 me-1"></i> Delete
                    </button>
                </td>
            </tr>
        `;
    });

    if (groups.length === 0) {
        html = '<tr><td colspan="3" class="text-center text-muted py-3">No groups configured.</td></tr>';
    }

    tbody.innerHTML = html;
}

// 12. 1-Click Availability Toggle
function toggleStaffAvailability(staffId) {
    const formData = new FormData();
    formData.append('action', 'toggle_availability');
    formData.append('staff_id', staffId);
    formData.append('date', currentMatrixDate);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, res.status === 'available' ? 'success' : 'warning');
            loadMatrixData();
        } else {
            showToast(res.message, 'danger');
        }
    })
    .catch(err => {
        showToast('Error toggling availability.', 'danger');
    });
}

// 12. Smart Multi-Day Leave Modal (10 Dino ki Chhutti)
function openLeaveModal(staffId) {
    const staff = matrixData.staff.find(s => s.id === staffId);
    if (!staff) return;

    document.getElementById('leaveStaffId').value = staff.id;
    document.getElementById('leaveStaffName').innerText = staff.name;
    document.getElementById('leaveStaffInitial').innerText = staff.name.charAt(0).toUpperCase();
    document.getElementById('leaveStaffCode').innerText = staff.code;
    document.getElementById('leaveStaffCategory').innerText = staff.category;

    const alertBox = document.getElementById('activeLeaveAlert');
    if (staff.is_on_leave && staff.leave_info) {
        document.getElementById('existingLeaveId').value = staff.leave_info.id;
        document.getElementById('activeLeaveAlertText').innerText = `Active leave: ${staff.leave_info.days} days (${staff.leave_info.start_date} to ${staff.leave_info.end_date}).`;
        alertBox.classList.remove('d-none');
        document.getElementById('leaveStartDate').value = staff.leave_info.start_date;
        document.getElementById('leaveEndDate').value = staff.leave_info.end_date;
        document.getElementById('leaveTypeSelect').value = staff.leave_info.type || 'vacation';
        document.getElementById('leaveReasonInput').value = staff.leave_info.reason || '';
    } else {
        document.getElementById('existingLeaveId').value = '';
        alertBox.classList.add('d-none');
        document.getElementById('leaveStartDate').value = currentMatrixDate;
        setLeavePreset(10); // Default to 10-day leave requested by user!
    }

    calculateLeaveDuration();
    activeLeaveModal.show();
}

function setLeavePreset(days) {
    const startInput = document.getElementById('leaveStartDate');
    const endInput = document.getElementById('leaveEndDate');
    const startVal = startInput.value || currentMatrixDate;
    
    const startDate = new Date(startVal);
    const endDate = new Date(startDate);
    endDate.setDate(startDate.getDate() + (days - 1));

    const yyyy = endDate.getFullYear();
    const mm = String(endDate.getMonth() + 1).padStart(2, '0');
    const dd = String(endDate.getDate()).padStart(2, '0');
    
    endInput.value = `${yyyy}-${mm}-${dd}`;
    calculateLeaveDuration();
}

function calculateLeaveDuration() {
    const s = document.getElementById('leaveStartDate').value;
    const e = document.getElementById('leaveEndDate').value;
    if (!s || !e) return;
    
    const diff = Math.round((new Date(e) - new Date(s)) / (1000 * 60 * 60 * 24)) + 1;
    const badge = document.getElementById('leaveDurationBadge');
    if (diff < 1) {
        badge.innerText = 'End date cannot be earlier than start date!';
        badge.className = 'text-danger small fw-bold';
    } else {
        badge.innerText = `Duration: ${diff} Day(s) Leave`;
        badge.className = 'text-primary small fw-bold';
    }
}

function handleLeaveFormSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'save_leave');

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            activeLeaveModal.hide();
            loadMatrixData();
        } else {
            showToast(res.message, 'danger');
        }
    })
    .catch(err => {
        showToast('Error saving leave.', 'danger');
    });
}

function cancelCurrentActiveLeave() {
    const leaveId = document.getElementById('existingLeaveId').value;
    if (!leaveId) return;
    cancelLeaveDirect(leaveId);
}

async function cancelLeaveDirect(leaveId) {
    const confirmed = await customConfirm({
        title: 'Cancel Staff Leave',
        message: 'Reactivate this staff member and cancel their active leave? They will immediately become available in their pool.',
        confirmText: 'Reactivate Staff',
        btnClass: 'btn-success',
        icon: 'bi-play-circle-fill',
        iconBg: '#dcfce7',
        iconColor: '#16a34a'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'cancel_leave');
    formData.append('leave_id', leaveId);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            if (activeLeaveModal) activeLeaveModal.hide();
            loadMatrixData();
        } else {
            showToast(res.message, 'danger');
        }
    })
    .catch(err => {
        showToast('Error cancelling leave.', 'danger');
    });
}

// 13. Date & Shift Navigators
let matrixFpInstance = null;

function initMatrixDatePicker() {
    const btn = document.getElementById('matrixDatePickerBtn');
    if (typeof flatpickr === 'function' && btn && !matrixFpInstance) {
        try {
            matrixFpInstance = flatpickr(btn, {
                defaultDate: currentMatrixDate || '<?= $selectedDate ?>',
                dateFormat: 'Y-m-d',
                disableMobile: true,
                onChange: function(selectedDates, dateStr) {
                    if (dateStr && dateStr !== currentMatrixDate) {
                        changeMatrixDate(dateStr);
                    }
                }
            });
        } catch (e) {
            console.warn('Flatpickr init error:', e);
        }
    }
}

function openMatrixDatePicker() {
    if (matrixFpInstance) {
        matrixFpInstance.open();
        return;
    }
    const picker = document.getElementById('matrixDatePicker');
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

function navigateMatrixDay(offsetDays) {
    let baseDate = currentMatrixDate || '<?= $selectedDate ?>';
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
        changeMatrixDate(`${nextY}-${nextM}-${nextD}`);
    }
}

function changeMatrixDate(newDate) {
    if (!newDate) return;
    const shiftId = currentMatrixShiftId || 0;
    const targetUrl = `<?= BASE_URL ?>/modules/mapping/deploy.php?date=${encodeURIComponent(newDate)}${shiftId ? '&shift_id=' + shiftId : ''}`;
    window.location.href = targetUrl;
}

function changeMatrixShift(shiftId) {
    clearStaffSelection();
    currentMatrixShiftId = parseInt(shiftId) || 0;
    const dateParam = currentMatrixDate || '<?= $selectedDate ?>';
    const targetUrl = `<?= BASE_URL ?>/modules/mapping/deploy.php?date=${encodeURIComponent(dateParam)}${currentMatrixShiftId ? '&shift_id=' + currentMatrixShiftId : ''}`;
    window.location.href = targetUrl;
}

function clearStaffSearch() {
    const input = document.getElementById('staffFilterSearch');
    if (input) {
        input.value = '';
        applyFilters();
        input.focus();
    }
}

function applyFilters() {
    const input = document.getElementById('staffFilterSearch');
    const clearBtn = document.getElementById('clearStaffSearchBtn');
    if (input && clearBtn) {
        clearBtn.classList.toggle('d-none', !input.value || input.value.trim().length === 0);
    }
    renderExcelMatrix(matrixData.staff);
}

// Select or Deselect all currently matching staff in search results
function selectAllSearchMatches() {
    const searchInput = document.getElementById('staffFilterSearch');
    const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
    if (!q || !matrixData.staff) return;

    const matched = matrixData.staff.filter(s => 
        !s.is_on_leave && !s.is_deployed && (
            (s.name && s.name.toLowerCase().includes(q)) || 
            (s.code && s.code.toLowerCase().includes(q)) || 
            (s.phone && s.phone.toLowerCase().includes(q)) ||
            (s.category && s.category.toLowerCase().includes(q)) ||
            (s.group && s.group.toLowerCase().includes(q))
        )
    );

    if (matched.length === 0) {
        showToast('No ready staff to select in search results.', 'warning');
        return;
    }

    const allSelected = matched.every(s => selectedStaffIds.has(s.id));
    if (allSelected) {
        matched.forEach(s => unselectStaff(s.id));
        showToast(`Deselected ${matched.length} search matches.`, 'secondary');
    } else {
        matched.forEach(s => selectStaff(s.id));
        showToast(`Selected all ${matched.length} ready search matches!`, 'success');
    }
    updateFloatingBar();
}

// 14. Toast Notification
function showToast(msg, type = 'primary') {
    const toastEl = document.getElementById('matrixToast');
    const bodyEl = document.getElementById('matrixToastBody');
    toastEl.className = `toast align-items-center text-white border-0 shadow-lg bg-${type}`;
    bodyEl.innerHTML = msg;
    const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
    toast.show();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatDateShort(dStr) {
    const d = new Date(dStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function formatDateMedium(dStr) {
    const d = new Date(dStr);
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
}

// =========================================================================
// 15. MULTI-SELECT & BATCH DEPLOYMENT ENGINE (MOBILE & DESKTOP)
// =========================================================================

let lastSelectedStaffId = null;
let isMouseDraggingSelection = false;
let dragSelectionTargetState = true;
let mouseDownHandledStaffId = null;
let dragHasMoved = false;

// Toggle Multi-Select Mode button in toolbar
function toggleMultiSelectMode() {
    multiSelectMode = !multiSelectMode;
    const btn = document.getElementById('toggleMultiSelectBtn');
    const txt = document.getElementById('multiSelectBtnText');
    const canvas = document.getElementById('excelMatrixCanvas');
    if (multiSelectMode) {
        if (btn) {
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary', 'active');
        }
        if (txt) txt.innerText = 'Multi-Select (ON)';
        if (canvas) canvas.classList.add('multi-select-active');

        // Turn OFF HTML5 card dragging on all cells so mouse-drag selects cells like Excel
        document.querySelectorAll('.excel-staff-cell').forEach(c => {
            c.setAttribute('draggable', 'false');
        });

        showToast('Multi-Select ON: Click or drag mouse across cells to select. Click "Deploy to Venue" when ready.', 'info');
    } else {
        if (btn) {
            btn.classList.remove('btn-primary', 'active');
            btn.classList.add('btn-outline-primary');
        }
        if (txt) txt.innerText = 'Multi-Select';
        if (canvas) canvas.classList.remove('multi-select-active');

        // Restore HTML5 card dragging
        document.querySelectorAll('.excel-staff-cell').forEach(c => {
            if (!c.classList.contains('status-leave')) {
                c.setAttribute('draggable', 'true');
            }
        });

        // Auto-deselect all selected cells when multi-select mode is turned OFF
        clearStaffSelection();
        showToast('Multi-Select OFF: All selections cleared.', 'secondary');
    }
}

// Mouse down on staff cell for Excel-like click & sweep drag selection
function handleStaffCellMouseDown(e, staffId) {
    if (e.button !== 0) return; // Only primary left mouse click

    const isCtrl = e.ctrlKey || e.metaKey;
    if (!multiSelectMode && !isCtrl) return;
    if (e.shiftKey) return; // Shift + click handled in handleStaffCellClick

    const stf = matrixData.staff ? matrixData.staff.find(s => s.id === staffId) : null;
    if (!stf || stf.is_on_leave || stf.is_deployed) return;

    // Prevent text highlight and browser default drag while selecting
    e.preventDefault();

    isMouseDraggingSelection = true;
    dragHasMoved = false;
    dragSelectionTargetState = !selectedStaffIds.has(staffId);

    if (dragSelectionTargetState) {
        selectStaff(staffId);
    } else {
        unselectStaff(staffId);
    }
    mouseDownHandledStaffId = staffId;
    lastSelectedStaffId = staffId;
    updateFloatingBar();
}

// Mouse enter on staff cell while dragging (Excel sweep selection)
function handleStaffCellMouseEnter(e, staffId) {
    if (!isMouseDraggingSelection) return;
    if (e.buttons !== 1) {
        isMouseDraggingSelection = false;
        return;
    }

    const stf = matrixData.staff ? matrixData.staff.find(s => s.id === staffId) : null;
    if (!stf || stf.is_on_leave || stf.is_deployed) return;

    dragHasMoved = true;
    if (dragSelectionTargetState) {
        selectStaff(staffId);
    } else {
        unselectStaff(staffId);
    }
    lastSelectedStaffId = staffId;
    updateFloatingBar();
}

// Click handler for a staff cell (Excel-style multi-select & click toggle)
function handleStaffCellClick(e, staffId) {
    closeContextMenu();
    closeGhMenu();
    closeCellPopover();

    // If mouse was dragged across multiple cells, suppress click on the drop cell
    if (dragHasMoved) {
        dragHasMoved = false;
        mouseDownHandledStaffId = null;
        return;
    }

    // If this cell was already toggled during mousedown, do not double-toggle
    if (mouseDownHandledStaffId === staffId) {
        mouseDownHandledStaffId = null;
        return;
    }
    mouseDownHandledStaffId = null;

    const stf = matrixData.staff ? matrixData.staff.find(s => s.id === staffId) : null;
    if (!stf) return;

    if (stf.is_on_leave) {
        showToast(`${stf.name} is on leave and cannot be selected for deployment.`, 'warning');
        return;
    }
    if (stf.is_deployed) {
        showToast(`${stf.name} is already deployed at ${stf.deploy_info ? stf.deploy_info.site_name : 'a venue'}. Unassign first if you want to redeploy.`, 'warning');
        return;
    }

    e.stopPropagation();

    if (e.shiftKey && lastSelectedStaffId && lastSelectedStaffId !== staffId) {
        handleRangeSelection(lastSelectedStaffId, staffId);
    } else {
        toggleStaffSelection(staffId);
        lastSelectedStaffId = staffId;
    }
}

// Range selection for Shift + Click (Excel column or contiguous range)
function handleRangeSelection(fromId, toId) {
    if (!matrixData || !matrixData.staff) return;

    const fromStf = matrixData.staff.find(s => s.id === fromId);
    const toStf = matrixData.staff.find(s => s.id === toId);
    if (!fromStf || !toStf) return;

    const fromGroup = fromStf.group || 'GENERAL POOL';
    const toGroup = toStf.group || 'GENERAL POOL';

    let rangeStaff = [];
    if (fromGroup === toGroup) {
        // Within same group column
        const colStaff = matrixData.staff.filter(s => (s.group || 'GENERAL POOL') === fromGroup);
        const idx1 = colStaff.findIndex(s => s.id === fromId);
        const idx2 = colStaff.findIndex(s => s.id === toId);
        if (idx1 !== -1 && idx2 !== -1) {
            const start = Math.min(idx1, idx2);
            const end = Math.max(idx1, idx2);
            rangeStaff = colStaff.slice(start, end + 1);
        }
    } else {
        // Across different columns
        const idx1 = matrixData.staff.findIndex(s => s.id === fromId);
        const idx2 = matrixData.staff.findIndex(s => s.id === toId);
        if (idx1 !== -1 && idx2 !== -1) {
            const start = Math.min(idx1, idx2);
            const end = Math.max(idx1, idx2);
            rangeStaff = matrixData.staff.slice(start, end + 1);
        }
    }

    let newlySelected = 0;
    rangeStaff.forEach(s => {
        if (!s.is_on_leave && !s.is_deployed) {
            selectStaff(s.id);
            newlySelected++;
        }
    });

    updateFloatingBar();
    showToast(`Selected ${selectedStaffIds.size} staff`, 'info');
}

// Select an individual staff member (syncs across all visible column cells)
function selectStaff(staffId) {
    selectedStaffIds.add(staffId);
    document.querySelectorAll(`.excel-staff-cell[data-staff-id="${staffId}"]`).forEach(cell => {
        cell.classList.add('is-selected');
        if (!cell.querySelector('.selection-check-icon')) {
            const icon = document.createElement('span');
            icon.className = 'selection-check-icon';
            icon.innerHTML = '<i class="bi bi-check-lg"></i>';
            cell.insertBefore(icon, cell.firstChild);
        }
    });
}

// Unselect an individual staff member (syncs across all visible column cells)
function unselectStaff(staffId) {
    selectedStaffIds.delete(staffId);
    document.querySelectorAll(`.excel-staff-cell[data-staff-id="${staffId}"]`).forEach(cell => {
        cell.classList.remove('is-selected');
        const icon = cell.querySelector('.selection-check-icon');
        if (icon) icon.remove();
    });
}

// Toggle individual staff selection state
function toggleStaffSelection(staffId) {
    if (selectedStaffIds.has(staffId)) {
        unselectStaff(staffId);
    } else {
        selectStaff(staffId);
    }
    updateFloatingBar();
}

// Update floating bottom action bar
function updateFloatingBar() {
    const bar = document.getElementById('matrixFloatingBar');
    const countSpan = document.getElementById('floatingSelectedCount');
    const count = selectedStaffIds.size;

    if (countSpan) countSpan.innerText = count;

    if (count > 0) {
        bar.classList.add('show');
    } else {
        bar.classList.remove('show');
    }
}

// Clear all staff selection
function clearStaffSelection() {
    selectedStaffIds.clear();
    lastSelectedStaffId = null;
    document.querySelectorAll('.excel-staff-cell.is-selected').forEach(c => {
        c.classList.remove('is-selected');
        const icon = c.querySelector('.selection-check-icon');
        if (icon) icon.remove();
    });
    document.querySelectorAll('.selection-check-icon').forEach(icon => icon.remove());
    updateFloatingBar();
}

// Open Searchable Client Venue Modal
function openBatchDeployModal() {
    if (selectedStaffIds.size === 0) {
        showToast('Please select at least one staff member first.', 'warning');
        return;
    }

    // Set count badges
    const count = selectedStaffIds.size;
    const badgeEl = document.getElementById('batchModalStaffCount');
    if (badgeEl) badgeEl.innerText = count;
    const btnCount = document.getElementById('batchBtnCount');
    if (btnCount) btnCount.innerText = count;

    // Render chips of selected staff
    const chipsContainer = document.getElementById('batchModalStaffChips');
    let chipsHtml = '';
    selectedStaffIds.forEach(sid => {
        const s = matrixData.staff.find(st => st.id === sid);
        const name = s ? s.name : `Staff #${sid}`;
        const cat = s ? s.category : 'Staff';
        chipsHtml += `
            <span class="badge bg-primary text-white d-inline-flex align-items-center gap-1 py-1 px-2" style="font-size: 0.70rem;">
                <i class="bi bi-person-fill"></i> ${escapeHtml(name)} <small class="text-white-50">(${escapeHtml(cat)})</small>
            </span>
        `;
    });
    chipsContainer.innerHTML = chipsHtml;

    // Reset venue selection
    selectedVenueSiteId = null;
    selectedVenueClientId = null;
    document.getElementById('btnSubmitBatchDeploy').disabled = true;
    document.getElementById('modalVenueSearch').value = '';
    const clearBtn = document.getElementById('clearModalVenueSearchBtn');
    if (clearBtn) clearBtn.classList.add('d-none');

    // Render venue list
    renderModalVenues(matrixData.sites || []);

    // Show modal
    activeBatchDeployModal.show();
}

// Render venue list inside modal
function renderModalVenues(sitesList) {
    const listContainer = document.getElementById('modalVenueList');
    if (!sitesList || sitesList.length === 0) {
        listContainer.innerHTML = `<div class="text-center py-4 text-muted small fst-italic">No venues matching search.</div>`;
        return;
    }

    let html = '';
    sitesList.forEach(site => {
        const isSelected = (selectedVenueSiteId === site.id);
        const depCount = site.deployed_count || 0;
        html += `
            <div class="venue-select-card ${isSelected ? 'selected' : ''}" 
                 id="modal-venue-card-${site.id}" 
                 onclick="selectVenueForBatch(${site.id}, ${site.client_id})">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <div class="venue-radio-circle"></div>
                    <div>
                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.80rem;">
                            ${escapeHtml(site.site_name)}
                        </div>
                        <div class="text-muted text-truncate" style="font-size: 0.68rem;">
                            <i class="bi bi-building me-1"></i>${escapeHtml(site.client_name)}
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">
                        <i class="bi bi-people-fill me-0.5"></i> ${depCount} Mapped
                    </span>
                </div>
            </div>
        `;
    });
    listContainer.innerHTML = html;
}

// Filter modal venues live search
function filterModalVenues() {
    const q = (document.getElementById('modalVenueSearch').value || '').toLowerCase().trim();
    const clearBtn = document.getElementById('clearModalVenueSearchBtn');
    if (clearBtn) {
        clearBtn.classList.toggle('d-none', !q);
    }

    let filtered = matrixData.sites || [];
    if (q) {
        filtered = filtered.filter(s => 
            s.site_name.toLowerCase().includes(q) || 
            s.client_name.toLowerCase().includes(q) ||
            (s.address && s.address.toLowerCase().includes(q))
        );
    }
    renderModalVenues(filtered);
}

function clearModalVenueSearch() {
    document.getElementById('modalVenueSearch').value = '';
    filterModalVenues();
    document.getElementById('modalVenueSearch').focus();
}

// Select a venue card in the modal
function selectVenueForBatch(siteId, clientId) {
    selectedVenueSiteId = siteId;
    selectedVenueClientId = clientId;

    document.querySelectorAll('.venue-select-card').forEach(c => c.classList.remove('selected'));
    const targetCard = document.getElementById(`modal-venue-card-${siteId}`);
    if (targetCard) targetCard.classList.add('selected');

    document.getElementById('btnSubmitBatchDeploy').disabled = false;
}

// Submit batch deployment to backend
function submitBatchDeploy() {
    if (selectedStaffIds.size === 0 || !selectedVenueSiteId || !selectedVenueClientId) {
        showToast('Please select a venue to deploy.', 'warning');
        return;
    }

    const submitBtn = document.getElementById('btnSubmitBatchDeploy');
    const originalBtnHtml = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Deploying...`;

    const shiftId = parseInt(document.getElementById('batchModalShiftSelect').value) || 1;
    const staffIdsArr = Array.from(selectedStaffIds);

    const formData = new FormData();
    formData.append('action', 'assign_batch_staff');
    formData.append('staff_ids', staffIdsArr.join(','));
    formData.append('client_id', selectedVenueClientId);
    formData.append('site_id', selectedVenueSiteId);
    formData.append('shift_id', shiftId);
    formData.append('date', currentMatrixDate);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnHtml;

        if (res.success) {
            showToast(res.message, 'success');
            activeBatchDeployModal.hide();
            clearStaffSelection();
            expandedSiteId = selectedVenueSiteId;
            loadMatrixData();
        } else {
            showToast(res.message || 'Deployment could not be completed.', 'danger');
        }
    })
    .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnHtml;
        showToast('Network error during batch deployment.', 'danger');
    });
}

// Mobile Tab Switcher between Staff Matrix Sheet and Client Venues
function switchMobileDeployTab(tab) {
    const sheetCanvas = document.getElementById('excelMatrixCanvas');
    const clientPanel = document.getElementById('clientPanelCard');
    const btnSheet = document.getElementById('mobTabBtnSheet');
    const btnVenues = document.getElementById('mobTabBtnVenues');

    if (tab === 'sheet') {
        if (sheetCanvas) sheetCanvas.classList.remove('d-mobile-hide');
        if (clientPanel) clientPanel.classList.add('d-mobile-hide');
        if (btnSheet) {
            btnSheet.classList.remove('btn-outline-primary');
            btnSheet.classList.add('btn-primary');
        }
        if (btnVenues) {
            btnVenues.classList.remove('btn-primary');
            btnVenues.classList.add('btn-outline-primary');
        }
    } else {
        if (sheetCanvas) sheetCanvas.classList.add('d-mobile-hide');
        if (clientPanel) clientPanel.classList.remove('d-mobile-hide');
        if (btnVenues) {
            btnVenues.classList.remove('btn-outline-primary');
            btnVenues.classList.add('btn-primary');
        }
        if (btnSheet) {
            btnSheet.classList.remove('btn-primary');
            btnSheet.classList.add('btn-outline-primary');
        }
    }
}

// =========================================================================
// 16. QUICK ADD CLIENT & VENUE (DIRECTLY INSIDE BATCH DEPLOY POPUP)
// =========================================================================
function toggleQuickClientVenueForm(show = null) {
    const el = document.getElementById('quickClientVenueCollapse');
    const btnText = document.getElementById('quickAddVenueBtnText');
    if (!el) return;

    const willShow = (show !== null) ? show : el.classList.contains('d-none');
    if (willShow) {
        el.classList.remove('d-none');
        if (btnText) btnText.innerText = '- Close Form';
        setTimeout(() => {
            const isNew = document.getElementById('quickIsNewClient').checked;
            if (isNew) {
                const newCli = document.getElementById('quickNewClientName');
                if (newCli) newCli.focus();
            } else {
                const siteInput = document.getElementById('quickSiteName');
                if (siteInput) siteInput.focus();
            }
        }, 120);
    } else {
        el.classList.add('d-none');
        if (btnText) btnText.innerText = '+ Quick Add Client & Venue';
    }
}

function toggleQuickNewClientInput(isNew) {
    const existWrap = document.getElementById('quickExistingClientWrapper');
    const newWrap = document.getElementById('quickNewClientWrapper');
    if (isNew) {
        if (existWrap) existWrap.classList.add('d-none');
        if (newWrap) newWrap.classList.remove('d-none');
        const inp = document.getElementById('quickNewClientName');
        if (inp) inp.focus();
    } else {
        if (newWrap) newWrap.classList.add('d-none');
        if (existWrap) existWrap.classList.remove('d-none');
        const sel = document.getElementById('quickSelectClient');
        if (sel) sel.focus();
    }
}

function submitQuickAddClientSite() {
    const isNewCheckbox = document.getElementById('quickIsNewClient');
    const isNewClient = isNewCheckbox ? isNewCheckbox.checked : false;
    const clientSelect = document.getElementById('quickSelectClient');
    const clientId = clientSelect ? clientSelect.value : '';
    const newClientName = (document.getElementById('quickNewClientName').value || '').trim();
    const siteName = (document.getElementById('quickSiteName').value || '').trim();
    const address = (document.getElementById('quickSiteAddress').value || '').trim();
    const rate = (document.getElementById('quickClientRate').value || '').trim();

    if (isNewClient && !newClientName) {
        showToast('Please enter new client / company name.', 'warning');
        return;
    }
    if (!isNewClient && !clientId) {
        showToast('Please select an existing client or check "+ New Client".', 'warning');
        return;
    }
    if (!siteName) {
        showToast('Please enter venue / site name.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitQuickClientVenue');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Saving...`;

    const formData = new FormData();
    formData.append('action', 'quick_add_client_site');
    formData.append('client_mode', isNewClient ? 'new' : 'existing');
    formData.append('client_id', clientId || '0');
    formData.append('new_client_name', newClientName);
    formData.append('site_name', siteName);
    formData.append('location_address', address || 'Patna, Bihar');
    formData.append('default_client_rate', rate || '850');

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = origHtml;

        if (res.success && res.site) {
            showToast(res.message, 'success');

            // Add new site to local matrixData.sites array
            if (!matrixData.sites) matrixData.sites = [];
            matrixData.sites.unshift(res.site);

            // If a new client was created, append to client dropdown options
            if (isNewClient && res.site.client_id) {
                const sel = document.getElementById('quickSelectClient');
                if (sel) {
                    const opt = document.createElement('option');
                    opt.value = res.site.client_id;
                    opt.textContent = res.site.client_name;
                    sel.appendChild(opt);
                    sel.value = res.site.client_id;
                }
            }

            // Re-render modal venues and auto-select this new site immediately!
            renderModalVenues(matrixData.sites);
            selectVenueForBatch(res.site.id, res.site.client_id);

            // Scroll modal venue list to top
            const listEl = document.getElementById('modalVenueList');
            if (listEl) listEl.scrollTop = 0;

            // Reset inputs and close quick add collapse
            document.getElementById('quickSiteName').value = '';
            document.getElementById('quickNewClientName').value = '';
            if (isNewCheckbox) isNewCheckbox.checked = false;
            toggleQuickNewClientInput(false);
            toggleQuickClientVenueForm(false);

            // Also re-render right accordion panel if available
            renderClientAccordion(matrixData.sites, matrixData.staff);
        } else {
            showToast(res.message || 'Could not save client/venue.', 'danger');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        showToast('Network error saving client & venue.', 'danger');
    });
}

// =========================================================================
// 17. QUICK ADD STAFF (FROM BLANK EXCEL CELL CLICK)
// =========================================================================
let activeQuickStaffModalInstance = null;

function getQuickStaffModal() {
    if (!activeQuickStaffModalInstance) {
        const modalEl = document.getElementById('quickAddStaffModal');
        activeQuickStaffModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    }
    return activeQuickStaffModalInstance;
}

function handleEmptyCellClick(e, groupName) {
    if (multiSelectMode || isMouseDraggingSelection) return;
    openQuickAddStaffModal(groupName);
}

function openQuickAddStaffModal(groupName) {
    closeCellPopover();
    closeContextMenu();
    closeGhMenu();

    document.getElementById('quickStaffGroupInput').value = groupName;
    const badgeEl = document.getElementById('quickStaffGroupBadge');
    if (badgeEl) badgeEl.innerText = groupName;

    document.getElementById('quickStaffNameInput').value = '';
    document.getElementById('quickStaffPhoneInput').value = '';
    document.getElementById('quickStaffRateInput').value = '500';

    getQuickStaffModal().show();

    setTimeout(() => {
        const nameInput = document.getElementById('quickStaffNameInput');
        if (nameInput) nameInput.focus();
    }, 350);
}

function submitQuickAddStaff(e) {
    if (e && e.preventDefault) e.preventDefault();

    const name = (document.getElementById('quickStaffNameInput').value || '').trim();
    const phone = (document.getElementById('quickStaffPhoneInput').value || '').trim();
    const group = (document.getElementById('quickStaffGroupInput').value || '').trim();
    const categoryId = document.getElementById('quickStaffCategorySelect').value;
    const rate = document.getElementById('quickStaffRateInput').value;

    if (!name) {
        showToast('Please enter staff full name.', 'warning');
        return;
    }
    const cleanPhone = phone.replace(/\D/g, '');
    if (!cleanPhone || cleanPhone.length < 10) {
        showToast('Please enter a valid 10-digit mobile number.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitQuickStaff');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Adding...`;

    const formData = new FormData();
    formData.append('action', 'quick_add_staff');
    formData.append('full_name', name);
    formData.append('phone', cleanPhone);
    formData.append('staff_group', group);
    formData.append('category_id', categoryId);
    formData.append('default_payout_rate', rate);

    fetch('<?= BASE_URL ?>/modules/mapping/ajax_matrix.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = origHtml;

        if (res.success) {
            showToast(res.message, 'success');
            getQuickStaffModal().hide();
            loadMatrixData();
        } else {
            showToast(res.message || 'Could not add staff.', 'danger');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        showToast('Network error adding staff member.', 'danger');
    });
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
