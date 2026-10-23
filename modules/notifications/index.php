<?php
// modules/notifications/index.php - WhatsApp, SMS & Email Broadcast Hub
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager']);

$db = DB::getConnection();

// Fetch upcoming tomorrow deployments to show quick WhatsApp alert triggers
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$deployments = $db->query("
    SELECT d.*, c.company_name, s.site_name, s.location_address, sh.shift_name, sh.start_time,
           stf.full_name as staff_name, stf.phone as staff_phone
    FROM deployments d
    JOIN clients c ON d.client_id = c.id
    JOIN sites s ON d.site_id = s.id
    JOIN shifts sh ON d.shift_id = sh.id
    JOIN staff stf ON d.staff_id = stf.id
    WHERE d.deployment_date >= CURDATE() AND d.status != 'cancelled'
    ORDER BY d.deployment_date ASC, sh.start_time ASC
    LIMIT 20
")->fetchAll();

$pageTitle = 'Notifications & WhatsApp Hub';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-chat-left-dots-fill text-primary me-2"></i>Notifications & WhatsApp Dispatcher</h4>
        <p class="text-muted small mb-0">Send 1-click deployment alerts, shift reminders, invoice delivery, and wage confirmations via WhatsApp.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Quick Deployment Dispatch -->
    <div class="col-lg-8">
        <div class="custom-card">
            <div class="custom-card-header">
                <h6 class="fw-bold mb-0"><i class="bi bi-whatsapp text-success me-1"></i> Send Deployment Alerts to Staff (Today & Upcoming)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Staff Worker</th>
                            <th>Venue & Client</th>
                            <th>Shift Time</th>
                            <th class="text-end">WhatsApp Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deployments)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No scheduled deployments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($deployments as $d): 
                                $msg = "Namaste {$d['staff_name']} ji! You are scheduled for work on " . date('d M Y', strtotime($d['deployment_date'])) . ". Venue: {$d['site_name']} ({$d['company_name']}). Shift: {$d['shift_name']} from " . date('h:i A', strtotime($d['start_time'])) . ". Address: {$d['location_address']}. Please report 15 mins early in proper uniform. - Kansan Manpower";
                                $waLink = "https://wa.me/91" . preg_replace('/[^0-9]/', '', $d['staff_phone']) . "?text=" . urlencode($msg);
                            ?>
                                <tr>
                                    <td><strong><?= Helper::formatDate($d['deployment_date']) ?></strong></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($d['staff_name']) ?></div>
                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($d['staff_phone']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($d['site_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($d['company_name']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($d['shift_name']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= $waLink ?>" target="_blank" class="btn btn-sm btn-success">
                                            <i class="bi bi-whatsapp me-1"></i> Send Alert
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Notification Templates Showcase -->
    <div class="col-lg-4">
        <div class="custom-card p-4">
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-file-text text-primary me-1"></i> Standard Notification Templates</h6>

            <div class="mb-3 p-3 bg-light rounded border small">
                <strong class="text-dark d-block mb-1"><i class="bi bi-person-check text-primary me-1"></i> 1. Shift Deployment Alert</strong>
                <p class="text-muted mb-0">"Namaste {Staff_Name}! You are deployed to {Venue_Name} for {Shift_Name} on {Date}. Reporting: {Time}."</p>
            </div>

            <div class="mb-3 p-3 bg-light rounded border small">
                <strong class="text-dark d-block mb-1"><i class="bi bi-receipt text-success me-1"></i> 2. Client Invoice Delivery</strong>
                <p class="text-muted mb-0">"Dear {Client_Name}, Invoice #{Invoice_Number} for amount {Amount} has been generated. Due date: {Due_Date}."</p>
            </div>

            <div class="p-3 bg-light rounded border small">
                <strong class="text-dark d-block mb-1"><i class="bi bi-cash-coin text-warning-emphasis me-1"></i> 3. Salary Payout Confirmation</strong>
                <p class="text-muted mb-0">"Dear {Staff_Name}, your shift wages payout of {Amount} has been transferred via {Payment_Mode}. Voucher #{Voucher}."</p>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>
