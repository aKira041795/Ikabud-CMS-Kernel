<?php
/**
 * Security Module — Handlers
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// ── Admin Pages ───────────────────────────────────────────────────────────

/**
 * GET /admin/security — Dashboard
 */
function pageSecurityDashboard(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $settings   = securityGetSettings();
    $auditStats = securityGetAuditStats();
    $integrity  = securityGetIntegrityReport();
    $allowlist  = securityGetIpAllowlist();

    // Recent audit log entries.
    $recentLog = securityGetAuditLog(20);

    echo securityRender('modules/security/pages/home.disyl', [
        'page_title'  => 'Security Dashboard',
        'settings'    => $settings,
        'audit_stats' => $auditStats,
        'integrity'   => $integrity,
        'allowlist'   => $allowlist,
        'recent_log'  => $recentLog['entries'] ?? [],
    ]);
}

/**
 * GET /admin/security/audit-log — Full audit log view
 */
function pageSecurityAuditLog(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $filter = $_GET['filter'] ?? 'all';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $result = securityGetAuditLog($limit, $offset, $filter !== 'all' ? $filter : null);

    echo securityRender('modules/security/pages/audit-log.disyl', [
        'page_title' => 'Security Audit Log',
        'entries'    => $result['entries'],
        'total'      => $result['total'],
        'filter'     => $filter,
        'page'       => $page,
        'limit'      => $limit,
        'total_pages' => max(1, (int)ceil($result['total'] / $limit)),
    ]);
}

/**
 * GET /admin/security/integrity — File integrity view
 */
function pageSecurityIntegrity(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $integrity = securityGetIntegrityReport();

    echo securityRender('modules/security/pages/integrity.disyl', [
        'page_title' => 'File Integrity',
        'integrity'  => $integrity,
    ]);
}

/**
 * GET /admin/security/ip-allowlist — IP Allowlist management
 */
function pageSecurityIpAllowlist(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $allowlist = securityGetIpAllowlist();
    $settings  = securityGetSettings();

    echo securityRender('modules/security/pages/ip-allowlist.disyl', [
        'page_title'             => 'Admin IP Allowlist',
        'allowlist'              => $allowlist,
        'allowlist_enabled'      => ($settings['admin_ip_allowlist_enabled'] ?? '0') === '1',
    ]);
}

/**
 * GET /admin/security/settings — Settings page
 */
function pageSecuritySettings(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $settings = securityGetSettings();

    echo securityRender('modules/security/pages/settings.disyl', [
        'page_title' => 'Security Settings',
        'settings'   => $settings,
    ]);
}

// ── API Endpoints ─────────────────────────────────────────────────────────

/**
 * POST /api/v1/security/settings — Save settings
 */
function apiSaveSettings(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $input = app()->input();
    $allowed = array_keys(securityDefaultSettings());

    $toSave = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            $toSave[$key] = (string)$input[$key];
        }
    }

    if (!empty($toSave)) {
        saveTenantModuleSettings('security', $toSave);
    }

    securityAuditLog('settings_updated', 'info', ['keys' => array_keys($toSave)]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'saved' => count($toSave)]);
}

/**
 * POST /api/v1/security/integrity-baseline — Rebuild integrity baseline
 */
function apiRebuildBaseline(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $result = securityBuildFileBaseline();

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'scanned' => $result['scanned'], 'stored' => $result['stored']]);
}

/**
 * POST /api/v1/security/integrity-check — Run integrity check
 */
function apiCheckIntegrity(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $result = securityCheckFileIntegrity();

    header('Content-Type: application/json');
    echo json_encode($result);
}

/**
 * POST /api/v1/security/ip-allowlist/add — Add IP to allowlist
 */
function apiAddIpAllowlist(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $input = app()->input();
    $ip = trim((string)($input['ip_address'] ?? ''));
    $label = trim((string)($input['label'] ?? ''));

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid IP address']);
        return;
    }

    $user = $ctx->user();
    $userId = (int)($user['id'] ?? 0) ?: null;
    $added = securityAddIpAllowlist($ip, $label, $userId);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'added' => $added]);
}

/**
 * POST /api/v1/security/ip-allowlist/remove — Remove IP from allowlist
 */
function apiRemoveIpAllowlist(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $input = app()->input();
    $ip = trim((string)($input['ip_address'] ?? ''));

    if ($ip === '') {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'IP address required']);
        return;
    }

    $removed = securityRemoveIpAllowlist($ip);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'removed' => $removed]);
}

/**
 * POST /api/v1/security/audit-log/clear — Clear old audit log entries
 */
function apiClearAuditLog(array $params = []): void
{
    $ctx = securityCtx();
    $ctx->requireAnyRole('admin');

    $deleted = securityPruneAuditLog();

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'deleted' => $deleted]);
}
