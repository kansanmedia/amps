<?php
// core/Security.php - Enterprise Security Hardening Suite for Kansan Manpower Management

declare(strict_types=1);

class Security {
    /**
     * 1. SSRF Protection: Validate that an outbound URL does not target loopback, private RFC1918, or cloud metadata IPs
     */
    public static function validateOutboundUrl(string $url): array {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return ['valid' => false, 'error' => 'Invalid URL structure.'];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => 'Unsupported protocol. Only HTTP and HTTPS are allowed.'];
        }

        $host = $parts['host'];
        $ip = gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['valid' => false, 'error' => 'Could not resolve domain name to an IP address.'];
        }

        // Check for Private, Loopback, Link-Local or Cloud Metadata IP ranges (SSRF protection)
        $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $isLoopback = ($ip === '127.0.0.1' || str_starts_with($ip, '127.') || $ip === '::1');
        $isCloudMeta = ($ip === '169.254.169.254'); // AWS/GCP/Azure IMDS metadata IP

        if ($isPrivate || $isLoopback || $isCloudMeta) {
            return ['valid' => false, 'error' => "SSRF Blocked: Address {$ip} resolves to an internal/private network."];
        }

        return ['valid' => true, 'ip' => $ip, 'clean_url' => $url];
    }

    /**
     * 2. BOLA / Ownership Enforcement: Verify that the current user owns or is authorized to access a record
     */
    public static function enforceOwnership(?int $recordOwnerId, ?int $currentUserId, string $resourceName = 'Resource'): void {
        if ($recordOwnerId === null || $currentUserId === null || $recordOwnerId !== $currentUserId) {
            http_response_code(403);
            if (class_exists('Helper') && method_exists('Helper', 'setFlash')) {
                Helper::setFlash('danger', "Security Access Denied: You do not have permission to modify this {$resourceName}.");
            }
            die("Security Authorization Violation: Access Denied for {$resourceName}.");
        }
    }

    /**
     * 3. Secure Password Hashing: Uses Argon2id if available on the PHP runtime, otherwise Bcrypt (cost 12)
     */
    public static function hashPassword(string $password): string {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 1
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Password Strength Validation: Enforce min 8 characters, including numbers and letters
     */
    public static function validatePasswordStrength(string $password): array {
        if (strlen($password) < 8) {
            return ['valid' => false, 'error' => 'Password must be at least 8 characters long.'];
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'error' => 'Password must contain both letters and at least one number.'];
        }
        return ['valid' => true];
    }

    /**
     * 4. Sensitive Log Masking: Recursively masks sensitive fields (passwords, bank accounts, Aadhaar, PAN)
     */
    public static function maskSensitiveData(mixed $data): mixed {
        if (!is_array($data)) {
            return $data;
        }

        $sensitivePatterns = [
            'password', 'password_hash', 'pass', 'pwd',
            'csrf_token', 'token', 'secret', 'auth_token',
            'account_number', 'bank_account', 'acc_no',
            'aadhaar', 'id_proof_number', 'pan_number',
            'card_number', 'cvv', 'pin', 'mfa_code'
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string)$key);
            $matched = false;
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($keyLower, $pattern)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                // Completely mask credentials, passwords, tokens and secrets
                $completeMask = ['password', 'pass', 'pwd', 'token', 'secret', 'cvv', 'pin', 'mfa_code'];
                $isCred = false;
                foreach ($completeMask as $cm) {
                    if (str_contains($keyLower, $cm)) {
                        $isCred = true;
                        break;
                    }
                }

                if ($isCred) {
                    $sanitized[$key] = '********';
                } elseif (is_string($value) && strlen($value) > 4) {
                    $sanitized[$key] = '****' . substr($value, -4);
                } else {
                    $sanitized[$key] = '********';
                }
            } elseif (is_array($value)) {
                $sanitized[$key] = self::maskSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * 5. File Upload Verification: Check real MIME type, file size and prevent dangerous payload execution
     */
    public static function validateUploadedFile(array $file, array $allowedMimes, int $maxBytes = 2097152): array {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'File upload error code: ' . ($file['error'] ?? 'missing')];
        }

        if ($file['size'] > $maxBytes) {
            $mb = round($maxBytes / (1024 * 1024), 1);
            return ['valid' => false, 'error' => "File size exceeds the allowed limit of {$mb}MB."];
        }

        $tmpPath = $file['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            return ['valid' => false, 'error' => 'Invalid file upload source.'];
        }

        // Real MIME check via fileinfo extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!in_array($realMime, $allowedMimes, true)) {
            return ['valid' => false, 'error' => "Disallowed file type detected ({$realMime})."];
        }

        // Extra check for images
        if (str_starts_with($realMime, 'image/')) {
            $imgInfo = @getimagesize($tmpPath);
            if ($imgInfo === false) {
                return ['valid' => false, 'error' => 'Corrupt or deceptive image file detected.'];
            }
        }

        return ['valid' => true, 'mime' => $realMime];
    }

    /**
     * 6. Data Integrity & Backfill Verification: Cryptographic HMAC Signature for invoices / financial records
     */
    public static function generateSignature(mixed $payload, string $secretKey = 'KM_ENTERPRISE_SIGNATURE_KEY'): string {
        $canonical = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : (string)$payload;
        return hash_hmac('sha256', $canonical, $secretKey);
    }

    public static function verifySignature(mixed $payload, string $signature, string $secretKey = 'KM_ENTERPRISE_SIGNATURE_KEY'): bool {
        $expected = self::generateSignature($payload, $secretKey);
        return hash_equals($expected, $signature);
    }

    /**
     * 7. Lightweight Stateless JWT Token Generator & Verifier (HMAC-SHA256)
     */
    public static function generateJWT(array $payload, string $secret = 'KM_SECURE_JWT_SECRET_2026', int $ttlSeconds = 3600): string {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttlSeconds;

        $b64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
        $b64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $signature = hash_hmac('sha256', "$b64Header.$b64Payload", $secret, true);
        $b64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return "$b64Header.$b64Payload.$b64Signature";
    }

    public static function verifyJWT(string $token, string $secret = 'KM_SECURE_JWT_SECRET_2026'): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$b64Header, $b64Payload, $b64Signature] = $parts;
        $expectedSig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(hash_hmac('sha256', "$b64Header.$b64Payload", $secret, true)));

        if (!hash_equals($expectedSig, $b64Signature)) {
            return null;
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $b64Payload)), true);
        if (!$payload || !isset($payload['exp']) || time() > $payload['exp']) {
            return null; // Expired
        }

        return $payload;
    }
}
