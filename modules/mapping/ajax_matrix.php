<?php
// modules/mapping/ajax_matrix.php - Real-Time AJAX API for Spreadsheet & Drag-Drop Deployment Hub
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/DeploymentService.php';
require_once ROOT_PATH . '/services/AttendanceService.php';

header('Content-Type: application/json');

if (!Auth::check() || !Auth::isManager()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$db = DB::getConnection();
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. GET COMPLETE MATRIX DATA FOR SPREADSHEET & DRAG-DROP CANVAS
        // -------------------------------------------------------------
        case 'get_matrix_data':
            $date = !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
            $shiftId = !empty($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;

            // A. Fetch all active staff with staff_group
            $staffStmt = $db->query("
                SELECT s.id, s.staff_code, s.full_name, s.skill_category, s.category_id,
                       s.staff_group, s.default_payout_rate, s.phone, s.status,
                       sc.category_name, sc.category_code
                FROM staff s
                LEFT JOIN staff_categories sc ON s.category_id = sc.id
                WHERE s.status = 'active'
                ORDER BY s.full_name ASC
            ");
            $allStaff = $staffStmt->fetchAll();

            // A2. Fetch staff groups
            $groupsStmt = $db->query("
                SELECT id, group_name, group_code, color_theme, display_order
                FROM staff_groups
                WHERE status = 'active'
                ORDER BY display_order ASC, id ASC
            ");
            $allGroups = $groupsStmt->fetchAll();

            // B. Active leaves on this date
            $activeLeaves = DeploymentService::getActiveLeavesForDate($date);

            // C. Deployments on this date
            $depSql = "
                SELECT d.id as deploy_id, d.staff_id, d.client_id, d.site_id, d.shift_id,
                       d.client_rate, d.staff_rate, d.status as deploy_status, d.deployment_date,
                       c.company_name, s.site_name, sh.shift_name, sh.shift_code,
                       sh.start_time, sh.end_time, sh.is_overnight,
                       a.status as att_status, a.id as att_id
                FROM deployments d
                JOIN clients c ON d.client_id = c.id
                JOIN sites s ON d.site_id = s.id
                JOIN shifts sh ON d.shift_id = sh.id
                LEFT JOIN attendance a ON a.deployment_id = d.id
                WHERE d.deployment_date = :dt AND d.status != 'cancelled'
            ";
            if ($shiftId > 0) {
                $depSql .= " AND d.shift_id = :shid";
            }
            $depSql .= " ORDER BY sh.start_time ASC, d.id ASC";
            $depStmt = $db->prepare($depSql);
            $depParams = [':dt' => $date];
            if ($shiftId > 0) {
                $depParams[':shid'] = $shiftId;
            }
            $depStmt->execute($depParams);
            $allDeployments = $depStmt->fetchAll();

            // Fetch active shifts to determine current running shift
            $shiftsList = $db->query("SELECT id, shift_name, shift_code, start_time, end_time, is_overnight, duration_hours FROM shifts WHERE status = 'active' ORDER BY start_time ASC")->fetchAll();
            $currentTime = date('H:i:s');
            $currentRunningShiftId = null;
            foreach ($shiftsList as $sh) {
                if (!$sh['is_overnight']) {
                    if ($currentTime >= $sh['start_time'] && $currentTime <= $sh['end_time']) {
                        $currentRunningShiftId = (int)$sh['id'];
                        break;
                    }
                } else {
                    if ($currentTime >= $sh['start_time'] || $currentTime <= $sh['end_time']) {
                        $currentRunningShiftId = (int)$sh['id'];
                        break;
                    }
                }
            }

            // Map deployments by staff_id (Absent staff are treated as free/available!)
            $staffDeployments = [];
            $siteDeploys = [];
            foreach ($allDeployments as $dep) {
                if (($dep['att_status'] ?? '') === 'absent') {
                    continue; // Absent staff are unblocked and free!
                }
                $stfId = (int)$dep['staff_id'];
                if (!isset($staffDeployments[$stfId])) {
                    $staffDeployments[$stfId] = [];
                }
                $staffDeployments[$stfId][] = $dep;

                $sid = (int)$dep['site_id'];
                if (!isset($siteDeploys[$sid])) {
                    $siteDeploys[$sid] = [];
                }
                $siteDeploys[$sid][] = $dep;
            }

            // D. Client Sites
            $sitesStmt = $db->query("
                SELECT s.id, s.client_id, s.site_name, s.location_address,
                       s.default_client_rate, s.default_staff_rate,
                       c.company_name
                FROM sites s
                JOIN clients c ON s.client_id = c.id
                WHERE s.status = 'active' AND c.status = 'active'
                ORDER BY c.company_name ASC, s.site_name ASC
            ");
            $allSites = $sitesStmt->fetchAll();

            // E. Build enriched staff payload
            $enrichedStaff = [];
            $totalCount = count($allStaff);
            $deployedCount = 0;   // scheduled only
            $ongoingCount = 0;    // present / ongoing
            $completedCount = 0;  // completed
            $multiShiftCount = 0; // multi-shift
            $leaveCount = 0;
            $readyCount = 0;

            $today = date('Y-m-d');
            $isToday = ($date === $today);
            $isPast = ($date < $today);
            $isFuture = ($date > $today);

            foreach ($allStaff as $stf) {
                $stfId = (int)$stf['id'];
                $isOnLeave = isset($activeLeaves[$stfId]);
                $leaveInfo = $isOnLeave ? $activeLeaves[$stfId] : null;

                $rawDeploys = $staffDeployments[$stfId] ?? [];
                $isDeployed = !empty($rawDeploys);

                if ($isOnLeave) {
                    $leaveCount++;
                    $statusType = 'leave';
                    $processedShifts = [];
                    $gradientCss = '';
                } elseif ($isDeployed) {
                    $processedShifts = [];
                    foreach ($rawDeploys as $dep) {
                        $attSt = $dep['att_status'] ?? 'scheduled';
                        $isAttended = in_array($attSt, ['present', 'half_day']);
                        $isOvernight = (bool)$dep['is_overnight'];
                        $startTime = $dep['start_time'];
                        $endTime = $dep['end_time'];

                        if ($isPast) {
                            if ($isAttended || $dep['deploy_status'] === 'completed') {
                                $shState = 'completed';
                                $shColor = '#2563eb'; // Blue
                                $shLabel = 'Completed';
                            } else {
                                $shState = 'scheduled';
                                $shColor = '#facc15'; // Yellow
                                $shLabel = 'Scheduled';
                            }
                        } elseif ($isFuture) {
                            $shState = 'scheduled';
                            $shColor = '#facc15'; // Yellow
                            $shLabel = 'Scheduled';
                        } else {
                            // Today
                            $hasEnded = false;
                            if (!$isOvernight) {
                                if ($currentTime > $endTime) {
                                    $hasEnded = true;
                                }
                            }

                            if ($hasEnded) {
                                $shState = 'completed';
                                $shColor = '#2563eb'; // Blue
                                $shLabel = 'Completed';
                            } elseif ($isAttended) {
                                $shState = 'ongoing';
                                $shColor = '#16a34a'; // Green
                                $shLabel = 'Ongoing';
                            } else {
                                $shState = 'scheduled';
                                $shColor = '#facc15'; // Yellow
                                $shLabel = 'Scheduled';
                            }
                        }

                        $processedShifts[] = [
                            'deploy_id'    => (int)$dep['deploy_id'],
                            'shift_id'     => (int)$dep['shift_id'],
                            'shift_name'   => $dep['shift_name'],
                            'shift_code'   => $dep['shift_code'],
                            'start_time'   => $dep['start_time'],
                            'end_time'     => $dep['end_time'],
                            'client_id'    => (int)$dep['client_id'],
                            'site_id'      => (int)$dep['site_id'],
                            'client_name'  => $dep['company_name'],
                            'site_name'    => $dep['site_name'],
                            'client_rate'  => (float)$dep['client_rate'],
                            'staff_rate'   => (float)$dep['staff_rate'],
                            'att_status'   => $attSt,
                            'state'        => $shState,
                            'color'        => $shColor,
                            'label'        => $shLabel
                        ];
                    }

                    $shiftCount = count($processedShifts);
                    $colors = array_column($processedShifts, 'color');
                    $uniqueColors = array_values(array_unique($colors));

                    if ($shiftCount >= 2) {
                        $multiShiftCount++;
                        $statusType = 'multi_shift';

                        if (count($uniqueColors) === 1) {
                            $isYellow = ($uniqueColors[0] === '#fef08a' || $uniqueColors[0] === '#fde047');
                            $txtColor = $isYellow ? '#713f12' : '#ffffff';
                            $gradientCss = "background-color: {$uniqueColors[0]} !important; color: {$txtColor} !important;";
                        } else {
                            $pctStep = 100 / $shiftCount;
                            $stops = [];
                            for ($i = 0; $i < $shiftCount; $i++) {
                                $c = $colors[$i];
                                $p1 = round($i * $pctStep, 2);
                                $p2 = round(($i + 1) * $pctStep, 2);
                                $stops[] = "{$c} {$p1}%";
                                $stops[] = "{$c} {$p2}%";
                            }
                            $gradientCss = "background: linear-gradient(135deg, " . implode(', ', $stops) . ") !important; color: #ffffff !important;";
                        }
                    } else {
                        // 1 Shift
                        $singleState = $processedShifts[0]['state'];
                        if ($singleState === 'completed') {
                            $completedCount++;
                            $statusType = 'completed';
                            $gradientCss = 'background-color: #2563eb !important; color: #ffffff !important;';
                        } elseif ($singleState === 'ongoing') {
                            $ongoingCount++;
                            $statusType = 'ongoing';
                            $gradientCss = 'background-color: #16a34a !important; color: #ffffff !important;';
                        } else {
                            $deployedCount++;
                            $statusType = 'deployed';
                            $gradientCss = 'background-color: #fef08a !important; color: #713f12 !important;';
                        }
                    }
                } else {
                    $readyCount++;
                    $statusType = 'available';
                    $processedShifts = [];
                    $gradientCss = '';
                }

                $enrichedStaff[] = [
                    'id'            => $stfId,
                    'code'          => $stf['staff_code'],
                    'name'          => $stf['full_name'],
                    'phone'         => $stf['phone'],
                    'category'      => $stf['category_name'] ?: ($stf['skill_category'] ?: 'General Staff'),
                    'category_code' => $stf['category_code'] ?: 'GEN',
                    'group'         => !empty($stf['staff_group']) ? $stf['staff_group'] : 'GENERAL POOL',
                    'rate'          => (float)$stf['default_payout_rate'],
                    'status_type'   => $statusType,
                    'is_on_leave'   => $isOnLeave,
                    'leave_info'    => $leaveInfo ? [
                        'id'         => (int)$leaveInfo['id'],
                        'type'       => $leaveInfo['leave_type'],
                        'start_date' => $leaveInfo['start_date'],
                        'end_date'   => $leaveInfo['end_date'],
                        'reason'     => $leaveInfo['reason'],
                        'days'       => max(1, (int)round((strtotime($leaveInfo['end_date']) - strtotime($leaveInfo['start_date'])) / 86400) + 1)
                    ] : null,
                    'is_deployed'   => $isDeployed,
                    'shift_count'   => count($processedShifts),
                    'shifts'        => $processedShifts,
                    'gradient_css'  => $gradientCss,
                    'deploy_info'   => !empty($processedShifts) ? $processedShifts[0] : null
                ];
            }

            // F. Build enriched sites payload
            $enrichedSites = [];
            foreach ($allSites as $st) {
                $sid = (int)$st['id'];
                $assignedStaff = $siteDeploys[$sid] ?? [];
                $enrichedSites[] = [
                    'id'               => $sid,
                    'client_id'        => (int)$st['client_id'],
                    'client_name'      => $st['company_name'],
                    'site_name'        => $st['site_name'],
                    'address'          => $st['location_address'] ?? '',
                    'default_billing'  => (float)$st['default_client_rate'],
                    'default_payout'   => (float)$st['default_staff_rate'],
                    'deployed_count'   => count($assignedStaff),
                    'deployed_staff'   => array_map(function($d) {
                        return [
                            'deploy_id'   => (int)$d['deploy_id'],
                            'staff_id'    => (int)$d['staff_id'],
                            'shift_id'    => (int)$d['shift_id'],
                            'shift_name'  => $d['shift_name'],
                            'client_rate' => (float)$d['client_rate'],
                            'staff_rate'  => (float)$d['staff_rate'],
                            'att_status'  => $d['att_status'] ?? 'scheduled'
                        ];
                    }, $assignedStaff)
                ];
            }

            echo json_encode([
                'success' => true,
                'date'    => $date,
                'shift_id'=> $shiftId,
                'counts'  => [
                    'total'       => $totalCount,
                    'ready'       => $readyCount,
                    'deployed'    => $deployedCount, // scheduled
                    'ongoing'     => $ongoingCount,   // ongoing / present
                    'present'     => $ongoingCount,   // alias
                    'completed'   => $completedCount, // completed
                    'multi_shift' => $multiShiftCount,
                    'on_leave'    => $leaveCount
                ],
                'groups'  => $allGroups,
                'staff'   => $enrichedStaff,
                'sites'   => $enrichedSites,
                'shifts'  => $shiftsList
            ]);
            exit;

        // -------------------------------------------------------------
        // 2. ASSIGN STAFF (DRAG & DROP OR CLICK-TO-ASSIGN)
        // -------------------------------------------------------------
        case 'assign_staff':
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $clientId = (int)($_POST['client_id'] ?? 0);
            $siteId = (int)($_POST['site_id'] ?? 0);
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $forceMove = !empty($_POST['force_move']) ? 1 : 0;

            if (!$staffId || !$clientId || !$siteId || !$shiftId || !$date) {
                echo json_encode(['success' => false, 'message' => 'Missing required assignment fields.']);
                exit;
            }

            // Target venue info
            $siteStmt = $db->prepare("SELECT s.site_name, c.company_name FROM sites s JOIN clients c ON s.client_id = c.id WHERE s.id = :id");
            $siteStmt->execute([':id' => $siteId]);
            $targetSiteInfo = $siteStmt->fetch();
            $targetVenueName = $targetSiteInfo ? "{$targetSiteInfo['company_name']} ({$targetSiteInfo['site_name']})" : "Selected Venue";
            $targetSiteName = $targetSiteInfo ? $targetSiteInfo['site_name'] : "Venue";

            // Check conflict
            $conflict = DeploymentService::checkConflict($staffId, $shiftId, $date);
            if ($conflict) {
                // If conflict is leave:
                if (($conflict['type'] ?? '') === 'leave') {
                    echo json_encode(['success' => false, 'message' => $conflict['message'] ?? 'Staff is on leave.']);
                    exit;
                }

                // If already assigned to the SAME site:
                if ((int)$conflict['site_id'] === $siteId) {
                    echo json_encode(['success' => false, 'message' => "Staff '{$conflict['staff_name']}' is already assigned to {$conflict['site_name']}."]);
                    exit;
                }

                $attStatus = $conflict['attendance_status'] ?? 'scheduled';

                // Condition 1: Staff is ALREADY PRESENT / HALF-DAY -> Block with direct message
                if (in_array($attStatus, ['present', 'half_day'])) {
                    echo json_encode([
                        'success'       => false,
                        'conflict_type' => 'already_present',
                        'message'       => "Staff already present on {$conflict['site_name']}"
                    ]);
                    exit;
                }

                // Condition 2: Staff is mapped elsewhere, but NOT present yet!
                if (!$forceMove) {
                    echo json_encode([
                        'success'           => false,
                        'conflict_type'     => 'warning_can_move',
                        'staff_id'          => $staffId,
                        'staff_name'        => $conflict['staff_name'],
                        'current_venue'     => "{$conflict['company_name']} ({$conflict['site_name']})",
                        'current_site_name' => $conflict['site_name'],
                        'target_site_id'    => $siteId,
                        'target_client_id'  => $clientId,
                        'target_shift_id'   => $shiftId,
                        'target_venue'      => $targetVenueName,
                        'target_site_name'  => $targetSiteName,
                        'message'           => "Staff '{$conflict['staff_name']}' pehle se '{$conflict['site_name']}' par mapped hai. Kya aap inhe dusre venue site par map karna chahte hain?"
                    ]);
                    exit;
                }

                // If forceMove is confirmed: Move existing deployment
                try {
                    DeploymentService::moveDeployment((int)$conflict['id'], $clientId, $siteId, $shiftId);
                    echo json_encode([
                        'success' => true,
                        'message' => "Staff '{$conflict['staff_name']}' ko successfully '{$targetVenueName}' par shift/map kar diya gaya!",
                        'moved'   => true
                    ]);
                    exit;
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                    exit;
                }
            }

            try {
                $deployId = DeploymentService::createDeployment([
                    'deployment_date' => $date,
                    'staff_id'        => $staffId,
                    'client_id'       => $clientId,
                    'site_id'         => $siteId,
                    'shift_id'        => $shiftId,
                    'notes'           => $notes
                ]);

                // Fetch created deployment info for instant UI refresh
                $stmt = $db->prepare("
                    SELECT d.id as deploy_id, d.staff_id, d.client_id, d.site_id, d.shift_id,
                           d.client_rate, d.staff_rate,
                           c.company_name, s.site_name, sh.shift_name, stf.full_name as staff_name
                    FROM deployments d
                    JOIN clients c ON d.client_id = c.id
                    JOIN sites s ON d.site_id = s.id
                    JOIN shifts sh ON d.shift_id = sh.id
                    JOIN staff stf ON d.staff_id = stf.id
                    WHERE d.id = :id
                ");
                $stmt->execute([':id' => $deployId]);
                $details = $stmt->fetch();

                echo json_encode([
                    'success' => true,
                    'message' => "Successfully deployed {$details['staff_name']} to {$details['company_name']} ({$details['site_name']})!",
                    'deployment' => $details
                ]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }

        // -------------------------------------------------------------
        // 2B. ASSIGN BATCH STAFF (CLICK/TAP MULTI-SELECT TO DEPLOY)
        // -------------------------------------------------------------
        case 'assign_batch_staff':
            $rawStaffIds = $_POST['staff_ids'] ?? [];
            if (is_string($rawStaffIds)) {
                $rawStaffIds = explode(',', $rawStaffIds);
            }
            $staffIds = array_filter(array_map('intval', (array)$rawStaffIds));
            $clientId = (int)($_POST['client_id'] ?? 0);
            $siteId = (int)($_POST['site_id'] ?? 0);
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? 'Batch deployment via Multi-Select');
            $forceMove = !empty($_POST['force_move']) ? 1 : 0;

            if (empty($staffIds) || !$clientId || !$siteId || !$shiftId || !$date) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields for batch deployment.']);
                exit;
            }

            // Get site and client name
            $siteStmt = $db->prepare("SELECT s.site_name, c.company_name FROM sites s JOIN clients c ON s.client_id = c.id WHERE s.id = :id");
            $siteStmt->execute([':id' => $siteId]);
            $siteInfo = $siteStmt->fetch();
            $targetVenueName = $siteInfo ? "{$siteInfo['company_name']} ({$siteInfo['site_name']})" : "Selected Venue";

            $successCount = 0;
            $failedStaff = [];

            foreach ($staffIds as $sid) {
                $conflict = DeploymentService::checkConflict($sid, $shiftId, $date);
                if ($conflict) {
                    if (($conflict['type'] ?? '') === 'leave') {
                        $failedStaff[] = "{$conflict['staff_name']} (on leave)";
                        continue;
                    }
                    if ((int)$conflict['site_id'] === $siteId) {
                        continue; // Already at this site
                    }
                    $attSt = $conflict['attendance_status'] ?? 'scheduled';
                    if (in_array($attSt, ['present', 'half_day'])) {
                        $failedStaff[] = "{$conflict['staff_name']} (already present on {$conflict['site_name']})";
                        continue;
                    }
                    if ($forceMove) {
                        try {
                            DeploymentService::moveDeployment((int)$conflict['id'], $clientId, $siteId, $shiftId);
                            $successCount++;
                            continue;
                        } catch (Exception $e) {
                            $failedStaff[] = "{$conflict['staff_name']}: " . $e->getMessage();
                            continue;
                        }
                    } else {
                        $failedStaff[] = "{$conflict['staff_name']} (already mapped to {$conflict['site_name']})";
                        continue;
                    }
                }

                try {
                    DeploymentService::createDeployment([
                        'deployment_date' => $date,
                        'staff_id'        => $sid,
                        'client_id'       => $clientId,
                        'site_id'         => $siteId,
                        'shift_id'        => $shiftId,
                        'notes'           => $notes
                    ]);
                    $successCount++;
                } catch (Exception $e) {
                    $failedStaff[] = "Staff #{$sid}: " . $e->getMessage();
                }
            }

            if ($successCount > 0) {
                $msg = "Successfully deployed {$successCount} staff to {$targetVenueName}!";
                if (!empty($failedStaff)) {
                    $msg .= " (" . count($failedStaff) . " skipped: " . implode(', ', array_slice($failedStaff, 0, 3)) . (count($failedStaff) > 3 ? '...' : '') . ")";
                }
                echo json_encode([
                    'success' => true,
                    'message' => $msg,
                    'deployed_count' => $successCount,
                    'failed_count' => count($failedStaff)
                ]);
            } else {
                $firstErr = !empty($failedStaff) ? implode('; ', $failedStaff) : 'No staff could be deployed.';
                echo json_encode([
                    'success' => false,
                    'message' => "Deployment failed: " . $firstErr
                ]);
            }
            exit;

        // -------------------------------------------------------------
        // 3. UNASSIGN / CANCEL DEPLOYMENT
        // -------------------------------------------------------------
        case 'unassign_staff':
            $deployId = (int)($_POST['deploy_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Unassigned via Spreadsheet Hub');

            if (!$deployId) {
                echo json_encode(['success' => false, 'message' => 'Deployment ID is required.']);
                exit;
            }

            DeploymentService::cancelDeployment($deployId, $reason);
            echo json_encode([
                'success' => true,
                'message' => 'Staff unassigned from venue successfully.'
            ]);
            exit;

        // -------------------------------------------------------------
        // 4. 1-CLICK TOGGLE AVAILABILITY (DISABLE / ENABLE TODAY)
        // -------------------------------------------------------------
        case 'toggle_availability':
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');

            if (!$staffId) {
                echo json_encode(['success' => false, 'message' => 'Staff ID is required.']);
                exit;
            }

            $currentLeave = DeploymentService::getStaffLeave($staffId, $date);

            if ($currentLeave) {
                // Currently on leave / disabled -> Reactivate
                DeploymentService::cancelStaffLeave((int)$currentLeave['id']);
                echo json_encode([
                    'success' => true,
                    'status'  => 'available',
                    'message' => 'Staff marked Available and ready for duty!'
                ]);
            } else {
                // Currently available -> 1-Click disable for today
                $leaveId = DeploymentService::saveStaffLeave($staffId, $date, $date, 'unavailable', '1-Click Disabled via Spreadsheet');
                echo json_encode([
                    'success' => true,
                    'status'  => 'disabled',
                    'leave_id'=> $leaveId,
                    'message' => 'Staff marked as Unavailable for today.'
                ]);
            }
            exit;

        // -------------------------------------------------------------
        // 5. SAVE MULTI-DAY LEAVE (E.G. 10 DINO KI CHHUTTI)
        // -------------------------------------------------------------
        case 'save_leave':
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            $endDate = $_POST['end_date'] ?? $startDate;
            $leaveType = $_POST['leave_type'] ?? 'vacation';
            $reason = trim($_POST['reason'] ?? '');

            if (!$staffId || !$startDate || !$endDate) {
                echo json_encode(['success' => false, 'message' => 'Staff ID and date range are required.']);
                exit;
            }

            if ($endDate < $startDate) {
                echo json_encode(['success' => false, 'message' => 'End date cannot be earlier than start date.']);
                exit;
            }

            $leaveId = DeploymentService::saveStaffLeave($staffId, $startDate, $endDate, $leaveType, $reason);
            $days = max(1, (int)round((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);

            echo json_encode([
                'success'  => true,
                'leave_id' => $leaveId,
                'days'     => $days,
                'message'  => "Leave applied successfully for {$days} day(s) ({$startDate} to {$endDate})!"
            ]);
            exit;

        // -------------------------------------------------------------
        // 6. CANCEL ACTIVE LEAVE (RESUME DUTY EARLY)
        // -------------------------------------------------------------
        case 'cancel_leave':
            $leaveId = (int)($_POST['leave_id'] ?? 0);
            if (!$leaveId) {
                echo json_encode(['success' => false, 'message' => 'Leave ID is required.']);
                exit;
            }

            DeploymentService::cancelStaffLeave($leaveId);
            echo json_encode([
                'success' => true,
                'message' => 'Leave cancelled successfully. Staff is now active for duty!'
            ]);
            exit;

        // -------------------------------------------------------------
        // 7. SHIFT STAFF TO ANOTHER GROUP / POOL
        // -------------------------------------------------------------
        case 'shift_staff_group':
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $targetGroup = trim($_POST['target_group'] ?? '');

            if (!$staffId || empty($targetGroup)) {
                echo json_encode(['success' => false, 'message' => 'Staff ID and Target Group are required.']);
                exit;
            }

            // Verify staff exists
            $stfStmt = $db->prepare("SELECT id, full_name, staff_group FROM staff WHERE id = :id");
            $stfStmt->execute([':id' => $staffId]);
            $staffData = $stfStmt->fetch();
            if (!$staffData) {
                echo json_encode(['success' => false, 'message' => 'Staff record not found.']);
                exit;
            }

            $oldGroup = $staffData['staff_group'] ?: 'GENERAL POOL';

            // Update staff group
            $upd = $db->prepare("UPDATE staff SET staff_group = :grp, updated_at = NOW() WHERE id = :id");
            $upd->execute([':grp' => $targetGroup, ':id' => $staffId]);

            // Ensure group exists in staff_groups table
            $checkGrp = $db->prepare("SELECT id FROM staff_groups WHERE group_name = :gname");
            $checkGrp->execute([':gname' => $targetGroup]);
            if (!$checkGrp->fetch()) {
                $insGrp = $db->prepare("INSERT INTO staff_groups (group_name, group_code, color_theme, display_order) VALUES (:name, :code, 'danger', 99)");
                $code = 'GRP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $targetGroup), 0, 3));
                $insGrp->execute([':name' => $targetGroup, ':code' => $code]);
            }

            // Audit log
            if (class_exists('AuditLog')) {
                AuditLog::log('shift_staff_group', "Shifted staff #$staffId ({$staffData['full_name']}) from '$oldGroup' to '$targetGroup'");
            }

            echo json_encode([
                'success'      => true,
                'staff_id'     => $staffId,
                'staff_name'   => $staffData['full_name'],
                'old_group'    => $oldGroup,
                'new_group'    => $targetGroup,
                'message'      => "{$staffData['full_name']} shifted to {$targetGroup} successfully!"
            ]);
            exit;

        // -------------------------------------------------------------
        // 8. ADD NEW STAFF GROUP
        // -------------------------------------------------------------
        case 'add_staff_group':
            $groupName = strtoupper(trim($_POST['group_name'] ?? ''));
            if (empty($groupName)) {
                echo json_encode(['success' => false, 'message' => 'Group name is required.']);
                exit;
            }

            $check = $db->prepare("SELECT id FROM staff_groups WHERE group_name = :name");
            $check->execute([':name' => $groupName]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Group already exists.']);
                exit;
            }

            $maxOrd = (int)$db->query("SELECT MAX(display_order) FROM staff_groups")->fetchColumn();
            $ins = $db->prepare("INSERT INTO staff_groups (group_name, group_code, color_theme, display_order) VALUES (:name, :code, 'danger', :ord)");
            $code = 'GRP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $groupName), 0, 3));
            $ins->execute([':name' => $groupName, ':code' => $code, ':ord' => $maxOrd + 1]);

            echo json_encode([
                'success'    => true,
                'group_name' => $groupName,
                'message'    => "New group '{$groupName}' created successfully!"
            ]);
            exit;

        // -------------------------------------------------------------
        // 9. RENAME STAFF GROUP (MODIFY)
        // -------------------------------------------------------------
        case 'rename_staff_group':
            $oldName = strtoupper(trim($_POST['old_group_name'] ?? ''));
            $newName = strtoupper(trim($_POST['new_group_name'] ?? ''));

            if (empty($oldName) || empty($newName)) {
                echo json_encode(['success' => false, 'message' => 'Both old and new group names are required.']);
                exit;
            }

            if ($oldName === $newName) {
                echo json_encode(['success' => true, 'message' => 'Group name is unchanged.']);
                exit;
            }

            // Check if new name already exists
            $check = $db->prepare("SELECT id FROM staff_groups WHERE UPPER(group_name) = :new_name AND UPPER(group_name) != :old_name AND status = 'active'");
            $check->execute([':new_name' => $newName, ':old_name' => $oldName]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "A group named '{$newName}' already exists."]);
                exit;
            }

            // Update in staff_groups table
            $updGrp = $db->prepare("UPDATE staff_groups SET group_name = :new_name, updated_at = NOW() WHERE UPPER(group_name) = :old_name");
            $updGrp->execute([':new_name' => $newName, ':old_name' => $oldName]);

            if ($updGrp->rowCount() === 0) {
                $maxOrd = (int)$db->query("SELECT MAX(display_order) FROM staff_groups")->fetchColumn();
                $code = 'GRP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $newName), 0, 3));
                $ins = $db->prepare("INSERT INTO staff_groups (group_name, group_code, color_theme, display_order) VALUES (:name, :code, 'danger', :ord)");
                $ins->execute([':name' => $newName, ':code' => $code, ':ord' => $maxOrd + 1]);
            }

            // Update staff table for all staff currently in this group
            $updStaff = $db->prepare("UPDATE staff SET staff_group = :new_name, updated_at = NOW() WHERE UPPER(staff_group) = :old_name");
            $updStaff->execute([':new_name' => $newName, ':old_name' => $oldName]);
            $affectedCount = $updStaff->rowCount();

            if (class_exists('AuditLog')) {
                AuditLog::log('rename_staff_group', "Renamed staff group '$oldName' to '$newName' ($affectedCount staff updated)");
            }

            echo json_encode([
                'success'        => true,
                'old_group_name' => $oldName,
                'new_group_name' => $newName,
                'affected_staff' => $affectedCount,
                'message'        => "Group renamed to '{$newName}' successfully!"
            ]);
            exit;

        // -------------------------------------------------------------
        // 10. DELETE STAFF GROUP
        // -------------------------------------------------------------
        case 'delete_staff_group':
            $groupName = strtoupper(trim($_POST['group_name'] ?? ''));
            if (empty($groupName)) {
                echo json_encode(['success' => false, 'message' => 'Group name is required.']);
                exit;
            }

            // Reassign any staff in this group safely to another active group
            $firstGrpStmt = $db->prepare("SELECT group_name FROM staff_groups WHERE UPPER(group_name) != :name AND status = 'active' ORDER BY display_order ASC LIMIT 1");
            $firstGrpStmt->execute([':name' => $groupName]);
            $fallbackGrp = $firstGrpStmt->fetchColumn() ?: 'MAURYA REGULAR';

            $updStaff = $db->prepare("UPDATE staff SET staff_group = :fallback, updated_at = NOW() WHERE UPPER(staff_group) = :name");
            $updStaff->execute([':fallback' => $fallbackGrp, ':name' => $groupName]);
            $reassignedCount = $updStaff->rowCount();

            // Delete from staff_groups table
            $delGrp = $db->prepare("DELETE FROM staff_groups WHERE UPPER(group_name) = :name");
            $delGrp->execute([':name' => $groupName]);

            if (class_exists('AuditLog')) {
                AuditLog::log('delete_staff_group', "Deleted staff group '$groupName' ($reassignedCount staff moved to $fallbackGrp)");
            }

            echo json_encode([
                'success'          => true,
                'deleted_group'    => $groupName,
                'reassigned_count' => $reassignedCount,
                'message'          => "Group '{$groupName}' deleted successfully! ({$reassignedCount} staff moved to {$fallbackGrp})"
            ]);
            exit;

        // -------------------------------------------------------------
        // 11. BULK UPDATE ATTENDANCE (PRESENT / HALF-DAY / ABSENT)
        // -------------------------------------------------------------
        case 'bulk_update_attendance':
            $deployIdsRaw = $_POST['deploy_ids'] ?? '';
            $deployIds = is_array($deployIdsRaw) ? $deployIdsRaw : explode(',', $deployIdsRaw);
            $deployIds = array_filter(array_map('intval', $deployIds));
            $status = strtolower(trim($_POST['status'] ?? 'present'));
            $date = $_POST['date'] ?? date('Y-m-d');
            $isFuture = (strtotime($date) > strtotime(date('Y-m-d')));

            if (empty($deployIds)) {
                echo json_encode(['success' => false, 'message' => 'No deployments selected.']);
                exit;
            }

            if ($isFuture && in_array($status, ['present', 'half_day'])) {
                echo json_encode(['success' => false, 'message' => 'Cannot mark Present for a future date. Attendance can only be confirmed on or before today.']);
                exit;
            }

            $successCount = 0;
            foreach ($deployIds as $did) {
                try {
                    if ($status === 'absent') {
                        // Process attendance as absent
                        $attStmt = $db->prepare("SELECT id FROM attendance WHERE deployment_id = :did");
                        $attStmt->execute([':did' => $did]);
                        $att = $attStmt->fetch();
                        if ($att) {
                            AttendanceService::processAttendance((int)$att['id'], [
                                'status'  => 'absent',
                                'remarks' => 'Marked Absent via Duty Roster'
                            ]);
                        }
                        // Cancel deployment so staff is freed up
                        DeploymentService::cancelDeployment($did, 'Marked Absent via Duty Roster');
                        $successCount++;
                    } else {
                        // 'present' or 'half_day'
                        $attStmt = $db->prepare("SELECT id FROM attendance WHERE deployment_id = :did");
                        $attStmt->execute([':did' => $did]);
                        $att = $attStmt->fetch();
                        if ($att) {
                            AttendanceService::processAttendance((int)$att['id'], [
                                'status'  => $status,
                                'remarks' => 'Updated via Duty Roster'
                            ]);
                            $successCount++;
                        } else {
                            // If attendance row missing, create it
                            $depStmt = $db->prepare("SELECT staff_id, client_id, site_id, shift_id, deployment_date FROM deployments WHERE id = :did");
                            $depStmt->execute([':did' => $did]);
                            $depRow = $depStmt->fetch();
                            if ($depRow) {
                                $insAtt = $db->prepare("
                                    INSERT INTO attendance (deployment_id, attendance_date, staff_id, client_id, site_id, shift_id, status, hours_worked)
                                    VALUES (:did, :dt, :stfid, :cid, :sid, :shid, :st, :hw)
                                ");
                                $insAtt->execute([
                                    ':did'   => $did,
                                    ':dt'    => $depRow['deployment_date'],
                                    ':stfid' => $depRow['staff_id'],
                                    ':cid'   => $depRow['client_id'],
                                    ':sid'   => $depRow['site_id'],
                                    ':shid'  => $depRow['shift_id'],
                                    ':st'    => $status,
                                    ':hw'    => ($status === 'half_day' ? 4.00 : 8.00)
                                ]);
                                $successCount++;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // continue
                }
            }

            echo json_encode([
                'success' => true,
                'count'   => $successCount,
                'status'  => $status,
                'message' => "Successfully marked {$successCount} staff as " . ucfirst(str_replace('_', ' ', $status)) . "!"
            ]);
            exit;

        // -------------------------------------------------------------
        // 12. MOVE SCHEDULED STAFF TO ANOTHER VENUE (DRAG & DROP)
        // -------------------------------------------------------------
        case 'move_staff_venue':
            $deployId = (int)($_POST['deploy_id'] ?? 0);
            $targetClientId = (int)($_POST['target_client_id'] ?? 0);
            $targetSiteId = (int)($_POST['target_site_id'] ?? 0);
            $targetShiftId = (int)($_POST['target_shift_id'] ?? 0);
            $forceMove = !empty($_POST['force_move']) ? 1 : 0;

            if (!$deployId || !$targetClientId || !$targetSiteId) {
                echo json_encode(['success' => false, 'message' => 'Missing deployment or target venue information.']);
                exit;
            }

            // Fetch current deployment info
            $depStmt = $db->prepare("
                SELECT d.*, c.company_name as current_client, s.site_name as current_site,
                       stf.full_name as staff_name, a.status as att_status
                FROM deployments d
                JOIN clients c ON d.client_id = c.id
                JOIN sites s ON d.site_id = s.id
                JOIN staff stf ON d.staff_id = stf.id
                LEFT JOIN attendance a ON a.deployment_id = d.id
                WHERE d.id = :did AND d.status != 'cancelled'
            ");
            $depStmt->execute([':did' => $deployId]);
            $currentDep = $depStmt->fetch();

            if (!$currentDep) {
                echo json_encode(['success' => false, 'message' => 'Active deployment record not found.']);
                exit;
            }

            // Target site info
            $targetStmt = $db->prepare("SELECT s.site_name, c.company_name FROM sites s JOIN clients c ON s.client_id = c.id WHERE s.id = :sid");
            $targetStmt->execute([':sid' => $targetSiteId]);
            $targetInfo = $targetStmt->fetch();
            $targetVenueName = $targetInfo ? "{$targetInfo['company_name']} ({$targetInfo['site_name']})" : "Target Venue";

            if ((int)$currentDep['site_id'] === $targetSiteId) {
                echo json_encode(['success' => false, 'message' => "Staff '{$currentDep['staff_name']}' is already mapped to {$targetInfo['site_name']}."]);
                exit;
            }

            $currentAtt = strtolower($currentDep['att_status'] ?? 'scheduled');
            // Condition 1: Staff is ALREADY PRESENT on current site
            if (in_array($currentAtt, ['present', 'half_day'])) {
                echo json_encode([
                    'success'       => false,
                    'conflict_type' => 'already_present',
                    'message'       => "Staff already present on {$currentDep['current_site']}. Cannot move a present staff to another site."
                ]);
                exit;
            }

            $destShiftId = $targetShiftId ?: (int)$currentDep['shift_id'];

            // Condition 2: Staff is mapped elsewhere, but NOT present yet -> Ask confirmation or force move
            if (!$forceMove) {
                echo json_encode([
                    'success'          => false,
                    'conflict_type'    => 'warning_can_move',
                    'deploy_id'        => $deployId,
                    'staff_name'       => $currentDep['staff_name'],
                    'current_venue'    => "{$currentDep['current_client']} ({$currentDep['current_site']})",
                    'target_venue'     => $targetVenueName,
                    'target_client_id' => $targetClientId,
                    'target_site_id'   => $targetSiteId,
                    'target_shift_id'  => $destShiftId,
                    'message'          => "Staff '{$currentDep['staff_name']}' pehle se '{$currentDep['current_site']}' par mapped hai. Kya aap inhe dusre venue site ('{$targetVenueName}') par map karna chahte hain?"
                ]);
                exit;
            }

            // Execute move
            try {
                DeploymentService::moveDeployment($deployId, $targetClientId, $targetSiteId, $destShiftId);
                echo json_encode([
                    'success' => true,
                    'message' => "Staff '{$currentDep['staff_name']}' ko successfully '{$targetVenueName}' par shift/map kar diya gaya!",
                    'moved'   => true
                ]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }

        // -------------------------------------------------------------
        // 13. SWAP TWO DEPLOYMENTS (STAFF SWAP FEATURE)
        // -------------------------------------------------------------
        case 'swap_staff':
            $deployId1 = (int)($_POST['deploy_id1'] ?? 0);
            $deployId2 = (int)($_POST['deploy_id2'] ?? 0);

            if (!$deployId1 || !$deployId2) {
                echo json_encode(['success' => false, 'message' => 'Both deployment IDs are required for swap.']);
                exit;
            }

            if ($deployId1 === $deployId2) {
                echo json_encode(['success' => false, 'message' => 'Cannot swap the same deployment slot.']);
                exit;
            }

            try {
                $swapResult = DeploymentService::swapDeployments($deployId1, $deployId2);
                echo json_encode([
                    'success' => true,
                    'message' => "Successfully swapped {$swapResult['staff1_name']} ({$swapResult['venue1']}) with {$swapResult['staff2_name']} ({$swapResult['venue2']})!"
                ]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }

        // -------------------------------------------------------------
        // 14. QUICK ADD CLIENT & VENUE (DIRECTLY FROM DEPLOY POPUP)
        // -------------------------------------------------------------
        case 'quick_add_client_site':
            $clientMode = $_POST['client_mode'] ?? 'existing'; // 'existing' or 'new'
            $clientId = (int)($_POST['client_id'] ?? 0);
            $newClientName = trim($_POST['new_client_name'] ?? '');
            $siteName = trim($_POST['site_name'] ?? '');
            $locationAddress = trim($_POST['location_address'] ?? 'Patna, Bihar');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $contactPhone = trim($_POST['contact_phone'] ?? '');
            $defaultClientRate = !empty($_POST['default_client_rate']) ? (float)$_POST['default_client_rate'] : 850.00;

            if (empty($siteName)) {
                echo json_encode(['success' => false, 'message' => 'Venue / Site Name is required.']);
                exit;
            }

            // Handle Client (Existing or Create New)
            if ($clientMode === 'new' || empty($clientId)) {
                if (empty($newClientName)) {
                    echo json_encode(['success' => false, 'message' => 'Client / Company Name is required.']);
                    exit;
                }

                // Check if client with same name already exists
                $chkClient = $db->prepare("SELECT id, company_name FROM clients WHERE LOWER(company_name) = LOWER(:cname) LIMIT 1");
                $chkClient->execute([':cname' => $newClientName]);
                $existingCli = $chkClient->fetch();

                if ($existingCli) {
                    $clientId = (int)$existingCli['id'];
                    $clientCompanyName = $existingCli['company_name'];
                } else {
                    $clientCode = 'CLI-' . rand(100, 9999);
                    while (true) {
                        $chk = $db->prepare("SELECT id FROM clients WHERE client_code = :cc");
                        $chk->execute([':cc' => $clientCode]);
                        if (!$chk->fetch()) break;
                        $clientCode = 'CLI-' . rand(100, 9999);
                    }

                    $insClient = $db->prepare("
                        INSERT INTO clients (client_code, company_name, contact_person, phone, billing_address, default_billing_rate, status)
                        VALUES (:code, :name, :cp, :ph, :addr, :dbr, 'active')
                    ");
                    $insClient->execute([
                        ':code' => $clientCode,
                        ':name' => $newClientName,
                        ':cp'   => $contactPerson ?: $newClientName,
                        ':ph'   => $contactPhone ?: '9999999999',
                        ':addr' => $locationAddress ?: 'Patna, Bihar',
                        ':dbr'  => $defaultClientRate
                    ]);
                    $clientId = (int)$db->lastInsertId();
                    $clientCompanyName = $newClientName;
                    Audit::log('CREATE', 'CLIENTS', $clientId, null, ['company_name' => $newClientName]);
                }
            } else {
                $cStmt = $db->prepare("SELECT company_name FROM clients WHERE id = :cid LIMIT 1");
                $cStmt->execute([':cid' => $clientId]);
                $clientRow = $cStmt->fetch();
                if (!$clientRow) {
                    echo json_encode(['success' => false, 'message' => 'Selected client not found.']);
                    exit;
                }
                $clientCompanyName = $clientRow['company_name'];
            }

            // Create Site / Venue
            $siteCode = 'SIT-' . rand(100, 9999);
            $insSite = $db->prepare("
                INSERT INTO sites (client_id, site_code, site_name, location_address, contact_person, contact_phone, default_client_rate, status)
                VALUES (:cid, :code, :name, :addr, :cp, :cph, :dcr, 'active')
            ");
            $insSite->execute([
                ':cid'  => $clientId,
                ':code' => $siteCode,
                ':name' => $siteName,
                ':addr' => $locationAddress ?: 'Patna, Bihar',
                ':cp'   => $contactPerson,
                ':cph'  => $contactPhone,
                ':dcr'  => $defaultClientRate
            ]);
            $newSiteId = (int)$db->lastInsertId();
            Audit::log('CREATE', 'SITES', $newSiteId, null, ['site_name' => $siteName, 'client_id' => $clientId]);

            echo json_encode([
                'success' => true,
                'message' => "Client & Venue '{$siteName}' created and mapped successfully!",
                'site' => [
                    'id'              => $newSiteId,
                    'client_id'       => $clientId,
                    'client_name'     => $clientCompanyName,
                    'site_name'       => $siteName,
                    'address'         => $locationAddress,
                    'default_billing' => (float)$defaultClientRate,
                    'default_payout'  => 0,
                    'deployed_count'  => 0,
                    'deployed_staff'  => []
                ]
            ]);
            exit;

        // -------------------------------------------------------------
        // 15. QUICK ADD STAFF (FROM BLANK EXCEL CELL CLICK)
        // -------------------------------------------------------------
        case 'quick_add_staff':
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $staffGroup = trim($_POST['staff_group'] ?? '');
            $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $payoutRate = !empty($_POST['default_payout_rate']) ? (float)$_POST['default_payout_rate'] : 500.00;

            if (empty($fullName)) {
                echo json_encode(['success' => false, 'message' => 'Staff Full Name is required.']);
                exit;
            }

            if (empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Mobile number is required.']);
                exit;
            }

            // Clean phone (keep digits)
            $phone = preg_replace('/\D/', '', $phone);
            if (strlen($phone) < 10) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
                exit;
            }
            if (strlen($phone) > 10) {
                $phone = substr($phone, -10); // keep last 10 digits
            }

            if (empty($staffGroup)) {
                $staffGroup = 'GENERAL POOL';
            }

            // Check if phone is already registered
            $checkPhone = $db->prepare("SELECT id, full_name FROM staff WHERE phone = :ph LIMIT 1");
            $checkPhone->execute([':ph' => $phone]);
            $existing = $checkPhone->fetch();
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "Mobile number {$phone} is already registered for '{$existing['full_name']}'."
                ]);
                exit;
            }

            // Look up category
            $categoryName = 'General Staff';
            $categoryCode = 'GEN';
            if ($categoryId) {
                $catStmt = $db->prepare("SELECT category_name, category_code FROM staff_categories WHERE id = :catid LIMIT 1");
                $catStmt->execute([':catid' => $categoryId]);
                $cat = $catStmt->fetch();
                if ($cat) {
                    $categoryName = $cat['category_name'];
                    $categoryCode = $cat['category_code'];
                }
            }

            // Generate unique staff code
            $staffCode = 'STF-' . rand(1000, 9999);
            while (true) {
                $chkCode = $db->prepare("SELECT id FROM staff WHERE staff_code = :sc");
                $chkCode->execute([':sc' => $staffCode]);
                if (!$chkCode->fetch()) break;
                $staffCode = 'STF-' . rand(1000, 9999);
            }

            $insStaff = $db->prepare("
                INSERT INTO staff (staff_code, full_name, phone, category_id, skill_category, staff_group, default_payout_rate, status, joined_date)
                VALUES (:sc, :fn, :ph, :cid, :scat, :grp, :rate, 'active', CURRENT_DATE)
            ");
            $insStaff->execute([
                ':sc'   => $staffCode,
                ':fn'   => $fullName,
                ':ph'   => $phone,
                ':cid'  => $categoryId,
                ':scat' => $categoryName,
                ':grp'  => $staffGroup,
                ':rate' => $payoutRate
            ]);
            $newStaffId = (int)$db->lastInsertId();
            Audit::log('CREATE', 'STAFF', $newStaffId, null, [
                'full_name'   => $fullName,
                'staff_group' => $staffGroup,
                'phone'       => $phone
            ]);

            echo json_encode([
                'success'     => true,
                'message'     => "Staff '{$fullName}' added to '{$staffGroup}' successfully!",
                'staff_id'    => $newStaffId,
                'staff_code'  => $staffCode,
                'full_name'   => $fullName,
                'staff_group' => $staffGroup,
                'category'    => $categoryName,
                'rate'        => $payoutRate
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
            exit;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
