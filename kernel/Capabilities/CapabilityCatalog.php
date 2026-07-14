<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

final class CapabilityCatalog
{
    use CapabilityIdTrait;

    private CapabilityRegistry $registry;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $modules;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $built = null;

    /**
     * @param array<string, array<string, mixed>>|null $modules
     */
    public function __construct(?CapabilityRegistry $registry = null, ?array $modules = null)
    {
        $this->registry = $registry ?? $this->resolveRegistry();
        $this->modules = $modules ?? $this->discoverModules();
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'summary' => $this->summary(),
            'modules' => $this->modules(),
            'events' => $this->events(),
            'capabilities' => $this->inspectAll(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function inspectAll(): array
    {
        $this->build();

        return array_values($this->built['capabilities'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $capabilityId): array
    {
        $this->build();

        $resolvedId = $this->resolveCapabilityIdFromSet($capabilityId, array_keys($this->built['capabilities'] ?? []));
        $entry = $this->built['capabilities'][$resolvedId] ?? $this->emptyCapabilityEntry($resolvedId);
        $entry['requested_id'] = $capabilityId !== $resolvedId ? $capabilityId : null;

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function modules(): array
    {
        $this->build();

        return array_values($this->built['modules'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function module(string $moduleId): ?array
    {
        $this->build();

        return $this->built['modules'][$moduleId] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        $this->build();

        return $this->built['events'] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $this->build();

        return $this->built['summary'] ?? [];
    }

    private function build(): void
    {
        if ($this->built !== null) {
            return;
        }

        $runtimeIds = $this->registry->capabilityIds();
        $capabilityIds = array_fill_keys($runtimeIds, true);
        $declaredProviders = [];
        $dependentModules = [];
        $events = [];
        $modules = [];

        foreach ($this->modules as $moduleId => $manifest) {
            if (!is_array($manifest)) {
                continue;
            }

            $normalizedModule = $this->normalizeModule(
                is_string($moduleId) && $moduleId !== '' ? $moduleId : (string)($manifest['id'] ?? ''),
                $manifest,
                $declaredProviders,
                $dependentModules,
                $events,
                $capabilityIds
            );

            if (($normalizedModule['id'] ?? '') !== '') {
                $modules[$normalizedModule['id']] = $normalizedModule;
            }
        }

        ksort($modules);
        usort($events, static function (array $left, array $right): int {
            $moduleCmp = strcmp((string)($left['module'] ?? ''), (string)($right['module'] ?? ''));
            if ($moduleCmp !== 0) {
                return $moduleCmp;
            }

            return strcmp((string)($left['key'] ?? ''), (string)($right['key'] ?? ''));
        });

        $allCapabilityIds = array_keys($capabilityIds);
        sort($allCapabilityIds);

        $latestIds = [];
        foreach ($allCapabilityIds as $capabilityId) {
            $baseId = $this->baseId($capabilityId);
            $currentLatest = $latestIds[$baseId] ?? null;
            if (!is_string($currentLatest) || ($this->majorVersion($capabilityId) ?? -1) > ($this->majorVersion($currentLatest) ?? -1)) {
                $latestIds[$baseId] = $capabilityId;
            }
        }

        $capabilities = [];
        foreach ($allCapabilityIds as $capabilityId) {
            $capabilities[$capabilityId] = $this->buildCapabilityEntry(
                $capabilityId,
                $declaredProviders[$capabilityId] ?? [],
                $dependentModules[$capabilityId] ?? [],
                $latestIds[$this->baseId($capabilityId)] ?? $capabilityId
            );
        }

        $enabledCount = 0;
        foreach ($modules as $module) {
            if (!empty($module['enabled'])) {
                $enabledCount++;
            }
        }

        $declaredCapabilityCount = 0;
        foreach ($capabilities as $capability) {
            if (((int)($capability['declared_provider_count'] ?? 0)) > 0) {
                $declaredCapabilityCount++;
            }
        }

        $this->built = [
            'modules' => $modules,
            'events' => $events,
            'capabilities' => $capabilities,
            'summary' => [
                'module_count' => count($modules),
                'enabled_module_count' => $enabledCount,
                'event_count' => count($events),
                'runtime_capability_count' => count($runtimeIds),
                'declared_capability_count' => $declaredCapabilityCount,
                'capability_count' => count($capabilities),
            ],
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $declaredProviders
     * @param array<string, array<int, array<string, mixed>>> $dependentModules
     * @param array<int, array<string, mixed>> $events
     * @param array<string, bool> $capabilityIds
     * @return array<string, mixed>
     */
    private function normalizeModule(
        string $moduleId,
        array $manifest,
        array &$declaredProviders,
        array &$dependentModules,
        array &$events,
        array &$capabilityIds
    ): array {
        $moduleId = trim($moduleId);
        if ($moduleId === '') {
            return [];
        }

        $capabilityCheck = $this->validateModuleCapabilities($manifest);
        $exposes = !empty($capabilityCheck['ok']) && is_array($capabilityCheck['exposes'] ?? null)
            ? $capabilityCheck['exposes']
            : [];
        $depends = !empty($capabilityCheck['ok']) && is_array($capabilityCheck['depends'] ?? null)
            ? $capabilityCheck['depends']
            : [];
        $policy = !empty($capabilityCheck['ok']) && is_array($capabilityCheck['policy'] ?? null)
            ? $capabilityCheck['policy']
            : [];

        $manifestPath = $this->manifestPath($manifest);
        $declaredExposeEntries = [];
        foreach ($exposes as $expose) {
            if (!is_array($expose)) {
                continue;
            }

            $capabilityId = trim((string)($expose['id'] ?? ''));
            if ($capabilityId === '') {
                continue;
            }

            $capabilityIds[$capabilityId] = true;

            $entry = [
                'module' => $moduleId,
                'module_name' => (string)($manifest['name'] ?? $moduleId),
                'enabled' => !empty($manifest['_enabled']),
                'id' => $capabilityId,
                'base_id' => $this->baseId($capabilityId),
                'major_version' => $this->majorVersion($capabilityId),
                'priority' => (int)($expose['priority'] ?? 10),
                'modes' => array_values(is_array($expose['modes'] ?? null) ? $expose['modes'] : ['first']),
                'schema' => is_array($expose['schema'] ?? null) ? $expose['schema'] : null,
                'policy' => $this->moduleCapabilityPolicy($policy, $capabilityId),
                'origin' => [
                    'type' => 'module_manifest',
                    'provider' => $moduleId,
                    'module' => $moduleId,
                    'file' => $manifestPath,
                    'capability' => $capabilityId,
                ],
            ];

            $declaredProviders[$capabilityId][] = $entry;
            $declaredExposeEntries[] = $entry;
        }

        foreach ($depends as $dependencyId) {
            $dependencyId = trim((string)$dependencyId);
            if ($dependencyId === '') {
                continue;
            }

            $capabilityIds[$dependencyId] = true;
            $dependentModules[$dependencyId][] = [
                'module' => $moduleId,
                'module_name' => (string)($manifest['name'] ?? $moduleId),
                'enabled' => !empty($manifest['_enabled']),
                'id' => $dependencyId,
                'base_id' => $this->baseId($dependencyId),
                'major_version' => $this->majorVersion($dependencyId),
                'origin' => [
                    'type' => 'module_manifest_dependency',
                    'module' => $moduleId,
                    'file' => $manifestPath,
                    'capability' => $dependencyId,
                ],
            ];
        }

        $moduleEvents = [];
        foreach ($this->normalizeEvents($manifest) as $event) {
            $eventEntry = [
                'module' => $moduleId,
                'module_name' => (string)($manifest['name'] ?? $moduleId),
                'enabled' => !empty($manifest['_enabled']),
                'key' => (string)($event['key'] ?? ''),
                'description' => (string)($event['description'] ?? ''),
                'available_vars' => array_values(is_array($event['available_vars'] ?? null) ? $event['available_vars'] : []),
                'origin' => [
                    'type' => 'module_manifest',
                    'module' => $moduleId,
                    'file' => $manifestPath,
                    'event' => (string)($event['key'] ?? ''),
                ],
            ];

            $moduleEvents[] = $eventEntry;
            $events[] = $eventEntry;
        }

        usort($declaredExposeEntries, static function (array $left, array $right): int {
            $priorityCmp = ((int)($right['priority'] ?? 0)) <=> ((int)($left['priority'] ?? 0));
            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }

            return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
        });

        usort($moduleEvents, static function (array $left, array $right): int {
            return strcmp((string)($left['key'] ?? ''), (string)($right['key'] ?? ''));
        });

        sort($depends);

        return [
            'id' => $moduleId,
            'name' => (string)($manifest['name'] ?? $moduleId),
            'version' => (string)($manifest['version'] ?? ''),
            'enabled' => !empty($manifest['_enabled']),
            'manifest_path' => $manifestPath,
            'path' => $this->modulePath($manifest),
            'capabilities' => [
                'exposes' => $declaredExposeEntries,
                'depends' => array_values($depends),
                'policy' => $policy,
                'exposes_count' => count($declaredExposeEntries),
                'depends_count' => count($depends),
            ],
            'events' => $moduleEvents,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $declaredProviders
     * @param array<int, array<string, mixed>> $dependentModules
     * @return array<string, mixed>
     */
    private function buildCapabilityEntry(string $capabilityId, array $declaredProviders, array $dependentModules, string $latestId): array
    {
        $runtime = $this->registry->inspect($capabilityId);

        usort($declaredProviders, static function (array $left, array $right): int {
            $priorityCmp = ((int)($right['priority'] ?? 0)) <=> ((int)($left['priority'] ?? 0));
            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }

            return strcmp((string)($left['module'] ?? ''), (string)($right['module'] ?? ''));
        });

        usort($dependentModules, static function (array $left, array $right): int {
            return strcmp((string)($left['module'] ?? ''), (string)($right['module'] ?? ''));
        });

        return [
            'id' => $capabilityId,
            'requested_id' => null,
            'base_id' => $this->baseId($capabilityId),
            'major_version' => $this->majorVersion($capabilityId),
            'latest_id' => $latestId,
            'is_latest' => $capabilityId === $latestId,
            'provider_count' => (int)($runtime['provider_count'] ?? 0),
            'effective_schema_mode' => $runtime['effective_schema_mode'] ?? null,
            'runtime_registered' => ((int)($runtime['provider_count'] ?? 0)) > 0,
            'providers' => array_values(is_array($runtime['providers'] ?? null) ? $runtime['providers'] : []),
            'declared_provider_count' => count($declaredProviders),
            'declared_providers' => $declaredProviders,
            'dependent_module_count' => count($dependentModules),
            'dependent_modules' => $dependentModules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCapabilityEntry(string $capabilityId): array
    {
        return [
            'id' => $capabilityId,
            'requested_id' => null,
            'base_id' => $this->baseId($capabilityId),
            'major_version' => $this->majorVersion($capabilityId),
            'latest_id' => $capabilityId,
            'is_latest' => true,
            'provider_count' => 0,
            'effective_schema_mode' => null,
            'runtime_registered' => false,
            'providers' => [],
            'declared_provider_count' => 0,
            'declared_providers' => [],
            'dependent_module_count' => 0,
            'dependent_modules' => [],
        ];
    }

    /**
     * @param array<int, string> $capabilityIds
     */
    private function resolveCapabilityIdFromSet(string $capabilityId, array $capabilityIds): string
    {
        $capabilityId = trim($capabilityId);
        if ($capabilityId === '') {
            return '';
        }

        if (preg_match('/@\d+$/', $capabilityId) === 1) {
            return $capabilityId;
        }

        $best = null;
        $bestMajor = -1;
        $prefix = $capabilityId . '@';
        foreach ($capabilityIds as $id) {
            if (!str_starts_with($id, $prefix)) {
                continue;
            }

            $major = $this->majorVersion($id) ?? -1;
            if ($major > $bestMajor) {
                $bestMajor = $major;
                $best = $id;
            }
        }

        if (is_string($best) && $best !== '') {
            return $best;
        }

        return $this->registry->resolve($capabilityId);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discoverModules(): array
    {
        if (\function_exists('discoverModules')) {
            $modules = \discoverModules();
            return is_array($modules) ? $modules : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateModuleCapabilities(array $manifest): array
    {
        if (\function_exists('validateModuleCapabilities')) {
            $validated = \validateModuleCapabilities($manifest);
            return is_array($validated) ? $validated : ['ok' => true, 'exposes' => [], 'depends' => [], 'policy' => []];
        }

        $caps = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        return [
            'ok' => true,
            'exposes' => array_values(is_array($caps['exposes'] ?? null) ? $caps['exposes'] : []),
            'depends' => array_values(is_array($caps['depends'] ?? null) ? $caps['depends'] : []),
            'policy' => is_array($caps['policy'] ?? null) ? $caps['policy'] : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEvents(array $manifest): array
    {
        $events = $manifest['events'] ?? [];
        if (!is_array($events)) {
            return [];
        }

        // Handle shorthand format: {"emits": ["event.key1", "event.key2", ...]}
        if (!array_is_list($events)) {
            $emits = $events['emits'] ?? [];
            if (is_array($emits)) {
                $events = $emits;
            }
        }

        // If events is now a flat list of strings (shorthand), convert to object format
        if (array_is_list($events) && !empty($events) && is_string($events[0] ?? null)) {
            $out = [];
            foreach ($events as $eventKey) {
                $key = trim((string)$eventKey);
                if ($key === '') {
                    continue;
                }
                $out[] = [
                    'key' => $key,
                    'description' => '',
                    'available_vars' => [],
                ];
            }
            return $out;
        }

        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $key = trim((string)($event['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $availableVars = $event['available_vars'] ?? [];
            if (!is_array($availableVars)) {
                $availableVars = [];
            }

            $out[] = [
                'key' => $key,
                'description' => trim((string)($event['description'] ?? '')),
                'available_vars' => array_values(array_filter($availableVars, static fn($value): bool => is_string($value) && trim($value) !== '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function moduleCapabilityPolicy(array $policy, string $capabilityId): array
    {
        $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];
        $perCapability = is_array($policy['capabilities'] ?? null) ? $policy['capabilities'] : [];
        $specific = is_array($perCapability[$capabilityId] ?? null) ? $perCapability[$capabilityId] : [];

        return array_merge($default, $specific);
    }

    private function resolveRegistry(): CapabilityRegistry
    {
        if (\function_exists('app')) {
            try {
                return \app()->capabilities();
            } catch (\Throwable $e) {
            }
        }

        return new CapabilityRegistry();
    }

    private function manifestPath(array $manifest): ?string
    {
        $path = trim((string)($manifest['_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        return $this->relativePath($path . '/module.json');
    }

    private function modulePath(array $manifest): ?string
    {
        $path = trim((string)($manifest['_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        return $this->relativePath($path);
    }

    private function relativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (\defined('BASE_PATH')) {
            $basePath = str_replace('\\', '/', (string)BASE_PATH);
            if ($basePath !== '' && str_starts_with($normalized, $basePath . '/')) {
                return substr($normalized, strlen($basePath) + 1);
            }
        }

        return ltrim($normalized, '/');
    }

    // baseId() and majorVersion() provided by CapabilityIdTrait
}