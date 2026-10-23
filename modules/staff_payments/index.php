<?php
// modules/staff_payments/index.php - Staff salary management has been merged into Staff Profile & Ledger
require_once dirname(__DIR__, 2) . '/config/config.php';

// Redirect to Staff Workforce directory where individual profiles & ledgers manage payouts
header("Location: " . BASE_URL . "/modules/staff/index.php");
exit;
