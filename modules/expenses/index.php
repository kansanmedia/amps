<?php
// modules/expenses/index.php - Business Expenses & Outflow Tracking
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant', 'manager']);

$db = DB::getConnection();
$error = '';

// Handle New Expense Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'record_expense') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
        $amount = (float)($_POST['amount'] ?? 0);
        $clientId = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
        $siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $vendorName = trim($_POST['vendor_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Default to Miscellaneous Expenses (id: 8) if not explicitly supplied
        $defaultCat = $db->query("SELECT id FROM expense_categories WHERE id = 8 LIMIT 1")->fetch();
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : ($defaultCat['id'] ?? 1);

        if (empty($accountId) || $amount <= 0 || empty($description)) {
            $error = 'Paying Account, Amount, and Expense Detail are required.';
        } else {
            // Check account balance
            $accStmt = $db->prepare("SELECT account_name, current_balance FROM financial_accounts WHERE id = :id");
            $accStmt->execute([':id' => $accountId]);
            $acc = $accStmt->fetch();

            if (!$acc || (float)$acc['current_balance'] < $amount) {
                $error = "Insufficient funds in '{$acc['account_name']}'. Current balance is " . Helper::formatCurrency($acc['current_balance'] ?? 0);
            } else {
                // Handle receipt upload
                $receiptImage = null;
                if (!empty($_FILES['receipt_file']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fname = 'rec_' . time() . '_' . rand(100, 999) . '.' . $ext;
                        $target = UPLOAD_DIR . 'receipts/' . $fname;
                        if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $target)) {
                            $receiptImage = 'uploads/receipts/' . $fname;
                        }
                    }
                }

                // Generate Expense Number: EXP-YYYYMM-XXXX
                $seqStmt = $db->query("SELECT COUNT(id) FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE())");
                $seq = (int)$seqStmt->fetchColumn() + 1;
                $expenseNumber = 'EXP-' . date('Ym') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                $db->beginTransaction();
                try {
                    // 1. Insert Expense
                    $stmt = $db->prepare("
                        INSERT INTO expenses (expense_number, category_id, account_id, expense_date, amount, client_id, site_id, staff_id, vendor_name, description, receipt_image, created_by)
                        VALUES (:num, :cat, :acc, :dt, :amt, :cid, :sid, :stfid, :vnd, :desc, :rcpt, :uid)
                    ");
                    $stmt->execute([
                        ':num'   => $expenseNumber,
                        ':cat'   => $categoryId,
                        ':acc'   => $accountId,
                        ':dt'    => $expenseDate,
                        ':amt'   => $amount,
                        ':cid'   => $clientId,
                        ':sid'   => $siteId,
                        ':stfid' => $staffId,
                        ':vnd'   => $vendorName,
                        ':desc'  => $description,
                        ':rcpt'  => $receiptImage,
                        ':uid'   => Auth::id()
                    ]);
                    $expenseId = (int)$db->lastInsertId();

                    // 2. Decrement Financial Account
                    $updAcc = $db->prepare("UPDATE financial_accounts SET current_balance = current_balance - :amt WHERE id = :id");
                    $updAcc->execute([':amt' => $amount, ':id' => $accountId]);

                    // 3. Post to Ledger
                    $newBal = (float)$acc['current_balance'] - $amount;
                    $txnId = 'TXN-EXP-' . $expenseNumber;

                    $insLedger = $db->prepare("
                        INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by)
                        VALUES (:txnid, :dt, :accid, 'financial_account', :accid, 'credit', :amt, :rbal, 'expense', :sid, :narr, :uid)
                    ");
                    $insLedger->execute([
                        ':txnid' => $txnId,
                        ':dt'    => $expenseDate,
                        ':accid' => $accountId,
                        ':amt'   => $amount,
                        ':rbal'  => $newBal,
                        ':sid'   => $expenseId,
                        ':narr'  => "Expense: {$description} (Vendor: {$vendorName})",
                        ':uid'   => Auth::id()
                    ]);

                    $db->commit();
                    Audit::log('CREATE', 'EXPENSES', $expenseId, null, $_POST);
                    Helper::setFlash('success', "Expense {$expenseNumber} of " . Helper::formatCurrency($amount) . " logged.");
                    Helper::redirect('modules/expenses/index.php');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to record expense: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch expenses list
$query = "
    SELECT e.*, ec.category_name, fa.account_name, fa.account_type,
           c.company_name, s.site_name, stf.full_name as staff_name, u.full_name as created_by_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    JOIN financial_accounts fa ON e.account_id = fa.id
    LEFT JOIN clients c ON e.client_id = c.id
    LEFT JOIN sites s ON e.site_id = s.id
    LEFT JOIN staff stf ON e.staff_id = stf.id
    LEFT JOIN users u ON e.created_by = u.id
    ORDER BY e.expense_date DESC, e.id DESC
";
$expenses = $db->query($query)->fetchAll();

// Dropdowns & Accounts Data
$categories = $db->query("SELECT id, category_name FROM expense_categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type, bank_name, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$sites = $db->query("SELECT id, site_name, client_id FROM sites WHERE status = 'active' ORDER BY site_name ASC")->fetchAll();
$staff = $db->query("SELECT id, full_name, staff_code FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Fetch Staff Payouts (Salary Settlements & Advances)
$staffPayQuery = "
    SELECT sp.*, stf.full_name as staff_name, stf.staff_code, stf.phone as staff_phone,
           fa.account_name, fa.account_type, u.full_name as processed_by_name
    FROM staff_payments sp
    JOIN staff stf ON sp.staff_id = stf.id
    JOIN financial_accounts fa ON sp.account_id = fa.id
    LEFT JOIN users u ON sp.processed_by = u.id
    ORDER BY sp.payment_date DESC, sp.id DESC
";
$staffPayments = $db->query($staffPayQuery)->fetchAll();
$totalStaffPayments = array_sum(array_column($staffPayments, 'amount'));
$totalAllOutflows = $totalExpenses + $totalStaffPayments;

// Compute real-time remaining account balances for the top strip
$cashBalance = 0.00;
$bankBalance = 0.00;
$upiBalance = 0.00;
$totalAvailableBalance = 0.00;
$primaryBankName = 'HDFC Bank';

foreach ($accounts as $acc) {
    $bal = (float)$acc['current_balance'];
    $totalAvailableBalance += $bal;
    if ($acc['account_type'] === 'cash') {
        $cashBalance += $bal;
    } elseif ($acc['account_type'] === 'bank') {
        $bankBalance += $bal;
        if (!empty($acc['bank_name'])) $primaryBankName = $acc['bank_name'];
    } elseif ($acc['account_type'] === 'upi') {
        $upiBalance += $bal;
    }
}

$pageTitle = 'Expense Management';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-credit-card-2-back-fill text-primary me-2"></i>Expenses & Cash Outflows</h4>
        <p class="text-muted small mb-0">Track company operational overheads, office expenses, and staff salary & advance payouts.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/staff/index.php" class="btn btn-outline-success btn-sm fw-semibold">
            <i class="bi bi-cash-coin me-1"></i> Disburse Staff Payment
        </a>
        <button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="openExpenseModal()">
            <i class="bi bi-plus-lg me-1"></i> Record New Expense
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Financial Outflow & Balance Strip -->
<div class="custom-card p-2.5 px-3 mb-4 shadow-2xs border bg-white rounded-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <!-- 1. Total Operating Expense -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="bi bi-journal-minus fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.4px;">Operating Expenses</div>
                <div class="fw-bold text-danger fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($totalExpenses) ?></div>
            </div>
        </div>

        <div class="vr bg-secondary opacity-25 d-none d-lg-block" style="height: 30px;"></div>

        <!-- 2. Staff Wage Disbursements -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 bg-warning-subtle text-warning-emphasis d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="bi bi-cash-coin fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.4px;">Staff Payouts</div>
                <div class="fw-bold text-dark fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($totalStaffPayments) ?></div>
            </div>
        </div>

        <div class="vr bg-secondary opacity-25 d-none d-lg-block" style="height: 30px;"></div>

        <!-- 3. Total Outflows Combined -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 bg-info-subtle text-info d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="bi bi-arrow-down-circle fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.4px;">Total Cash Outflow</div>
                <div class="fw-bold text-info-emphasis fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($totalAllOutflows) ?></div>
            </div>
        </div>

        <div class="vr bg-secondary opacity-25 d-none d-lg-block" style="height: 30px;"></div>

        <!-- 4. Cash in Hand -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="bi bi-cash-stack fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.4px;">Remaining Cash</div>
                <div class="fw-bold text-dark fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($cashBalance) ?></div>
            </div>
        </div>

        <div class="vr bg-secondary opacity-25 d-none d-lg-block" style="height: 30px;"></div>

        <!-- 5. Bank Balance -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0;">
                <i class="bi bi-bank fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.4px;">Bank Balance (<?= htmlspecialchars($primaryBankName) ?>)</div>
                <div class="fw-bold text-dark fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($bankBalance) ?></div>
            </div>
        </div>

        <div class="vr bg-secondary opacity-25 d-none d-lg-block" style="height: 30px;"></div>

        <!-- 6. Net Available Balance -->
        <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; flex-shrink: 0; background-color: #dcfce7; color: #166534;">
                <i class="bi bi-shield-check fs-5"></i>
            </div>
            <div>
                <div class="text-muted text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.5px;">Net Total Balance</div>
                <div class="fw-bold text-success fs-6 lh-1 mt-0.5"><?= Helper::formatCurrency($totalAvailableBalance) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs: Operational Expenses vs Staff Salary Payouts -->
<div class="custom-card">
    <div class="custom-card-header bg-white border-bottom py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <ul class="nav nav-tabs card-header-tabs border-bottom-0" id="outflowTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold text-dark py-2 px-3 border-0 border-bottom border-primary border-2" id="tab-expenses-btn" data-bs-toggle="tab" data-bs-target="#pane-expenses" type="button" role="tab" onclick="updateTableSearchTarget('#expensesTable')">
                    <i class="bi bi-journal-minus me-1.5 text-danger"></i> Operational Expenses
                    <span class="badge bg-danger-subtle text-danger rounded-pill ms-1"><?= count($expenses) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold text-secondary py-2 px-3 border-0" id="tab-staff-payouts-btn" data-bs-toggle="tab" data-bs-target="#pane-staff-payouts" type="button" role="tab" onclick="updateTableSearchTarget('#staffPaymentsTable')">
                    <i class="bi bi-cash-coin me-1.5 text-success"></i> Staff Salary & Advances
                    <span class="badge bg-success-subtle text-success rounded-pill ms-1"><?= count($staffPayments) ?></span>
                </button>
            </li>
        </ul>
        <div class="ms-auto">
            <input type="text" id="outflowTableSearch" class="form-control form-control-sm" placeholder="Search records..." data-table-search="#expensesTable" style="width: 210px;">
        </div>
    </div>

    <div class="tab-content" id="outflowTabsContent">
        <!-- PANE 1: OPERATIONAL EXPENSES -->
        <div class="tab-pane fade show active" id="pane-expenses" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-custom align-middle mb-0" id="expensesTable">
                    <thead>
                        <tr>
                            <th class="col-row-num text-center">#</th>
                            <th>Voucher #</th>
                            <th>Date</th>
                            <th>Expense Detail</th>
                            <th>Via</th>
                            <th>Paid From Account</th>
                            <th>Cost Tagging</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center" style="width: 60px;">Receipt</th>
                            <th>Entered By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">No operational expenses recorded yet. Click "Record New Expense" above.</td></tr>
                        <?php else: ?>
                            <?php 
                                $rowNum = 1;
                                $sumExpense = 0;
                                foreach ($expenses as $ex): 
                                    $sumExpense += (float)$ex['amount'];
                            ?>
                                <tr>
                                    <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                                    <td><strong class="text-dark font-monospace"><?= htmlspecialchars($ex['expense_number']) ?></strong></td>
                                    <td><?= Helper::formatDate($ex['expense_date']) ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($ex['description']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($ex['vendor_name'])): ?>
                                            <span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-person-fill text-secondary me-1"></i><?= htmlspecialchars($ex['vendor_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><i class="bi bi-bank text-secondary me-1"></i><?= htmlspecialchars($ex['account_name']) ?></td>
                                    <td>
                                        <?php if ($ex['site_name']): ?>
                                            <div class="small fw-semibold text-primary"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($ex['site_name']) ?></div>
                                        <?php elseif ($ex['company_name']): ?>
                                            <div class="small text-muted"><i class="bi bi-building"></i> <?= htmlspecialchars($ex['company_name']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($ex['staff_name']): ?>
                                            <div class="small text-secondary"><i class="bi bi-person"></i> <?= htmlspecialchars($ex['staff_name']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!$ex['site_name'] && !$ex['company_name'] && !$ex['staff_name']): ?>
                                            <span class="text-muted small">General Overhead</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-monospace"><strong class="text-danger"><?= Helper::formatCurrency($ex['amount']) ?></strong></td>
                                    <td class="text-center">
                                        <?php if (!empty($ex['receipt_image'])): ?>
                                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ex['receipt_image']) ?>" target="_blank" class="btn btn-xs btn-outline-secondary px-1.5 py-0.5" title="View Bill">
                                                <i class="bi bi-paperclip"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($ex['created_by_name'] ?? 'Staff') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($expenses)): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td class="col-row-num text-center">&Sigma;</td>
                            <td colspan="6" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                                Expense Ledger Total: <strong><?= count($expenses) ?></strong> Vouchers
                            </td>
                            <td class="text-end font-monospace text-danger" style="font-size: 0.82rem; background-color: #fef2f2;">
                                <?= Helper::formatCurrency($sumExpense) ?>
                            </td>
                            <td colspan="2" class="text-muted small">Net Overhead</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- PANE 2: STAFF SALARY & ADVANCE PAYOUTS -->
        <div class="tab-pane fade" id="pane-staff-payouts" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-custom align-middle mb-0" id="staffPaymentsTable">
                    <thead>
                        <tr>
                            <th class="col-row-num text-center">#</th>
                            <th>Voucher #</th>
                            <th>Payment Date</th>
                            <th>Staff Member</th>
                            <th>Payment Type</th>
                            <th>Payment Mode</th>
                            <th>Paid From Account</th>
                            <th>Ref # / Notes</th>
                            <th class="text-end">Amount Disbursed</th>
                            <th>Processed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staffPayments)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">No staff salary or advance payments recorded yet.</td></tr>
                        <?php else: ?>
                            <?php 
                                $spRowNum = 1;
                                $sumSp = 0;
                                foreach ($staffPayments as $sp): 
                                    $sumSp += (float)$sp['amount'];
                                    $typeBadge = match($sp['payment_type']) {
                                        'salary_settlement' => 'bg-success-subtle text-success border border-success-subtle',
                                        'advance' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                        'incentive' => 'bg-info-subtle text-info border border-info-subtle',
                                        default => 'bg-secondary-subtle text-secondary'
                                    };
                                    $typeLabel = match($sp['payment_type']) {
                                        'salary_settlement' => 'Salary Settlement',
                                        'advance' => 'Advance Payout',
                                        'incentive' => 'Incentive',
                                        default => ucfirst($sp['payment_type'])
                                    };
                            ?>
                                <tr>
                                    <td class="col-row-num text-center"><?= $spRowNum++ ?></td>
                                    <td><strong class="text-dark font-monospace"><?= htmlspecialchars($sp['payment_number']) ?></strong></td>
                                    <td><?= Helper::formatDate($sp['payment_date']) ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($sp['staff_name']) ?></div>
                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.65rem;"><?= htmlspecialchars($sp['staff_code']) ?></span>
                                    </td>
                                    <td><span class="badge <?= $typeBadge ?> px-2 py-0.5 fw-semibold"><?= $typeLabel ?></span></td>
                                    <td><span class="badge bg-light text-dark border text-uppercase" style="font-size: 0.68rem;"><?= htmlspecialchars($sp['payment_mode']) ?></span></td>
                                    <td><i class="bi bi-bank text-secondary me-1"></i><?= htmlspecialchars($sp['account_name']) ?></td>
                                    <td>
                                        <?php if (!empty($sp['reference_number'])): ?>
                                            <div class="small font-monospace text-muted">Ref: <?= htmlspecialchars($sp['reference_number']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($sp['notes'])): ?>
                                            <div class="small text-secondary"><?= htmlspecialchars($sp['notes']) ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($sp['reference_number']) && empty($sp['notes'])): ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-monospace"><strong class="text-danger"><?= Helper::formatCurrency($sp['amount']) ?></strong></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($sp['processed_by_name'] ?? 'Admin') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($staffPayments)): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td class="col-row-num text-center">&Sigma;</td>
                            <td colspan="7" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                                Staff Payouts Total: <strong><?= count($staffPayments) ?></strong> Vouchers
                            </td>
                            <td class="text-end font-monospace text-danger" style="font-size: 0.82rem; background-color: #fef2f2;">
                                <?= Helper::formatCurrency($sumSp) ?>
                            </td>
                            <td class="text-muted small">Disbursed</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Record Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" enctype="multipart/form-data">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="record_expense">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">Record Operational Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Paid From Account *</label>
                            <select name="account_id" class="form-select" required>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= Helper::formatCurrency($acc['current_balance']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Expense Date *</label>
                            <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount (₹) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="0.5" name="amount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Via</label>
                            <input type="text" name="vendor_name" class="form-control" placeholder="e.g. Sakshi, Metro Cabs, Haldiram Foods, Indian Oil">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Upload Receipt / Bill Copy</label>
                            <input type="file" name="receipt_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                        </div>

                        <!-- Optional Cost Allocation Tagging -->
                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-dark border-bottom pb-1"><i class="bi bi-tag me-1 text-primary"></i> Optional Cost Center Tagging</h6>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Link to Client</label>
                            <select name="client_id" class="form-select">
                                <option value="">-- General / Non-Client --</option>
                                <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Link to Venue / Site</label>
                            <select name="site_id" class="form-select">
                                <option value="">-- Non-Site Specific --</option>
                                <?php foreach ($sites as $st): ?>
                                    <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['site_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Link to Staff Member</label>
                            <select name="staff_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($staff as $stf): ?>
                                    <option value="<?= $stf['id'] ?>"><?= htmlspecialchars($stf['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Expense Detail *</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="e.g. Late night cab transport for banquet staff from Grand Hyatt to metro station" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save & Post Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const expenseModal = new bootstrap.Modal(document.getElementById('expenseModal'));
function openExpenseModal() {
    expenseModal.show();
}

function updateTableSearchTarget(targetSelector) {
    const searchInput = document.getElementById('outflowTableSearch');
    if (searchInput) {
        searchInput.setAttribute('data-table-search', targetSelector);
        searchInput.value = '';
        document.querySelectorAll('#expensesTable tbody tr, #staffPaymentsTable tbody tr').forEach(tr => tr.style.display = '');
    }
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
