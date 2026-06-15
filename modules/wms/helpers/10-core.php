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
    $baseUrl = wmsBaseUrl();
    $brandText = 'WMS Console';
    $brandMarkHtml = '<span>W</span>';

    return array_merge([
        'page_title' => 'WMS Sign In',
        'brand_mark_html' => $brandMarkHtml,
        'login_logo_html' => $brandMarkHtml,
        'login_brand_text' => $brandText,
        'login_subtitle' => 'Sign in to manage warehouse operations',
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/wms/auth/login',
        'login_button_text' => 'Access WMS',
        'login_loading_text' => 'Signing in...',
        'login_brand_html' => '<span>WMS</span> Console',
        'login_forgot_url' => $baseUrl . '/wms/forgot-password',
        'login_forgot_text' => 'Forgot password?',
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

function wmsLog(string $message, string $level = 'info'): void
{
    try {
        wmsCtx()->log($message, $level);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('wms log failed: ' . $message . ' (' . $e->getMessage() . ')', $level);
        }
    }
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
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'low_stock_threshold' => 10,
        'picking_strategy' => 'fefo',
    ];

    if (!function_exists('readTenantModuleSettings')) {
        $cached = $defaults;
        return $cached;
    }

    $settings = readTenantModuleSettings('wms');
    if (!is_array($settings)) {
        $cached = $defaults;
        return $cached;
    }

    $cached = array_merge($defaults, $settings);
    return $cached;
}


function wmsConfigGet(string $key, mixed $default = null): mixed
{
    // Per-request cache: batch-load all configs on first call to avoid
    // repeated DB queries when multiple configs are read in one request.
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = wmsDb()->query('SELECT config_key, config_value FROM wms_configs')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $k = (string)$row['config_key'];
                $val = json_decode((string)$row['config_value'], true);
                $cache[$k] = (json_last_error() === JSON_ERROR_NONE) ? $val : $default;
            }
        } catch (Throwable $e) {
            // Fall through to per-key query on cache miss
        }
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    // Cold path: key not in batch-loaded cache
    $row = wmsFetchOne('SELECT config_value FROM wms_configs WHERE config_key = ? LIMIT 1', [$key]);
    if ($row !== null && isset($row['config_value'])) {
        $val = json_decode($row['config_value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $cache[$key] = $val;
            return $val;
        }
    }
    $cache[$key] = $default;
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

function wmsLocationRecord(int $locationId): ?array
{
    if ($locationId <= 0) {
        return null;
    }

    // Per-request cache per location ID: location records are read frequently
    // (stock queries, movement validation, staging checks) within a request.
    static $cache = [];
    if (array_key_exists($locationId, $cache)) {
        return $cache[$locationId];
    }

    $record = wmsFetchOne('SELECT * FROM wms_locations WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$locationId]);
    $cache[$locationId] = $record;
    return $record;
}

function wmsLocationIsStaging(int $locationId): bool
{
    return (int)(wmsLocationRecord($locationId)['is_staging'] ?? 0) === 1;
}

function wmsMovementTypes(): array
{
    return ['in', 'out', 'transfer_out', 'transfer_in', 'adjustment', 'cycle_count_adjustment', 'reserved', 'unreserved'];
}

function wmsDeliveryStatuses(): array
{
    return ['pending', 'partial', 'staged', 'received', 'cancelled'];
}

function wmsOrderStatuses(): array
{
    return ['pending', 'picking', 'picked', 'dispatched', 'delivered', 'cancelled'];
}

function wmsCycleCountStatuses(): array
{
    return ['open', 'in_progress', 'completed', 'cancelled'];
}

function wmsCap_wmsStockPayload(mixed $payload): array
{
    return is_array($payload) ? $payload : [];
}

// ── Entity-View Capabilities ──────────────────────────────────────────

function wms_cap_entity_list_stock_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 100);
    $qualifier = (string)($payload['qualifier'] ?? '');
    $filter = '';
    if ($qualifier === 'low') { $filter = ' AND s.qty <= 10'; }
    try {
        $db = wmsDb();
        $stmt = $db->query("SELECT s.id, s.sku, s.name, s.qty, l.name as location_name, s.updated_at FROM wms_stock s LEFT JOIN wms_locations l ON l.id = s.location_id WHERE s.deleted_at IS NULL{$filter} ORDER BY s.updated_at DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query("SELECT COUNT(*) FROM wms_stock WHERE deleted_at IS NULL{$filter}");
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function wms_cap_entity_get_stock_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = wmsDb();
        $stmt = $db->prepare('SELECT s.*, l.name as location_name FROM wms_stock s LEFT JOIN wms_locations l ON l.id = s.location_id WHERE s.id = :id AND s.deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function wms_cap_entity_list_location_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 100);
    try {
        $db = wmsDb();
        $stmt = $db->query("SELECT id, name, type, is_staging, created_at FROM wms_locations WHERE deleted_at IS NULL ORDER BY name ASC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query('SELECT COUNT(*) FROM wms_locations WHERE deleted_at IS NULL');
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function wms_cap_entity_get_location_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        return wmsLocationRecord($id) ?? [];
    } catch (\Throwable $e) {
        return [];
    }
}

function wmsBridgeResolveProductId(array $item): int
{
    $productId = (int)($item['product_id'] ?? 0);
    if ($productId > 0) {
        $existing = wmsFetchOne('SELECT id FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$productId]);
        if ($existing !== null) {
            return (int)($existing['id'] ?? 0);
        }
    }

    $sku = trim((string)($item['sku'] ?? ''));
    if ($sku !== '') {
        $matched = wmsFetchOne(
            'SELECT id FROM wms_products WHERE deleted_at IS NULL AND (sku = ? OR barcode = ?) ORDER BY id ASC LIMIT 1',
            [$sku, $sku]
        );
        if ($matched !== null) {
            return (int)($matched['id'] ?? 0);
        }
    }

    return 0;
}

function wmsBridgeNormalizeStockItem(array $item, array $payload, int $index = 0): array
{
    $normalized = $item;
    $resolvedProductId = wmsBridgeResolveProductId($normalized);
    if ($resolvedProductId > 0) {
        $normalized['product_id'] = $resolvedProductId;
    }

    $itemIdempotencyKey = trim((string)($normalized['idempotency_key'] ?? ''));
    if ($itemIdempotencyKey === '') {
        $baseIdempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
        if ($baseIdempotencyKey !== '') {
            $itemIdempotencyKey = $baseIdempotencyKey . ':' . ($index + 1);
        }
    }
    if ($itemIdempotencyKey !== '') {
        $normalized['idempotency_key'] = $itemIdempotencyKey;
    }

    return $normalized;
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

    // Support batch items directly in payload
    if (!empty($payload['items']) && is_array($payload['items'])) {
        $movementIds = [];
        $app = app();
        $db = $app->db();
        $db->beginTransaction();
        try {
            foreach ($payload['items'] as $index => $it) {
                $it = is_array($it) ? wmsBridgeNormalizeStockItem($it, $payload, $index) : [];
                $warehouseId = (int)($it['warehouse_id'] ?? $payload['warehouse_id'] ?? 0);
                $locationId = (int)($it['location_id'] ?? 0);
                if ($locationId <= 0 && $warehouseId > 0) {
                    $locationId = wmsResolveBridgeLocationId($warehouseId);
                }

                $item = [
                    'product_id' => (int)($it['product_id'] ?? 0),
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'batch_id' => isset($it['batch_id']) ? (int)$it['batch_id'] : null,
                    'qty' => wmsNormalizeDecimal($it['qty'] ?? 0),
                    'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
                    'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
                    'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
                ];
                if (!empty($it['idempotency_key'])) {
                    $item['idempotency_key'] = (string)$it['idempotency_key'];
                }
                if ($item['product_id'] > 0 && $item['qty'] > 0) {
                    $movementIds[] = wmsReserveStock($item);
                }
            }
            $db->commit();
            return ['ok' => true, 'movement_ids' => $movementIds];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $locationId = (int)($payload['location_id'] ?? 0);
    if ($locationId <= 0 && $warehouseId > 0) {
        $locationId = wmsResolveBridgeLocationId($warehouseId);
    }

    // Single item fallback
    $payload = wmsBridgeNormalizeStockItem($payload, $payload, 0);
    $item = [
        'product_id' => (int)($payload['product_id'] ?? 0),
        'warehouse_id' => $warehouseId,
        'location_id' => $locationId,
        'batch_id' => isset($payload['batch_id']) ? (int)$payload['batch_id'] : null,
        'qty' => wmsNormalizeDecimal($payload['qty'] ?? 0),
        'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
        'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
    ];
    if (!empty($payload['idempotency_key'])) {
        $item['idempotency_key'] = (string)$payload['idempotency_key'];
    }

    return [
        'ok' => true,
        'movement_id' => wmsReserveStock($item),
    ];
}

function wms_cap_wms_stock_release_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $payload = wmsCap_wmsStockPayload($payload);

    if (!empty($payload['items']) && is_array($payload['items'])) {
        $movementIds = [];
        $app = app();
        $db = $app->db();
        $db->beginTransaction();
        try {
            foreach ($payload['items'] as $index => $it) {
                $it = is_array($it) ? wmsBridgeNormalizeStockItem($it, $payload, $index) : [];
                $warehouseId = (int)($it['warehouse_id'] ?? $payload['warehouse_id'] ?? 0);
                $locationId = (int)($it['location_id'] ?? 0);
                if ($locationId <= 0 && $warehouseId > 0) {
                    $locationId = wmsResolveBridgeLocationId($warehouseId);
                }

                $itemIdempotencyKey = trim((string)($it['idempotency_key'] ?? ''));
                if ($itemIdempotencyKey === '') {
                    $baseIdempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
                    if ($baseIdempotencyKey !== '') {
                        $itemIdempotencyKey = $baseIdempotencyKey . ':release:' . ((int)$index + 1);
                    }
                }

                $item = [
                    'product_id' => (int)($it['product_id'] ?? 0),
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'batch_id' => isset($it['batch_id']) ? (int)$it['batch_id'] : null,
                    'qty' => wmsNormalizeDecimal($it['qty'] ?? 0),
                    'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
                    'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
                    'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
                ];
                if ($itemIdempotencyKey !== '') {
                    $item['idempotency_key'] = $itemIdempotencyKey;
                }
                if ($item['product_id'] > 0 && $item['qty'] > 0) {
                    $movementIds[] = wmsReleaseStock($item);
                }
            }
            $db->commit();
            return ['ok' => true, 'movement_ids' => $movementIds];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $locationId = (int)($payload['location_id'] ?? 0);
    if ($locationId <= 0 && $warehouseId > 0) {
        $locationId = wmsResolveBridgeLocationId($warehouseId);
    }

    $payload = wmsBridgeNormalizeStockItem($payload, $payload, 0);

    $item = [
        'product_id' => (int)($payload['product_id'] ?? 0),
        'warehouse_id' => $warehouseId,
        'location_id' => $locationId,
        'batch_id' => isset($payload['batch_id']) ? (int)$payload['batch_id'] : null,
        'qty' => wmsNormalizeDecimal($payload['qty'] ?? 0),
        'reference_type' => (string)($payload['reference_type'] ?? 'reservation'),
        'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
    ];
    if (!empty($payload['idempotency_key'])) {
        $item['idempotency_key'] = (string)$payload['idempotency_key'];
    }

    return [
        'ok' => true,
        'movement_id' => wmsReleaseStock($item),
    ];
}

function wmsResolveBridgeLocationId(int $warehouseId): int
{
    $warehouseId = wmsRequirePositiveId($warehouseId, 'Warehouse ID');

    $configuredLocationId = (int)wmsConfigGet('bridge.default_location_id', 0);
    if ($configuredLocationId > 0) {
        $configuredLocation = wmsFetchOne(
            'SELECT id FROM wms_locations WHERE id = ? AND warehouse_id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
            [$configuredLocationId, $warehouseId]
        );
        if ($configuredLocation !== null) {
            return (int)$configuredLocation['id'];
        }
    }

    $location = wmsFetchOne(
        'SELECT id FROM wms_locations WHERE warehouse_id = ? AND is_active = 1 AND deleted_at IS NULL AND type <> ? ORDER BY sort_order ASC, id ASC LIMIT 1',
        [$warehouseId, 'staging']
    );
    if ($location !== null) {
        return (int)$location['id'];
    }

    $fallback = wmsFetchOne(
        'SELECT id FROM wms_locations WHERE warehouse_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC LIMIT 1',
        [$warehouseId]
    );
    if ($fallback !== null) {
        return (int)$fallback['id'];
    }

    throw new RuntimeException('No active WMS location available for warehouse #' . $warehouseId . '.');
}

function wmsProductEventPayload(array $product, ?int $actorUserId = null): array
{
    return [
        'id' => (int)($product['id'] ?? 0),
        'sku' => trim((string)($product['sku'] ?? '')),
        'barcode' => trim((string)($product['barcode'] ?? '')),
        'name' => trim((string)($product['name'] ?? '')),
        'description' => (string)($product['description'] ?? ''),
        'unit' => trim((string)($product['unit'] ?? '')),
        'product_type' => trim((string)($product['product_type'] ?? '')),
        'is_active' => (int)($product['is_active'] ?? 0),
        'actor_user_id' => $actorUserId,
    ];
}

function wmsEmitProductEvent(string $eventKey, array $product, ?int $actorUserId = null): void
{
    try {
        wmsCtx()->fireEvent($eventKey, wmsProductEventPayload($product, $actorUserId));
    } catch (Throwable $e) {
        wmsLog('wms product event failed: ' . $e->getMessage(), 'warning');
    }
}

function wms_cap_wms_order_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    // Payload should mirror wmsOrderCreate expected data array
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    if (!empty($payload['external_reference'])) {
        $existing = wmsBridgeOrderRecordByPayload(['external_reference' => (string)$payload['external_reference']]);
        if ($existing !== null) {
            return ['ok' => true, 'order_id' => (int)$existing['id'], 'existing' => true];
        }
    }

    if (!empty($payload['items']) && is_array($payload['items'])) {
        foreach ($payload['items'] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $payload['items'][$index] = wmsBridgeNormalizeStockItem($item, $payload, $index);
            if (!isset($item['qty_ordered']) && isset($item['qty'])) {
                $payload['items'][$index]['qty_ordered'] = $item['qty'];
            }
        }
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

function wmsBridgeOrderRecordByPayload(array $payload): ?array
{
    $wmsOrderId = (int)($payload['wms_order_id'] ?? $payload['order_id'] ?? 0);
    if ($wmsOrderId > 0) {
        $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$wmsOrderId]);
        if ($order !== null) {
            return $order;
        }
    }

    $externalReference = trim((string)($payload['external_reference'] ?? $payload['order_number'] ?? ''));
    if ($externalReference !== '') {
        $order = wmsFetchOne('SELECT * FROM wms_orders WHERE external_reference = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$externalReference]);
        if ($order !== null) {
            return $order;
        }
    }

    $ecommerceOrderId = (int)($payload['ecommerce_order_id'] ?? 0);
    if ($ecommerceOrderId > 0) {
        $order = wmsFetchOne(
            'SELECT * FROM wms_orders WHERE meta LIKE ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            ['%"ecommerce_order_id":' . $ecommerceOrderId . '%']
        );
        if ($order !== null) {
            return $order;
        }
    }

    return null;
}

function wms_cap_wms_order_cancel_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $order = wmsBridgeOrderRecordByPayload($payload);
    if ($order === null) {
        return ['ok' => true, 'missing' => true];
    }

    if ((string)($order['status'] ?? '') === 'cancelled') {
        return ['ok' => true, 'order_id' => (int)$order['id'], 'already_cancelled' => true];
    }

    try {
        wmsOrderCancel((int)$order['id'], isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null);
        return ['ok' => true, 'order_id' => (int)$order['id']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'order_id' => (int)$order['id']];
    }
}

function wms_cap_wms_return_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $referenceNumber = trim((string)($payload['reference_number'] ?? ''));
    if ($referenceNumber === '') {
        return ['ok' => false, 'error' => 'Reference number is required.'];
    }

    $existing = wmsFetchOne('SELECT id, reference_number FROM wms_returns WHERE reference_number = ? AND deleted_at IS NULL LIMIT 1', [$referenceNumber]);
    if ($existing !== null) {
        return [
            'ok' => true,
            'existing' => true,
            'return_id' => (int)($existing['id'] ?? 0),
            'reference_number' => (string)($existing['reference_number'] ?? $referenceNumber),
        ];
    }

    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        return ['ok' => false, 'error' => 'Warehouse ID is required.'];
    }

    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if ($items === []) {
        return ['ok' => false, 'error' => 'At least one return item is required.'];
    }

    $db = wmsDb();
    $db->beginTransaction();
    try {
        $db->execute(
            'INSERT INTO wms_returns (reference_number, order_id, customer_name, warehouse_id, status, reason, received_at, notes, meta, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())',
            [
                $referenceNumber,
                isset($payload['order_id']) && (int)($payload['order_id'] ?? 0) > 0 ? (int)$payload['order_id'] : null,
                trim((string)($payload['customer_name'] ?? '')) !== '' ? trim((string)$payload['customer_name']) : null,
                $warehouseId,
                'pending',
                trim((string)($payload['reason'] ?? '')) !== '' ? trim((string)$payload['reason']) : null,
                trim((string)($payload['notes'] ?? '')) !== '' ? trim((string)$payload['notes']) : null,
                ($meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : []) !== []
                    ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                isset($payload['actor_user_id']) && (int)($payload['actor_user_id'] ?? 0) > 0 ? (int)$payload['actor_user_id'] : null,
            ]
        );
        $returnId = (int)$db->lastInsertId();

        foreach ($items as $index => $item) {
            $item = is_array($item) ? wmsBridgeNormalizeStockItem($item, $payload, $index) : [];
            $productId = (int)($item['product_id'] ?? 0);
            $qtyReturned = wmsNormalizeDecimal($item['qty_returned'] ?? $item['qty'] ?? 0);
            if ($productId <= 0 || $qtyReturned <= 0) {
                throw new RuntimeException('Each return item requires a resolvable product and positive quantity.');
            }

            $locationId = isset($item['location_id']) ? (int)$item['location_id'] : 0;
            if ($locationId <= 0) {
                $locationId = (int)(wmsResolveQuarantineLocation($warehouseId)['id'] ?? 0);
            }
            if ($locationId <= 0) {
                throw new RuntimeException('No quarantine location is available for warehouse #' . $warehouseId . '.');
            }

            $condition = in_array((string)($item['condition'] ?? ''), ['good', 'damaged', 'expired', 'unknown'], true)
                ? (string)$item['condition']
                : 'unknown';

            $db->execute(
                'INSERT INTO wms_return_items (return_id, product_id, location_id, batch_id, qty_returned, qty_restocked, `condition`, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())',
                [
                    $returnId,
                    $productId,
                    $locationId,
                    isset($item['batch_id']) && (int)($item['batch_id'] ?? 0) > 0 ? (int)$item['batch_id'] : null,
                    $qtyReturned,
                    $condition,
                    trim((string)($item['notes'] ?? '')) !== '' ? trim((string)$item['notes']) : null,
                ]
            );
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    wmsAudit('wms.return.created', 'wms_returns', (string)$returnId, null, ['reference_number' => $referenceNumber]);

    return [
        'ok' => true,
        'return_id' => $returnId,
        'reference_number' => $referenceNumber,
    ];
}

/**
 * Capability: wms.product.upsert@1
 * Upserts a product from an external source (like Ecommerce) into WMS.
 *
 * Payload:
 * {
 *     "sku": "SKU-123",
 *     "title": "Product Title",
 *     "barcode": "890123"
 * }
 */
function wms_cap_wms_product_upsert_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Payload must be an object'];
    }

    $sku = trim((string)($payload['sku'] ?? ''));
    if ($sku === '') {
        return ['ok' => false, 'error' => 'Product SKU is required for WMS sync'];
    }

    $name = trim((string)($payload['name'] ?? $payload['title'] ?? $sku));
    $barcode = trim((string)($payload['barcode'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $unit = trim((string)($payload['unit'] ?? 'pcs'));
    $productType = trim((string)($payload['product_type'] ?? $payload['type'] ?? 'physical'));
    $isActiveRaw = $payload['is_active'] ?? true;
    if (is_string($isActiveRaw)) {
        $normalizedActive = strtolower(trim($isActiveRaw));
        $isActive = in_array($normalizedActive, ['1', 'true', 'yes', 'published', 'active'], true) ? 1 : 0;
    } else {
        $isActive = (int)!empty($isActiveRaw);
    }
    $actorUserId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null;

    try {
        $db = wmsDb();
        
        $stmt = $db->prepare('SELECT id FROM wms_products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $updateStmt = $db->prepare('UPDATE wms_products SET name = ?, barcode = ?, description = ?, unit = ?, product_type = ?, is_active = ?, deleted_at = NULL, updated_at = NOW() WHERE id = ?');
            $updateStmt->execute([$name, $barcode !== '' ? $barcode : null, $description !== '' ? $description : null, $unit, $productType, $isActive, $existingId]);
            $productId = (int)$existingId;
            $action = 'updated';
            $eventKey = 'wms.product.updated';
        } else {
            $insertStmt = $db->prepare('INSERT INTO wms_products (sku, barcode, name, description, unit, product_type, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $insertStmt->execute([$sku, $barcode !== '' ? $barcode : null, $name, $description !== '' ? $description : null, $unit, $productType, $isActive]);
            $productId = (int)$db->lastInsertId();
            $action = 'created';
            $eventKey = 'wms.product.created';
        }

        $product = wmsFetchOne('SELECT * FROM wms_products WHERE id = ? LIMIT 1', [$productId]);
        if ($product !== null) {
            wmsEmitProductEvent($eventKey, $product, $actorUserId);
        }

        return ['ok' => true, 'product_id' => $productId, 'sku' => $sku, 'action' => $action];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
