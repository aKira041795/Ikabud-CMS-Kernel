<?php
/**
 * Security Module — Admin IP Allowlist
 */

declare(strict_types=1);

/**
 * Check if the given IP is allowed for admin access.
 * Returns true if:
 *   - Feature is disabled, OR
 *   - Allowlist is empty (dormant), OR
 *   - IP is in the allowlist.
 */
function securityIsAdminIpAllowed(string $ip): bool
{
    $settings = securityGetSettings();
    if (($settings['admin_ip_allowlist_enabled'] ?? '0') !== '1') {
        return true;
    }

    $db = securityDb();
    $count = (int)$db->query('SELECT COUNT(*) FROM security_admin_ip_allowlist')->fetchColumn();
    if ($count === 0) {
        // Allowlist is empty — feature is dormant, allow all.
        return true;
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM security_admin_ip_allowlist WHERE ip_address = ?');
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Add an IP to the admin allowlist.
 */
function securityAddIpAllowlist(string $ip, string $label = '', ?int $userId = null): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    $db = securityDb();
    $stmt = $db->prepare(
        'INSERT IGNORE INTO security_admin_ip_allowlist (ip_address, label, created_by) VALUES (?, ?, ?)'
    );
    $stmt->execute([$ip, $label, $userId]);

    securityAuditLog('ip_allowlist_added', 'info', [
        'ip' => $ip,
        'label' => $label,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Remove an IP from the admin allowlist.
 */
function securityRemoveIpAllowlist(string $ip): bool
{
    $db = securityDb();
    $stmt = $db->prepare('DELETE FROM security_admin_ip_allowlist WHERE ip_address = ?');
    $stmt->execute([$ip]);

    securityAuditLog('ip_allowlist_removed', 'info', ['ip' => $ip]);

    return $stmt->rowCount() > 0;
}

/**
 * Get all IPs in the admin allowlist.
 */
function securityGetIpAllowlist(): array
{
    $db = securityDb();
    return $db->query('SELECT * FROM security_admin_ip_allowlist ORDER BY created_at DESC')
        ->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Enforce admin IP allowlist on request dispatch.
 * Called via kernel.request.before_dispatch hook.
 */
function securityEnforceIpAllowlist(array $context): array
{
    if (!securityIsEnabled()) {
        return $context;
    }

    $uri = $context['uri'] ?? '';

    // Only enforce on admin routes.
    if (!str_starts_with($uri, '/admin/') && !str_starts_with($uri, '/superadmin/')) {
        return $context;
    }

    // Superadmin routes are exempt to prevent lockout.
    if (str_starts_with($uri, '/superadmin/')) {
        return $context;
    }

    $ip = kernel_client_ip();
    if (!securityIsAdminIpAllowed($ip)) {
        securityAuditLog('ip_allowlist_blocked', 'warning', [
            'ip'  => $ip,
            'uri' => $uri,
        ]);

        if (function_exists('kernelEmitEvent')) {
            try {
                kernelEmitEvent('security.ip_blocked', ['ip' => $ip, 'uri' => $uri]);
            } catch (\Throwable $ignored) {
            }
        }

        http_response_code(403);
        echo json_encode(['error' => 'Access denied: IP not in admin allowlist']);
        $context['handled'] = true;
    }

    return $context;
}
