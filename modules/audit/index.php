<?php
// modules/audit/index.php - System Audit Trail & Security Logs
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin']);

$db = DB::getConnection();

$filterModule = $_GET['module'] ?? '';
$sql = "
    SELECT a.*, u.full_name as user_full_name, u.username, u.role
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE 1=1
";
$params = [];
if (!empty($filterModule)) {
    $sql .= " AND a.module = :mod";
    $params[':mod'] = strtoupper($filterModule);
}
$sql .= " ORDER BY a.id DESC LIMIT 150";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Trail';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-shield-check text-primary me-2"></i>Security Audit Trail</h4>
        <p class="text-muted small mb-0">Track all sensitive operations, rate snapshots, attendance edits, invoices, and payments.</p>
    </div>
</div>

<div class="custom-card p-3 mb-4">
    <form method="GET" action="" class="row g-2 align-items-center">
        <div class="col-md-3">
            <select name="module" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- All Modules --</option>
                <option value="DEPLOYMENTS" <?= $filterModule === 'DEPLOYMENTS' ? 'selected' : '' ?>>Deployments / Mapping</option>
                <option value="ATTENDANCE" <?= $filterModule === 'ATTENDANCE' ? 'selected' : '' ?>>Attendance</option>
                <option value="INVOICES" <?= $filterModule === 'INVOICES' ? 'selected' : '' ?>>Invoices & Billing</option>
                <option value="CLIENT_PAYMENTS" <?= $filterModule === 'CLIENT_PAYMENTS' ? 'selected' : '' ?>>Client Payments</option>
                <option value="STAFF_PAYMENTS" <?= $filterModule === 'STAFF_PAYMENTS' ? 'selected' : '' ?>>Staff Payouts</option>
                <option value="EXPENSES" <?= $filterModule === 'EXPENSES' ? 'selected' : '' ?>>Expenses</option>
                <option value="USERS" <?= $filterModule === 'USERS' ? 'selected' : '' ?>>Logins / Users</option>
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" placeholder="Search log entries..." data-table-search="#auditTable">
        </div>
        <div class="col-auto">
            <a href="<?= BASE_URL ?>/modules/audit/index.php" class="btn btn-light btn-sm border">Reset</a>
        </div>
    </form>
</div>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="auditTable">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Record ID</th>
                    <th>Change Payload</th>
                    <th>IP Address & Device</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No audit logs recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="text-nowrap"><small><?= Helper::formatDateTime($l['created_at']) ?></small></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($l['user_full_name'] ?? 'System') ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($l['role'] ?? 'cron') ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border text-uppercase" style="font-size:0.72rem;">
                                    <?= htmlspecialchars($l['action']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($l['module']) ?></span>
                            </td>
                            <td>#<?= $l['record_id'] ?: '-' ?></td>
                            <td>
                                <?php if ($l['new_values']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.75rem;" onclick='showPayload(<?= json_encode($l['new_values']) ?>)'>
                                        <i class="bi bi-file-earmark-code"></i> View Data
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= htmlspecialchars($l['ip_address']) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payload View Modal -->
<div class="modal fade" id="payloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Audit Payload Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <pre class="bg-light p-3 rounded border small mb-0" id="payloadContent" style="max-height:300px; overflow:auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
const payloadModal = new bootstrap.Modal(document.getElementById('payloadModal'));
function showPayload(jsonStr) {
    try {
        const obj = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
        document.getElementById('payloadContent').textContent = JSON.stringify(obj, null, 2);
    } catch(e) {
        document.getElementById('payloadContent').textContent = jsonStr;
    }
    payloadModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
