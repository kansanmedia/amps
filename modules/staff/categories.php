<?php
// modules/staff/categories.php - Staff Category Master & Role Designation Definition
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager', 'accountant']);

$db = DB::getConnection();
$error = '';

// Handle Create / Update / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_category') {
        $catId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $catCode = strtoupper(trim($_POST['category_code'] ?? ''));
        $catName = trim($_POST['category_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($catName)) {
            $error = 'Category / Role Name is required.';
        } else {
            if (empty($catCode)) {
                $words = explode(' ', preg_replace('/[^a-zA-Z0-9\s]/', '', $catName));
                $prefix = '';
                foreach ($words as $w) {
                    if (!empty($w)) $prefix .= strtoupper(substr($w, 0, 1));
                    if (strlen($prefix) >= 3) break;
                }
                $catCode = 'CAT-' . (strlen($prefix) >= 2 ? $prefix : 'GEN');
            }

            // Check duplicate code
            $codeCheck = $db->prepare("SELECT id FROM staff_categories WHERE category_code = :c AND (:id IS NULL OR id != :id)");
            $codeCheck->execute([':c' => $catCode, ':id' => $catId]);
            if ($codeCheck->fetch()) {
                $error = "A category with code '{$catCode}' already exists.";
            } else {
                if ($catId) {
                    $stmt = $db->prepare("
                        UPDATE staff_categories 
                        SET category_code = :cc, category_name = :cn, description = :desc, status = :st
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':cc' => $catCode, ':cn' => $catName, ':desc' => $desc, ':st' => $status, ':id' => $catId
                    ]);
                    Audit::log('UPDATE', 'STAFF_CATEGORIES', $catId, null, $_POST);
                    Helper::setFlash('success', "Category '{$catName}' updated successfully.");
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO staff_categories (category_code, category_name, description, status)
                        VALUES (:cc, :cn, :desc, :st)
                    ");
                    $stmt->execute([
                        ':cc' => $catCode, ':cn' => $catName, ':desc' => $desc, ':st' => $status
                    ]);
                    $newId = (int)$db->lastInsertId();
                    Audit::log('CREATE', 'STAFF_CATEGORIES', $newId, null, $_POST);
                    Helper::setFlash('success', "Staff Category '{$catName}' registered successfully.");
                }
                Helper::redirect('modules/staff/categories.php');
            }
        }
    } elseif ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        if ($catId) {
            // Check if any staff are assigned
            $check = $db->prepare("SELECT COUNT(*) FROM staff WHERE category_id = :id");
            $check->execute([':id' => $catId]);
            $staffCount = (int)$check->fetchColumn();

            // Check if rate cards use it
            $rcCheck = $db->prepare("SELECT COUNT(*) FROM rate_cards WHERE category_id = :id");
            $rcCheck->execute([':id' => $catId]);
            $rcCount = (int)$rcCheck->fetchColumn();

            if ($staffCount > 0 || $rcCount > 0) {
                Helper::setFlash('danger', "Cannot delete category: {$staffCount} staff member(s) and {$rcCount} client rate rule(s) are linked. Deactivate it instead.");
            } else {
                $stmt = $db->prepare("DELETE FROM staff_categories WHERE id = :id");
                $stmt->execute([':id' => $catId]);
                Audit::log('DELETE', 'STAFF_CATEGORIES', $catId, null, null);
                Helper::setFlash('info', 'Staff Category deleted successfully.');
            }
            Helper::redirect('modules/staff/categories.php');
        }
    }
}

// Fetch categories with staff count and rate card count
$categories = $db->query("
    SELECT sc.*,
           (SELECT COUNT(s.id) FROM staff s WHERE s.category_id = sc.id) as staff_count,
           (SELECT COUNT(rc.id) FROM rate_cards rc WHERE rc.category_id = sc.id) as rate_card_count
    FROM staff_categories sc
    ORDER BY sc.id ASC
")->fetchAll();

// Statistics
$totalCategories = count($categories);
$activeCategories = count(array_filter($categories, fn($c) => $c['status'] === 'active'));
$totalStaffLinked = array_sum(array_column($categories, 'staff_count'));
$totalClientRules = array_sum(array_column($categories, 'rate_card_count'));

$pageTitle = 'Staff Categories & Roles';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('directory', 'categories') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-person-badge-fill text-primary me-2"></i>Staff Categories & Role Master</h4>
        <p class="text-muted small mb-0">Define staff designations and operational job roles. Billing and wage rates are configured client-wise.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/rates/index.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-tags-fill me-1"></i> Client Rate Cards Setup
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCategoryModal()">
            <i class="bi bi-plus-lg me-1"></i> Add New Category
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Business Info Alert -->
<div class="alert alert-primary border-primary-subtle bg-primary-subtle text-primary-emphasis d-flex align-items-start gap-2 mb-4 shadow-sm">
    <i class="bi bi-info-circle-fill fs-5 mt-0.5"></i>
    <div>
        <strong>Client-Specific Pricing Model:</strong>
        Staff categories represent employee designations and skill sets (e.g. <em>Senior Steward / Waiter</em>, <em>Bartender</em>, <em>Captain</em>, <em>Housekeeping</em>).
        Billing rates and staff payout wages are configured <strong>client-wise</strong> under <a href="<?= BASE_URL ?>/modules/rates/index.php" class="fw-bold text-decoration-underline">Client Rate Cards</a>, so you can agree upon different rates for different hotels, clubs, and venues.
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Total Categories</div>
            <div class="metric-value text-dark"><?= $totalCategories ?></div>
            <div class="text-muted small mt-1"><?= $activeCategories ?> active for deployments</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Active Staff Mapped</div>
            <div class="metric-value text-primary"><?= $totalStaffLinked ?></div>
            <div class="text-muted small mt-1">Classified workforce members</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Client Rate Contracts</div>
            <div class="metric-value text-success"><?= $totalClientRules ?></div>
            <div class="text-muted small mt-1">Client-specific pricing agreements</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="metric-card">
            <div class="metric-label">Quick Actions</div>
            <div class="d-flex flex-column gap-1 mt-2">
                <a href="<?= BASE_URL ?>/modules/rates/index.php" class="small text-primary fw-semibold"><i class="bi bi-tags me-1"></i> Setup Client Rates</a>
                <a href="<?= BASE_URL ?>/modules/staff/index.php" class="small text-primary fw-semibold"><i class="bi bi-people me-1"></i> Staff Directory</a>
            </div>
        </div>
    </div>
</div>

<!-- Category List Table -->
<div class="custom-card">
    <div class="custom-card-header">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold mb-0">Categories Directory</h6>
            <span class="badge bg-light text-secondary border font-monospace"><?= $totalCategories ?> Roles</span>
        </div>
        <div>
            <input type="text" class="form-control form-control-sm" placeholder="Filter categories..." data-table-search="#categoryTable" style="width: 220px;">
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="categoryTable">
            <thead>
                <tr>
                    <th class="col-row-num text-center">#</th>
                    <th style="width: 130px;">Category Code</th>
                    <th>Category / Role Designation</th>
                    <th>Description / Job Scope</th>
                    <th class="text-center" style="width: 140px;">Mapped Staff</th>
                    <th class="text-center" style="width: 160px;">Client Pricing Rules</th>
                    <th class="text-center" style="width: 110px;">Status</th>
                    <th class="text-end" style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-person-badge fs-1 d-block mb-2 text-secondary"></i>
                            No staff categories registered yet. Click <strong>Add New Category</strong> to define your job designations.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $rowNum = 1; foreach ($categories as $cat): ?>
                        <tr>
                            <td class="col-row-num text-center"><?= $rowNum++ ?></td>
                            <td>
                                <span class="badge bg-dark-subtle text-dark font-monospace px-2 py-1"><?= htmlspecialchars($cat['category_code']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($cat['category_name']) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($cat['description'])): ?>
                                    <div class="text-muted small"><?= htmlspecialchars($cat['description']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">No description provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/modules/staff/index.php" class="badge bg-light text-dark border text-decoration-none px-2 py-1">
                                    <i class="bi bi-people me-1 text-primary"></i> <?= $cat['staff_count'] ?> Staff
                                </a>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/modules/rates/index.php" class="badge bg-info-subtle text-info-emphasis border text-decoration-none px-2 py-1" title="Manage agreed client rates for this category">
                                    <i class="bi bi-tags me-1"></i> <?= $cat['rate_card_count'] ?> Client Rules
                                </a>
                            </td>
                            <td class="text-center"><?= Helper::statusBadge($cat['status']) ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2" onclick='editCategory(<?= json_encode($cat) ?>)' title="Edit Category">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="" class="d-inline" data-confirm="Delete category &ldquo;<?= htmlspecialchars($cat['category_name']) ?>&rdquo;? Note: Cannot delete if staff or client rates are linked." data-confirm-title="Delete Category" data-confirm-btn="btn-danger">
                                        <?= CSRF::tokenField() ?>
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Category">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($categories)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td class="col-row-num text-center">&Sigma;</td>
                    <td colspan="3" class="text-uppercase text-muted" style="font-size: 0.72rem;">
                        Total: <strong><?= $totalCategories ?></strong> Job Designations Defined
                    </td>
                    <td class="text-center text-primary" style="font-size: 0.80rem;">
                        <?= $totalStaffLinked ?> Staff
                    </td>
                    <td class="text-center text-info-emphasis" style="font-size: 0.80rem;">
                        <?= $totalClientRules ?> Contracts
                    </td>
                    <td colspan="2" class="text-end text-muted small" style="font-size: 0.70rem;">
                        Rates managed client-wise
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" id="modal_cat_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="catModalTitle">Add Staff Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Category Code</label>
                            <input type="text" name="category_code" id="modal_cat_code" class="form-control text-uppercase" placeholder="e.g. CAT-SER">
                            <div class="form-text" style="font-size:0.72rem;">Unique identifier code</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Category / Role Name *</label>
                            <input type="text" name="category_name" id="modal_cat_name" class="form-control" placeholder="e.g. Service / Waiter" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Description / Duties (Optional)</label>
                            <textarea name="description" id="modal_cat_desc" class="form-control" rows="3" placeholder="Brief job scope, responsibilities, required uniform or skills..."></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="modal_cat_status" class="form-select">
                                <option value="active">Active (Available for staff & client contracts)</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));

function openCategoryModal() {
    document.getElementById('modal_cat_id').value = '';
    document.getElementById('modal_cat_code').value = '';
    document.getElementById('modal_cat_name').value = '';
    document.getElementById('modal_cat_desc').value = '';
    document.getElementById('modal_cat_status').value = 'active';
    document.getElementById('catModalTitle').textContent = 'Add New Staff Category';
    categoryModal.show();
}

function editCategory(cat) {
    document.getElementById('modal_cat_id').value = cat.id;
    document.getElementById('modal_cat_code').value = cat.category_code;
    document.getElementById('modal_cat_name').value = cat.category_name;
    document.getElementById('modal_cat_desc').value = cat.description || '';
    document.getElementById('modal_cat_status').value = cat.status;
    document.getElementById('catModalTitle').textContent = 'Edit Staff Category';
    categoryModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
