<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Visual;

final class ComponentScenarioGovernance
{
    public const REQUIRED_STATES = ['empty', 'loading', 'populated', 'validation', 'error', 'unauthorized', 'degraded'];
    public const REQUIRED_VIEWPORTS = ['desktop', 'tablet', 'mobile'];

    /** @param array<string,mixed> $catalog @return array<string,mixed> */
    public function validateCatalog(array $catalog): array
    {
        $errors = [];
        $components = (array) ($catalog['components'] ?? []);
        foreach ($components as $component) {
            if (is_string($component)) $component = ['id' => $component, 'states' => (array) ($catalog['required_states'] ?? []), 'variants' => (array) ($catalog['required_variants'] ?? [])];
            $id = (string) ($component['id'] ?? 'unknown');
            $states = (array) ($component['states'] ?? []);
            foreach (self::REQUIRED_STATES as $state) if (!in_array($state, $states, true)) $errors[] = "{$id}: missing state {$state}";
            $viewports = (array) ($component['variants']['viewports'] ?? []);
            foreach (self::REQUIRED_VIEWPORTS as $viewport) if (!in_array($viewport, $viewports, true)) $errors[] = "{$id}: missing viewport {$viewport}";
            foreach (['themes', 'locales', 'data_shapes'] as $variant) if (($component['variants'][$variant] ?? []) === []) $errors[] = "{$id}: missing {$variant} variants";
        }
        if ($components === []) $errors[] = 'component catalog is empty';
        return ['ok' => $errors === [], 'errors' => $errors, 'governed_components' => count($components)];
    }

    /** @return array<string,mixed> */
    public function compare(string $component, string $scenario, string $baselineHash, string $actualHash, array $accessibilityViolations = []): array
    {
        $critical = array_values(array_filter($accessibilityViolations, static fn(array $v): bool => in_array($v['impact'] ?? '', ['critical', 'serious'], true)));
        $changed = !hash_equals($baselineHash, $actualHash);
        return ['component' => $component, 'scenario' => $scenario, 'changed' => $changed, 'baseline_hash' => $baselineHash, 'actual_hash' => $actualHash, 'critical_accessibility_violations' => $critical, 'release_allowed' => !$changed && $critical === [], 'approval_required' => $changed];
    }

    /** @param array<string,mixed> $comparison @return array<string,mixed> */
    public function approve(array $comparison, string $approver, string $reason): array
    {
        if (!($comparison['changed'] ?? false)) throw new \RuntimeException('No visual baseline change to approve');
        if (trim($approver) === '' || trim($reason) === '') throw new \RuntimeException('Approver and reason are required');
        return ['schema' => 'ark.visual-baseline-approval.v1', 'component' => $comparison['component'], 'scenario' => $comparison['scenario'], 'previous_hash' => $comparison['baseline_hash'], 'approved_hash' => $comparison['actual_hash'], 'approver' => $approver, 'reason' => $reason, 'approved_at' => gmdate(DATE_ATOM)];
    }

    /** @param list<array<string,mixed>> $contracts @return list<string> */
    public function affectedModules(string $component, array $contracts): array
    {
        $modules = [];
        foreach ($contracts as $contract) {
            foreach ((array) ($contract['pages'] ?? []) as $page) {
                if (in_array($component, (array) ($page['required_components'] ?? []), true)) $modules[] = (string) ($contract['module']['id'] ?? '');
            }
        }
        $modules = array_values(array_unique(array_filter($modules))); sort($modules); return $modules;
    }
}
