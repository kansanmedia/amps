<?php
// modules/settings/users.php - Comprehensive User Accounts & Role Permissions Hub
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin']);

$db = DB::getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    // 1. DELETE USER
    if ($action === 'delete_user') {
        $delUserId = (int)($_POST['user_id'] ?? 0);
        $currentAdminId = (int)Auth::id();

        if ($delUserId <= 0) {
            Helper::setFlash('danger', 'Invalid user account specified.');
        } elseif ($delUserId === $currentAdminId) {
            Helper::setFlash('danger', 'Safety Guard: You cannot delete your own active logged-in account.');
        } else {
            $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
            $uStmt->execute([':id' => $delUserId]);
            $userToDelete = $uStmt->fetch();

            if (!$userToDelete) {
                Helper::setFlash('danger', 'User account not found.');
            } elseif ($userToDelete['role'] === 'owner_admin') {
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'owner_admin' AND status = 'active'")->fetchColumn();
                if ($adminCount <= 1) {
                    Helper::setFlash('danger', 'Safety Guard: Cannot delete this user. At least one active Super Admin (Owner Admin) must remain in the system.');
                } else {
                    $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $delUserId]);
                    Audit::log('DELETE', 'USERS', $delUserId, $userToDelete, null);
                    Helper::setFlash('success', "Super Admin account '{$userToDelete['username']}' ({$userToDelete['full_name']}) was permanently deleted.");
                }
            } else {
                $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $delUserId]);
                Audit::log('DELETE', 'USERS', $delUserId, $userToDelete, null);
                Helper::setFlash('success', "User account '{$userToDelete['username']}' ({$userToDelete['full_name']}) was permanently deleted.");
            }
        }
        Helper::redirect('modules/settings/users.php');
    }

    // 2. SAVE USER (CREATE / UPDATE)
    if ($action === 'save_user') {
        $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $username = strtolower(trim($_POST['username'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $status = $_POST['status'] ?? 'active';
        $password = trim($_POST['password'] ?? '');

        // Validation
        if (empty($username) || empty($email) || empty($fullName)) {
            $error = 'Username, Email address, and Full Name are strictly required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $error = 'Username must be between 3 and 30 characters and contain only letters, numbers, dots, hyphens, and underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check for duplicate username or email
            $dupSql = "SELECT id, username, email FROM users WHERE (username = :un OR email = :em)";
            if ($userId) $dupSql .= " AND id != :uid";
            $dupStmt = $db->prepare($dupSql);
            $dupParams = [':un' => $username, ':em' => $email];
            if ($userId) $dupParams[':uid'] = $userId;
            $dupStmt->execute($dupParams);
            $dupUser = $dupStmt->fetch();

            if ($dupUser) {
                if (strtolower($dupUser['username']) === $username) {
                    $error = "The username '{$username}' is already in use by another account.";
                } else {
                    $error = "The email address '{$email}' is already registered with another account.";
                }
            } elseif (!empty($password) && !Security::validatePasswordStrength($password)['valid']) {
                $error = Security::validatePasswordStrength($password)['error'];
            } else {
                if ($userId) {
                    // Update user
                    $sql = "UPDATE users SET username=:un, email=:em, full_name=:fn, phone=:ph, role=:ro, staff_id=:stfid, status=:st, updated_at=NOW()";
                    $params = [
                        ':un' => $username, ':em' => $email, ':fn' => $fullName, ':ph' => $phone,
                        ':ro' => $role, ':stfid' => $staffId, ':st' => $status, ':id' => $userId
                    ];
                    if (!empty($password)) {
                        $sql .= ", password_hash=:phash";
                        $params[':phash'] = Security::hashPassword($password);
                    }
                    $sql .= " WHERE id=:id";

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    Audit::log('UPDATE', 'USERS', $userId, null, ['username' => $username, 'role' => $role, 'status' => $status]);
                    Helper::setFlash('success', "User account '{$username}' ({$fullName}) updated successfully.");
                } else {
                    // Insert new user
                    if (empty($password)) {
                        $password = bin2hex(random_bytes(4)) . 'A1!';
                    }
                    $stmt = $db->prepare("
                        INSERT INTO users (username, email, password_hash, full_name, phone, role, staff_id, status, created_at, updated_at)
                        VALUES (:un, :em, :phash, :fn, :ph, :ro, :stfid, :st, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':un'    => $username,
                        ':em'    => $email,
                        ':phash' => Security::hashPassword($password),
                        ':fn'    => $fullName,
                        ':ph'    => $phone,
                        ':ro'    => $role,
                        ':stfid' => $staffId,
                        ':st'    => $status
                    ]);
                    $newId = (int)$db->lastInsertId();
                    Audit::log('CREATE', 'USERS', $newId, null, ['username' => $username, 'role' => $role]);
                    Helper::setFlash('success', "User account '{$username}' created successfully. Login Password: {$password}");
                }
                Helper::redirect('modules/settings/users.php');
            }
        }
    }
}

// Fetch all users
$users = $db->query("
    SELECT u.*, s.full_name as linked_staff_name, s.staff_code, s.phone as linked_staff_phone
    FROM users u
    LEFT JOIN staff s ON u.staff_id = s.id
    ORDER BY 
        CASE u.role 
            WHEN 'owner_admin' THEN 1 
            WHEN 'manager' THEN 2 
            WHEN 'accountant' THEN 3 
            ELSE 4 
        END ASC, 
        u.id ASC
")->fetchAll();

// Counts by role
$totalUsers = count($users);
$adminCount = 0;
$managerCount = 0;
$accountantCount = 0;
$staffUserCount = 0;
$activeUsers = 0;

foreach ($users as $u) {
    if ($u['role'] === 'owner_admin') $adminCount++;
    elseif ($u['role'] === 'manager') $managerCount++;
    elseif ($u['role'] === 'accountant') $accountantCount++;
    elseif ($u['role'] === 'staff') $staffUserCount++;
    if ($u['status'] === 'active') $activeUsers++;
}

// Fetch staff for dropdown
$staffList = $db->query("SELECT id, full_name, staff_code, phone FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'Users & Role Permissions | ' . Helper::getCompanyName();
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<!-- Header Section & Quick Hub Navigation -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill" style="font-size: 0.72rem;">
                <i class="bi bi-shield-lock-fill me-1"></i> Owner Admin Exclusive
            </span>
            <span class="text-secondary small">|</span>
            <span class="text-muted small">Total Accounts: <strong><?= $totalUsers ?></strong> (Active: <?= $activeUsers ?>)</span>
        </div>
        <h4 class="fw-bold mb-0 text-dark">
            <i class="bi bi-people-gear text-primary me-2"></i>Admin & User Role Management
        </h4>
        <p class="text-muted small mb-0">Control team access levels, reset login credentials, and assign granular system permissions.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/settings/company.php" class="btn btn-outline-secondary btn-sm px-3 shadow-2xs">
            <i class="bi bi-gear me-1"></i> Company Profile
        </a>
        <button type="button" class="btn btn-primary btn-sm px-3 shadow-2xs fw-semibold" onclick="openUserModal()">
            <i class="bi bi-person-plus-fill me-1"></i> Add New User
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2.5 small mb-3 shadow-2xs d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<!-- 1. KPI Metric Summary Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-2xs p-3 rounded-3 bg-white h-100 border-start border-3 border-danger">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.68rem; letter-spacing: 0.5px;">Super Admins</div>
                    <div class="fs-4 fw-bold text-danger"><?= $adminCount ?></div>
                </div>
                <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="bi bi-shield-shaded fs-5"></i>
                </div>
            </div>
            <div class="text-muted small mt-1" style="font-size: 0.7rem;">Unrestricted full control</div>
        </div>
    </div>

    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-2xs p-3 rounded-3 bg-white h-100 border-start border-3 border-primary">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.68rem; letter-spacing: 0.5px;">Operations Managers</div>
                    <div class="fs-4 fw-bold text-primary"><?= $managerCount ?></div>
                </div>
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="bi bi-person-workspace fs-5"></i>
                </div>
            </div>
            <div class="text-muted small mt-1" style="font-size: 0.7rem;">Deployments & Attendance</div>
        </div>
    </div>

    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-2xs p-3 rounded-3 bg-white h-100 border-start border-3 border-success">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.68rem; letter-spacing: 0.5px;">Accountants</div>
                    <div class="fs-4 fw-bold text-success"><?= $accountantCount ?></div>
                </div>
                <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="bi bi-cash-stack fs-5"></i>
                </div>
            </div>
            <div class="text-muted small mt-1" style="font-size: 0.7rem;">Invoices, Ledger & PnL</div>
        </div>
    </div>

    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-2xs p-3 rounded-3 bg-white h-100 border-start border-3 border-secondary">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.68rem; letter-spacing: 0.5px;">Staff Self-Service</div>
                    <div class="fs-4 fw-bold text-secondary"><?= $staffUserCount ?></div>
                </div>
                <div class="rounded-circle bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="bi bi-phone fs-5"></i>
                </div>
            </div>
            <div class="text-muted small mt-1" style="font-size: 0.7rem;">Mobile duty & passbook</div>
        </div>
    </div>
</div>

<!-- 2. Users Table Card -->
<div class="custom-card shadow-2xs border-0 mb-4">
    <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2 bg-white">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-dark" style="font-size: 0.95rem;">Registered User Directory</span>
            <span class="badge bg-light text-secondary border px-2 py-0.5" id="userCountBadge"><?= $totalUsers ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 240px;">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="userFilterSearch" class="form-control border-start-0" placeholder="Search name, user, email...">
            </div>
            <select id="userRoleFilter" class="form-select form-select-sm" style="width: 170px;">
                <option value="">All Roles</option>
                <option value="owner_admin">Owner / Super Admin</option>
                <option value="manager">Operations Manager</option>
                <option value="accountant">Chief Accountant</option>
                <option value="staff">Staff Self-Service</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="usersMasterTable">
            <thead class="bg-light text-muted text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                <tr>
                    <th class="ps-3 py-2.5">User Identity</th>
                    <th class="py-2.5">Email & Phone</th>
                    <th class="py-2.5">Role Permission</th>
                    <th class="py-2.5">Linked Staff Worker</th>
                    <th class="py-2.5">Last Login</th>
                    <th class="py-2.5">Account Status</th>
                    <th class="pe-3 py-2.5 text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): 
                    $isSelf = ((int)$u['id'] === (int)Auth::id());
                    $initial = strtoupper(substr($u['full_name'] ?: ($u['username'] ?: 'U'), 0, 1));
                    $roleDetails = match($u['role']) {
                        'owner_admin' => ['label' => 'Owner / Admin', 'cls' => 'bg-danger text-white shadow-2xs', 'icon' => 'bi-shield-shaded'],
                        'manager'     => ['label' => 'Operations Manager', 'cls' => 'bg-primary text-white shadow-2xs', 'icon' => 'bi-person-workspace'],
                        'accountant'  => ['label' => 'Chief Accountant', 'cls' => 'bg-success text-white shadow-2xs', 'icon' => 'bi-cash-coin'],
                        default       => ['label' => 'Staff Worker', 'cls' => 'bg-secondary-subtle text-dark border', 'icon' => 'bi-person']
                    };
                ?>
                    <tr class="user-row-item" data-role="<?= $u['role'] ?>" data-search="<?= strtolower($u['username'] . ' ' . $u['full_name'] . ' ' . $u['email'] . ' ' . $u['phone']) ?>">
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="rounded-circle bg-primary-subtle text-primary fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; font-size: 0.95rem;">
                                    <?= $initial ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.88rem;">
                                        <?= htmlspecialchars($u['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size: 0.65rem;">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small font-monospace" style="font-size: 0.74rem;">@<?= htmlspecialchars($u['username']) ?></div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div class="small fw-semibold text-dark text-truncate" style="max-width: 220px;">
                                <i class="bi bi-envelope text-muted me-1"></i><?= htmlspecialchars($u['email']) ?>
                            </div>
                            <div class="text-muted small" style="font-size: 0.74rem;">
                                <i class="bi bi-telephone text-muted me-1"></i><?= !empty($u['phone']) ? htmlspecialchars($u['phone']) : '-' ?>
                            </div>
                        </td>

                        <td>
                            <span class="badge <?= $roleDetails['cls'] ?> px-2.5 py-1 rounded-pill fw-semibold" style="font-size: 0.72rem;">
                                <i class="bi <?= $roleDetails['icon'] ?> me-1"></i><?= $roleDetails['label'] ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($u['linked_staff_name']): ?>
                                <span class="badge bg-light text-dark border px-2 py-1">
                                    <i class="bi bi-person-badge text-primary me-1"></i><?= htmlspecialchars($u['linked_staff_name']) ?> (<?= $u['staff_code'] ?>)
                                </span>
                            <?php else: ?>
                                <span class="text-muted small" style="font-size: 0.74rem;">— HQ Office User —</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="small text-muted" style="font-size: 0.76rem;">
                                <?= Helper::formatDateTime($u['last_login']) ?>
                            </div>
                        </td>

                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill" style="font-size: 0.72rem;">
                                    <i class="bi bi-check-circle-fill me-1"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 rounded-pill" style="font-size: 0.72rem;">
                                    <i class="bi bi-x-circle-fill me-1"></i> Inactive
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="pe-3 text-end">
                            <div class="d-inline-flex gap-1.5">
                                <button type="button" class="btn btn-xs btn-outline-primary px-2 py-1 shadow-2xs" onclick='editUser(<?= json_encode($u) ?>)' title="Edit User Account & Role">
                                    <i class="bi bi-pencil-square me-1"></i> Edit
                                </button>
                                <?php if (!$isSelf): ?>
                                    <button type="button" class="btn btn-xs btn-outline-danger px-2 py-1 shadow-2xs" onclick="confirmDeleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($u['full_name']), ENT_QUOTES) ?>')" title="Delete User Account">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3. Granular Role & Permission Matrix Reference Guide -->
<div class="card border-0 shadow-2xs rounded-3 bg-white p-3.5 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-shield-check text-success me-1.5"></i>System Role Permissions Matrix
        </h6>
        <span class="text-muted small" style="font-size: 0.72rem;">Built-in Security Access Profiles</span>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <div class="p-2.5 rounded-2 bg-light border h-100">
                <div class="d-flex align-items-center gap-1.5 mb-1.5">
                    <span class="badge bg-danger text-white rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">Owner Admin</span>
                </div>
                <ul class="text-muted small ps-3 mb-0" style="font-size: 0.74rem; line-height: 1.5;">
                    <li>Full unrestricted administrative system control</li>
                    <li>Manage Users, Passwords & System Roles</li>
                    <li>Update Company Profile & Legal Tax IDs</li>
                    <li>Configure Bank & Cash Financial Accounts</li>
                    <li>Access full System Audit Trail Logs</li>
                </ul>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-2.5 rounded-2 bg-light border h-100">
                <div class="d-flex align-items-center gap-1.5 mb-1.5">
                    <span class="badge bg-primary text-white rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">Operations Manager</span>
                </div>
                <ul class="text-muted small ps-3 mb-0" style="font-size: 0.74rem; line-height: 1.5;">
                    <li>Daily Excel Staff Deployment Grid & Drag-Drop</li>
                    <li>Manage Shift Timings, Rules & Venues</li>
                    <li>Record and verify Daily Shift Attendance</li>
                    <li>Manage Staff Workforce Directory</li>
                    <li>Configure Clients, Venues & Rate Cards</li>
                </ul>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-2.5 rounded-2 bg-light border h-100">
                <div class="d-flex align-items-center gap-1.5 mb-1.5">
                    <span class="badge bg-success text-white rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">Chief Accountant</span>
                </div>
                <ul class="text-muted small ps-3 mb-0" style="font-size: 0.74rem; line-height: 1.5;">
                    <li>Generate and issue Client GST Invoices</li>
                    <li>Record Client Payments & Issue Receipts</li>
                    <li>Disburse Staff Wages & Salary Vouchers</li>
                    <li>Log Operating Expenses & General Ledger</li>
                    <li>Generate Profit & Loss (P&L) Statements</li>
                </ul>
            </div>
        </div>

        <div class="col-md-3">
            <div class="p-2.5 rounded-2 bg-light border h-100">
                <div class="d-flex align-items-center gap-1.5 mb-1.5">
                    <span class="badge bg-secondary text-white rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">Staff Worker</span>
                </div>
                <ul class="text-muted small ps-3 mb-0" style="font-size: 0.74rem; line-height: 1.5;">
                    <li>Self-service worker mobile access</li>
                    <li>View assigned Shift Venue and timings</li>
                    <li>Review personal attendance records</li>
                    <li>Check personal Duty Passbook and payouts</li>
                    <li>Restricted from viewing client financials</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" id="modal_u_id" value="">

                <div class="modal-header bg-light py-2.5 px-3.5 border-bottom">
                    <h6 class="modal-title fw-bold text-dark" id="userModalTitle">
                        <i class="bi bi-person-plus text-primary me-1.5"></i> Add New User
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-3.5">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Username *</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">@</span>
                                <input type="text" name="username" id="modal_u_username" class="form-control" placeholder="e.g. aditya_admin" required>
                            </div>
                            <div class="form-text" style="font-size: 0.7rem;">Alphanumeric login identifier, no spaces.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Full Name *</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-person"></i></span>
                                <input type="text" name="full_name" id="modal_u_name" class="form-control" placeholder="e.g. Aditya Kumar" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Email Address *</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="modal_u_email" class="form-control" placeholder="e.g. aditya@kansan.in" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Phone Number</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="phone" id="modal_u_phone" class="form-control" placeholder="e.g. 8051296873">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">System Role & Access Permission *</label>
                            <select name="role" id="modal_u_role" class="form-select form-select-sm" required>
                                <option value="owner_admin">Owner / Super Admin (Full Access)</option>
                                <option value="manager" selected>Operations Manager (Deployment & Attendance)</option>
                                <option value="accountant">Chief Accountant (Billing, Payments & PnL)</option>
                                <option value="staff">Staff Worker (Self-Service Portal)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Account Status *</label>
                            <select name="status" id="modal_u_status" class="form-select form-select-sm" required>
                                <option value="active" selected>Active (Can Log In)</option>
                                <option value="inactive">Inactive (Suspended / Blocked)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Link to Staff Directory Profile</label>
                            <select name="staff_id" id="modal_u_staff_id" class="form-select form-select-sm">
                                <option value="">-- No Linked Staff Profile (HQ User) --</option>
                                <?php foreach ($staffList as $stf): ?>
                                    <option value="<?= $stf['id'] ?>"><?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?> - <?= $stf['phone'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size: 0.7rem;">Optional: Links this login to a physical staff worker for mobile self-service passbook.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-dark">Account Password</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" id="modal_u_pass" class="form-control" placeholder="Enter password (or leave blank to keep existing)">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('modal_u_pass')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text" id="passwordHelpText" style="font-size: 0.7rem;">Leave empty when editing to keep existing password.</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-3 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold shadow-2xs">
                        <i class="bi bi-check2-circle me-1"></i> Save User Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Delete User Confirmation Form -->
<form method="POST" action="" id="deleteUserForm" style="display: none;">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete_user_id_input" value="">
</form>

<script>
const userModal = new bootstrap.Modal(document.getElementById('userModal'));

function openUserModal() {
    document.getElementById('modal_u_id').value = '';
    document.getElementById('modal_u_username').value = '';
    document.getElementById('modal_u_name').value = '';
    document.getElementById('modal_u_email').value = '';
    document.getElementById('modal_u_phone').value = '';
    document.getElementById('modal_u_role').value = 'manager';
    document.getElementById('modal_u_staff_id').value = '';
    document.getElementById('modal_u_status').value = 'active';
    document.getElementById('modal_u_pass').value = '';
    document.getElementById('modal_u_pass').placeholder = 'Enter temporary login password';
    document.getElementById('passwordHelpText').textContent = 'Default strong temporary password will be auto-generated if left blank.';
    document.getElementById('userModalTitle').innerHTML = '<i class="bi bi-person-plus text-primary me-1.5"></i> Add New User';
    userModal.show();
}

function editUser(u) {
    document.getElementById('modal_u_id').value = u.id;
    document.getElementById('modal_u_username').value = u.username;
    document.getElementById('modal_u_name').value = u.full_name;
    document.getElementById('modal_u_email').value = u.email;
    document.getElementById('modal_u_phone').value = u.phone || '';
    document.getElementById('modal_u_role').value = u.role;
    document.getElementById('modal_u_staff_id').value = u.staff_id || '';
    document.getElementById('modal_u_status').value = u.status;
    document.getElementById('modal_u_pass').value = '';
    document.getElementById('modal_u_pass').placeholder = 'Leave empty to keep current password';
    document.getElementById('passwordHelpText').textContent = 'Only enter a new password if you want to reset this user\'s credentials.';
    document.getElementById('userModalTitle').innerHTML = '<i class="bi bi-pencil-square text-primary me-1.5"></i> Edit User: ' + escapeHtml(u.username);
    userModal.show();
}

function confirmDeleteUser(userId, username, fullName) {
    showCustomConfirm({
        title: 'Delete User Account?',
        message: 'Are you sure you want to permanently delete the user account "' + fullName + '" (@' + username + ')? This action cannot be undone.',
        confirmText: 'Delete User',
        confirmBtnClass: 'btn-danger',
        onConfirm: function() {
            document.getElementById('delete_user_id_input').value = userId;
            document.getElementById('deleteUserForm').submit();
        }
    });
}

function togglePasswordVisibility(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.type = (el.type === 'password') ? 'text' : 'password';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Live table search & role filter
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('userFilterSearch');
    const roleFilter = document.getElementById('userRoleFilter');
    const rows = document.querySelectorAll('.user-row-item');
    const countBadge = document.getElementById('userCountBadge');

    function filterTable() {
        const query = (searchInput.value || '').toLowerCase().trim();
        const role = (roleFilter.value || '').trim();
        let visible = 0;

        rows.forEach(row => {
            const rowRole = row.getAttribute('data-role');
            const rowSearch = row.getAttribute('data-search') || '';

            const matchRole = !role || (rowRole === role);
            const matchSearch = !query || rowSearch.includes(query);

            if (matchRole && matchSearch) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        if (countBadge) countBadge.textContent = visible;
    }

    if (searchInput) searchInput.addEventListener('input', filterTable);
    if (roleFilter) roleFilter.addEventListener('change', filterTable);
});
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
