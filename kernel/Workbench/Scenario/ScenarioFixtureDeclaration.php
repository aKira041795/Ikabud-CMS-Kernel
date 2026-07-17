<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Scenario;

/**
 * Declares the fixture prerequisites for a scenario.
 *
 * Each scenario can declare:
 *   - actor/role identity
 *   - tenant context
 *   - seed identity (data isolation namespace)
 *   - required entity relationships
 *   - lifecycle state expectations
 *
 * Modules provide concrete records via CapabilityScenarioDataProvider;
 * this declaration tells the runner what to ask for and how to classify
 * an unavailable prerequisite.
 */
final class ScenarioFixtureDeclaration
{
    /** @param array<string,mixed> $declaration */
    public function __construct(private readonly array $declaration) {}

    /** @return array<string,mixed> */
    public function normalize(): array
    {
        $d = $this->declaration;
        return [
            'schema' => 'ark.scenario-fixture-declaration.v1',
            'actor' => $this->normalizeActor($d),
            'tenant' => $this->normalizeTenant($d),
            'seed_identity' => $this->normalizeSeedIdentity($d),
            'required_entities' => $this->normalizeRequiredEntities($d),
            'lifecycle_state' => $this->normalizeLifecycleState($d),
            'ownership' => $this->normalizeOwnership($d),
        ];
    }

    public function validate(): array
    {
        $normalized = $this->normalize();
        $errors = [];

        if ($normalized['actor']['role'] === '') {
            $errors[] = 'missing:actor.role';
        }
        if ($normalized['tenant']['tenant_key'] === '' && $normalized['tenant']['tenant_id'] === 0) {
            $errors[] = 'missing:tenant.identity';
        }
        foreach ($normalized['required_entities'] as $i => $entity) {
            if (($entity['type'] ?? '') === '') {
                $errors[] = "missing:required_entity.{$i}.type";
            }
            if (($entity['min_count'] ?? 0) < 0) {
                $errors[] = "invalid:required_entity.{$i}.min_count";
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'normalized' => $normalized];
    }

    /**
     * Classify a missing fixture as unmet-prerequisite.
     *
     * @param array<string,mixed> $entity The entity declaration from required_entities
     * @param string $reason Why the fixture is unavailable
     * @return array<string,mixed>
     */
    public function classifyUnmet(array $entity, string $reason = 'No records found'): array
    {
        $normalized = $this->normalize();
        $capabilityId = 'workbench.scenario.seed@1';
        $owner = $normalized['ownership']['provider_module'] ?? 'unassigned';

        // Find the recommended provider capability from entity constraints
        if (isset($entity['provider_capability']) && is_string($entity['provider_capability'])) {
            $capabilityId = $entity['provider_capability'];
        }

        return [
            'classification' => 'unmet-prerequisite',
            'entity_type' => (string) ($entity['type'] ?? 'unknown'),
            'reason' => $reason,
            'owner' => $owner,
            'recommended_provider_capability' => $capabilityId,
            'fixture_requirements' => [
                'role' => $normalized['actor']['label'] ?: $normalized['actor']['role'],
                'tenant' => $normalized['tenant']['tenant_key'] ?: 'tenant-' . $normalized['tenant']['tenant_id'],
                'min_count' => $entity['min_count'] ?? 1,
                'lifecycle' => $normalized['lifecycle_state']['required_state'] ?? 'any',
            ],
            'observed_links' => [],
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeActor(array $d): array
    {
        return [
            'role' => (string) ($d['fixture_role'] ?? $d['actor']['role'] ?? ''),
            'user_id' => (int) ($d['fixture_user_id'] ?? $d['actor']['user_id'] ?? 0),
            'label' => (string) ($d['fixture_label'] ?? $d['actor']['label'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeTenant(array $d): array
    {
        return [
            'tenant_id' => (int) ($d['tenant_id'] ?? $d['tenant']['tenant_id'] ?? 0),
            'tenant_key' => (string) ($d['tenant_key'] ?? $d['tenant']['tenant_key'] ?? ''),
            'domain' => (string) ($d['tenant_domain'] ?? $d['tenant']['domain'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeSeedIdentity(array $d): array
    {
        return [
            'namespace' => (string) ($d['seed_namespace'] ?? $d['seed_identity']['namespace'] ?? ''),
            'version' => (string) ($d['scenario_version'] ?? $d['seed_identity']['version'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeRequiredEntities(array $d): array
    {
        $entities = (array) ($d['required_entities'] ?? $d['data']['entities'] ?? []);
        $normalized = [];

        foreach ($entities as $type => $records) {
            if (is_array($records) && isset($records['type'])) {
                // Already structured
                $normalized[] = $this->normalizeEntity($records);
            } elseif (is_string($type) && is_array($records)) {
                // Legacy format: entity type => array of records
                $normalized[] = [
                    'type' => $type,
                    'min_count' => count($records),
                    'provider_capability' => '',
                    'lifecycle' => 'any',
                ];
            }
        }

        return $normalized;
    }

    /** @param array<string,mixed> $e */
    private function normalizeEntity(array $e): array
    {
        return [
            'type' => (string) ($e['type'] ?? ''),
            'min_count' => max(0, (int) ($e['min_count'] ?? 1)),
            'provider_capability' => (string) ($e['provider_capability'] ?? ''),
            'lifecycle' => (string) ($e['lifecycle'] ?? 'any'),
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeLifecycleState(array $d): array
    {
        return [
            'required_state' => (string) ($d['lifecycle_state'] ?? $d['fixture_lifecycle'] ?? ''),
            'optional' => (bool) ($d['lifecycle_optional'] ?? false),
        ];
    }

    /** @param array<string,mixed> $d */
    private function normalizeOwnership(array $d): array
    {
        return [
            'provider_module' => (string) ($d['module_id'] ?? $d['module'] ?? ''),
            'declared_by' => (string) ($d['created_by'] ?? 'workbench'),
        ];
    }
}
