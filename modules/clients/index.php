<?php
// modules/clients/index.php - Client Management Directory
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$error = '';

// Handle Client Create / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_client') {
        Auth::requireRole(['owner_admin', 'manager']);
        $delClientId = (int)($_POST['client_id'] ?? 0);
        $cliStmt = $db->prepare("SELECT * FROM clients WHERE id = :id");
        $cliStmt->execute([':id' => $delClientId]);
        $cli = $cliStmt->fetch();

        if (!$cli) {
            Helper::setFlash('danger', 'Client not found.');
        } else {
            // Check if there are generated invoices or recorded payments
            $invCount = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE client_id = $delClientId")->fetchColumn();
            $payCount = (int)$db->query("SELECT COUNT(*) FROM client_payments WHERE client_id = $delClientId")->fetchColumn();
            if ($invCount > 0 || $payCount > 0) {
                Helper::setFlash('danger', "Cannot delete client '{$cli['company_name']}': Generated invoices or payment records exist. Please set their status to Inactive instead.");
            } else {
                try {
                    $db->beginTransaction();
                    $db->prepare("DELETE FROM attendance WHERE client_id = :id")->execute([':id' => $delClientId]);
                    $db->prepare("DELETE FROM deployments WHERE client_id = :id")->execute([':id' => $delClientId]);
                    $db->prepare("DELETE FROM rate_cards WHERE client_id = :id")->execute([':id' => $delClientId]);
                    $db->prepare("DELETE FROM sites WHERE client_id = :id")->execute([':id' => $delClientId]);
                    $db->prepare("DELETE FROM clients WHERE id = :id")->execute([':id' => $delClientId]);
                    $db->commit();

                    Audit::log('DELETE', 'CLIENTS', $delClientId, $cli, null);
                    Helper::setFlash('success', "Client '{$cli['company_name']}' and associated site profiles have been permanently deleted.");
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    Helper::setFlash('danger', "Failed to delete client: " . $e->getMessage());
                }
            }
        }
        Helper::redirect('modules/clients/index.php');
    }

    if ($action === 'save_client') {
        $clientId = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
        $clientCode = strtoupper(trim($_POST['client_code'] ?? ''));
        $companyName = trim($_POST['company_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gstNumber = strtoupper(trim($_POST['gst_number'] ?? ''));
        $panNumber = strtoupper(trim($_POST['pan_number'] ?? ''));
        $billingAddress = trim($_POST['billing_address'] ?? '');
        $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
        $paymentTermsDays = (int)($_POST['payment_terms_days'] ?? 15);
        $defaultBillingRate = (float)($_POST['default_billing_rate'] ?? 800.00);
        $status = $_POST['status'] ?? 'active';

        if (empty($companyName) || empty($contactPerson) || empty($phone)) {
            $error = 'Company Name, Contact Person, and Phone are required.';
        } else {
            if (empty($clientCode)) {
                $clientCode = 'CLI-' . rand(100, 999);
            }

            if ($clientId) {
                $stmt = $db->prepare("
                    UPDATE clients SET company_name=:cn, contact_person=:cp, phone=:ph, email=:em,
                        gst_number=:gst, pan_number=:pan, billing_address=:ba, billing_cycle=:bc,
                        payment_terms_days=:ptd, default_billing_rate=:dbr, status=:st
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':cn' => $companyName, ':cp' => $contactPerson, ':ph' => $phone, ':em' => $email,
                    ':gst' => $gstNumber, ':pan' => $panNumber, ':ba' => $billingAddress, ':bc' => $billingCycle,
                    ':ptd' => $paymentTermsDays, ':dbr' => $defaultBillingRate, ':st' => $status, ':id' => $clientId
                ]);
                Audit::log('UPDATE', 'CLIENTS', $clientId, null, $_POST);
                Helper::setFlash('success', "Client '{$companyName}' updated successfully.");
            } else {
                $stmt = $db->prepare("
                    INSERT INTO clients (client_code, company_name, contact_person, phone, email, gst_number,
                                         pan_number, billing_address, billing_cycle, payment_terms_days,
                                         default_billing_rate, status)
                    VALUES (:code, :cn, :cp, :ph, :em, :gst, :pan, :ba, :bc, :ptd, :dbr, :st)
                ");
                $stmt->execute([
                    ':code' => $clientCode, ':cn' => $companyName, ':cp' => $contactPerson, ':ph' => $phone,
                    ':em' => $email, ':gst' => $gstNumber, ':pan' => $panNumber, ':ba' => $billingAddress,
                    ':bc' => $billingCycle, ':ptd' => $paymentTermsDays, ':dbr' => $defaultBillingRate, ':st' => $status
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'CLIENTS', $newId, null, $_POST);
                Helper::setFlash('success', "Client '{$companyName}' registered successfully.");
            }
            Helper::redirect('modules/clients/index.php');
        }
    }
}

// Fetch clients with site count and billing metrics
$query = "
    SELECT c.*,
        (SELECT COUNT(s.id) FROM sites s WHERE s.client_id = c.id AND s.status = 'active') as active_sites,
        (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE i.client_id = c.id AND i.payment_status != 'cancelled') as total_invoiced,
        (SELECT COALESCE(SUM(cp.amount), 0) FROM client_payments cp WHERE cp.client_id = c.id) as total_collected
    FROM clients c
    ORDER BY c.id DESC
";
$clients = $db->query($query)->fetchAll();

// Compute client directory totals
$totalInvoicedAll = 0;
$totalCollectedAll = 0;
$totalReceivableAll = 0;
$totalSitesAll = 0;
foreach ($clients as $c) {
    $totalInvoicedAll += (float)$c['total_invoiced'];
    $totalCollectedAll += (float)$c['total_collected'];
    $totalReceivableAll += ((float)$c['total_invoiced'] - (float)$c['total_collected']);
    $totalSitesAll += (int)$c['active_sites'];
}

$pageTitle = 'Client Management';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('directory', 'clients') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-building text-primary me-2"></i>Clients Directory</h4>
        <p class="text-muted small mb-0">Manage corporate hospitality clients, billing cycles, GST credentials, and outstanding balances.</p>
    </div>
    <div class="d-flex gap-2">
        <div class="input-group input-group-sm" style="width: 250px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control border-start-0" placeholder="Search clients..." data-table-search="#clientsTable">
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openClientModal()">
            <i class="bi bi-plus-lg me-1"></i> Add New Client
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="clientsTable">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th>Client Code & Company</th>
                    <th>Contact Person</th>
                    <th>Billing Terms</th>
                    <th class="text-end">Default Shift Rate</th>
                    <th class="text-center">Active Sites</th>
                    <th class="text-end">Total Invoiced</th>
                    <th class="text-end">Collected</th>
                    <th class="text-end">Outstanding Receivable</th>
                    <th class="text-center">Status</th>
                    <th class="text-end" style="width: 95px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="11" class="text-center py-4 text-muted">No clients found. Add your first client above.</td></tr>
                <?php else: ?>
                    <?php $rowNum = 1; foreach ($clients as $c): 
                        $receivable = (float)$c['total_invoiced'] - (float)$c['total_collected'];
                    ?>
                        <tr>
                            <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($c['company_name']) ?></div>
                                <div class="text-muted" style="font-size:0.70rem;">Code: <?= htmlspecialchars($c['client_code']) ?> &bull; GST: <?= htmlspecialchars($c['gst_number'] ?: 'N/A') ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($c['contact_person']) ?></div>
                                <div class="text-muted" style="font-size:0.70rem;"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($c['phone']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border text-capitalize py-0.5 px-1.5" style="font-size:0.70rem;"><?= $c['billing_cycle'] ?></span>
                                <div class="text-muted" style="font-size:0.68rem;"><?= $c['payment_terms_days'] ?> Days Net</div>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="text-dark"><?= Helper::formatCurrency($c['default_billing_rate']) ?></strong>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/modules/sites/index.php?client_id=<?= $c['id'] ?>" class="badge bg-info-subtle text-info-emphasis text-decoration-none py-0.5 px-2" style="font-size:0.72rem;">
                                    <?= $c['active_sites'] ?> Sites
                                </a>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="text-dark"><?= Helper::formatCurrency($c['total_invoiced']) ?></strong>
                            </td>
                            <td class="text-end font-monospace">
                                <span class="text-success"><?= Helper::formatCurrency($c['total_collected']) ?></span>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="<?= $receivable > 0 ? 'text-danger' : 'text-secondary' ?>">
                                    <?= Helper::formatCurrency($receivable) ?>
                                </strong>
                            </td>
                            <td class="text-center"><?= Helper::statusBadge($c['status']) ?></td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-xs btn-outline-secondary dropdown-toggle px-2 py-0.5 shadow-2xs" type="button" data-bs-toggle="dropdown" style="font-size: 0.72rem;">
                                        Options
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>"><i class="bi bi-file-text me-2"></i> Client Ledger & Invoices</a></li>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/sites/index.php?client_id=<?= $c['id'] ?>"><i class="bi bi-geo-alt me-2"></i> Manage Sites</a></li>
                                        <li><button class="dropdown-item" onclick='editClient(<?= json_encode($c) ?>)'><i class="bi bi-pencil me-2"></i> Edit Details</button></li>
                                        <?php if (Auth::isAccountant()): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-primary" href="<?= BASE_URL ?>/modules/billing/generate.php?client_id=<?= $c['id'] ?>"><i class="bi bi-receipt me-2"></i> Generate Invoice</a></li>
                                            <li><a class="dropdown-item text-success" href="<?= BASE_URL ?>/modules/payments/index.php?client_id=<?= $c['id'] ?>"><i class="bi bi-cash-stack me-2"></i> Record Payment</a></li>
                                        <?php endif; ?>
                                        <?php if (Auth::hasRole(['owner_admin', 'manager'])): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button type="button" class="dropdown-item text-danger" onclick="confirmDeleteClient(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['company_name']), ENT_QUOTES) ?>')"><i class="bi bi-trash me-2"></i> Delete Client</button></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($clients)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td colspan="4" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                        Client Summary: <strong><?= count($clients) ?></strong> Corporate Accounts &bull; <strong><?= $totalSitesAll ?></strong> Active Sites
                    </td>
                    <td class="text-center font-monospace" style="font-size: 0.80rem; background-color: #f1f5f9;">
                        <?= $totalSitesAll ?>
                    </td>
                    <td class="text-end font-monospace text-dark" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totalInvoicedAll) ?>
                    </td>
                    <td class="text-end font-monospace text-success" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totalCollectedAll) ?>
                    </td>
                    <td class="text-end font-monospace <?= $totalReceivableAll > 0 ? 'text-danger' : 'text-secondary' ?>" style="font-size: 0.82rem; background-color: #fef2f2;">
                        <?= Helper::formatCurrency($totalReceivableAll) ?>
                    </td>
                    <td colspan="2" class="text-muted small text-end">Total Outstanding</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Add / Edit Client Modal -->
<div class="modal fade" id="clientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_client">
                <input type="hidden" name="client_id" id="modal_client_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="clientModalTitle">Add New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Client Code</label>
                            <input type="text" name="client_code" id="modal_cli_code" class="form-control" placeholder="CLI-001">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Company / Client Name *</label>
                            <input type="text" name="company_name" id="modal_cli_company" class="form-control" placeholder="e.g. Grand Hyatt Banquets" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Contact Person *</label>
                            <input type="text" name="contact_person" id="modal_cli_contact" class="form-control" placeholder="Manager Name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Phone Number *</label>
                            <input type="text" name="phone" id="modal_cli_phone" class="form-control" placeholder="10-digit mobile" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" name="email" id="modal_cli_email" class="form-control" placeholder="accounts@client.com">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">GST Identification Number (GSTIN)</label>
                            <input type="text" name="gst_number" id="modal_cli_gst" class="form-control" placeholder="e.g. 07AAAAA0000A1Z5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Permanent Account Number (PAN)</label>
                            <input type="text" name="pan_number" id="modal_cli_pan" class="form-control" placeholder="ABCDE1234F">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Billing Cycle</label>
                            <select name="billing_cycle" id="modal_cli_cycle" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="fortnightly">Fortnightly (15 days)</option>
                                <option value="weekly">Weekly</option>
                                <option value="on_demand">On-Demand / Event-wise</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Payment Terms (Days)</label>
                            <input type="number" name="payment_terms_days" id="modal_cli_terms" class="form-control" value="15">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Default Billing Rate (₹ / Shift)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="10" name="default_billing_rate" id="modal_cli_rate" class="form-control" value="850" required>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Registered Billing Address</label>
                            <textarea name="billing_address" id="modal_cli_address" class="form-control" rows="2" placeholder="Full legal billing address for invoices..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="modal_cli_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Client
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const clientModal = new bootstrap.Modal(document.getElementById('clientModal'));

function openClientModal() {
    document.getElementById('modal_client_id').value = '';
    document.getElementById('modal_cli_code').value = 'CLI-' + Math.floor(Math.random() * 900 + 100);
    document.getElementById('modal_cli_company').value = '';
    document.getElementById('modal_cli_contact').value = '';
    document.getElementById('modal_cli_phone').value = '';
    document.getElementById('modal_cli_email').value = '';
    document.getElementById('modal_cli_gst').value = '';
    document.getElementById('modal_cli_pan').value = '';
    document.getElementById('modal_cli_cycle').value = 'monthly';
    document.getElementById('modal_cli_terms').value = '15';
    document.getElementById('modal_cli_rate').value = '850';
    document.getElementById('modal_cli_address').value = '';
    document.getElementById('modal_cli_status').value = 'active';
    document.getElementById('clientModalTitle').textContent = 'Add New Client';
    clientModal.show();
}

function editClient(c) {
    document.getElementById('modal_client_id').value = c.id;
    document.getElementById('modal_cli_code').value = c.client_code;
    document.getElementById('modal_cli_company').value = c.company_name;
    document.getElementById('modal_cli_contact').value = c.contact_person;
    document.getElementById('modal_cli_phone').value = c.phone;
    document.getElementById('modal_cli_email').value = c.email || '';
    document.getElementById('modal_cli_gst').value = c.gst_number || '';
    document.getElementById('modal_cli_pan').value = c.pan_number || '';
    document.getElementById('modal_cli_cycle').value = c.billing_cycle;
    document.getElementById('modal_cli_terms').value = c.payment_terms_days;
    document.getElementById('modal_cli_rate').value = c.default_billing_rate;
    document.getElementById('modal_cli_address').value = c.billing_address || '';
    document.getElementById('modal_cli_status').value = c.status;
    document.getElementById('clientModalTitle').textContent = 'Edit Client: ' + c.company_name;
    clientModal.show();
}

const deleteClientModal = new bootstrap.Modal(document.getElementById('deleteClientModal'));
function confirmDeleteClient(id, name) {
    document.getElementById('delete_client_id').value = id;
    document.getElementById('delete_client_name').textContent = name;
    deleteClientModal.show();
}
</script>

<!-- Delete Client Confirmation Modal -->
<div class="modal fade" id="deleteClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Client</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" name="client_id" id="delete_client_id">
                <div class="modal-body text-center py-3">
                    <p class="mb-2">Are you sure you want to permanently delete <strong id="delete_client_name" class="text-danger"></strong>?</p>
                    <p class="text-muted small mb-0">This will remove this client profile and associated sites.</p>
                </div>
                <div class="modal-footer bg-light py-2 justify-content-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger px-3"><i class="bi bi-trash me-1"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
