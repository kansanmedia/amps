<?php
// modules/reports/attendance.php - Deployment & Attendance Analytics
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$filterStaffId = !empty($_GET['staff_id']) ? (int)$_GET['staff_id'] : null;
$filterClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;

$sql = "
    SELECT a.*, d.deployment_date, d.client_rate, d.staff_rate,
           c.company_name, s.site_name, sh.shift_name, sh.shift_code, sh.is_overnight,
           stf.full_name as staff_name, stf.staff_code, stf.skill_category
    FROM attendance a
    JOIN deployments d ON a.deployment_id = d.id
    JOIN clients c ON a.client_id = c.id
    JOIN sites s ON a.site_id = s.id
    JOIN shifts sh ON a.shift_id = sh.id
    JOIN staff stf ON a.staff_id = stf.id
    WHERE a.attendance_date BETWEEN :start AND :end
";
$params = [':start' => $startDate, ':end' => $endDate];

if ($filterStaffId) {
    $sql .= " AND a.staff_id = :stfid";
    $params[':stfid'] = $filterStaffId;
}
if ($filterClientId) {
    $sql .= " AND a.client_id = :cid";
    $params[':cid'] = $filterClientId;
}
$sql .= " ORDER BY a.attendance_date DESC, a.shift_id ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Dropdowns
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$staffList = $db->query("SELECT id, full_name, staff_code FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Deployment & Attendance Reports';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Deployment & Attendance Reports</h4>
        <p class="text-muted small mb-0">Track shift attendance compliance, worker hours, and client site fulfillment.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-danger btn-sm" onclick="downloadAttendancePDF()">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>
</div>

<!-- Filters Bar (No Print) -->
<div class="custom-card p-3 mb-4 no-print">
    <form method="GET" action="" class="row g-2 align-items-center">
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Start Date</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $startDate ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">End Date</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $endDate ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Client Filter</label>
            <select name="client_id" class="form-select form-select-sm">
                <option value="">-- All Clients --</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= $filterClientId == $cl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cl['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Worker Filter</label>
            <select name="staff_id" class="form-select form-select-sm">
                <option value="">-- All Workers --</option>
                <?php foreach ($staffList as $stf): ?>
                    <option value="<?= $stf['id'] ?>" <?= $filterStaffId == $stf['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 text-end mt-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply Filters</button>
            <a href="<?= BASE_URL ?>/modules/reports/attendance.php" class="btn btn-light btn-sm border ms-1">Reset</a>
        </div>
    </form>
</div>

<!-- Report Table Container -->
<div id="attendance-report-container">
<div class="custom-card">
    <div class="custom-card-header">
        <h6 class="fw-bold mb-0">Records (<?= count($records) ?> Deployments)</h6>
        <input type="text" class="form-control form-control-sm no-print" placeholder="Search report..." data-table-search="#reportTable" style="width: 220px;">
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="reportTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Worker Name</th>
                    <th>Skill</th>
                    <th>Client & Site</th>
                    <th>Shift</th>
                    <th>Status</th>
                    <th>Hours</th>
                    <th>Client Billed</th>
                    <th>Staff Paid</th>
                    <th>GPS</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted">No records found for the selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?= Helper::formatDate($r['attendance_date']) ?></strong></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($r['staff_name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($r['staff_code']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['skill_category']) ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($r['site_name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($r['company_name']) ?></div>
                            </td>
                            <td><?= Helper::shiftBadge($r['shift_name'], $r['shift_code'], (bool)$r['is_overnight']) ?></td>
                            <td><?= Helper::statusBadge($r['status']) ?></td>
                            <td><?= $r['hours_worked'] ?> hrs</td>
                            <td><strong class="text-dark"><?= Helper::formatCurrency($r['final_client_amount']) ?></strong></td>
                            <td><strong class="text-primary"><?= Helper::formatCurrency($r['final_staff_amount']) ?></strong></td>
                            <td>
                                <?php if ($r['gps_verified']): ?>
                                    <i class="bi bi-geo-alt-fill text-success" title="GPS Verified"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash text-muted"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /#attendance-report-container -->

<script>
function downloadAttendancePDF() {
    const compSlug = '<?= preg_replace('/[^a-zA-Z0-9_-]/', '_', Helper::getCompanyName()) ?>';
    downloadElementAsPDF('attendance-report-container', compSlug + '_Attendance_Report_<?= $startDate ?>_to_<?= $endDate ?>', {
        margin: [8, 8, 8, 8],
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    });
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
