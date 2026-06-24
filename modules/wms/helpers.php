<?php

declare(strict_types=1);

app()->registerAuthTable('wms', 'wms_users');

/**
 * Capability handler map for the WMS module.
 */
function wms_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'wms_cap_kernel_auth_authenticate_1',
        'wms.stock.query@1'     => 'wms_cap_stock_query_1',
        'wms.stock.reserve@1'   => 'wms_cap_stock_reserve_1',
        'wms.stock.release@1'   => 'wms_cap_stock_release_1',
        'wms.order.create@1'    => 'wms_cap_order_create_1',
        'wms.order.cancel@1'    => 'wms_cap_order_cancel_1',
    ];
}

// ── Auth capability handler ──

function wms_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) return null;

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') return null;

    $prefix = '@wms:';
    if (!str_starts_with($username, $prefix)) return null;
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') return null;

    try {
        $stmt = wmsDb()->prepare(
            "SELECT id, username, email, password_hash, full_name, role, is_active\n"
            . "FROM wms_users\n"
            . "WHERE (username = :username OR email = :email) AND is_active = 1\n"
            . "LIMIT 1"
        );
        $stmt->execute([':username' => $username, ':email' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
            return null;
        }

        return [
            'user' => [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'viewer'),
                'sub' => 'wms:' . (int)($row['id'] ?? 0),
            ],
            'source' => 'wms',
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Stock capability stubs (Phase 2+) ──

function wms_cap_stock_query_1(mixed $payload): ?array { return null; }
function wms_cap_stock_reserve_1(mixed $payload): ?array { return null; }
function wms_cap_stock_release_1(mixed $payload): ?array { return null; }
function wms_cap_order_create_1(mixed $payload): ?array { return null; }
function wms_cap_order_cancel_1(mixed $payload): ?array { return null; }

// ── Core helpers ──

function wmsBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function wmsExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function wmsCookieName(): string
{
    return 'wms_token';
}

function wmsSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(wmsCookieName(), $token, [
        'expires'  => $expiry,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsClearAuthCookie(): void
{
    setcookie(wmsCookieName(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('wms');
    if ($ctx === null) {
        throw new \RuntimeException('WMS module context not available.');
    }
    return $ctx;
}

function wmsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return wmsCtx()->db();
}

function wmsInput(): mixed
{
    return wmsCtx()->input();
}

function wmsRender(string $template, array $context = []): string
{
    return app()->render($template, $context);
}

function wmsJsonOk(array $extra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function wmsJsonError(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function wmsUser(): ?array
{
    return app()->user();
}

function wmsSettings(): array
{
    static $settings = null;
    if ($settings !== null) return $settings;

    try {
        $stmt = wmsDb()->prepare('SELECT config_key, config_value FROM wms_configs');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['config_key']] = $r['config_value'];
        }
    } catch (\Throwable $e) {
        $settings = [];
    }
    return $settings;
}

function wmsConfigGet(string $key, mixed $default = null): mixed
{
    $s = wmsSettings();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}

function wmsConfigSet(string $key, string $value): void
{
    $stmt = wmsDb()->prepare(
        'INSERT INTO wms_configs (config_key, config_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE config_value = :v2'
    );
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    // Reset cache
    $GLOBALS['__wms_settings_cache'] = null;
}

function wmsLoginPageContext(array $overrides = []): array
{
    $baseUrl = wmsBaseUrl();
    return array_merge([
        'page_title'                => 'WMS Sign In',
        'brand_mark_html'           => '<span>W</span>',
        'login_logo_html'           => '<span>W</span>',
        'login_brand_text'          => 'WMS Console',
        'login_subtitle'            => 'Sign in to manage warehouse operations',
        'login_username_label'      => 'Username or Email',
        'login_endpoint'            => $baseUrl . '/wms/auth/login',
        'login_button_text'         => 'Access WMS',
        'login_loading_text'        => 'Signing in...',
        'login_brand_html'          => '<span>WMS</span> Console',
        'login_forgot_url'          => $baseUrl . '/wms/forgot-password',
        'login_forgot_text'         => 'Forgot password?',
        'gui' => [
            'app_name'        => 'WMS Console',
            'app_name_accent' => 'WMS',
            'app_name_rest'   => 'Console',
            'font_url'        => '',
            'font_family'     => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary'       => '#2563eb',
            'color_primary_hover' => '#1d4ed8',
            'color_primary_light' => 'rgba(37, 99, 235, 0.18)',
            'color_bg'            => 'linear-gradient(135deg, #0f172a 0%, #0b1120 45%, #1e3a8a 100%)',
            'color_surface'       => '#ffffff',
            'color_border'        => '#e5e7eb',
            'color_text'          => '#111827',
            'color_text_muted'    => '#6b7280',
        ],
    ], $overrides);
}

function wmsPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function wmsRenderTemplate(string $pageContent, array $extra = []): void
{
    $user = wmsUser();
    $settings = wmsSettings();
    $baseUrl = wmsBaseUrl();

    $context = array_merge([
        'current_user' => $user,
        'settings' => $settings,
        'base_url' => $baseUrl,
        'page_content' => $pageContent,
        'menu_items' => wmsNavItems($user['role'] ?? ''),
        'page_title' => $extra['page_title'] ?? 'WMS',
    ], $extra);

    // Render the page template as a string, pass as page_body
    $pageTemplate = __DIR__ . '/templates/pages/' . $pageContent . '.disyl';
    if (file_exists($pageTemplate)) {
        $context['page_body'] = app()->render($pageTemplate, $context);
    } else {
        $context['page_body'] = '<div class="p-8 text-center text-gray-400 text-sm">Page not found: ' . htmlspecialchars($pageContent, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    echo app()->render('modules/wms/layouts/admin.disyl', $context);
}

// ── Home URL hook for WMS ──
try {
    app()->hooks()->on('kernel.home_url', function ($url, $role, $user) {
        if (is_array($user) && ($user['source'] ?? '') === 'wms') {
            return '/wms';
        }
        return $url;
    });
} catch (\Throwable $e) {
    // Hook registration not available yet — will be picked up on next request
}
