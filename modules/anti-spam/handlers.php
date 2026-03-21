<?php
/**
 * Anti-Spam Module — Handlers
 */

declare(strict_types=1);

// ── Admin Pages ───────────────────────────────────────────────────────────

/**
 * GET /admin/anti-spam — Dashboard
 */
function pageAntiSpamDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $ctx->requireAnyRole('admin');

    $stats    = antispamGetStats();
    $settings = antispamGetSettings();

    // Recent log entries (last 20)
    $db  = $ctx->db();
    $recent = $db->query('SELECT * FROM antispam_log ORDER BY created_at DESC LIMIT 20')->fetchAll(\PDO::FETCH_ASSOC);

    echo $ctx->render('modules/anti-spam/pages/home.disyl', [
        'page_title' => 'Anti-Spam Dashboard',
        'stats'      => $stats,
        'settings'   => $settings,
        'recent_log' => $recent,
    ]);
}

/**
 * GET /admin/anti-spam/log — Full log view
 */
function pageAntiSpamLog(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $ctx->requireAnyRole('admin');

    $db = $ctx->db();
    $filter = $_GET['filter'] ?? 'all';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $where = '';
    $bindParams = [];
    if ($filter === 'fail') {
        $where = 'WHERE result = ?';
        $bindParams[] = 'fail';
    } elseif ($filter === 'pass') {
        $where = 'WHERE result = ?';
        $bindParams[] = 'pass';
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM antispam_log {$where}");
    $countStmt->execute($bindParams);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM antispam_log {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($bindParams);
    $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo $ctx->render('modules/anti-spam/pages/log.disyl', [
        'page_title' => 'Anti-Spam Log',
        'entries'    => $entries,
        'filter'     => $filter,
        'page_num'   => $page,
        'total'      => $total,
        'total_pages'=> (int)ceil($total / $limit),
    ]);
}

/**
 * GET /admin/anti-spam/blocked — Blocked IPs
 */
function pageAntiSpamBlocked(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $ctx->requireAnyRole('admin');

    $db = $ctx->db();
    $ips = $db->query('SELECT * FROM antispam_blocked_ips ORDER BY created_at DESC')->fetchAll(\PDO::FETCH_ASSOC);

    echo $ctx->render('modules/anti-spam/pages/blocked.disyl', [
        'page_title'  => 'Blocked IPs',
        'blocked_ips' => $ips,
    ]);
}

/**
 * GET /admin/anti-spam/settings — Settings page
 */
function pageAntiSpamSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $ctx->requireAnyRole('admin');

    $settings = antispamGetSettings();

    echo $ctx->render('modules/anti-spam/pages/settings.disyl', [
        'page_title' => 'Anti-Spam Settings',
        'settings'   => $settings,
    ]);
}

// ── API Handlers ──────────────────────────────────────────────────────────

/**
 * POST /api/v1/anti-spam/settings — Save settings
 */
function apiSaveSettings(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false]); return; }
    $ctx->requireAnyRole('admin');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $allowed = [
        'enabled', 'honeypot_enabled', 'rate_limit_enabled',
        'rate_limit_window', 'rate_limit_max',
        'keyword_block_enabled', 'blocked_keywords', 'log_retention_days',
    ];

    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            antispamSaveSetting($key, (string)$input[$key]);
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Settings saved.']);
}

/**
 * POST /api/v1/anti-spam/block-ip — Block an IP
 */
function apiBlockIp(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false]); return; }
    $ctx->requireAnyRole('admin');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $ip     = trim((string)($input['ip'] ?? ''));
    $reason = trim((string)($input['reason'] ?? 'Manual block'));
    $duration = isset($input['duration']) ? (int)$input['duration'] : null;

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid IP address.']);
        return;
    }

    antispamBlockIp($ip, $reason, $duration > 0 ? $duration : null);
    antispamLog($ip, 'manual', 'fail', "Blocked: {$reason}");

    echo json_encode(['ok' => true, 'message' => "IP {$ip} blocked."]);
}

/**
 * POST /api/v1/anti-spam/unblock-ip — Unblock an IP
 */
function apiUnblockIp(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false]); return; }
    $ctx->requireAnyRole('admin');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ip = trim((string)($input['ip'] ?? ''));

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid IP address.']);
        return;
    }

    antispamUnblockIp($ip);
    echo json_encode(['ok' => true, 'message' => "IP {$ip} unblocked."]);
}

/**
 * POST /api/v1/anti-spam/clear-log — Clear old log entries
 */
function apiClearLog(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false]); return; }
    $ctx->requireAnyRole('admin');

    $settings = antispamGetSettings();
    $days = max(1, (int)($settings['log_retention_days'] ?? 30));

    $db = $ctx->db();
    $stmt = $db->prepare('DELETE FROM antispam_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    $deleted = $stmt->rowCount();

    echo json_encode(['ok' => true, 'message' => "{$deleted} old log entries cleared."]);
}
