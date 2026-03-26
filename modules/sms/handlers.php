<?php
/**
 * SMS Module Handlers
 * 
 * All route handler functions for the SMS module.
 * Adapted for Baron Bakeshop kernel v2 (hooks, CSRF, response format).
 * 
 * @package Baron\Modules\SMS
 */

declare(strict_types=1);

// Load SMS gateway helper
require_once __DIR__ . '/helpers/sms-gateway.php';

// ============================================================
// PAGE HANDLERS
// ============================================================

/**
 * SMS Log page — main module page showing sent/failed messages
 */
function pageSmsLog(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireAnyRole('admin', 'supervisor');
    
    echo smsRender('modules/sms/pages/sms-log.disyl', [
        'current_page' => 'sms',
        'page_title' => 'SMS Notifications',
        'sms_configured' => smsIsConfigured(),
        'sms_settings' => smsGetSettings(),
    ]);
}

/**
 * SMS Compose modal/partial
 */
function pageSmsCompose(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireAnyRole('admin', 'supervisor');
    
    echo smsRender('modules/sms/partials/compose.disyl', [
        'sms_configured' => smsIsConfigured(),
        'test_mode' => !empty(smsGetSettings()['sms_test_mode']),
    ]);
}

/**
 * SMS Templates management partial
 */
function pageSmsTemplates(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireRole('admin');

    $templates = $ctx->db()->query("SELECT * FROM sms_templates ORDER BY event_key")->fetchAll(\PDO::FETCH_ASSOC);

    echo $ctx->render('modules/sms/partials/templates.disyl', [
        'templates' => $templates,
    ]);
}

/**
 * SMS Settings page/partial
 */
function pageSmsSettings(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireRole('admin');
    
    $settings = smsGetSettings();
    $settingDefs = smsSettingsFieldDefinitions();
    
    // Pre-resolve each field so the template doesn't need dynamic key lookups
    $fields = [];
    foreach ($settingDefs as $def) {
        $key = $def['key'];
        $type = $def['type'] ?? 'text';
        $value = $settings[$key] ?? ($def['default'] ?? '');
        if ($type === 'password') {
            $value = '';
        }
        if ($type === 'checkbox') {
            $value = in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
        }
        $fields[] = [
            'key'         => $key,
            'label'       => $def['label'] ?? $key,
            'type'        => $type,
            'value'       => $value,
            'description' => $def['description'] ?? '',
            'options'     => $def['options'] ?? [],
        ];
    }
    
    echo smsRender('modules/sms/partials/settings.disyl', [
        'fields' => $fields,
    ]);
}

// ============================================================
// API HANDLERS
// ============================================================

/**
 * GET /api/v1/modules/sms/log — List SMS log entries
 */
function apiSmsLog(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireAnyRole('admin', 'supervisor');

    $db = $ctx->db();
    $input = $ctx->input();
    $page = max(1, (int) ($input['page'] ?? 1));
    $limit = min(100, max(10, (int) ($input['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $status = $input['status'] ?? '';
    $search = trim((string) ($input['search'] ?? ''));
    
    $where = '1=1';
    $binds = [];
    
    if ($status && in_array($status, ['sent', 'failed', 'pending', 'simulated'], true)) {
        $where .= ' AND status = ?';
        $binds[] = $status;
    }
    if ($search !== '') {
        $where .= ' AND (recipient LIKE ? OR recipient_name LIKE ? OR message LIKE ?)';
        $binds[] = "%{$search}%";
        $binds[] = "%{$search}%";
        $binds[] = "%{$search}%";
    }
    
    $countStmt = $db->prepare("SELECT COUNT(*) FROM sms_log WHERE {$where}");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT l.*, u.full_name as sent_by_name
        FROM sms_log l
        LEFT JOIN users u ON l.sent_by = u.id
        WHERE {$where}
        ORDER BY l.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($binds);
    $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if ($ctx->isHtmx()) {
        echo $ctx->render('modules/sms/partials/log-table.disyl', [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ]);
    } else {
        $ctx->json(['ok' => true, 'data' => $logs, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $limit)]);
    }
}

/**
 * GET /api/v1/modules/sms/stats — SMS statistics
 */
function apiSmsStats(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireAnyRole('admin', 'supervisor');
    $db = $ctx->db();
    
    $stats = [];
    
    // Today's counts
    $stmt = $db->query("
        SELECT status, COUNT(*) as cnt
        FROM sms_log
        WHERE DATE(created_at) = CURDATE()
        GROUP BY status
    ");
    $todayCounts = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    
    $stats['today_sent'] = (int) ($todayCounts['sent'] ?? 0);
    $stats['today_failed'] = (int) ($todayCounts['failed'] ?? 0);
    $stats['today_simulated'] = (int) ($todayCounts['simulated'] ?? 0);
    $stats['today_total'] = array_sum(array_map('intval', $todayCounts));
    
    // This month total
    $stmt = $db->query("SELECT COUNT(*) FROM sms_log WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'sent'");
    $stats['month_sent'] = (int) $stmt->fetchColumn();
    
    // All time
    $stmt = $db->query("SELECT COUNT(*) FROM sms_log WHERE status = 'sent'");
    $stats['total_sent'] = (int) $stmt->fetchColumn();
    
    $ctx->json(['ok' => true, 'data' => $stats]);
}

/**
 * GET /api/v1/modules/sms/balance — Check provider balance
 */
function apiSmsBalance(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireRole('admin');
    $ctx->json(smsGetBalance());
}

/**
 * POST /api/v1/modules/sms/send — Send an SMS
 */
function apiSmsSend(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireAnyRole('admin', 'supervisor');

    $input = $ctx->input();
    $to = trim((string) ($input['recipient'] ?? ''));
    $message = trim((string) ($input['message'] ?? ''));
    $recipientName = trim((string) ($input['recipient_name'] ?? ''));
    
    if ($to === '' || $message === '') {
        $ctx->json(['ok' => false, 'error' => 'Recipient number and message are required'], 400);
        return;
    }
    
    if (strlen($message) > 320) {
        $ctx->json(['ok' => false, 'error' => 'Message too long (max 320 characters)'], 400);
        return;
    }
    
    $user = $ctx->user();
    $result = smsSend($to, $message, [
        'recipient_name' => $recipientName,
        'trigger_event' => 'manual',
        'sent_by' => $user['id'] ?? null,
    ]);

    if ($ctx->isHtmx()) {
        if (!headers_sent()) {
            header('HX-Trigger: ' . json_encode([
                'showToast' => [
                    'message' => $result['ok'] ? 'SMS sent' : ('SMS failed: ' . ($result['error'] ?? 'Unknown error')),
                    'type' => $result['ok'] ? 'success' : 'error',
                ],
            ]));
        }

        echo '';
        return;
    }

    $ctx->json($result, $result['ok'] ? 200 : 400);
}

/**
 * POST /api/v1/modules/sms/test — Send a test SMS
 */
function apiSmsTest(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireRole('admin');
    
    $input = smsInput();
    $to = trim((string) ($input['test_number'] ?? ''));
    
    if ($to === '') {
        $ctx->json(['ok' => false, 'error' => 'Phone number is required'], 400);
        return;
    }
    
    $user = smsUser();
    $result = smsSend($to, 'This is a test SMS from Baron Bakeshop. If you received this, SMS is working!', [
        'trigger_event' => 'test',
        'sent_by' => $user['id'] ?? null,
    ]);
    
    $ctx->json($result);
}

/**
 * GET /api/v1/modules/sms/templates — List SMS templates
 */
function apiSmsTemplates(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireRole('admin');
    $templates = $ctx->db()->query("SELECT * FROM sms_templates ORDER BY event_key")->fetchAll(\PDO::FETCH_ASSOC);
    $ctx->json(['ok' => true, 'data' => $templates]);
}

/**
 * POST /api/v1/modules/sms/templates — Save SMS template
 */
function apiSmsTemplateSave(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $ctx->requireRole('admin');
    $input = $ctx->input();
    
    $eventKey = trim((string) ($input['event_key'] ?? ''));
    $template = trim((string) ($input['template'] ?? ''));
    $isEnabled = isset($input['is_enabled']) ? 1 : 0;
    
    if ($eventKey === '' || $template === '') {
        $ctx->json(['ok' => false, 'error' => 'Event key and template are required'], 400);
        return;
    }

    $db = $ctx->db();
    $db->prepare("UPDATE sms_templates SET template = ?, is_enabled = ?, updated_at = NOW() WHERE event_key = ?")
        ->execute([$template, $isEnabled, $eventKey]);
    
    if ($ctx->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Template saved', 'type' => 'success']]));
        echo '';
    } else {
        $ctx->json(['ok' => true]);
    }
}

/**
 * POST /api/v1/modules/sms/settings — Save module settings
 */
function apiSmsSettingsSave(array $params = []): void
{
    $ctx = smsCtx();
    $ctx->requireRole('admin');
    
    $input = smsInput();
    $settingDefs = smsSettingsFieldDefinitions();

    $old = getModuleSettings('sms');
    if (!is_array($old)) {
        $old = [];
    }
    $settings = $old;
    foreach ($settingDefs as $def) {
        $key = $def['key'];
        if ($def['type'] === 'checkbox') {
            $raw = $input[$key] ?? null;
            $settings[$key] = in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        } elseif (($def['type'] ?? '') === 'password') {
            $val = trim((string)($input[$key] ?? ''));
            if ($val !== '') {
                $settings[$key] = $val;
            } elseif (!array_key_exists($key, $settings)) {
                $settings[$key] = '';
            }
        } else {
            $settings[$key] = trim((string) ($input[$key] ?? ($def['default'] ?? '')));
        }
    }
    
    saveModuleSettings('sms', $settings);
    
    if (smsIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'SMS settings saved', 'type' => 'success']]));
        echo '';
    } else {
        $ctx->json(['ok' => true]);
    }
}
