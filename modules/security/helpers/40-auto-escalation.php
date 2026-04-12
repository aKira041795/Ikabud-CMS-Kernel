<?php
/**
 * Security Module — Auto-Escalation from Anti-Spam
 *
 * When anti-spam blocks for a single IP exceed a configurable threshold
 * within a time window, the IP is permanently blocked via the anti-spam
 * module's blocking API.
 */

declare(strict_types=1);

/**
 * Handle an anti-spam event (antispam.blocked or antispam.honeypot.triggered).
 * Checks block count for the IP and auto-escalates if threshold exceeded.
 */
function securityHandleAntiSpamEvent(array $payload): void
{
    if (!securityIsEnabled()) {
        return;
    }

    $settings = securityGetSettings();
    if (($settings['auto_escalation_enabled'] ?? '0') !== '1') {
        return;
    }

    $ip = $payload['ip'] ?? '';
    if ($ip === '') {
        return;
    }

    $threshold = max(1, (int)($settings['auto_escalation_threshold'] ?? 10));
    $windowMinutes = max(1, (int)($settings['auto_escalation_window_minutes'] ?? 60));

    // Count recent blocks for this IP in the anti-spam log (reads_tables: antispam_log).
    $blockCount = securityCountRecentAntiSpamBlocks($ip, $windowMinutes);

    if ($blockCount >= $threshold) {
        securityAutoEscalate($ip, $blockCount, $windowMinutes);
    }
}

/**
 * Count recent anti-spam blocks for an IP.
 */
function securityCountRecentAntiSpamBlocks(string $ip, int $windowMinutes): int
{
    try {
        $db = securityDb();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM antispam_log
             WHERE ip_address = ? AND result = 'fail'
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$ip, $windowMinutes]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        // antispam_log may not exist if anti-spam module is not installed.
        return 0;
    }
}

/**
 * Auto-escalate: permanently block an IP via the anti-spam module.
 */
function securityAutoEscalate(string $ip, int $blockCount, int $windowMinutes): void
{
    // Block via anti-spam if the function exists.
    if (function_exists('antispamBlockIp')) {
        $reason = "Auto-escalated by security module: {$blockCount} blocks in {$windowMinutes}min";
        antispamBlockIp($ip, $reason, null); // null = permanent block
    }

    securityAuditLog('auto_escalation', 'critical', [
        'ip'             => $ip,
        'block_count'    => $blockCount,
        'window_minutes' => $windowMinutes,
    ]);

    // Fire event.
    if (function_exists('kernelEmitEvent')) {
        try {
            kernelEmitEvent('security.auto_escalation', [
                'ip'             => $ip,
                'block_count'    => $blockCount,
                'window_minutes' => $windowMinutes,
            ]);
        } catch (\Throwable $ignored) {
        }
    }

    if (function_exists('write_log')) {
        write_log("Security auto-escalation: IP {$ip} permanently blocked after {$blockCount} anti-spam blocks in {$windowMinutes}min", 'warning');
    }
}
