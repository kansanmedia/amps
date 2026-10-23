<?php
// modules/payments/index.php - Client Payment Receipts & Ledger Accounting
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();
$error = '';

$preClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$preInvoiceId = !empty($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : null;

// Auto-resolve client_id if only invoice_id is passed
if ($preInvoiceId && !$preClientId) {
    $invCidStmt = $db->prepare("SELECT client_id FROM invoices WHERE id = :id");
    $invCidStmt->execute([':id' => $preInvoiceId]);
    $preClientId = (int)$invCidStmt->fetchColumn() ?: null;
}

// Handle Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $invoiceId = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
        $rawMode = $_POST['payment_mode'] ?? 'bank_transfer';
        $accountId = (int)($_POST['account_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount = (float)($_POST['amount'] ?? 0);
        $refNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Extract payment mode and mapped account ID if combined as 'mode|accountId'
        if (strpos($rawMode, '|') !== false) {
            list($paymentMode, $accIdFromMode) = explode('|', $rawMode, 2);
            if ($accountId <= 0) {
                $accountId = (int)$accIdFromMode;
            }
        } else {
            $paymentMode = $rawMode;
        }

        // Fallback account resolution if missing
        if ($accountId <= 0) {
            if ($paymentMode === 'cash') {
                $accStmt = $db->query("SELECT id FROM financial_accounts WHERE account_type = 'cash' AND status = 'active' ORDER BY id ASC LIMIT 1");
            } elseif ($paymentMode === 'upi') {
                $accStmt = $db->query("SELECT id FROM financial_accounts WHERE account_type = 'upi' AND status = 'active' ORDER BY id ASC LIMIT 1");
            } else {
                $accStmt = $db->query("SELECT id FROM financial_accounts WHERE account_type = 'bank' AND status = 'active' ORDER BY id ASC LIMIT 1");
            }
            $accountId = (int)($accStmt ? $accStmt->fetchColumn() : 1);
        }

        if (empty($clientId) || empty($accountId) || $amount <= 0) {
            $error = 'Client, valid Payment Mode / Account, and positive Amount are required.';
        } else {
            // Generate Payment Receipt Number
            $seqStmt = $db->query("SELECT COUNT(id) FROM client_payments WHERE YEAR(payment_date) = YEAR(CURDATE())");
            $seq = (int)$seqStmt->fetchColumn() + 1;
            $paymentNumber = 'RCP-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            $db->beginTransaction();
            try {
                // 1. Insert Client Payment
                $stmt = $db->prepare("
                    INSERT INTO client_payments (payment_number, client_id, invoice_id, account_id, payment_date, amount, payment_mode, reference_number, notes, received_by)
                    VALUES (:pnum, :cid, :invid, :accid, :pdate, :amt, :pmode, :ref, :notes, :uid)
                ");
                $stmt->execute([
                    ':pnum'  => $paymentNumber,
                    ':cid'   => $clientId,
                    ':invid' => $invoiceId,
                    ':accid' => $accountId,
                    ':pdate' => $paymentDate,
                    ':amt'   => $amount,
                    ':pmode' => $paymentMode,
                    ':ref'   => $refNumber,
                    ':notes' => $notes,
                    ':uid'   => Auth::id()
                ]);
                $paymentId = (int)$db->lastInsertId();

                // 2. Increment receiving Financial Account current_balance
                $updAcc = $db->prepare("UPDATE financial_accounts SET current_balance = current_balance + :amt WHERE id = :id");
                $updAcc->execute([':amt' => $amount, ':id' => $accountId]);

                // 3. Update Invoices (FIFO Settlement of Prior Back Dues + Target Invoice + Advance)
                $remainingAmount = $amount;

                if ($invoiceId) {
                    // 3a. Settle any older unpaid back-due invoices for this client first (FIFO order)
                    $priorStmt = $db->prepare("
                        SELECT id, invoice_number, total_amount, paid_amount, balance_amount
                        FROM invoices
                        WHERE client_id = :cid
                          AND id < :invid
                          AND balance_amount > 0
                          AND payment_status IN ('unpaid', 'partially_paid')
                        ORDER BY invoice_date ASC, id ASC
                    ");
                    $priorStmt->execute([':cid' => $clientId, ':invid' => $invoiceId]);
                    $priorInvoices = $priorStmt->fetchAll();

                    foreach ($priorInvoices as $pInv) {
                        if ($remainingAmount <= 0) break;
                        $due = (float)$pInv['balance_amount'];
                        $payToPrior = min($remainingAmount, $due);
                        $newPriorPaid = (float)$pInv['paid_amount'] + $payToPrior;
                        $newPriorBal = (float)$pInv['total_amount'] - $newPriorPaid;
                        if ($newPriorBal < 0) $newPriorBal = 0.00;
                        $pStatus = ($newPriorBal <= 0) ? 'paid' : 'partially_paid';

                        $updPrior = $db->prepare("UPDATE invoices SET paid_amount = :paid, balance_amount = :bal, payment_status = :st WHERE id = :id");
                        $updPrior->execute([
                            ':paid' => $newPriorPaid,
                            ':bal'  => $newPriorBal,
                            ':st'   => $pStatus,
                            ':id'   => $pInv['id']
                        ]);

                        $remainingAmount -= $payToPrior;
                    }

                    // 3b. Apply remaining payment to target invoice ($invoiceId)
                    $invStmt = $db->prepare("SELECT total_amount, carried_dues, paid_amount, balance_amount FROM invoices WHERE id = :id");
                    $invStmt->execute([':id' => $invoiceId]);
                    $inv = $invStmt->fetch();
                    if ($inv) {
                        $netBillPayable = (float)$inv['total_amount'] + (float)($inv['carried_dues'] ?? 0.0);
                        $newPaid = (float)$inv['paid_amount'] + $remainingAmount;
                        $newBalance = $netBillPayable - $newPaid;
                        // Negative balance correctly represents ADVANCE / excess payment!
                        $status = ($newBalance <= 0) ? 'paid' : 'partially_paid';

                        $updInv = $db->prepare("UPDATE invoices SET paid_amount = :paid, balance_amount = :bal, payment_status = :st WHERE id = :id");
                        $updInv->execute([':paid' => $newPaid, ':bal' => $newBalance, ':st' => $status, ':id' => $invoiceId]);
                    }
                } else {
                    // 3c. No specific invoice selected: Settle client unpaid invoices in FIFO order
                    $unpaidStmt = $db->prepare("
                        SELECT id, invoice_number, total_amount, paid_amount, balance_amount
                        FROM invoices
                        WHERE client_id = :cid
                          AND balance_amount > 0
                          AND payment_status IN ('unpaid', 'partially_paid')
                        ORDER BY invoice_date ASC, id ASC
                    ");
                    $unpaidStmt->execute([':cid' => $clientId]);
                    $unpaidList = $unpaidStmt->fetchAll();

                    $lastUpdatedId = null;
                    foreach ($unpaidList as $uInv) {
                        if ($remainingAmount <= 0) break;
                        $due = (float)$uInv['balance_amount'];
                        $payAmount = min($remainingAmount, $due);
                        $newUPaid = (float)$uInv['paid_amount'] + $payAmount;
                        $newUBal = (float)$uInv['total_amount'] - $newUPaid;
                        if ($newUBal < 0) $newUBal = 0.00;
                        $uStatus = ($newUBal <= 0) ? 'paid' : 'partially_paid';

                        $updU = $db->prepare("UPDATE invoices SET paid_amount = :paid, balance_amount = :bal, payment_status = :st WHERE id = :id");
                        $updU->execute([
                            ':paid' => $newUPaid,
                            ':bal'  => $newUBal,
                            ':st'   => $uStatus,
                            ':id'   => $uInv['id']
                        ]);

                        $remainingAmount -= $payAmount;
                        $lastUpdatedId = $uInv['id'];
                    }

                    // If still excess and we updated invoices, apply negative balance / advance to latest invoice
                    if ($remainingAmount > 0 && $lastUpdatedId) {
                        $latestStmt = $db->prepare("SELECT total_amount, paid_amount FROM invoices WHERE id = :id");
                        $latestStmt->execute([':id' => $lastUpdatedId]);
                        $latestInv = $latestStmt->fetch();
                        if ($latestInv) {
                            $advPaid = (float)$latestInv['paid_amount'] + $remainingAmount;
                            $advBal = (float)$latestInv['total_amount'] - $advPaid;
                            $updAdv = $db->prepare("UPDATE invoices SET paid_amount = :paid, balance_amount = :bal, payment_status = 'paid' WHERE id = :id");
                            $updAdv->execute([':paid' => $advPaid, ':bal' => $advBal, ':id' => $lastUpdatedId]);
                        }
                    }
                }

                // 4. Post Ledger Entry: Credit Client Accounts Receivable
                $txnId = 'TXN-CPAY-' . $paymentNumber;
                $balStmt = $db->prepare("
                    SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END), 0)
                    FROM ledger_entries WHERE entity_type='client' AND entity_id=:cid
                ");
                $balStmt->execute([':cid' => $clientId]);
                $clientBal = (float)$balStmt->fetchColumn() - $amount;

                $insLedger = $db->prepare("
                    INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                    VALUES (:txnid, :dt, :accid, 'client', :cid, 'credit', :amt, :rbal, 'client_payment', :sid, :narr, :uid)
                ");
                $insLedger->execute([
                    ':txnid' => $txnId,
                    ':dt'    => $paymentDate,
                    ':accid' => $accountId,
                    ':cid'   => $clientId,
                    ':amt'   => $amount,
                    ':rbal'  => $clientBal,
                    ':sid'   => $paymentId,
                    ':narr'  => "Payment received ({$paymentMode}) Ref: {$refNumber} for " . ($invoiceId ? "Invoice #{$invoiceId}" : "Client on-account"),
                    ':uid'   => Auth::id()
                ]);

                // Also post debit to Financial Account ledger
                $accBalStmt = $db->prepare("SELECT current_balance FROM financial_accounts WHERE id = :id");
                $accBalStmt->execute([':id' => $accountId]);
                $accBal = (float)$accBalStmt->fetchColumn();

                $insAccLedger = $db->prepare("
                    INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                    VALUES (:txnid, :dt, :accid, 'financial_account', :accid, 'debit', :amt, :rbal, 'client_payment', :sid, :narr, :uid)
                ");
                $insAccLedger->execute([
                    ':txnid' => $txnId,
                    ':dt'    => $paymentDate,
                    ':accid' => $accountId,
                    ':amt'   => $amount,
                    ':rbal'  => $accBal,
                    ':sid'   => $paymentId,
                    ':narr'  => "Deposit from Client payment {$paymentNumber}",
                    ':uid'   => Auth::id()
                ]);

                $db->commit();
                Audit::log('CREATE', 'CLIENT_PAYMENTS', $paymentId, null, $_POST);
                Helper::setFlash('success', "Payment {$paymentNumber} of " . Helper::formatCurrency($amount) . " recorded successfully!");
                Helper::redirect('modules/payments/index.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Transaction failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch payment history
$query = "
    SELECT cp.*, c.company_name, c.client_code, fa.account_name, fa.account_type,
           i.invoice_number, u.full_name as received_by_name
    FROM client_payments cp
    JOIN clients c ON cp.client_id = c.id
    JOIN financial_accounts fa ON cp.account_id = fa.id
    LEFT JOIN invoices i ON cp.invoice_id = i.id
    LEFT JOIN users u ON cp.received_by = u.id
    ORDER BY cp.payment_date DESC, cp.id DESC
";
$payments = $db->query($query)->fetchAll();

// Dropdowns
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

// Build unified Payment Mode options mapped to Financial Accounts
$paymentModeOptions = [];
$defaultAccountId = 0;

$bankAccounts = array_filter($accounts, fn($a) => $a['account_type'] === 'bank');
$upiAccounts  = array_filter($accounts, fn($a) => $a['account_type'] === 'upi');
$cashAccounts = array_filter($accounts, fn($a) => $a['account_type'] === 'cash');

// 1. Bank Transfer / NEFT / RTGS
foreach ($bankAccounts as $ba) {
    if (!$defaultAccountId) $defaultAccountId = $ba['id'];
    $paymentModeOptions[] = [
        'mode'       => 'bank_transfer',
        'account_id' => $ba['id'],
        'value'      => 'bank_transfer|' . $ba['id'],
        'label'      => 'Bank Transfer / NEFT / RTGS (' . $ba['account_name'] . ')',
        'is_default' => count($paymentModeOptions) === 0
    ];
}

// 2. UPI
foreach ($upiAccounts as $ua) {
    if (!$defaultAccountId) $defaultAccountId = $ua['id'];
    $paymentModeOptions[] = [
        'mode'       => 'upi',
        'account_id' => $ua['id'],
        'value'      => 'upi|' . $ua['id'],
        'label'      => 'UPI - PhonePe / GPay / QR (' . $ua['account_name'] . ')',
        'is_default' => count($paymentModeOptions) === 0
    ];
}

// 3. Cash
foreach ($cashAccounts as $ca) {
    if (!$defaultAccountId) $defaultAccountId = $ca['id'];
    $paymentModeOptions[] = [
        'mode'       => 'cash',
        'account_id' => $ca['id'],
        'value'      => 'cash|' . $ca['id'],
        'label'      => 'Cash (' . $ca['account_name'] . ')',
        'is_default' => count($paymentModeOptions) === 0
    ];
}

// 4. Cheque (into bank accounts)
foreach ($bankAccounts as $ba) {
    $paymentModeOptions[] = [
        'mode'       => 'cheque',
        'account_id' => $ba['id'],
        'value'      => 'cheque|' . $ba['id'],
        'label'      => 'Cheque (' . $ba['account_name'] . ')',
        'is_default' => false
    ];
}

if (empty($paymentModeOptions)) {
    $firstAcc = !empty($accounts) ? $accounts[0]['id'] : 1;
    $defaultAccountId = $firstAcc;
    $paymentModeOptions[] = [
        'mode'       => 'bank_transfer',
        'account_id' => $firstAcc,
        'value'      => 'bank_transfer|' . $firstAcc,
        'label'      => 'Bank Transfer / NEFT / RTGS',
        'is_default' => true
    ];
}

// Unpaid Invoices for modal selection (with prior back-due calculation)
$unpaidInvoicesRaw = $db->query("SELECT id, invoice_number, client_id, balance_amount FROM invoices WHERE payment_status != 'paid' AND balance_amount > 0 ORDER BY id DESC")->fetchAll();
$unpaidInvoices = [];
foreach ($unpaidInvoicesRaw as $ui) {
    $priorDueStmt = $db->prepare("SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE client_id = :cid AND id < :id AND balance_amount > 0 AND payment_status != 'paid'");
    $priorDueStmt->execute([':cid' => $ui['client_id'], ':id' => $ui['id']]);
    $priorDue = (float)$priorDueStmt->fetchColumn();
    $ui['prior_back_due'] = $priorDue;
    $ui['net_payable'] = (float)$ui['balance_amount'] + $priorDue;
    $unpaidInvoices[] = $ui;
}

$totalReceived = array_sum(array_column($payments, 'amount'));

$pageTitle = 'Client Payments';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-cash-stack text-primary me-2"></i>Client Collections & Payments</h4>
        <p class="text-muted small mb-0">Record incoming client settlements via Bank, Cash, or UPI and automatically update invoice balances.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openPaymentModal()">
            <i class="bi bi-plus-lg me-1"></i> Record Client Payment
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Total Collections Recorded</div>
            <div class="metric-value text-success"><?= Helper::formatCurrency($totalReceived) ?></div>
            <div class="text-muted small mt-1"><?= count($payments) ?> Receipts logged</div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="custom-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-bold text-muted text-uppercase">Account Deposit Hubs</span>
                <a href="<?= BASE_URL ?>/modules/accounting/accounts.php" class="small text-decoration-none">View All Ledgers &rarr;</a>
            </div>
            <div class="row g-2">
                <?php foreach ($accounts as $acc): ?>
                    <div class="col-sm-3 col-6">
                        <div class="p-2 bg-light rounded border">
                            <div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($acc['account_name']) ?></div>
                            <strong class="text-dark fs-6"><?= Helper::formatCurrency($acc['current_balance']) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payments History Table -->
<div class="custom-card">
    <div class="custom-card-header">
        <h6 class="fw-bold mb-0">Payment Receipts Log</h6>
        <input type="text" class="form-control form-control-sm" placeholder="Search receipt, client or ref..." data-table-search="#paymentsTable" style="width: 240px;">
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="paymentsTable">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Payment Date</th>
                    <th>Client Company</th>
                    <th>Linked Invoice</th>
                    <th>Credited Account</th>
                    <th>Payment Mode</th>
                    <th>Reference / UTR</th>
                    <th>Amount Received</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No payments recorded yet. Click "Record Client Payment" to log one.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong class="text-dark"><?= htmlspecialchars($p['payment_number']) ?></strong></td>
                            <td><?= Helper::formatDate($p['payment_date']) ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($p['company_name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($p['client_code']) ?></div>
                            </td>
                            <td>
                                <?php if ($p['invoice_number']): ?>
                                    <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $p['invoice_id'] ?>" class="badge bg-primary-subtle text-primary text-decoration-none">
                                        <?= htmlspecialchars($p['invoice_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">On-Account / Advance</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <i class="bi bi-bank text-secondary me-1"></i> <?= htmlspecialchars($p['account_name']) ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border text-uppercase" style="font-size:0.75rem;">
                                    <?= htmlspecialchars($p['payment_mode']) ?>
                                </span>
                            </td>
                            <td>
                                <code class="text-dark"><?= htmlspecialchars($p['reference_number'] ?: '-') ?></code>
                            </td>
                            <td>
                                <strong class="text-success fs-6"><?= Helper::formatCurrency($p['amount']) ?></strong>
                            </td>
                            <td>
                                <small class="text-muted"><?= htmlspecialchars($p['received_by_name'] ?? 'System') ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="record_payment">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Record Client Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Client *</label>
                            <select name="client_id" id="modal_pay_cid" class="form-select" required onchange="onClientChanged(this.value)">
                                <option value="">-- Choose Client --</option>
                                <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>" <?= $preClientId == $cl['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cl['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Apply to Invoice (Optional)</label>
                            <select name="invoice_id" id="modal_pay_invid" class="form-select" onchange="onInvoiceChanged(this)" <?= empty($preClientId) ? 'disabled' : '' ?>>
                                <option value="">-- Choose Client First --</option>
                            </select>
                            <div id="client_due_summary" class="form-text small mt-1 d-none"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount Received (₹) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="0.5" name="amount" id="modal_pay_amount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Payment Mode *</label>
                            <select name="payment_mode" id="modal_payment_mode" class="form-select" required onchange="syncPaymentAccount(this)">
                                <?php foreach ($paymentModeOptions as $opt): ?>
                                    <option value="<?= $opt['value'] ?>" data-account-id="<?= $opt['account_id'] ?>" data-mode="<?= $opt['mode'] ?>" <?= $opt['is_default'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="account_id" id="modal_pay_account_id" value="<?= $defaultAccountId ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Transaction Reference / UTR Number</label>
                            <input type="text" name="reference_number" id="modal_pay_ref" class="form-control" placeholder="e.g. UTR12345678 or Cheque #987654">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Internal Notes</label>
                            <input type="text" name="notes" id="modal_pay_notes" class="form-control" placeholder="e.g. Cleared against invoice billing">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-check2-circle me-1"></i> Confirm & Post Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
const unpaidInvoicesData = <?= json_encode($unpaidInvoices) ?>;

function openPaymentModal() {
    const cidSelect = document.getElementById('modal_pay_cid');
    cidSelect.value = '';
    onClientChanged('');
    document.getElementById('modal_pay_amount').value = '';
    const refInput = document.getElementById('modal_pay_ref');
    if (refInput) refInput.value = '';
    const notesInput = document.getElementById('modal_pay_notes');
    if (notesInput) notesInput.value = '';
    paymentModal.show();
}

function onClientChanged(clientId, targetInvoiceId = null) {
    const invSelect = document.getElementById('modal_pay_invid');
    const amountInput = document.getElementById('modal_pay_amount');
    const dueSummary = document.getElementById('client_due_summary');

    invSelect.innerHTML = '';

    if (!clientId) {
        invSelect.disabled = true;
        invSelect.innerHTML = '<option value="">-- Choose Client First --</option>';
        amountInput.value = '';
        if (dueSummary) {
            dueSummary.classList.add('d-none');
            dueSummary.innerHTML = '';
        }
        return;
    }

    invSelect.disabled = false;

    // Filter unpaid invoices for this client only
    const clientInvoices = unpaidInvoicesData.filter(inv => String(inv.client_id) === String(clientId));

    if (clientInvoices.length === 0) {
        invSelect.innerHTML = '<option value="">-- No Pending Invoices (On-Account / Advance) --</option>';
        amountInput.value = '';
        if (dueSummary) {
            dueSummary.classList.remove('d-none');
            dueSummary.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>No pending dues for this client. You can record advance / on-account payment.</span>';
        }
        return;
    }

    const totalClientDue = clientInvoices.reduce((sum, inv) => sum + parseFloat(inv.balance_amount || 0), 0);

    // Option 1: On-Account / Settle All Dues (FIFO)
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = '-- On-Account / Settle All Dues (FIFO) --';
    invSelect.appendChild(defaultOpt);

    // Option 2..N: Specific invoices
    clientInvoices.forEach(inv => {
        const opt = document.createElement('option');
        opt.value = inv.id;
        opt.dataset.bal = inv.net_payable;
        opt.dataset.billBal = inv.balance_amount;

        let label = inv.invoice_number + ' (Payable: ₹' + Number(inv.net_payable).toLocaleString('en-IN', {minimumFractionDigits: 2});
        if (parseFloat(inv.prior_back_due) > 0) {
            label += ' [Bill: ₹' + Number(inv.balance_amount).toLocaleString('en-IN') + ' + Prior Due: ₹' + Number(inv.prior_back_due).toLocaleString('en-IN') + ']';
        }
        label += ')';
        opt.textContent = label;

        if (targetInvoiceId && String(inv.id) === String(targetInvoiceId)) {
            opt.selected = true;
        }
        invSelect.appendChild(opt);
    });

    if (dueSummary) {
        dueSummary.classList.remove('d-none');
        dueSummary.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-info-circle me-1"></i>' + clientInvoices.length + ' Unpaid Invoice(s) found (Total Pending: ₹' + Number(totalClientDue).toLocaleString('en-IN', {minimumFractionDigits: 2}) + ')</span>';
    }

    if (targetInvoiceId) {
        onInvoiceChanged(invSelect);
    } else {
        amountInput.value = '';
    }
}

function onInvoiceChanged(selectEl) {
    const amountInput = document.getElementById('modal_pay_amount');
    const selectedOption = selectEl.options[selectEl.selectedIndex];

    if (selectedOption && selectedOption.dataset && selectedOption.dataset.bal) {
        amountInput.value = selectedOption.dataset.bal;
    } else {
        amountInput.value = '';
    }
}

function syncPaymentAccount(selectEl) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    const accIdInput = document.getElementById('modal_pay_account_id');
    if (selectedOpt && selectedOpt.dataset.accountId) {
        accIdInput.value = selectedOpt.dataset.accountId;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const modeSelect = document.getElementById('modal_payment_mode');
    if (modeSelect) {
        syncPaymentAccount(modeSelect);
    }

    <?php if ($preClientId || $preInvoiceId): ?>
        paymentModal.show();
        const preCid = '<?= $preClientId ?? '' ?>';
        const preInvid = '<?= $preInvoiceId ?? '' ?>';
        if (preCid) {
            document.getElementById('modal_pay_cid').value = preCid;
            onClientChanged(preCid, preInvid);
        }
    <?php endif; ?>
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
