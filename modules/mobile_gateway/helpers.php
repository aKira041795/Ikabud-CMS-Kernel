<?php

declare(strict_types=1);

require_once __DIR__ . '/Contracts/MobileModuleContract.php';
require_once __DIR__ . '/Services/DeviceRegistrationService.php';
require_once __DIR__ . '/Services/MobileSyncService.php';

/**
 * Register the mobile gateway's capability handlers.
 *
 * Each capability delegates to its respective handler function below.
 */
function mobile_gateway_capability_handlers(): array
{
    return [
        'mobile.bootstrap@1' => 'mobile_gateway_cap_bootstrap_1',
        'mobile.sync.pull@1' => 'mobile_gateway_cap_sync_pull_1',
        'mobile.sync.push@1' => 'mobile_gateway_cap_sync_push_1',
        'mobile.device.register@1' => 'mobile_gateway_cap_device_register_1',
        'mobile.device.unregister@1' => 'mobile_gateway_cap_device_unregister_1',
    ];
}

/**
 * Capability handler: mobile.bootstrap@1
 *
 * Returns the bootstrap manifest for mobile clients.
 */
function mobile_gateway_cap_bootstrap_1(array $context): array
{
    $syncService = new MobileSyncService();
    $manifest = $syncService->buildBootstrapManifest($context['user'] ?? null);

    return [
        'ok' => true,
        'manifest' => $manifest,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        'api_version' => '1',
    ];
}

/**
 * Capability handler: mobile.sync.pull@1
 *
 * Returns cursor-based changes for a given entity type.
 */
function mobile_gateway_cap_sync_pull_1(array $context): array
{
    $entityType = (string)($context['entity_type'] ?? '');
    $deviceId = (string)($context['device_id'] ?? '');
    $limit = max(1, min((int)($context['limit'] ?? 100), 500));
    $user = $context['user'] ?? [];
    $tenantId = isset($user['tenant_id']) ? (int)$user['tenant_id'] : null;
    $userId = (int)($user['id'] ?? 0);

    $syncService = new MobileSyncService();
    if (!$syncService->validateSyncAccess($entityType, $user)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    try {
        return $syncService->pullChanges($entityType, $deviceId, $tenantId, $userId, $limit);
    } catch (\RuntimeException $e) {
        return ['ok' => false, 'error' => 'tenant_sync_unavailable'];
    }
}

/**
 * Capability handler: mobile.sync.push@1
 *
 * Processes a batch of push operations.
 */
function mobile_gateway_cap_sync_push_1(array $context): array
{
    $operations = $context['operations'] ?? [];
    $user = is_array($context['user'] ?? null) ? $context['user'] : [];
    $syncService = new MobileSyncService();
    return $syncService->pushOperations($operations, 'mobile-gateway', $user);
}

/**
 * Capability handler: mobile.device.register@1
 *
 * Registers a mobile device.
 */
function mobile_gateway_cap_device_register_1(array $context): array
{
    $user = $context['user'] ?? [];
    $userId = (int)($user['id'] ?? 0);
    $tenantId = (int)($user['tenant_id'] ?? 0);

    $service = new MobileDeviceRegistrationService();
    return $service->register(
        $userId,
        $tenantId,
        (string)($context['device_id'] ?? ''),
        (string)($context['platform'] ?? 'android'),
        $context['push_token'] ?? null,
        $context['device_name'] ?? null,
        $context['ip'] ?? null,
        $context['user_agent'] ?? null,
    );
}

/**
 * Capability handler: mobile.device.unregister@1
 *
 * Unregisters a mobile device.
 */
function mobile_gateway_cap_device_unregister_1(array $context): array
{
    $user = $context['user'] ?? [];
    $userId = (int)($user['id'] ?? 0);
    $tenantId = (int)($user['tenant_id'] ?? 0);

    $service = new MobileDeviceRegistrationService();
    return $service->unregister(
        $userId,
        $tenantId,
        (string)($context['device_id'] ?? '')
    );
}

// ─── Provider Registry ────────────────────────────────────────────────────

/**
 * Registry of MobileModuleContract providers.
 * Modules register themselves during their helpers.php load.
 *
 * @var array<string, Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract>
 */
$GLOBALS['_mobile_gateway_providers'] = [];

/**
 * Register a module as a mobile provider.
 *
 * Call this from your module's helpers.php:
 * ```
 * mobile_gateway_register_provider('my-module', new MyModuleMobileProvider());
 * ```
 */
function mobile_gateway_register_provider(string $moduleId, \Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract $provider): void
{
    $GLOBALS['_mobile_gateway_providers'][$moduleId] = $provider;
}

/**
 * Get all registered mobile providers.
 *
 * @return array<string, Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract>
 */
function mobile_gateway_get_providers(): array
{
    return $GLOBALS['_mobile_gateway_providers'] ?? [];
}

// ─── DB Helper ────────────────────────────────────────────────────────────

/**
 * Get the mobile gateway module's scoped database context.
 */
function mobile_gateway_db(): \Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('mobile-gateway');
    if (!$ctx) {
        throw new \RuntimeException('Mobile gateway module context unavailable');
    }
    return $ctx->db();
}
