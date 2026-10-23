<?php
// modules/staff/my_passbook.php - Staff Self-Service Passbook
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireLogin();

$user = Auth::user();
$staffId = $user['staff_id'] ?? null;

if (!$staffId) {
    if (Auth::isManager() || Auth::isAccountant()) {
        Helper::setFlash('info', 'Please select a staff member from the directory.');
        Helper::redirect('modules/staff/index.php');
    } else {
        die("No linked staff profile found for this account.");
    }
}

$db = DB::getConnection();
$stmt = $db->prepare("SELECT * FROM staff WHERE id = :id");
$stmt->execute([':id' => $staffId]);
$staff = $stmt->fetch();

// Summary metrics
$earnedStmt = $db->prepare("SELECT COALESCE(SUM(final_staff_amount), 0) FROM attendance WHERE staff_id = :id AND status IN ('present', 'half_day')");
$earnedStmt->execute([':id' => $staffId]);
$totalEarned = (float)$earnedStmt->fetchColumn();

$paidStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM staff_payments WHERE staff_id = :id");
$paidStmt->execute([':id' => $staffId]);
$totalPaid = (float)$paidStmt->fetchColumn();

$balanceDue = $totalEarned - $totalPaid;

// Deployment history
$depStmt = $db->prepare("
    SELECT a.*, d.deployment_date, d.staff_rate, c.company_name, s.site_name, sh.shift_name, sh.shift_code, sh.is_overnight
    FROM attendance a
    JOIN deployments d ON a.deployment_id = d.id
    JOIN clients c ON a.client_id = c.id
    JOIN sites s ON a.site_id = s.id
    JOIN shifts sh ON a.shift_id = sh.id
    WHERE a.staff_id = :id
    ORDER BY a.attendance_date DESC, a.id DESC
");
$depStmt->execute([':id' => $staffId]);
$attendanceHistory = $depStmt->fetchAll();

// Payment history
$payStmt = $db->prepare("
    SELECT sp.*, fa.account_name
    FROM staff_payments sp
    JOIN financial_accounts fa ON sp.account_id = fa.id
    WHERE sp.staff_id = :id
    ORDER BY sp.payment_date DESC, sp.id DESC
");
$payStmt->execute([':id' => $staffId]);
$paymentHistory = $payStmt->fetchAll();

$pageTitle = 'My Earnings Passbook';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-wallet2 text-primary me-2"></i>My Earnings Passbook</h4>
        <p class="text-muted small mb-0">Track all your completed shifts, verified attendance, salary earnings, and payment payouts.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Total Shifts Completed</div>
            <div class="metric-value text-primary"><?= count($attendanceHistory) ?></div>
            <div class="text-muted small mt-1">Shifts marked present/half-day</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Total Earnings Accrued</div>
            <div class="metric-value text-dark"><?= Helper::formatCurrency($totalEarned) ?></div>
            <div class="text-muted small mt-1">Earned to date</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Pending Payout Due</div>
            <div class="metric-value <?= $balanceDue > 0 ? 'text-success' : 'text-secondary' ?>">
                <?= Helper::formatCurrency($balanceDue) ?>
            </div>
            <div class="text-muted small mt-1">Available for next settlement</div>
        </div>
    </div>
</div>

<div class="custom-card">
    <div class="custom-card-header">
        <h6 class="fw-bold mb-0">Shift Attendance & Earning Breakdown</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-custom table-sm align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Client & Site</th>
                    <th>Shift</th>
                    <th>Status</th>
                    <th>Hours</th>
                    <th>Payout Rate</th>
                    <th>My Earning</th>
                    <th>Payout Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attendanceHistory)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No attendance records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($attendanceHistory as $att): ?>
                        <tr>
                            <td><strong><?= Helper::formatDate($att['attendance_date']) ?></strong></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($att['site_name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($att['company_name']) ?></div>
                            </td>
                            <td><?= Helper::shiftBadge($att['shift_name'], $att['shift_code'], (bool)$att['is_overnight']) ?></td>
                            <td><?= Helper::statusBadge($att['status']) ?></td>
                            <td><?= $att['hours_worked'] ?> hrs</td>
                            <td><?= Helper::formatCurrency($att['staff_rate']) ?></td>
                            <td><strong class="text-primary"><?= Helper::formatCurrency($att['final_staff_amount']) ?></strong></td>
                            <td>
                                <?php if ($att['is_settled'] || $balanceDue <= 0): ?>
                                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check2-circle"></i> Paid</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
