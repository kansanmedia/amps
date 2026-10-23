<?php
// services/DeploymentService.php - Staff Scheduling, Double-Booking Guard & Rate Snapshotting

declare(strict_types=1);

class DeploymentService {
    /**
     * Check if a staff member is on leave on a given date.
     */
    public static function getStaffLeave(int $staffId, string $date): ?array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM staff_leaves
            WHERE staff_id = :stfid 
              AND start_date <= :dt 
              AND end_date >= :dt 
              AND status = 'active'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':stfid' => $staffId, ':dt' => $date]);
        $leave = $stmt->fetch();
        return $leave ?: null;
    }

    /**
     * Get all active leaves for all staff on a specific date, keyed by staff_id.
     */
    public static function getActiveLeavesForDate(string $date): array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT sl.*, s.full_name as staff_name, s.staff_code
            FROM staff_leaves sl
            JOIN staff s ON sl.staff_id = s.id
            WHERE sl.start_date <= :dt 
              AND sl.end_date >= :dt 
              AND sl.status = 'active'
        ");
        $stmt->execute([':dt' => $date]);
        $rows = $stmt->fetchAll();
        $leavesByStaff = [];
        foreach ($rows as $r) {
            $leavesByStaff[(int)$r['staff_id']] = $r;
        }
        return $leavesByStaff;
    }

    /**
     * Check if a staff member is already assigned to a shift on a given date, or is on approved leave.
     * Prevents double/overlapping deployments and scheduling while unavailable.
     */
    public static function checkConflict(int $staffId, int $shiftId, string $deploymentDate, ?int $ignoreDeploymentId = null): ?array {
        $db = DB::getConnection();

        // 1. Check if staff is on approved leave / unavailable
        $leave = self::getStaffLeave($staffId, $deploymentDate);
        if ($leave) {
            $stfStmt = $db->prepare("SELECT full_name FROM staff WHERE id = :id");
            $stfStmt->execute([':id' => $staffId]);
            $staffName = $stfStmt->fetchColumn() ?: 'Staff member';
            return [
                'type' => 'leave',
                'staff_name' => $staffName,
                'leave_id' => (int)$leave['id'],
                'leave_type' => $leave['leave_type'],
                'start_date' => $leave['start_date'],
                'end_date' => $leave['end_date'],
                'reason' => $leave['reason'],
                'message' => "Staff member '{$staffName}' is on {$leave['leave_type']} leave from " . date('d M', strtotime($leave['start_date'])) . " to " . date('d M Y', strtotime($leave['end_date'])) . "."
            ];
        }

        // 2. Check for overlapping active shift deployment
        $sql = "
            SELECT d.*, c.company_name, s.site_name, sh.shift_name, stf.full_name as staff_name,
                   a.id as att_id, a.status as attendance_status
            FROM deployments d
            JOIN clients c ON d.client_id = c.id
            JOIN sites s ON d.site_id = s.id
            JOIN shifts sh ON d.shift_id = sh.id
            JOIN staff stf ON d.staff_id = stf.id
            LEFT JOIN attendance a ON a.deployment_id = d.id
            WHERE d.staff_id = :stfid 
              AND d.shift_id = :shid 
              AND d.deployment_date = :dt 
              AND d.status != 'cancelled'
              AND (a.status IS NULL OR a.status != 'absent')
        ";
        if ($ignoreDeploymentId) {
            $sql .= " AND d.id != :ignId";
        }
        $sql .= " LIMIT 1";

        $stmt = $db->prepare($sql);
        $params = [
            ':stfid' => $staffId,
            ':shid'  => $shiftId,
            ':dt'    => $deploymentDate
        ];
        if ($ignoreDeploymentId) {
            $params[':ignId'] = $ignoreDeploymentId;
        }

        $stmt->execute($params);
        $conflict = $stmt->fetch();
        return $conflict ?: null;
    }

    /**
     * Lookup the exact applicable rates and snapshot them permanently.
     * Hierarchy: Rate Cards (Exact) -> Rate Cards (Site/Shift) -> Site Defaults -> Client & Staff Defaults
     */
    public static function lookupRates(int $clientId, int $siteId, int $shiftId, int $staffId, string $date): array {
        $db = DB::getConnection();

        // Fetch staff profile and linked category
        $stfStmt = $db->prepare("
            SELECT s.id, s.category_id, s.default_payout_rate, s.skill_category,
                   sc.category_name, sc.category_code
            FROM staff s
            LEFT JOIN staff_categories sc ON s.category_id = sc.id
            WHERE s.id = :stfid
        ");
        $stfStmt->execute([':stfid' => $staffId]);
        $staff = $stfStmt->fetch();
        $categoryId = !empty($staff['category_id']) ? (int)$staff['category_id'] : null;
        $categoryName = $staff['category_name'] ?? $staff['skill_category'] ?? 'General Staff';
        // Staff payout rate is strictly governed by the staff member's profile in the Staff Workforce menu
        $staffPayoutRate = !empty($staff['default_payout_rate']) ? (float)$staff['default_payout_rate'] : 550.00;

        // 1. Specific Staff Override for this Client + Site + Shift
        $stmt = $db->prepare("
            SELECT client_billing_rate 
            FROM rate_cards 
            WHERE client_id = :cid AND site_id = :sid AND shift_id = :shid AND staff_id = :stfid
              AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $clientId, ':sid' => $siteId, ':shid' => $shiftId, ':stfid' => $staffId, ':dt' => $date]);
        if ($row = $stmt->fetch()) {
            return [
                'client_rate' => (float)$row['client_billing_rate'],
                'staff_rate'  => $staffPayoutRate,
                'source'      => 'Specific Staff Override (Client+Site+Shift+Staff)'
            ];
        }

        // 2. Specific Staff Override for this Client
        $stmt = $db->prepare("
            SELECT client_billing_rate 
            FROM rate_cards 
            WHERE client_id = :cid AND staff_id = :stfid AND site_id IS NULL AND shift_id IS NULL
              AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $clientId, ':stfid' => $staffId, ':dt' => $date]);
        if ($row = $stmt->fetch()) {
            return [
                'client_rate' => (float)$row['client_billing_rate'],
                'staff_rate'  => $staffPayoutRate,
                'source'      => 'Specific Staff Client Rate Card'
            ];
        }

        // 3. Client + Category + Site + Shift
        if ($categoryId) {
            $stmt = $db->prepare("
                SELECT client_billing_rate 
                FROM rate_cards 
                WHERE client_id = :cid AND category_id = :catid AND site_id = :sid AND shift_id = :shid AND staff_id IS NULL
                  AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':cid' => $clientId, ':catid' => $categoryId, ':sid' => $siteId, ':shid' => $shiftId, ':dt' => $date]);
            if ($row = $stmt->fetch()) {
                return [
                    'client_rate' => (float)$row['client_billing_rate'],
                    'staff_rate'  => $staffPayoutRate,
                    'source'      => "Client Category Site & Shift Rate ({$categoryName})"
                ];
            }

            // 4. Client + Category + Shift
            $stmt = $db->prepare("
                SELECT client_billing_rate 
                FROM rate_cards 
                WHERE client_id = :cid AND category_id = :catid AND shift_id = :shid AND site_id IS NULL AND staff_id IS NULL
                  AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':cid' => $clientId, ':catid' => $categoryId, ':shid' => $shiftId, ':dt' => $date]);
            if ($row = $stmt->fetch()) {
                return [
                    'client_rate' => (float)$row['client_billing_rate'],
                    'staff_rate'  => $staffPayoutRate,
                    'source'      => "Client Category Shift Rate ({$categoryName})"
                ];
            }

            // 5. Client + Category + Site
            $stmt = $db->prepare("
                SELECT client_billing_rate 
                FROM rate_cards 
                WHERE client_id = :cid AND category_id = :catid AND site_id = :sid AND shift_id IS NULL AND staff_id IS NULL
                  AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':cid' => $clientId, ':catid' => $categoryId, ':sid' => $siteId, ':dt' => $date]);
            if ($row = $stmt->fetch()) {
                return [
                    'client_rate' => (float)$row['client_billing_rate'],
                    'staff_rate'  => $staffPayoutRate,
                    'source'      => "Client Category Site Rate ({$categoryName})"
                ];
            }

            // 6. Primary Requirement: Client + Category (Applicable across all shifts and sites for this client)
            $stmt = $db->prepare("
                SELECT client_billing_rate 
                FROM rate_cards 
                WHERE client_id = :cid AND category_id = :catid AND site_id IS NULL AND shift_id IS NULL AND staff_id IS NULL
                  AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':cid' => $clientId, ':catid' => $categoryId, ':dt' => $date]);
            if ($row = $stmt->fetch()) {
                return [
                    'client_rate' => (float)$row['client_billing_rate'],
                    'staff_rate'  => $staffPayoutRate,
                    'source'      => "Client Category Rate ({$categoryName})"
                ];
            }
        }

        // 7. General Rate Card (Client + Site + Shift)
        $stmt = $db->prepare("
            SELECT client_billing_rate 
            FROM rate_cards 
            WHERE client_id = :cid AND site_id = :sid AND shift_id = :shid AND staff_id IS NULL AND category_id IS NULL
              AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $clientId, ':sid' => $siteId, ':shid' => $shiftId, ':dt' => $date]);
        if ($row = $stmt->fetch()) {
            return [
                'client_rate' => (float)$row['client_billing_rate'],
                'staff_rate'  => $staffPayoutRate,
                'source'      => 'General Site & Shift Rate Card'
            ];
        }

        // 8. General Rate Card (Client + Shift)
        $stmt = $db->prepare("
            SELECT client_billing_rate 
            FROM rate_cards 
            WHERE client_id = :cid AND site_id IS NULL AND shift_id = :shid AND staff_id IS NULL AND category_id IS NULL
              AND effective_from <= :dt AND (effective_to IS NULL OR effective_to >= :dt)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $clientId, ':shid' => $shiftId, ':dt' => $date]);
        if ($row = $stmt->fetch()) {
            return [
                'client_rate' => (float)$row['client_billing_rate'],
                'staff_rate'  => $staffPayoutRate,
                'source'      => 'General Client & Shift Rate Card'
            ];
        }

        // 9. Fetch Site Overrides, Client Default & Staff Default
        $sStmt = $db->prepare("SELECT default_client_rate, default_staff_rate FROM sites WHERE id = :sid");
        $sStmt->execute([':sid' => $siteId]);
        $site = $sStmt->fetch();

        $cStmt = $db->prepare("SELECT default_billing_rate FROM clients WHERE id = :cid");
        $cStmt->execute([':cid' => $clientId]);
        $client = $cStmt->fetch();

        $clientRate = !empty($site['default_client_rate']) ? (float)$site['default_client_rate'] : (float)($client['default_billing_rate'] ?? 800.00);

        return [
            'client_rate' => $clientRate,
            'staff_rate'  => $staffPayoutRate,
            'source'      => !empty($site['default_client_rate']) ? 'Site Default Override' : 'Client & Staff Profile Defaults'
        ];
    }

    /**
     * Create a new deployment with guaranteed conflict prevention and rate snapshot
     */
    public static function createDeployment(array $data): int {
        $db = DB::getConnection();

        $staffId = (int)$data['staff_id'];
        $clientId = (int)$data['client_id'];
        $siteId = (int)$data['site_id'];
        $shiftId = (int)$data['shift_id'];
        $date = $data['deployment_date'];
        $notes = $data['notes'] ?? '';

        // Conflict check
        $conflict = self::checkConflict($staffId, $shiftId, $date);
        if ($conflict) {
            throw new Exception("Conflict Detected: Staff member '{$conflict['staff_name']}' is already assigned to '{$conflict['company_name']} ({$conflict['site_name']})' for this shift on {$date}.");
        }

        // Rates snapshot
        if (isset($data['client_rate']) && isset($data['staff_rate']) && is_numeric($data['client_rate']) && is_numeric($data['staff_rate'])) {
            $clientRate = (float)$data['client_rate'];
            $staffRate = (float)$data['staff_rate'];
        } else {
            $rates = self::lookupRates($clientId, $siteId, $shiftId, $staffId, $date);
            $clientRate = $rates['client_rate'];
            $staffRate = $rates['staff_rate'];
        }

        // Fetch shift duration
        $shStmt = $db->prepare("SELECT duration_hours FROM shifts WHERE id = :id");
        $shStmt->execute([':id' => $shiftId]);
        $shift = $shStmt->fetch();
        $duration = (float)($shift['duration_hours'] ?? 8.00);

        $db->beginTransaction();
        try {
            // Check if a deployment row already exists for this unique slot (e.g. previously cancelled or marked absent)
            $existStmt = $db->prepare("SELECT id FROM deployments WHERE deployment_date = :dt AND staff_id = :stfid AND shift_id = :shid LIMIT 1");
            $existStmt->execute([':dt' => $date, ':stfid' => $staffId, ':shid' => $shiftId]);
            $existingId = (int)$existStmt->fetchColumn();

            if ($existingId > 0) {
                // Re-activate and update existing deployment
                $stmt = $db->prepare("
                    UPDATE deployments 
                    SET client_id = :cid, site_id = :sid, client_rate = :crate, staff_rate = :srate,
                        status = 'scheduled', notes = :notes, created_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':cid'   => $clientId,
                    ':sid'   => $siteId,
                    ':crate' => $clientRate,
                    ':srate' => $staffRate,
                    ':notes' => $notes,
                    ':uid'   => Auth::id(),
                    ':id'    => $existingId
                ]);
                $deployId = $existingId;

                // Reset attendance record to scheduled
                $attStmt = $db->prepare("
                    INSERT INTO attendance (deployment_id, attendance_date, staff_id, client_id, site_id, shift_id,
                                            status, hours_worked, billable_rate_multiplier, final_client_amount,
                                            final_staff_amount, is_billed, is_settled, supervisor_verified)
                    VALUES (:did, :dt, :stfid, :cid, :sid, :shid, 'scheduled', 0.00, 0.00, 0.00, 0.00, 0, 0, 0)
                    ON DUPLICATE KEY UPDATE 
                        client_id = VALUES(client_id),
                        site_id = VALUES(site_id),
                        shift_id = VALUES(shift_id),
                        status = 'scheduled',
                        hours_worked = 0.00,
                        billable_rate_multiplier = 0.00,
                        final_client_amount = 0.00,
                        final_staff_amount = 0.00,
                        is_billed = 0,
                        is_settled = 0,
                        supervisor_verified = 0
                ");
                $attStmt->execute([
                    ':did'   => $deployId,
                    ':dt'    => $date,
                    ':stfid' => $staffId,
                    ':cid'   => $clientId,
                    ':sid'   => $siteId,
                    ':shid'  => $shiftId
                ]);
            } else {
                // Insert new deployment
                $stmt = $db->prepare("
                    INSERT INTO deployments (deployment_date, staff_id, client_id, site_id, shift_id, client_rate, staff_rate, status, notes, created_by)
                    VALUES (:dt, :stfid, :cid, :sid, :shid, :crate, :srate, 'scheduled', :notes, :uid)
                ");
                $stmt->execute([
                    ':dt'    => $date,
                    ':stfid' => $staffId,
                    ':cid'   => $clientId,
                    ':sid'   => $siteId,
                    ':shid'  => $shiftId,
                    ':crate' => $clientRate,
                    ':srate' => $staffRate,
                    ':notes' => $notes,
                    ':uid'   => Auth::id()
                ]);
                $deployId = (int)$db->lastInsertId();

                // Initialize Attendance Record (Scheduled by default)
                $attStmt = $db->prepare("
                    INSERT INTO attendance (deployment_id, attendance_date, staff_id, client_id, site_id, shift_id,
                                            status, hours_worked, billable_rate_multiplier, final_client_amount,
                                            final_staff_amount, is_billed, is_settled, supervisor_verified)
                    VALUES (:did, :dt, :stfid, :cid, :sid, :shid, 'scheduled', 0.00, 0.00, 0.00, 0.00, 0, 0, 0)
                ");
                $attStmt->execute([
                    ':did'   => $deployId,
                    ':dt'    => $date,
                    ':stfid' => $staffId,
                    ':cid'   => $clientId,
                    ':sid'   => $siteId,
                    ':shid'  => $shiftId
                ]);
            }

            $db->commit();
            Audit::log('CREATE_OR_UPDATE', 'DEPLOYMENTS', $deployId, null, [
                'date' => $date, 'staff_id' => $staffId, 'client_id' => $clientId, 'site_id' => $siteId, 'shift_id' => $shiftId,
                'client_rate' => $clientRate, 'staff_rate' => $staffRate
            ]);

            return $deployId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Move an existing scheduled deployment to a new venue site.
     * Prevents moving if the staff member is already marked Present/Half-day.
     */
    public static function moveDeployment(int $deployId, int $newClientId, int $newSiteId, ?int $newShiftId = null): bool {
        $db = DB::getConnection();
        $dStmt = $db->prepare("SELECT * FROM deployments WHERE id = :id");
        $dStmt->execute([':id' => $deployId]);
        $deploy = $dStmt->fetch();
        if (!$deploy) {
            throw new Exception("Deployment #{$deployId} not found.");
        }

        $staffId = (int)$deploy['staff_id'];
        $date = $deploy['deployment_date'];
        $targetShiftId = $newShiftId ?: (int)$deploy['shift_id'];

        // Check attendance status: Disallow moving if already marked present
        $attStmt = $db->prepare("SELECT status FROM attendance WHERE deployment_id = :did");
        $attStmt->execute([':did' => $deployId]);
        $attStatus = $attStmt->fetchColumn();
        if (in_array($attStatus, ['present', 'half_day'])) {
            throw new Exception("Staff member is already marked PRESENT and cannot be moved.");
        }

        // Lookup applicable rates for the new site
        $rates = self::lookupRates($newClientId, $newSiteId, $targetShiftId, $staffId, $date);

        $db->beginTransaction();
        try {
            $upd = $db->prepare("
                UPDATE deployments 
                SET client_id = :cid, site_id = :sid, shift_id = :shid,
                    client_rate = :crate, staff_rate = :srate,
                    status = 'scheduled', notes = CONCAT(COALESCE(notes, ''), ' [Re-mapped]'),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':cid'   => $newClientId,
                ':sid'   => $newSiteId,
                ':shid'  => $targetShiftId,
                ':crate' => $rates['client_rate'],
                ':srate' => $rates['staff_rate'],
                ':id'    => $deployId
            ]);

            $attUpd = $db->prepare("
                UPDATE attendance 
                SET client_id = :cid, site_id = :sid, shift_id = :shid,
                    status = 'scheduled', hours_worked = 0.00,
                    billable_rate_multiplier = 0.00, final_client_amount = 0.00,
                    final_staff_amount = 0.00, is_billed = 0, is_settled = 0
                WHERE deployment_id = :did
            ");
            $attUpd->execute([
                ':cid'  => $newClientId,
                ':sid'  => $newSiteId,
                ':shid' => $targetShiftId,
                ':did'  => $deployId
            ]);

            $db->commit();
            Audit::log('MOVE', 'DEPLOYMENTS', $deployId, null, [
                'new_client_id' => $newClientId,
                'new_site_id'   => $newSiteId
            ]);
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Swap venues between two active scheduled staff deployments.
     */
    public static function swapDeployments(int $deployId1, int $deployId2): array {
        $db = DB::getConnection();

        $fetchStmt = $db->prepare("
            SELECT d.*, c.company_name, s.site_name, stf.full_name as staff_name,
                   a.status as att_status
            FROM deployments d
            JOIN clients c ON d.client_id = c.id
            JOIN sites s ON d.site_id = s.id
            JOIN staff stf ON d.staff_id = stf.id
            LEFT JOIN attendance a ON a.deployment_id = d.id
            WHERE d.id = :id AND d.status != 'cancelled'
        ");

        $fetchStmt->execute([':id' => $deployId1]);
        $dep1 = $fetchStmt->fetch();
        $fetchStmt->execute([':id' => $deployId2]);
        $dep2 = $fetchStmt->fetch();

        if (!$dep1 || !$dep2) {
            throw new Exception("One or both deployments could not be found or have been cancelled.");
        }

        $att1 = strtolower($dep1['att_status'] ?? 'scheduled');
        $att2 = strtolower($dep2['att_status'] ?? 'scheduled');
        if (in_array($att1, ['present', 'half_day']) || in_array($att2, ['present', 'half_day'])) {
            throw new Exception("Cannot swap: One or both staff members are already marked Present.");
        }

        // Calculate new rates:
        // Staff 1 goes to Venue 2 (Client 2, Site 2, Shift 2)
        $rates1 = self::lookupRates((int)$dep2['client_id'], (int)$dep2['site_id'], (int)$dep2['shift_id'], (int)$dep1['staff_id'], $dep1['deployment_date']);
        // Staff 2 goes to Venue 1 (Client 1, Site 1, Shift 1)
        $rates2 = self::lookupRates((int)$dep1['client_id'], (int)$dep1['site_id'], (int)$dep1['shift_id'], (int)$dep2['staff_id'], $dep2['deployment_date']);

        $db->beginTransaction();
        try {
            // Update Deploy 1 with Venue 2 info
            $upd1 = $db->prepare("
                UPDATE deployments 
                SET client_id = :cid, site_id = :sid, shift_id = :shid,
                    client_rate = :crate, staff_rate = :srate,
                    notes = CONCAT(COALESCE(notes, ''), ' [Swapped with ', :other_staff, ']'),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upd1->execute([
                ':cid'         => $dep2['client_id'],
                ':sid'         => $dep2['site_id'],
                ':shid'        => $dep2['shift_id'],
                ':crate'       => $rates1['client_rate'],
                ':srate'       => $rates1['staff_rate'],
                ':other_staff' => $dep2['staff_name'],
                ':id'          => $deployId1
            ]);

            // Update Deploy 2 with Venue 1 info
            $upd2 = $db->prepare("
                UPDATE deployments 
                SET client_id = :cid, site_id = :sid, shift_id = :shid,
                    client_rate = :crate, staff_rate = :srate,
                    notes = CONCAT(COALESCE(notes, ''), ' [Swapped with ', :other_staff, ']'),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upd2->execute([
                ':cid'         => $dep1['client_id'],
                ':sid'         => $dep1['site_id'],
                ':shid'        => $dep1['shift_id'],
                ':crate'       => $rates2['client_rate'],
                ':srate'       => $rates2['staff_rate'],
                ':other_staff' => $dep1['staff_name'],
                ':id'          => $deployId2
            ]);

            // Update attendance records
            $attUpd = $db->prepare("
                UPDATE attendance 
                SET client_id = :cid, site_id = :sid, shift_id = :shid,
                    status = 'scheduled', hours_worked = 0.00
                WHERE deployment_id = :did
            ");
            $attUpd->execute([
                ':cid'  => $dep2['client_id'],
                ':sid'  => $dep2['site_id'],
                ':shid' => $dep2['shift_id'],
                ':did'  => $deployId1
            ]);
            $attUpd->execute([
                ':cid'  => $dep1['client_id'],
                ':sid'  => $dep1['site_id'],
                ':shid' => $dep1['shift_id'],
                ':did'  => $deployId2
            ]);

            $db->commit();
            Audit::log('SWAP', 'DEPLOYMENTS', $deployId1, null, [
                'swapped_with' => $deployId2,
                'staff1' => $dep1['staff_name'],
                'staff2' => $dep2['staff_name']
            ]);

            return [
                'staff1_name' => $dep1['staff_name'],
                'staff2_name' => $dep2['staff_name'],
                'venue1'      => "{$dep1['company_name']} ({$dep1['site_name']})",
                'venue2'      => "{$dep2['company_name']} ({$dep2['site_name']})"
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel/unassign an existing deployment.
     */
    public static function cancelDeployment(int $deploymentId, string $reason = ''): bool {
        $db = DB::getConnection();
        $stmt = $db->prepare("UPDATE deployments SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ' [Cancelled: ', :reason, ']') WHERE id = :id");
        $stmt->execute([':id' => $deploymentId, ':reason' => $reason ?: 'Manual unassign']);

        $attStmt = $db->prepare("UPDATE attendance SET status = 'absent', final_client_amount = 0, final_staff_amount = 0, remarks = CONCAT(COALESCE(remarks, ''), ' [Cancelled]') WHERE deployment_id = :did");
        $attStmt->execute([':did' => $deploymentId]);

        Audit::log('CANCEL', 'DEPLOYMENTS', $deploymentId, null, ['reason' => $reason]);
        return true;
    }

    /**
     * Save staff leave (single day or multi-day range, e.g. 10 days).
     */
    public static function saveStaffLeave(int $staffId, string $startDate, string $endDate, string $leaveType = 'casual', string $reason = ''): int {
        $db = DB::getConnection();

        // If employee has any scheduled deployments in this leave range, cancel them
        $depStmt = $db->prepare("
            SELECT id FROM deployments 
            WHERE staff_id = :stfid 
              AND deployment_date >= :sdt 
              AND deployment_date <= :edt 
              AND status != 'cancelled'
        ");
        $depStmt->execute([':stfid' => $staffId, ':sdt' => $startDate, ':edt' => $endDate]);
        $conflictedDeploys = $depStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($conflictedDeploys as $dId) {
            self::cancelDeployment((int)$dId, "Staff on {$leaveType} leave ({$startDate} to {$endDate})");
        }

        $stmt = $db->prepare("
            INSERT INTO staff_leaves (staff_id, leave_type, start_date, end_date, reason, status, created_by)
            VALUES (:stfid, :ltype, :sdt, :edt, :reason, 'active', :uid)
        ");
        $stmt->execute([
            ':stfid'  => $staffId,
            ':ltype'  => $leaveType,
            ':sdt'    => $startDate,
            ':edt'    => $endDate,
            ':reason' => $reason,
            ':uid'    => Auth::id()
        ]);
        $leaveId = (int)$db->lastInsertId();
        Audit::log('CREATE', 'STAFF_LEAVES', $leaveId, null, [
            'staff_id' => $staffId, 'start_date' => $startDate, 'end_date' => $endDate, 'leave_type' => $leaveType, 'reason' => $reason
        ]);
        return $leaveId;
    }

    /**
     * Cancel an active leave record to reactivate the worker.
     */
    public static function cancelStaffLeave(int $leaveId): bool {
        $db = DB::getConnection();
        $stmt = $db->prepare("UPDATE staff_leaves SET status = 'cancelled' WHERE id = :id");
        $stmt->execute([':id' => $leaveId]);
        Audit::log('CANCEL', 'STAFF_LEAVES', $leaveId, null, null);
        return true;
    }
}
