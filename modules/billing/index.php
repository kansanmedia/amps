<?php
// modules/billing/index.php - Client Invoicing Directory
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();

$filterStatus = $_GET['status'] ?? '';
$filterClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;

$sql = "
    SELECT i.*, c.company_name, c.client_code, c.phone as client_phone,
           (SELECT COUNT(ii.id) FROM invoice_items ii WHERE ii.invoice_id = i.id) as item_count
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($filterStatus)) {
    $sql .= " AND i.payment_status = :st";
    $params[':st'] = $filterStatus;
}
if ($filterClientId) {
    $sql .= " AND i.client_id = :cid";
    $params[':cid'] = $filterClientId;
}
$sql .= " ORDER BY i.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Clients for dropdown
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();

// Aggregate metrics
$totalBilled = array_sum(array_column($invoices, 'total_amount'));
$totalCollected = array_sum(array_column($invoices, 'paid_amount'));
$totalOutstanding = array_sum(array_column($invoices, 'balance_amount'));

$pageTitle = 'Client Invoicing';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Client Invoices & Billing</h4>
        <p class="text-muted small mb-0">Generate itemized shift invoices from approved attendance, apply GST/discounts, and monitor receivables.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/billing/generate.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Generate New Invoice
        </a>
    </div>
</div>

<!-- Metrics Overview -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Total Amount Billed</div>
            <div class="metric-value text-dark"><?= Helper::formatCurrency($totalBilled) ?></div>
            <div class="text-muted small mt-1"><?= count($invoices) ?> Total invoices issued</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Total Collections Received</div>
            <div class="metric-value text-success"><?= Helper::formatCurrency($totalCollected) ?></div>
            <div class="text-muted small mt-1">Cleared to business accounts</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-label">Outstanding Receivables</div>
            <div class="metric-value <?= $totalOutstanding > 0 ? 'text-danger' : 'text-secondary' ?>">
                <?= Helper::formatCurrency($totalOutstanding) ?>
            </div>
            <div class="text-muted small mt-1">Unpaid balance pending recovery</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="custom-card p-3 mb-4">
    <form method="GET" action="" class="row g-2 align-items-center">
        <div class="col-md-4">
            <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- All Clients --</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= $filterClientId == $cl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cl['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- All Payment Statuses --</option>
                <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="partially_paid" <?= $filterStatus === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Fully Paid</option>
            </select>
        </div>

        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" placeholder="Search invoice # or client..." data-table-search="#invoicesTable">
        </div>

        <div class="col-auto">
            <a href="<?= BASE_URL ?>/modules/billing/index.php" class="btn btn-light btn-sm border">Reset</a>
        </div>
    </form>
</div>

<!-- Invoices Table -->
<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="invoicesTable">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th>Invoice #</th>
                    <th>Client Name</th>
                    <th>Invoice Date</th>
                    <th>Due Date</th>
                    <th>Period Covered</th>
                    <th class="text-center">Shifts</th>
                    <th class="text-end">Total Billed</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance Due</th>
                    <th class="text-center">Status</th>
                    <th class="text-end" style="width: 105px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="12" class="text-center py-4 text-muted">No invoices found matching criteria. Click "Generate New Invoice" to bill attendance.</td></tr>
                <?php else: ?>
                    <?php 
                        $rowNum = 1;
                        $totBilledSum = 0;
                        $totPaidSum = 0;
                        $totBalSum = 0;
                        $totShiftsSum = 0;
                        foreach ($invoices as $inv): 
                            $totBilledSum += (float)$inv['total_amount'];
                            $totPaidSum += (float)$inv['paid_amount'];
                            $totBalSum += (float)$inv['balance_amount'];
                            $totShiftsSum += (int)$inv['item_count'];
                    ?>
                        <tr>
                            <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $inv['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace">
                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($inv['company_name']) ?></div>
                                <div class="text-muted" style="font-size:0.70rem;"><?= htmlspecialchars($inv['client_code']) ?></div>
                            </td>
                            <td><?= Helper::formatDate($inv['invoice_date']) ?></td>
                            <td>
                                <?php 
                                    $isOverdue = ($inv['payment_status'] !== 'paid' && $inv['due_date'] < date('Y-m-d'));
                                ?>
                                <span class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>">
                                    <?= Helper::formatDate($inv['due_date']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted"><?= Helper::formatDate($inv['period_start'], 'd M') ?> - <?= Helper::formatDate($inv['period_end'], 'd M Y') ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border py-0.5 px-1.5" style="font-size:0.70rem;"><?= $inv['item_count'] ?> shifts</span>
                            </td>
                            <td class="text-end font-monospace"><strong class="text-dark"><?= Helper::formatCurrency($inv['total_amount']) ?></strong></td>
                            <td class="text-end font-monospace"><span class="text-success"><?= Helper::formatCurrency($inv['paid_amount']) ?></span></td>
                            <td class="text-end font-monospace">
                                <?php if ((float)$inv['balance_amount'] < 0): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold" title="Advance Received">
                                        Adv: <?= Helper::formatCurrency(abs($inv['balance_amount'])) ?>
                                    </span>
                                <?php else: ?>
                                    <strong class="<?= (float)$inv['balance_amount'] > 0 ? 'text-danger' : 'text-secondary' ?>">
                                        <?= Helper::formatCurrency($inv['balance_amount']) ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= Helper::statusBadge($inv['payment_status'], $inv['carried_to_invoice_number'] ?? null) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary px-1.5 py-0.5" title="View & Print Invoice">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $inv['id'] ?>&download_pdf=1" class="btn btn-xs btn-outline-danger px-1.5 py-0.5" title="Download PDF Invoice">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                    <?php if ((float)$inv['balance_amount'] > 0): ?>
                                        <a href="<?= BASE_URL ?>/modules/payments/index.php?client_id=<?= $inv['client_id'] ?>&invoice_id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-success px-1.5 py-0.5" title="Record Payment">
                                            <i class="bi bi-cash-coin"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($invoices)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td colspan="5" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                        Billing Total: <strong><?= count($invoices) ?></strong> Invoices &bull; <strong><?= $totShiftsSum ?></strong> Billed Shifts
                    </td>
                    <td class="text-center font-monospace" style="font-size: 0.80rem; background-color: #f1f5f9;">
                        <?= $totShiftsSum ?>
                    </td>
                    <td class="text-end font-monospace text-dark" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totBilledSum) ?>
                    </td>
                    <td class="text-end font-monospace text-success" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totPaidSum) ?>
                    </td>
                    <td class="text-end font-monospace <?= $totBalSum > 0 ? 'text-danger' : 'text-secondary' ?>" style="font-size: 0.82rem; background-color: #fef2f2;">
                        <?= Helper::formatCurrency($totBalSum) ?>
                    </td>
                    <td colspan="2" class="text-muted small text-end">Net Receivable</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
