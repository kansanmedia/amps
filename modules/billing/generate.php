<?php
// modules/billing/generate.php - Compile Invoices from Approved Attendance
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/BillingService.php';
Auth::requireRole(['owner_admin', 'accountant']);

$db = DB::getConnection();
$error = '';

$selectedClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : (!empty($_POST['client_id']) ? (int)$_POST['client_id'] : null);
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First of current month by default
$endDate = $_GET['end_date'] ?? date('Y-m-d');
if (strtotime($endDate) > strtotime(date('Y-m-d'))) {
    $endDate = date('Y-m-d');
}

// Handle Invoice Generation Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_invoice') {
    CSRF::validateRequest();
    $clientId = (int)$_POST['client_id'];
    $startDate = $_POST['period_start'];
    $endDate = $_POST['period_end'];
    if (strtotime($endDate) > strtotime(date('Y-m-d'))) {
        $endDate = date('Y-m-d');
    }
    $taxPercentage = (float)($_POST['tax_percentage'] ?? 18.00);
    $discountAmount = (float)($_POST['discount_amount'] ?? 0.00);
    $attendanceIds = $_POST['attendance_ids'] ?? [];
    $categoryRates = $_POST['category_rates'] ?? [];
    $notes = trim($_POST['notes'] ?? '');

    if (empty($clientId)) {
        $error = 'Please choose a client.';
    } elseif (empty($attendanceIds)) {
        $error = 'Please select at least one attendance shift record to bill.';
    } else {
        try {
            $invoiceId = BillingService::generateInvoice($clientId, $startDate, $endDate, $taxPercentage, $discountAmount, $attendanceIds, $notes, $categoryRates);
            Helper::setFlash('success', 'Invoice generated successfully with updated category rates and posted to accounting ledger!');
            Helper::redirect("modules/billing/view.php?id={$invoiceId}");
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch ONLY clients who have unbilled shifts waiting to be invoiced (Invoice Dues) - Exclude future dates & scheduled shifts
$clientsQuery = "
    SELECT c.id, c.company_name, c.gst_number, c.default_billing_rate,
           MIN(a.attendance_date) as earliest_unbilled_date,
           MAX(a.attendance_date) as latest_unbilled_date,
           COUNT(a.id) as unbilled_count
    FROM clients c
    JOIN attendance a ON a.client_id = c.id
    WHERE a.is_billed = 0 
      AND a.attendance_date <= CURDATE() 
      AND a.status IN ('present', 'half_day')
    GROUP BY c.id, c.company_name, c.gst_number, c.default_billing_rate
    ORDER BY earliest_unbilled_date ASC, c.company_name ASC
";
$clients = $db->query($clientsQuery)->fetchAll();

// If a client is selected but not currently in unbilled list, preserve it
if ($selectedClientId && !in_array($selectedClientId, array_column($clients, 'id'))) {
    $selCStmt = $db->prepare("SELECT id, company_name, gst_number, default_billing_rate FROM clients WHERE id = :id");
    $selCStmt->execute([':id' => $selectedClientId]);
    if ($sc = $selCStmt->fetch()) {
        $sc['earliest_unbilled_date'] = null;
        $sc['latest_unbilled_date'] = null;
        $sc['unbilled_count'] = 0;
        $clients[] = $sc;
    }
}

// Auto-expand start_date if client has pending shifts earlier than startDate
$selectedClientPendingDate = null;
if ($selectedClientId) {
    foreach ($clients as $cl) {
        if ($cl['id'] == $selectedClientId) {
            $selectedClientPendingDate = $cl['earliest_unbilled_date'] ?? null;
            if (!empty($selectedClientPendingDate)) {
                if (empty($_GET['start_date']) || strtotime($startDate) > strtotime($selectedClientPendingDate)) {
                    $startDate = $selectedClientPendingDate;
                }
            }
            break;
        }
    }
}

// Default Tax preference: Default OFF (0% Non-GST) as requested
$defaultTaxEnabled = false;
$defaultTaxPercentage = 0.0;

// Fetch unbilled attendance records and category rates if client selected
$unbilledAttendance = [];
$activeCategories = [];
$clientCategoryRates = [];
$selectedClient = null;

if ($selectedClientId) {
    $unbilledAttendance = BillingService::getUnbilledAttendance($selectedClientId, $startDate, $endDate);
    $clientCategoryRates = BillingService::getClientCategoryRates($selectedClientId);
    
    $cStmt = $db->prepare("SELECT * FROM clients WHERE id = :id");
    $cStmt->execute([':id' => $selectedClientId]);
    $selectedClient = $cStmt->fetch();

    foreach ($unbilledAttendance as $att) {
        $catId = (int)($att['resolved_category_id'] ?? 1);
        $catName = !empty($att['category_name']) ? $att['category_name'] : (!empty($att['skill_category']) ? $att['skill_category'] : 'Service');
        if (!isset($activeCategories[$catId])) {
            $prefill = $clientCategoryRates[$catId] ?? null;
            if ($prefill === null || $prefill <= 0) {
                $prefill = !empty($att['client_rate']) && (float)$att['client_rate'] > 0 
                    ? (float)$att['client_rate'] 
                    : (float)($selectedClient['default_billing_rate'] ?? 650.00);
            }
            $activeCategories[$catId] = [
                'id' => $catId,
                'name' => $catName,
                'count' => 0,
                'rate' => (float)$prefill
            ];
        }
        $activeCategories[$catId]['count']++;
    }
}

$pageTitle = 'Generate Client Invoice';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/billing/index.php" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0">Generate Client Invoice</h4>
            <div class="text-muted small">Auto-compile shift attendance into an invoice with line items and accounting ledger postings.</div>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Step 1: Selection Form -->
<div class="custom-card p-3 mb-4" style="overflow: visible;">
    <form method="GET" action="" class="row g-3 align-items-end" id="clientFilterForm">
        <div class="col-md-5">
            <label class="form-label small fw-semibold">Select Client with Pending Invoices *</label>
            <input type="hidden" name="client_id" id="selectedClientIdInput" value="<?= $selectedClientId ?: '' ?>" required>
            
            <div class="dropdown client-select-dropdown w-100">
                <button type="button" 
                        class="form-select form-select-sm text-start d-flex align-items-center justify-content-between w-100 bg-white" 
                        id="clientSelectDropdownBtn" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false" 
                        style="height: 34px; font-size: 0.8125rem;">
                    <span class="text-truncate me-2" id="selectedClientDisplay">
                        <?php if ($selectedClient): 
                            $pDate = $selectedClientPendingDate ? date('d M Y', strtotime($selectedClientPendingDate)) : null;
                        ?>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($selectedClient['company_name']) ?></span>
                            <?php if ($pDate): ?>
                                <span class="client-due-tag ms-1" style="color: #dc2626; font-size: 0.74rem; font-weight: 500;">— (Invoice pending from <?= $pDate ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-- Choose Client (<?= count($clients) ?> with pending dues) --</span>
                        <?php endif; ?>
                    </span>
                </button>
                <div class="dropdown-menu shadow-lg border p-1 w-100" style="max-height: 320px; overflow-y: auto; min-width: 100%;">
                    <div class="p-1 pb-2 border-bottom mb-1">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control form-control-sm border-start-0" id="clientFilterSearch" placeholder="Search client name..." autocomplete="off">
                        </div>
                    </div>
                    <?php if (empty($clients)): ?>
                        <div class="p-3 text-center text-muted small">No clients with pending invoice dues</div>
                    <?php else: ?>
                        <?php foreach ($clients as $cl): 
                            $isSelected = ($selectedClientId == $cl['id']);
                            $pDate = !empty($cl['earliest_unbilled_date']) ? date('d M Y', strtotime($cl['earliest_unbilled_date'])) : null;
                        ?>
                            <button type="button" 
                                    class="dropdown-item client-opt-btn rounded-1 py-1.5 px-2 text-start d-flex align-items-center justify-content-between w-100 <?= $isSelected ? 'is-active bg-primary-subtle text-dark fw-semibold' : '' ?>"
                                    data-name="<?= strtolower(htmlspecialchars($cl['company_name'])) ?>"
                                    onclick="selectClientFilter(<?= $cl['id'] ?>)">
                                <div class="text-truncate">
                                    <span class="fw-medium text-dark"><?= htmlspecialchars($cl['company_name']) ?></span>
                                    <?php if ($pDate): ?>
                                        <span class="client-due-tag ms-1" style="color: #dc2626; font-size: 0.74rem; font-weight: 500;">— (Invoice pending from <?= $pDate ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isSelected): ?>
                                    <i class="bi bi-check-lg text-primary ms-2"></i>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($selectedClientId && !empty($selectedClientPendingDate)): ?>
                <div class="mt-1.5 d-flex align-items-center gap-1.5 flex-wrap">
                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle d-inline-flex align-items-center gap-1 py-1 px-2" style="font-size: 0.74rem;">
                        <i class="bi bi-clock-history text-warning"></i> Due Since: <strong><?= date('d M Y', strtotime($selectedClientPendingDate)) ?></strong>
                    </span>
                    <span class="badge bg-light text-secondary border py-1 px-2" style="font-size: 0.72rem;">
                        <?= count($unbilledAttendance) ?> Shifts Pending
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-semibold">Billing Period Start *</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $startDate ?>" max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-semibold">Billing Period End *</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $endDate ?>" max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i> Fetch Shifts
            </button>
        </div>
    </form>
</div>

<?php if ($selectedClientId): ?>
    <?php if (empty($unbilledAttendance)): ?>
        <div class="custom-card text-center py-5">
            <i class="bi bi-check2-circle fs-1 text-success d-block mb-2"></i>
            <h5 class="fw-bold text-dark">No Unbilled Attendance Found</h5>
            <p class="text-muted small mb-0">All approved shifts for this client in the selected date range are already billed or none exist.</p>
        </div>
    <?php else: ?>
        <form method="POST" action="" id="invoiceForm">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="action" value="generate_invoice">
            <input type="hidden" name="client_id" value="<?= $selectedClientId ?>">
            <input type="hidden" name="period_start" value="<?= $startDate ?>">
            <input type="hidden" name="period_end" value="<?= $endDate ?>">

            <div class="row g-4">
                <!-- Left: Attendance Items List -->
                <div class="col-lg-8">
                    <div class="custom-card">
                        <div class="custom-card-header">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input" type="checkbox" id="selectAllItems" checked onchange="toggleAllItems(this)">
                                <label class="form-check-label fw-bold" for="selectAllItems">
                                    Select All Shifts (<?= count($unbilledAttendance) ?> unbilled)
                                </label>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-custom table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"></th>
                                        <th>Date</th>
                                        <th>Venue Site</th>
                                        <th>Shift</th>
                                        <th>Staff Name</th>
                                        <th>Category</th>
                                        <th>Rate</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unbilledAttendance as $att): 
                                        $catId = (int)($att['resolved_category_id'] ?? 1);
                                        $catDisplay = !empty($att['category_name']) ? $att['category_name'] : (!empty($att['skill_category']) ? $att['skill_category'] : 'Service');
                                        $effectiveRate = (float)($activeCategories[$catId]['rate'] ?? $att['client_rate']);
                                        $multiplier = (float)($att['billable_rate_multiplier'] ?? 1.0);
                                        if ($att['status'] === 'half_day' && $multiplier >= 1.0) {
                                            $multiplier = 0.5;
                                        }
                                        $initialAmount = round($multiplier * $effectiveRate, 2);
                                    ?>
                                        <tr id="att-row-<?= $att['id'] ?>" data-cat-id="<?= $catId ?>">
                                            <td>
                                                <input class="form-check-input item-checkbox" type="checkbox" name="attendance_ids[]" 
                                                       value="<?= $att['id'] ?>" 
                                                       data-cat-id="<?= $catId ?>"
                                                       data-multiplier="<?= $multiplier ?>"
                                                       data-amount="<?= $initialAmount ?>" 
                                                       checked onchange="recalculateInvoice()">
                                            </td>
                                            <td><strong><?= Helper::formatDate($att['attendance_date']) ?></strong></td>
                                            <td><?= htmlspecialchars($att['site_name']) ?></td>
                                            <td><?= Helper::shiftBadge($att['shift_name'], $att['shift_code'], (bool)$att['is_overnight']) ?></td>
                                            <td><?= htmlspecialchars($att['staff_name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($catDisplay) ?></span></td>
                                            <td id="row-rate-<?= $att['id'] ?>" class="fw-semibold"><?= Helper::formatCurrency($effectiveRate) ?></td>
                                            <td><strong id="row-amount-<?= $att['id'] ?>" class="text-primary"><?= Helper::formatCurrency($initialAmount) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right: Invoice Calculations & Summary Card -->
                <div class="col-lg-4">
                    <div class="custom-card">
                        <div class="custom-card-header bg-light">
                            <h6 class="fw-bold mb-0">Invoice Financial Summary</h6>
                        </div>
                        <div class="p-3">
                            <!-- Category-wise Billing Rates (Placed inside Financial Summary) -->
                            <?php if (!empty($activeCategories)): ?>
                                <div class="mb-3 p-2.5 bg-light rounded border">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <label class="form-label small fw-bold mb-0 text-dark">
                                            <i class="bi bi-tags-fill me-1 text-primary"></i> Category Billing Rates
                                        </label>
                                        <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.70rem;">Auto-Save</span>
                                    </div>
                                    <?php foreach ($activeCategories as $catId => $catInfo): ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="small fw-semibold text-secondary" style="font-size: 0.80rem;"><?= htmlspecialchars($catInfo['name']) ?> Rate:</span>
                                                <span class="badge bg-light text-dark border" style="font-size: 0.68rem;"><?= $catInfo['count'] ?> Shifts</span>
                                            </div>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text bg-white fw-bold">₹</span>
                                                <input type="number" step="0.5" min="0" 
                                                       name="category_rates[<?= $catId ?>]" 
                                                       id="cat_rate_<?= $catId ?>" 
                                                       class="form-control fw-bold text-primary category-rate-input" 
                                                       data-cat-id="<?= $catId ?>" 
                                                       value="<?= number_format($catInfo['rate'], 2, '.', '') ?>" 
                                                       oninput="onCategoryRateChange(<?= $catId ?>, this.value)">
                                                <span class="input-group-text bg-light text-muted">/ shift</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-muted" style="font-size: 0.68rem;">
                                        <i class="bi bi-info-circle me-1"></i> Overwrite karne par rate client ke liye save ho jayega.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">Selected Shifts:</span>
                                <strong id="summaryItemCount" class="text-dark">0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">Subtotal (Shifts Gross):</span>
                                <strong id="summarySubtotal" class="text-dark">₹0.00</strong>
                            </div>

                            <hr class="my-2">

                            <!-- 1-Click Tax System Toggle (Default OFF / 0% Non-GST) -->
                            <div class="mb-3 p-2.5 bg-light rounded border">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-label small fw-bold mb-0 text-dark">
                                        <i class="bi bi-receipt me-1 text-primary"></i> Tax System (GST)
                                    </label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="taxSwitch" <?= $defaultTaxEnabled ? 'checked' : '' ?> onchange="toggleTaxSystem(this)">
                                        <label class="form-check-label small fw-semibold" for="taxSwitch" id="taxSwitchLabel">
                                            <?= $defaultTaxEnabled ? 'Apply GST (18%)' : 'Non-GST (0%)' ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Rate %</span>
                                    <input type="number" step="0.5" min="0" max="100" name="tax_percentage" id="taxPercentage" class="form-control" value="<?= number_format($defaultTaxPercentage, 1, '.', '') ?>" oninput="handleTaxInput(this)">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="text-muted mt-1" style="font-size: 0.70rem;">
                                    Default OFF (Non-GST). GST lagane ke liye switch ON karein.
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mb-2 small">
                                <span class="text-muted">Tax Amount (GST):</span>
                                <strong id="summaryTax" class="text-primary">₹0.00</strong>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Discount (₹ Flat Amount)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="10" name="discount_amount" id="discountAmount" class="form-control" value="0.00" oninput="recalculateInvoice()">
                                </div>
                            </div>

                            <hr class="my-2">

                            <div class="d-flex justify-content-between align-items-center mb-3 p-2.5 bg-light rounded border">
                                <span class="fw-bold text-dark">Invoice Total:</span>
                                <span id="summaryGrandTotal" class="fw-bold fs-5 text-success">₹0.00</span>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Invoice Terms / Notes</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Services rendered for monthly banquet coverage."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                                <i class="bi bi-file-earmark-check me-1"></i> Generate & Post Invoice
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
function onCategoryRateChange(catId, newRateVal) {
    const rate = parseFloat(newRateVal) || 0;
    const checkboxes = document.querySelectorAll('.item-checkbox[data-cat-id="' + catId + '"]');
    checkboxes.forEach(cb => {
        const multiplier = parseFloat(cb.dataset.multiplier) || 1.0;
        const newAmount = Math.round((rate * multiplier) * 100) / 100;
        cb.dataset.amount = newAmount;
        
        const attId = cb.value;
        const rateEl = document.getElementById('row-rate-' + attId);
        const amtEl = document.getElementById('row-amount-' + attId);
        if (rateEl) {
            rateEl.textContent = '₹' + rate.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        if (amtEl) {
            amtEl.textContent = '₹' + newAmount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    });
    recalculateInvoice();
}

function toggleAllItems(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    recalculateInvoice();
}

function toggleTaxSystem(switcher) {
    const taxInput = document.getElementById('taxPercentage');
    const label = document.getElementById('taxSwitchLabel');
    if (switcher.checked) {
        taxInput.value = '18.0';
        label.textContent = 'Apply GST (18%)';
    } else {
        taxInput.value = '0.0';
        label.textContent = 'Non-GST (0%)';
    }
    recalculateInvoice();
}

function handleTaxInput(input) {
    const switcher = document.getElementById('taxSwitch');
    const label = document.getElementById('taxSwitchLabel');
    const val = parseFloat(input.value || 0);
    if (val > 0) {
        switcher.checked = true;
        label.textContent = 'Apply GST (' + val + '%)';
    } else {
        switcher.checked = false;
        label.textContent = 'Non-GST (0%)';
    }
    recalculateInvoice();
}

function recalculateInvoice() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    let subtotal = 0;
    checkboxes.forEach(cb => {
        subtotal += parseFloat(cb.dataset.amount || 0);
    });

    const taxPercent = parseFloat(document.getElementById('taxPercentage').value || 0);
    const discount = parseFloat(document.getElementById('discountAmount').value || 0);

    const taxAmount = (subtotal * (taxPercent / 100));
    let grandTotal = subtotal + taxAmount - discount;
    if (grandTotal < 0) grandTotal = 0;

    document.getElementById('summaryItemCount').textContent = checkboxes.length;
    document.getElementById('summarySubtotal').textContent = '₹' + subtotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summaryTax').textContent = '₹' + taxAmount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summaryGrandTotal').textContent = '₹' + grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function selectClientFilter(id) {
    const input = document.getElementById('selectedClientIdInput');
    const form = document.getElementById('clientFilterForm');
    if (input && form) {
        input.value = id;
        form.submit();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    recalculateInvoice();

    const clientSearch = document.getElementById('clientFilterSearch');
    if (clientSearch) {
        clientSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            const items = document.querySelectorAll('.client-opt-btn');
            items.forEach(el => {
                const name = el.getAttribute('data-name') || '';
                el.closest('li, button').style.display = name.includes(q) ? '' : 'none';
            });
        });
        clientSearch.addEventListener('click', (e) => e.stopPropagation());
        clientSearch.addEventListener('keydown', (e) => e.stopPropagation());
    }
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
