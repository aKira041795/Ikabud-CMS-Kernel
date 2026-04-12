<?php
/**
 * Security Module — Audit Log
 */

declare(strict_types=1);

/**
 * Write a security audit log entry.
 */
function securityAuditLog(string $eventType, string $severity = 'info', array $detail = []): void
{
    // Check if audit logging is enabled (default to enabled if settings unavailable).
    try {
        $settings = securityGetSettings();
        if (($settings['audit_log_enabled'] ?? '1') !== '1') {
            return;
        }
    } catch (\Throwable $e) {
        // If settings can't be read (e.g., during bootstrap), still log.
    }

    $ip = function_exists('kernel_client_ip') ? kernel_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $userId = null;
    $userSource = null;

    try {
        $user = app()->currentUser();
        if (is_array($user)) {
            $userId = (int)($user['id'] ?? 0) ?: null;
            $userSource = $user['source'] ?? null;
        }
    } catch (\Throwable $ignored) {
    }

    try {
        $db = securityDb();
        $stmt = $db->prepare(
            'INSERT INTO security_audit_log (event_type, severity, ip_address, user_id, user_source, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $eventType,
            $severity,
            $ip,
            $userId,
            $userSource,
            !empty($detail) ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (\Throwable $e) {
        // Non-fatal: audit log writes must never break the request.
        if (function_exists('write_log')) {
            write_log('Security audit log write failed: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Get recent audit log entries.
 */
function securityGetAuditLog(int $limit = 50, int $offset = 0, ?string $eventTypeFilter = null): array
{
    $db = securityDb();

    $where = '';
    $params = [];
    if ($eventTypeFilter !== null && $eventTypeFilter !== '' && $eventTypeFilter !== 'all') {
        $where = 'WHERE event_type = ?';
        $params[] = $eventTypeFilter;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM security_audit_log {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT * FROM security_audit_log {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return ['entries' => $entries, 'total' => $total];
}

/**
 * Get audit log statistics for the dashboard.
 */
function securityGetAuditStats(): array
{
    $db = securityDb();

    $total = (int)$db->query('SELECT COUNT(*) FROM security_audit_log')->fetchColumn();

    $todayCount = (int)$db->query(
        'SELECT COUNT(*) FROM security_audit_log WHERE created_at >= CURDATE()'
    )->fetchColumn();

    $criticalToday = (int)$db->query(
        "SELECT COUNT(*) FROM security_audit_log WHERE severity = 'critical' AND created_at >= CURDATE()"
    )->fetchColumn();

    $warningToday = (int)$db->query(
        "SELECT COUNT(*) FROM security_audit_log WHERE severity = 'warning' AND created_at >= CURDATE()"
    )->fetchColumn();

    // Event type breakdown (top 5 today).
    $breakdown = $db->query(
        "SELECT event_type, COUNT(*) AS cnt FROM security_audit_log
         WHERE created_at >= CURDATE() GROUP BY event_type ORDER BY cnt DESC LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    return [
        'total'          => $total,
        'today'          => $todayCount,
        'critical_today' => $criticalToday,
        'warning_today'  => $warningToday,
        'breakdown'      => $breakdown,
    ];
}

/**
 * Clean up old audit log entries based on retention setting.
 *
 * @return int Number of entries deleted.
 */
function securityPruneAuditLog(): int
{
    $settings = securityGetSettings();
    $days = max(1, (int)($settings['audit_log_retention_days'] ?? 90));

    $db = securityDb();
    $stmt = $db->prepare('DELETE FROM security_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);

    return $stmt->rowCount();
}
