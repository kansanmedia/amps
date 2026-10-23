<?php
// modules/rates/index.php - Rate Card Hierarchy & Rate Management
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$error = '';

// Handle Rate Card Create / Update / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rate') {
        $rateId = !empty($_POST['rate_id']) ? (int)$_POST['rate_id'] : null;
        $clientId = (int)($_POST['client_id'] ?? 0);
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;
        $shiftId = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $clientRate = (float)($_POST['client_billing_rate'] ?? 0);
        $staffRate = 0.00; // Staff payout is configured on Staff Workforce menu
        $effectiveFrom = $_POST['effective_from'] ?? date('Y-m-d');
        $effectiveTo = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (empty($clientId) || $clientRate <= 0) {
            $error = 'Client and valid Client Billing Rate are required.';
        } else {
            if ($rateId) {
                $stmt = $db->prepare("
                    UPDATE rate_cards SET client_id=:cid, category_id=:catid, site_id=:sid, shift_id=:shid, staff_id=:stfid,
                        client_billing_rate=:cbr, staff_payout_rate=:spr, effective_from=:ef, effective_to=:et, notes=:nt
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':cid' => $clientId, ':catid' => $categoryId, ':sid' => $siteId, ':shid' => $shiftId, ':stfid' => $staffId,
                    ':cbr' => $clientRate, ':spr' => $staffRate, ':ef' => $effectiveFrom, ':et' => $effectiveTo,
                    ':nt' => $notes, ':id' => $rateId
                ]);
                Audit::log('UPDATE', 'RATE_CARDS', $rateId, null, $_POST);
                Helper::setFlash('success', 'Rate card updated successfully.');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO rate_cards (client_id, category_id, site_id, shift_id, staff_id, client_billing_rate,
                                            staff_payout_rate, effective_from, effective_to, notes, created_by)
                    VALUES (:cid, :catid, :sid, :shid, :stfid, :cbr, :spr, :ef, :et, :nt, :uid)
                ");
                $stmt->execute([
                    ':cid' => $clientId, ':catid' => $categoryId, ':sid' => $siteId, ':shid' => $shiftId, ':stfid' => $staffId,
                    ':cbr' => $clientRate, ':spr' => $staffRate, ':ef' => $effectiveFrom, ':et' => $effectiveTo,
                    ':nt' => $notes, ':uid' => Auth::id()
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'RATE_CARDS', $newId, null, $_POST);
                Helper::setFlash('success', 'New rate card override added successfully.');
            }
            Helper::redirect('modules/rates/index.php');
        }
    } elseif ($action === 'delete_rate') {
        $rateId = (int)($_POST['rate_id'] ?? 0);
        if ($rateId) {
            $stmt = $db->prepare("DELETE FROM rate_cards WHERE id = :id");
            $stmt->execute([':id' => $rateId]);
            Audit::log('DELETE', 'RATE_CARDS', $rateId, null, null);
            Helper::setFlash('info', 'Rate card override deleted.');
            Helper::redirect('modules/rates/index.php');
        }
    }
}

// Fetch Rate Cards
$query = "
    SELECT rc.*, c.company_name, s.site_name, sh.shift_name, sh.shift_code, stf.full_name as staff_name,
           sc.category_name, sc.category_code
    FROM rate_cards rc
    JOIN clients c ON rc.client_id = c.id
    LEFT JOIN sites s ON rc.site_id = s.id
    LEFT JOIN shifts sh ON rc.shift_id = sh.id
    LEFT JOIN staff stf ON rc.staff_id = stf.id
    LEFT JOIN staff_categories sc ON rc.category_id = sc.id
    ORDER BY rc.id DESC
";
$rateCards = $db->query($query)->fetchAll();

// Dropdowns
$clients = $db->query("SELECT id, company_name FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$categories = $db->query("SELECT id, category_code, category_name FROM staff_categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll();
$sites = $db->query("SELECT id, site_name, client_id FROM sites WHERE status = 'active' ORDER BY site_name ASC")->fetchAll();
$shifts = $db->query("SELECT id, shift_name, shift_code FROM shifts WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$staff = $db->query("SELECT id, full_name, staff_code FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Rate Cards Management';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('directory', 'rates') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-tags-fill text-primary me-2"></i>Rate Cards & Pricing Matrix</h4>
        <p class="text-muted small mb-0">Configure client billing and staff payout overrides by Client, Site, Shift, or specific Staff member.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openRateModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Custom Rate Rule
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Important Business Rule Alert -->
<div class="alert alert-success border-success-subtle bg-success-subtle text-success-emphasis d-flex align-items-start gap-2 mb-4 shadow-sm">
    <i class="bi bi-shield-lock-fill fs-5 mt-0.5"></i>
    <div>
        <strong>Historical Rate Protection Guarantee:</strong>
        When any daily staff deployment is created, the applicable client rate and staff payout rate are immediately frozen and snapshotted into the deployment record. Future modifications to rate cards will <em>never</em> alter past attendance earnings, invoices, or financial accounts.
    </div>
</div>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th>Client</th>
                    <th>Staff Category</th>
                    <th>Site Target</th>
                    <th>Shift Target</th>
                    <th>Staff Target</th>
                    <th class="text-end">Client Billing Rate</th>
                    <th>Effective Period</th>
                    <th class="text-end" style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rateCards)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No custom rate overrides configured. System will use default client & staff rates.</td></tr>
                <?php else: ?>
                    <?php 
                        $rowNum = 1;
                        $totBilledRate = 0;
                        foreach ($rateCards as $rc): 
                            $totBilledRate += (float)$rc['client_billing_rate'];
                    ?>
                        <tr>
                            <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($rc['company_name']) ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($rc['category_name'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold py-0.5 px-1.5" style="font-size: 0.70rem;">
                                        <?= htmlspecialchars($rc['category_name']) ?>
                                    </span>
                                    <div class="text-muted font-monospace" style="font-size: 0.68rem;"><?= htmlspecialchars($rc['category_code']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">All Categories</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $rc['site_name'] ? '<span class="badge bg-light text-dark border py-0.5 px-1.5" style="font-size: 0.70rem;">' . htmlspecialchars($rc['site_name']) . '</span>' : '<span class="text-muted small">All Sites</span>' ?>
                            </td>
                            <td>
                                <?= $rc['shift_name'] ? Helper::shiftBadge($rc['shift_name'], $rc['shift_code'] ?? '') : '<span class="text-muted small">All Shifts</span>' ?>
                            </td>
                            <td>
                                <?= $rc['staff_name'] ? '<span class="badge bg-primary-subtle text-primary py-0.5 px-1.5" style="font-size: 0.70rem;">' . htmlspecialchars($rc['staff_name']) . '</span>' : '<span class="text-muted small">All Staff</span>' ?>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="text-dark"><?= Helper::formatCurrency($rc['client_billing_rate']) ?></strong>
                            </td>
                            <td>
                                <div style="font-size: 0.72rem;">From: <strong><?= Helper::formatDate($rc['effective_from']) ?></strong></div>
                                <div class="text-muted" style="font-size: 0.68rem;">To: <?= $rc['effective_to'] ? Helper::formatDate($rc['effective_to']) : 'Ongoing' ?></div>
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <button type="button" class="btn btn-xs btn-outline-primary px-1.5 py-0.5" onclick='editRate(<?= json_encode($rc) ?>)' title="Edit Rate Rule">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="" class="d-inline" data-confirm="Are you sure you want to delete this rate override rule?" data-confirm-title="Delete Rate Override" data-confirm-btn="btn-danger">
                                        <?= CSRF::tokenField() ?>
                                        <input type="hidden" name="action" value="delete_rate">
                                        <input type="hidden" name="rate_id" value="<?= $rc['id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger px-1.5 py-0.5" title="Delete Rule">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($rateCards)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td colspan="5" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                        Pricing Rules Total: <strong><?= count($rateCards) ?></strong> Active Custom Overrides
                    </td>
                    <td class="text-end font-monospace text-dark" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totBilledRate / count($rateCards)) ?> <small class="text-muted" style="font-size:0.65rem;">(avg)</small>
                    </td>
                    <td colspan="2" class="text-muted small text-end">Average Client Billing Rate</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Add / Edit Rate Card Modal -->
<div class="modal fade" id="rateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_rate">
                <input type="hidden" name="rate_id" id="modal_rate_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="rateModalTitle">Add Rate Override Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Client *</label>
                            <select name="client_id" id="modal_rate_cid" class="form-select" required>
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clients as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Staff Category (Contracted Role)</label>
                            <select name="category_id" id="modal_rate_cat_id" class="form-select" onchange="onRateCategoryChange()">
                                <option value="">-- Apply to All Categories --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= htmlspecialchars($cat['category_name']) ?> (<?= $cat['category_code'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:0.72rem;">Set agreed price for this staff role at the client</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Site (Optional Specific Venue)</label>
                            <select name="site_id" id="modal_rate_sid" class="form-select">
                                <option value="">-- Apply to All Sites of Client --</option>
                                <?php foreach ($sites as $st): ?>
                                    <option value="<?= $st['id'] ?>" data-client="<?= $st['client_id'] ?>"><?= htmlspecialchars($st['site_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shift (Optional Specific Shift)</label>
                            <select name="shift_id" id="modal_rate_shift_id" class="form-select">
                                <option value="">-- Apply to All Shifts --</option>
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?= $sh['id'] ?>"><?= htmlspecialchars($sh['shift_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Staff (Optional Specific Worker)</label>
                            <select name="staff_id" id="modal_rate_staff_id" class="form-select">
                                <option value="">-- Apply to All Staff --</option>
                                <?php foreach ($staff as $stf): ?>
                                    <option value="<?= $stf['id'] ?>"><?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Client Billing Rate (₹ / Shift) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="10" name="client_billing_rate" id="modal_rate_client" class="form-control" placeholder="e.g. 950" required>
                            </div>
                            <small class="text-muted" style="font-size:0.72rem;">Staff payout is configured on the Staff Workforce menu.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Effective From Date *</label>
                            <input type="date" name="effective_from" id="modal_rate_from" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Effective To Date (Optional)</label>
                            <input type="date" name="effective_to" id="modal_rate_to" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Notes / Purpose</label>
                            <input type="text" name="notes" id="modal_rate_notes" class="form-control" placeholder="e.g. Contract category agreed rate">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Rate Rule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const rateModal = new bootstrap.Modal(document.getElementById('rateModal'));

function onRateCategoryChange() {
    // Category selected for client pricing rule
}

function openRateModal() {
    document.getElementById('modal_rate_id').value = '';
    document.getElementById('modal_rate_cid').value = '';
    document.getElementById('modal_rate_cat_id').value = '';
    document.getElementById('modal_rate_sid').value = '';
    document.getElementById('modal_rate_shift_id').value = '';
    document.getElementById('modal_rate_staff_id').value = '';
    document.getElementById('modal_rate_client').value = '900';
    document.getElementById('modal_rate_client').dataset.isDefault = '1';
    document.getElementById('modal_rate_from').value = new Date().toISOString().split('T')[0];
    document.getElementById('modal_rate_to').value = '';
    document.getElementById('modal_rate_notes').value = '';
    document.getElementById('rateModalTitle').textContent = 'Add Rate Override Rule';
    rateModal.show();
}

function editRate(r) {
    document.getElementById('modal_rate_id').value = r.id;
    document.getElementById('modal_rate_cid').value = r.client_id;
    document.getElementById('modal_rate_cat_id').value = r.category_id || '';
    document.getElementById('modal_rate_sid').value = r.site_id || '';
    document.getElementById('modal_rate_shift_id').value = r.shift_id || '';
    document.getElementById('modal_rate_staff_id').value = r.staff_id || '';
    document.getElementById('modal_rate_client').value = r.client_billing_rate;
    document.getElementById('modal_rate_client').dataset.isDefault = '0';
    document.getElementById('modal_rate_from').value = r.effective_from;
    document.getElementById('modal_rate_to').value = r.effective_to || '';
    document.getElementById('modal_rate_notes').value = r.notes || '';
    document.getElementById('rateModalTitle').textContent = 'Edit Rate Override Rule';
    rateModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
