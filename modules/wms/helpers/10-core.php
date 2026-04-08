<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleContext;
use Ikabud\Kernel\Contracts\ModuleDB;

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (($user['source'] ?? null) !== 'wms') {
        return $url;
    }

    if (in_array($role, ['admin', 'supervisor', 'viewer'], true)) {
        return '/wms';
    }

    return $url;
}, 80);

function wmsDb(): ModuleDB
{
    $ctx = module('wms');
    if (!$ctx) {
        throw new RuntimeException('WMS module context unavailable');
    }

    return $ctx->db();
}

function wmsCtx(): ModuleContext
{
    $ctx = module('wms');
    if (!$ctx) {
        throw new RuntimeException('WMS module context unavailable');
    }

    return $ctx;
}

function wmsUser(): ?array
{
    return wmsCtx()->user();
}

function wmsInput(?string $key = null, mixed $default = null): mixed
{
    return wmsCtx()->input($key, $default);
}

function wmsRender(string $template, array $context = []): string
{
    $resolvedTemplate = str_starts_with($template, 'modules/wms/')
        ? $template
        : 'modules/wms/' . ltrim($template, '/');

    if (function_exists('kernelPrepareRenderContext')) {
        $context = kernelPrepareRenderContext($resolvedTemplate, $context);
    }

    return wmsCtx()->render($resolvedTemplate, $context);
}

function wmsCookieName(): string
{
    return 'wms_token';
}

function wmsSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(wmsCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsClearAuthCookie(): void
{
    setcookie(wmsCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsLoginPageContext(array $overrides = []): array
{
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    return array_merge([
        'page_title' => 'WMS Sign In',
        'login_subtitle' => 'Sign in to manage warehouse operations',
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/wms/auth/login',
        'login_button_text' => 'Access WMS',
        'login_loading_text' => 'Signing in...',
        'login_brand_html' => '<span>WMS</span> Console',
        'gui' => [
            'app_name' => 'WMS Console',
            'app_name_accent' => 'WMS',
            'app_name_rest' => 'Console',
            'font_url' => '',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#2563eb',
            'color_primary_hover' => '#1d4ed8',
            'color_primary_light' => 'rgba(37, 99, 235, 0.18)',
            'color_bg' => 'linear-gradient(135deg, #0f172a 0%, #0b1120 45%, #1e3a8a 100%)',
            'color_surface' => 'rgba(255, 255, 255, 0.96)',
            'color_border' => '#dbeafe',
            'color_text' => '#0f172a',
            'color_text_muted' => '#475569',
            'css_overrides' => '.login-card{max-width:420px;border:1px solid rgba(191,219,254,.7);box-shadow:0 24px 80px rgba(15,23,42,.28)}.login-logo{margin-bottom:28px}.login-logo h1{font-size:30px;letter-spacing:-.03em}.login-logo p{font-size:14px}.btn-login{box-shadow:0 12px 30px rgba(37,99,235,.25)}',
        ],
    ], $overrides);
}

function wms_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    $prefix = '@wms:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }

    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    try {
        $stmt = wmsDb()->prepare(
            "SELECT id, username, email, password_hash, full_name, role, is_active\n"
            . "FROM wms_users\n"
            . "WHERE (username = :username OR email = :email) AND is_active = 1\n"
            . "LIMIT 1"
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $username,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

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
    } catch (Throwable $e) {
        return null;
    }
}

function wmsRequireAnyRole(string ...$roles): array
{
    return wmsCtx()->requireAnyRole(...$roles);
}

function wmsJsonOk(array $data = [], int $status = 200): never
{
    wmsCtx()->json(array_merge(['ok' => true], $data), $status);
    exit;
}

function wmsJsonError(string $message, int $status = 400, array $extra = []): never
{
    wmsCtx()->json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    exit;
}

function wmsAudit(string $action, ?string $entityType = null, ?string $entityId = null, mixed $oldData = null, mixed $newData = null): void
{
    try {
        wmsCtx()->audit($action, null, $entityType, $entityId, $oldData, $newData);
    } catch (Throwable $e) {
        wmsCtx()->log('wms audit failed: ' . $e->getMessage(), 'error');
    }
}

function wmsAdminContext(array $user, string $currentPage, array $extra = []): array
{
    $settings = wmsSettings();
    $lowStockThreshold = (int)($settings['low_stock_threshold'] ?? 10);
    $pickingStrategy = (string)($settings['picking_strategy'] ?? 'fefo');

    return array_merge([
        'user' => $user,
        'current_page' => $currentPage,
        'page_title' => 'Warehouse Management',
        'base_url' => rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/'),
        'csrf_token' => app()->csrfToken(),
        'csrf_field' => app()->csrfField(),
        'wms_settings' => [
            'low_stock_threshold' => $lowStockThreshold,
            'picking_strategy' => $pickingStrategy,
        ],
    ], $extra);
}

function wmsSettings(): array
{
    $defaults = [
        'low_stock_threshold' => 10,
        'picking_strategy' => 'fefo',
    ];

    if (!function_exists('readTenantModuleSettings')) {
        return $defaults;
    }

    $settings = readTenantModuleSettings('wms');
    if (!is_array($settings)) {
        return $defaults;
    }

    return array_merge($defaults, $settings);
}


function wmsConfigGet(string $key, mixed $default = null): mixed
{
    $row = wmsFetchOne('SELECT config_value FROM wms_configs WHERE config_key = ? LIMIT 1', [$key]);
    if ($row !== null && isset($row['config_value'])) {
        $val = json_decode($row['config_value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $val;
        }
    }
    return $default;
}

function wmsConfigSet(string $key, mixed $value, ?string $description = null): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    $db = wmsDb();
    if ($description !== null) {
        $db->execute(
            'INSERT INTO wms_configs (config_key, config_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), description = VALUES(description)',
            [$key, $json, $description]
        );
    } else {
        $db->execute(
            'INSERT INTO wms_configs (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
            [$key, $json]
        );
    }
}

function wmsNormalizeDecimal(mixed $value, int $precision = 4): float
{
    return round((float)$value, $precision);
}

function wmsUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function wmsTableId(array $row): int
{
    return (int)($row['id'] ?? 0);
}

function wmsFetchAll(string $sql, array $params = []): array
{
    return wmsDb()->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wmsFetchOne(string $sql, array $params = []): ?array
{
    $row = wmsDb()->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function wmsRequirePositiveId(int $id, string $label = 'ID'): int
{
    if ($id <= 0) {
        throw new RuntimeException($label . ' is required.');
    }

    return $id;
}

function wmsSanitizeString(mixed $value, int $maxLength = 255): string
{
    return mb_substr(trim((string)$value), 0, $maxLength);
}

function wmsJsonDecodeArray(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function wmsSqlLike(string $value): string
{
    return '%' . $value . '%';
}

function wmsMovementTypes(): array
{
    return ['in', 'out', 'transfer_out', 'transfer_in', 'adjustment', 'cycle_count_adjustment', 'reserved', 'unreserved'];
}

function wmsDeliveryStatuses(): array
{
    return ['pending', 'partial', 'received', 'cancelled'];
}

function wmsOrderStatuses(): array
{
    return ['pending', 'picking', 'picked', 'dispatched', 'cancelled'];
}

function wmsCycleCountStatuses(): array
{
    return ['open', 'in_progress', 'completed', 'cancelled'];
}

function wmsCap_wmsStockPayload(mixed $payload): array
{
    return is_array($payload) ? $payload : [];
}

function wms_cap_wms_stock_query_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $payload = wmsCap_wmsStockPayload($payload);
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

    return [
        'ok' => true,
        'data' => wmsStockSnapshot($warehouseId, $filters),
    ];
}

function wms_cap_wms_stock_reserve_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $payload = wmsCap_wmsStockPayload($payload);
    $item = [
        'product_id' => (int)($payload['product_id'] ?? 0),
        'warehouse_id' => (int)($payload['warehouse_id'] ?? 0),
        'location_id' => (int)($payload['location_id'] ?? 0),
        'batch_id' => isset($payload['batch_id']) ? (int)$payload['batch_id'] : null,
        'qty' => wmsNormalizeDecimal($payload['qty'] ?? 0),
        'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
        'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
    ];

    return [
        'ok' => true,
        'movement_id' => wmsReserveStock($item),
    ];
}

function wms_cap_wms_stock_release_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $payload = wmsCap_wmsStockPayload($payload);
    $item = [
        'product_id' => (int)($payload['product_id'] ?? 0),
        'warehouse_id' => (int)($payload['warehouse_id'] ?? 0),
        'location_id' => (int)($payload['location_id'] ?? 0),
        'batch_id' => isset($payload['batch_id']) ? (int)$payload['batch_id'] : null,
        'qty' => wmsNormalizeDecimal($payload['qty'] ?? 0),
        'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
        'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
    ];

    return [
        'ok' => true,
        'movement_id' => wmsReleaseStock($item),
    ];
}

function wms_cap_wms_order_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    // Payload should mirror wmsOrderCreate expected data array
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }
    
    // Defer to the operation helper in 30-operations.php
    try {
        if (!function_exists('wmsOrderCreate')) {
            // In case 30-operations.php hasn't been loaded yet via standard flow
            require_once __DIR__ . '/30-operations.php'; 
        }
        $orderId = wmsOrderCreate($payload);
        return ['ok' => true, 'order_id' => $orderId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
