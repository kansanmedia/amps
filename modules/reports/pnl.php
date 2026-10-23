<?php
// modules/reports/pnl.php - Profit & Loss (P&L) Financial Engine
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();

// Date range filters
$preset = $_GET['preset'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (isset($_GET['preset'])) {
    if ($preset === 'today') {
        $startDate = $endDate = date('Y-m-d');
    } elseif ($preset === 'yesterday') {
        $startDate = $endDate = date('Y-m-d', strtotime('-1 day'));
    } elseif ($preset === 'this_week') {
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d');
    } elseif ($preset === 'this_month') {
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-d');
    } elseif ($preset === 'last_month') {
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
    }
}

$filterClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$filterSiteId = !empty($_GET['site_id']) ? (int)$_GET['site_id'] : null;
$filterShiftId = !empty($_GET['shift_id']) ? (int)$_GET['shift_id'] : null;

// 1. Calculate Revenue & Staff Labor Cost from Approved Attendance
$attSql = "
    SELECT 
        COUNT(a.id) as total_shifts,
        COALESCE(SUM(a.final_client_amount), 0) as gross_revenue,
        COALESCE(SUM(a.final_staff_amount), 0) as staff_labor_cost
    FROM attendance a
    JOIN deployments d ON a.deployment_id = d.id
    WHERE a.attendance_date BETWEEN :start AND :end
      AND a.status IN ('present', 'half_day')
      AND d.status != 'cancelled'
";
$attParams = [':start' => $startDate, ':end' => $endDate];

if ($filterClientId) {
    $attSql .= " AND a.client_id = :cid";
    $attParams[':cid'] = $filterClientId;
}
if ($filterSiteId) {
    $attSql .= " AND a.site_id = :sid";
    $attParams[':sid'] = $filterSiteId;
}
if ($filterShiftId) {
    $attSql .= " AND a.shift_id = :shid";
    $attParams[':shid'] = $filterShiftId;
}

$attStmt = $db->prepare($attSql);
$attStmt->execute($attParams);
$metrics = $attStmt->fetch();

$grossRevenue = (float)$metrics['gross_revenue'];
$staffLaborCost = (float)$metrics['staff_labor_cost'];
$grossMargin = $grossRevenue - $staffLaborCost;
$grossMarginPct = $grossRevenue > 0 ? round(($grossMargin / $grossRevenue) * 100, 1) : 0;

// 2. Calculate Operating Expenses for Period
$expSql = "
    SELECT COALESCE(SUM(amount), 0) as total_expenses
    FROM expenses
    WHERE expense_date BETWEEN :start AND :end
";
$expParams = [':start' => $startDate, ':end' => $endDate];
if ($filterClientId) {
    $expSql .= " AND client_id = :cid";
    $expParams[':cid'] = $filterClientId;
}
if ($filterSiteId) {
    $expSql .= " AND site_id = :sid";
    $expParams[':sid'] = $filterSiteId;
}

$expStmt = $db->prepare($expSql);
$expStmt->execute($expParams);
$totalExpenses = (float)$expStmt->fetchColumn();

$netProfit = $grossMargin - $totalExpenses;
$netMarginPct = $grossRevenue > 0 ? round(($netProfit / $grossRevenue) * 100, 1) : 0;

// 3. Shift-wise Breakdown
$shBreakdownSql = "
    SELECT sh.id, sh.shift_name, sh.shift_code, sh.is_overnight,
           COUNT(a.id) as shifts_count,
           COALESCE(SUM(a.final_client_amount), 0) as shift_revenue,
           COALESCE(SUM(a.final_staff_amount), 0) as shift_cost
    FROM shifts sh
    LEFT JOIN attendance a ON a.shift_id = sh.id AND a.attendance_date BETWEEN :start AND :end AND a.status IN ('present', 'half_day')
    GROUP BY sh.id
    ORDER BY sh.id ASC
";
$shStmt = $db->prepare($shBreakdownSql);
$shStmt->execute([':start' => $startDate, ':end' => $endDate]);
$shiftBreakdown = $shStmt->fetchAll();

// 4. Client-wise Profitability Breakdown
$cliBreakdownSql = "
    SELECT c.id, c.company_name,
           COUNT(a.id) as shifts_count,
           COALESCE(SUM(a.final_client_amount), 0) as client_revenue,
           COALESCE(SUM(a.final_staff_amount), 0) as client_cost
    FROM clients c
    LEFT JOIN attendance a ON a.client_id = c.id AND a.attendance_date BETWEEN :start AND :end AND a.status IN ('present', 'half_day')
    GROUP BY c.id
    HAVING client_revenue > 0
    ORDER BY client_revenue DESC
";
$cliStmt = $db->prepare($cliBreakdownSql);
$cliStmt->execute([':start' => $startDate, ':end' => $endDate]);
$clientBreakdown = $cliStmt->fetchAll();

// Dropdowns
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$shifts = $db->query("SELECT id, shift_name FROM shifts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

$pageTitle = 'Profit & Loss (P&L) Statement';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-graph-up-arrow text-success me-2"></i>Profit & Loss (P&L) Intelligence</h4>
        <p class="text-muted small mb-0">Live calculation of Gross Revenue, Direct Labor Costs, Operating Expenses, and Net Margins.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-danger btn-sm" onclick="downloadPnLPDF()">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>
</div>

<!-- Date Preset & Filter Box (Hidden in Print) -->
<div class="custom-card p-3 mb-4 no-print">
    <form method="GET" action="" class="row g-2 align-items-center">
        <div class="col-auto">
            <div class="btn-group btn-group-sm">
                <a href="?preset=today" class="btn btn-outline-secondary <?= $preset === 'today' ? 'active' : '' ?>">Today</a>
                <a href="?preset=this_week" class="btn btn-outline-secondary <?= $preset === 'this_week' ? 'active' : '' ?>">This Week</a>
                <a href="?preset=this_month" class="btn btn-outline-secondary <?= $preset === 'this_month' ? 'active' : '' ?>">This Month</a>
                <a href="?preset=last_month" class="btn btn-outline-secondary <?= $preset === 'last_month' ? 'active' : '' ?>">Last Month</a>
            </div>
        </div>

        <div class="col-md-2">
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $startDate ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $endDate ?>">
        </div>

        <div class="col-md-3">
            <select name="client_id" class="form-select form-select-sm">
                <option value="">-- All Clients --</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= $filterClientId == $cl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cl['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel me-1"></i> Apply Filter
            </button>
            <a href="<?= BASE_URL ?>/modules/reports/pnl.php" class="btn btn-light btn-sm border ms-1">Reset</a>
        </div>
    </form>
</div>

<!-- Printable P&L Report Container -->
<div id="pnl-report-container">
<!-- Main P&L KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">1. Gross Service Revenue</div>
            <div class="metric-value text-primary"><?= Helper::formatCurrency($grossRevenue) ?></div>
            <div class="text-muted small mt-1"><?= $metrics['total_shifts'] ?> Shifts attended</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">2. Direct Staff Labor Cost</div>
            <div class="metric-value text-secondary"><?= Helper::formatCurrency($staffLaborCost) ?></div>
            <div class="text-muted small mt-1">Staff shift earnings obligation</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">3. Operating Expenses</div>
            <div class="metric-value text-warning-emphasis"><?= Helper::formatCurrency($totalExpenses) ?></div>
            <div class="text-muted small mt-1">Travel, meals, fuel, overheads</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">4. Net Profit</div>
            <div class="metric-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= Helper::formatCurrency($netProfit) ?>
            </div>
            <div class="small fw-semibold mt-1 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= $netMarginPct ?>% Net Profit Margin
            </div>
        </div>
    </div>
</div>

<!-- Visual Chart & Breakdown Section -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="custom-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart text-primary me-1"></i> Financial Flow Breakdown</h6>
            <div style="height: 240px; position: relative;">
                <canvas id="pnlChart"></canvas>
            </div>
            <div class="row g-2 text-center mt-3 pt-2 border-top">
                <div class="col-6">
                    <span class="text-muted small" style="font-size:0.75rem;">Gross Margin:</span>
                    <strong class="text-dark d-block"><?= Helper::formatCurrency($grossMargin) ?> (<?= $grossMarginPct ?>%)</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted small" style="font-size:0.75rem;">Net Retained:</span>
                    <strong class="<?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?> d-block"><?= Helper::formatCurrency($netProfit) ?> (<?= $netMarginPct ?>%)</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Shift Performance Breakdown -->
    <div class="col-lg-7">
        <div class="custom-card h-100">
            <div class="custom-card-header">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-1 text-primary"></i> Profitability by Shift (3 Daily Shifts)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th class="col-row-num text-center">#</th>
                            <th>Shift Name</th>
                            <th class="text-center">Deployments</th>
                            <th class="text-end">Client Revenue</th>
                            <th class="text-end">Staff Cost</th>
                            <th class="text-end">Gross Profit</th>
                            <th class="text-center">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $shRowNum = 1;
                            $totShiftsCount = 0;
                            $totShRev = 0;
                            $totShCost = 0;
                            foreach ($shiftBreakdown as $sh): 
                                $rev = (float)$sh['shift_revenue'];
                                $cost = (float)$sh['shift_cost'];
                                $margin = $rev - $cost;
                                $pct = $rev > 0 ? round(($margin / $rev) * 100, 1) : 0;
                                $totShiftsCount += (int)$sh['shifts_count'];
                                $totShRev += $rev;
                                $totShCost += $cost;
                        ?>
                            <tr>
                                <td class="col-row-num text-center"><?= $shRowNum++ ?></td>
                                <td><?= Helper::shiftBadge($sh['shift_name'], $sh['shift_code'], (bool)$sh['is_overnight']) ?></td>
                                <td class="text-center font-monospace"><strong><?= $sh['shifts_count'] ?></strong></td>
                                <td class="text-end font-monospace"><?= Helper::formatCurrency($rev) ?></td>
                                <td class="text-end font-monospace text-secondary"><?= Helper::formatCurrency($cost) ?></td>
                                <td class="text-end font-monospace"><strong class="text-success">+<?= Helper::formatCurrency($margin) ?></strong></td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success py-0.5 px-1.5" style="font-size:0.72rem;"><?= $pct ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($shiftBreakdown)): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td class="col-row-num text-center">&Sigma;</td>
                            <td class="text-uppercase text-muted" style="font-size: 0.72rem;">Total Shifts</td>
                            <td class="text-center font-monospace" style="font-size: 0.80rem; background-color: #f1f5f9;"><?= $totShiftsCount ?></td>
                            <td class="text-end font-monospace text-dark" style="font-size: 0.82rem; background-color: #f1f5f9;"><?= Helper::formatCurrency($totShRev) ?></td>
                            <td class="text-end font-monospace text-secondary" style="font-size: 0.82rem; background-color: #f1f5f9;"><?= Helper::formatCurrency($totShCost) ?></td>
                            <td class="text-end font-monospace text-success" style="font-size: 0.82rem; background-color: #f0fdf4;">+<?= Helper::formatCurrency($totShRev - $totShCost) ?></td>
                            <td class="text-center font-monospace small"><?= $totShRev > 0 ? round((($totShRev - $totShCost) / $totShRev) * 100, 1) : 0 ?>%</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Client-wise Profitability Table -->
<div class="custom-card">
    <div class="custom-card-header">
        <h6 class="fw-bold mb-0"><i class="bi bi-building me-1 text-primary"></i> Client Profitability & Margin Matrix</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th>Client Company</th>
                    <th class="text-center">Shifts Served</th>
                    <th class="text-end">Gross Invoiced / Billable</th>
                    <th class="text-end">Staff Payout Cost</th>
                    <th class="text-end">Gross Margin (₹)</th>
                    <th>Profit Margin (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientBreakdown)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No attendance data for this period.</td></tr>
                <?php else: ?>
                    <?php 
                        $clRowNum = 1;
                        $totCliShifts = 0;
                        $totCliRev = 0;
                        $totCliCost = 0;
                        foreach ($clientBreakdown as $cl): 
                            $cRev = (float)$cl['client_revenue'];
                            $cCost = (float)$cl['client_cost'];
                            $cMargin = $cRev - $cCost;
                            $cPct = $cRev > 0 ? round(($cMargin / $cRev) * 100, 1) : 0;
                            $totCliShifts += (int)$cl['shifts_count'];
                            $totCliRev += $cRev;
                            $totCliCost += $cCost;
                    ?>
                        <tr>
                            <td class="col-row-num text-center"><?= $clRowNum++ ?></td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($cl['company_name']) ?></strong>
                            </td>
                            <td class="text-center font-monospace"><?= $cl['shifts_count'] ?> shifts</td>
                            <td class="text-end font-monospace"><strong><?= Helper::formatCurrency($cRev) ?></strong></td>
                            <td class="text-end font-monospace"><span class="text-secondary"><?= Helper::formatCurrency($cCost) ?></span></td>
                            <td class="text-end font-monospace"><strong class="text-success">+<?= Helper::formatCurrency($cMargin) ?></strong></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= min($cPct, 100) ?>%"></div>
                                    </div>
                                    <span class="small fw-bold font-monospace"><?= $cPct ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($clientBreakdown)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td class="text-uppercase text-muted" style="font-size: 0.72rem;">All Clients (<?= count($clientBreakdown) ?> Accounts)</td>
                    <td class="text-center font-monospace" style="font-size: 0.80rem; background-color: #f1f5f9;"><?= $totCliShifts ?></td>
                    <td class="text-end font-monospace text-dark" style="font-size: 0.82rem; background-color: #f1f5f9;"><?= Helper::formatCurrency($totCliRev) ?></td>
                    <td class="text-end font-monospace text-secondary" style="font-size: 0.82rem; background-color: #f1f5f9;"><?= Helper::formatCurrency($totCliCost) ?></td>
                    <td class="text-end font-monospace text-success" style="font-size: 0.82rem; background-color: #f0fdf4;">+<?= Helper::formatCurrency($totCliRev - $totCliCost) ?></td>
                    <td class="font-monospace small"><?= $totCliRev > 0 ? round((($totCliRev - $totCliCost) / $totCliRev) * 100, 1) : 0 ?>% Overall Margin</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
</div><!-- /#pnl-report-container -->

<script>
function downloadPnLPDF() {
    downloadElementAsPDF('pnl-report-container', 'Kansan_PNL_Statement_<?= $startDate ?>_to_<?= $endDate ?>', {
        margin: [8, 8, 8, 8],
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('pnlChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Staff Labor Cost', 'Operating Expenses', 'Net Profit'],
            datasets: [{
                data: [
                    <?= $staffLaborCost ?>,
                    <?= $totalExpenses ?>,
                    <?= max(0, $netProfit) ?>
                ],
                backgroundColor: [
                    '#6366f1', // Indigo Staff Cost
                    '#f59e0b', // Amber Expenses
                    '#10b981'  // Emerald Net Profit
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 } }
                }
            },
            cutout: '70%'
        }
    });
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
