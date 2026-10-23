<?php
// modules/settings/users.php - User Accounts & Role Permissions
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin']);

$db = DB::getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $staffId = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;
        $status = $_POST['status'] ?? 'active';
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($email) || empty($fullName)) {
            $error = 'Username, Email, and Full Name are required.';
        } elseif (!empty($password) && !Security::validatePasswordStrength($password)['valid']) {
            $error = Security::validatePasswordStrength($password)['error'];
        } else {
            if ($userId) {
                // Update
                $sql = "UPDATE users SET username=:un, email=:em, full_name=:fn, phone=:ph, role=:ro, staff_id=:stfid, status=:st";
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
                Audit::log('UPDATE', 'USERS', $userId, null, ['username' => $username, 'role' => $role]);
                Helper::setFlash('success', "User '{$username}' updated successfully.");
            } else {
                // Insert
                $initialPassword = !empty($password) ? $password : bin2hex(random_bytes(4)) . 'A1';
                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, phone, role, staff_id, status)
                    VALUES (:un, :em, :phash, :fn, :ph, :ro, :stfid, :st)
                ");
                $stmt->execute([
                    ':un'    => $username,
                    ':em'    => $email,
                    ':phash' => Security::hashPassword($initialPassword),
                    ':fn'    => $fullName,
                    ':ph'    => $phone,
                    ':ro'    => $role,
                    ':stfid' => $staffId,
                    ':st'    => $status
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'USERS', $newId, null, ['username' => $username, 'role' => $role]);
                $pwMsg = empty($password) ? " (Temporary Password: {$initialPassword})" : "";
                Helper::setFlash('success', "User '{$username}' created successfully{$pwMsg}.");
            }
            Helper::redirect('modules/settings/users.php');
        }
    }
}

// Fetch users
$users = $db->query("
    SELECT u.*, s.full_name as linked_staff_name, s.staff_code
    FROM users u
    LEFT JOIN staff s ON u.staff_id = s.id
    ORDER BY u.id ASC
")->fetchAll();

// Fetch staff for dropdown
$staffList = $db->query("SELECT id, full_name, staff_code FROM staff WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$pageTitle = 'User Accounts & Access Control';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-person-gear text-primary me-2"></i>User Accounts & Roles</h4>
        <p class="text-muted small mb-0">Manage login credentials and granular permissions for Owner/Admin, Managers, Accountants, and Staff.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openUserModal()">
            <i class="bi bi-plus-lg me-1"></i> Add New User
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle">
            <thead>
                <tr>
                    <th>Username & Full Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Linked Staff Worker</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">@<?= htmlspecialchars($u['username']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <?php
                                $roleBadge = match($u['role']) {
                                    'owner_admin' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                    'manager'     => 'bg-primary-subtle text-primary border border-primary-subtle',
                                    'accountant'  => 'bg-success-subtle text-success border border-success-subtle',
                                    default       => 'bg-secondary-subtle text-secondary border'
                                };
                            ?>
                            <span class="badge <?= $roleBadge ?> text-capitalize px-2 py-1 rounded-pill">
                                <?= str_replace('_', ' ', $u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['linked_staff_name']): ?>
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-link me-1"></i><?= htmlspecialchars($u['linked_staff_name']) ?> (<?= $u['staff_code'] ?>)
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">None (HQ Staff)</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= Helper::formatDateTime($u['last_login']) ?></small></td>
                        <td><?= Helper::statusBadge($u['status']) ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='editUser(<?= json_encode($u) ?>)'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" id="modal_u_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="userModalTitle">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username *</label>
                            <input type="text" name="username" id="modal_u_username" class="form-control" placeholder="johndoe" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name *</label>
                            <input type="text" name="full_name" id="modal_u_name" class="form-control" placeholder="John Doe" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email Address *</label>
                            <input type="email" name="email" id="modal_u_email" class="form-control" placeholder="john@kansan.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone</label>
                            <input type="text" name="phone" id="modal_u_phone" class="form-control" placeholder="Phone number">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Role & Access Level *</label>
                            <select name="role" id="modal_u_role" class="form-select" required>
                                <option value="owner_admin">Owner / Super Admin</option>
                                <option value="manager">Operations Manager</option>
                                <option value="accountant">Chief Accountant</option>
                                <option value="staff">Staff Worker (Self-Service)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="modal_u_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Link to Staff Directory Profile (Optional)</label>
                            <select name="staff_id" id="modal_u_staff_id" class="form-select">
                                <option value="">-- No Linked Staff Profile (HQ User) --</option>
                                <?php foreach ($staffList as $stf): ?>
                                    <option value="<?= $stf['id'] ?>"><?= htmlspecialchars($stf['full_name']) ?> (<?= $stf['staff_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:0.72rem;">If this user is a staff worker, link their profile to enable self-service attendance & passbook.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Password</label>
                            <input type="password" name="password" id="modal_u_pass" class="form-control" placeholder="Leave empty to keep existing password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
    document.getElementById('modal_u_pass').placeholder = 'Enter login password';
    document.getElementById('userModalTitle').textContent = 'Add New User';
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
    document.getElementById('modal_u_pass').placeholder = 'Leave empty to keep current password';
    document.getElementById('userModalTitle').textContent = 'Edit User: ' + u.username;
    userModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
