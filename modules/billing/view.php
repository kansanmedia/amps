<?php
// modules/billing/view.php - Exact Authentic Client Invoice View Matching Business Standard
// Features: Category-wise dynamic matrix columns, Highlighted formula row, Back dues statement, Last 3 payments history, PDF/Print
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'accountant', 'manager']);

$hideFooter = true; // Completely prevent rendering of application footer on invoice view/print

$db = DB::getConnection();
$invoiceId = (int)($_GET['id'] ?? 0);

// Handle Tax Toggle / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_tax') {
    CSRF::validateRequest();
    Auth::requireRole(['owner_admin', 'accountant']);
    $newTaxPct = (float)($_POST['tax_percentage'] ?? 0.0);
    require_once ROOT_PATH . '/services/BillingService.php';
    try {
        BillingService::updateInvoiceTax($invoiceId, $newTaxPct);
        $taxMsg = ($newTaxPct > 0) ? "Tax applied ({$newTaxPct}% GST)." : "Tax removed. Invoice is now Tax-Exempt / Non-GST.";
        Helper::setFlash('success', $taxMsg);
        Helper::redirect("modules/billing/view.php?id={$invoiceId}");
    } catch (Exception $e) {
        Helper::setFlash('danger', 'Error updating tax: ' . $e->getMessage());
        Helper::redirect("modules/billing/view.php?id={$invoiceId}");
    }
}

// Fetch Invoice & Client Details
$stmt = $db->prepare("
    SELECT i.*, c.company_name, c.client_code, c.contact_person, c.phone as client_phone,
           c.email as client_email, c.billing_address, c.gst_number as client_gst, c.pan_number as client_pan,
           u.full_name as generated_by_name
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE i.id = :id
");
$stmt->execute([':id' => $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    Helper::setFlash('danger', 'Invoice not found.');
    Helper::redirect('modules/billing/index.php');
}

// Fetch Invoice Line Items (with staff category support)
$itemStmt = $db->prepare("
    SELECT ii.*, stf.skill_category, sc.category_name
    FROM invoice_items ii
    LEFT JOIN attendance a ON ii.attendance_id = a.id
    LEFT JOIN staff stf ON a.staff_id = stf.id
    LEFT JOIN staff_categories sc ON stf.category_id = sc.id
    WHERE ii.invoice_id = :id 
    ORDER BY ii.service_date ASC, ii.id ASC
");
$itemStmt->execute([':id' => $invoiceId]);
$items = $itemStmt->fetchAll();

// Normalizer: Standardize Category Name to uppercase (e.g. SERVICE, UTILITY, etc.)
function normalizeInvoiceCategory(array $item): string {
    $raw = !empty($item['staff_category']) ? $item['staff_category'] : (!empty($item['category_name']) ? $item['category_name'] : (!empty($item['skill_category']) ? $item['skill_category'] : ''));
    $c = strtoupper(trim($raw));
    if (strpos($c, 'STEWARD') !== false || strpos($c, 'WAITER') !== false || strpos($c, 'SERVICE') !== false) {
        return 'SERVICE';
    }
    if (strpos($c, 'MASHALCHI') !== false || strpos($c, 'UTILITY') !== false || strpos($c, 'HOUSEKEEP') !== false || strpos($c, 'CLEAN') !== false || strpos($c, 'BARTAN') !== false) {
        return 'UTILITY';
    }
    if (strpos($c, 'BARTEND') !== false || strpos($c, 'BAR') !== false) {
        return 'BARTENDER';
    }
    if (strpos($c, 'CHEF') !== false || strpos($c, 'COOK') !== false || strpos($c, 'PANTRY') !== false) {
        return 'CHEF / PANTRY';
    }
    return !empty($c) ? $c : 'SERVICE';
}

// 1. Identify distinct active categories PRESENT in this invoice (Only categories with present staff)
$activeCategories = [];
foreach ($items as $it) {
    $cat = normalizeInvoiceCategory($it);
    if (!in_array($cat, $activeCategories, true)) {
        $activeCategories[] = $cat;
    }
}

// Sort order: SERVICE first, UTILITY second, followed by others
usort($activeCategories, function($a, $b) {
    if ($a === 'SERVICE') return -1;
    if ($b === 'SERVICE') return 1;
    if ($a === 'UTILITY') return -1;
    if ($b === 'UTILITY') return 1;
    return strcmp($a, $b);
});

if (empty($activeCategories)) {
    $activeCategories = ['SERVICE'];
}

// 2. Aggregate Items by (Date, Shift, Venue)
$groupedRows = [];
$categoryTotals = array_fill_keys($activeCategories, 0);
$categoryRates = array_fill_keys($activeCategories, 0.0);
$categoryAmounts = array_fill_keys($activeCategories, 0.0);

foreach ($items as $it) {
    $date = $it['service_date'];
    $shift = strtoupper(trim($it['shift_name'] ?? 'EVENT'));
    $venue = strtoupper(trim($it['site_name'] ?? 'MAIN VENUE'));
    $cat = normalizeInvoiceCategory($it);

    $groupKey = "{$date}|{$shift}|{$venue}";
    if (!isset($groupedRows[$groupKey])) {
        $groupedRows[$groupKey] = [
            'date' => $date,
            'shift' => $shift,
            'venue' => $venue,
            'counts' => array_fill_keys($activeCategories, 0),
            'rates' => array_fill_keys($activeCategories, 0.0),
            'subtotal' => 0.0
        ];
    }

    $qty = (float)($it['quantity'] ?? 1.0);
    $rate = (float)($it['unit_rate'] ?? 0.0);
    $amt = (float)($it['total_amount'] ?? ($qty * $rate));

    $groupedRows[$groupKey]['counts'][$cat] = ($groupedRows[$groupKey]['counts'][$cat] ?? 0) + $qty;
    $groupedRows[$groupKey]['rates'][$cat] = $rate;
    $groupedRows[$groupKey]['subtotal'] += $amt;

    $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0) + $qty;
    if ($rate > 0) {
        $categoryRates[$cat] = $rate;
    }
    $categoryAmounts[$cat] = ($categoryAmounts[$cat] ?? 0.0) + $amt;
}

// Fallback: If any active category rate is missing or zero, dynamically fetch from rate_cards for this client
foreach ($activeCategories as $c) {
    if (empty($categoryRates[$c])) {
        $rcStmt = $db->prepare("
            SELECT rc.client_billing_rate 
            FROM rate_cards rc 
            JOIN staff_categories sc ON rc.category_id = sc.id 
            WHERE rc.client_id = :cid AND UPPER(sc.category_name) LIKE :cat
              AND rc.site_id IS NULL AND rc.shift_id IS NULL AND rc.staff_id IS NULL
            ORDER BY rc.id DESC LIMIT 1
        ");
        $rcStmt->execute([':cid' => $invoice['client_id'], ':cat' => "%{$c}%"]);
        if ($rRow = $rcStmt->fetch()) {
            $categoryRates[$c] = (float)$rRow['client_billing_rate'];
        } else {
            $categoryRates[$c] = (float)($invoice['default_billing_rate'] ?? 650.00);
        }
    }
}

// 3. Construct Formula String: e.g. [22 SERVICE X 650] + [11 UTILITY X 650] = RS. 21,450
$formulaParts = [];
$calculatedSubtotal = 0.0;
foreach ($activeCategories as $c) {
    $cnt = $categoryTotals[$c] ?? 0;
    $rate = $categoryRates[$c] ?? 0;
    if ($cnt > 0) {
        $rateFormatted = ($rate == (int)$rate) ? (int)$rate : number_format($rate, 2);
        $formulaParts[] = "[{$cnt} {$c} X {$rateFormatted}]";
        $calculatedSubtotal += ($cnt * $rate);
    }
}
$subtotalToDisplay = ($calculatedSubtotal > 0) ? $calculatedSubtotal : (float)$invoice['subtotal'];
$formulaString = implode(' + ', $formulaParts);
if (!empty($formulaString)) {
    $formulaString .= ' = RS. ' . number_format($subtotalToDisplay, 0);
} else {
    $formulaString = 'RS. ' . number_format($subtotalToDisplay, 0);
}

// 4. Fetch Previous Invoices Carried Forward onto this Invoice (Back Dues)
$carriedDues = (float)($invoice['carried_dues'] ?? 0.0);
$backDueInvoices = [];

if (!empty($invoice['carried_invoices_detail'])) {
    $backDueInvoices = json_decode($invoice['carried_invoices_detail'], true) ?: [];
}

// Fallback for older invoices generated before migration
if (empty($backDueInvoices) && $carriedDues <= 0) {
    $backDueStmt = $db->prepare("
        SELECT id, invoice_number, invoice_date, total_amount, paid_amount, balance_amount, payment_status
        FROM invoices
        WHERE client_id = :cid
          AND id < :current_id
          AND balance_amount > 0
          AND payment_status IN ('unpaid', 'partially_paid')
        ORDER BY invoice_date ASC, id ASC
    ");
    $backDueStmt->execute([':cid' => $invoice['client_id'], ':current_id' => $invoiceId]);
    $backDueInvoices = $backDueStmt->fetchAll();
    $carriedDues = array_sum(array_column($backDueInvoices, 'balance_amount'));
}

$totalBackDue = $carriedDues;
$netBillPayable = (float)$invoice['total_amount'] + $totalBackDue;
$currentPaid = (float)$invoice['paid_amount'];
$netBalance = (float)$invoice['balance_amount'];

// 5. Fetch Last 3 Received Payments for this Client
$paymentStmt = $db->prepare("
    SELECT cp.*, a.bank_name, a.account_number, a.account_type
    FROM client_payments cp
    LEFT JOIN financial_accounts a ON cp.account_id = a.id
    WHERE cp.client_id = :cid
    ORDER BY cp.payment_date DESC, cp.id DESC
    LIMIT 3
");
$paymentStmt->execute([':cid' => $invoice['client_id']]);
$lastPayments = $paymentStmt->fetchAll();

// 6. Fetch Company Settings
$compStmt = $db->query("SELECT * FROM company_settings LIMIT 1");
$company = $compStmt->fetch();

$companyName = !empty($company['company_name']) ? $company['company_name'] : 'ADITYA MANPOWER SUPPLIER';
$companyTagline = !empty($company['tagline']) ? $company['tagline'] : 'All types of Manpower suppliers for Hotel/ Banquet Hall/O.D.C. & Other purpose';
$companyAddress = !empty($company['address']) ? $company['address'] : 'Kumar Commercial Complex, Ram Gulam Chowk, Gandhi Maidan Gate No.10, Patna- 800001';
$companyPhone = !empty($company['phone']) ? $company['phone'] : '8051296873';

// 7. Fetch Financial Accounts
$bankStmt = $db->query("SELECT * FROM financial_accounts WHERE account_type = 'bank' AND status = 'active' ORDER BY id ASC LIMIT 1");
$bankAccount = $bankStmt->fetch();
$upiStmt = $db->query("SELECT * FROM financial_accounts WHERE account_type = 'upi' AND status = 'active' ORDER BY id ASC LIMIT 1");
$upiAccount = $upiStmt->fetch();

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<style>
/* Authentic PDF-Like Invoice Stylesheet */
.pdf-invoice-container {
    background-color: #ffffff !important;
    max-width: 900px;
    margin: 0 auto;
    color: #000000;
    font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
    padding: 25px 35px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.08);
    border: 1px solid #dcdcdc;
    border-radius: 4px;
}

.pdf-invoice-table {
    width: 100%;
    border-collapse: collapse !important;
    border: 1.5px solid #000000 !important;
    margin-bottom: 0px;
}

.pdf-invoice-table th {
    border: 1px solid #000000 !important;
    color: #dc2626 !important;
    font-weight: 800 !important;
    text-align: center !important;
    padding: 4px 6px !important;
    font-size: 12px !important;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    background-color: #ffffff;
}

.pdf-invoice-table td {
    border: 1px solid #000000 !important;
    text-align: center !important;
    padding: 4px 6px !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #000000 !important;
    background-color: #ffffff;
}

.pdf-invoice-table .row-total td {
    border-top: 1.5px solid #000000 !important;
    border-bottom: 1.5px solid #000000 !important;
    font-weight: 800 !important;
    font-size: 12.5px !important;
    padding: 5px 6px !important;
}

.formula-highlight-row {
    background-color: #ffff00 !important;
    color: #000000 !important;
    font-weight: 800 !important;
    font-size: 13px !important;
    letter-spacing: 0.5px;
    text-align: center !important;
    border: 1.5px solid #000000 !important;
    padding: 6px 10px !important;
}

.secondary-table {
    width: 100%;
    border-collapse: collapse !important;
    border: 1px solid #333333 !important;
    font-size: 0.78rem !important;
}

.secondary-table th {
    border: 1px solid #333333 !important;
    background-color: #f1f5f9 !important;
    color: #0f172a !important;
    font-weight: 700 !important;
    padding: 4px 6px !important;
    text-align: left;
    white-space: nowrap !important;
}

.secondary-table td {
    border: 1px solid #333333 !important;
    padding: 4px 6px !important;
    color: #000000 !important;
    white-space: nowrap !important;
}

/* Completely hide application footer on invoice view & print */
footer, .app-footer, footer.d-flex, footer.border-top {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    opacity: 0 !important;
}

@media print {
    body {
        background-color: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .no-print, .deploy-unified-toolstrip, .navbar, .sidebar, footer, .app-footer, footer.d-flex, footer.border-top {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        opacity: 0 !important;
    }
    .pdf-invoice-container {
        box-shadow: none !important;
        border: none !important;
        padding: 10px 15px !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
    }
    .pdf-invoice-table, .pdf-invoice-table th, .pdf-invoice-table td,
    .secondary-table, .secondary-table th, .secondary-table td {
        border-color: #000000 !important;
    }
    .formula-highlight-row {
        background-color: #ffff00 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .text-danger, .pdf-header-red, .pdf-invoice-table th, .total-label-red {
        color: #dc2626 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .page-break-inside-avoid {
        page-break-inside: avoid;
    }
}
</style>

<!-- Top Action Toolbar (Hidden when printing or exporting) -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 no-print">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/billing/index.php" class="btn btn-sm btn-light border shadow-2xs">
            <i class="bi bi-arrow-left me-1"></i> Back to Invoices
        </a>
        <div>
            <h5 class="fw-bold mb-0 d-flex align-items-center gap-2">
                Client Bill: <?= htmlspecialchars($invoice['invoice_number']) ?>
                <?= Helper::statusBadge($invoice['payment_status'], $invoice['carried_to_invoice_number'] ?? null) ?>
            </h5>
            <div class="text-muted small">Client: <strong><?= htmlspecialchars($invoice['company_name']) ?></strong></div>
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if (Auth::isAccountant() || Auth::isAdmin()): ?>
            <button type="button" class="btn btn-sm <?= ((float)$invoice['tax_percentage'] > 0) ? 'btn-outline-primary' : 'btn-outline-warning' ?>" 
                    data-bs-toggle="modal" data-bs-target="#taxToggleModal" 
                    title="Toggle Tax (GST 18% vs Non-GST 0%)">
                <i class="bi bi-percent me-1"></i> 
                <?= ((float)$invoice['tax_percentage'] > 0) ? 'GST (18%) Active' : 'Non-GST (0%)' ?>
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-danger btn-sm shadow-2xs" onclick="downloadInvoicePDF()">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF
        </button>
        <button type="button" class="btn btn-dark btn-sm shadow-2xs" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Bill
        </button>
        <?php if ((float)$invoice['balance_amount'] > 0 && Auth::isAccountant()): ?>
            <a href="<?= BASE_URL ?>/modules/payments/index.php?client_id=<?= $invoice['client_id'] ?>&invoice_id=<?= $invoice['id'] ?>" class="btn btn-success btn-sm shadow-2xs">
                <i class="bi bi-cash-stack me-1"></i> Record Payment
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- PRINTABLE / PDF INVOICE AREA (MATCHES SAMPLE PDF EXACTLY)                 -->
<!-- ========================================================================= -->
<div id="invoice-printable-area" class="pdf-invoice-container">

    <!-- 1. Top Right Mobile Number -->
    <div class="text-end fw-bold" style="font-size: 0.90rem; color: #000000; margin-bottom: 2px;">
        Mobile No. <?= htmlspecialchars($companyPhone) ?>
    </div>

    <!-- 2. Centered Agency Header with Red Underlined Title -->
    <div class="text-center" style="margin-bottom: 16px;">
        <h2 class="pdf-header-red fw-bold text-uppercase mb-1" style="color: #dc2626; font-size: 1.65rem; text-decoration: underline; letter-spacing: 0.5px;">
            <?= htmlspecialchars($companyName) ?>
        </h2>
        <div class="fw-semibold text-dark" style="font-size: 0.88rem; margin-bottom: 2px;">
            <?= htmlspecialchars($companyTagline) ?>
        </div>
        <div style="font-size: 0.82rem; color: #111111; line-height: 1.35;">
            <?= htmlspecialchars($companyAddress) ?>
        </div>
    </div>

    <!-- 3. Invoice Number & Date Meta Row -->
    <div class="d-flex justify-content-between align-items-center fw-bold" style="font-size: 0.92rem; color: #000000; margin-bottom: 8px;">
        <span>Invoice No. <?= htmlspecialchars($invoice['invoice_number']) ?></span>
        <span>Invoice Date. <?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?></span>
    </div>

    <!-- 4. Client M/S Line: Bold black M/S: and Bold red [CLIENT NAME] -->
    <div class="mb-2" style="font-size: 1.05rem; text-transform: uppercase;">
        <strong style="color: #000000; font-weight: 800;">M/S:</strong> 
        <span style="color: #dc2626; font-weight: 800;"><?= htmlspecialchars($invoice['company_name']) ?></span>
    </div>

    <!-- 5. Main Attendance & Category Deployment Grid Table (100% Dynamic - Any Number of Categories) -->
    <table class="pdf-invoice-table">
        <thead>
            <tr>
                <th style="width: 18%;">DATE</th>
                <th style="width: 16%;">SHIFT</th>
                <th style="width: 32%;">VENUE</th>
                <?php foreach ($activeCategories as $cat): ?>
                    <th style="width: <?= round(34 / max(1, count($activeCategories)), 1) ?>%;"><?= htmlspecialchars($cat) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($groupedRows)): ?>
                <tr>
                    <td colspan="<?= 3 + count($activeCategories) ?>" class="py-4 text-muted fst-italic">
                        No shift deployment records found for this invoice period.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($groupedRows as $row): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($row['date'])) ?></td>
                        <td><?= htmlspecialchars($row['shift']) ?></td>
                        <td><?= htmlspecialchars($row['venue']) ?></td>
                        <?php foreach ($activeCategories as $cat): 
                            $cnt = $row['counts'][$cat] ?? 0;
                        ?>
                            <td><?= $cnt > 0 ? $cnt : '-' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Total Row -->
            <tr class="row-total">
                <td colspan="3" class="total-label-red" style="color: #dc2626; font-weight: 800;">TOTAL</td>
                <?php foreach ($activeCategories as $cat): ?>
                    <td style="color: #000000; font-weight: 800;"><?= $categoryTotals[$cat] ?></td>
                <?php endforeach; ?>
            </tr>

            <!-- Highlighted Yellow Calculation / Formula Row -->
            <tr>
                <td colspan="<?= 3 + count($activeCategories) ?>" class="formula-highlight-row">
                    <?= htmlspecialchars($formulaString) ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- 6. Tax / GST Breakdown (If GST active) -->
    <?php if ((float)$invoice['tax_percentage'] > 0): ?>
        <div class="d-flex justify-content-between align-items-center p-2 mt-1 border border-dark rounded-1" style="background-color: #f8fafc; font-size: 0.85rem; font-weight: 700;">
            <span>Subtotal: ₹<?= number_format($invoice['subtotal'], 2) ?></span>
            <span class="text-primary">GST (<?= $invoice['tax_percentage'] ?>%): ₹<?= number_format($invoice['tax_amount'], 2) ?></span>
            <span class="text-success fs-6">Grand Total Amount: ₹<?= number_format($invoice['total_amount'], 2) ?></span>
        </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- 7. PREVIOUS BACK DUES STATEMENT (SHOWN IF CARRIED FORWARD DUES EXIST)     -->
    <!-- ========================================================================= -->
    <?php if (!empty($backDueInvoices) && $totalBackDue > 0): ?>
    <div class="mt-3 page-break-inside-avoid">
        <div class="mb-1" style="border-bottom: 1.5px solid #000; padding-bottom: 2px;">
            <span class="fw-bold text-uppercase" style="font-size: 0.84rem; color: #b91c1c;">
                <i class="bi bi-clock-history me-1"></i> PREVIOUS BACK DUES STATEMENT
            </span>
        </div>

        <table class="secondary-table mb-1">
            <thead>
                <tr>
                    <th style="width: 25%;">Previous Invoice #</th>
                    <th style="width: 20%;">Invoice Date</th>
                    <th style="width: 20%; text-align: right;">Total Billed</th>
                    <th style="width: 18%; text-align: right;">Paid</th>
                    <th style="width: 17%; text-align: right;">Back Due Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backDueInvoices as $bd): ?>
                    <tr>
                        <td class="fw-bold font-monospace"><?= htmlspecialchars($bd['invoice_number']) ?></td>
                        <td><?= date('d-m-Y', strtotime($bd['invoice_date'])) ?></td>
                        <td style="text-align: right;">₹<?= number_format($bd['total_amount'], 2) ?></td>
                        <td style="text-align: right; color: #166534;">₹<?= number_format($bd['paid_amount'], 2) ?></td>
                        <td style="text-align: right; font-weight: 800; color: #dc2626;">₹<?= number_format($bd['balance_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="d-flex justify-content-between align-items-center p-2 border border-danger rounded-1 mt-1" style="background-color: #fff1f2; font-size: 0.84rem;">
            <div>
                Current Bill: <strong>₹<?= number_format((float)$invoice['total_amount'], 2) ?></strong>
                + Previous Back Due: <strong class="text-danger">₹<?= number_format($totalBackDue, 2) ?></strong>
            </div>
            <div class="fw-bold text-danger fs-6">
                Net Total Amount Payable: ₹<?= number_format($netBillPayable, 2) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Settlement / Payment / Advance / Carry Forward Status Bar -->
    <?php if ($invoice['payment_status'] === 'carried_forward'): ?>
        <div class="d-flex justify-content-between align-items-center px-2.5 py-1.5 mt-2 border rounded-1 page-break-inside-avoid" style="background-color: #f0f9ff; font-size: 0.80rem; border-color: #0284c7 !important; white-space: nowrap;">
            <div style="white-space: nowrap;">
                <span class="badge bg-info text-dark me-2 text-uppercase fw-bold" style="font-size: 0.74rem; letter-spacing: 0.4px;"><i class="bi bi-arrow-right-circle me-1"></i> CARRY FORWARD</span>
                <span>Bill Total: <strong>₹<?= number_format((float)$invoice['total_amount'], 2) ?></strong></span>
                <span class="ms-2 text-muted">Balance: <strong class="text-secondary">₹0.00</strong></span>
            </div>
            <div class="fw-bold text-primary font-monospace" style="font-size: 0.84rem; white-space: nowrap;">
                <i class="bi bi-arrow-right-circle-fill me-1"></i> Carry Forward to <?= htmlspecialchars(!empty($invoice['carried_to_invoice_number']) ? $invoice['carried_to_invoice_number'] : 'New Invoice') ?>
            </div>
        </div>
    <?php elseif ($currentPaid > 0): ?>
        <?php if ($netBalance < 0): ?>
            <div class="d-flex justify-content-between align-items-center px-2.5 py-1.5 mt-2 border border-success rounded-1 page-break-inside-avoid" style="background-color: #f0fdf4; font-size: 0.80rem; white-space: nowrap;">
                <div style="white-space: nowrap;">
                    <span class="badge bg-success me-2 text-uppercase" style="font-size: 0.74rem;">PAID &bull; ADVANCE</span>
                    <span>Net Payable: <strong>₹<?= number_format($netBillPayable, 2) ?></strong></span>
                    <span class="ms-2 text-success">Total Paid: <strong>₹<?= number_format($currentPaid, 2) ?></strong></span>
                </div>
                <div class="fw-bold text-success" style="font-size: 0.84rem; white-space: nowrap;">
                    <i class="bi bi-check2-circle me-1"></i> Advance / Excess Paid: ₹<?= number_format(abs($netBalance), 2) ?>
                </div>
            </div>
        <?php elseif ($netBalance == 0): ?>
            <div class="d-flex justify-content-between align-items-center px-2.5 py-1.5 mt-2 border border-success rounded-1 page-break-inside-avoid" style="background-color: #f0fdf4; font-size: 0.80rem; white-space: nowrap;">
                <div style="white-space: nowrap;">
                    <span class="badge bg-success me-2 text-uppercase" style="font-size: 0.74rem;">PAID IN FULL</span>
                    <span>Net Bill Payable: <strong>₹<?= number_format($netBillPayable, 2) ?></strong></span>
                </div>
                <div class="fw-bold text-success" style="font-size: 0.84rem; white-space: nowrap;">
                    <i class="bi bi-check2-circle me-1"></i> Fully Settled (₹<?= number_format($currentPaid, 2) ?> Paid)
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center px-2.5 py-1.5 mt-2 border border-warning rounded-1 page-break-inside-avoid" style="background-color: #fffbeb; font-size: 0.80rem; white-space: nowrap;">
                <div style="white-space: nowrap;">
                    <span class="badge bg-warning text-dark me-2 text-uppercase" style="font-size: 0.74rem;">PARTIALLY PAID</span>
                    <span>Net Payable: <strong>₹<?= number_format($netBillPayable, 2) ?></strong></span>
                    <span class="ms-2 text-success">Paid: <strong>₹<?= number_format($currentPaid, 2) ?></strong></span>
                </div>
                <div class="fw-bold text-danger" style="font-size: 0.84rem; white-space: nowrap;">
                    Remaining Balance Due: ₹<?= number_format($netBalance, 2) ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- 8. LAST 3 RECEIVED PAYMENTS DETAILS (SHOWN ONLY IF PAYMENTS EXIST)        -->
    <!-- ========================================================================= -->
    <?php if (!empty($lastPayments)): ?>
    <div class="mt-3 page-break-inside-avoid">
        <div class="d-flex align-items-center justify-content-between mb-1" style="border-bottom: 1.5px solid #000; padding-bottom: 2px;">
            <span class="fw-bold text-uppercase" style="font-size: 0.84rem; color: #1e3a8a;">
                <i class="bi bi-receipt-cutoff me-1"></i> LAST 3 RECEIVED PAYMENTS DETAILS
            </span>
        </div>

        <table class="secondary-table mb-1">
            <thead>
                <tr>
                    <th style="width: 6%;">#</th>
                    <th style="width: 22%;">Receipt</th>
                    <th style="width: 18%;">Payment Date</th>
                    <th style="width: 20%;">Payment Mode</th>
                    <th style="width: 20%;">Remarks</th>
                    <th style="width: 14%; text-align: right;">Amount Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php $pIdx = 1; foreach ($lastPayments as $pay): ?>
                    <tr>
                        <td><?= $pIdx++ ?></td>
                        <td class="fw-bold font-monospace"><?= htmlspecialchars($pay['payment_number'] ?? ('REC-' . str_pad((string)$pay['id'], 4, '0', STR_PAD_LEFT))) ?></td>
                        <td><?= date('d-m-Y', strtotime($pay['payment_date'])) ?></td>
                        <td class="text-uppercase fw-semibold"><?= htmlspecialchars(str_replace('_', ' ', $pay['payment_mode'])) ?></td>
                        <td><?= htmlspecialchars($pay['reference_number'] ?? '-') ?></td>
                        <td style="text-align: right; font-weight: 800; color: #166534;">₹<?= number_format($pay['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- 9. BANK TRANSFER DETAILS & AUTHORIZED SIGNATURE                           -->
    <!-- ========================================================================= -->
    <div class="row align-items-end mt-4 pt-2 page-break-inside-avoid">
        <!-- Left: Bank Account Info -->
        <div class="col-7">
            <div class="p-2 border border-dark rounded-1" style="background-color: #f8fafc; font-size: 0.74rem; line-height: 1.4;">
                <div class="fw-bold text-dark text-uppercase mb-1" style="font-size: 0.76rem;">
                    <i class="bi bi-bank me-1 text-primary"></i> Bank Transfer & Payment Details:
                </div>
                <div><strong>Beneficiary Name:</strong> <?= htmlspecialchars(!empty($company['account_holder']) ? $company['account_holder'] : $companyName) ?></div>
                <div><strong>Bank Name:</strong> <?= htmlspecialchars(!empty($company['bank_name']) ? $company['bank_name'] : ($bankAccount['bank_name'] ?? 'HDFC Bank')) ?></div>
                <div><strong>Account No:</strong> <span class="font-monospace fw-bold"><?= htmlspecialchars(!empty($company['account_number']) ? $company['account_number'] : ($bankAccount['account_number'] ?? '50200012345678')) ?></span></div>
                <div><strong>IFSC Code:</strong> <span class="font-monospace fw-bold"><?= htmlspecialchars(!empty($company['ifsc_code']) ? $company['ifsc_code'] : ($bankAccount['ifsc_code'] ?? 'HDFC0001234')) ?></span></div>
                <?php if (!empty($company['upi_id']) || !empty($upiAccount['account_number'])): ?>
                    <div class="mt-0.5 pt-0.5 border-top">
                        <strong>Official UPI ID:</strong> <span class="text-success fw-bold font-monospace"><?= htmlspecialchars(!empty($company['upi_id']) ? $company['upi_id'] : ($upiAccount['account_number'] ?? 'kansanmanpower@hdfcbank')) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Authorized Signature (Matches PDF Exactly) -->
        <div class="col-5 text-end">
            <div class="d-inline-block text-center" style="min-width: 230px;">
                <div style="height: 82px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 4px;">
                    <?php if (!empty($company['signature_url']) && file_exists(ROOT_PATH . '/' . $company['signature_url'])): ?>
                        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($company['signature_url']) ?>" alt="Authorized Signature" style="max-height: 80px; max-width: 220px; object-fit: contain;">
                    <?php endif; ?>
                </div>
                <div class="fw-bold text-uppercase" style="font-size: 0.88rem; color: #000000; letter-spacing: 0.5px; border-top: 1px dashed #666; padding-top: 3px;">
                    AUTHORIZED SIGNATURE
                </div>
                <div class="text-muted small" style="font-size: 0.70rem;">
                    <?= htmlspecialchars($companyName) ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Tax System Toggle Modal -->
<div class="modal fade" id="taxToggleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background-color: #ffffff !important;">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="toggle_tax">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold"><i class="bi bi-percent me-2"></i>Invoice Tax System (GST) Configuration</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="background-color: #ffffff !important;">
                    <p class="text-muted small mb-3">
                        Is invoice par Tax (GST) lagana hai ya hatana hai, option chunein:
                    </p>
                    <div class="mb-3">
                        <div class="form-check p-3 bg-light rounded border mb-2.5">
                            <input class="form-check-input" type="radio" name="tax_choice" id="taxChoice18" value="18" 
                                   <?= ((float)$invoice['tax_percentage'] > 0) ? 'checked' : '' ?> 
                                   onchange="document.getElementById('taxPctInput').value='18.0'">
                            <label class="form-check-label fw-bold text-dark d-block" for="taxChoice18">
                                <i class="bi bi-check-circle-fill text-primary me-1"></i> Apply Standard GST (18.00%)
                                <span class="d-block text-muted small fw-normal mt-0.5">Standard tax calculate hoga aur bill amount me judega.</span>
                            </label>
                        </div>
                        <div class="form-check p-3 bg-light rounded border">
                            <input class="form-check-input" type="radio" name="tax_choice" id="taxChoice0" value="0" 
                                   <?= ((float)$invoice['tax_percentage'] == 0) ? 'checked' : '' ?> 
                                   onchange="document.getElementById('taxPctInput').value='0.0'">
                            <label class="form-check-label fw-bold text-dark d-block" for="taxChoice0">
                                <i class="bi bi-slash-circle-fill text-warning me-1"></i> Remove Tax (0% - Non-GST / Tax-Exempt Bill)
                                <span class="d-block text-muted small fw-normal mt-0.5">Tax hat jayega aur Total Amount sidhe subtotal ke barabar hoga.</span>
                            </label>
                        </div>
                    </div>
                    <input type="hidden" name="tax_percentage" id="taxPctInput" value="<?= (float)$invoice['tax_percentage'] ?>">
                </div>
                <div class="modal-footer bg-light" style="background-color: #f8fafc !important;">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-bold">
                        <i class="bi bi-save me-1"></i> Save Tax Setting
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function downloadInvoicePDF() {
    downloadElementAsPDF('invoice-printable-area', 'Invoice_<?= htmlspecialchars($invoice['invoice_number']) ?>', {
        margin: [6, 6, 6, 6],
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    });
}

<?php if (isset($_GET['download_pdf']) && $_GET['download_pdf'] == '1'): ?>
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(downloadInvoicePDF, 600);
});
<?php endif; ?>
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
