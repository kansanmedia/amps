<?php
// modules/clients/view.php - Client Profile, Ledger, Invoices, Sites & Category Rate Cards
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$clientId = (int)($_GET['id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'rates';

$stmt = $db->prepare("SELECT * FROM clients WHERE id = :id");
$stmt->execute([':id' => $clientId]);
$client = $stmt->fetch();

if (!$client) {
    Helper::setFlash('danger', 'Client not found.');
    Helper::redirect('modules/clients/index.php');
}

// Handle Category Rate Card Actions for this Client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_client_rate') {
        $rateId = !empty($_POST['rate_id']) ? (int)$_POST['rate_id'] : null;
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;
        $shiftId = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
        $clientRate = (float)($_POST['client_billing_rate'] ?? 0);
        $staffRate = 0.00; // Staff payout is configured on Staff Workforce menu
        $effectiveFrom = $_POST['effective_from'] ?? date('Y-m-d');
        $effectiveTo = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if ($clientRate <= 0) {
            Helper::setFlash('danger', 'Valid Client Billing Rate is required.');
        } else {
            if ($rateId) {
                $stmt = $db->prepare("
                    UPDATE rate_cards SET category_id = :catid, site_id = :sid, shift_id = :shid,
                        client_billing_rate = :cbr, staff_payout_rate = :spr, effective_from = :ef, effective_to = :et, notes = :nt
                    WHERE id = :id AND client_id = :cid
                ");
                $stmt->execute([
                    ':catid' => $categoryId, ':sid' => $siteId, ':shid' => $shiftId,
                    ':cbr' => $clientRate, ':spr' => $staffRate, ':ef' => $effectiveFrom, ':et' => $effectiveTo,
                    ':nt' => $notes, ':id' => $rateId, ':cid' => $clientId
                ]);
                Audit::log('UPDATE', 'RATE_CARDS', $rateId, null, $_POST);
                Helper::setFlash('success', 'Category rate rule updated for this client.');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO rate_cards (client_id, category_id, site_id, shift_id, client_billing_rate,
                                            staff_payout_rate, effective_from, effective_to, notes, created_by)
                    VALUES (:cid, :catid, :sid, :shid, :cbr, :spr, :ef, :et, :nt, :uid)
                ");
                $stmt->execute([
                    ':cid' => $clientId, ':catid' => $categoryId, ':sid' => $siteId, ':shid' => $shiftId,
                    ':cbr' => $clientRate, ':spr' => $staffRate, ':ef' => $effectiveFrom, ':et' => $effectiveTo,
                    ':nt' => $notes, ':uid' => Auth::id()
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'RATE_CARDS', $newId, null, $_POST);
                Helper::setFlash('success', 'Category pricing contract added for this client.');
            }
            Helper::redirect("modules/clients/view.php?id={$clientId}&tab=rates");
        }
    } elseif ($action === 'delete_client_rate') {
        $rateId = (int)($_POST['rate_id'] ?? 0);
        if ($rateId) {
            $stmt = $db->prepare("DELETE FROM rate_cards WHERE id = :id AND client_id = :cid");
            $stmt->execute([':id' => $rateId, ':cid' => $clientId]);
            Audit::log('DELETE', 'RATE_CARDS', $rateId, null, null);
            Helper::setFlash('info', 'Category pricing contract removed.');
            Helper::redirect("modules/clients/view.php?id={$clientId}&tab=rates");
        }
    }
}

// Invoices
$invStmt = $db->prepare("SELECT * FROM invoices WHERE client_id = :id ORDER BY invoice_date DESC, id DESC");
$invStmt->execute([':id' => $clientId]);
$invoices = $invStmt->fetchAll();

// Payments
$payStmt = $db->prepare("
    SELECT cp.*, fa.account_name, u.full_name as received_by_name
    FROM client_payments cp
    JOIN financial_accounts fa ON cp.account_id = fa.id
    LEFT JOIN users u ON cp.received_by = u.id
    WHERE cp.client_id = :id
    ORDER BY cp.payment_date DESC, cp.id DESC
");
$payStmt->execute([':id' => $clientId]);
$payments = $payStmt->fetchAll();

// Sites
$siteStmt = $db->prepare("SELECT * FROM sites WHERE client_id = :id ORDER BY id ASC");
$siteStmt->execute([':id' => $clientId]);
$sites = $siteStmt->fetchAll();

// Category Rate Cards for this client
$rcStmt = $db->prepare("
    SELECT rc.*, sc.category_name, sc.category_code, s.site_name, sh.shift_name, stf.full_name as staff_name
    FROM rate_cards rc
    LEFT JOIN staff_categories sc ON rc.category_id = sc.id
    LEFT JOIN sites s ON rc.site_id = s.id
    LEFT JOIN shifts sh ON rc.shift_id = sh.id
    LEFT JOIN staff stf ON rc.staff_id = stf.id
    WHERE rc.client_id = :id
    ORDER BY rc.id DESC
");
$rcStmt->execute([':id' => $clientId]);
$rateCards = $rcStmt->fetchAll();

// Master options for modals
$availableCategories = $db->query("SELECT id, category_code, category_name FROM staff_categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll();
$clientShifts = $db->query("SELECT id, shift_name, shift_code FROM shifts WHERE status = 'active' ORDER BY id ASC")->fetchAll();

// Metrics
$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$totalCollected = array_sum(array_column($payments, 'amount'));
$balanceDue = $totalInvoiced - $totalCollected;

$pageTitle = 'Client: ' . $client['company_name'];
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0"><?= htmlspecialchars($client['company_name']) ?></h4>
            <div class="text-muted small">Client Code: <strong><?= htmlspecialchars($client['client_code']) ?></strong> &bull; <?= htmlspecialchars($client['contact_person']) ?></div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-warning text-dark btn-sm fw-semibold shadow-2xs" onclick="openClientRateModal()">
            <i class="bi bi-tags-fill me-1"></i> + Set Category Rate
        </button>
        <?php if (Auth::isAccountant()): ?>
            <a href="<?= BASE_URL ?>/modules/billing/generate.php?client_id=<?= $client['id'] ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-receipt me-1"></i> Generate Invoice
            </a>
            <a href="<?= BASE_URL ?>/modules/payments/index.php?client_id=<?= $client['id'] ?>" class="btn btn-success btn-sm">
                <i class="bi bi-cash-stack me-1"></i> Record Payment
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/sites/index.php?client_id=<?= $client['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-geo-alt me-1"></i> Sites (<?= count($sites) ?>)
        </a>
    </div>
</div>

<!-- Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Contracted Role Rates</div>
            <div class="metric-value text-primary"><?= count($rateCards) ?></div>
            <div class="text-muted small mt-1">Category pricing rules</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Amount Billed</div>
            <div class="metric-value text-dark"><?= Helper::formatCurrency($totalInvoiced) ?></div>
            <div class="text-muted small mt-1">Gross client invoicing</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Payments Received</div>
            <div class="metric-value text-success"><?= Helper::formatCurrency($totalCollected) ?></div>
            <div class="text-muted small mt-1">Cleared to bank/cash/UPI</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Outstanding Balance Due</div>
            <div class="metric-value <?= $balanceDue > 0 ? 'text-danger' : 'text-secondary' ?>">
                <?= Helper::formatCurrency($balanceDue) ?>
            </div>
            <div class="text-muted small mt-1">Receivable balance</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Left: Client Info -->
    <div class="col-lg-4">
        <div class="custom-card">
            <div class="custom-card-header">
                <h6 class="fw-bold mb-0">Client Overview</h6>
                <?= Helper::statusBadge($client['status']) ?>
            </div>
            <div class="p-3">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Primary Phone:</span>
                        <strong class="text-dark"><?= htmlspecialchars($client['phone']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Billing Email:</span>
                        <span><?= htmlspecialchars($client['email'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">GST Number:</span>
                        <strong class="text-primary"><?= htmlspecialchars($client['gst_number'] ?: 'Not Registered') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">PAN Number:</span>
                        <span><?= htmlspecialchars($client['pan_number'] ?: '-') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Billing Cycle:</span>
                        <span class="badge bg-light text-dark border text-capitalize"><?= $client['billing_cycle'] ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Payment Terms:</span>
                        <span><?= $client['payment_terms_days'] ?> Days</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Fallback Shift Rate:</span>
                        <strong class="text-dark"><?= Helper::formatCurrency($client['default_billing_rate']) ?></strong>
                    </li>
                    <li class="list-group-item px-0">
                        <span class="text-muted d-block mb-1">Billing Address:</span>
                        <div class="text-dark"><?= nl2br(htmlspecialchars($client['billing_address'])) ?></div>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: Category Rates, Invoices, Payments, Sites Tabs -->
    <div class="col-lg-8">
        <div class="custom-card">
            <div class="custom-card-header p-0">
                <ul class="nav nav-tabs border-0 px-3 pt-2" id="clientTab" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?= $activeTab === 'rates' ? 'active' : '' ?> fw-semibold" data-bs-toggle="tab" data-bs-target="#rates-pane">
                            <i class="bi bi-tags-fill me-1 text-warning"></i> Category Rates (<?= count($rateCards) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?= $activeTab === 'invoices' ? 'active' : '' ?> fw-semibold" data-bs-toggle="tab" data-bs-target="#invoices-pane">
                            <i class="bi bi-receipt me-1 text-primary"></i> Invoices (<?= count($invoices) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?= $activeTab === 'payments' ? 'active' : '' ?> fw-semibold" data-bs-toggle="tab" data-bs-target="#payments-pane">
                            <i class="bi bi-cash-stack me-1 text-success"></i> Payments Received (<?= count($payments) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?= $activeTab === 'sites' ? 'active' : '' ?> fw-semibold" data-bs-toggle="tab" data-bs-target="#sites-pane">
                            <i class="bi bi-geo-alt me-1 text-info"></i> Sites (<?= count($sites) ?>)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content p-3" id="clientTabContent">
                <!-- 1. Category Rates Tab (Client-Wise Rate Card) -->
                <div class="tab-pane fade <?= $activeTab === 'rates' ? 'show active' : '' ?>" id="rates-pane">
                    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Contracted Role Rates for <?= htmlspecialchars($client['company_name']) ?></h6>
                            <p class="text-muted small mb-0">Specifies what this client pays per role, and what staff receives for working here.</p>
                        </div>
                        <button type="button" class="btn btn-warning text-dark btn-sm fw-semibold shadow-2xs" onclick="openClientRateModal()">
                            <i class="bi bi-plus-circle me-1"></i> + Add Category Rate
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th class="col-row-num text-center">#</th>
                                    <th>Staff Category / Role</th>
                                    <th>Venue Site Scope</th>
                                    <th>Shift Scope</th>
                                    <th class="text-end">Client Billing Rate</th>
                                    <th>Effective</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rateCards)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <i class="bi bi-tags fs-2 d-block mb-1 text-secondary"></i>
                                            No specific category rates configured for this client.<br>
                                            Staff deployed will use fallback client rate (<strong><?= Helper::formatCurrency($client['default_billing_rate']) ?></strong>).<br>
                                            <button type="button" class="btn btn-sm btn-primary mt-2" onclick="openClientRateModal()">
                                                <i class="bi bi-plus-lg me-1"></i> Set First Category Rate
                                            </button>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $rcNum = 1; foreach ($rateCards as $rc): ?>
                                        <tr>
                                            <td class="col-row-num text-center"><?= $rcNum++ ?></td>
                                            <td>
                                                <?php if (!empty($rc['category_name'])): ?>
                                                    <strong class="text-dark"><?= htmlspecialchars($rc['category_name']) ?></strong>
                                                    <span class="badge bg-light text-secondary border font-monospace ms-1" style="font-size:0.65rem;"><?= htmlspecialchars($rc['category_code']) ?></span>
                                                <?php elseif (!empty($rc['staff_name'])): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Worker: <?= htmlspecialchars($rc['staff_name']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary">All Categories</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= !empty($rc['site_name']) ? htmlspecialchars($rc['site_name']) : '<span class="text-muted small">All Sites</span>' ?>
                                            </td>
                                            <td>
                                                <?= !empty($rc['shift_name']) ? htmlspecialchars($rc['shift_name']) : '<span class="text-muted small">All Shifts</span>' ?>
                                            </td>
                                            <td class="text-end font-monospace">
                                                <strong class="text-dark"><?= Helper::formatCurrency($rc['client_billing_rate']) ?></strong>
                                                <div class="text-muted" style="font-size:0.65rem;">charge / shift</div>
                                            </td>
                                            <td class="small">
                                                <div><?= Helper::formatDate($rc['effective_from']) ?></div>
                                                <?php if (!empty($rc['effective_to'])): ?>
                                                    <div class="text-muted" style="font-size:0.68rem;">To: <?= Helper::formatDate($rc['effective_to']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1.5" onclick='editClientRate(<?= json_encode($rc) ?>)' title="Edit Rate Rule">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" action="" class="d-inline" data-confirm="Remove this category rate contract?" data-confirm-title="Delete Rate Contract" data-confirm-btn="btn-danger">
                                                        <?= CSRF::tokenField() ?>
                                                        <input type="hidden" name="action" value="delete_client_rate">
                                                        <input type="hidden" name="rate_id" value="<?= $rc['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1.5" title="Delete Rate Rule">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. Invoices Tab -->
                <div class="tab-pane fade <?= $activeTab === 'invoices' ? 'show active' : '' ?>" id="invoices-pane">
                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Billing Period</th>
                                    <th>Total Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invoices)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No invoices generated yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $inv): ?>
                                        <tr>
                                            <td><strong class="text-dark"><?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                                            <td><?= Helper::formatDate($inv['invoice_date']) ?></td>
                                            <td><small><?= Helper::formatDate($inv['period_start'], 'd M') ?> - <?= Helper::formatDate($inv['period_end'], 'd M Y') ?></small></td>
                                            <td><strong><?= Helper::formatCurrency($inv['total_amount']) ?></strong></td>
                                            <td class="text-success"><?= Helper::formatCurrency($inv['paid_amount']) ?></td>
                                            <td>
                                                <?php if ((float)$inv['balance_amount'] < 0): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">Adv: <?= Helper::formatCurrency(abs($inv['balance_amount'])) ?></span>
                                                <?php else: ?>
                                                    <strong class="<?= (float)$inv['balance_amount'] > 0 ? 'text-danger' : 'text-secondary' ?>"><?= Helper::formatCurrency($inv['balance_amount']) ?></strong>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= Helper::statusBadge($inv['payment_status'], $inv['carried_to_invoice_number'] ?? null) ?></td>
                                            <td class="text-end">
                                                <a href="<?= BASE_URL ?>/modules/billing/view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="View & Print Invoice">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. Payments Tab -->
                <div class="tab-pane fade <?= $activeTab === 'payments' ? 'show active' : '' ?>" id="payments-pane">
                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Payment Date</th>
                                    <th>Mode & Ref</th>
                                    <th>Credited Account</th>
                                    <th>Amount Received</th>
                                    <th>Entered By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No payments recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td><strong class="text-dark"><?= htmlspecialchars($p['payment_number']) ?></strong></td>
                                            <td><?= Helper::formatDate($p['payment_date']) ?></td>
                                            <td>
                                                <span class="text-uppercase fw-semibold" style="font-size:0.75rem;"><?= htmlspecialchars($p['payment_mode']) ?></span>
                                                <?php if (!empty($p['reference_number'])): ?>
                                                    <div class="text-muted" style="font-size:0.72rem;">Ref: <?= htmlspecialchars($p['reference_number']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><i class="bi bi-bank text-secondary me-1"></i><?= htmlspecialchars($p['account_name']) ?></td>
                                            <td><strong class="text-success fs-6"><?= Helper::formatCurrency($p['amount']) ?></strong></td>
                                            <td><small class="text-muted"><?= htmlspecialchars($p['received_by_name'] ?? 'Staff') ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. Sites Tab -->
                <div class="tab-pane fade <?= $activeTab === 'sites' ? 'show active' : '' ?>" id="sites-pane">
                    <div class="table-responsive">
                        <table class="table table-custom table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Site Code & Name</th>
                                    <th>Address / Location</th>
                                    <th>Contact</th>
                                    <th>Client Rate</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sites)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No sites defined for this client.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sites as $st): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= htmlspecialchars($st['site_name']) ?></strong>
                                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($st['site_code']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($st['location_address']) ?></td>
                                            <td>
                                                <div><?= htmlspecialchars($st['contact_person'] ?: '-') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($st['contact_phone'] ?: '') ?></small>
                                            </td>
                                            <td><strong><?= Helper::formatCurrency($st['default_client_rate'] ?? $client['default_billing_rate']) ?></strong></td>
                                            <td><?= Helper::statusBadge($st['status']) ?></td>
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

<!-- Modal: Add / Edit Client Category Rate Card -->
<div class="modal fade" id="clientRateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_client_rate">
                <input type="hidden" name="rate_id" id="modal_rate_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="clientRateModalTitle">Set Category Rate for <?= htmlspecialchars($client['company_name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Staff Category / Role *</label>
                            <select name="category_id" id="modal_rate_cat" class="form-select" required>
                                <option value="">-- Choose Category Role --</option>
                                <?php foreach ($availableCategories as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['category_name']) ?> (<?= $c['category_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:0.72rem;">e.g. Service / Waiter, Bartender, Captain, Housekeeping</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Client Billing Rate (₹ / Shift) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="10" name="client_billing_rate" id="modal_rate_client" class="form-control" placeholder="e.g. 850" required>
                            </div>
                            <div class="form-text" style="font-size:0.72rem;">Billing rate charged to client per shift. Staff payout is managed from the Staff Workforce menu.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Venue Site (Optional)</label>
                            <select name="site_id" id="modal_rate_site" class="form-select">
                                <option value="">-- All Sites of Client --</option>
                                <?php foreach ($sites as $st): ?>
                                    <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['site_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shift (Optional)</label>
                            <select name="shift_id" id="modal_rate_shift" class="form-select">
                                <option value="">-- All Shifts --</option>
                                <?php foreach ($clientShifts as $sh): ?>
                                    <option value="<?= $sh['id'] ?>"><?= htmlspecialchars($sh['shift_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Effective From Date</label>
                            <input type="date" name="effective_from" id="modal_rate_from" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Effective To Date (Optional)</label>
                            <input type="date" name="effective_to" id="modal_rate_to" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Notes / Agreement Details</label>
                            <input type="text" name="notes" id="modal_rate_notes" class="form-control" placeholder="e.g. Contract rate agreed with General Manager">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-check2-circle me-1"></i> Save Contract Rate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const clientRateModal = new bootstrap.Modal(document.getElementById('clientRateModal'));

function openClientRateModal() {
    document.getElementById('modal_rate_id').value = '';
    document.getElementById('modal_rate_cat').value = '';
    document.getElementById('modal_rate_client').value = '';
    document.getElementById('modal_rate_site').value = '';
    document.getElementById('modal_rate_shift').value = '';
    document.getElementById('modal_rate_from').value = '<?= date('Y-m-d') ?>';
    document.getElementById('modal_rate_to').value = '';
    document.getElementById('modal_rate_notes').value = '';
    document.getElementById('clientRateModalTitle').textContent = 'Add Category Rate for <?= htmlspecialchars($client['company_name']) ?>';
    clientRateModal.show();
}

function editClientRate(rc) {
    document.getElementById('modal_rate_id').value = rc.id;
    document.getElementById('modal_rate_cat').value = rc.category_id || '';
    document.getElementById('modal_rate_client').value = rc.client_billing_rate;
    document.getElementById('modal_rate_site').value = rc.site_id || '';
    document.getElementById('modal_rate_shift').value = rc.shift_id || '';
    document.getElementById('modal_rate_from').value = rc.effective_from || '<?= date('Y-m-d') ?>';
    document.getElementById('modal_rate_to').value = rc.effective_to || '';
    document.getElementById('modal_rate_notes').value = rc.notes || '';
    document.getElementById('clientRateModalTitle').textContent = 'Edit Category Rate for <?= htmlspecialchars($client['company_name']) ?>';
    clientRateModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
