<?php
// modules/sites/index.php - Client Site & Location Management
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::requireRole(['owner_admin', 'manager']);

$db = DB::getConnection();
$error = '';

$filterClientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;

// Handle Site Create / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_site') {
        $siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;
        $clientId = (int)($_POST['client_id'] ?? 0);
        $siteCode = strtoupper(trim($_POST['site_code'] ?? ''));
        $siteName = trim($_POST['site_name'] ?? '');
        $locationAddress = trim($_POST['location_address'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $geofenceRadius = (int)($_POST['geofence_radius_meters'] ?? 250);
        $defaultClientRate = !empty($_POST['default_client_rate']) ? (float)$_POST['default_client_rate'] : null;
        $defaultStaffRate = null; // Staff payout is set in Staff Workforce menu, not from client/site
        $status = $_POST['status'] ?? 'active';

        if (empty($clientId) || empty($siteName) || empty($locationAddress)) {
            $error = 'Client, Site Name, and Address are required.';
        } else {
            if (empty($siteCode)) {
                $siteCode = 'SIT-' . rand(100, 999);
            }

            if ($siteId) {
                $stmt = $db->prepare("
                    UPDATE sites SET client_id=:cid, site_code=:code, site_name=:name, location_address=:addr,
                        contact_person=:cp, contact_phone=:cph, latitude=:lat, longitude=:lng,
                        geofence_radius_meters=:geo, default_client_rate=:dcr, default_staff_rate=:dsr, status=:st
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':cid' => $clientId, ':code' => $siteCode, ':name' => $siteName, ':addr' => $locationAddress,
                    ':cp' => $contactPerson, ':cph' => $contactPhone, ':lat' => $latitude, ':lng' => $longitude,
                    ':geo' => $geofenceRadius, ':dcr' => $defaultClientRate, ':dsr' => $defaultStaffRate,
                    ':st' => $status, ':id' => $siteId
                ]);
                Audit::log('UPDATE', 'SITES', $siteId, null, $_POST);
                Helper::setFlash('success', "Site '{$siteName}' updated successfully.");
            } else {
                $stmt = $db->prepare("
                    INSERT INTO sites (client_id, site_code, site_name, location_address, contact_person,
                                       contact_phone, latitude, longitude, geofence_radius_meters,
                                       default_client_rate, default_staff_rate, status)
                    VALUES (:cid, :code, :name, :addr, :cp, :cph, :lat, :lng, :geo, :dcr, :dsr, :st)
                ");
                $stmt->execute([
                    ':cid' => $clientId, ':code' => $siteCode, ':name' => $siteName, ':addr' => $locationAddress,
                    ':cp' => $contactPerson, ':cph' => $contactPhone, ':lat' => $latitude, ':lng' => $longitude,
                    ':geo' => $geofenceRadius, ':dcr' => $defaultClientRate, ':dsr' => $defaultStaffRate, ':st' => $status
                ]);
                $newId = (int)$db->lastInsertId();
                Audit::log('CREATE', 'SITES', $newId, null, $_POST);
                Helper::setFlash('success', "Site '{$siteName}' created successfully.");
            }
            $redirectUrl = 'modules/sites/index.php' . ($filterClientId ? "?client_id={$filterClientId}" : '');
            Helper::redirect($redirectUrl);
        }
    }
}

// Fetch all active clients for dropdown
$clientsList = $db->query("SELECT id, company_name, default_billing_rate FROM clients WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();

// Fetch sites
$sql = "
    SELECT s.*, c.company_name, c.default_billing_rate as client_def_rate,
        (SELECT COUNT(d.id) FROM deployments d WHERE d.site_id = s.id AND d.status != 'cancelled') as total_deployments
    FROM sites s
    JOIN clients c ON s.client_id = c.id
";
$params = [];
if ($filterClientId) {
    $sql .= " WHERE s.client_id = :cid";
    $params[':cid'] = $filterClientId;
}
$sql .= " ORDER BY s.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$sites = $stmt->fetchAll();

$pageTitle = 'Site Management';
require_once ROOT_PATH . '/templates/header.php';
require_once ROOT_PATH . '/templates/sidebar.php';
require_once ROOT_PATH . '/templates/navbar.php';
?>

<?= Helper::renderSubNav('directory', 'sites') ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-geo-fill text-primary me-2"></i>Client Sites & Venues</h4>
        <p class="text-muted small mb-0">Manage banquet halls, hotel wings, restaurant outlets, event lawns, and GPS geofence verifications.</p>
    </div>
    <div class="d-flex gap-2">
        <div class="input-group input-group-sm" style="width: 250px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control border-start-0" placeholder="Search sites..." data-table-search="#sitesTable">
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openSiteModal()">
            <i class="bi bi-plus-lg me-1"></i> Add New Site
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Filter by Client if needed -->
<?php if ($filterClientId): ?>
    <div class="alert alert-secondary py-2 small d-flex justify-content-between align-items-center mb-3">
        <span>Filtering sites for client: <strong><?= htmlspecialchars($sites[0]['company_name'] ?? 'Client #' . $filterClientId) ?></strong></span>
        <a href="<?= BASE_URL ?>/modules/sites/index.php" class="btn btn-sm btn-outline-dark py-0">Clear Filter</a>
    </div>
<?php endif; ?>

<div class="custom-card">
    <div class="table-responsive">
        <table class="table table-custom align-middle" id="sitesTable">
            <thead>
                <tr>
                    <th>Site Code & Name</th>
                    <th>Client / Parent Company</th>
                    <th>Location & Address</th>
                    <th>Site Contact</th>
                    <th>GPS Coordinates</th>
                    <th>Billing Rate Override</th>
                    <th>Deployments</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sites)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No sites configured. Click "Add New Site" to create one.</td></tr>
                <?php else: ?>
                    <?php foreach ($sites as $st): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($st['site_name']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($st['site_code']) ?></div>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $st['client_id'] ?>" class="text-decoration-none fw-semibold text-dark">
                                    <i class="bi bi-building text-primary me-1"></i><?= htmlspecialchars($st['company_name']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 240px;" title="<?= htmlspecialchars($st['location_address']) ?>">
                                    <?= htmlspecialchars($st['location_address']) ?>
                                </div>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($st['contact_person'] ?: '-') ?></div>
                                <small class="text-muted"><?= htmlspecialchars($st['contact_phone'] ?: '') ?></small>
                            </td>
                            <td>
                                <?php if ($st['latitude'] && $st['longitude']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle" title="Lat: <?= $st['latitude'] ?>, Lng: <?= $st['longitude'] ?>">
                                        <i class="bi bi-geo-alt-fill"></i> GPS Set (<?= $st['geofence_radius_meters'] ?>m)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary">No GPS</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($st['default_client_rate']): ?>
                                    <strong class="text-dark"><?= Helper::formatCurrency($st['default_client_rate']) ?></strong>
                                    <div class="text-muted" style="font-size:0.7rem;">Site override</div>
                                <?php else: ?>
                                    <span class="text-muted"><?= Helper::formatCurrency($st['client_def_rate']) ?></span>
                                    <div class="text-muted" style="font-size:0.7rem;">Client default</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary"><?= $st['total_deployments'] ?> shifts</span>
                            </td>
                            <td><?= Helper::statusBadge($st['status']) ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='editSite(<?= json_encode($st) ?>)'>
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Site Modal -->
<div class="modal fade" id="siteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="action" value="save_site">
                <input type="hidden" name="site_id" id="modal_site_id" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="siteModalTitle">Add New Site</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Parent Client *</label>
                            <select name="client_id" id="modal_site_cid" class="form-select" required>
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clientsList as $cl): ?>
                                    <option value="<?= $cl['id'] ?>" <?= ($filterClientId == $cl['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cl['company_name']) ?> (Def: <?= Helper::formatCurrency($cl['default_billing_rate']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Site Code</label>
                            <input type="text" name="site_code" id="modal_site_code" class="form-control" placeholder="SIT-001">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Site / Venue Name *</label>
                            <input type="text" name="site_name" id="modal_site_name" class="form-control" placeholder="e.g. Grand Ballroom & Lawn" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="modal_site_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">On-site Contact Person</label>
                            <input type="text" name="contact_person" id="modal_site_contact" class="form-control" placeholder="Floor Supervisor Name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">On-site Contact Phone</label>
                            <input type="text" name="contact_phone" id="modal_site_phone" class="form-control" placeholder="Direct phone number">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Venue Address *</label>
                            <textarea name="location_address" id="modal_site_address" class="form-control" rows="2" placeholder="Full address for staff navigation..." required></textarea>
                        </div>

                        <!-- Site Specific Rate Overrides -->
                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2"><i class="bi bi-tag me-1 text-primary"></i>Optional Billing Rate Override for This Site</h6>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Client Billing Rate for this Site (₹ / Shift)</label>
                            <input type="number" step="10" name="default_client_rate" id="modal_site_client_rate" class="form-control" placeholder="Leave empty to use client default">
                            <small class="text-muted" style="font-size: 0.72rem;">Staff payout is automatically managed by each staff member's payout rate in the Staff Workforce menu.</small>
                        </div>

                        <!-- GPS Verification -->
                        <div class="col-12 mt-3">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2">
                                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-crosshair me-1 text-danger"></i>GPS Geofence Settings</h6>
                                <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" onclick="captureCurrentLocation('modal_site_lat', 'modal_site_lng', 'gps_status_msg')">
                                    <i class="bi bi-geo-alt"></i> Set Current GPS Coordinates
                                </button>
                            </div>
                            <div id="gps_status_msg" class="small mt-1"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Latitude</label>
                            <input type="number" step="0.00000001" name="latitude" id="modal_site_lat" class="form-control" placeholder="28.556160">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Longitude</label>
                            <input type="number" step="0.00000001" name="longitude" id="modal_site_lng" class="form-control" placeholder="77.100000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Geofence Radius (Meters)</label>
                            <input type="number" name="geofence_radius_meters" id="modal_site_radius" class="form-control" value="250">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Site
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/templates/footer.php'; ?>

<script>
let siteModalInstance = null;

function getSiteModal() {
    if (!siteModalInstance) {
        const modalEl = document.getElementById('siteModal');
        siteModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    }
    return siteModalInstance;
}

function openSiteModal() {
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };

    setVal('modal_site_id', '');
    setVal('modal_site_code', 'SIT-' + Math.floor(Math.random() * 900 + 100));
    setVal('modal_site_name', '');
    setVal('modal_site_contact', '');
    setVal('modal_site_phone', '');
    setVal('modal_site_address', '');
    setVal('modal_site_client_rate', '');
    setVal('modal_site_lat', '');
    setVal('modal_site_lng', '');
    setVal('modal_site_radius', '250');
    setVal('modal_site_status', 'active');

    const msgEl = document.getElementById('gps_status_msg');
    if (msgEl) msgEl.innerHTML = '';

    const titleEl = document.getElementById('siteModalTitle');
    if (titleEl) titleEl.textContent = 'Add New Site';

    getSiteModal().show();
}

function editSite(s) {
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };

    setVal('modal_site_id', s.id || '');
    setVal('modal_site_cid', s.client_id || '');
    setVal('modal_site_code', s.site_code || '');
    setVal('modal_site_name', s.site_name || '');
    setVal('modal_site_contact', s.contact_person || '');
    setVal('modal_site_phone', s.contact_phone || '');
    setVal('modal_site_address', s.location_address || '');
    setVal('modal_site_client_rate', s.default_client_rate || '');
    setVal('modal_site_lat', s.latitude || '');
    setVal('modal_site_lng', s.longitude || '');
    setVal('modal_site_radius', s.geofence_radius_meters || '250');
    setVal('modal_site_status', s.status || 'active');

    const msgEl = document.getElementById('gps_status_msg');
    if (msgEl) msgEl.innerHTML = '';

    const titleEl = document.getElementById('siteModalTitle');
    if (titleEl) titleEl.textContent = 'Edit Site: ' + (s.site_name || '');

    getSiteModal().show();
}
</script>
