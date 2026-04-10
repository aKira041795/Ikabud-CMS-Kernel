<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

final class CapabilityRegistry
{
    /**
     * @var array<string, array<int, array{cap: string, provider: string, priority: int, modes: string[], handler: callable, meta: array, registration_order: int}>>
     */
    private array $providers = [];

    private int $registrationCounter = 0;

    public function register(
        string $capabilityId,
        string $providerId,
        callable $handler,
        int $priority = 10,
        array $modes = ['first'],
        array $meta = []
    ): void {
        $modes = array_values(array_unique(array_map('strtolower', $modes)));

        $this->providers[$capabilityId][] = [
            'cap' => $capabilityId,
            'provider' => $providerId,
            'priority' => $priority,
            'modes' => $modes,
            'handler' => $handler,
            'meta' => $meta,
            'registration_order' => $this->registrationCounter++,
        ];

        $this->sortProviders($capabilityId);
    }

    public function has(string $capabilityId): bool
    {
        return !empty($this->providers[$capabilityId]);
    }

    /**
     * @return array<int, array{cap: string, provider: string, priority: int, modes: string[], handler: callable, meta: array}>
     */
    public function providers(string $capabilityId): array
    {
        return $this->providers[$capabilityId] ?? [];
    }

    /**
     * @return string[]
     */
    public function capabilityIds(): array
    {
        $keys = array_keys($this->providers);
        sort($keys);
        return $keys;
    }

    public function resolve(string $capabilityId): string
    {
        if (preg_match('/@\d+$/', $capabilityId)) {
            return $capabilityId;
        }

        $best = null;
        $bestMajor = -1;
        $prefix = $capabilityId . '@';
        foreach (array_keys($this->providers) as $id) {
            if (!str_starts_with($id, $prefix)) {
                continue;
            }
            $major = (int)substr($id, strlen($prefix));
            if ($major > $bestMajor) {
                $bestMajor = $major;
                $best = $id;
            }
        }

        return $best ?? $capabilityId;
    }

    public function inspect(string $capabilityId): array
    {
        $resolvedId = $this->resolve($capabilityId);
        $providers = [];

        foreach ($this->providers($resolvedId) as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $providers[] = [
                'provider' => (string)($entry['provider'] ?? ''),
                'priority' => (int)($entry['priority'] ?? 0),
                'modes' => array_values(is_array($entry['modes'] ?? null) ? $entry['modes'] : []),
                'schema' => $meta['schema'] ?? null,
                'policy' => $meta['policy'] ?? null,
                'origin' => $this->normalizeOrigin($entry),
            ];
        }

        $baseId = $this->baseId($resolvedId);

        $effectiveSchemaMode = null;
        try {
            if (function_exists('app')) {
                $effectiveSchemaMode = app()->cap()->resolveSchemaMode($resolvedId);
            }
        } catch (\Throwable $e) {
        }

        return [
            'id' => $resolvedId,
            'requested_id' => $capabilityId !== $resolvedId ? $capabilityId : null,
            'base_id' => $baseId,
            'major_version' => $this->majorVersion($resolvedId),
            'latest_id' => $this->resolve($baseId),
            'is_latest' => $resolvedId === $this->resolve($baseId),
            'provider_count' => count($providers),
            'effective_schema_mode' => $effectiveSchemaMode,
            'providers' => $providers,
        ];
    }

    public function inspectAll(): array
    {
        $out = [];
        foreach ($this->capabilityIds() as $capabilityId) {
            $out[] = $this->inspect($capabilityId);
        }

        return $out;
    }

    private function sortProviders(string $capabilityId): void
    {
        if (empty($this->providers[$capabilityId])) {
            return;
        }

        usort($this->providers[$capabilityId], function (array $a, array $b): int {
            // priority DESC
            $p = ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
            if ($p !== 0) return $p;
            // FIFO tiebreaker: earlier registration wins (lower order = higher precedence)
            return ($a['registration_order'] ?? 0) <=> ($b['registration_order'] ?? 0);
        });
    }

    private function baseId(string $capabilityId): string
    {
        return (string)preg_replace('/@\d+$/', '', $capabilityId);
    }

    private function majorVersion(string $capabilityId): ?int
    {
        if (!preg_match('/@(\d+)$/', $capabilityId, $matches)) {
            return null;
        }

        return (int)$matches[1];
    }

    private function normalizeOrigin(array $entry): array
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $origin = is_array($meta['origin'] ?? null) ? $meta['origin'] : [];
        if (!isset($origin['type']) || !is_string($origin['type']) || trim($origin['type']) === '') {
            $origin['type'] = (string)($entry['provider'] ?? '') === 'kernel' ? 'kernel' : 'runtime_register';
        }
        if (!isset($origin['provider']) || !is_string($origin['provider']) || trim($origin['provider']) === '') {
            $origin['provider'] = (string)($entry['provider'] ?? '');
        }

        return $origin;
    }
}
