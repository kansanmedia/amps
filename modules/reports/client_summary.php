<?php
// modules/reports/client_summary.php - Financial Position & Receivables/Payables Aging
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();

// 1. Client Receivables (Who owes us money)
$clientQuery = "
    SELECT c.id, c.client_code, c.company_name, c.contact_person, c.phone, c.payment_terms_days,
        (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE i.client_id = c.id AND i.payment_status != 'cancelled') as total_invoiced,
        (SELECT COALESCE(SUM(cp.amount), 0) FROM client_payments cp WHERE cp.client_id = c.id) as total_collected
    FROM clients c
    WHERE c.status = 'active'
    ORDER BY c.company_name ASC
";
$clientList = $db->query($clientQuery)->fetchAll();

// 2. Staff Payables (Who we owe money to)
$staffQuery = "
    SELECT s.id, s.staff_code, s.full_name, s.skill_category, s.phone,
        (SELECT COALESCE(SUM(a.final_staff_amount), 0) FROM attendance a WHERE a.staff_id = s.id AND a.status IN ('present', 'half_day')) as total_earned,
        (SELECT COALESCE(SUM(sp.amount), 0) FROM staff_payments sp WHERE sp.staff_id = s.id) as total_paid
    FROM staff s
    WHERE s.status = 'active'
    ORDER BY s.full_name ASC
";
$staffList = $db->query($staffQuery)->fetchAll();

// Calculate totals
$totalReceivables = 0;
foreach ($clientList as $cl) {
    $bal = (float)$cl['total_invoiced'] - (float)$cl['total_collected'];
    if ($bal > 0) $totalReceivables += $bal;
}

$totalPayables = 0;
foreach ($staffList as $stf) {
    $bal = (float)$stf['total_earned'] - (float)$stf['total_paid'];
    if ($bal > 0) $totalPayables += $bal;
}

// Cash in hand & bank
$totalLiquidity = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM financial_accounts WHERE status = 'active'")->fetchColumn();

// Net Financial Position
$netWorth = $totalLiquidity + $totalReceivables - $totalPayables;

$pageTitle = 'Financial Statement & Receivables/Payables';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-spreadsheet-fill text-primary me-2"></i>Receivables, Payables & Financial Position</h4>
        <p class="text-muted small mb-0">Transparent breakdown of who owes money to whom, how much, and net business financial position.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-danger btn-sm" onclick="downloadFinancialPDF()">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Statement
        </button>
    </div>
</div>

<!-- Financial Statement Container for PDF/Print -->
<div id="financial-statement-container">
<!-- High-Level Position Overview -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Liquid Capital (Cash & Bank)</div>
            <div class="metric-value text-primary"><?= Helper::formatCurrency($totalLiquidity) ?></div>
            <div class="text-muted small mt-1">Available in financial accounts</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Client Receivables</div>
            <div class="metric-value text-success"><?= Helper::formatCurrency($totalReceivables) ?></div>
            <div class="text-muted small mt-1">Money owed to business by clients</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Staff Payables</div>
            <div class="metric-value text-danger"><?= Helper::formatCurrency($totalPayables) ?></div>
            <div class="text-muted small mt-1">Wages owed to workers</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Net Financial Standing</div>
            <div class="metric-value <?= $netWorth >= 0 ? 'text-dark' : 'text-danger' ?>">
                <?= Helper::formatCurrency($netWorth) ?>
            </div>
            <div class="text-muted small mt-1">Capital + Receivables - Payables</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Client Receivables Ledger -->
    <div class="col-lg-6">
        <div class="custom-card h-100">
            <div class="custom-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-arrow-down-left-circle-fill text-success fs-5"></i>
                    <h6 class="fw-bold mb-0">Clients Owing Money (Receivables)</h6>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-custom table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Client Company</th>
                            <th>Total Invoiced</th>
                            <th>Collected</th>
                            <th class="text-end">Due (Receivable)</th>
                            <th class="text-end no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $hasDueClients = false;
                        foreach ($clientList as $cl): 
                            $due = (float)$cl['total_invoiced'] - (float)$cl['total_collected'];
                            if ($due > 0):
                                $hasDueClients = true;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($cl['company_name']) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($cl['contact_person']) ?> &bull; <?= htmlspecialchars($cl['phone']) ?></div>
                                </td>
                                <td><?= Helper::formatCurrency($cl['total_invoiced']) ?></td>
                                <td class="text-success"><?= Helper::formatCurrency($cl['total_collected']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= Helper::formatCurrency($due) ?></td>
                                <td class="text-end no-print">
                                    <a href="<?= BASE_URL ?>/modules/payments/index.php?client_id=<?= $cl['id'] ?>" class="btn btn-sm btn-outline-success py-0 px-1.5" title="Collect Payment">
                                        <i class="bi bi-cash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; endforeach; ?>
                        <?php if (!$hasDueClients): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">Zero client receivables! All client invoices are fully cleared.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Staff Payables Ledger -->
    <div class="col-lg-6">
        <div class="custom-card h-100">
            <div class="custom-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-arrow-up-right-circle-fill text-danger fs-5"></i>
                    <h6 class="fw-bold mb-0">Staff Wages Payable (Liabilities)</h6>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-custom table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Worker Name</th>
                            <th>Earned</th>
                            <th>Paid</th>
                            <th class="text-end">Balance Payable</th>
                            <th class="text-end no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $hasDueStaff = false;
                        foreach ($staffList as $stf): 
                            $due = (float)$stf['total_earned'] - (float)$stf['total_paid'];
                            if ($due > 0):
                                $hasDueStaff = true;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($stf['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($stf['staff_code']) ?> &bull; <?= htmlspecialchars($stf['skill_category']) ?></div>
                                </td>
                                <td><?= Helper::formatCurrency($stf['total_earned']) ?></td>
                                <td class="text-success"><?= Helper::formatCurrency($stf['total_paid']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= Helper::formatCurrency($due) ?></td>
                                <td class="text-end no-print">
                                    <a href="<?= BASE_URL ?>/modules/staff_payments/settle.php?staff_id=<?= $stf['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1.5" title="Settle Wage">
                                        <i class="bi bi-cash-coin"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; endforeach; ?>
                        <?php if (!$hasDueStaff): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">All staff wages and shift earnings are fully settled!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div><!-- /#financial-statement-container -->

<script>
function downloadFinancialPDF() {
    const compSlug = '<?= preg_replace('/[^a-zA-Z0-9_-]/', '_', Helper::getCompanyName()) ?>';
    downloadElementAsPDF('financial-statement-container', compSlug + '_Financial_Statement_<?= date('Y-m-d') ?>', {
        margin: [8, 8, 8, 8],
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    });
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
