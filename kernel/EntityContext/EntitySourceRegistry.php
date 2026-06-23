<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Explicit registry for entity data sources.
 *
 * Modules register entity sources here with full metadata instead of relying
 * on implicit capability name inference. The registry provides one
 * authoritative definition for capability IDs, filter schemas, timeout,
 * sort limits, and field visibility.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntitySourceRegistry
{
    /** @var array<string, array> sourceId => definition */
    private array $sources = [];

    /** @var bool Whether the registry has been frozen after module boot */
    private bool $frozen = false;

    /**
     * Register an entity data source.
     *
     * Definition keys:
     *   entity_type       (string) Canonical entity type (e.g. 'pal_expense')
     *   list_capability   (string) Capability ID for list queries
     *   detail_capability (string|null) Capability ID for detail queries
     *   filters           (array) Filter schema: name => ['type'=>'int|string|enum', 'values'=>[...]]
     *   sort              (array|null) Allowed sort fields, default sort
     *   timeout_ms        (int) Per-source capability call timeout
     *   max_limit         (int) Maximum rows per page
     *   field_schema      (array|null) Field => ['type'=>'...', 'visible'=>bool]
     *
     * @param string $sourceId  Dot-separated source identifier (e.g. 'pal.expense')
     * @param array  $definition  Source definition
     * @throws \RuntimeException if registry is frozen
     */
    public function register(string $sourceId, array $definition): void
    {
        if ($this->frozen) {
            throw new \RuntimeException("EntitySourceRegistry is frozen — cannot register '{$sourceId}' after boot.");
        }

        $this->sources[$sourceId] = array_replace([
            'entity_type'       => '',
            'list_capability'   => null,
            'detail_capability' => null,
            'filters'           => [],
            'sort'              => null,
            'timeout_ms'        => 10000,
            'max_limit'         => 100,
            'field_schema'      => null,
        ], $definition);
    }

    /**
     * Get a source definition by ID.
     */
    public function get(string $sourceId): ?array
    {
        return $this->sources[$sourceId] ?? null;
    }

    /**
     * Check if a source is registered.
     */
    public function has(string $sourceId): bool
    {
        return isset($this->sources[$sourceId]);
    }

    /**
     * Get all registered source IDs.
     *
     * @return string[]
     */
    public function all(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Get all source definitions keyed by ID.
     *
     * @return array<string, array>
     */
    public function allDefinitions(): array
    {
        return $this->sources;
    }

    /**
     * Resolve the capability ID for a given source and operation type.
     */
    public function resolveCapabilityId(string $sourceId, string $type = 'list'): ?string
    {
        $source = $this->sources[$sourceId] ?? null;
        if ($source === null) {
            return null;
        }
        return $type === 'detail' ? ($source['detail_capability'] ?? null) : ($source['list_capability'] ?? null);
    }

    /**
     * Parse a source string (e.g. 'pal.expense' or 'pal.expense.recent')
     * into base source ID and qualifier.
     *
     * @return array{source_id: string, qualifier: string|null}
     */
    public function parseSource(string $source): array
    {
        // Try exact match first
        if (isset($this->sources[$source])) {
            return ['source_id' => $source, 'qualifier' => null];
        }

        // Try source.qualifier pattern
        $parts = explode('.', $source);
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $base = implode('.', array_slice($parts, 0, $i));
            $qualifier = implode('.', array_slice($parts, $i));
            if (isset($this->sources[$base])) {
                return ['source_id' => $base, 'qualifier' => $qualifier];
            }
        }

        return ['source_id' => $source, 'qualifier' => null];
    }

    /**
     * Freeze the registry — prevents further modifications.
     * Called after module boot completes.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * Check if the registry is frozen.
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Get the filter schema for a source.
     */
    public function getFilterSchema(string $sourceId): array
    {
        $source = $this->sources[$sourceId] ?? null;
        return $source['filters'] ?? [];
    }
}
