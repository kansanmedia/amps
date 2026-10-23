<?php
// modules/staff/view.php - Staff Profile & Earnings Ledger
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$staffId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT s.*, sc.category_name, sc.category_code
    FROM staff s
    LEFT JOIN staff_categories sc ON s.category_id = sc.id
    WHERE s.id = :id
");
$stmt->execute([':id' => $staffId]);
$staff = $stmt->fetch();

if (!$staff) {
    Helper::setFlash('danger', 'Staff member not found.');
    Helper::redirect('modules/staff/index.php');
}

// Fetch active accounts for disbursement
$accounts = $db->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

// Handle Payment or Advance Submission directly on Staff Profile & Ledger
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    Auth::requireRole(['owner_admin', 'manager', 'accountant']);
    $action = $_POST['action'] ?? '';

    if ($action === 'settle_salary') {
        $postStaffId = (int)($_POST['staff_id'] ?? 0);
        $accountId   = (int)($_POST['account_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount      = (float)($_POST['amount'] ?? 0);
        $paymentMode = $_POST['payment_mode'] ?? 'cash';
        $refNumber   = trim($_POST['reference_number'] ?? '');
        $periodStart = !empty($_POST['period_start']) ? $_POST['period_start'] : null;
        $periodEnd   = !empty($_POST['period_end']) ? $_POST['period_end'] : null;
        $notes       = trim($_POST['notes'] ?? '');

        if ($postStaffId !== $staffId || empty($accountId) || $amount <= 0) {
            Helper::setFlash('danger', 'Valid financial account and positive amount are required.');
        } elseif ($balanceDue <= 0) {
            Helper::setFlash('warning', 'Staff has no pending due balance to settle.');
        } else {
            // Check account balance
            $accStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
            $accStmt->execute([':id' => $accountId]);
            $acc = $accStmt->fetch();

            if (!$acc || (float)$acc['current_balance'] < $amount) {
                Helper::setFlash('danger', "Insufficient funds in '{$acc['account_name']}'. Current balance is " . Helper::formatCurrency($acc['current_balance'] ?? 0));
            } else {
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

                    // 3. FIFO Settlement allocation - Using Bound Prepared Statements
                    $earnedStmt = $db->prepare("SELECT COALESCE(SUM(final_staff_amount), 0) FROM attendance WHERE staff_id = :stfid AND status IN ('present', 'half_day')");
                    $earnedStmt->execute([':stfid' => $staffId]);
                    $totEarned = (float)$earnedStmt->fetchColumn();

                    $paidStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM staff_payments WHERE staff_id = :stfid");
                    $paidStmt->execute([':stfid' => $staffId]);
                    $totPaid   = (float)$paidStmt->fetchColumn();

                    if ($totPaid >= $totEarned) {
                        $db->prepare("UPDATE attendance SET is_settled = 1 WHERE staff_id = :stfid AND status IN ('present', 'half_day')")
                           ->execute([':stfid' => $staffId]);
                    } else {
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
                    }

                    // 4. Balanced Ledger Entries
                    $txnId = 'TXN-SPAY-' . $voucherNumber;
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
                        ':narr'  => "Salary payout voucher {$voucherNumber} via {$paymentMode}",
                        ':uid'   => Auth::id()
                    ]);

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
                        ':narr'  => "Wage payout {$voucherNumber} to Staff #{$staffId}",
                        ':uid'   => Auth::id()
                    ]);

                    $db->commit();
                    Audit::log('CREATE', 'STAFF_PAYMENTS', $paymentId, null, $_POST);
                    Helper::setFlash('success', "Salary payout {$voucherNumber} of " . Helper::formatCurrency($amount) . " successfully disbursed!");
                } catch (Exception $e) {
                    $db->rollBack();
                    Helper::setFlash('danger', 'Disbursement failed: ' . $e->getMessage());
                }
            }
        }
        Helper::redirect("modules/staff/view.php?id={$staffId}");
    } elseif ($action === 'disburse_advance') {
        $postStaffId = (int)($_POST['staff_id'] ?? 0);
        $accountId   = (int)($_POST['account_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount      = (float)($_POST['amount'] ?? 0);
        $paymentMode = $_POST['payment_mode'] ?? 'cash';
        $refNumber   = trim($_POST['reference_number'] ?? '');
        $notes       = trim($_POST['notes'] ?? 'Salary advance disbursement');

        if ($postStaffId !== $staffId || empty($accountId) || $amount <= 0) {
            Helper::setFlash('danger', 'Valid financial account and positive advance amount are required.');
        } else {
            $accStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
            $accStmt->execute([':id' => $accountId]);
            $acc = $accStmt->fetch();

            if (!$acc || (float)$acc['current_balance'] < $amount) {
                Helper::setFlash('danger', "Insufficient funds in '{$acc['account_name']}'. Current balance is " . Helper::formatCurrency($acc['current_balance'] ?? 0));
            } else {
                $seqStmt = $db->query("SELECT COUNT(id) FROM staff_payments WHERE YEAR(payment_date) = YEAR(CURDATE())");
                $seq = (int)$seqStmt->fetchColumn() + 1;
                $voucherNumber = 'ADV-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                $db->beginTransaction();
                try {
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

                    $updAcc = $db->prepare("UPDATE financial_accounts SET current_balance = current_balance - :amt WHERE id = :id");
                    $updAcc->execute([':amt' => $amount, ':id' => $accountId]);

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
                        ':narr'  => "Advance disbursed {$voucherNumber} to Staff #{$staffId}",
                        ':uid'   => Auth::id()
                    ]);

                    $db->commit();
                    Audit::log('CREATE', 'STAFF_PAYMENTS', $paymentId, null, $_POST);
                    Helper::setFlash('success', "Advance {$voucherNumber} of " . Helper::formatCurrency($amount) . " successfully disbursed!");
                } catch (Exception $e) {
                    $db->rollBack();
                    Helper::setFlash('danger', 'Advance disbursement failed: ' . $e->getMessage());
                }
            }
        }
        Helper::redirect("modules/staff/view.php?id={$staffId}");
    }
}

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
    SELECT a.*, d.deployment_date, d.client_rate, d.staff_rate as planned_staff_rate,
           c.company_name, s.site_name, sh.shift_name, sh.shift_code, sh.is_overnight
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
    SELECT sp.*, fa.account_name, u.full_name as processed_by_name
    FROM staff_payments sp
    JOIN financial_accounts fa ON sp.account_id = fa.id
    LEFT JOIN users u ON sp.processed_by = u.id
    WHERE sp.staff_id = :id
    ORDER BY sp.payment_date DESC, sp.id DESC
");
$payStmt->execute([':id' => $staffId]);
$paymentHistory = $payStmt->fetchAll();

$pageTitle = 'Staff Profile: ' . $staff['full_name'];
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/staff/index.php" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0"><?= htmlspecialchars($staff['full_name']) ?></h4>
            <div class="text-muted small mt-0.5">
                Staff Code: <strong><?= htmlspecialchars($staff['staff_code']) ?></strong> &bull; 
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <?= htmlspecialchars($staff['category_name'] ?? $staff['skill_category']) ?>
                    <?= !empty($staff['category_code']) ? "({$staff['category_code']})" : '' ?>
                </span>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (Auth::isManager() || Auth::isAccountant()): ?>
            <?php $canSettle = ($balanceDue > 0); ?>
            <?php if ($canSettle): ?>
                <button type="button" class="btn btn-sm btn-success fw-bold d-flex align-items-center gap-1.5 shadow-2xs" data-bs-toggle="modal" data-bs-target="#modalSettlePayment">
                    <i class="bi bi-cash-stack"></i> <span>Settle Payment (<?= Helper::formatCurrency($balanceDue) ?>)</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary fw-bold d-flex align-items-center gap-1.5 shadow-2xs disabled opacity-50" disabled style="cursor: not-allowed; pointer-events: auto;" title="<?= $balanceDue < 0 ? 'Cannot settle: Staff has negative due (Advance of ' . Helper::formatCurrency(abs($balanceDue)) . ' already disbursed)' : 'Cannot settle: Current outstanding due is ₹0.00' ?>">
                    <i class="bi bi-cash-stack"></i> <span>Settle Payment</span>
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-bold d-flex align-items-center gap-1.5 shadow-2xs" data-bs-toggle="modal" data-bs-target="#modalDisburseAdvance">
                <i class="bi bi-hand-thumbs-up"></i> <span>Pay Advance</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Metric Overview -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Shifts Attended</div>
            <div class="metric-value text-primary"><?= count($attendanceHistory) ?></div>
            <div class="text-muted small mt-1">Deployments mapped</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Earnings Accrued</div>
            <div class="metric-value text-dark"><?= Helper::formatCurrency($totalEarned) ?></div>
            <div class="text-muted small mt-1">Gross shift earnings</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Payouts Settled</div>
            <div class="metric-value text-success"><?= Helper::formatCurrency($totalPaid) ?></div>
            <div class="text-muted small mt-1">Disbursed cash/bank/UPI</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Current Outstanding Due</div>
            <div class="metric-value <?= $balanceDue > 0 ? 'text-danger' : ($balanceDue < 0 ? 'text-warning-emphasis' : 'text-secondary') ?>">
                <?= Helper::formatCurrency($balanceDue) ?>
            </div>
            <div class="text-muted small mt-1">
                <?php if ($balanceDue > 0): ?>
                    <span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Payable to staff</span>
                <?php elseif ($balanceDue < 0): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace py-0.5 px-1.5">
                        <i class="bi bi-arrow-down-left-circle me-1"></i>Advance / Excess Paid
                    </span>
                <?php else: ?>
                    <span class="text-muted"><i class="bi bi-check2-circle me-1 text-success"></i>All clear (No dues)</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Left Column: Profile & Banking Card -->
    <div class="col-lg-4">
        <div class="custom-card">
            <div class="custom-card-header">
                <h6 class="fw-bold mb-0">Profile Information</h6>
                <?= Helper::statusBadge($staff['status']) ?>
            </div>
            <div class="p-3">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Mobile Phone:</span>
                        <strong class="text-dark"><?= htmlspecialchars($staff['phone']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Emergency Contact:</span>
                        <span><?= htmlspecialchars($staff['emergency_contact'] ?: 'Not Provided') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Default Shift Rate:</span>
                        <strong class="text-primary"><?= Helper::formatCurrency($staff['default_payout_rate']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Joined Date:</span>
                        <span><?= Helper::formatDate($staff['joined_date']) ?></span>
                    </li>
                    <li class="list-group-item px-0">
                        <span class="text-muted d-block mb-1">Residential Address:</span>
                        <div class="text-dark"><?= nl2br(htmlspecialchars($staff['address'] ?: 'Not provided')) ?></div>
                    </li>
                </ul>

                <h6 class="fw-bold mt-4 mb-2 pb-1 border-bottom"><i class="bi bi-bank me-1 text-primary"></i> Banking & UPI</h6>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Bank Name:</span>
                        <strong class="text-dark"><?= htmlspecialchars($staff['bank_name'] ?: '-') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">A/C Number:</span>
                        <span><?= htmlspecialchars($staff['bank_account_no'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">IFSC Code:</span>
                        <span><?= htmlspecialchars($staff['bank_ifsc'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">UPI ID:</span>
                        <strong class="text-success"><?= htmlspecialchars($staff['upi_id'] ?: '-') ?></strong>
                    </li>
                </ul>

                <h6 class="fw-bold mt-4 mb-2 pb-1 border-bottom"><i class="bi bi-card-text me-1 text-primary"></i> KYC Identification</h6>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">ID Type:</span>
                        <span><?= htmlspecialchars($staff['id_proof_type'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">ID Number:</span>
                        <strong class="text-dark"><?= htmlspecialchars($staff['id_proof_number'] ?: '-') ?></strong>
                    </li>
                    <?php if (!empty($staff['id_proof_doc'])): ?>
                        <li class="list-group-item px-0 pt-2">
                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($staff['id_proof_doc']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="bi bi-paperclip me-1"></i> View Uploaded ID Document
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right Column: Shift Earning Ledger & Payments History Tabs -->
    <div class="col-lg-8">
        <div class="custom-card">
            <div class="custom-card-header p-0">
                <ul class="nav nav-tabs border-0 px-3 pt-2" id="staffLedgerTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-semibold" id="shifts-tab" data-bs-toggle="tab" data-bs-target="#shifts-pane" type="button" role="tab">
                            <i class="bi bi-calendar-check me-1 text-primary"></i> Shift Attendance & Earnings (<?= count($attendanceHistory) ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments-pane" type="button" role="tab">
                            <i class="bi bi-cash-stack me-1 text-success"></i> Payments & Advances Paid (<?= count($paymentHistory) ?>)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content p-3" id="staffLedgerTabContent">
                <!-- Shifts & Attendance Pane -->
                <div class="tab-pane fade show active" id="shifts-pane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Shift Date</th>
                                    <th>Client & Site</th>
                                    <th>Shift</th>
                                    <th>Status</th>
                                    <th>Hours</th>
                                    <th>Rate</th>
                                    <th>Earned</th>
                                    <th>Settled?</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($attendanceHistory)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No attendance recorded yet for this staff member.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($attendanceHistory as $att): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= Helper::formatDate($att['attendance_date']) ?></strong>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= htmlspecialchars($att['site_name']) ?></div>
                                                <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($att['company_name']) ?></div>
                                            </td>
                                            <td>
                                                <?= Helper::shiftBadge($att['shift_name'], $att['shift_code'], (bool)$att['is_overnight']) ?>
                                            </td>
                                            <td><?= Helper::statusBadge($att['status']) ?></td>
                                            <td><?= $att['hours_worked'] ?> hrs</td>
                                            <td><?= Helper::formatCurrency($att['planned_staff_rate']) ?></td>
                                            <td>
                                                <strong class="text-primary"><?= Helper::formatCurrency($att['final_staff_amount']) ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($att['is_settled'] || $balanceDue <= 0): ?>
                                                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check2"></i> Settled</span>
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

                <!-- Payments Pane -->
                <div class="tab-pane fade" id="payments-pane" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Voucher / Date</th>
                                    <th>Payment Type</th>
                                    <th>Paid From Account</th>
                                    <th>Mode & Ref</th>
                                    <th>Amount Paid</th>
                                    <th>Processed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentHistory)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-wallet2 text-secondary fs-3 d-block mb-1"></i>
                                            <div>No payment settlements or advances recorded yet for this worker.</div>
                                            <?php if (Auth::isManager() || Auth::isAccountant()): ?>
                                                <div class="mt-2 d-flex justify-content-center gap-2">
                                                    <button type="button" class="btn btn-xs btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#modalSettlePayment">
                                                        <i class="bi bi-cash-stack me-1"></i> Settle Payment
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-outline-warning text-dark fw-semibold" data-bs-toggle="modal" data-bs-target="#modalDisburseAdvance">
                                                        <i class="bi bi-hand-thumbs-up me-1"></i> Pay Advance
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentHistory as $pay): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= htmlspecialchars($pay['payment_number']) ?></strong>
                                                <div class="text-muted" style="font-size:0.72rem;"><?= Helper::formatDate($pay['payment_date']) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= ucwords(str_replace('_', ' ', $pay['payment_type'])) ?></span>
                                            </td>
                                            <td>
                                                <i class="bi bi-bank text-secondary me-1"></i> <?= htmlspecialchars($pay['account_name']) ?>
                                            </td>
                                            <td>
                                                <span class="text-uppercase fw-semibold" style="font-size:0.78rem;"><?= htmlspecialchars($pay['payment_mode']) ?></span>
                                                <?php if (!empty($pay['reference_number'])): ?>
                                                    <div class="text-muted" style="font-size:0.72rem;">Ref: <?= htmlspecialchars($pay['reference_number']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-success fs-6"><?= Helper::formatCurrency($pay['amount']) ?></strong>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($pay['processed_by_name'] ?? 'System') ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 1. MODAL: SETTLE SALARY PAYOUT -->
<div class="modal fade" id="modalSettlePayment" tabindex="-1" aria-labelledby="modalSettlePaymentLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="settle_salary">
                <input type="hidden" name="staff_id" value="<?= $staff['id'] ?>">

                <div class="modal-header bg-success text-white py-2.5 px-3">
                    <h6 class="modal-title fw-bold d-flex align-items-center gap-1.5" id="modalSettlePaymentLabel" style="font-size: 0.92rem;">
                        <i class="bi bi-cash-stack"></i> Settle Salary Payout
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3">
                    <!-- Staff Balance Snapshot Box -->
                    <div class="p-2.5 bg-light rounded-2 border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark"><?= htmlspecialchars($staff['full_name']) ?></span>
                            <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($staff['staff_code']) ?></span>
                        </div>
                        <div class="row g-2 pt-1 border-top mt-1" style="font-size: 0.76rem;">
                            <div class="col-4 text-center">
                                <span class="text-muted d-block">Accrued</span>
                                <strong class="text-dark"><?= Helper::formatCurrency($totalEarned) ?></strong>
                            </div>
                            <div class="col-4 text-center border-start border-end">
                                <span class="text-muted d-block">Paid</span>
                                <strong class="text-success"><?= Helper::formatCurrency($totalPaid) ?></strong>
                            </div>
                            <div class="col-4 text-center">
                                <span class="text-muted d-block">Balance Due</span>
                                <strong class="<?= $balanceDue > 0 ? 'text-danger' : 'text-secondary' ?>"><?= Helper::formatCurrency($balanceDue) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <!-- Disbursing Account -->
                        <div class="col-12 mb-2">
                            <label class="form-label small fw-semibold mb-1">Disburse From Financial Account <span class="text-danger">*</span></label>
                            <select name="account_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Cash / Bank Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= htmlspecialchars($acc['account_name']) ?> (Balance: <?= Helper::formatCurrency($acc['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Amount to Disburse -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Payout Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control form-control-sm fw-bold text-success" value="<?= $balanceDue > 0 ? $balanceDue : '' ?>" placeholder="0.00" required>
                        </div>

                        <!-- Payment Date -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Payment Mode -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Payment Mode</label>
                            <select name="payment_mode" class="form-select form-select-sm">
                                <option value="cash" selected>Cash</option>
                                <option value="upi">UPI</option>
                                <option value="bank_transfer">Bank Transfer / NEFT</option>
                            </select>
                        </div>

                        <!-- Reference Number -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Ref / UTR / Cheque No</label>
                            <input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Optional reference">
                        </div>

                        <!-- Period Range (Optional) -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small text-muted mb-1">Period From</label>
                            <input type="date" name="period_start" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label small text-muted mb-1">Period To</label>
                            <input type="date" name="period_end" class="form-control form-control-sm">
                        </div>

                        <!-- Narration / Notes -->
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1">Notes / Narration</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Shift wages settlement"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success fw-bold d-flex align-items-center gap-1.5 shadow-2xs">
                        <i class="bi bi-check2-circle"></i> Confirm & Settle Payout
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. MODAL: DISBURSE ADVANCE -->
<div class="modal fade" id="modalDisburseAdvance" tabindex="-1" aria-labelledby="modalDisburseAdvanceLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="disburse_advance">
                <input type="hidden" name="staff_id" value="<?= $staff['id'] ?>">

                <div class="modal-header bg-warning text-dark py-2.5 px-3">
                    <h6 class="modal-title fw-bold d-flex align-items-center gap-1.5" id="modalDisburseAdvanceLabel" style="font-size: 0.92rem;">
                        <i class="bi bi-hand-thumbs-up"></i> Disburse Staff Advance
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3">
                    <!-- Staff Info Box -->
                    <div class="p-2.5 bg-light rounded-2 border mb-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-bold text-dark d-block"><?= htmlspecialchars($staff['full_name']) ?></span>
                            <small class="text-muted">Staff Code: <?= htmlspecialchars($staff['staff_code']) ?></small>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">Current Due</small>
                            <strong class="<?= $balanceDue > 0 ? 'text-danger' : ($balanceDue < 0 ? 'text-warning-emphasis' : 'text-secondary') ?>"><?= Helper::formatCurrency($balanceDue) ?></strong>
                        </div>
                    </div>

                    <div class="row g-2">
                        <!-- Disbursing Account -->
                        <div class="col-12 mb-2">
                            <label class="form-label small fw-semibold mb-1">Disburse From Financial Account <span class="text-danger">*</span></label>
                            <select name="account_id" class="form-select form-select-sm" required>
                                <option value="">-- Select Cash / Bank Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= htmlspecialchars($acc['account_name']) ?> (Balance: <?= Helper::formatCurrency($acc['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Advance Amount -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Advance Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control form-control-sm fw-bold text-warning-emphasis" placeholder="e.g. 1000" required>
                        </div>

                        <!-- Payment Date -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Disbursement Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Payment Mode -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Payment Mode</label>
                            <select name="payment_mode" class="form-select form-select-sm">
                                <option value="cash" selected>Cash</option>
                                <option value="upi">UPI</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>

                        <!-- Reference Number -->
                        <div class="col-md-6 mb-2">
                            <label class="form-label small fw-semibold mb-1">Ref / UTR Number</label>
                            <input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Optional UPI/Bank Ref">
                        </div>

                        <!-- Reason / Notes -->
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1">Reason / Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Festival advance, emergency medical expense"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold d-flex align-items-center gap-1.5 shadow-2xs">
                        <i class="bi bi-check2-circle"></i> Confirm & Pay Advance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
