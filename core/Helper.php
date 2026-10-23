<?php
// core/Helper.php - General Utilities & UI Rendering Helpers

declare(strict_types=1);

class Helper {
    private static ?array $companySettings = null;

    /**
     * Get cached company settings from company_settings table with graceful fallback
     */
    public static function getCompanySettings(): array {
        if (self::$companySettings !== null) {
            return self::$companySettings;
        }

        try {
            $db = DB::getConnection();
            $stmt = $db->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1");
            $data = $stmt->fetch();
            if ($data && is_array($data) && !empty($data['company_name'])) {
                self::$companySettings = $data;
                return self::$companySettings;
            }
        } catch (\Throwable $e) {
            // Database not yet connected or table empty
        }

        self::$companySettings = [
            'company_name' => 'ADITYA MANPOWER SUPPLIER',
            'tagline'      => 'All types of Manpower suppliers for Hotel/ Banquet Hall/O.D.C. & Other purpose',
            'email'        => 'contact@kansanmanpower.com',
            'phone'        => '8051296873',
            'address'      => '',
            'logo_url'     => '',
            'currency_symbol' => '₹',
            'invoice_prefix'  => 'INV-',
        ];
        return self::$companySettings;
    }

    /**
     * Force refresh cached company settings (e.g. after updating settings in admin panel)
     */
    public static function refreshCompanySettings(): array {
        self::$companySettings = null;
        return self::getCompanySettings();
    }

    /**
     * Get dynamic company name
     */
    public static function getCompanyName(): string {
        $settings = self::getCompanySettings();
        return !empty($settings['company_name']) ? (string)$settings['company_name'] : 'ADITYA MANPOWER SUPPLIER';
    }

    /**
     * Get dynamic company tagline
     */
    public static function getCompanyTagline(): string {
        $settings = self::getCompanySettings();
        return !empty($settings['tagline']) ? (string)$settings['tagline'] : 'Workforce Deployment & Enterprise Operations';
    }

    /**
     * Generate dynamic initials from company name (e.g. "ADITYA MANPOWER SUPPLIER" -> "AMS")
     */
    public static function getCompanyInitials(): string {
        $name = trim(self::getCompanyName());
        if (empty($name)) return 'AMS';
        $words = preg_split('/\s+/', $name);
        $initials = '';
        foreach ($words as $w) {
            $w = trim($w);
            if ($w !== '') {
                $initials .= mb_strtoupper(mb_substr($w, 0, 1));
            }
            if (mb_strlen($initials) >= 3) break;
        }
        return $initials ?: 'AMS';
    }

    /**
     * Get company logo web URL if exists on disk
     */
    public static function getCompanyLogo(): ?string {
        $settings = self::getCompanySettings();
        $logo = $settings['logo_url'] ?? '';
        if (!empty($logo) && defined('ROOT_PATH')) {
            $cleanLogo = ltrim(str_replace('\\', '/', $logo), '/');
            if (file_exists(ROOT_PATH . '/' . $cleanLogo)) {
                return (defined('BASE_URL') ? BASE_URL : '') . '/' . $cleanLogo;
            }
        }
        return null;
    }

    /**
     * Format currency with Rupee symbol and Indian numbering
     */
    public static function formatCurrency(float|int|string|null $amount): string {
        $val = (float)($amount ?? 0);
        if ($val < 0) {
            return '-₹' . number_format(abs($val), 2);
        }
        return '₹' . number_format($val, 2);
    }

    /**
     * Format date
     */
    public static function formatDate(?string $date, string $format = 'd M Y'): string {
        if (empty($date) || $date === '0000-00-00') return '-';
        $timestamp = strtotime($date);
        return $timestamp ? date($format, $timestamp) : '-';
    }

    /**
     * Format date and time
     */
    public static function formatDateTime(?string $datetime, string $format = 'd M Y, h:i A'): string {
        if (empty($datetime) || str_starts_with($datetime, '0000-00-00')) return '-';
        $timestamp = strtotime($datetime);
        return $timestamp ? date($format, $timestamp) : '-';
    }

    /**
     * Format time
     */
    public static function formatTime(?string $time, string $format = 'h:i A'): string {
        if (empty($time)) return '-';
        $timestamp = strtotime($time);
        return $timestamp ? date($format, $timestamp) : '-';
    }

    /**
     * Render rich shift badge
     */
    public static function shiftBadge(string $shiftName, string $shiftCode = '', bool $isOvernight = false): string {
        $bg = 'bg-primary-subtle text-primary border border-primary-subtle';
        $icon = '<i class="bi bi-brightness-high me-1"></i>';

        $isNight = false;
        if (stripos($shiftCode, '1') !== false || stripos($shiftName, 'morning') !== false) {
            $bg = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
            $icon = '<i class="bi bi-sun me-1"></i>';
        } elseif (stripos($shiftCode, '2') !== false || stripos($shiftName, 'evening') !== false) {
            $bg = 'bg-info-subtle text-info-emphasis border border-info-subtle';
            $icon = '<i class="bi bi-sunset me-1"></i>';
        } elseif (stripos($shiftCode, '3') !== false || stripos($shiftName, 'night') !== false || $isOvernight) {
            $bg = 'badge-night-shift border';
            $icon = '<i class="bi bi-moon-stars-fill me-1" style="color: #6b21a8;"></i>';
            $isNight = true;
        }

        $nightStyle = $isNight ? 'style="background-color: #f3e8ff !important; color: #581c87 !important; border-color: #c084fc !important; font-weight: 600;"' : '';
        $overnightTag = $isOvernight ? ' <span class="badge bg-dark ms-1" style="font-size:0.65rem;">Night / Next Day</span>' : '';
        return '<span class="badge ' . $bg . ' px-2.5 py-1 rounded-pill" ' . $nightStyle . '>' . $icon . htmlspecialchars($shiftName) . '</span>' . $overnightTag;
    }

    /**
     * Render status badge
     */
    public static function statusBadge(string $status, ?string $carriedToInvoice = null): string {
        $st = strtolower(trim($status));
        $icon = '';
        if ($st === 'scheduled') {
            $class = 'bg-primary-subtle text-primary border border-primary-subtle';
            $icon = '<i class="bi bi-clock-history me-1"></i>';
            $label = 'Scheduled';
        } elseif ($st === 'carried_forward') {
            $class = 'bg-info-subtle text-info border border-info-subtle';
            $icon = '<i class="bi bi-arrow-right-circle me-1"></i>';
            $label = !empty($carriedToInvoice) ? "Carry Forward to {$carriedToInvoice}" : 'Carry Forward';
        } else {
            $class = match($st) {
                'active', 'present', 'paid', 'approved', 'completed' => 'bg-success-subtle text-success border border-success-subtle',
                'pending', 'half_day', 'partially_paid' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                'inactive', 'absent', 'unpaid', 'cancelled', 'blocked' => 'bg-danger-subtle text-danger border border-danger-subtle',
                'leave' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            };
            $label = ucwords(str_replace('_', ' ', $status));
        }
        return '<span class="badge ' . $class . ' px-2 py-1 rounded-pill">' . $icon . htmlspecialchars($label) . '</span>';
    }

    /**
     * Sanitize string input
     */
    public static function sanitize(?string $str): string {
        if ($str === null) return '';
        return trim(htmlspecialchars($str, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Flash messages
     */
    public static function setFlash(string $type, string $message): void {
        $_SESSION['flash'] = [
            'type' => $type, // success, danger, warning, info
            'message' => $message
        ];
    }

    public static function getFlash(): ?array {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }

    public static function hasFlash(): bool {
        return isset($_SESSION['flash']);
    }

    /**
     * Safe redirection
     */
    public static function redirect(string $url): void {
        if (!str_starts_with($url, 'http')) {
            $url = BASE_URL . '/' . ltrim($url, '/');
        }
        header("Location: $url");
        exit;
    }

    /**
     * Calculate GPS distance in meters between two lat/lng points (Haversine formula)
     */
    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371000; // in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadius * $c, 2);
    }

    /**
     * Render quick top sub-navigation pills for unified hub views
     */
    public static function renderSubNav(string $hub, string $activeKey): string {
        $hubs = [
            'operations' => [
                'deploy'     => ['label' => 'Excel Deploy', 'icon' => 'bi-file-earmark-spreadsheet-fill', 'url' => BASE_URL . '/modules/mapping/deploy.php'],
                'roster'     => ['label' => 'Duty Roster', 'icon' => 'bi-calendar-check', 'url' => BASE_URL . '/modules/mapping/index.php'],
                'attendance' => ['label' => 'Shift Attendance', 'icon' => 'bi-person-check', 'url' => BASE_URL . '/modules/attendance/index.php'],
                'shifts'     => ['label' => 'Shift Rules', 'icon' => 'bi-clock-history', 'url' => BASE_URL . '/modules/shifts/index.php'],
            ],
            'directory' => [
                'staff'      => ['label' => 'Staff Directory', 'icon' => 'bi-people', 'url' => BASE_URL . '/modules/staff/index.php'],
                'categories' => ['label' => 'Categories & Rates', 'icon' => 'bi-tags', 'url' => BASE_URL . '/modules/staff/categories.php'],
                'clients'    => ['label' => 'Clients Directory', 'icon' => 'bi-buildings', 'url' => BASE_URL . '/modules/clients/index.php'],
                'sites'      => ['label' => 'Venues & Sites', 'icon' => 'bi-geo-alt', 'url' => BASE_URL . '/modules/sites/index.php'],
                'rates'      => ['label' => 'Client Rate Cards', 'icon' => 'bi-cash-stack', 'url' => BASE_URL . '/modules/rates/index.php'],
            ],
        ];

        if (!isset($hubs[$hub])) return '';

        $html = '<div class="sub-nav-wrapper mb-2 overflow-auto"><ul class="nav nav-pills gap-1.5 flex-nowrap pb-1">';
        foreach ($hubs[$hub] as $key => $item) {
            $isActive = ($key === $activeKey);
            $cls = $isActive ? 'btn btn-primary btn-xs active px-2.5 py-1 shadow-2xs fw-semibold' : 'btn btn-outline-secondary btn-xs px-2.5 py-1 bg-white text-dark fw-semibold';
            $html .= '<li class="nav-item">';
            $html .= '<a class="' . $cls . '" href="' . $item['url'] . '" style="font-size: 0.74rem;">';
            $html .= '<i class="bi ' . $item['icon'] . ' me-1"></i> ' . htmlspecialchars($item['label']);
            $html .= '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }
}

