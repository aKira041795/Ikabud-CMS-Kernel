<?php
/**
 * Anti-Spam Module — Helpers
 *
 * Core anti-spam checking functions.
 * Other modules call antispamCheck() to validate incoming requests.
 */

declare(strict_types=1);

function antispamCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('anti-spam');
    if (!$ctx) {
        throw new \RuntimeException('Anti-spam module context unavailable');
    }

    return $ctx;
}

function antispamRender(string $template, array $context = []): string
{
    return antispamCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function antispamNormalizeDashboardRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = kernelApplyRenderContextShape($context, [
        'page_title' => 'Anti-Spam Dashboard',
        'stats' => [],
        'settings' => [],
        'recent_log' => [],
    ], ['page_title', 'stats', 'settings', 'recent_log'], $missingKeys, $typeMismatches);

    $context['stats'] = kernelApplyRenderContextShape($context['stats'], [
        'blocked_ips' => 0,
        'blocked_today' => 0,
        'passed_today' => 0,
        'total_log' => 0,
    ], ['blocked_ips', 'blocked_today', 'passed_today', 'total_log'], $missingKeys, $typeMismatches, 'stats.');

    return $context;
}

function antispamNormalizeLogRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Anti-Spam Log',
        'entries' => [],
        'filter' => 'all',
        'page_num' => 1,
        'total' => 0,
        'total_pages' => 1,
    ], ['page_title', 'entries', 'filter', 'page_num', 'total', 'total_pages'], $missingKeys, $typeMismatches);
}

function antispamNormalizeBlockedRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Blocked IPs',
        'blocked_ips' => [],
    ], ['page_title', 'blocked_ips'], $missingKeys, $typeMismatches);
}

function antispamNormalizeSettingsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Anti-Spam Settings',
        'settings' => [],
    ], ['page_title', 'settings'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('anti-spam.page.dashboard', [
    'template' => 'modules/anti-spam/pages/home.disyl',
    'priority' => 20,
    'normalize' => 'antispamNormalizeDashboardRenderContext',
    'log_event' => 'anti-spam.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('anti-spam.page.log', [
    'template' => 'modules/anti-spam/pages/log.disyl',
    'priority' => 20,
    'normalize' => 'antispamNormalizeLogRenderContext',
    'log_event' => 'anti-spam.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('anti-spam.page.blocked', [
    'template' => 'modules/anti-spam/pages/blocked.disyl',
    'priority' => 20,
    'normalize' => 'antispamNormalizeBlockedRenderContext',
    'log_event' => 'anti-spam.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('anti-spam.page.settings', [
    'template' => 'modules/anti-spam/pages/settings.disyl',
    'priority' => 20,
    'normalize' => 'antispamNormalizeSettingsRenderContext',
    'log_event' => 'anti-spam.render_context.contract_mismatch',
]);

function antispamDefaultSettings(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['anti-spam'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

function antispamNormalizeSettingValue(string $key, mixed $value): string
{
    $boolKeys = [
        'enabled',
        'auto_protect_web_apis',
        'skip_authenticated_api_users',
        'honeypot_enabled',
        'rate_limit_enabled',
        'keyword_block_enabled',
    ];
    $intKeys = [
        'rate_limit_window',
        'rate_limit_max',
        'log_retention_days',
    ];

    if (in_array($key, $boolKeys, true)) {
        return !empty($value) && !in_array(strtolower((string)$value), ['0', 'false', 'off', 'no'], true) ? '1' : '0';
    }

    if (in_array($key, $intKeys, true)) {
        return (string)max(0, (int)$value);
    }

    return trim((string)$value);
}

function antispamResetSettingsCache(): void
{
    unset($GLOBALS['_antispam_settings_cache']);
}

function antispamDb()
{
    return app()->db();
}

function antispamTableExists(string $table): bool
{
    static $cache = [];
    $tid = app()->tenantId();
    if (!isset($cache[$tid])) {
        $cache[$tid] = [];
    }

    $table = trim($table);
    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $cache[$tid])) {
        return $cache[$tid][$table];
    }

    try {
        $db = antispamDb();
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        $cache[$tid][$table] = (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $cache[$tid][$table] = false;
    }

    return $cache[$tid][$table];
}

function antispamReadLegacySettings(): array
{
    if (!antispamTableExists('antispam_settings')) {
        return [];
    }

    try {
        $db = antispamDb();
        $stmt = $db->query('SELECT setting_key, setting_value FROM antispam_settings');
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function antispamModuleSettings(): array
{
    try {
        $settings = getModuleSettings('anti-spam');
        return is_array($settings) ? $settings : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function antispamBuildRequestBodyText(mixed $input = null, int $maxLength = 2000): string
{
    if ($input === null) {
        $input = app()->input();
    }

    $pieces = [];
    $walk = static function (mixed $value, string $path = '') use (&$walk, &$pieces, $maxLength): void {
        if (strlen(implode(' ', $pieces)) >= $maxLength) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $keyStr = is_string($key) ? $key : (string)$key;
                if (preg_match('/token|password|secret|csrf|refresh/i', $keyStr) === 1) {
                    continue;
                }
                $walk($nested, $path === '' ? $keyStr : $path . '.' . $keyStr);
            }
            return;
        }

        if (is_scalar($value) || $value === null) {
            $string = trim((string)$value);
            if ($string === '') {
                return;
            }
            $pieces[] = substr($string, 0, 250);
        }
    };

    $walk($input);
    return substr(implode(' ', $pieces), 0, $maxLength);
}

function antispamShouldProtectModuleApiRequest(string $moduleId, ?array $user, string $requestUri, string $requestMethod): bool
{
    if ($moduleId === 'anti-spam') {
        return false;
    }

    if (!str_starts_with($requestUri, '/api/') && preg_match('#^/[a-zA-Z0-9\-]+/api/#', $requestUri) !== 1) {
        return false;
    }

    if (!in_array(strtoupper($requestMethod), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return false;
    }

    $settings = antispamGetSettings();
    if (($settings['enabled'] ?? '1') !== '1') {
        return false;
    }
    if (($settings['auto_protect_web_apis'] ?? '1') !== '1') {
        return false;
    }
    if (($settings['skip_authenticated_api_users'] ?? '1') === '1' && is_array($user) && !empty($user)) {
        return false;
    }

    return true;
}

// ── Public API ────────────────────────────────────────────────────────────

/**
 * Run all enabled anti-spam checks against the current request.
 *
 * @param  string $body  Optional request body text to scan for blocked keywords.
 * @return array{pass: bool, check: string, detail: string}
 */
function antispamCheck(string $body = ''): array
{
    $ip = antispamClientIp();

    $settings = antispamGetSettings();
    if (($settings['enabled'] ?? '1') !== '1') {
        return ['pass' => true, 'check' => 'disabled', 'detail' => ''];
    }

    // 1. IP block check
    if (antispamIsIpBlocked($ip)) {
        antispamLog($ip, 'ip_block', 'fail', 'Blocked IP');
        antispamFireEvent('antispam.blocked', ['ip' => $ip, 'check' => 'ip_block', 'detail' => 'Blocked IP address', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        return ['pass' => false, 'check' => 'ip_block', 'detail' => 'Blocked IP address'];
    }

    // 2. Rate limit check
    if (($settings['rate_limit_enabled'] ?? '1') === '1') {
        $window = max(1, (int)($settings['rate_limit_window'] ?? 60));
        $max    = max(1, (int)($settings['rate_limit_max']    ?? 10));
        if (antispamIsRateLimited($ip, $window, $max)) {
            antispamLog($ip, 'rate_limit', 'fail', ">{$max} requests in {$window}s");
            antispamFireEvent('antispam.blocked', ['ip' => $ip, 'check' => 'rate_limit', 'detail' => 'Rate limit exceeded', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
            return ['pass' => false, 'check' => 'rate_limit', 'detail' => 'Rate limit exceeded'];
        }
    }

    // 3. Keyword blocklist
    if ($body !== '' && ($settings['keyword_block_enabled'] ?? '1') === '1') {
        $keywords = array_filter(array_map('trim', explode(',', $settings['blocked_keywords'] ?? '')));
        $matched  = antispamMatchesKeywords($body, $keywords);
        if ($matched !== null) {
            antispamLog($ip, 'keyword', 'fail', "Matched: {$matched}");
            antispamFireEvent('antispam.blocked', ['ip' => $ip, 'check' => 'keyword', 'detail' => "Matched: {$matched}", 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
            return ['pass' => false, 'check' => 'keyword', 'detail' => 'Blocked content detected'];
        }
    }

    antispamLog($ip, 'rate_limit', 'pass', '');
    return ['pass' => true, 'check' => 'all', 'detail' => ''];
}

/**
 * Honeypot check — call this on form submissions.
 * Returns true if the submission looks like a bot (honeypot field was filled).
 */
function antispamHoneypotTriggered(array $input, string $fieldName = '_hp_name'): bool
{
    $settings = antispamGetSettings();
    if (($settings['honeypot_enabled'] ?? '1') !== '1') {
        return false;
    }

    $triggered = !empty($input[$fieldName]);
    if ($triggered) {
        $ip = antispamClientIp();
        antispamLog($ip, 'honeypot', 'fail', "Field: {$fieldName}");
        antispamFireEvent('antispam.honeypot.triggered', ['ip' => $ip, 'field' => $fieldName, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    }
    return $triggered;
}

// ── Settings ──────────────────────────────────────────────────────────────

function antispamGetSettings(): array
{
    // Cache keyed by tenant ID so different tenants in the same process
    // don't share each other's antispam configuration.
    static $cache = [];
    $tid = app()->tenantId();
    if (array_key_exists($tid, $cache)) return $cache[$tid];

    $merged = array_merge(
        antispamDefaultSettings(),
        antispamReadLegacySettings(),
        antispamModuleSettings()
    );

    $normalized = [];
    foreach ($merged as $key => $value) {
        $key = trim((string)$key);
        if ($key === '' || str_starts_with($key, '_')) {
            continue;
        }
        $normalized[$key] = antispamNormalizeSettingValue($key, $value);
    }

    $cache[$tid] = $normalized;
    return $normalized;
}

function antispamSaveSetting(string $key, string $value): void
{
    $normalized = antispamNormalizeSettingValue($key, $value);
    $db = antispamDb();
    $stmt = $db->prepare('INSERT INTO antispam_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $normalized]);

    try {
        saveModuleSettings('anti-spam', [$key => $normalized]);
    } catch (\Throwable $e) {
        write_log('antispamSaveSetting module-settings sync failed: ' . $e->getMessage(), 'warning', ['key' => $key]);
    }

    antispamResetSettingsCache();
}

// ── IP Blocking ───────────────────────────────────────────────────────────

function antispamIsIpBlocked(string $ip): bool
{
    if (!antispamTableExists('antispam_blocked_ips')) {
        return false;
    }

    try {
        $db = antispamDb();
        $stmt = $db->prepare(
            'SELECT id FROM antispam_blocked_ips WHERE ip_address = ? AND (is_permanent = 1 OR blocked_until > NOW())'
        );
        $stmt->execute([$ip]);
        if ($stmt->fetch()) {
            // Increment hit counter
            $db->prepare('UPDATE antispam_blocked_ips SET hits = hits + 1 WHERE ip_address = ?')->execute([$ip]);
            return true;
        }
    } catch (\Throwable $e) {
        write_log('antispamIsIpBlocked failed: ' . $e->getMessage(), 'warning');
    }
    return false;
}

function antispamBlockIp(string $ip, string $reason, ?int $durationMinutes = null): void
{
    if (!antispamTableExists('antispam_blocked_ips')) {
        return;
    }

    $db = antispamDb();

    $permanent   = $durationMinutes === null ? 1 : 0;
    $blockedUntil = $durationMinutes !== null
        ? date('Y-m-d H:i:s', time() + $durationMinutes * 60)
        : null;

    $stmt = $db->prepare(
        'INSERT INTO antispam_blocked_ips (ip_address, reason, blocked_until, is_permanent) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_until = VALUES(blocked_until), is_permanent = VALUES(is_permanent)'
    );
    $stmt->execute([$ip, $reason, $blockedUntil, $permanent]);
}

function antispamUnblockIp(string $ip): void
{
    if (!antispamTableExists('antispam_blocked_ips')) {
        return;
    }

    $db = antispamDb();
    $db->prepare('DELETE FROM antispam_blocked_ips WHERE ip_address = ?')->execute([$ip]);
}

// ── Rate Limiting ─────────────────────────────────────────────────────────

function antispamIsRateLimited(string $ip, int $windowSeconds, int $maxRequests): bool
{
    if (!antispamTableExists('antispam_log')) {
        return false;
    }

    try {
        $db = antispamDb();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM antispam_log WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$ip, $windowSeconds]);
        return (int)$stmt->fetchColumn() >= $maxRequests;
    } catch (\Throwable $e) {
        write_log('antispamIsRateLimited failed: ' . $e->getMessage(), 'warning');
        return false;
    }
}

// ── Keyword Matching ──────────────────────────────────────────────────────

function antispamMatchesKeywords(string $text, array $keywords): ?string
{
    $lower = mb_strtolower($text);
    foreach ($keywords as $kw) {
        if ($kw !== '' && mb_strpos($lower, mb_strtolower($kw)) !== false) {
            return $kw;
        }
    }
    return null;
}

// ── Logging ───────────────────────────────────────────────────────────────

function antispamLog(string $ip, string $checkType, string $result, string $detail): void
{
    if (!antispamTableExists('antispam_log')) {
        return;
    }

    try {
        $db = antispamDb();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $stmt = $db->prepare(
            'INSERT INTO antispam_log (ip_address, request_uri, check_type, result, detail) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ip, substr($uri, 0, 500), $checkType, $result, substr($detail, 0, 500)]);
    } catch (\Throwable $e) {
        // Silently fail — logging should never break the request
    }
}

// ── Utilities ─────────────────────────────────────────────────────────────

function antispamClientIp(): string
{
    // Prefer X-Forwarded-For behind proxies, fall back to REMOTE_ADDR
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Kernel Integration ───────────────────────────────────────────────────

/**
 * Fire a kernel event if kernelEmitEvent is available.
 * Non-fatal — event firing must never break the spam check itself.
 */
function antispamFireEvent(string $event, array $payload): void
{
    if (!function_exists('kernelEmitEvent')) {
        return;
    }
    try {
        kernelEmitEvent($event, $payload, 'anti-spam');
    } catch (\Throwable $e) {
        write_log('antispamFireEvent error: ' . $e->getMessage(), 'warning', ['event' => $event]);
    }
}

/**
 * Capability export function — auto-discovered by the module manager.
 * Exposes antispam.check@1 to the kernel capability bus.
 *
 * @return array<string, callable>
 */
function anti_spam_capability_handlers(): array
{
    return [
        'antispam.check@1' => 'anti_spam_cap_antispam_check_1',
    ];
}

/**
 * Capability handler for antispam.check@1.
 *
 * Payload: { body?: string, ip?: string }
 * Returns: { pass: bool, check: string, detail: string }
 */
function anti_spam_cap_antispam_check_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['pass' => true, 'check' => 'skipped', 'detail' => 'Invalid payload type'];
    }
    $body = isset($payload['body']) ? (string)$payload['body'] : '';
    return antispamCheck($body);
}

// ── Stats (for dashboard) ─────────────────────────────────────────────────

function antispamGetStats(): array
{
    $db = antispamDb();

    $today = date('Y-m-d');

    $blocked = (int)$db->query('SELECT COUNT(*) FROM antispam_blocked_ips WHERE is_permanent = 1 OR blocked_until > NOW()')->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM antispam_log WHERE result = ? AND created_at >= ?');
    $stmt->execute(['fail', $today . ' 00:00:00']);
    $blockedToday = (int)$stmt->fetchColumn();

    $stmt2 = $db->prepare('SELECT COUNT(*) FROM antispam_log WHERE result = ? AND created_at >= ?');
    $stmt2->execute(['pass', $today . ' 00:00:00']);
    $passedToday = (int)$stmt2->fetchColumn();

    $totalLog = (int)$db->query('SELECT COUNT(*) FROM antispam_log')->fetchColumn();

    return [
        'blocked_ips'    => $blocked,
        'blocked_today'  => $blockedToday,
        'passed_today'   => $passedToday,
        'total_log'      => $totalLog,
    ];
}
