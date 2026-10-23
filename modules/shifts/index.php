<?php
// modules/shifts/index.php - Admin Shift Timing & Configuration
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager']);

$db = DB::getConnection();
$error = '';
$success = '';

// Handle Shift Creation or Update (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isOwnerAdmin()) {
        Helper::setFlash('danger', 'Security Alert: Only Administrator can modify shift timings and configurations.');
        Helper::redirect('modules/shifts/index.php');
    }
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_shift') {
        $shiftId = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
        $shiftCode = strtoupper(trim($_POST['shift_code'] ?? ''));
        $shiftName = trim($_POST['shift_name'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $isOvernight = !empty($_POST['is_overnight']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($shiftCode) || empty($shiftName) || empty($startTime) || empty($endTime)) {
            $error = 'Shift Code, Name, Start Time, and End Time are required.';
        } else {
            // Calculate duration in hours
            $sParts = explode(':', $startTime);
            $eParts = explode(':', $endTime);
            $startMins = (int)$sParts[0] * 60 + (int)($sParts[1] ?? 0);
            $endMins = (int)$eParts[0] * 60 + (int)($eParts[1] ?? 0);

            if ($endMins <= $startMins) {
                $endMins += 24 * 60;
                $isOvernight = 1; // Auto-confirm overnight
            }

            $durationHours = round(($endMins - $startMins) / 60, 2);

            if ($shiftId) {
                // Update
                $stmt = $db->prepare("
                    UPDATE shifts 
                    SET shift_code = :code, shift_name = :name, start_time = :start, 
                        end_time = :end, is_overnight = :overnight, duration_hours = :duration, 
                        description = :desc, status = :status 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':code' => $shiftCode,
                    ':name' => $shiftName,
                    ':start' => $startTime,
                    ':end' => $endTime,
                    ':overnight' => $isOvernight,
                    ':duration' => $durationHours,
                    ':desc' => $description,
                    ':status' => $status,
                    ':id' => $shiftId
                ]);
                Audit::log('UPDATE', 'SHIFTS', $shiftId, null, $_POST);
                Helper::setFlash('success', "Shift '{$shiftName}' updated successfully with {$durationHours} hrs duration.");
            } else {
                // Insert
                $stmt = $db->prepare("
                    INSERT INTO shifts (shift_code, shift_name, start_time, end_time, is_overnight, duration_hours, description, status)
                    VALUES (:code, :name, :start, :end, :overnight, :duration, :desc, :status)
                ");
                $stmt->execute([
                    ':code' => $shiftCode,
                    ':name' => $shiftName,
                    ':start' => $startTime,
                    ':end' => $endTime,
                    ':overnight' => $isOvernight,
                    ':duration' => $durationHours,
                    ':desc' => $description,
                    ':status' => $status
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'SHIFTS', $newId, null, $_POST);
                Helper::setFlash('success', "New Shift '{$shiftName}' added successfully.");
            }
            Helper::redirect('modules/shifts/index.php');
        }
    }
}

// Fetch all shifts
$shifts = $db->query("SELECT * FROM shifts ORDER BY id ASC")->fetchAll();

$pageTitle = 'Shift Configuration & Timings';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('operations', 'shifts') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Shift Configuration & Timings</h4>
        <p class="text-muted small mb-0">Admin-controlled shift configuration. Set exact start/end timings, and configure overnight accounting rules.</p>
    </div>
    <div>
        <?php if (Auth::isOwnerAdmin()): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openShiftModal()">
                <i class="bi bi-plus-lg me-1"></i> Add Custom Shift
            </button>
        <?php else: ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2">
                <i class="bi bi-shield-lock-fill me-1"></i> Admin Protected (Read-Only)
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!Auth::isOwnerAdmin()): ?>
    <div class="alert alert-warning border-warning-subtle bg-warning-subtle text-warning-emphasis d-flex align-items-center gap-2 mb-4 shadow-sm">
        <i class="bi bi-shield-exclamation fs-5"></i>
        <div>
            <strong>Admin-Only Configuration:</strong> Shift timings and operational hours can only be modified by the <strong>Administrator</strong>. You have read-only visibility for roster scheduling and attendance tracking.
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info border-info-subtle bg-info-subtle text-info-emphasis d-flex align-items-start gap-2 mb-4 shadow-sm">
    <i class="bi bi-info-circle-fill fs-5 mt-0.5"></i>
    <div>
        <strong>Overnight Shift Accounting Rule:</strong>
        When a shift crosses midnight (e.g. 23:00 to 07:00 next morning), the system automatically locks it to the scheduled anchor date. All attendance, client billing, staff earnings, and P&L calculations remain firmly attached to the shift's starting date.
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($shifts as $s): ?>
        <div class="col-md-4">
            <div class="custom-card h-100 shadow-sm border">
                <div class="custom-card-header pb-2">
                    <div class="d-flex align-items-center gap-2">
                        <?= Helper::shiftBadge($s['shift_name'], $s['shift_code'], (bool)$s['is_overnight']) ?>
                    </div>
                    <div>
                        <?= Helper::statusBadge($s['status']) ?>
                    </div>
                </div>
                <div class="p-3">
                    <div class="text-muted small mb-1">Shift Code: <strong class="text-dark"><?= htmlspecialchars($s['shift_code']) ?></strong></div>
                    
                    <div class="d-flex align-items-center justify-content-between my-3 p-2 bg-light rounded-3 border">
                        <div>
                            <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">Start Time</div>
                            <div class="fw-bold text-dark fs-6"><?= date('h:i A', strtotime($s['start_time'])) ?></div>
                            <small class="text-secondary"><?= $s['start_time'] ?></small>
                        </div>
                        <div class="text-center px-2">
                            <i class="bi bi-arrow-right fs-5 text-muted"></i>
                            <div class="badge bg-secondary-subtle text-dark" style="font-size:0.7rem;"><?= $s['duration_hours'] ?> hrs</div>
                        </div>
                        <div class="text-end">
                            <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase;">End Time</div>
                            <div class="fw-bold text-dark fs-6"><?= date('h:i A', strtotime($s['end_time'])) ?></div>
                            <small class="text-secondary"><?= $s['end_time'] ?></small>
                        </div>
                    </div>

                    <div class="small text-secondary mb-3">
                        <i class="bi bi-card-text me-1"></i> <?= htmlspecialchars($s['description'] ?? 'Standard operational shift') ?>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if (Auth::isOwnerAdmin()): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                                    onclick='editShift(<?= json_encode($s) ?>)'>
                                <i class="bi bi-pencil-square me-1"></i> Edit Timing & Settings
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-light btn-sm w-100 text-muted border" disabled>
                                <i class="bi bi-lock-fill me-1"></i> Timings Locked (Admin Only)
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Shift Modal Form -->
<div class="modal fade" id="shiftModal" tabindex="-1" aria-labelledby="shiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_shift">
                <input type="hidden" name="shift_id" id="modal_shift_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="shiftModalLabel">Configure Shift Timings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shift Code</label>
                            <input type="text" name="shift_code" id="modal_shift_code" class="form-control" placeholder="SHIFT-1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Shift Name</label>
                            <input type="text" name="shift_name" id="modal_shift_name" class="form-control" placeholder="Morning Shift" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Start Time (24h)</label>
                            <input type="time" name="start_time" id="shift_start_time" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">End Time (24h)</label>
                            <input type="time" name="end_time" id="shift_end_time" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Duration (Hours)</label>
                            <input type="number" step="0.25" name="duration_hours" id="shift_duration_hours" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="modal_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch p-3 bg-light rounded border">
                                <input class="form-check-input ms-0 me-2" type="checkbox" name="is_overnight" id="shift_is_overnight" value="1">
                                <label class="form-check-label fw-semibold text-dark" for="shift_is_overnight">
                                    Overnight Shift (Ends on the Next Calendar Day)
                                </label>
                                <div class="form-text mt-1 text-muted" style="font-size:0.75rem;">
                                    When checked, check-outs that occur the next morning are automatically connected to the original deployment date.
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Description / Notes</label>
                            <textarea name="description" id="modal_description" class="form-control" rows="2" placeholder="Operations coverage details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Shift Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const shiftModal = new bootstrap.Modal(document.getElementById('shiftModal'));

function openShiftModal() {
    document.getElementById('modal_shift_id').value = '';
    document.getElementById('modal_shift_code').value = 'SHIFT-' + Math.floor(Math.random() * 900 + 100);
    document.getElementById('modal_shift_name').value = '';
    document.getElementById('shift_start_time').value = '08:00';
    document.getElementById('shift_end_time').value = '16:00';
    document.getElementById('shift_duration_hours').value = '8.00';
    document.getElementById('shift_is_overnight').checked = false;
    document.getElementById('modal_description').value = '';
    document.getElementById('modal_status').value = 'active';
    document.getElementById('shiftModalLabel').textContent = 'Add Custom Shift';
    shiftModal.show();
}

function editShift(shift) {
    document.getElementById('modal_shift_id').value = shift.id;
    document.getElementById('modal_shift_code').value = shift.shift_code;
    document.getElementById('modal_shift_name').value = shift.shift_name;
    document.getElementById('shift_start_time').value = shift.start_time.substring(0, 5);
    document.getElementById('shift_end_time').value = shift.end_time.substring(0, 5);
    document.getElementById('shift_duration_hours').value = shift.duration_hours;
    document.getElementById('shift_is_overnight').checked = parseInt(shift.is_overnight) === 1;
    document.getElementById('modal_description').value = shift.description || '';
    document.getElementById('modal_status').value = shift.status;
    document.getElementById('shiftModalLabel').textContent = 'Edit Shift: ' + shift.shift_name;
    shiftModal.show();
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
