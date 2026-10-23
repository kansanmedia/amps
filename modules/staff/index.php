<?php
// modules/staff/index.php - Staff Directory & Management
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$error = '';

// Handle Staff Creation / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_staff') {
        Auth::requireRole(['owner_admin', 'manager']);
        $delStaffId = (int)($_POST['staff_id'] ?? 0);
        $stfStmt = $db->prepare("SELECT * FROM staff WHERE id = :id");
        $stfStmt->execute([':id' => $delStaffId]);
        $stf = $stfStmt->fetch();

        if (!$stf) {
            Helper::setFlash('danger', 'Staff member not found.');
        } else {
            // Check if there are recorded salary payments
            $payCount = (int)$db->query("SELECT COUNT(*) FROM staff_payments WHERE staff_id = $delStaffId")->fetchColumn();
            if ($payCount > 0) {
                Helper::setFlash('danger', "Cannot delete staff '{$stf['full_name']}': Recorded salary payments exist. Please set status to Inactive instead.");
            } else {
                try {
                    $db->beginTransaction();
                    $db->prepare("DELETE FROM attendance WHERE staff_id = :id")->execute([':id' => $delStaffId]);
                    $db->prepare("DELETE FROM deployments WHERE staff_id = :id")->execute([':id' => $delStaffId]);
                    $db->prepare("DELETE FROM rate_cards WHERE staff_id = :id")->execute([':id' => $delStaffId]);
                    $db->prepare("DELETE FROM staff_leaves WHERE staff_id = :id")->execute([':id' => $delStaffId]);
                    $db->prepare("UPDATE users SET staff_id = NULL WHERE staff_id = :id")->execute([':id' => $delStaffId]);
                    $db->prepare("DELETE FROM staff WHERE id = :id")->execute([':id' => $delStaffId]);
                    $db->commit();

                    Audit::log('DELETE', 'STAFF', $delStaffId, $stf, null);
                    Helper::setFlash('success', "Staff member '{$stf['full_name']}' has been permanently deleted.");
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    Helper::setFlash('danger', "Failed to delete staff member: " . $e->getMessage());
                }
            }
        }
        Helper::redirect('modules/staff/index.php');
    }

    if ($action === 'save_staff') {
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $staffCode = strtoupper(trim($_POST['staff_code'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $emergencyContact = trim($_POST['emergency_contact'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $idProofType = trim($_POST['id_proof_type'] ?? 'Aadhaar Card');
        $idProofNumber = trim($_POST['id_proof_number'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $skillCategory = trim($_POST['skill_category'] ?? '');
        if ($categoryId) {
            $catStmt = $db->prepare("SELECT category_name FROM staff_categories WHERE id = :cid");
            $catStmt->execute([':cid' => $categoryId]);
            if ($cRow = $catStmt->fetch()) {
                $skillCategory = $cRow['category_name'];
            }
        }
        if (empty($skillCategory)) {
            $skillCategory = 'General Staff';
        }
        $defaultPayoutRate = (float)($_POST['default_payout_rate'] ?? 500.00);
        $bankName = trim($_POST['bank_name'] ?? '');
        $bankAccountNo = trim($_POST['bank_account_no'] ?? '');
        $bankIfsc = strtoupper(trim($_POST['bank_ifsc'] ?? ''));
        $upiId = trim($_POST['upi_id'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $joinedDate = !empty($_POST['joined_date']) ? $_POST['joined_date'] : date('Y-m-d');

        if (empty($fullName) || empty($phone)) {
            $error = 'Full Name and Phone Number are required.';
        } else {
            // Check unique phone
            $checkStmt = $db->prepare("SELECT id FROM staff WHERE phone = :p AND (:id IS NULL OR id != :id)");
            $checkStmt->execute([':p' => $phone, ':id' => $staffId]);
            if ($checkStmt->fetch()) {
                $error = 'A staff member with this phone number already exists.';
            } else {
                if (empty($staffCode)) {
                    $staffCode = 'STF-' . date('ym') . '-' . rand(100, 999);
                }

                // Handle file upload if provided
                $idProofDoc = null;
                if (!empty($_FILES['id_proof_file']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['id_proof_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $filename = 'doc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        $destination = UPLOAD_DIR . 'staff_docs/' . $filename;
                        if (move_uploaded_file($_FILES['id_proof_file']['tmp_name'], $destination)) {
                            $idProofDoc = 'uploads/staff_docs/' . $filename;
                        }
                    }
                }

                if ($staffId) {
                    $sql = "UPDATE staff SET full_name=:fn, phone=:ph, email=:em, emergency_contact=:ec, 
                            address=:addr, id_proof_type=:ipt, id_proof_number=:ipn, skill_category=:sc, 
                            category_id=:cid, default_payout_rate=:dpr, bank_name=:bn, bank_account_no=:ban, 
                            bank_ifsc=:bif, upi_id=:upi, status=:st, joined_date=:jd";
                    if ($idProofDoc) {
                        $sql .= ", id_proof_doc=:doc";
                    }
                    $sql .= " WHERE id=:id";

                    $params = [
                        ':fn' => $fullName, ':ph' => $phone, ':em' => $email, ':ec' => $emergencyContact,
                        ':addr' => $address, ':ipt' => $idProofType, ':ipn' => $idProofNumber, ':sc' => $skillCategory,
                        ':cid' => $categoryId, ':dpr' => $defaultPayoutRate, ':bn' => $bankName, ':ban' => $bankAccountNo,
                        ':bif' => $bankIfsc, ':upi' => $upiId, ':st' => $status, ':jd' => $joinedDate, ':id' => $staffId
                    ];
                    if ($idProofDoc) $params[':doc'] = $idProofDoc;

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);

                    Audit::log('UPDATE', 'STAFF', $staffId, null, $_POST);
                    Helper::setFlash('success', "Staff '{$fullName}' updated successfully.");
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO staff (staff_code, full_name, phone, email, emergency_contact, address, 
                                           id_proof_type, id_proof_number, id_proof_doc, skill_category, category_id,
                                           default_payout_rate, bank_name, bank_account_no, bank_ifsc, upi_id, 
                                           status, joined_date)
                        VALUES (:code, :fn, :ph, :em, :ec, :addr, :ipt, :ipn, :doc, :sc, :cid, :dpr, :bn, :ban, :bif, :upi, :st, :jd)
                    ");
                    $stmt->execute([
                        ':code' => $staffCode, ':fn' => $fullName, ':ph' => $phone, ':em' => $email, ':ec' => $emergencyContact,
                        ':addr' => $address, ':ipt' => $idProofType, ':ipn' => $idProofNumber, ':doc' => $idProofDoc,
                        ':sc' => $skillCategory, ':cid' => $categoryId, ':dpr' => $defaultPayoutRate, ':bn' => $bankName,
                        ':ban' => $bankAccountNo, ':bif' => $bankIfsc, ':upi' => $upiId, ':st' => $status, ':jd' => $joinedDate
                    ]);
                    $newId = (int)$db->lastInsertId();
                    Audit::log('CREATE', 'STAFF', $newId, null, $_POST);
                    Helper::setFlash('success', "Staff '{$fullName}' added successfully.");
                }
                Helper::redirect('modules/staff/index.php');
            }
        }
    }
}

// Fetch active categories for dropdown & badges
$categories = $db->query("SELECT id, category_code, category_name FROM staff_categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll();

// Fetch staff list with their un-settled balance & deployment counts
$query = "
    SELECT s.*, sc.category_name, sc.category_code,
        (SELECT COUNT(d.id) FROM deployments d WHERE d.staff_id = s.id AND d.status != 'cancelled') as total_deployments,
        (SELECT COUNT(a.id) FROM attendance a WHERE a.staff_id = s.id AND a.status IN ('present', 'half_day')) as total_duties,
        (SELECT COALESCE(SUM(a.final_staff_amount), 0) FROM attendance a WHERE a.staff_id = s.id AND a.status IN ('present', 'half_day')) as total_earnings,
        (SELECT COALESCE(SUM(sp.amount), 0) FROM staff_payments sp WHERE sp.staff_id = s.id) as total_paid
    FROM staff s 
    LEFT JOIN staff_categories sc ON s.category_id = sc.id
    ORDER BY s.id DESC
";
$staffList = $db->query($query)->fetchAll();

// Compute staff workforce totals
$totalDutiesAll = 0;
$totalEarnedAll = 0;
$totalPaidAll = 0;
$totalBalanceAll = 0;
foreach ($staffList as $stf) {
    $totalDutiesAll += (int)($stf['total_duties'] ?? 0);
    $totalEarnedAll += (float)$stf['total_earnings'];
    $totalPaidAll += (float)$stf['total_paid'];
    $totalBalanceAll += ((float)$stf['total_earnings'] - (float)$stf['total_paid']);
}

$pageTitle = 'Staff Directory';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('directory', 'staff') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Staff Directory</h4>
        <p class="text-muted small mb-0">Manage workforce profiles, KYC documents, banking/UPI credentials, and deployment earnings.</p>
    </div>
    <div class="d-flex gap-2">
        <div class="input-group input-group-sm" style="width: 250px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control border-start-0" placeholder="Search staff..." data-table-search="#staffTable">
        </div>
        <a href="<?= BASE_URL ?>/modules/staff/categories.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-person-badge-fill me-1"></i> Staff Categories
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="openStaffModal()">
            <i class="bi bi-person-plus-fill me-1"></i> Register New Staff
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<style>
.staff-row-clickable {
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.staff-row-clickable:hover {
    background-color: #f8fafc !important;
}
.staff-ledger-link {
    color: #0f172a;
    transition: color 0.15s ease;
}
.staff-row-clickable:hover .staff-ledger-link {
    color: #0d6efd !important;
    text-decoration: underline !important;
}
</style>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="staffTable">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th>Staff Code / Name</th>
                    <th>Category / Role</th>
                    <th>Contact & Emergency</th>
                    <th class="text-center" style="width: 105px;">Total Duty</th>
                    <th class="text-end">Default Payout Rate</th>
                    <th class="text-end">Total Earned</th>
                    <th class="text-end">Settled</th>
                    <th class="text-end">Balance Due</th>
                    <th class="text-center">Status</th>
                    <th class="text-end" style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr><td colspan="11" class="text-center py-4 text-muted">No staff members found. Register your first staff member above.</td></tr>
                <?php else: ?>
                    <?php $rowNum = 1; foreach ($staffList as $stf): 
                        $balanceDue = (float)$stf['total_earnings'] - (float)$stf['total_paid'];
                    ?>
                        <tr class="staff-row-clickable" onclick="if (!event.target.closest('.dropdown') && !event.target.closest('button') && !event.target.closest('a')) window.location.href='<?= BASE_URL ?>/modules/staff/view.php?id=<?= $stf['id'] ?>'" title="Click to view <?= htmlspecialchars($stf['full_name']) ?>'s Profile & Ledger">
                            <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary-subtle text-primary fw-bold d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size:0.75rem; flex-shrink: 0;">
                                        <?= strtoupper(substr($stf['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <a href="<?= BASE_URL ?>/modules/staff/view.php?id=<?= $stf['id'] ?>" class="fw-bold staff-ledger-link text-decoration-none">
                                            <?= htmlspecialchars($stf['full_name']) ?>
                                        </a>
                                        <div class="text-muted" style="font-size: 0.70rem;"><?= htmlspecialchars($stf['staff_code']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($stf['category_name'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold py-0.5 px-1.5" style="font-size: 0.70rem;">
                                        <?= htmlspecialchars($stf['category_name']) ?>
                                    </span>
                                    <div class="text-muted font-monospace" style="font-size: 0.68rem;"><?= htmlspecialchars($stf['category_code']) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border py-0.5 px-1.5" style="font-size: 0.70rem;"><?= htmlspecialchars($stf['skill_category']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><i class="bi bi-telephone text-primary me-1"></i> <?= htmlspecialchars($stf['phone']) ?></div>
                                <?php if (!empty($stf['emergency_contact'])): ?>
                                    <div class="text-muted" style="font-size: 0.70rem;"><i class="bi bi-shield-exclamation text-warning me-1"></i> Emg: <?= htmlspecialchars($stf['emergency_contact']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center font-monospace">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold px-2 py-1" style="font-size: 0.76rem;" title="<?= (int)($stf['total_duties'] ?? 0) ?> Duties Completed">
                                    <i class="bi bi-calendar-check me-0.5"></i> <?= (int)($stf['total_duties'] ?? 0) ?> Duty
                                </span>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="text-dark"><?= Helper::formatCurrency($stf['default_payout_rate']) ?></strong>
                            </td>
                            <td class="text-end font-monospace">
                                <span class="text-primary fw-bold"><?= Helper::formatCurrency($stf['total_earnings']) ?></span>
                            </td>
                            <td class="text-end font-monospace">
                                <span class="text-success"><?= Helper::formatCurrency($stf['total_paid']) ?></span>
                            </td>
                            <td class="text-end font-monospace">
                                <strong class="<?= $balanceDue > 0 ? 'text-danger' : 'text-secondary' ?>">
                                    <?= Helper::formatCurrency($balanceDue) ?>
                                </strong>
                            </td>
                            <td class="text-center"><?= Helper::statusBadge($stf['status']) ?></td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <div class="dropdown">
                                    <button class="btn btn-xs btn-outline-secondary dropdown-toggle px-2 py-0.5 shadow-2xs" type="button" data-bs-toggle="dropdown" style="font-size: 0.72rem;">
                                        Options
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/staff/view.php?id=<?= $stf['id'] ?>"><i class="bi bi-eye me-2"></i> View Profile & Ledger</a></li>
                                        <li><button class="dropdown-item" onclick='editStaff(<?= json_encode($stf) ?>)'><i class="bi bi-pencil me-2"></i> Edit Details</button></li>
                                        <?php if (Auth::isAccountant() && $balanceDue > 0): ?>
                                             <li><hr class="dropdown-divider"></li>
                                             <li><a class="dropdown-item text-success fw-semibold" href="<?= BASE_URL ?>/modules/staff_payments/settle.php?staff_id=<?= $stf['id'] ?>"><i class="bi bi-cash-coin me-2"></i> Settle Salary</a></li>
                                        <?php endif; ?>
                                        <?php if (Auth::hasRole(['owner_admin', 'manager'])): ?>
                                             <li><hr class="dropdown-divider"></li>
                                             <li><button type="button" class="dropdown-item text-danger" onclick="confirmDeleteStaff(<?= $stf['id'] ?>, '<?= htmlspecialchars(addslashes($stf['full_name']), ENT_QUOTES) ?>')"><i class="bi bi-trash me-2"></i> Delete Staff</button></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($staffList)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td colspan="3" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                        Workforce Total: <strong><?= count($staffList) ?></strong> Registered Staff Members
                    </td>
                    <td class="text-center font-monospace text-primary" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <strong><?= number_format($totalDutiesAll) ?></strong> Duties
                    </td>
                    <td class="text-end text-muted small">--</td>
                    <td class="text-end font-monospace text-primary" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totalEarnedAll) ?>
                    </td>
                    <td class="text-end font-monospace text-success" style="font-size: 0.82rem; background-color: #f1f5f9;">
                        <?= Helper::formatCurrency($totalPaidAll) ?>
                    </td>
                    <td class="text-end font-monospace <?= $totalBalanceAll > 0 ? 'text-danger' : 'text-secondary' ?>" style="font-size: 0.82rem; background-color: #fef2f2;">
                        <?= Helper::formatCurrency($totalBalanceAll) ?>
                    </td>
                    <td colspan="2" class="text-muted small text-end">Active Ledger Balance</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Add/Edit Staff Modal -->
<div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" enctype="multipart/form-data">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_staff">
                <input type="hidden" name="staff_id" id="modal_staff_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="staffModalTitle">Register New Staff</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Staff Code (Auto or Custom)</label>
                            <input type="text" name="staff_code" id="modal_stf_code" class="form-control" placeholder="STF-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Full Name *</label>
                            <input type="text" name="full_name" id="modal_stf_name" class="form-control" placeholder="e.g. Rahul Sharma" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Phone Number *</label>
                            <input type="text" name="phone" id="modal_stf_phone" class="form-control" placeholder="10-digit mobile" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" name="email" id="modal_stf_email" class="form-control" placeholder="optional@email.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Emergency Contact Phone</label>
                            <input type="text" name="emergency_contact" id="modal_stf_emg" class="form-control" placeholder="Family member phone">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Staff Category / Role *</label>
                            <select name="category_id" id="modal_stf_category_id" class="form-select" required onchange="onStaffCategoryChange()">
                                <option value="">-- Choose Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" data-name="<?= htmlspecialchars($cat['category_name']) ?>">
                                        <?= htmlspecialchars($cat['category_name']) ?> (<?= $cat['category_code'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="skill_category" id="modal_stf_skill" value="">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Default Payout Rate (₹ per shift)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₹</span>
                                <input type="number" step="10" name="default_payout_rate" id="modal_stf_rate" class="form-control" value="600" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Joining Date</label>
                            <input type="date" name="joined_date" id="modal_stf_joined" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- Banking & UPI -->
                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-bank me-2 text-primary"></i>Banking & UPI Details</h6>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Bank Name</label>
                            <input type="text" name="bank_name" id="modal_stf_bank" class="form-control" placeholder="e.g. SBI, HDFC">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Account Number</label>
                            <input type="text" name="bank_account_no" id="modal_stf_acc" class="form-control" placeholder="Account No">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">IFSC Code</label>
                            <input type="text" name="bank_ifsc" id="modal_stf_ifsc" class="form-control" placeholder="SBIN0001234">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">UPI ID</label>
                            <input type="text" name="upi_id" id="modal_stf_upi" class="form-control" placeholder="user@okhdfcbank">
                        </div>

                        <!-- KYC & Status -->
                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-card-checklist me-2 text-primary"></i>KYC & ID Proof</h6>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">ID Proof Type</label>
                            <select name="id_proof_type" id="modal_stf_idtype" class="form-select">
                                <option value="Aadhaar Card">Aadhaar Card</option>
                                <option value="Voter ID">Voter ID</option>
                                <option value="PAN Card">PAN Card</option>
                                <option value="Passport">Passport</option>
                                <option value="Driving License">Driving License</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">ID Proof Number</label>
                            <input type="text" name="id_proof_number" id="modal_stf_idnum" class="form-control" placeholder="e.g. 1234 5678 9012">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Upload ID Document</label>
                            <input type="file" name="id_proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Address</label>
                            <textarea name="address" id="modal_stf_addr" class="form-control" rows="2" placeholder="Residential address..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Account Status</label>
                            <select name="status" id="modal_stf_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-check2-circle me-1"></i> Save Staff Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const staffModal = new bootstrap.Modal(document.getElementById('staffModal'));

function onStaffCategoryChange() {
    const sel = document.getElementById('modal_stf_category_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('modal_stf_skill').value = opt.dataset.name || '';
    }
}

function openStaffModal() {
    document.getElementById('modal_staff_id').value = '';
    document.getElementById('modal_stf_code').value = 'STF-' + Math.floor(Math.random() * 9000 + 1000);
    document.getElementById('modal_stf_name').value = '';
    document.getElementById('modal_stf_phone').value = '';
    document.getElementById('modal_stf_email').value = '';
    document.getElementById('modal_stf_emg').value = '';
    
    const catSelect = document.getElementById('modal_stf_category_id');
    catSelect.selectedIndex = catSelect.options.length > 1 ? 1 : 0;
    const rateInput = document.getElementById('modal_stf_rate');
    rateInput.dataset.isDefault = '1';
    onStaffCategoryChange();

    document.getElementById('modal_stf_joined').value = new Date().toISOString().split('T')[0];
    document.getElementById('modal_stf_bank').value = '';
    document.getElementById('modal_stf_acc').value = '';
    document.getElementById('modal_stf_ifsc').value = '';
    document.getElementById('modal_stf_upi').value = '';
    document.getElementById('modal_stf_idtype').value = 'Aadhaar Card';
    document.getElementById('modal_stf_idnum').value = '';
    document.getElementById('modal_stf_addr').value = '';
    document.getElementById('modal_stf_status').value = 'active';
    document.getElementById('staffModalTitle').textContent = 'Register New Staff';
    staffModal.show();
}

function editStaff(s) {
    document.getElementById('modal_staff_id').value = s.id;
    document.getElementById('modal_stf_code').value = s.staff_code;
    document.getElementById('modal_stf_name').value = s.full_name;
    document.getElementById('modal_stf_phone').value = s.phone;
    document.getElementById('modal_stf_email').value = s.email || '';
    document.getElementById('modal_stf_emg').value = s.emergency_contact || '';
    
    const catSelect = document.getElementById('modal_stf_category_id');
    catSelect.value = s.category_id || '';
    document.getElementById('modal_stf_skill').value = s.skill_category || '';

    const rateInput = document.getElementById('modal_stf_rate');
    rateInput.value = s.default_payout_rate;
    rateInput.dataset.isDefault = '0'; // User-configured custom rate

    document.getElementById('modal_stf_joined').value = s.joined_date;
    document.getElementById('modal_stf_bank').value = s.bank_name || '';
    document.getElementById('modal_stf_acc').value = s.bank_account_no || '';
    document.getElementById('modal_stf_ifsc').value = s.bank_ifsc || '';
    document.getElementById('modal_stf_upi').value = s.upi_id || '';
    document.getElementById('modal_stf_idtype').value = s.id_proof_type || 'Aadhaar Card';
    document.getElementById('modal_stf_idnum').value = s.id_proof_number || '';
    document.getElementById('modal_stf_addr').value = s.address || '';
    document.getElementById('modal_stf_status').value = s.status;
    document.getElementById('staffModalTitle').textContent = 'Edit Staff: ' + s.full_name;
    staffModal.show();
}

const deleteStaffModal = new bootstrap.Modal(document.getElementById('deleteStaffModal'));
function confirmDeleteStaff(id, name) {
    document.getElementById('delete_staff_id').value = id;
    document.getElementById('delete_staff_name').textContent = name;
    deleteStaffModal.show();
}
</script>

<!-- Delete Staff Confirmation Modal -->
<div class="modal fade" id="deleteStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Staff</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="staff_id" id="delete_staff_id">
                <div class="modal-body text-center py-3">
                    <p class="mb-2">Are you sure you want to permanently delete <strong id="delete_staff_name" class="text-danger"></strong>?</p>
                    <p class="text-muted small mb-0">This will remove this staff record permanently.</p>
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
