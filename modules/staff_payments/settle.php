<?php
// modules/staff_payments/settle.php - Settle Staff Earnings & Salary Payouts
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();
$error = '';

$preStaffId = !empty($_GET['staff_id']) ? (int)$_GET['staff_id'] : null;

// Handle Settlement Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? 'cash';
    $refNumber = trim($_POST['reference_number'] ?? '');
    $periodStart = !empty($_POST['period_start']) ? $_POST['period_start'] : null;
    $periodEnd = !empty($_POST['period_end']) ? $_POST['period_end'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if (empty($staffId) || empty($accountId) || $amount <= 0) {
        $error = 'Staff member, disbursing Financial Account, and valid Amount are required.';
    } else {
        // Check account balance
        $accStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
        $accStmt->execute([':id' => $accountId]);
        $acc = $accStmt->fetch();

        if (!$acc || (float)$acc['current_balance'] < $amount) {
            $error = "Insufficient funds in '{$acc['account_name']}'. Current balance is " . Helper::formatCurrency($acc['current_balance'] ?? 0);
        } else {
            // Generate Voucher Number: SAL-YYYYMM-XXXX
            $seqStmt = $db->query("SELECT COUNT(id) FROM staff_payments WHERE YEAR(payment_date) = YEAR(CURDATE())");
            $seq = (int)$seqStmt->fetchColumn() + 1;
            $voucherNumber = 'SAL-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            $db->beginTransaction();
            try {
                // 1. Insert Staff Payment
                $stmt = $db->prepare("
                    INSERT INTO staff_payments (payment_number, staff_id, account_id, payment_type, payment_date,
                                               amount, payment_mode, reference_number, settlement_period_start,
                                               settlement_period_end, notes, processed_by)
                    VALUES (:vnum, :stfid, :accid, 'salary_settlement', :pdate, :amt, :pmode, :ref, :pstart, :pend, :notes, :uid)
                ");
                $stmt->execute([
                    ':vnum'   => $voucherNumber,
                    ':stfid'  => $staffId,
                    ':accid'  => $accountId,
                    ':pdate'  => $paymentDate,
                    ':amt'    => $amount,
                    ':pmode'  => $paymentMode,
                    ':ref'    => $refNumber,
                    ':pstart' => $periodStart,
                    ':pend'   => $periodEnd,
                    ':notes'  => $notes,
                    ':uid'    => Auth::id()
                ]);
                $paymentId = (int)$db->lastInsertId();

                // 2. Decrement disbursing Financial Account
                $updAcc = $db->prepare("UPDATE financial_accounts SET current_balance = current_balance - :amt WHERE id = :id");
                $updAcc->execute([':amt' => $amount, ':id' => $accountId]);

                // 3. Flag attendance as settled (FIFO & Balance-aware) - Using Bound Prepared Statements
                $earnedStmt = $db->prepare("SELECT COALESCE(SUM(final_staff_amount), 0) FROM attendance WHERE staff_id = :stfid AND status IN ('present', 'half_day')");
                $earnedStmt->execute([':stfid' => $staffId]);
                $totEarned = (float)$earnedStmt->fetchColumn();

                $paidStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM staff_payments WHERE staff_id = :stfid");
                $paidStmt->execute([':stfid' => $staffId]);
                $totPaid = (float)$paidStmt->fetchColumn();

                if ($totPaid >= $totEarned) {
                    // All shifts are completely settled
                    $db->prepare("UPDATE attendance SET is_settled = 1 WHERE staff_id = :stfid AND status IN ('present', 'half_day')")
                       ->execute([':stfid' => $staffId]);
                } else {
                    // FIFO settlement: allocate total paid across shifts chronologically
                    $allAtts = $db->prepare("SELECT id, final_staff_amount FROM attendance WHERE staff_id = :stfid AND status IN ('present', 'half_day') ORDER BY attendance_date ASC, id ASC");
                    $allAtts->execute([':stfid' => $staffId]);
                    $unsettledList = $allAtts->fetchAll();

                    $remainingPayout = $totPaid;
                    $settleIds = [];
                    foreach ($unsettledList as $row) {
                        $rowAmt = (float)$row['final_staff_amount'];
                        if ($remainingPayout >= $rowAmt) {
                            $settleIds[] = (int)$row['id'];
                            $remainingPayout -= $rowAmt;
                        } else {
                            break;
                        }
                    }
                    if (!empty($settleIds)) {
                        $inClause = implode(',', $settleIds);
                        $db->exec("UPDATE attendance SET is_settled = 1 WHERE id IN ({$inClause})");
                    }
                    // Also honor specific period date range if supplied
                    if ($periodStart && $periodEnd) {
                        $db->prepare("UPDATE attendance SET is_settled = 1 WHERE staff_id = :stfid AND attendance_date BETWEEN :start AND :end")
                           ->execute([':stfid' => $staffId, ':start' => $periodStart, ':end' => $periodEnd]);
                    }
                }

                // 4. Post Balanced Ledger Entries
                $txnId = 'TXN-SPAY-' . $voucherNumber;

                // Staff Payable Debit
                $balStmt = $db->prepare("
                    SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE -amount END), 0)
                    FROM ledger_entries WHERE entity_type='staff' AND entity_id=:stfid
                ");
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
                    ':narr'  => "Salary settlement voucher {$voucherNumber} via {$paymentMode}",
                    ':uid'   => Auth::id()
                ]);

                // Financial Account Credit
                $newAccBal = (float)$acc['current_balance'] - $amount;
                $insAcc = $db->prepare("
                    INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                    VALUES (:txnid, :dt, :accid, 'financial_account', :accid, 'credit', :amt, :rbal, 'staff_payment', :sid, :narr, :uid)
                ");
                $insAcc->execute([
                    ':txnid' => $txnId,
                    ':dt'    => $paymentDate,
                    ':accid' => $accountId,
                    ':amt'   => $amount,
                    ':rbal'  => $newAccBal,
                    ':sid'   => $paymentId,
                    ':narr'  => "Wage disbursement {$voucherNumber} to Staff #{$staffId}",
                    ':uid'   => Auth::id()
                ]);

                $db->commit();
                Audit::log('CREATE', 'STAFF_PAYMENTS', $paymentId, null, $_POST);
                Helper::setFlash('success', "Salary payout {$voucherNumber} of " . Helper::formatCurrency($amount) . " successfully disbursed!");
                Helper::redirect('modules/staff_payments/index.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Disbursement failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch staff list with individual balances
$staffList = $db->query("
    SELECT s.*,
        (SELECT COALESCE(SUM(a.final_staff_amount), 0) FROM attendance a WHERE a.staff_id = s.id AND a.status IN ('present', 'half_day')) as total_earned,
        (SELECT COALESCE(SUM(sp.amount), 0) FROM staff_payments sp WHERE sp.staff_id = s.id) as total_paid
    FROM staff s
    WHERE s.status = 'active'
    ORDER BY s.full_name ASC
")->fetchAll();

// Financial Accounts
$accounts = $db->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

$pageTitle = 'Settle Staff Salary';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/staff_payments/index.php" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0">Settle Staff Salary Payout</h4>
            <div class="text-muted small">Disburse earned shift wages from cash, bank, or company UPI accounts.</div>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="custom-card p-4">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Select Worker to Settle *</label>
                        <select name="staff_id" id="settleStaffSelect" class="form-select" required onchange="handleStaffChange(this)">
                            <option value="">-- Choose Worker --</option>
                            <?php foreach ($staffList as $stf): 
                                $bal = (float)$stf['total_earned'] - (float)$stf['total_paid'];
                            ?>
                                <option value="<?= $stf['id'] ?>" 
                                        data-balance="<?= $bal ?>"
                                        data-earned="<?= $stf['total_earned'] ?>"
                                        data-paid="<?= $stf['total_paid'] ?>"
                                        data-bank="<?= htmlspecialchars($stf['bank_name'] ?: '') ?>"
                                        data-acc="<?= htmlspecialchars($stf['bank_account_no'] ?: '') ?>"
                                        data-ifsc="<?= htmlspecialchars($stf['bank_ifsc'] ?: '') ?>"
                                        data-upi="<?= htmlspecialchars($stf['upi_id'] ?: '') ?>"
                                        <?= $preStaffId == $stf['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?>) &bull; Due: <?= Helper::formatCurrency($bal) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Worker Balance Preview Box -->
                    <div class="col-12">
                        <div id="workerMetricsBox" class="p-3 bg-light rounded-3 border d-none">
                            <div class="row text-center g-2">
                                <div class="col-4">
                                    <div class="text-muted small" style="font-size:0.7rem;">TOTAL ACCRUED</div>
                                    <strong id="previewEarned" class="text-dark fs-6">₹0.00</strong>
                                </div>
                                <div class="col-4 border-start border-end">
                                    <div class="text-muted small" style="font-size:0.7rem;">ALREADY PAID</div>
                                    <strong id="previewPaid" class="text-secondary fs-6">₹0.00</strong>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted small" style="font-size:0.7rem;">NET BALANCE DUE</div>
                                    <strong id="previewBalance" class="text-danger fs-6">₹0.00</strong>
                                </div>
                            </div>
                            <div id="workerBankBox" class="mt-3 pt-2 border-top small text-muted"></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Disbursement Date *</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Payout Amount (₹) *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">₹</span>
                            <input type="number" step="1" name="amount" id="payoutAmountInput" class="form-control" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Disbursed From Account *</label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= Helper::formatCurrency($acc['current_balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Payment Mode</label>
                        <select name="payment_mode" class="form-select">
                            <option value="cash">Cash in Hand</option>
                            <option value="upi">UPI Transfer (PhonePe / GPay)</option>
                            <option value="bank_transfer">Bank NEFT / IMPS</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Transaction Reference / UTR Number</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="e.g. UPI Ref / Cash voucher #">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Settlement Period Start</label>
                        <input type="date" name="period_start" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Settlement Period End</label>
                        <input type="date" name="period_end" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Voucher Narration / Remarks</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Full shift salary clearance for 1st fortnight">
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="<?= BASE_URL ?>/modules/staff_payments/index.php" class="btn btn-secondary btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-cash-coin me-1"></i> Disburse Payout & Settle Shifts
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="custom-card p-3">
            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-shield-check text-success me-1"></i> Settlement Integrity</h6>
            <div class="small text-muted">
                <p>When you disburse a salary settlement:</p>
                <ul>
                    <li>The selected financial account balance is automatically decremented.</li>
                    <li>The worker's attendance records in the period are marked as <strong>Settled</strong>.</li>
                    <li>Double-entry ledger entry debits Staff Payable and credits Cash/Bank.</li>
                    <li>Worker's live passbook reflects the settlement instantly.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function handleStaffChange(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const box = document.getElementById('workerMetricsBox');

    if (!opt.value) {
        box.classList.add('d-none');
        return;
    }

    box.classList.remove('d-none');
    const bal = parseFloat(opt.dataset.balance || 0);
    const earned = parseFloat(opt.dataset.earned || 0);
    const paid = parseFloat(opt.dataset.paid || 0);

    document.getElementById('previewEarned').textContent = '₹' + earned.toFixed(2);
    document.getElementById('previewPaid').textContent = '₹' + paid.toFixed(2);
    document.getElementById('previewBalance').textContent = '₹' + bal.toFixed(2);
    document.getElementById('payoutAmountInput').value = bal > 0 ? bal : 0;

    let bankInfo = '<strong>Worker Bank / UPI Details:</strong> ';
    if (opt.dataset.bank) {
        bankInfo += `${opt.dataset.bank} A/C: ${opt.dataset.acc} (IFSC: ${opt.dataset.ifsc}) `;
    }
    if (opt.dataset.upi) {
        bankInfo += `&bull; UPI: <span class="text-success">${opt.dataset.upi}</span>`;
    }
    if (!opt.dataset.bank && !opt.dataset.upi) {
        bankInfo += 'No bank or UPI on file. Pay via Cash.';
    }
    document.getElementById('workerBankBox').innerHTML = bankInfo;
}

document.addEventListener('DOMContentLoaded', () => {
    const selectEl = document.getElementById('settleStaffSelect');
    if (selectEl.value) {
        handleStaffChange(selectEl);
    }
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
