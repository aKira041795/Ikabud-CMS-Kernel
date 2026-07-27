<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Services/DeviceRegistrationService.php';
require_once __DIR__ . '/Services/MobileSyncService.php';

use Ikabud\Kernel\Http\ApiResponse;
use Ikabud\Kernel\Http\Idempotency;
use Ikabud\Kernel\Http\Validator;

// ─── Bootstrap ────────────────────────────────────────────────────────────

/**
 * POST/GET /api/v1/mobile/bootstrap
 *
 * Returns aggregated mobile manifest from all modules implementing
 * MobileModuleContract, user profile, and server info.
 *
 * Required auth: JWT Bearer token
 */
function handleMobileBootstrap(array $params = []): void
{
    $user = requireMobileAuth();

    $syncService = new MobileSyncService();
    $manifest = $syncService->buildBootstrapManifest($user);

    $userProfile = [
        'id' => (int)($user['id'] ?? 0),
        'name' => (string)($user['name'] ?? $user['username'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'tenant_id' => $user['tenant_id'] ?? null,
    ];

    $tenantId = (int)$user['tenant_id'];

    ApiResponse::success([
        'manifest' => $manifest,
        'user' => $userProfile,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        'api_version' => '1',
    ]);
}

// ─── Sync Pull ────────────────────────────────────────────────────────────

/**
 * GET/POST /api/v1/mobile/sync/pull
 *
 * Cursor-based incremental pull for a specific entity type.
 * Delegates to SyncEngine::changes().
 *
 * Query params: entity_type (required), device_id (required), limit (optional, default 100)
 *
 * Required auth: JWT Bearer token
 */
function handleMobileSyncPull(array $params = []): void
{
    $user = requireMobileAuth();
    $input = app()->input();

    $entityType = trim((string)($input['entity_type'] ?? ''));
    $deviceId = trim((string)($input['device_id'] ?? ''));
    $limit = max(1, min((int)($input['limit'] ?? 100), 500));

    if ($entityType === '') {
        ApiResponse::error('validation_failed', 'entity_type is required', 422);
    }
    if ($deviceId === '') {
        ApiResponse::error('validation_failed', 'device_id is required', 422);
    }

    $tenantId = (int)$user['tenant_id'];
    $userId = (int)$user['id'];

    $syncService = new MobileSyncService();

    // Validate access
    if (!$syncService->validateSyncAccess($entityType, $user)) {
        ApiResponse::error('forbidden', 'Access denied to entity type: ' . $entityType, 403);
    }

    try {
        $result = $syncService->pullChanges($entityType, $deviceId, $tenantId, $userId, $limit);
    } catch (\RuntimeException) {
        ApiResponse::error(
            'tenant_sync_unavailable',
            'Tenant-safe synchronization is not available',
            503
        );
    }
    ApiResponse::success($result);
}

// ─── Sync Push ────────────────────────────────────────────────────────────

/**
 * POST /api/v1/mobile/sync/push
 *
 * Idempotent batch push of operations from mobile client.
 * Delegates to SyncEngine::push().
 *
 * Body: { operations: [...], idempotency_key: "..." }
 *
 * Required auth: JWT Bearer token
 */
function handleMobileSyncPush(array $params = []): void
{
    $user = requireMobileAuth();
    $input = app()->input();

    $operations = $input['operations'] ?? [];
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));

    if (!is_array($operations) || empty($operations)) {
        ApiResponse::error('validation_failed', 'operations array is required and must be non-empty', 422);
    }
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        ApiResponse::error('validation_failed', 'A valid idempotency key is required', 422);
    }

    $tenantId = (int)$user['tenant_id'];
    $cached = Idempotency::check($idempotencyKey, $tenantId);
    if ($cached !== null) {
        ApiResponse::success($cached, 200, ['idempotent' => true]);
    }

    try {
        $syncService = new MobileSyncService();
        $result = $syncService->pushOperations($operations, 'mobile-gateway', $user);
        Idempotency::store($idempotencyKey, $tenantId, $result);
    } catch (\Throwable $e) {
        Idempotency::release($idempotencyKey, $tenantId);
        throw $e;
    }

    ApiResponse::success($result);
}

// ─── Device Registration ──────────────────────────────────────────────────

/**
 * POST /api/v1/mobile/device
 *
 * Register or update a mobile device.
 *
 * Body: { device_id, platform, push_token?, device_name? }
 *
 * Required auth: JWT Bearer token
 */
function handleMobileRegisterDevice(array $params = []): void
{
    $user = requireMobileAuth();
    $input = app()->input();

    $v = new Validator($input, [
        'device_id' => 'required|string|min:1|max:64',
        'platform' => 'required|in:android,ios,web',
        'push_token' => 'nullable|string|max:512',
        'device_name' => 'nullable|string|max:255',
    ]);

    $clean = $v->validated();

    $userId = (int)$user['id'];
    $tenantId = (int)$user['tenant_id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $service = new MobileDeviceRegistrationService();
    $result = $service->register(
        $userId,
        $tenantId,
        $clean['device_id'],
        $clean['platform'],
        $clean['push_token'] ?? null,
        $clean['device_name'] ?? null,
        $ip,
        $userAgent,
    );

    ApiResponse::success($result, 201);
}

/**
 * DELETE /api/v1/mobile/device
 *
 * Unregister/revoke a mobile device.
 *
 * Query params: device_id (required)
 *
 * Required auth: JWT Bearer token
 */
function handleMobileUnregisterDevice(array $params = []): void
{
    $user = requireMobileAuth();
    $input = app()->input();

    $deviceId = trim((string)($input['device_id'] ?? ''));
    if ($deviceId === '') {
        ApiResponse::error('validation_failed', 'device_id is required', 422);
    }

    $userId = (int)$user['id'];
    $tenantId = (int)$user['tenant_id'];

    $service = new MobileDeviceRegistrationService();
    $result = $service->unregister($userId, $tenantId, $deviceId);
    if (($result['status'] ?? '') === 'not_found') {
        ApiResponse::error('not_found', 'Device registration not found', 404);
    }

    ApiResponse::success($result);
}

/**
 * GET /api/v1/mobile/devices
 *
 * List all registered devices for the current user.
 *
 * Required auth: JWT Bearer token
 */
function handleMobileListDevices(array $params = []): void
{
    $user = requireMobileAuth();

    $userId = (int)$user['id'];
    $tenantId = (int)$user['tenant_id'];

    $service = new MobileDeviceRegistrationService();
    $devices = $service->getDevicesForUser($userId, $tenantId);

    // Strip sensitive fields before returning
    $safe = array_map(static function (array $device): array {
        unset($device['push_token']);
        return $device;
    }, $devices);

    ApiResponse::success($safe);
}

// ─── Auth Helper ──────────────────────────────────────────────────────────

/**
 * Require JWT Bearer authentication for mobile API routes.
 *
 * Returns user array on success.
 * Emits 401 JSON and exits on failure.
 *
 * @return array
 */
function requireMobileAuth(): array
{
    $user = mobileGatewayBearerUserFromRequest();
    if ($user === null) {
        ApiResponse::error('unauthorized', 'A valid Bearer token is required', 401);
    }

    return $user;
}

function mobileGatewayBearerUserFromRequest(): ?array
{
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                $header = trim($value);
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        return null;
    }

    try {
        $user = app()->jwt()->verify($matches[1]);
    } catch (\Throwable) {
        return null;
    }

    if (!is_array($user)) {
        return null;
    }

    $userId = (int)($user['id'] ?? $user['sub'] ?? 0);
    $tenantId = (int)($user['tenant_id'] ?? 0);
    $source = trim((string)($user['source'] ?? ''));
    if ($userId <= 0 || $tenantId <= 0 || $source === '') {
        return null;
    }

    $currentTenantId = app()->tenant()->current();
    if ($currentTenantId !== null && $currentTenantId !== $tenantId) {
        return null;
    }

    $user['id'] = $userId;
    $user['tenant_id'] = $tenantId;
    return $user;
}
