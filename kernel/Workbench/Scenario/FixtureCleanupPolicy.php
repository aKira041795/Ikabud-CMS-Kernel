<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Scenario;

/**
 * Fixture cleanup policy — tenant-scoped, idempotent, never deletes user-owned data.
 *
 * Rules:
 *   - All fixture data is scoped to a tenant (tenant_id or tenant_key must be provided)
 *   - Cleanup is idempotent: calling multiple times is safe
 *   - Never deletes records that lack a run_id / _ark_id / _ark_scenario marker
 *   - Never truncates or DROP TABLEs — only deletes marked fixture records
 *   - Rollback on failure: if prepare() fails mid-way, cleanup removes partial data
 */
final class FixtureCleanupPolicy
{
    /** @param array<string,mixed> $scenario */
    public function validate(array $scenario): array
    {
        $errors = [];
        $data = (array) ($scenario['data'] ?? []);
        $tenantId = (int) ($data['tenant_id'] ?? $scenario['tenant_id'] ?? 0);
        $tenantKey = (string) ($data['tenant_key'] ?? $scenario['tenant_key'] ?? '');

        if ($tenantId === 0 && $tenantKey === '') {
            $errors[] = 'Fixture cleanup requires tenant scope (tenant_id or tenant_key)';
        }

        $entities = (array) ($data['entities'] ?? []);
        $entityCount = 0;
        foreach ($entities as $records) {
            $entityCount += is_array($records) ? count($records) : 0;
        }
        if ($entityCount === 0) {
            $errors[] = 'No fixture entities declared for cleanup';
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Build cleanup instructions from a seed receipt.
     *
     * @param array<string,mixed> $scenario
     * @param array<string,mixed> $receipt Seed receipt from prepare()
     * @return array<string,mixed>
     */
    public function buildCleanup(array $scenario, array $receipt): array
    {
        $data = (array) ($scenario['data'] ?? []);

        return [
            'schema' => 'ark.fixture-cleanup.v1',
            'scope' => [
                'tenant_id' => (int) ($data['tenant_id'] ?? $scenario['tenant_id'] ?? 0),
                'tenant_key' => (string) ($data['tenant_key'] ?? $scenario['tenant_key'] ?? ''),
                'run_id' => (string) ($receipt['namespace'] ?? $receipt['run_id'] ?? ''),
                'scenario_id' => (string) ($scenario['scenario_id'] ?? ''),
            ],
            'policy' => [
                'never_delete_user_owned_data' => true,
                'idempotent' => true,
                'tenant_scoped' => true,
                'method' => 'marker-based-deletion',
                'marker_field' => '_ark_scenario',
            ],
            'entities' => $this->buildEntityCleanup($scenario, $receipt),
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * Check if cleanup can proceed safely.
     *
     * @param array<string,mixed> $cleanupPlan
     * @return array<string,mixed>
     */
    public function canCleanup(array $cleanupPlan): array
    {
        $scope = (array) ($cleanupPlan['scope'] ?? []);
        $runId = (string) ($scope['run_id'] ?? '');
        $tenantId = (int) ($scope['tenant_id'] ?? 0);
        $tenantKey = (string) ($scope['tenant_key'] ?? '');

        if ($runId === '') {
            return ['allowed' => false, 'reason' => 'No run_id in cleanup scope — cannot identify fixture data'];
        }
        if ($tenantId === 0 && $tenantKey === '') {
            return ['allowed' => false, 'reason' => 'No tenant scope — cleanup must remain tenant-scoped'];
        }

        // Safety check: never delete without a run_id marker
        $policy = (array) ($cleanupPlan['policy'] ?? []);
        $markerField = (string) ($policy['marker_field'] ?? '');
        if ($markerField !== '_ark_scenario'
            || ($policy['method'] ?? '') !== 'marker-based-deletion'
            || ($policy['tenant_scoped'] ?? false) !== true
            || ($policy['never_delete_user_owned_data'] ?? false) !== true) {
            return ['allowed' => false, 'reason' => 'Cleanup policy is not a verified tenant-scoped marker policy'];
        }

        $entities = (array) ($cleanupPlan['entities'] ?? []);
        if ($entities === []) {
            return ['allowed' => false, 'reason' => 'No marked fixture entities in cleanup plan'];
        }
        foreach ($entities as $entity) {
            if (!is_array($entity) || (string) ($entity['type'] ?? '') === '' || (string) ($entity['marker_value'] ?? '') === '') {
                return ['allowed' => false, 'reason' => 'Cleanup entity is missing an immutable fixture marker'];
            }
        }

        return ['allowed' => true, 'reason' => 'Cleanup scope valid', 'marker' => $markerField];
    }

    /** @param array<string,mixed> $scenario */
    private function buildEntityCleanup(array $scenario, array $receipt): array
    {
        $entities = [];
        $data = (array) ($scenario['data'] ?? []);
        $receiptEntities = (array) ($receipt['entities'] ?? []);

        foreach ($receiptEntities as $entity) {
            $type = (string) ($entity['type'] ?? '');
            if ($type === '') continue;
            $entities[] = [
                'type' => $type,
                'cleanup_method' => 'delete-where-_ark_scenario',
                'marker_value' => (string) ($scenario['scenario_id'] ?? ''),
                'count' => (int) ($entity['count'] ?? 0),
            ];
        }

        // Fall back to scenario data entities if receipt doesn't have them
        if ($entities === []) {
            foreach ((array) ($data['entities'] ?? []) as $type => $records) {
                if (is_array($records)) {
                    $entities[] = [
                        'type' => (string) $type,
                        'cleanup_method' => 'delete-where-_ark_scenario',
                        'marker_value' => (string) ($scenario['scenario_id'] ?? ''),
                        'count' => count($records),
                    ];
                }
            }
        }

        return $entities;
    }
}
