<?php
// modules/staff_payments/advance.php - Disburse Staff Advance
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();
$error = '';
$preStaffId = !empty($_GET['staff_id']) ? (int)$_GET['staff_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? 'cash';
    $refNumber = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? 'Salary advance disbursement');

    if (empty($staffId) || empty($accountId) || $amount <= 0) {
        $error = 'Staff, Account, and valid Amount are required.';
    } else {
        $seqStmt = $db->query("SELECT COUNT(id) FROM staff_payments WHERE YEAR(payment_date) = YEAR(CURDATE())");
        $seq = (int)$seqStmt->fetchColumn() + 1;
        $voucherNumber = 'ADV-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $db->beginTransaction();
        try {
            // Insert advance payment
            $stmt = $db->prepare("
                INSERT INTO staff_payments (payment_number, staff_id, account_id, payment_type, payment_date, amount, payment_mode, reference_number, notes, processed_by)
                VALUES (:vnum, :stfid, :accid, 'advance', :pdate, :amt, :pmode, :ref, :notes, :uid)
            ");
            $stmt->execute([
                ':vnum'  => $voucherNumber,
                ':stfid' => $staffId,
                ':accid' => $accountId,
                ':pdate' => $paymentDate,
                ':amt'   => $amount,
                ':pmode' => $paymentMode,
                ':ref'   => $refNumber,
                ':notes' => $notes,
                ':uid'   => Auth::id()
            ]);
            $paymentId = (int)$db->lastInsertId();

            // Decrement Account
            $updAcc = $db->prepare("UPDATE financial_accounts SET current_balance = current_balance - :amt WHERE id = :id");
            $updAcc->execute([':amt' => $amount, ':id' => $accountId]);

            // Ledger
            $txnId = 'TXN-ADV-' . $voucherNumber;
            $balStmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE -amount END), 0) FROM ledger_entries WHERE entity_type='staff' AND entity_id=:stfid");
            $balStmt->execute([':stfid' => $staffId]);
            $staffBal = (float)$balStmt->fetchColumn() - $amount;

            $insLedger = $db->prepare("
                INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                VALUES (:txnid, :dt, :accid, 'staff', :stfid, 'debit', :amt, :rbal, 'staff_payment', :sid, :narr, :uid)
            ");
            $insLedger->execute([
                ':txnid' => $txnId,
                ':dt'    => $paymentDate,
                ':accid' => $accountId,
                ':stfid' => $staffId,
                ':amt'   => $amount,
                ':rbal'  => $staffBal,
                ':sid'   => $paymentId,
                ':narr'  => "Advance paid {$voucherNumber} ({$paymentMode})",
                ':uid'   => Auth::id()
            ]);

            $db->commit();
            Audit::log('CREATE', 'STAFF_PAYMENTS', $paymentId, null, $_POST);
            Helper::setFlash('success', "Advance voucher {$voucherNumber} of " . Helper::formatCurrency($amount) . " disbursed.");
            Helper::redirect('modules/staff_payments/index.php');
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Disbursement failed: ' . $e->getMessage();
        }
    }
}

$staffList = $db->query("SELECT id, full_name, staff_code, phone FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

$pageTitle = 'Disburse Staff Advance';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/staff_payments/index.php" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0">Disburse Staff Advance</h4>
            <div class="text-muted small">Record cash or UPI advance disbursement to be deducted against upcoming shift earnings.</div>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="custom-card p-4" style="max-width: 600px; margin: 0 auto;">
    <form method="POST" action="">
        <?= CSRF::tokenField() ?>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Staff Worker *</label>
            <select name="staff_id" class="form-select" required>
                <option value="">-- Choose Worker --</option>
                <?php foreach ($staffList as $stf): ?>
                    <option value="<?= $stf['id'] ?>" <?= $preStaffId == $stf['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label small fw-semibold">Date *</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Advance Amount (₹) *</label>
                <div class="input-group">
                    <span class="input-group-text bg-light">₹</span>
                    <input type="number" step="10" name="amount" class="form-control" placeholder="500" required>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label small fw-semibold">Paid From Account *</label>
                <select name="account_id" class="form-select" required>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>">
                            <?= htmlspecialchars($acc['account_name']) ?> (<?= Helper::formatCurrency($acc['current_balance']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Payment Mode</label>
                <select name="payment_mode" class="form-select">
                    <option value="cash">Cash</option>
                    <option value="upi">UPI (PhonePe / GPay)</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Reference / Receipt Number</label>
            <input type="text" name="reference_number" class="form-control" placeholder="e.g. Cash slip #102">
        </div>

        <div class="mb-4">
            <label class="form-label small fw-semibold">Reason / Purpose</label>
            <input type="text" name="notes" class="form-control" placeholder="e.g. Emergency medical advance / travel fare">
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="<?= BASE_URL ?>/modules/staff_payments/index.php" class="btn btn-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-warning btn-sm px-4 fw-semibold">
                <i class="bi bi-hand-thumbs-up me-1"></i> Disburse Advance
            </button>
        </div>
    </form>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
