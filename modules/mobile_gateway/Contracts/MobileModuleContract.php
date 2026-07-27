<?php

declare(strict_types=1);

namespace Ikabud\Modules\MobileGateway\Contracts;

/**
 * Contract for modules that expose mobile sync capabilities.
 *
 * Implement this interface in your module's helpers.php or a dedicated provider
 * class, then register it via the mobile gateway's provider registry.
 *
 * Usage (in module helpers.php):
 * ```
 * use Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract;
 *
 * final class MyModuleMobileProvider implements MobileModuleContract
 * {
 *     public function syncEntities(): array
 *     {
 *         return [
 *             'my_entity' => ['sync_mode' => 'read_write'],
 *         ];
 *     }
 *
 *     public function mobileCapabilities(): array
 *     {
 *         return [
 *             'mymodule.entity.view',
 *             'mymodule.entity.update',
 *         ];
 *     }
 *
 *     public function mobileManifest(): array
 *     {
 *         return [
 *             'name' => 'My Module',
 *             'start_route' => '/mobile/mymodule',
 *             'offline' => true,
 *         ];
 *     }
 * }
 * ```
 */
interface MobileModuleContract
{
    /**
     * Entity types this module supports for offline sync.
     *
     * @return array<string, array{sync_mode: 'read_write'|'read_only'|'append_only'}>
     *   Keyed by entity type identifier (e.g. 'guidance_case').
     *   Each value declares the sync mode.
     */
    public function syncEntities(): array;

    /**
     * Capabilities required for mobile access to this module's data.
     *
     * These are checked before allowing sync pull/push for the module's entities.
     *
     * @return array<int, string> List of capability IDs (e.g. ['guidance.cases.view', 'guidance.cases.update'])
     */
    public function mobileCapabilities(): array;

    /**
     * Manifest data for the mobile client.
     *
     * @return array{name: string, start_route: string, offline: bool}
     */
    public function mobileManifest(): array;
}
