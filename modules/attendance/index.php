<?php
// modules/attendance/index.php - Shift Attendance is now integrated into the Excel Duty Roster
require_once dirname(__DIR__, 2) . '/config/config.php';

$date = $_GET['date'] ?? date('Y-m-d');
$shiftId = !empty($_GET['shift_id']) ? (int)$_GET['shift_id'] : 0;

// Redirect directly to Duty Roster
header("Location: " . BASE_URL . "/modules/mapping/index.php?date={$date}&shift_id={$shiftId}");
exit;
