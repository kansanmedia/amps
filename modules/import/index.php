<?php
// modules/import/index.php - Bulk Import Center for Clients, Sites & Staff
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/ExcelImportService.php';

Auth::requireRole(['owner_admin', 'manager', 'accountant']);

// Handle Template Download Requests
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $type = $_GET['type'] ?? 'clients';
    if (!in_array($type, ['clients', 'sites', 'staff'])) {
        $type = 'clients';
    }
    ExcelImportService::downloadTemplate($type);
    exit;
}

$pageTitle = 'Bulk Data Import Center | ' . APP_NAME;
$activeType = $_GET['type'] ?? 'clients';
if (!in_array($activeType, ['clients', 'sites', 'staff'])) {
    $activeType = 'clients';
}

$error = '';
$importResult = null;

// Handle File Upload & Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();

    $importType = $_POST['import_type'] ?? '';
    $duplicateMode = $_POST['duplicate_mode'] ?? 'skip'; // 'skip' or 'update'
    $activeType = $importType;

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrCode = $_FILES['import_file']['error'] ?? -1;
        $error = match($uploadErrCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "File size exceeds server upload limit.",
            UPLOAD_ERR_NO_FILE => "Please select an Excel (.xlsx) or CSV (.csv) file to upload.",
            default => "Error uploading file. Please try again."
        };
    } else {
        $tmpPath = $_FILES['import_file']['tmp_name'];
        $origName = $_FILES['import_file']['name'];

        try {
            $parsedData = ExcelImportService::parseFile($tmpPath, $origName);

            if (empty($parsedData['rows'])) {
                throw new Exception("The uploaded file does not contain any data rows.");
            }

            if ($importType === 'clients') {
                $importResult = ExcelImportService::importClients($parsedData['rows'], ['mode' => $duplicateMode]);
            } elseif ($importType === 'sites') {
                $importResult = ExcelImportService::importSites($parsedData['rows'], ['mode' => $duplicateMode]);
            } elseif ($importType === 'staff') {
                $importResult = ExcelImportService::importStaff($parsedData['rows'], ['mode' => $duplicateMode]);
            } else {
                throw new Exception("Invalid import category specified.");
            }

            $successMsg = "Import processed: {$importResult['inserted']} added, {$importResult['updated']} updated, {$importResult['skipped']} skipped.";
            Helper::setFlash($importResult['inserted'] > 0 || $importResult['updated'] > 0 ? 'success' : 'warning', $successMsg);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<!-- Top Hub Navigation -->
<?= Helper::renderSubNav('directory', 'import') ?>

    <!-- Page Header & Action Bar -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark">
                <i class="bi bi-file-earmark-excel-fill text-success me-2"></i>Bulk Import Center
            </h4>
            <div class="text-muted small">Import bulk Clients, Venue Sites, and Staff Workforce in seconds from Excel (.xlsx) or CSV files.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <button class="btn btn-outline-success btn-sm dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-download me-1"></i> Download Sample Templates
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li><h6 class="dropdown-header">Ready-to-use Sample Templates</h6></li>
                    <li><a class="dropdown-item py-2" href="?action=download_template&type=clients"><i class="bi bi-buildings text-primary me-2"></i>Clients Template (.csv)</a></li>
                    <li><a class="dropdown-item py-2" href="?action=download_template&type=sites"><i class="bi bi-geo-alt text-info me-2"></i>Venues & Sites Template (.csv)</a></li>
                    <li><a class="dropdown-item py-2" href="?action=download_template&type=staff"><i class="bi bi-people text-success me-2"></i>Staff Workforce Template (.csv)</a></li>
                </ul>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2.5 small mb-3 shadow-sm border-0" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5 text-danger flex-shrink-0"></i>
                <div>
                    <strong>Import Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- IMPORT RESULT SUMMARY CARD -->
    <?php if ($importResult): ?>
        <div class="custom-card mb-4 border-0 shadow-sm overflow-hidden">
            <div class="p-3 bg-white border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-check-circle-fill text-success me-1.5"></i>Import Execution Summary
                </h6>
                <span class="badge bg-light text-dark border">Type: <?= strtoupper($activeType) ?></span>
            </div>
            <div class="p-3 bg-light-subtle">
                <div class="row g-2 text-center mb-3">
                    <div class="col-6 col-md-3">
                        <div class="bg-white p-2.5 rounded-3 border">
                            <div class="text-muted small text-uppercase" style="font-size: 0.7rem;">Total Rows</div>
                            <h4 class="fw-bold mb-0 text-dark"><?= $importResult['total_rows'] ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-white p-2.5 rounded-3 border border-success-subtle">
                            <div class="text-success small text-uppercase fw-semibold" style="font-size: 0.7rem;">Newly Inserted</div>
                            <h4 class="fw-bold mb-0 text-success">+<?= $importResult['inserted'] ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-white p-2.5 rounded-3 border border-primary-subtle">
                            <div class="text-primary small text-uppercase fw-semibold" style="font-size: 0.7rem;">Updated Existing</div>
                            <h4 class="fw-bold mb-0 text-primary"><?= $importResult['updated'] ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-white p-2.5 rounded-3 border <?= $importResult['skipped'] > 0 ? 'border-warning-subtle' : '' ?>">
                            <div class="text-muted small text-uppercase" style="font-size: 0.7rem;">Skipped / Conflicts</div>
                            <h4 class="fw-bold mb-0 <?= $importResult['skipped'] > 0 ? 'text-warning-emphasis' : 'text-muted' ?>"><?= $importResult['skipped'] ?></h4>
                        </div>
                    </div>
                </div>

                <!-- Errors / Skipped List -->
                <?php if (!empty($importResult['errors'])): ?>
                    <div class="alert alert-warning py-2 px-3 small mb-3 border-0">
                        <div class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i> Skipped / Conflict Notices:</div>
                        <ul class="mb-0 ps-3">
                            <?php foreach (array_slice($importResult['errors'], 0, 10) as $err): ?>
                                <li>Row <?= $err['row'] ?? '?' ?> (<?= htmlspecialchars($err['item'] ?? '') ?>): <?= htmlspecialchars($err['reason']) ?></li>
                            <?php endforeach; ?>
                            <?php if (count($importResult['errors']) > 10): ?>
                                <li class="text-muted">...and <?= count($importResult['errors']) - 10 ?> more items.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Quick Navigation to Directory -->
                <div class="d-flex align-items-center justify-content-end gap-2">
                    <?php if ($activeType === 'clients'): ?>
                        <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-right me-1"></i> View Clients Directory
                        </a>
                    <?php elseif ($activeType === 'sites'): ?>
                        <a href="<?= BASE_URL ?>/modules/sites/index.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-right me-1"></i> View Sites & Venues
                        </a>
                    <?php elseif ($activeType === 'staff'): ?>
                        <a href="<?= BASE_URL ?>/modules/staff/index.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-right me-1"></i> View Staff Workforce
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MAIN IMPORT TABS CONTAINER -->
    <div class="custom-card">
        <!-- Navigation Pills -->
        <ul class="nav nav-pills p-2.5 bg-light border-bottom gap-2" id="importTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeType === 'clients' ? 'active' : '' ?> fw-semibold py-2 px-3" id="tab-clients-btn" data-bs-toggle="pill" data-bs-target="#tab-clients" type="button" role="tab">
                    <i class="bi bi-buildings me-1.5"></i>1. Import Clients
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeType === 'sites' ? 'active' : '' ?> fw-semibold py-2 px-3" id="tab-sites-btn" data-bs-toggle="pill" data-bs-target="#tab-sites" type="button" role="tab">
                    <i class="bi bi-geo-alt me-1.5"></i>2. Import Venues & Sites
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeType === 'staff' ? 'active' : '' ?> fw-semibold py-2 px-3" id="tab-staff-btn" data-bs-toggle="pill" data-bs-target="#tab-staff" type="button" role="tab">
                    <i class="bi bi-people me-1.5"></i>3. Import Staff Workforce
                </button>
            </li>
        </ul>

        <div class="tab-content p-3 p-md-4">
            <!-- =================================================================== -->
            <!-- TAB 1: CLIENTS IMPORT -->
            <!-- =================================================================== -->
            <div class="tab-pane fade <?= $activeType === 'clients' ? 'show active' : '' ?>" id="tab-clients" role="tabpanel">
                <div class="row g-4">
                    <!-- Left: Upload Form -->
                    <div class="col-lg-6">
                        <div class="card border p-3 p-md-4 rounded-3 h-100 shadow-xs">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Upload Clients File</h6>
                                <a href="?action=download_template&type=clients" class="btn btn-xs btn-outline-success">
                                    <i class="bi bi-download me-1"></i> Sample CSV
                                </a>
                            </div>

                            <form method="POST" enctype="multipart/form-data">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="import_type" value="clients">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small text-secondary">Select Excel (.xlsx) or CSV (.csv) File <span class="text-danger">*</span></label>
                                    <input type="file" name="import_file" class="form-control" accept=".xlsx, .xls, .csv, .txt" required>
                                    <div class="form-text small">Accepts Microsoft Excel workbooks (.xlsx) and standard CSV files.</div>
                                </div>

                                <div class="mb-3 p-2.5 bg-light rounded-2 border">
                                    <label class="form-label fw-bold small text-dark mb-1.5">Duplicate Handling (By Code or Name):</label>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="clientModeSkip" value="skip" checked>
                                            <label class="form-check-label small" for="clientModeSkip">
                                                <strong>Skip Existing Records</strong> (Recommended: protects existing data)
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="clientModeUpdate" value="update">
                                            <label class="form-check-label small" for="clientModeUpdate">
                                                <strong>Update Existing Records</strong> (Overwrite details with spreadsheet data)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                    <i class="bi bi-upload me-1.5"></i> Start Bulk Client Import
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Right: Field Specification Guide -->
                    <div class="col-lg-6">
                        <div class="card border rounded-3 p-3 bg-light-subtle h-100">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-table me-1.5 text-secondary"></i>Clients Column Reference</h6>
                            <div class="table-responsive" style="max-height: 380px;">
                                <table class="table table-sm table-bordered bg-white small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Column Header</th>
                                            <th>Status</th>
                                            <th>Description / Example</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>Company Name</code></td>
                                            <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                                            <td>e.g. <em>Grand Hyatt Hotel & Banquets</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Contact Person</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>Rajesh Sharma</em> (defaults to Accounts)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Phone</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>9876543210</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Client Code</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>CLI-101</em> (auto-generated if omitted)</td>
                                        </tr>
                                        <tr>
                                            <td><code>GST Number</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>07AAAAA0000A1Z5</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Billing Address</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>Aerocity, New Delhi</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Default Billing Rate</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>950.00</em> (default ₹800.00)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Billing Cycle</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td><code>monthly</code>, <code>weekly</code>, <code>fortnightly</code></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =================================================================== -->
            <!-- TAB 2: SITES IMPORT -->
            <!-- =================================================================== -->
            <div class="tab-pane fade <?= $activeType === 'sites' ? 'show active' : '' ?>" id="tab-sites" role="tabpanel">
                <div class="row g-4">
                    <!-- Left: Upload Form -->
                    <div class="col-lg-6">
                        <div class="card border p-3 p-md-4 rounded-3 h-100 shadow-xs">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up-fill text-info me-2"></i>Upload Venues & Sites File</h6>
                                <a href="?action=download_template&type=sites" class="btn btn-xs btn-outline-success">
                                    <i class="bi bi-download me-1"></i> Sample CSV
                                </a>
                            </div>

                            <form method="POST" enctype="multipart/form-data">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="import_type" value="sites">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small text-secondary">Select Excel (.xlsx) or CSV (.csv) File <span class="text-danger">*</span></label>
                                    <input type="file" name="import_file" class="form-control" accept=".xlsx, .xls, .csv, .txt" required>
                                    <div class="form-text small">Sites will automatically be linked to their corresponding Client by name or code.</div>
                                </div>

                                <div class="mb-3 p-2.5 bg-light rounded-2 border">
                                    <label class="form-label fw-bold small text-dark mb-1.5">Duplicate Handling (By Site Name under Client):</label>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="siteModeSkip" value="skip" checked>
                                            <label class="form-check-label small" for="siteModeSkip">
                                                <strong>Skip Existing Sites</strong> (Recommended)
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="siteModeUpdate" value="update">
                                            <label class="form-check-label small" for="siteModeUpdate">
                                                <strong>Update Existing Sites</strong> (Update address and rates)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                    <i class="bi bi-upload me-1.5"></i> Start Bulk Sites Import
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Right: Field Specification Guide -->
                    <div class="col-lg-6">
                        <div class="card border rounded-3 p-3 bg-light-subtle h-100">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-table me-1.5 text-secondary"></i>Sites Column Reference</h6>
                            <div class="table-responsive" style="max-height: 380px;">
                                <table class="table table-sm table-bordered bg-white small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Column Header</th>
                                            <th>Status</th>
                                            <th>Description / Example</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>Client Company or Code</code></td>
                                            <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                                            <td>e.g. <em>Grand Hyatt Hotel & Banquets</em> or <em>CLI-101</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Site Name</code></td>
                                            <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                                            <td>e.g. <em>Main Ballroom & Pre-Function</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Location Address</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>Gate 2, Lower Ground, Aerocity</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Site Code</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>SIT-HYT-01</em> (auto-generated if omitted)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Contact Person</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>Vikram Malhotra</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Contact Phone</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>9899001122</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Default Client Rate</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>950.00</em> (falls back to client's rate)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Default Staff Rate</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>600.00</em> (default ₹600.00)</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =================================================================== -->
            <!-- TAB 3: STAFF WORKFORCE IMPORT -->
            <!-- =================================================================== -->
            <div class="tab-pane fade <?= $activeType === 'staff' ? 'show active' : '' ?>" id="tab-staff" role="tabpanel">
                <div class="row g-4">
                    <!-- Left: Upload Form -->
                    <div class="col-lg-6">
                        <div class="card border p-3 p-md-4 rounded-3 h-100 shadow-xs">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up-fill text-success me-2"></i>Upload Staff Workforce File</h6>
                                <a href="?action=download_template&type=staff" class="btn btn-xs btn-outline-success">
                                    <i class="bi bi-download me-1"></i> Sample CSV
                                </a>
                            </div>

                            <form method="POST" enctype="multipart/form-data">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="import_type" value="staff">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small text-secondary">Select Excel (.xlsx) or CSV (.csv) File <span class="text-danger">*</span></label>
                                    <input type="file" name="import_file" class="form-control" accept=".xlsx, .xls, .csv, .txt" required>
                                    <div class="form-text small">Mobile phone number is used as the unique identifier to prevent duplicate entries.</div>
                                </div>

                                <div class="mb-3 p-2.5 bg-light rounded-2 border">
                                    <label class="form-label fw-bold small text-dark mb-1.5">Duplicate Handling (By Mobile Phone):</label>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="staffModeSkip" value="skip" checked>
                                            <label class="form-check-label small" for="staffModeSkip">
                                                <strong>Skip Existing Staff</strong> (Recommended)
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duplicate_mode" id="staffModeUpdate" value="update">
                                            <label class="form-check-label small" for="staffModeUpdate">
                                                <strong>Update Existing Staff</strong> (Update bank, UPI & category info)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                    <i class="bi bi-upload me-1.5"></i> Start Bulk Staff Import
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Right: Field Specification Guide -->
                    <div class="col-lg-6">
                        <div class="card border rounded-3 p-3 bg-light-subtle h-100">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-table me-1.5 text-secondary"></i>Staff Column Reference</h6>
                            <div class="table-responsive" style="max-height: 380px;">
                                <table class="table table-sm table-bordered bg-white small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Column Header</th>
                                            <th>Status</th>
                                            <th>Description / Example</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>Full Name</code></td>
                                            <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                                            <td>e.g. <em>Amit Kumar Verma</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Phone</code></td>
                                            <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                                            <td>e.g. <em>9876500001</em> (10 digits)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Skill Category</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>Senior Steward / Waiter</em>, <em>Bartender</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Default Payout Rate</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>600.00</em> (defaults to category rate)</td>
                                        </tr>
                                        <tr>
                                            <td><code>Bank Name</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>State Bank of India</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Bank Account No</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>330198273645</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Bank IFSC</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>SBIN0001234</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>UPI ID</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>amitkumar@sbi</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>ID Proof Number</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>4521 8899 0012</em></td>
                                        </tr>
                                        <tr>
                                            <td><code>Joined Date</code></td>
                                            <td><span class="badge bg-secondary-subtle text-secondary">Optional</span></td>
                                            <td>e.g. <em>2026-01-10</em> (defaults to today)</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
