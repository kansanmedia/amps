<?php
// modules/settings/company.php - Reorganized Business Profile, Admin Bank, Cash & Invoicing Settings
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin']);

$db = DB::getConnection();
$error = '';
$success = '';

// Fetch Cash Account
$cashStmt = $db->query("SELECT * FROM financial_accounts WHERE account_type = 'cash' ORDER BY id ASC LIMIT 1");
$pettyCash = $cashStmt->fetch();

// Fetch All Financial Accounts
$allAccounts = $db->query("SELECT * FROM financial_accounts ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? 'update_settings';

    // 1. Quick Toggle Cash Status
    if ($action === 'toggle_petty_cash') {
        if ($pettyCash) {
            $newStatus = ($pettyCash['status'] === 'active') ? 'inactive' : 'active';
            $db->prepare("UPDATE financial_accounts SET status = :st WHERE id = :id")->execute([
                ':st' => $newStatus,
                ':id' => $pettyCash['id']
            ]);
            Audit::log('STATUS_CHANGE', 'FINANCIAL_ACCOUNTS', $pettyCash['id'], ['old' => $pettyCash['status']], ['new' => $newStatus]);
            $msg = ($newStatus === 'active') 
                ? 'Cash has been enabled and is now active.' 
                : 'Cash has been disabled / deactivated. It is now hidden from all payment and expense forms.';
            Helper::setFlash('success', $msg);
        } else {
            Helper::setFlash('error', 'Cash account not found in database.');
        }
        Helper::redirect('modules/settings/company.php#tab-pettycash');
    }

    // 2. Update Settings
    if ($action === 'update_settings') {
        $activeTab = $_POST['active_tab'] ?? 'profile';
        $companyName = trim($_POST['company_name'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gst = strtoupper(trim($_POST['gst_number'] ?? ''));
        $pan = strtoupper(trim($_POST['pan_number'] ?? ''));
        $currency = trim($_POST['currency_symbol'] ?? '₹');
        $invoicePrefix = strtoupper(trim($_POST['invoice_prefix'] ?? 'INV-'));
        $terms = trim($_POST['terms_conditions'] ?? '');

        // Admin Bank Account & Official UPI
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountHolder = trim($_POST['account_holder'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $ifscCode = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $branchName = trim($_POST['branch_name'] ?? '');
        $upiId = trim($_POST['upi_id'] ?? '');
        $enableTaxByDefault = isset($_POST['enable_tax_by_default']) ? 1 : 0;

        // Handle Signature Upload / Removal
        $currentComp = $db->query("SELECT signature_url FROM company_settings WHERE id = 1")->fetch();
        $signatureUrl = $currentComp['signature_url'] ?? null;

        if (isset($_POST['remove_signature']) && $_POST['remove_signature'] == '1') {
            if (!empty($signatureUrl) && file_exists(ROOT_PATH . '/' . $signatureUrl)) {
                @unlink(ROOT_PATH . '/' . $signatureUrl);
            }
            $signatureUrl = null;
        } elseif (!empty($_FILES['signature_image']['name'])) {
            // Security Hardening: Checkpoint 4 - Strict File Upload Validation
            $valResult = Security::validateUploadedFile(
                $_FILES['signature_image'],
                ['image/png', 'image/jpeg', 'image/webp'],
                2 * 1024 * 1024 // 2MB max
            );

            if (!$valResult['valid']) {
                $error = 'Security Upload Error: ' . $valResult['error'];
            } else {
                $fileInfo = pathinfo($_FILES['signature_image']['name']);
                $ext = strtolower($fileInfo['extension'] ?? 'png');
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                    $ext = ($valResult['mime'] === 'image/jpeg') ? 'jpg' : (($valResult['mime'] === 'image/webp') ? 'webp' : 'png');
                }

                $uploadDir = ROOT_PATH . '/uploads/company';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'signature_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $targetPath = $uploadDir . '/' . $filename;

                if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $targetPath)) {
                    if (!empty($signatureUrl) && file_exists(ROOT_PATH . '/' . $signatureUrl)) {
                        @unlink(ROOT_PATH . '/' . $signatureUrl);
                    }
                    $signatureUrl = 'uploads/company/' . $filename;
                } else {
                    $error = 'Failed to safely move uploaded signature file.';
                }
            }
        }

        if (empty($companyName) || empty($phone)) {
            $error = 'Company name and primary phone number are required.';
        } else {
            $stmt = $db->prepare("
                UPDATE company_settings 
                SET company_name = :cn, tagline = :tag, address = :addr, phone = :ph,
                    email = :em, gst_number = :gst, pan_number = :pan, currency_symbol = :curr,
                    invoice_prefix = :pref, terms_conditions = :terms,
                    bank_name = :bname, account_holder = :aholder, account_number = :ano,
                    ifsc_code = :ifsc, branch_name = :brname, upi_id = :upi,
                    enable_tax_by_default = :etax,
                    signature_url = :sig
                WHERE id = 1
            ");
            $stmt->execute([
                ':cn'   => $companyName, ':tag' => $tagline, ':addr' => $address, ':ph' => $phone,
                ':em'   => $email, ':gst' => $gst, ':pan' => $pan, ':curr' => $currency,
                ':pref' => $invoicePrefix, ':terms' => $terms,
                ':bname' => $bankName, ':aholder' => $accountHolder, ':ano' => $accountNumber,
                ':ifsc' => $ifscCode, ':brname' => $branchName, ':upi' => $upiId,
                ':etax' => $enableTaxByDefault,
                ':sig'  => $signatureUrl
            ]);

            // Sync with primary bank in financial_accounts if present
            if (!empty($bankName) && !empty($accountNumber)) {
                $checkBank = $db->query("SELECT id FROM financial_accounts WHERE account_type = 'bank' ORDER BY id ASC LIMIT 1")->fetch();
                if ($checkBank) {
                    $db->prepare("
                        UPDATE financial_accounts 
                        SET bank_name = :bname, account_number = :ano, ifsc_code = :ifsc,
                            account_name = :aname
                        WHERE id = :id
                    ")->execute([
                        ':bname' => $bankName,
                        ':ano' => $accountNumber,
                        ':ifsc' => $ifscCode,
                        ':aname' => $bankName . ' Primary A/C',
                        ':id' => $checkBank['id']
                    ]);
                }
            }

            // Sync with primary UPI in financial_accounts if present
            if (!empty($upiId)) {
                $checkUpi = $db->query("SELECT id FROM financial_accounts WHERE account_type = 'upi' ORDER BY id ASC LIMIT 1")->fetch();
                if ($checkUpi) {
                    $db->prepare("
                        UPDATE financial_accounts 
                        SET account_number = :upi
                        WHERE id = :id
                    ")->execute([
                        ':upi' => $upiId,
                        ':id' => $checkUpi['id']
                    ]);
                }
            }

            Audit::log('UPDATE', 'SETTINGS', 1, null, $_POST);
            Helper::setFlash('success', 'Settings updated successfully.');
            Helper::redirect('modules/settings/company.php#tab-' . $activeTab);
        }
    }
}

$compStmt = $db->query("SELECT * FROM company_settings LIMIT 1");
$company = $compStmt->fetch();

$pageTitle = 'Company Settings & System Configuration';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<!-- Header Greeting & Overview -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-sliders2 text-primary me-2"></i>Company Settings & Operations Hub</h4>
        <p class="text-muted small mb-0">Reorganized settings panel: Manage business profile, tax identifiers, admin bank account, and cash.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/accounting/accounts.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-journal-bookmark-fill me-1"></i> Open Financial Ledgers
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- REORGANIZED SETTINGS STRUCTURE: 2-COLUMN TABBED WORKSPACE -->
<div class="row g-4">
    <!-- Left Column: Modern Category Navigation Pills -->
    <div class="col-lg-3 col-md-4">
        <div class="custom-card settings-nav-card p-3 shadow-sm">
            <div class="text-muted small text-uppercase fw-bold mb-2 px-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                Settings Modules
            </div>

            <div class="nav flex-column nav-pills" id="settingsTabs" role="tablist">
                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-profile" type="button" role="tab">
                    <div class="nav-icon-box text-primary">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-bold tab-title text-dark">Business Profile</div>
                        <small class="tab-subtitle text-muted d-block text-truncate" style="font-size: 0.72rem;">Name, phone, address, tax IDs</small>
                    </div>
                </button>

                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-bank" type="button" role="tab">
                    <div class="nav-icon-box text-success">
                        <i class="bi bi-bank"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-bold tab-title text-dark">Admin Bank & UPI</div>
                        <small class="tab-subtitle text-muted d-block text-truncate" style="font-size: 0.72rem;">Payment target on invoices</small>
                    </div>
                </button>

                <?php if ($pettyCash): ?>
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-pettycash" type="button" role="tab">
                    <div class="nav-icon-box text-warning">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-bold tab-title text-dark">Cash</div>
                        <small class="tab-subtitle text-muted d-block text-truncate" style="font-size: 0.72rem;">
                            <?= ($pettyCash['status'] === 'active') ? 'Active (₹' . number_format((float)$pettyCash['current_balance']) . ')' : 'Disabled' ?>
                        </small>
                    </div>
                </button>
                <?php endif; ?>

                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-invoicing" type="button" role="tab">
                    <div class="nav-icon-box text-info">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-bold tab-title text-dark">Invoice & Terms</div>
                        <small class="tab-subtitle text-muted d-block text-truncate" style="font-size: 0.72rem;">Number prefix & legal terms</small>
                    </div>
                </button>

                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-accounts" type="button" role="tab">
                    <div class="nav-icon-box text-secondary">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-bold tab-title text-dark">Financial Accounts</div>
                        <small class="tab-subtitle text-muted d-block text-truncate" style="font-size: 0.72rem;">Overview of liquid balances</small>
                    </div>
                </button>
            </div>

            <!-- Quick Status Box at bottom of left nav -->
            <div class="mt-4 pt-3 border-top px-2">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small">Cash:</span>
                    <?php if ($pettyCash && $pettyCash['status'] === 'active'): ?>
                        <span class="badge bg-success-subtle text-success">Active</span>
                    <?php elseif ($pettyCash): ?>
                        <span class="badge bg-danger-subtle text-danger">Disabled</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary">Removed</span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small">Bank Linked:</span>
                    <span class="text-dark fw-bold small text-truncate" style="max-width: 110px;"><?= htmlspecialchars($company['bank_name'] ?? 'Not set') ?></span>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small">Currency:</span>
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($company['currency_symbol'] ?? '₹') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Tabbed Content Panes -->
    <div class="col-lg-9 col-md-8">
        <form method="POST" action="" id="mainSettingsForm" enctype="multipart/form-data">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="action" value="update_settings">
            <input type="hidden" name="active_tab" id="activeTabInput" value="profile">

            <!-- Shared Bank & Profile Hidden Inputs to ensure whole form can save from any tab -->
            <div class="tab-content" id="settingsTabContent">
                
                <!-- TAB 1: BUSINESS PROFILE & TAX IDENTIFIERS -->
                <div class="tab-pane fade show active" id="tab-profile" role="tabpanel">
                    <div class="custom-card p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-building text-primary me-2"></i>Business Profile & Legal Information</h5>
                                <small class="text-muted">Company name, official contact details, address, and GST/PAN identifiers</small>
                            </div>
                            <span class="badge bg-light text-dark border">General Settings</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Company Name *</label>
                                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($company['company_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Company Tagline</label>
                                <input type="text" name="tagline" class="form-control" value="<?= htmlspecialchars($company['tagline'] ?? '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Primary Contact Phone *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($company['phone'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Support / Billing Email</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-semibold">Registered Office Address</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="Street, Area, City, State, PIN"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Company GSTIN Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-file-earmark-check"></i></span>
                                    <input type="text" name="gst_number" class="form-control text-uppercase font-monospace" value="<?= htmlspecialchars($company['gst_number'] ?? '') ?>" placeholder="e.g. 27AAAAA0000A1Z5">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Company PAN Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-card-heading"></i></span>
                                    <input type="text" name="pan_number" class="form-control text-uppercase font-monospace" value="<?= htmlspecialchars($company['pan_number'] ?? '') ?>" placeholder="e.g. AAAAA0000A">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold" onclick="document.getElementById('activeTabInput').value='profile'">
                                <i class="bi bi-check2-circle me-1"></i> Save Company Profile
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: ADMIN BANK & OFFICIAL UPI -->
                <div class="tab-pane fade" id="tab-bank" role="tabpanel">
                    <div class="custom-card p-4 shadow-sm border-success border-top border-3">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-bank text-success me-2"></i>Admin Bank Account & Official UPI Details</h5>
                                <small class="text-muted">These banking credentials will be directly printed on client invoices for receiving payments</small>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Invoice Payment Target</span>
                        </div>

                        <div class="alert alert-info py-2.5 px-3 small mb-4 d-flex align-items-center gap-2.5">
                            <i class="bi bi-info-circle-fill fs-4 text-primary flex-shrink-0"></i>
                            <div>
                                <strong>Invoice Synchronization:</strong> Jab bhi aap yahan bank account update karenge, client ke invoices aur bills ke <em>"Bank Transfer & Payment Details"</em> section me yahi details automatically display hongi.
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Bank Name *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-building"></i></span>
                                    <input type="text" name="bank_name" class="form-control" 
                                           placeholder="e.g. HDFC Bank Ltd, ICICI Bank, SBI" 
                                           value="<?= htmlspecialchars($company['bank_name'] ?? 'HDFC Bank Ltd') ?>" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Account Holder / Beneficiary Name *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
                                    <input type="text" name="account_holder" class="form-control" 
                                           placeholder="e.g. Account Holder Name" 
                                           value="<?= htmlspecialchars($company['account_holder'] ?? ($company['company_name'] ?? '')) ?>" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Bank Account Number *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-credit-card-2-front"></i></span>
                                    <input type="text" name="account_number" class="form-control font-monospace fw-bold" 
                                           placeholder="e.g. 50200098765432" 
                                           value="<?= htmlspecialchars($company['account_number'] ?? '') ?>" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Bank IFSC Code *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-upc-scan"></i></span>
                                    <input type="text" name="ifsc_code" class="form-control font-monospace text-uppercase" 
                                           placeholder="e.g. HDFC0001234" 
                                           value="<?= htmlspecialchars($company['ifsc_code'] ?? '') ?>" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Bank Branch / Location</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-geo-alt"></i></span>
                                    <input type="text" name="branch_name" class="form-control" 
                                           placeholder="e.g. Main Branch" 
                                           value="<?= htmlspecialchars($company['branch_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-dark">Official UPI ID / VPA</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-success"><i class="bi bi-phone"></i></span>
                                    <input type="text" name="upi_id" class="form-control text-success fw-bold" 
                                           placeholder="e.g. company@upi" 
                                           value="<?= htmlspecialchars($company['upi_id'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Live Invoice Preview Card -->
                        <div class="mt-4 p-3 bg-light rounded-3 border">
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">
                                <i class="bi bi-eye me-1"></i> Live Invoice Preview (Client Display):
                            </div>
                            <div class="bg-white p-2.5 rounded border small font-monospace">
                                <div><strong>Beneficiary:</strong> <?= htmlspecialchars($company['account_holder'] ?? ($company['company_name'] ?? '')) ?></div>
                                <div><strong>Bank:</strong> <?= htmlspecialchars($company['bank_name'] ?? '-') ?> | <strong>A/C:</strong> <?= htmlspecialchars($company['account_number'] ?? '-') ?></div>
                                <div><strong>IFSC:</strong> <?= htmlspecialchars($company['ifsc_code'] ?? '-') ?> | <strong>UPI:</strong> <?= htmlspecialchars($company['upi_id'] ?? '-') ?></div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-success px-4 fw-semibold" onclick="document.getElementById('activeTabInput').value='bank'">
                                <i class="bi bi-check2-circle me-1"></i> Save Bank & UPI Details
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($pettyCash): ?>
                <!-- TAB 3: CASH & CASH DRAWER CONTROL -->
                <div class="tab-pane fade" id="tab-pettycash" role="tabpanel">
                    <div class="custom-card p-4 shadow-sm border <?= ($pettyCash && $pettyCash['status'] === 'active') ? 'border-success' : 'border-danger' ?>">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-cash-coin text-warning me-2"></i>Cash & Cash Drawer Control</h5>
                                <small class="text-muted">Manage physical cash transactions, daily staff advances, and disable or enable cash payouts</small>
                            </div>
                            <?php if ($pettyCash && $pettyCash['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 fs-6">
                                    <i class="bi bi-check-circle-fill me-1"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1.5 fs-6">
                                    <i class="bi bi-slash-circle-fill me-1"></i> Disabled
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="row g-4 mb-3">
                            <div class="col-md-6">
                                <div class="bg-light p-3 rounded-3 border h-100">
                                    <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Current Physical Cash in Hand Balance</div>
                                    <div class="fs-2 fw-bold text-dark mt-1">
                                        <?= Helper::formatCurrency($pettyCash['current_balance'] ?? 0) ?>
                                    </div>
                                    <div class="text-secondary small mt-1">
                                        Account Code: <code class="text-dark fw-bold"><?= htmlspecialchars($pettyCash['account_code'] ?? 'ACC-CASH-01') ?></code>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded-3 border h-100 d-flex flex-column justify-content-center">
                                    <?php if ($pettyCash && $pettyCash['status'] === 'active'): ?>
                                        <div class="text-success fw-bold small mb-1"><i class="bi bi-check2-circle me-1"></i> Cash Account is Active</div>
                                        <p class="text-muted small mb-0" style="font-size: 0.8rem;">
                                            Daily on-site salary disbursements, staff advances, aur office expense dropdowns me ye account available hai.
                                        </p>
                                    <?php else: ?>
                                        <div class="text-danger fw-bold small mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Cash Account is Disabled</div>
                                        <p class="text-muted small mb-0" style="font-size: 0.8rem;">
                                            Ye account temporary disable kiya gaya hai. Staff salary payouts aur expense forms me ye dropdowns me chup gaya hai.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 1-Click Action Card -->
                        <div class="p-3 rounded-3 border bg-white mt-2">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <strong class="text-dark d-block">Toggle Cash Operations</strong>
                                    <small class="text-muted">Ek click me physical cash accounts ko band ya chalu karein</small>
                                </div>
                                <div>
                                    <button type="submit" form="pettyCashToggleForm" 
                                            class="btn <?= ($pettyCash && $pettyCash['status'] === 'active') ? 'btn-outline-danger' : 'btn-success' ?> px-4 fw-bold">
                                        <?php if ($pettyCash && $pettyCash['status'] === 'active'): ?>
                                            <i class="bi bi-slash-circle me-1"></i> Disable Cash Account
                                        <?php else: ?>
                                            <i class="bi bi-check-circle me-1"></i> Enable / Activate Cash
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="<?= BASE_URL ?>/modules/accounting/accounts.php?account_id=<?= $pettyCash['id'] ?? 1 ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-check me-1"></i> View Cash Ledger Transactions
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TAB 4: INVOICE NUMBERING & TERMS -->
                <div class="tab-pane fade" id="tab-invoicing" role="tabpanel">
                    <div class="custom-card p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text text-primary me-2"></i>Invoice Configuration & Legal Terms</h5>
                                <small class="text-muted">Customize your invoice serial prefixes, currency symbol, and payment terms</small>
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Billing Settings</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Invoice Number Prefix *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-hash"></i></span>
                                    <input type="text" name="invoice_prefix" class="form-control text-uppercase font-monospace fw-bold" 
                                           value="<?= htmlspecialchars($company['invoice_prefix'] ?? 'INV-') ?>" required>
                                </div>
                                <small class="text-muted" style="font-size: 0.72rem;">Example: INV-202609-0001</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Currency Symbol *</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-currency-exchange"></i></span>
                                    <input type="text" name="currency_symbol" class="form-control fw-bold" 
                                           value="<?= htmlspecialchars($company['currency_symbol'] ?? '₹') ?>" required>
                                </div>
                                <small class="text-muted" style="font-size: 0.72rem;">Indian Rupee (₹), USD ($), etc.</small>
                            </div>

                            <div class="col-12">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            <strong class="text-dark d-block"><i class="bi bi-percent text-primary me-1"></i> Default Invoice Tax System (GST)</strong>
                                            <small class="text-muted">Naye invoices banate samay default me 18% GST lagana hai ya Non-GST / Tax-Exempt (0%) rakhna hai.</small>
                                        </div>
                                        <div class="form-check form-switch fs-5 mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" name="enable_tax_by_default" value="1" id="enableTaxByDefault" <?= (!isset($company['enable_tax_by_default']) || (int)$company['enable_tax_by_default'] === 1) ? 'checked' : '' ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="p-3 bg-light rounded-3 border">
                                    <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1.5 mb-1">
                                        <i class="bi bi-pen-fill text-primary"></i> Authorized Signatory & Official Signature Stamp
                                    </label>
                                    <small class="text-muted d-block mb-3">
                                        Invoices aur client bills par "AUTHORIZED SIGNATURE" ke upar yahi signature image automatically print hogi. Transparent PNG signature image recommend ki jati hai.
                                    </small>

                                    <div class="row align-items-center g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Upload Signature Image (PNG / JPG / WEBP)</label>
                                            <input type="file" name="signature_image" class="form-control" accept="image/png, image/jpeg, image/webp" onchange="previewSignature(this)">
                                            <small class="text-muted" style="font-size: 0.70rem;">Recommended size: ~250x80 px (Transparent background)</small>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center gap-3">
                                                <div id="signaturePreviewBox" class="p-2 border rounded bg-white text-center d-flex align-items-center justify-content-center shadow-2xs" style="width: 240px; height: 95px; min-height: 95px;">
                                                    <?php if (!empty($company['signature_url']) && file_exists(ROOT_PATH . '/' . $company['signature_url'])): ?>
                                                        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($company['signature_url']) ?>" id="sigPreviewImg" alt="Signature" style="max-height: 85px; max-width: 220px; object-fit: contain;">
                                                        <span class="text-muted small fst-italic" id="sigPlaceholder" style="display: none;"><i class="bi bi-image me-1"></i> No signature</span>
                                                    <?php else: ?>
                                                        <span class="text-muted small fst-italic" id="sigPlaceholder"><i class="bi bi-image me-1"></i> No signature uploaded</span>
                                                        <img src="" id="sigPreviewImg" alt="Signature" style="max-height: 85px; max-width: 220px; object-fit: contain; display: none;">
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($company['signature_url']) && file_exists(ROOT_PATH . '/' . $company['signature_url'])): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="removeSigCheck">
                                                        <label class="form-check-label text-danger small fw-semibold" for="removeSigCheck">
                                                            <i class="bi bi-trash me-1"></i> Remove Signature
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-semibold">Standard Invoice Terms & Conditions</label>
                                <textarea name="terms_conditions" class="form-control font-monospace small" rows="4" 
                                          placeholder="Enter standard payment terms, interest for delayed payment, dispute jurisdiction, etc."><?= htmlspecialchars($company['terms_conditions'] ?? '') ?></textarea>
                                <small class="text-muted" style="font-size: 0.72rem;">Ye terms client ke invoice ke bottom me print hongi.</small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-bold shadow-sm" onclick="document.getElementById('activeTabInput').value='invoicing'">
                                <i class="bi bi-check2-circle me-1"></i> Save Invoicing Rules
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: ALL FINANCIAL ACCOUNTS OVERVIEW -->
                <div class="tab-pane fade" id="tab-accounts" role="tabpanel">
                    <div class="custom-card p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-wallet2 text-secondary me-2"></i>All Financial Accounts & Liquid Balances</h5>
                                <small class="text-muted">Master view of company bank accounts, cash box, and UPI handles</small>
                            </div>
                            <a href="<?= BASE_URL ?>/modules/accounting/accounts.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-sliders me-1"></i> Full Accounts Manager
                            </a>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Account Name</th>
                                        <th>Code</th>
                                        <th>Type</th>
                                        <th>Bank / Identifier</th>
                                        <th class="text-end">Current Balance</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allAccounts as $acc): 
                                        $typeBadge = match($acc['account_type']) {
                                            'cash' => '<span class="badge bg-warning-subtle text-warning border">Cash</span>',
                                            'bank' => '<span class="badge bg-primary-subtle text-primary border">Bank</span>',
                                            'upi'  => '<span class="badge bg-success-subtle text-success border">UPI</span>',
                                            default => '<span class="badge bg-secondary">Other</span>'
                                        };
                                    ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= htmlspecialchars($acc['account_name']) ?></strong>
                                            </td>
                                            <td><code class="text-dark"><?= htmlspecialchars($acc['account_code']) ?></code></td>
                                            <td><?= $typeBadge ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($acc['bank_name'] ?: 'N/A') ?>
                                                    <?php if ($acc['account_number']): ?> (<?= htmlspecialchars($acc['account_number']) ?>)<?php endif; ?>
                                                </small>
                                            </td>
                                            <td class="text-end fw-bold text-dark">
                                                <?= Helper::formatCurrency($acc['current_balance']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?= Helper::statusBadge($acc['status']) ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?= BASE_URL ?>/modules/accounting/accounts.php?account_id=<?= $acc['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2">
                                                    <i class="bi bi-list-check me-1"></i> Ledger
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </form>

        <!-- Hidden form specifically for Cash 1-Click Toggle -->
        <form method="POST" action="" id="pettyCashToggleForm" style="display: none;">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="action" value="toggle_petty_cash">
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Check URL hash to open correct tab
    const hash = window.location.hash;
    if (hash) {
        const triggerEl = document.querySelector(`button[data-bs-target="${hash}"]`);
        if (triggerEl) {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }

    // Update URL hash when clicking tabs
    document.querySelectorAll('button[data-bs-toggle="pill"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', (e) => {
            const targetId = e.target.getAttribute('data-bs-target');
            history.replaceState(null, null, targetId);
            const tabKey = targetId.replace('#tab-', '');
            document.getElementById('activeTabInput').value = tabKey;
        });
    });
});

function previewSignature(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewImg = document.getElementById('sigPreviewImg');
            const placeholder = document.getElementById('sigPlaceholder');
            if (previewImg) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            }
            if (placeholder) {
                placeholder.style.display = 'none';
            }
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
