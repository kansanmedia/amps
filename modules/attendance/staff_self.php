<?php
// modules/attendance/staff_self.php - Staff Self-Service Mobile Check-In & Check-Out
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/AttendanceService.php';
Auth::requireLogin();

$user = Auth::user();
$staffId = $user['staff_id'] ?? null;

if (!$staffId) {
    if (Auth::isManager()) {
        Helper::setFlash('info', 'Viewing staff check-in portal. Please select a staff member from directory or log in as staff.');
        Helper::redirect('modules/attendance/index.php');
    } else {
        die("No linked staff profile found for this user account.");
    }
}

$db = DB::getConnection();
$today = date('Y-m-d');
$error = '';
$success = '';

// Handle Self Check-In or Check-Out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';
    $attId = (int)($_POST['attendance_id'] ?? 0);
    $lat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $lng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    if ($attId) {
        // Security Hardening: BOLA / IDOR Protection (Prevent tampering with other workers' shifts)
        $verifyStmt = $db->prepare("SELECT id, staff_id FROM attendance WHERE id = :id AND staff_id = :stfid");
        $verifyStmt->execute([':id' => $attId, ':stfid' => $staffId]);
        if (!$verifyStmt->fetch()) {
            http_response_code(403);
            Helper::setFlash('danger', 'Security Authorization Violation: You are not authorized to modify attendance for another staff member.');
            Helper::redirect('modules/attendance/staff_self.php');
        }

        if ($action === 'self_check_in') {
            AttendanceService::processAttendance($attId, [
                'status'         => 'present',
                'check_in_time'  => date('Y-m-d H:i:s'),
                'check_in_lat'   => $lat,
                'check_in_lng'   => $lng,
                'remarks'        => 'Self Check-in via mobile'
            ]);
            Helper::setFlash('success', 'Checked in successfully! Have a great shift.');
            Helper::redirect('modules/attendance/staff_self.php');
        } elseif ($action === 'self_check_out') {
            // Keep existing check-in time
            $cStmt = $db->prepare("SELECT check_in_time, check_in_lat, check_in_lng FROM attendance WHERE id = :id");
            $cStmt->execute([':id' => $attId]);
            $curr = $cStmt->fetch();

            AttendanceService::processAttendance($attId, [
                'status'         => 'present',
                'check_in_time'  => $curr['check_in_time'] ?? date('Y-m-d H:i:s', strtotime('-8 hours')),
                'check_in_lat'   => $curr['check_in_lat'],
                'check_in_lng'   => $curr['check_in_lng'],
                'check_out_time' => date('Y-m-d H:i:s'),
                'check_out_lat'  => $lat,
                'check_out_lng'  => $lng,
                'remarks'        => 'Self Check-out via mobile'
            ]);
            Helper::setFlash('success', 'Checked out successfully! Shift completed.');
            Helper::redirect('modules/attendance/staff_self.php');
        }
    }
}

// Fetch active assignments for Today and Tomorrow
$stmt = $db->prepare("
    SELECT a.*, d.deployment_date, d.staff_rate,
           c.company_name, s.site_name, s.location_address, s.latitude as site_lat, s.longitude as site_lng, s.geofence_radius_meters,
           sh.shift_name, sh.shift_code, sh.start_time, sh.end_time, sh.is_overnight, sh.duration_hours
    FROM attendance a
    JOIN deployments d ON a.deployment_id = d.id
    JOIN clients c ON a.client_id = c.id
    JOIN sites s ON a.site_id = s.id
    JOIN shifts sh ON a.shift_id = sh.id
    WHERE a.staff_id = :stfid AND d.deployment_date >= :dt AND d.status != 'cancelled'
    ORDER BY d.deployment_date ASC, sh.start_time ASC
");
$stmt->execute([':stfid' => $staffId, ':dt' => date('Y-m-d', strtotime('-1 day'))]);
$myShifts = $stmt->fetchAll();

$pageTitle = 'My Shifts & Mobile Check-in';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-primary me-2"></i>My Shifts & Mobile Check-In</h4>
        <p class="text-muted small mb-0">View scheduled venue assignments and tap to check-in/check-out with GPS verification.</p>
    </div>
</div>

<?php if (empty($myShifts)): ?>
    <div class="custom-card text-center py-5">
        <i class="bi bi-calendar-check fs-1 text-muted d-block mb-3"></i>
        <h5 class="fw-bold text-dark">No Active Shifts Assigned</h5>
        <p class="text-muted small mb-0">You currently have no scheduled shifts for today. Please contact your operations supervisor.</p>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($myShifts as $sh): 
            $isToday = ($sh['deployment_date'] === $today);
            $isCheckedIn = !empty($sh['check_in_time']);
            $isCheckedOut = !empty($sh['check_out_time']);
        ?>
            <div class="col-md-6">
                <div class="custom-card shadow-sm border <?= $isToday ? 'border-primary' : '' ?>">
                    <div class="custom-card-header <?= $isToday ? 'bg-primary text-white' : '' ?>">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-calendar-event fs-5"></i>
                            <div>
                                <strong class="d-block <?= $isToday ? 'text-white' : 'text-dark' ?>">
                                    <?= date('d M Y (l)', strtotime($sh['deployment_date'])) ?>
                                </strong>
                                <small class="<?= $isToday ? 'text-white-50' : 'text-muted' ?>" style="font-size:0.75rem;">
                                    <?= $isToday ? 'TODAY\'S ASSIGNMENT' : 'UPCOMING SHIFT' ?>
                                </small>
                            </div>
                        </div>
                        <div>
                            <?= Helper::shiftBadge($sh['shift_name'], $sh['shift_code'], (bool)$sh['is_overnight']) ?>
                        </div>
                    </div>

                    <div class="p-4">
                        <div class="mb-3">
                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($sh['site_name']) ?></h5>
                            <div class="text-primary fw-semibold small mb-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($sh['company_name']) ?></div>
                            <div class="text-muted small p-2.5 bg-light rounded border">
                                <i class="bi bi-pin-map-fill text-danger me-1"></i> <?= htmlspecialchars($sh['location_address']) ?>
                            </div>
                        </div>

                        <div class="row g-2 text-center my-3 p-2.5 bg-light rounded-3 border">
                            <div class="col-4">
                                <div class="text-muted small" style="font-size:0.7rem;">SHIFT TIMING</div>
                                <strong class="text-dark small"><?= date('h:i A', strtotime($sh['start_time'])) ?></strong>
                            </div>
                            <div class="col-4 border-start border-end">
                                <div class="text-muted small" style="font-size:0.7rem;">DURATION</div>
                                <strong class="text-dark small"><?= $sh['duration_hours'] ?> hrs</strong>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small" style="font-size:0.7rem;">MY PAYOUT</div>
                                <strong class="text-success small"><?= Helper::formatCurrency($sh['staff_rate']) ?></strong>
                            </div>
                        </div>

                        <?php if ($sh['is_overnight']): ?>
                            <div class="alert alert-warning py-1.5 px-2.5 small mb-3">
                                <i class="bi bi-moon-stars me-1"></i> <strong>Overnight Shift:</strong> Completes tomorrow morning at <?= date('h:i A', strtotime($sh['end_time'])) ?>.
                            </div>
                        <?php endif; ?>

                        <!-- Check-in / Check-out Status & Actions -->
                        <div class="pt-2 border-top">
                            <?php if (!$isCheckedIn): ?>
                                <form method="POST" action="" onsubmit="return handleCheckIn(this);">
                                    <?= CSRF::tokenField() ?>
                                    <input type="hidden" name="action" value="self_check_in">
                                    <input type="hidden" name="attendance_id" value="<?= $sh['id'] ?>">
                                    <input type="hidden" name="latitude" id="in_lat_<?= $sh['id'] ?>">
                                    <input type="hidden" name="longitude" id="in_lng_<?= $sh['id'] ?>">

                                    <div id="gps_msg_<?= $sh['id'] ?>" class="small text-muted mb-2"></div>

                                    <button type="submit" class="btn btn-primary w-100 py-2.5 fw-bold">
                                        <i class="bi bi-box-arrow-in-right me-1"></i> Tap to Check-In with GPS
                                    </button>
                                </form>
                            <?php elseif ($isCheckedIn && !$isCheckedOut): ?>
                                <div class="alert alert-success py-2 small mb-3">
                                    <i class="bi bi-check-circle-fill me-1"></i> Checked In at <strong><?= date('h:i A', strtotime($sh['check_in_time'])) ?></strong>
                                </div>

                                <form method="POST" action="" onsubmit="return handleCheckOut(this);">
                                    <?= CSRF::tokenField() ?>
                                    <input type="hidden" name="action" value="self_check_out">
                                    <input type="hidden" name="attendance_id" value="<?= $sh['id'] ?>">
                                    <input type="hidden" name="latitude" id="out_lat_<?= $sh['id'] ?>">
                                    <input type="hidden" name="longitude" id="out_lng_<?= $sh['id'] ?>">

                                    <button type="submit" class="btn btn-outline-danger w-100 py-2.5 fw-bold">
                                        <i class="bi bi-box-arrow-right me-1"></i> Tap to Check-Out & Finish Shift
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-secondary py-2 small text-center mb-0">
                                    <i class="bi bi-check2-all text-success fs-5 me-1"></i> Shift Completed & Logged!
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        In: <?= date('h:i A', strtotime($sh['check_in_time'])) ?> &bull; Out: <?= date('h:i A', strtotime($sh['check_out_time'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function handleCheckIn(form) {
    const attId = form.querySelector('input[name="attendance_id"]').value;
    const msgEl = document.getElementById('gps_msg_' + attId);
    if (msgEl) msgEl.innerHTML = 'Acquiring GPS location...';

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition((pos) => {
            document.getElementById('in_lat_' + attId).value = pos.coords.latitude.toFixed(8);
            document.getElementById('in_lng_' + attId).value = pos.coords.longitude.toFixed(8);
            form.submit();
        }, (err) => {
            // Proceed even if GPS failed, supervisor will verify
            form.submit();
        }, { enableHighAccuracy: true, timeout: 5000 });
        return false;
    }
    return true;
}

function handleCheckOut(form) {
    const attId = form.querySelector('input[name="attendance_id"]').value;
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition((pos) => {
            document.getElementById('out_lat_' + attId).value = pos.coords.latitude.toFixed(8);
            document.getElementById('out_lng_' + attId).value = pos.coords.longitude.toFixed(8);
            form.submit();
        }, (err) => {
            form.submit();
        }, { enableHighAccuracy: true, timeout: 5000 });
        return false;
    }
    return true;
}
</script>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
