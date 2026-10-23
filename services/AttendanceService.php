<?php
// services/AttendanceService.php - Attendance Processing, Multipliers & Overnight Calculation

declare(strict_types=1);

class AttendanceService {
    /**
     * Update attendance record with status, hours, multipliers and amounts
     */
    public static function processAttendance(int $attendanceId, array $data): void {
        $db = DB::getConnection();

        // Fetch existing attendance + deployment + site + shift details
        $stmt = $db->prepare("
            SELECT a.*, d.client_rate, d.staff_rate, d.deployment_date,
                   s.latitude as site_lat, s.longitude as site_lng, s.geofence_radius_meters,
                   sh.is_overnight, sh.start_time, sh.end_time, sh.duration_hours as default_shift_hours
            FROM attendance a
            JOIN deployments d ON a.deployment_id = d.id
            JOIN sites s ON a.site_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => $attendanceId]);
        $att = $stmt->fetch();

        if (!$att) {
            throw new Exception("Attendance record #{$attendanceId} not found.");
        }

        $status = strtolower($data['status'] ?? 'present');
        $attDate = $att['attendance_date'] ?? $att['deployment_date'] ?? null;
        if (in_array($status, ['present', 'half_day']) && $attDate && strtotime($attDate) > strtotime(date('Y-m-d'))) {
            throw new Exception("Cannot mark Present or Half-Day for future date (" . date('d/m/Y', strtotime($attDate)) . "). Staff attendance can only be confirmed on or before today.");
        }
        $checkInTime = !empty($data['check_in_time']) ? $data['check_in_time'] : null;
        $checkOutTime = !empty($data['check_out_time']) ? $data['check_out_time'] : null;
        $checkInLat = !empty($data['check_in_lat']) ? (float)$data['check_in_lat'] : null;
        $checkInLng = !empty($data['check_in_lng']) ? (float)$data['check_in_lng'] : null;
        $checkOutLat = !empty($data['check_out_lat']) ? (float)$data['check_out_lat'] : null;
        $checkOutLng = !empty($data['check_out_lng']) ? (float)$data['check_out_lng'] : null;
        $remarks = trim($data['remarks'] ?? '');

        // Determine multiplier based on status
        $multiplier = match($status) {
            'present'   => 1.00,
            'half_day'  => 0.50,
            'absent'    => 0.00,
            'leave'     => 0.00,
            'scheduled' => 0.00,
            default     => 0.00
        };

        $isVerified = in_array($status, ['present', 'half_day', 'absent', 'leave']) ? 1 : 0;

        // Calculate hours worked
        $hoursWorked = (float)$att['default_shift_hours'];
        if ($status === 'half_day') {
            $hoursWorked = round($hoursWorked / 2, 2);
        } elseif ($status === 'absent' || $status === 'leave' || $status === 'scheduled') {
            $hoursWorked = 0.00;
        } elseif ($checkInTime && $checkOutTime) {
            $inTs = strtotime($checkInTime);
            $outTs = strtotime($checkOutTime);
            if ($outTs > $inTs) {
                $hoursWorked = round(($outTs - $inTs) / 3600, 2);
            }
        }

        // Calculate final amounts from snapshotted deployment rates
        $finalClientAmount = round((float)$att['client_rate'] * $multiplier, 2);
        $finalStaffAmount  = round((float)$att['staff_rate'] * $multiplier, 2);

        // GPS Geofence verification
        $gpsVerified = 0;
        if ($checkInLat && $checkInLng && !empty($att['site_lat']) && !empty($att['site_lng'])) {
            $dist = Helper::calculateDistance($checkInLat, $checkInLng, (float)$att['site_lat'], (float)$att['site_lng']);
            if ($dist <= (int)$att['geofence_radius_meters']) {
                $gpsVerified = 1;
            }
        }

        // Update database
        $updStmt = $db->prepare("
            UPDATE attendance 
            SET status = :st,
                check_in_time = :cin,
                check_out_time = :cout,
                check_in_lat = :inlat,
                check_in_lng = :inlng,
                check_out_lat = :outlat,
                check_out_lng = :outlng,
                gps_verified = :gpsv,
                hours_worked = :hrs,
                billable_rate_multiplier = :mult,
                final_client_amount = :fca,
                final_staff_amount = :fsa,
                supervisor_verified = :supv,
                supervisor_id = :supid,
                remarks = :rem,
                updated_at = NOW()
            WHERE id = :id
        ");

        $updStmt->execute([
            ':st'     => $status,
            ':cin'    => $checkInTime,
            ':cout'   => $checkOutTime,
            ':inlat'  => $checkInLat,
            ':inlng'  => $checkInLng,
            ':outlat' => $checkOutLat,
            ':outlng' => $checkOutLng,
            ':gpsv'   => $gpsVerified,
            ':hrs'    => $hoursWorked,
            ':mult'   => $multiplier,
            ':fca'    => $finalClientAmount,
            ':fsa'    => $finalStaffAmount,
            ':supv'   => $isVerified,
            ':supid'  => $isVerified ? Auth::id() : null,
            ':rem'    => $remarks,
            ':id'     => $attendanceId
        ]);

        Audit::log('UPDATE', 'ATTENDANCE', $attendanceId, $att, [
            'status' => $status, 'multiplier' => $multiplier, 'final_client' => $finalClientAmount, 'final_staff' => $finalStaffAmount
        ]);
    }
}
