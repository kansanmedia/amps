<?php
// services/ExcelImportService.php - High Performance Native Excel (.xlsx) and CSV Bulk Importer
declare(strict_types=1);

class ExcelImportService {

    /**
     * Parse an uploaded file (.xlsx or .csv) into an array of associative row arrays
     * 
     * @param string $filePath Absolute path to temporary uploaded file
     * @param string $originalName Original file name with extension
     * @return array ['headers' => [...], 'rows' => [...]]
     */
    public static function parseFile(string $filePath, string $originalName): array {
        // Security Hardening: Checkpoint 4 - File Upload Validation
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Uploaded file not found on server.");
        }

        if (filesize($filePath) > 10 * 1024 * 1024) { // 10MB limit
            throw new InvalidArgumentException("Uploaded file exceeds 10MB safety limit.");
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            $rawRows = self::parseCsv($filePath);
        } elseif ($ext === 'xlsx') {
            // Verify XLSX is a legitimate zip container before parsing
            $zip = new ZipArchive();
            $res = $zip->open($filePath);
            if ($res !== true) {
                throw new InvalidArgumentException("Invalid or corrupt Excel file container. Please upload a valid .xlsx spreadsheet.");
            }
            $zip->close();
            $rawRows = self::parseXlsx($filePath);
        } else {
            throw new InvalidArgumentException("Unsupported file type: .{$ext}. Only authenticated .xlsx and .csv spreadsheets are accepted.");
        }

        if (empty($rawRows)) {
            throw new RuntimeException("The uploaded file is empty or contains no readable data.");
        }

        // Extract header row (first non-empty row)
        $rawHeaders = array_shift($rawRows);
        $normalizedHeaders = [];
        foreach ($rawHeaders as $idx => $h) {
            $normalizedHeaders[$idx] = self::normalizeHeaderName((string)$h);
        }

        // Build associative rows
        $assocRows = [];
        $rowNum = 2; // Row 1 was header
        foreach ($rawRows as $row) {
            // Check if entire row is empty
            $hasData = false;
            foreach ($row as $val) {
                if (trim((string)$val) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                $rowNum++;
                continue;
            }

            $assoc = ['_row_number' => $rowNum];
            foreach ($normalizedHeaders as $idx => $key) {
                if (empty($key)) continue;
                $val = $row[$idx] ?? '';
                $assoc[$key] = trim((string)$val);
            }
            $assocRows[] = $assoc;
            $rowNum++;
        }

        return [
            'headers' => array_values(array_filter($normalizedHeaders)),
            'rows'    => $assocRows
        ];
    }

    /**
     * Parse CSV file with BOM detection and auto-delimiter
     */
    private static function parseCsv(string $filePath): array {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException("Failed to open CSV file for reading.");
        }

        // Check and strip UTF-8 BOM (\xEF\xBB\xBF)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Detect delimiter: check first line
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }

        $commaCount = substr_count($firstLine, ',');
        $semiCount  = substr_count($firstLine, ';');
        $tabCount   = substr_count($firstLine, "\t");

        $delimiter = ',';
        if ($semiCount > $commaCount && $semiCount > $tabCount) {
            $delimiter = ';';
        } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
            $delimiter = "\t";
        }

        // Rewind and read with detected delimiter
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fread($handle, 3); // Skip BOM again
        }

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Native XLSX parser using ZipArchive and SimpleXML (zero external dependencies)
     */
    private static function parseXlsx(string $filePath): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("PHP ZipArchive extension is required to read .xlsx files.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Unable to read .xlsx file as a valid Excel archive.");
        }

        // 1. Extract Shared Strings
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml !== false) {
            $xml = @simplexml_load_string($stringsXml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        // Rich text runs
                        $richText = '';
                        foreach ($si->r as $r) {
                            $richText .= (string)$r->t;
                        }
                        $sharedStrings[] = $richText;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Extract Worksheet 1
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            // Try looking for any worksheet
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_starts_with($stat['name'], 'xl/worksheets/sheet') && str_ends_with($stat['name'], '.xml')) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException("Worksheet data not found in .xlsx archive.");
        }

        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $rowNode) {
            $rowCells = [];
            $maxColIdx = 0;

            foreach ($rowNode->c as $c) {
                $cellRef = (string)$c['r']; // e.g. "A1", "C2"
                $colLetters = preg_replace('/[0-9]/', '', $cellRef);
                $colIdx = self::columnLetterToIndex($colLetters);
                $maxColIdx = max($maxColIdx, $colIdx);

                $cellType = (string)$c['t'];
                $val = '';

                if ($cellType === 's') {
                    // Shared string reference
                    $sIndex = (int)$c->v;
                    $val = $sharedStrings[$sIndex] ?? '';
                } elseif ($cellType === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                } elseif (isset($c->v)) {
                    $val = (string)$c->v;
                }

                $rowCells[$colIdx] = $val;
            }

            // Fill gaps for sparse cells
            $fullRow = [];
            for ($i = 0; $i <= $maxColIdx; $i++) {
                $fullRow[$i] = $rowCells[$i] ?? '';
            }
            $rows[] = $fullRow;
        }

        return $rows;
    }

    /**
     * Convert Excel column letter to 0-based index: A -> 0, B -> 1, Z -> 25, AA -> 26
     */
    private static function columnLetterToIndex(string $col): int {
        $col = strtoupper($col);
        $len = strlen($col);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - 64);
        }
        return $idx - 1;
    }

    /**
     * Normalize human column header to standard snake_case identifier
     * e.g., "Company Name / Client" -> "company_name"
     */
    public static function normalizeHeaderName(string $header): string {
        $h = strtolower(trim($header));
        // Replace non-alphanumeric characters with underscores
        $h = preg_replace('/[^a-z0-9]/', '_', $h);
        $h = trim(preg_replace('/_+/', '_', $h), '_');

        // Common Synonyms Mapping
        $synonyms = [
            'client_code'          => ['client_code', 'client_id_code', 'code', 'customer_code'],
            'company_name'         => ['company_name', 'client_name', 'company', 'organization', 'client_company'],
            'contact_person'       => ['contact_person', 'contact_name', 'person_name', 'poc_name', 'contact'],
            'phone'                => ['phone', 'mobile', 'contact_phone', 'mobile_no', 'phone_number', 'contact_number'],
            'email'                => ['email', 'email_address', 'mail_id'],
            'gst_number'           => ['gst_number', 'gst', 'gstin', 'gst_no'],
            'pan_number'           => ['pan_number', 'pan', 'pan_no'],
            'billing_address'      => ['billing_address', 'address', 'company_address', 'office_address'],
            'billing_cycle'        => ['billing_cycle', 'cycle', 'invoice_cycle', 'frequency'],
            'payment_terms_days'   => ['payment_terms_days', 'payment_terms', 'terms_days', 'credit_days'],
            'default_billing_rate' => ['default_billing_rate', 'billing_rate', 'default_rate', 'client_rate', 'rate_per_shift'],
            'site_code'            => ['site_code', 'venue_code', 'branch_code'],
            'site_name'            => ['site_name', 'venue_name', 'site', 'venue', 'location_name'],
            'location_address'     => ['location_address', 'site_address', 'venue_address', 'address'],
            'geofence_radius_meters'=> ['geofence_radius_meters', 'geofence', 'radius', 'radius_meters'],
            'default_client_rate'  => ['default_client_rate', 'client_shift_rate', 'billing_rate', 'client_rate'],
            'default_staff_rate'   => ['default_staff_rate', 'staff_shift_rate', 'payout_rate', 'staff_rate'],
            'staff_code'           => ['staff_code', 'employee_code', 'emp_code', 'worker_code'],
            'full_name'            => ['full_name', 'staff_name', 'name', 'employee_name', 'worker_name'],
            'emergency_contact'    => ['emergency_contact', 'emergency_phone', 'alternate_phone', 'alt_phone'],
            'id_proof_type'        => ['id_proof_type', 'id_type', 'proof_type', 'govt_id_type', 'id_proof'],
            'id_proof_number'      => ['id_proof_number', 'id_number', 'aadhaar_number', 'aadhaar_card', 'aadhaar', 'proof_number', 'id_no'],
            'skill_category'       => ['skill_category', 'category', 'role', 'designation', 'role_designation', 'job_title', 'staff_category'],
            'default_payout_rate'  => ['default_payout_rate', 'daily_wage', 'shift_wage', 'payout_rate', 'staff_wage'],
            'bank_name'            => ['bank_name', 'bank'],
            'bank_account_no'      => ['bank_account_no', 'account_number', 'account_no', 'bank_account'],
            'bank_ifsc'            => ['bank_ifsc', 'ifsc', 'ifsc_code'],
            'upi_id'               => ['upi_id', 'upi', 'vpa'],
            'joined_date'          => ['joined_date', 'date_of_joining', 'joining_date', 'join_date'],
            'status'               => ['status', 'state', 'active_status']
        ];

        foreach ($synonyms as $canonical => $aliases) {
            if (in_array($h, $aliases, true)) {
                return $canonical;
            }
        }

        return $h;
    }

    // =========================================================================
    // CLIENTS BULK IMPORTER
    // =========================================================================

    public static function importClients(array $rows, array $options = []): array {
        $db = DB::getConnection();
        $mode = $options['mode'] ?? 'skip'; // 'skip' or 'update'

        $result = [
            'total_rows' => count($rows),
            'inserted'   => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errors'     => [],
            'items'      => []
        ];

        // Fetch existing clients for duplicate matching
        $existingClients = $db->query("SELECT id, client_code, LOWER(company_name) as lower_name, phone FROM clients")->fetchAll();
        $codeMap  = [];
        $nameMap  = [];
        $phoneMap = [];
        foreach ($existingClients as $ec) {
            $codeMap[strtoupper(trim($ec['client_code']))] = $ec['id'];
            $nameMap[trim($ec['lower_name'])] = $ec['id'];
            $cleanPh = preg_replace('/\D/', '', (string)$ec['phone']);
            if (!empty($cleanPh)) {
                $phoneMap[$cleanPh] = $ec['id'];
            }
        }

        $insertStmt = $db->prepare("
            INSERT INTO clients (
                client_code, company_name, contact_person, phone, email, gst_number,
                pan_number, billing_address, billing_cycle, payment_terms_days,
                default_billing_rate, status
            ) VALUES (
                :client_code, :company_name, :contact_person, :phone, :email, :gst_number,
                :pan_number, :billing_address, :billing_cycle, :payment_terms_days,
                :default_billing_rate, :status
            )
        ");

        $updateStmt = $db->prepare("
            UPDATE clients SET
                contact_person = :contact_person,
                phone = :phone,
                email = :email,
                gst_number = :gst_number,
                pan_number = :pan_number,
                billing_address = :billing_address,
                billing_cycle = :billing_cycle,
                payment_terms_days = :payment_terms_days,
                default_billing_rate = :default_billing_rate,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($rows as $r) {
            $rowNum      = $r['_row_number'] ?? 0;
            $companyName = trim($r['company_name'] ?? '');
            $contactPerson = trim($r['contact_person'] ?? '');
            $phone       = trim($r['phone'] ?? '');

            if (empty($companyName)) {
                $result['errors'][] = ['row' => $rowNum, 'item' => 'Row ' . $rowNum, 'reason' => 'Company Name is required.'];
                $result['skipped']++;
                continue;
            }

            if (empty($contactPerson)) {
                $contactPerson = 'Accounts / Management';
            }

            $cleanPhone = preg_replace('/\D/', '', $phone);
            if (empty($cleanPhone)) {
                $cleanPhone = '9999999999';
            }

            $clientCode = strtoupper(trim($r['client_code'] ?? ''));
            if (empty($clientCode)) {
                $clientCode = 'CLI-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $companyName), 0, 3)) . '-' . rand(100, 999);
            }

            $email         = trim($r['email'] ?? '');
            $gstNumber     = strtoupper(trim($r['gst_number'] ?? ''));
            $panNumber     = strtoupper(trim($r['pan_number'] ?? ''));
            $billingAddress= trim($r['billing_address'] ?? 'Same as Company Venue');
            $billingCycle  = strtolower(trim($r['billing_cycle'] ?? 'monthly'));
            if (!in_array($billingCycle, ['weekly', 'fortnightly', 'monthly', 'on_demand'])) {
                $billingCycle = 'monthly';
            }
            $paymentTermsDays = !empty($r['payment_terms_days']) ? (int)$r['payment_terms_days'] : 15;
            $defaultBillingRate = !empty($r['default_billing_rate']) ? (float)$r['default_billing_rate'] : 800.00;
            $status = strtolower(trim($r['status'] ?? 'active'));
            if ($status !== 'inactive') $status = 'active';

            // Check if duplicate exists
            $lowerName = strtolower($companyName);
            $existingId = $codeMap[$clientCode] ?? $nameMap[$lowerName] ?? null;

            if ($existingId !== null) {
                if ($mode === 'update') {
                    $updateStmt->execute([
                        ':contact_person'     => $contactPerson,
                        ':phone'              => $cleanPhone,
                        ':email'              => $email ?: null,
                        ':gst_number'         => $gstNumber ?: null,
                        ':pan_number'         => $panNumber ?: null,
                        ':billing_address'    => $billingAddress,
                        ':billing_cycle'      => $billingCycle,
                        ':payment_terms_days' => $paymentTermsDays,
                        ':default_billing_rate'=> $defaultBillingRate,
                        ':status'             => $status,
                        ':id'                 => $existingId
                    ]);
                    $result['updated']++;
                    $result['items'][] = ['row' => $rowNum, 'name' => $companyName, 'code' => $clientCode, 'action' => 'Updated'];
                    Audit::log('IMPORT_UPDATE', 'CLIENTS', $existingId, null, ['name' => $companyName]);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNum, 'item' => $companyName, 'reason' => "Client already exists (Code: {$clientCode}). Skipped."];
                }
                continue;
            }

            // Ensure unique code if random collision
            while (isset($codeMap[$clientCode])) {
                $clientCode = 'CLI-' . rand(1000, 9999);
            }

            // Insert new client
            $insertStmt->execute([
                ':client_code'          => $clientCode,
                ':company_name'         => $companyName,
                ':contact_person'       => $contactPerson,
                ':phone'                => $cleanPhone,
                ':email'                => $email ?: null,
                ':gst_number'           => $gstNumber ?: null,
                ':pan_number'           => $panNumber ?: null,
                ':billing_address'      => $billingAddress,
                ':billing_cycle'        => $billingCycle,
                ':payment_terms_days'   => $paymentTermsDays,
                ':default_billing_rate' => $defaultBillingRate,
                ':status'               => $status
            ]);

            $newId = (int)$db->lastInsertId();
            $codeMap[$clientCode] = $newId;
            $nameMap[$lowerName]  = $newId;
            $phoneMap[$cleanPhone]= $newId;

            $result['inserted']++;
            $result['items'][] = ['row' => $rowNum, 'name' => $companyName, 'code' => $clientCode, 'action' => 'Inserted'];
            Audit::log('IMPORT_INSERT', 'CLIENTS', $newId, null, ['name' => $companyName, 'code' => $clientCode]);
        }

        return $result;
    }

    // =========================================================================
    // SITES BULK IMPORTER
    // =========================================================================

    public static function importSites(array $rows, array $options = []): array {
        $db = DB::getConnection();
        $mode = $options['mode'] ?? 'skip';

        $result = [
            'total_rows' => count($rows),
            'inserted'   => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errors'     => [],
            'items'      => []
        ];

        // Fetch clients for name/code resolution
        $clients = $db->query("SELECT id, client_code, LOWER(company_name) as lower_name, default_billing_rate FROM clients")->fetchAll();
        $clientLookup = [];
        $clientRateMap= [];
        foreach ($clients as $c) {
            $clientLookup[strtoupper(trim($c['client_code']))] = $c['id'];
            $clientLookup[trim($c['lower_name'])] = $c['id'];
            $clientRateMap[$c['id']] = (float)$c['default_billing_rate'];
        }

        // Fetch existing sites for duplicate checks
        $existingSites = $db->query("SELECT id, client_id, site_code, LOWER(site_name) as lower_site FROM sites")->fetchAll();
        $siteMap = [];
        foreach ($existingSites as $s) {
            $key = $s['client_id'] . '::' . trim($s['lower_site']);
            $siteMap[$key] = $s['id'];
            $siteMap['code::' . strtoupper(trim($s['site_code']))] = $s['id'];
        }

        $insertStmt = $db->prepare("
            INSERT INTO sites (
                client_id, site_code, site_name, location_address, contact_person,
                contact_phone, latitude, longitude, geofence_radius_meters,
                default_client_rate, default_staff_rate, status
            ) VALUES (
                :client_id, :site_code, :site_name, :location_address, :contact_person,
                :contact_phone, :latitude, :longitude, :geofence_radius_meters,
                :default_client_rate, :default_staff_rate, :status
            )
        ");

        $updateStmt = $db->prepare("
            UPDATE sites SET
                location_address = :location_address,
                contact_person = :contact_person,
                contact_phone = :contact_phone,
                latitude = :latitude,
                longitude = :longitude,
                geofence_radius_meters = :geofence_radius_meters,
                default_client_rate = :default_client_rate,
                default_staff_rate = :default_staff_rate,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($rows as $r) {
            $rowNum = $r['_row_number'] ?? 0;
            $clientIdentifier = trim($r['client'] ?? $r['company_name'] ?? $r['client_name'] ?? $r['client_code'] ?? '');
            $siteName         = trim($r['site_name'] ?? '');

            if (empty($siteName)) {
                $result['errors'][] = ['row' => $rowNum, 'item' => 'Row ' . $rowNum, 'reason' => 'Site Name is required.'];
                $result['skipped']++;
                continue;
            }

            // Resolve Client ID
            $clientId = null;
            if (!empty($clientIdentifier)) {
                $cKey = strtoupper($clientIdentifier);
                $cLower = strtolower($clientIdentifier);
                $clientId = $clientLookup[$cKey] ?? $clientLookup[$cLower] ?? null;
            }

            if (!$clientId) {
                // If only 1 client exists in DB, fallback to that client
                if (count($clients) === 1) {
                    $clientId = $clients[0]['id'];
                } else {
                    $result['errors'][] = ['row' => $rowNum, 'item' => $siteName, 'reason' => "Client '{$clientIdentifier}' not found in database."];
                    $result['skipped']++;
                    continue;
                }
            }

            $siteCode = strtoupper(trim($r['site_code'] ?? ''));
            if (empty($siteCode)) {
                $siteCode = 'SIT-' . rand(100, 999);
            }

            $locationAddress = trim($r['location_address'] ?? $r['site_address'] ?? $r['address'] ?? 'Venue Main Gate');
            $contactPerson   = trim($r['contact_person'] ?? '');
            $contactPhone    = preg_replace('/\D/', '', $r['contact_phone'] ?? $r['phone'] ?? '');

            $lat = !empty($r['latitude']) ? (float)$r['latitude'] : null;
            $lng = !empty($r['longitude']) ? (float)$r['longitude'] : null;
            $radius = !empty($r['geofence_radius_meters']) ? (int)$r['geofence_radius_meters'] : 250;

            $defaultClientRate = !empty($r['default_client_rate']) ? (float)$r['default_client_rate'] : ($clientRateMap[$clientId] ?? 850.00);
            $defaultStaffRate  = !empty($r['default_staff_rate']) ? (float)$r['default_staff_rate'] : 600.00;
            $status = strtolower(trim($r['status'] ?? 'active'));
            if ($status !== 'inactive') $status = 'active';

            // Check duplicate
            $lowerSite = strtolower($siteName);
            $dupKey = $clientId . '::' . $lowerSite;
            $existingId = $siteMap[$dupKey] ?? $siteMap['code::' . $siteCode] ?? null;

            if ($existingId !== null) {
                if ($mode === 'update') {
                    $updateStmt->execute([
                        ':location_address'      => $locationAddress,
                        ':contact_person'        => $contactPerson ?: null,
                        ':contact_phone'         => $contactPhone ?: null,
                        ':latitude'              => $lat,
                        ':longitude'             => $lng,
                        ':geofence_radius_meters'=> $radius,
                        ':default_client_rate'   => $defaultClientRate,
                        ':default_staff_rate'    => $defaultStaffRate,
                        ':status'                => $status,
                        ':id'                    => $existingId
                    ]);
                    $result['updated']++;
                    $result['items'][] = ['row' => $rowNum, 'name' => $siteName, 'code' => $siteCode, 'action' => 'Updated'];
                    Audit::log('IMPORT_UPDATE', 'SITES', $existingId, null, ['name' => $siteName]);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNum, 'item' => $siteName, 'reason' => "Site already exists under client. Skipped."];
                }
                continue;
            }

            // Ensure unique site code
            while (isset($siteMap['code::' . $siteCode])) {
                $siteCode = 'SIT-' . rand(1000, 9999);
            }

            $insertStmt->execute([
                ':client_id'             => $clientId,
                ':site_code'             => $siteCode,
                ':site_name'             => $siteName,
                ':location_address'      => $locationAddress,
                ':contact_person'        => $contactPerson ?: null,
                ':contact_phone'         => $contactPhone ?: null,
                ':latitude'              => $lat,
                ':longitude'             => $lng,
                ':geofence_radius_meters'=> $radius,
                ':default_client_rate'   => $defaultClientRate,
                ':default_staff_rate'    => $defaultStaffRate,
                ':status'                => $status
            ]);

            $newId = (int)$db->lastInsertId();
            $siteMap[$dupKey] = $newId;
            $siteMap['code::' . $siteCode] = $newId;

            $result['inserted']++;
            $result['items'][] = ['row' => $rowNum, 'name' => $siteName, 'code' => $siteCode, 'action' => 'Inserted'];
            Audit::log('IMPORT_INSERT', 'SITES', $newId, null, ['name' => $siteName, 'code' => $siteCode]);
        }

        return $result;
    }

    // =========================================================================
    // STAFF WORKFORCE BULK IMPORTER
    // =========================================================================

    public static function importStaff(array $rows, array $options = []): array {
        $db = DB::getConnection();
        $mode = $options['mode'] ?? 'skip';

        $result = [
            'total_rows' => count($rows),
            'inserted'   => 0,
            'updated'    => 0,
            'skipped'    => 0,
            'errors'     => [],
            'items'      => []
        ];

        // Fetch categories for skill mapping
        $categories = $db->query("SELECT id, category_code, category_name FROM staff_categories")->fetchAll();
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[strtoupper(trim($cat['category_code']))] = $cat;
            $catMap[strtolower(trim($cat['category_name']))] = $cat;
            // Short names like "waiter", "bartender"
            $words = explode(' ', strtolower($cat['category_name']));
            foreach ($words as $w) {
                if (strlen($w) > 3 && !in_array($w, ['senior', 'head', 'lead', 'event', 'support', 'shift'])) {
                    $catMap[$w] = $cat;
                }
            }
        }

        // Fetch existing staff for duplicate checks by phone and code
        $existingStaff = $db->query("SELECT id, staff_code, phone FROM staff")->fetchAll();
        $phoneMap = [];
        $codeMap  = [];
        foreach ($existingStaff as $stf) {
            $cleanP = preg_replace('/\D/', '', $stf['phone']);
            if (!empty($cleanP)) {
                $phoneMap[$cleanP] = $stf['id'];
            }
            $codeMap[strtoupper(trim($stf['staff_code']))] = $stf['id'];
        }

        $insertStmt = $db->prepare("
            INSERT INTO staff (
                staff_code, full_name, phone, email, emergency_contact, address,
                id_proof_type, id_proof_number, bank_name, bank_account_no, bank_ifsc,
                upi_id, skill_category, category_id, default_payout_rate, status, joined_date
            ) VALUES (
                :staff_code, :full_name, :phone, :email, :emergency_contact, :address,
                :id_proof_type, :id_proof_number, :bank_name, :bank_account_no, :bank_ifsc,
                :upi_id, :skill_category, :category_id, :default_payout_rate, :status, :joined_date
            )
        ");

        $updateStmt = $db->prepare("
            UPDATE staff SET
                full_name = :full_name,
                email = :email,
                emergency_contact = :emergency_contact,
                address = :address,
                id_proof_type = :id_proof_type,
                id_proof_number = :id_proof_number,
                bank_name = :bank_name,
                bank_account_no = :bank_account_no,
                bank_ifsc = :bank_ifsc,
                upi_id = :upi_id,
                skill_category = :skill_category,
                category_id = :category_id,
                default_payout_rate = :default_payout_rate,
                status = :status,
                joined_date = :joined_date,
                updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($rows as $r) {
            $rowNum   = $r['_row_number'] ?? 0;
            $fullName = trim($r['full_name'] ?? '');
            $phone    = trim($r['phone'] ?? '');

            if (empty($fullName)) {
                $result['errors'][] = ['row' => $rowNum, 'item' => 'Row ' . $rowNum, 'reason' => 'Staff Full Name is required.'];
                $result['skipped']++;
                continue;
            }

            $cleanPhone = preg_replace('/\D/', '', $phone);
            if (empty($cleanPhone) || strlen($cleanPhone) < 10) {
                $result['errors'][] = ['row' => $rowNum, 'item' => $fullName, 'reason' => "Invalid phone number '{$phone}'. 10 digits required."];
                $result['skipped']++;
                continue;
            }

            // Truncate/standardize phone to 10 digits if standard Indian mobile
            if (strlen($cleanPhone) > 10 && str_starts_with($cleanPhone, '91') && strlen($cleanPhone) === 12) {
                $cleanPhone = substr($cleanPhone, 2);
            }

            $staffCode = strtoupper(trim($r['staff_code'] ?? ''));
            if (empty($staffCode)) {
                $staffCode = 'STF-' . rand(100, 999);
            }

            $email            = trim($r['email'] ?? '');
            $emergencyContact = preg_replace('/\D/', '', $r['emergency_contact'] ?? '');
            $address          = trim($r['address'] ?? '');
            $idProofType      = trim($r['id_proof_type'] ?? 'Aadhaar Card');
            $idProofNumber    = trim($r['id_proof_number'] ?? '');
            $bankName         = trim($r['bank_name'] ?? '');
            $bankAccountNo    = trim($r['bank_account_no'] ?? '');
            $bankIfsc         = strtoupper(trim($r['bank_ifsc'] ?? ''));
            $upiId            = trim($r['upi_id'] ?? '');

            // Resolve Category & Rate
            $categoryInput = trim($r['skill_category'] ?? $r['category'] ?? $r['role'] ?? '');
            $matchedCat = null;
            if (!empty($categoryInput)) {
                $cUpper = strtoupper($categoryInput);
                $cLower = strtolower($categoryInput);
                $matchedCat = $catMap[$cUpper] ?? $catMap[$cLower] ?? null;
            }

            $categoryId = $matchedCat ? (int)$matchedCat['id'] : null;
            $skillCategory = $matchedCat ? $matchedCat['category_name'] : ($categoryInput ?: 'General Staff');

            // Default payout rate
            $payoutRate = !empty($r['default_payout_rate']) ? (float)$r['default_payout_rate'] : 500.00;

            // Joined date parsing
            $rawDate = trim($r['joined_date'] ?? '');
            $joinedDate = self::parseDateToYmd($rawDate) ?: date('Y-m-d');

            $status = strtolower(trim($r['status'] ?? 'active'));
            if (!in_array($status, ['active', 'inactive', 'blocked'])) {
                $status = 'active';
            }

            // Check Duplicate by Phone or Staff Code
            $existingId = $phoneMap[$cleanPhone] ?? $codeMap[$staffCode] ?? null;

            if ($existingId !== null) {
                if ($mode === 'update') {
                    $updateStmt->execute([
                        ':full_name'          => $fullName,
                        ':email'              => $email ?: null,
                        ':emergency_contact'  => $emergencyContact ?: null,
                        ':address'            => $address ?: null,
                        ':id_proof_type'      => $idProofType ?: 'Aadhaar Card',
                        ':id_proof_number'    => $idProofNumber ?: null,
                        ':bank_name'          => $bankName ?: null,
                        ':bank_account_no'    => $bankAccountNo ?: null,
                        ':bank_ifsc'          => $bankIfsc ?: null,
                        ':upi_id'             => $upiId ?: null,
                        ':skill_category'     => $skillCategory,
                        ':category_id'        => $categoryId,
                        ':default_payout_rate'=> $payoutRate,
                        ':status'             => $status,
                        ':joined_date'        => $joinedDate,
                        ':id'                 => $existingId
                    ]);
                    $result['updated']++;
                    $result['items'][] = ['row' => $rowNum, 'name' => $fullName, 'code' => $staffCode, 'action' => 'Updated'];
                    Audit::log('IMPORT_UPDATE', 'STAFF', $existingId, null, ['name' => $fullName, 'phone' => $cleanPhone]);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNum, 'item' => $fullName, 'reason' => "Staff phone '{$cleanPhone}' already registered. Skipped."];
                }
                continue;
            }

            // Ensure unique staff code
            while (isset($codeMap[$staffCode])) {
                $staffCode = 'STF-' . rand(1000, 9999);
            }

            $insertStmt->execute([
                ':staff_code'          => $staffCode,
                ':full_name'           => $fullName,
                ':phone'               => $cleanPhone,
                ':email'               => $email ?: null,
                ':emergency_contact'   => $emergencyContact ?: null,
                ':address'             => $address ?: null,
                ':id_proof_type'       => $idProofType ?: 'Aadhaar Card',
                ':id_proof_number'     => $idProofNumber ?: null,
                ':bank_name'           => $bankName ?: null,
                ':bank_account_no'     => $bankAccountNo ?: null,
                ':bank_ifsc'           => $bankIfsc ?: null,
                ':upi_id'              => $upiId ?: null,
                ':skill_category'      => $skillCategory,
                ':category_id'         => $categoryId,
                ':default_payout_rate' => $payoutRate,
                ':status'              => $status,
                ':joined_date'         => $joinedDate
            ]);

            $newId = (int)$db->lastInsertId();
            $phoneMap[$cleanPhone] = $newId;
            $codeMap[$staffCode]   = $newId;

            $result['inserted']++;
            $result['items'][] = ['row' => $rowNum, 'name' => $fullName, 'code' => $staffCode, 'action' => 'Inserted'];
            Audit::log('IMPORT_INSERT', 'STAFF', $newId, null, ['name' => $fullName, 'phone' => $cleanPhone, 'code' => $staffCode]);
        }

        return $result;
    }

    /**
     * Flexible date parser converting DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD or Excel serial to YYYY-MM-DD
     */
    private static function parseDateToYmd(string $dateStr): ?string {
        if (empty($dateStr)) return null;

        // Excel serial number (e.g. 45210)
        if (is_numeric($dateStr) && (float)$dateStr > 30000 && (float)$dateStr < 70000) {
            $unixTime = ((float)$dateStr - 25569) * 86400;
            return date('Y-m-d', (int)$unixTime);
        }

        // Try standard formats
        $dateStr = str_replace('/', '-', $dateStr);
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    // =========================================================================
    // TEMPLATE GENERATORS
    // =========================================================================

    /**
     * Generate & stream a downloadable sample CSV template with UTF-8 BOM
     */
    public static function downloadTemplate(string $type): void {
        $filename = "sample_{$type}_import_template.csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // Write UTF-8 BOM for Microsoft Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");

        switch ($type) {
            case 'clients':
                fputcsv($out, [
                    'Client Code', 'Company Name', 'Contact Person', 'Phone', 'Email',
                    'GST Number', 'PAN Number', 'Billing Address', 'Billing Cycle',
                    'Payment Terms Days', 'Default Billing Rate', 'Status'
                ]);
                fputcsv($out, [
                    'CLI-HYAT', 'Grand Hyatt Hotel & Banquets', 'Rajesh Sharma', '9876543210', 'billing@grandhyatt.com',
                    '07AAAAA0000A1Z5', 'ABCDE1234F', 'Asset Area 1, Aerocity, New Delhi - 110037', 'monthly',
                    '15', '950.00', 'active'
                ]);
                fputcsv($out, [
                    'CLI-TAJ', 'Taj Palace Convention Centre', 'Ananya Deshmukh', '9811223344', 'events@tajpalace.com',
                    '07BBBBB1111B2Z8', 'XYZPQ5678K', '2 Sardar Patel Marg, Diplomatic Enclave, New Delhi', 'fortnightly',
                    '30', '1100.00', 'active'
                ]);
                break;

            case 'sites':
                fputcsv($out, [
                    'Client Company or Code', 'Site Code', 'Site Name', 'Location Address',
                    'Contact Person', 'Contact Phone', 'Latitude', 'Longitude',
                    'Geofence Radius Meters', 'Default Client Rate', 'Default Staff Rate', 'Status'
                ]);
                fputcsv($out, [
                    'Grand Hyatt Hotel & Banquets', 'SIT-HYT-01', 'Main Ballroom & Pre-Function', 'Gate No 2, Lower Ground, Grand Hyatt Aerocity',
                    'Vikram Malhotra', '9899001122', '28.556200', '77.100000',
                    '250', '950.00', '600.00', 'active'
                ]);
                fputcsv($out, [
                    'Taj Palace Convention Centre', 'SIT-TAJ-01', 'Durbar Grand Lawn & Pavilion', 'North Wing Gate, Taj Palace, New Delhi',
                    'Suresh Rao', '9822334455', '28.598200', '77.172400',
                    '300', '1100.00', '700.00', 'active'
                ]);
                break;

            case 'staff':
                fputcsv($out, [
                    'Staff Code', 'Full Name', 'Phone', 'Email', 'Emergency Contact',
                    'Address', 'ID Proof Type', 'ID Proof Number', 'Skill Category',
                    'Default Payout Rate', 'Bank Name', 'Bank Account No', 'Bank IFSC',
                    'UPI ID', 'Joined Date', 'Status'
                ]);
                fputcsv($out, [
                    'STF-101', 'Amit Kumar Verma', '9876500001', 'amit.kumar@gmail.com', '9876500002',
                    'H.No 45, Gali No 2, Mahipalpur, New Delhi', 'Aadhaar Card', '4521 8899 0012', 'Senior Steward / Waiter',
                    '600.00', 'State Bank of India', '330198273645', 'SBIN0001234',
                    'amitkumar@sbi', '2026-01-10', 'active'
                ]);
                fputcsv($out, [
                    'STF-102', 'Rohit Singhania', '9876500003', 'rohit.singh@gmail.com', '9876500004',
                    'Pocket B, Sector 14, Dwarka, New Delhi', 'Aadhaar Card', '7890 1234 5678', 'Bartender & Mixologist',
                    '800.00', 'HDFC Bank', '501002938475', 'HDFC0000456',
                    'rohit@okhdfcbank', '2026-02-01', 'active'
                ]);
                fputcsv($out, [
                    'STF-103', 'Manish Gupta', '9876500005', '', '9876500006',
                    'Village Kapashera, South West Delhi', 'Aadhaar Card', '9012 3456 7890', 'Banquet Captain / Supervisor',
                    '1000.00', 'Punjab National Bank', '02810015000234', 'PUNB0028100',
                    'manish@upi', '2026-02-15', 'active'
                ]);
                break;
        }

        fclose($out);
        exit;
    }
}
