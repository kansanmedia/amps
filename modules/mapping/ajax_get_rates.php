<?php
// modules/mapping/ajax_get_rates.php - Live Rate Lookup & Conflict Checking Endpoint
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/services/DeploymentService.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_sites') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    $db = DB::getConnection();
    $stmt = $db->prepare("SELECT id, site_name, default_client_rate, default_staff_rate FROM sites WHERE client_id = :cid AND status = 'active' ORDER BY site_name ASC");
    $stmt->execute([':cid' => $clientId]);
    $sites = $stmt->fetchAll();
    echo json_encode(['success' => true, 'sites' => $sites]);
    exit;
}

if ($action === 'check_staff_status') {
    $staffId = (int)($_GET['staff_id'] ?? 0);
    $shiftId = (int)($_GET['shift_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    $clientId = (int)($_GET['client_id'] ?? 0);
    $siteId = (int)($_GET['site_id'] ?? 0);

    $conflict = DeploymentService::checkConflict($staffId, $shiftId, $date);
    $rates = ($clientId && $siteId && $shiftId && $staffId) ? DeploymentService::lookupRates($clientId, $siteId, $shiftId, $staffId, $date) : null;

    echo json_encode([
        'success'  => true,
        'conflict' => $conflict ? true : false,
        'conflict_details' => $conflict,
        'rates'    => $rates
    ]);
    exit;
}

if ($action === 'get_busy_staff') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $shiftId = (int)($_GET['shift_id'] ?? 0);
    $db = DB::getConnection();
    $stmt = $db->prepare("
        SELECT d.staff_id, d.client_id, d.site_id, c.company_name, s.site_name, stf.full_name
        FROM deployments d
        JOIN clients c ON d.client_id = c.id
        JOIN sites s ON d.site_id = s.id
        JOIN staff stf ON d.staff_id = stf.id
        WHERE d.deployment_date = :dt AND d.shift_id = :shid AND d.status != 'cancelled'
    ");
    $stmt->execute([':dt' => $date, ':shid' => $shiftId]);
    $busy = $stmt->fetchAll();
    echo json_encode([
        'success' => true,
        'busy_staff_ids' => array_map('intval', array_column($busy, 'staff_id')),
        'busy_count' => count($busy),
        'busy_details' => $busy
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
