<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\ModuleComprehensionProvider;

/**
 * Layer 6: Cross-Module Cascade Analyzer.
 *
 * When module A's action fails at a capability-dependent step, checks if
 * the capability provider module has failing actions. This catches
 * cross-module bugs where the root cause is in a different module.
 *
 * Example:
 *   PAL calls entity.list.pal_project@1 (provided by PAL itself)
 *   If this fails, it might be a PAL DB schema issue
 *   But if PAL calls entity.list.employee@1 (provided by attendance-wage)
 *   and THAT fails, the root cause is in attendance-wage, not PAL
 *
 * Analyzes:
 *   - Capability dependency chains
 *   - Module boundary crossings
 *   - Cross-module data integrity (drift detection)
 */
class CrossModuleAnalyzer
{
    /** @var array<string, ModuleComprehensionProvider> */
    private array $providers = [];

    /**
     * @param array<string, ModuleComprehensionProvider> $providers Map of moduleId → provider
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * Register a provider.
     */
    public function registerProvider(string $moduleId, ModuleComprehensionProvider $provider): void
    {
        $this->providers[$moduleId] = $provider;
    }

    /**
     * Analyze cross-module impact for a failed action.
     *
     * @param string $moduleId The module where the failure was observed
     * @param string $actionId The action that failed
     * @param array $breakInfo Info about the breakpoint
     * @return array{cross_module: bool, cascade: array, recommendations: array}
     */
    public function analyzeImpact(string $moduleId, string $actionId, array $breakInfo): array
    {
        $cascade = [];
        $recommendations = [];

        // 1. Check if the break category suggests external dependency
        $breakCategory = $breakInfo['category'] ?? 'unknown';
        if ($breakCategory === 'capability' || $breakCategory === 'http') {
            // Likely calling an external module
            $cascade[] = [
                'type' => 'external_dependency',
                'description' => "Breakpoint at {$breakCategory} suggests dependency on external module",
                'severity' => 'info',
            ];
        }

        // 2. Check capability dependencies of this action
        $action = $this->findAction($moduleId, $actionId);
        if ($action) {
            $requires = $action->requires ?? [];
            foreach ($requires as $key => $value) {
                if (str_starts_with((string)$key, 'capability.') || str_starts_with((string)$value, 'capability.')) {
                    // This action depends on a capability — find who provides it
                    $capId = str_replace('capability.', '', (string)$value);
                    $providerModule = $this->findCapabilityProvider($capId);

                    if ($providerModule && $providerModule !== $moduleId) {
                        $cascade[] = [
                            'type' => 'capability_dependency',
                            'module' => $providerModule,
                            'capability' => $capId,
                            'description' => "Action depends on capability '{$capId}' provided by module '{$providerModule}'",
                            'severity' => 'warning',
                        ];

                        $recommendations[] = "Check module '{$providerModule}' for capability '{$capId}' health";
                    }
                }
            }
        }

        // 3. Check for read-contract drift (tables read from other modules)
        $moduleInfo = $this->getModuleInfo($moduleId);
        if (!empty($moduleInfo['reads_tables'])) {
            foreach ($moduleInfo['reads_tables'] as $table => $owner) {
                $cascade[] = [
                    'type' => 'cross_module_read',
                    'table' => $table,
                    'owner' => $owner,
                    'description' => "Module reads table '{$table}' owned by '{$owner}'",
                    'severity' => 'info',
                ];
            }
        }

        return [
            'cross_module' => count(array_filter($cascade, fn($c) => $c['severity'] === 'warning' || $c['severity'] === 'error')) > 0,
            'cascade' => $cascade,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Analyze all known modules and find cascade failures.
     *
     * @return array<int, array{from_module: string, to_module: string, through: string, description: string}>
     */
    public function findCascadeFailures(array $moduleResults): array
    {
        $cascades = [];

        foreach ($moduleResults as $moduleId => $results) {
            if (!is_array($results)) continue;

            foreach ($results as $actionId => $analysis) {
                if (!is_array($analysis)) continue;
                $breakCategory = $analysis['likely_area'] ?? '';
                $breakpoint = $analysis['breakpoint'] ?? null;

                if ($breakpoint && ($breakCategory === 'capability' || $breakCategory === 'external')) {
                    // This module failed on an external dependency — check the provider
                    // (Simplified: would need runtime provider registry to fully resolve)
                    $cascades[] = [
                        'from_module' => $moduleId,
                        'to_module' => 'unknown',
                        'through' => $breakCategory,
                        'description' => "Module '{$moduleId}' failed at {$breakCategory} step '{$breakpoint}'",
                    ];
                }
            }
        }

        return $cascades;
    }

    private function findAction(string $moduleId, string $actionId): mixed
    {
        $provider = $this->providers[$moduleId] ?? null;
        if (!$provider) return null;

        foreach ($provider->actions() as $action) {
            if ($action->id === $actionId) {
                return $action;
            }
        }

        return null;
    }

    private function findCapabilityProvider(string $capabilityId): ?string
    {
        foreach ($this->providers as $moduleId => $provider) {
            foreach ($provider->capabilities() as $cap) {
                if ($cap === $capabilityId || str_starts_with((string)$cap, $capabilityId)) {
                    return $moduleId;
                }
            }
        }

        return null;
    }

    /**
     * Get module.json info for a module (reads_tables, depends, etc.).
     *
     * @return array{reads_tables: array}
     */
    private function getModuleInfo(string $moduleId): array
    {
        $path = realpath(__DIR__ . "/../../../../modules/{$moduleId}/module.json");
        if ($path && file_exists($path)) {
            $info = json_decode(file_get_contents($path), true);
            if ($info) {
                $tables = $info['reads_tables'] ?? [];
                // If it's a simple indexed array, map to a default owner
                if (array_is_list($tables)) {
                    $tables = array_fill_keys($tables, $moduleId);
                }
                return ['reads_tables' => $tables];
            }
        }

        return ['reads_tables' => []];
    }
}
