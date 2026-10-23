<?php
// core/Audit.php - Centralized Audit Logging System

declare(strict_types=1);

class Audit {
    public static function log(string $action, string $module, ?int $recordId = null, mixed $oldValues = null, mixed $newValues = null): void {
        try {
            $db = DB::getConnection();
            $userId = Auth::id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 250);

            // Security Hardening: Automatically mask passwords, tokens, bank accounts, aadhaar, PAN
            $maskedOld = ($oldValues !== null && class_exists('Security')) ? Security::maskSensitiveData($oldValues) : $oldValues;
            $maskedNew = ($newValues !== null && class_exists('Security')) ? Security::maskSensitiveData($newValues) : $newValues;

            $oldJson = $maskedOld !== null ? json_encode($maskedOld, JSON_UNESCAPED_UNICODE) : null;
            $newJson = $maskedNew !== null ? json_encode($maskedNew, JSON_UNESCAPED_UNICODE) : null;

            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, module, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (:uid, :act, :mod, :rid, :old, :new, :ip, :ua, NOW())
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':act' => strtoupper($action),
                ':mod' => strtoupper($module),
                ':rid' => $recordId,
                ':old' => $oldJson,
                ':new' => $newJson,
                ':ip'  => $ip,
                ':ua'  => $ua
            ]);
        } catch (Exception $e) {
            // Log silently to avoid breaking primary transaction flow
            error_log("Audit log failed: " . $e->getMessage());
        }
    }
}
