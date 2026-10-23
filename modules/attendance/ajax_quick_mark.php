<?php
// modules/attendance/ajax_quick_mark.php - Instant 1-Click Attendance Toggle Endpoint
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/AttendanceService.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// Handle Bulk Mark All Present for a Site
if ($action === 'mark_site_all_present') {
    $siteId = (int)($_POST['site_id'] ?? $_GET['site_id'] ?? 0);
    $date = trim($_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'));

    if (!$siteId || !$date) {
        echo json_encode(['success' => false, 'message' => 'Site ID and Date are required']);
        exit;
    }

    if (strtotime($date) > strtotime(date('Y-m-d'))) {
        echo json_encode(['success' => false, 'message' => 'Cannot mark attendance for future date (' . date('d/m/Y', strtotime($date)) . '). Attendance can only be marked on or before today.']);
        exit;
    }

    try {
        $db = DB::getConnection();
        $deps = $db->prepare("
            SELECT d.*, s.duration_hours
            FROM deployments d
            JOIN shifts s ON d.shift_id = s.id
            WHERE d.site_id = :sid AND d.deployment_date = :dt AND d.status != 'cancelled'
        ");
        $deps->execute([':sid' => $siteId, ':dt' => $date]);
        $depList = $deps->fetchAll();

        if (empty($depList)) {
            echo json_encode(['success' => false, 'message' => 'No scheduled staff found for this site on this date.']);
            exit;
        }

        $markedDeployments = [];
        foreach ($depList as $dep) {
            $did = (int)$dep['id'];
            // Check attendance
            $attStmt = $db->prepare("SELECT id, is_billed, is_settled FROM attendance WHERE deployment_id = :did");
            $attStmt->execute([':did' => $did]);
            $att = $attStmt->fetch();

            $attId = $att ? (int)$att['id'] : 0;

            if ($att && ($att['is_billed'] || $att['is_settled'])) {
                continue; // Skip locked records
            }

            if (!$attId) {
                $dur = (float)($dep['duration_hours'] ?: 8.00);
                $insStmt = $db->prepare("
                    INSERT INTO attendance (deployment_id, attendance_date, staff_id, client_id, site_id, shift_id,
                                            status, hours_worked, billable_rate_multiplier, final_client_amount,
                                            final_staff_amount, is_billed, is_settled, supervisor_verified)
                    VALUES (:did, :dt, :stfid, :cid, :sid, :shid, 'present', :hrs, 1.00, :camt, :samt, 0, 0, 1)
                ");
                $insStmt->execute([
                    ':did'   => $did,
                    ':dt'    => $dep['deployment_date'],
                    ':stfid' => $dep['staff_id'],
                    ':cid'   => $dep['client_id'],
                    ':sid'   => $dep['site_id'],
                    ':shid'  => $dep['shift_id'],
                    ':hrs'   => $dur,
                    ':camt'  => $dep['client_rate'],
                    ':samt'  => $dep['staff_rate']
                ]);
                $attId = (int)$db->lastInsertId();
            }

            AttendanceService::processAttendance($attId, [
                'status' => 'present',
                'remarks' => 'Bulk marked Present via Site Hub'
            ]);

            $markedDeployments[] = [
                'deployment_id' => $did,
                'attendance_id' => $attId,
                'status' => 'present',
                'badge_html' => Helper::statusBadge('present')
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($markedDeployments),
            'marked' => $markedDeployments,
            'message' => "Successfully marked " . count($markedDeployments) . " staff members Present!"
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$attendanceId = (int)($_POST['attendance_id'] ?? $_GET['attendance_id'] ?? 0);
$deploymentId = (int)($_POST['deployment_id'] ?? $_GET['deployment_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? $_GET['status'] ?? '');

if ((!$attendanceId && !$deploymentId) || !in_array($newStatus, ['scheduled', 'present', 'half_day', 'absent', 'leave'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = DB::getConnection();

    if (!$attendanceId && $deploymentId) {
        $findStmt = $db->prepare("SELECT id FROM attendance WHERE deployment_id = :did LIMIT 1");
        $findStmt->execute([':did' => $deploymentId]);
        $attendanceId = (int)$findStmt->fetchColumn();
        
        // If still not found, create from deployment
        if (!$attendanceId) {
            $depStmt = $db->prepare("SELECT * FROM deployments WHERE id = :did");
            $depStmt->execute([':did' => $deploymentId]);
            $dep = $depStmt->fetch();
            if ($dep) {
                $insStmt = $db->prepare("
                    INSERT INTO attendance (deployment_id, attendance_date, staff_id, client_id, site_id, shift_id,
                                            status, hours_worked, billable_rate_multiplier, final_client_amount,
                                            final_staff_amount, is_billed, is_settled, supervisor_verified)
                    VALUES (:did, :dt, :stfid, :cid, :sid, :shid, 'scheduled', 0.00, 0.00, 0.00, 0.00, 0, 0, 0)
                ");
                $insStmt->execute([
                    ':did'   => $deploymentId,
                    ':dt'    => $dep['deployment_date'],
                    ':stfid' => $dep['staff_id'],
                    ':cid'   => $dep['client_id'],
                    ':sid'   => $dep['site_id'],
                    ':shid'  => $dep['shift_id']
                ]);
                $attendanceId = (int)$db->lastInsertId();
            }
        }
    }

    if (!$attendanceId) {
        echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
        exit;
    }

    // Check if already billed or settled
    $checkStmt = $db->prepare("SELECT is_billed, is_settled FROM attendance WHERE id = :id");
    $checkStmt->execute([':id' => $attendanceId]);
    $rec = $checkStmt->fetch();
    if (!$rec) {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
        exit;
    }
    if ($rec['is_billed'] || $rec['is_settled']) {
        echo json_encode(['success' => false, 'message' => 'Cannot change status: Already invoiced or wage settled.']);
        exit;
    }

    // Future date validation: Disallow marking Present or Half-Day for future dates
    if (in_array($newStatus, ['present', 'half_day'])) {
        $chkDateStmt = $db->prepare("SELECT a.attendance_date, d.deployment_date FROM attendance a LEFT JOIN deployments d ON a.deployment_id = d.id WHERE a.id = :id");
        $chkDateStmt->execute([':id' => $attendanceId]);
        $dateRow = $chkDateStmt->fetch();
        $recordDate = $dateRow['attendance_date'] ?? $dateRow['deployment_date'] ?? null;
        if ($recordDate && strtotime($recordDate) > strtotime(date('Y-m-d'))) {
            echo json_encode(['success' => false, 'message' => 'Future date (' . date('d/m/Y', strtotime($recordDate)) . ') ke liye Present ya Half-Day mark nahi kiya ja sakta. Attendance can only be marked on or before today.']);
            exit;
        }
    }

    $updated = AttendanceService::processAttendance($attendanceId, [
        'status' => $newStatus,
        'remarks' => 'Quick marked via One-Click Hub'
    ]);

    // Fetch updated record
    $fetchStmt = $db->prepare("SELECT id, status, final_client_amount, final_staff_amount, hours_worked FROM attendance WHERE id = :id");
    $fetchStmt->execute([':id' => $attendanceId]);
    $row = $fetchStmt->fetch();

    echo json_encode([
        'success' => true,
        'attendance_id' => $attendanceId,
        'status' => $row['status'],
        'badge_html' => Helper::statusBadge($row['status']),
        'client_amount' => Helper::formatCurrency($row['final_client_amount']),
        'staff_amount' => Helper::formatCurrency($row['final_staff_amount']),
        'message' => "Marked as " . ucfirst(str_replace('_', ' ', $newStatus))
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
