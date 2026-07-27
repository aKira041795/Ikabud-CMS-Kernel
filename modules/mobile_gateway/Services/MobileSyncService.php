<?php

declare(strict_types=1);

/**
 * Mobile sync orchestration service.
 *
 * Thin layer over kernel SyncEngine that adds module capability gating,
 * entity type resolution via MobileModuleContract, and tenant scoping.
 *
 * Delegates actual revision tracking to SyncEngine — no sync logic here.
 */

use Ikabud\Kernel\Services\SyncEngine;

class MobileSyncService
{
    /**
     * Discover all modules that implement MobileModuleContract and return
     * their combined entity type registry.
     *
     * @return array<string, array{sync_mode: 'read_write'|'read_only'|'append_only', module: string}>
     *   Keyed by entity type identifier.
     */
    public function resolveAvailableEntities(): array
    {
        $entities = [];
        $providers = mobile_gateway_get_providers();

        foreach ($providers as $moduleId => $provider) {
            foreach ($provider->syncEntities() as $entityType => $config) {
                $entities[$entityType] = [
                    'sync_mode' => $config['sync_mode'] ?? 'read_write',
                    'module' => $moduleId,
                ];
            }
        }

        return $entities;
    }

    /**
     * Check whether the current user has mobile access to a given entity type.
     *
     * @param string $entityType Entity type to check
     * @param array  $user       Authenticated user
     * @return bool
     */
    public function validateSyncAccess(string $entityType, array $user): bool
    {
        $provider = $this->providerForEntity($entityType);
        if ($provider === null) {
            return false;
        }

        $heldCapabilities = is_array($user['capabilities'] ?? null)
            ? array_values(array_filter($user['capabilities'], 'is_string'))
            : [];

        foreach ($provider->mobileCapabilities() as $capId) {
            if (
                is_string($capId)
                && $capId !== ''
                && app()->capabilities()->has($capId)
                && in_array($capId, $heldCapabilities, true)
            ) {
                return true;
            }
        }

        return false;
    }

    public function validatePushAccess(string $entityType, array $user): bool
    {
        $provider = $this->providerForEntity($entityType);
        if ($provider === null || !$this->validateSyncAccess($entityType, $user)) {
            return false;
        }

        $config = $provider->syncEntities()[$entityType] ?? [];
        return ($config['sync_mode'] ?? 'read_write') !== 'read_only';
    }

    /**
     * Pull changes for an entity type via SyncEngine.
     *
     * @param string   $entityType Entity type to pull
     * @param string   $deviceId   Device identifier
     * @param int|null $tenantId   Tenant ID
     * @param int|null $userId     User ID
     * @param int      $limit      Max items (default 100, max 500)
     * @return array
     */
    public function pullChanges(
        string $entityType,
        string $deviceId,
        ?int $tenantId = null,
        ?int $userId = null,
        int $limit = 100
    ): array {
        if ($tenantId === null || $tenantId <= 0 || $userId === null || $userId <= 0) {
            throw new \InvalidArgumentException('Tenant and user context are required for mobile sync');
        }

        throw new \RuntimeException(
            'SyncEngine revisions and tombstones are not tenant-scoped; refusing an unsafe pull'
        );
    }

    /**
     * Push operations via SyncEngine.
     *
     * @param array  $operations Batch of push operations
     * @param string $context    Context for error messages
     * @return array{results: array, conflicts: array}
     */
    public function pushOperations(array $operations, string $context, array $user): array
    {
        $tenantId = (int)($user['tenant_id'] ?? 0);
        $userId = (int)($user['id'] ?? $user['sub'] ?? 0);
        if ($tenantId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Tenant and user context are required for mobile sync');
        }

        $accepted = [];
        $rejected = [];
        foreach ($operations as $index => $operation) {
            if (!is_array($operation)) {
                $rejected[] = [
                    'client_id' => 'op-' . $index,
                    'status' => 'rejected',
                    'reason' => 'Operation must be an object',
                ];
                continue;
            }

            $entityType = trim((string)($operation['entity'] ?? ''));
            if ($entityType === '' || !$this->validatePushAccess($entityType, $user)) {
                $rejected[] = [
                    'client_id' => (string)($operation['client_id'] ?? 'op-' . $index),
                    'status' => 'rejected',
                    'reason' => 'Access denied to entity type: ' . $entityType,
                ];
                continue;
            }

            $accepted[] = $operation;
        }

        $result = $accepted === []
            ? ['results' => [], 'conflicts' => []]
            : SyncEngine::push($accepted, $context);
        foreach ($result['results'] ?? [] as &$operationResult) {
            if (($operationResult['status'] ?? '') === 'accepted') {
                $operationResult['status'] = 'rejected';
                $operationResult['reason'] = 'Module operation processing is not implemented';
                unset($operationResult['message']);
            }
        }
        unset($operationResult);
        $result['results'] = array_merge($result['results'] ?? [], $rejected);
        $result['conflicts'] = $result['conflicts'] ?? [];
        return $result;
    }

    /**
     * Build the aggregated mobile manifest from all providers.
     *
     * @param array|null $user Authenticated user (null if not available)
     * @return array{modules: array, entities: array, server_time: string}
     */
    public function buildBootstrapManifest(?array $user = null): array
    {
        $providers = mobile_gateway_get_providers();
        $modules = [];
        $entities = [];

        foreach ($providers as $moduleId => $provider) {
            if ($user !== null && !$this->userCanAccessProvider($provider, $user)) {
                continue;
            }

            $manifest = $provider->mobileManifest();
            $modules[] = [
                'id' => $moduleId,
                'name' => $manifest['name'],
                'start_route' => $manifest['start_route'],
                'offline' => $manifest['offline'],
            ];

            foreach ($provider->syncEntities() as $entityType => $config) {
                $entities[$entityType] = [
                    'sync_mode' => $config['sync_mode'],
                    'module' => $moduleId,
                ];
            }
        }

        return [
            'modules' => $modules,
            'entities' => $entities,
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function providerForEntity(string $entityType): ?\Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract
    {
        foreach (mobile_gateway_get_providers() as $provider) {
            if (isset($provider->syncEntities()[$entityType])) {
                return $provider;
            }
        }

        return null;
    }

    private function userCanAccessProvider(
        \Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract $provider,
        array $user
    ): bool {
        $heldCapabilities = is_array($user['capabilities'] ?? null)
            ? array_values(array_filter($user['capabilities'], 'is_string'))
            : [];

        foreach ($provider->mobileCapabilities() as $capId) {
            if (
                is_string($capId)
                && app()->capabilities()->has($capId)
                && in_array($capId, $heldCapabilities, true)
            ) {
                return true;
            }
        }

        return false;
    }
}
