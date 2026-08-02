<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * EntityViewResolver — resolves DiSyL source/view declarations to structured data.
 *
 * This is the north-star feature of the platform. It turns:
 *   <ikb_entity_list source="orders.recent" view="compact" />
 * into:
 *   - a resolved entity type (orders)
 *   - a resolved context profile (order.view.compact)
 *   - fetched data via the capability bus
 *   - sanitized, tenant-scoped, permission-checked output
 *
 * Source format:   {entity_type}.{qualifier}
 * View format:     compact | detailed | card_grid | table | admin_row | etc.
 *
 * @package Ikabud\Kernel\EntityContext
 * @version 1.1.0
 */
final class EntityViewResolver
{
    private static ?EntityViewResolver $instance = null;

    /** @var array<string, array{entity_type: string, qualifier: string}> parsed source cache */
    private array $sourceCache = [];

    /** @var array<string, array<string, mixed>> resolved view contracts */
    private array $viewContracts = [];

    /** @var array<string, ResolvedEntityContext> cached resolved contexts */
    private array $resolvedCache = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset all internal caches (call on request teardown or in tests).
     */
    public function reset(): void
    {
        $this->sourceCache = [];
        $this->viewContracts = [];
        $this->resolvedCache = [];
    }

    // ── Source parsing ──

    /**
     * Parse a source string like "orders.recent" or "products.featured"
     * into {entity_type, qualifier}.
     *
     * @return array{entity_type: string, qualifier: string}
     */
    public function parseSource(string $source): array
    {
        $source = trim($source);
        if ($source === '') {
            return ['entity_type' => '', 'qualifier' => ''];
        }

        if (isset($this->sourceCache[$source])) {
            return $this->sourceCache[$source];
        }

        $dot = strrpos($source, '.');
        if ($dot === false) {
            $parsed = ['entity_type' => $source, 'qualifier' => ''];
        } else {
            $parsed = [
                'entity_type' => substr($source, 0, $dot),
                'qualifier' => substr($source, $dot + 1),
            ];
        }

        $this->sourceCache[$source] = $parsed;
        return $parsed;
    }

    // ── View contract resolution ──

    /**
     * Register a view contract for an entity type + view combination.
     *
     * Uses domain-specific merge rules (P1.5) instead of generic
     * array_replace_recursive:
     *   - fields:   array union; '*' absorbs all
     *   - actions:  array union
     *   - sort:     $contract wins entirely
     *   - renderers, field_contracts, action_*:  array merge ($contract wins per key)
     *   - scalars:  $contract wins if non-null, else default
     *
     * Provenance (P1.4) is tracked per registration with provider ID + timestamp.
     *
     * @param array<string, mixed> $contract
     */
    public function registerView(string $entityType, string $view, array $contract, string $providerId = 'kernel'): void
    {
        $key = $this->viewKey($entityType, $view);

        $defaults = [
            'entity_type'     => $entityType,
            'view'            => $view,
            'fields'          => '*',
            'actions'         => [],
            'limit'           => 25,
            'sort'            => ['field' => 'created_at', 'direction' => 'desc'],
            'empty_state'     => 'No records found.',
            'error_state'     => 'Unable to load data.',
            'exportable'      => false,
            'capability'      => null,
            'provider'        => $providerId,
            'sortable_fields' => [],
            'field_contracts' => [],
            'renderers'       => [],
            'action_urls'     => [],
            'action_methods'  => [],
            'action_confirm'  => [],
            'action_show_if'  => [],
            'action_labels'   => [],
            'key_field'       => null,
            'timeout_ms'      => 10000,
        ];

        // Domain-specific merge (P1.5) — not generic array_replace
        $merged = $defaults;

        // Fields: array union; '*' absorbs
        if (isset($contract['fields'])) {
            $merged['fields'] = $contract['fields'];
            if (is_array($defaults['fields']) && is_array($contract['fields'])) {
                $merged['fields'] = array_values(array_unique(array_merge($defaults['fields'], $contract['fields'])));
            }
        }

        // Actions: array union
        if (isset($contract['actions']) && is_array($contract['actions'])) {
            $merged['actions'] = array_values(array_unique(array_merge($defaults['actions'], $contract['actions'])));
        }

        // Sort: contract wins entirely
        if (isset($contract['sort']) && is_array($contract['sort'])) {
            $merged['sort'] = $contract['sort'];
        }

        // Renderers, field_contracts, action maps: merge (contract wins per key)
        foreach (['renderers', 'field_contracts', 'action_urls', 'action_methods', 'action_confirm', 'action_show_if', 'action_labels', 'sortable_fields'] as $mapKey) {
            if (isset($contract[$mapKey]) && is_array($contract[$mapKey])) {
                $merged[$mapKey] = array_merge($defaults[$mapKey] ?? [], $contract[$mapKey]);
            }
        }

        // Scalars: contract wins if non-null
        foreach (['limit', 'empty_state', 'error_state', 'exportable', 'capability', 'key_field', 'timeout_ms'] as $scalar) {
            if (array_key_exists($scalar, $contract)) {
                $merged[$scalar] = $contract[$scalar];
            }
        }

        $merged['provider'] = $providerId;

        // Provenance tracking (P1.4)
        $entry = ['provider' => $providerId, 'timestamp' => date('c')];
        $provenance = [$entry];
        if (isset($this->viewContracts[$key]['_provenance']) && is_array($this->viewContracts[$key]['_provenance'])) {
            $provenance = array_merge($this->viewContracts[$key]['_provenance'], [$entry]);
        }
        $merged['_provenance'] = $provenance;

        $this->viewContracts[$key] = $merged;
        unset($this->resolvedCache[$key]);
    }

    /**
     * Get the view contract for an entity type + view.
     *
     * Returns cached ResolvedEntityContext for performance (P2.5).
     *
     * @return array<string, mixed>|null
     */
    public function viewContract(string $entityType, string $view): ?array
    {
        $key = $this->viewKey($entityType, $view);

        // Return cached resolved context as array
        if (isset($this->resolvedCache[$key])) {
            return $this->resolvedCache[$key]->toArray();
        }

        // Exact match
        if (isset($this->viewContracts[$key])) {
            $ctx = ResolvedEntityContext::fromContract(
                $entityType, $view, $this->viewContracts[$key],
                $this->viewContracts[$key]['_provenance'] ?? null
            );
            $this->resolvedCache[$key] = $ctx;
            return $ctx->toArray();
        }

        // Fallback: default view for the entity type
        $fallbackKey = $this->viewKey($entityType, 'default');
        if (isset($this->viewContracts[$fallbackKey])) {
            $ctx = ResolvedEntityContext::fromContract(
                $entityType, $view, $this->viewContracts[$fallbackKey],
                $this->viewContracts[$fallbackKey]['_provenance'] ?? null
            );
            $this->resolvedCache[$key] = $ctx;
            return $ctx->toArray();
        }

        // Last resort: built-in defaults per entity type
        $defaults = $this->builtinDefaults($entityType, $view);
        if ($defaults !== null) {
            $ctx = ResolvedEntityContext::fromContract($entityType, $view, $defaults, [
                ['provider' => 'kernel.builtin', 'timestamp' => '1970-01-01T00:00:00+00:00'],
            ]);
            $this->resolvedCache[$key] = $ctx;
            return $ctx->toArray();
        }

        // Ultimate fallback: generic contract with wildcard fields
        $genericDefaults = $this->genericFallbackContract($entityType, $view);
        $ctx = ResolvedEntityContext::fromContract($entityType, $view, $genericDefaults, [
            ['provider' => 'kernel.generic', 'timestamp' => '1970-01-01T00:00:00+00:00'],
        ]);
        $this->resolvedCache[$key] = $ctx;
        return $ctx->toArray();
    }

    // ── Data resolution (calls the capability bus) ──

    /**
     * Resolve a source + view to actual data rows.
     *
     * Calls the capability bus with:
     *   capability:  entity.list.{entity_type}@{version}
     *   args:        {qualifier, view, limit, sort, ...}
     *
     * @param array<string, mixed> $overrides  caller overrides (limit, sort, filters, etc.)
     * @return array{rows: array<int, array>, total: int, view: array, source: array, error: string|null}
     */
    public function resolve(string $source, string $view = 'compact', array $overrides = []): array
    {
        $parsed = $this->parseSource($source);
        $entityType = $parsed['entity_type'];
        $qualifier = $parsed['qualifier'];

        if ($entityType === '') {
            return $this->errorResult('Invalid source: entity type is empty.');
        }

        $contract = $this->viewContract($entityType, $view);
        if ($contract === null) {
            return $this->errorResult("No view contract for '{$entityType}.{$view}'.");
        }

        // Check capability gate
        $requiredCap = $contract['capability'] ?? null;
        if ($requiredCap !== null && \function_exists('app')) {
            $app = \app();
            if ($app !== null && method_exists($app, 'capabilities') && !$app->capabilities()->has($requiredCap)) {
                return $this->errorResult("Insufficient permissions for '{$requiredCap}'.");
            }
        }

        // Build context for the capability call
        $limit = (int)($overrides['limit'] ?? $contract['limit'] ?? 25);
        $sortField = (string)($overrides['sort_field'] ?? $contract['sort']['field'] ?? 'created_at');
        $sortDir = (string)($overrides['sort_direction'] ?? $contract['sort']['direction'] ?? 'desc');
        $filters = is_array($overrides['filters'] ?? null) ? $overrides['filters'] : [];
        $offset = (int)($overrides['offset'] ?? 0);
        $cursor = isset($overrides['cursor']) ? (string)$overrides['cursor'] : null;
        $prevCursor = isset($overrides['prev_cursor']) ? (string)$overrides['prev_cursor'] : null;

        // Resolve key_field — always include it in query results for URL interpolation
        // even when it's not a display field (e.g. {id} in action_urls / row-click).
        $keyField = $contract['key_field'] ?? 'id';
        $displayFields = $contract['fields'] ?? '*';
        $queryFields = $displayFields;
        // Ensure key_field is always queried — needed for row-click and action URLs
        if (is_array($queryFields)) {
            if (!in_array($keyField, $queryFields, true)) {
                $queryFields[] = $keyField;
            }
            // Also ensure 'id' is present even if key_field is different
            if ($keyField !== 'id' && !in_array('id', $queryFields, true)) {
                $queryFields[] = 'id';
            }
        }

        $capabilityArgs = [
            'entity_type' => $entityType,
            'qualifier' => $qualifier,
            'view' => $view,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => ['field' => $sortField, 'direction' => $sortDir],
            'filters' => $filters,
            'fields' => $queryFields,
        ];
        if ($cursor !== null) { $capabilityArgs['cursor'] = $cursor; }
        if ($prevCursor !== null) { $capabilityArgs['prev_cursor'] = $prevCursor; }

        // Attempt to fetch via the capability bus
        // Normalize entity type: dots → underscores for capability IDs
        $sanitizedType = str_replace('.', '_', $entityType);
        $capabilityId = "entity.list.{$sanitizedType}";
        if (
            \function_exists('app')
            && ($app = \app()) !== null
            && method_exists($app, 'capabilities')
            && !$app->capabilities()->has($capabilityId)
            && $app->capabilities()->has($capabilityId . '@1')
        ) {
            $capabilityId .= '@1';
        }
        $rows = null;
        $total = 0;
        $error = null;

        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'cap')) {
                $result = $app->cap()->call($capabilityId, $capabilityArgs, [
                    'caller' => ['module' => 'kernel'],
                    'mode' => 'first',
                    'timeout_ms' => $contract['timeout_ms'] ?? 10000,
                ]);
                if (is_array($result)) {
                    // Normalise capability result: prefer 'rows' key; also accept
                    // 'data' envelope and bare array-of-arrays.
                    if (isset($result['rows']) && is_array($result['rows'])) {
                        $rows = $result['rows'];
                    } elseif (isset($result['data']) && is_array($result['data'])) {
                        $rows = $result['data'];
                    } elseif ($this->isListOfAssocArrays($result)) {
                        $rows = $result;
                    }
                    $total = (int)($result['total'] ?? (is_array($rows) ? count($rows) : 0));
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (\function_exists('write_log')) {
                $level = str_contains($error, 'not found') ? 'info' : 'warning';
                \write_log("EntityViewResolver: capability call failed for '{$capabilityId}'", $level, [
                    'source' => $source,
                    'view' => $view,
                    'error' => $error,
                ]);
            }
        }

        if ($rows === null) {
            return $this->errorResult($error ?? $contract['error_state'] ?? 'Data source unavailable.');
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'view' => $contract,
            'display_fields' => is_array($displayFields) ? $displayFields : ($rows[0] ?? [] ? array_keys($rows[0]) : []),
            'source' => $parsed,
            'error' => null,
        ];
    }

    /**
     * Get the resolved context as a ResolvedEntityContext value object.
     */
    public function resolvedContext(string $entityType, string $view): ?ResolvedEntityContext
    {
        $key = $this->viewKey($entityType, $view);
        if (isset($this->resolvedCache[$key])) {
            return $this->resolvedCache[$key];
        }
        $arr = $this->viewContract($entityType, $view);
        if ($arr === null) {
            return null;
        }
        return $this->resolvedCache[$key];
    }

    /**
     * Resolve a source + view and return a typed EntityListResult.
     *
     * Same as resolve() but returns an EntityListResult value object that
     * supports both total-based and cursor-based pagination.
     *
     * @param array<string, mixed> $overrides
     */
    public function resolveAsResult(string $source, string $view = 'compact', array $overrides = []): EntityListResult
    {
        $parsed = $this->parseSource($source);
        $entityType = $parsed['entity_type'];
        $qualifier = $parsed['qualifier'];

        if ($entityType === '') {
            return new EntityListResult(error: 'Invalid source: entity type is empty.');
        }

        $contract = $this->viewContract($entityType, $view);
        if ($contract === null) {
            return new EntityListResult(error: "No view contract for '{$entityType}.{$view}'.");
        }

        // Check capability gate
        $requiredCap = $contract['capability'] ?? null;
        if ($requiredCap !== null && \function_exists('app')) {
            $app = \app();
            if ($app !== null && method_exists($app, 'capabilities') && !$app->capabilities()->has($requiredCap)) {
                return new EntityListResult(error: "Insufficient permissions for '{$requiredCap}'.");
            }
        }

        $limit = (int)($overrides['limit'] ?? $contract['limit'] ?? 25);
        $sortField = (string)($overrides['sort_field'] ?? $contract['sort']['field'] ?? 'created_at');
        $sortDir = (string)($overrides['sort_direction'] ?? $contract['sort']['direction'] ?? 'desc');
        $filters = is_array($overrides['filters'] ?? null) ? $overrides['filters'] : [];

        $keyField = $contract['key_field'] ?? null;
        $displayFields = $contract['fields'] ?? '*';
        $queryFields = $displayFields;
        if ($keyField !== null && is_array($queryFields) && !in_array($keyField, $queryFields, true)) {
            $queryFields[] = $keyField;
        }

        $capabilityArgs = [
            'entity_type' => $entityType,
            'qualifier' => $qualifier,
            'view' => $view,
            'limit' => $limit,
            'sort' => ['field' => $sortField, 'direction' => $sortDir],
            'filters' => $filters,
            'fields' => $queryFields,
        ];

        $sanitizedType = str_replace('.', '_', $entityType);
        $capabilityId = "entity.list.{$sanitizedType}";

        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'cap')) {
                $result = $app->cap()->call($capabilityId, $capabilityArgs, [
                    'caller' => ['module' => 'kernel'],
                    'mode' => 'first',
                    'timeout_ms' => 10000,
                ]);
                if (is_array($result)) {
                    return EntityListResult::fromCapabilityResult($result);
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (\function_exists('write_log')) {
                $level = str_contains($error, 'not found') ? 'info' : 'warning';
                \write_log("EntityViewResolver: resolveAsResult failed for '{$capabilityId}'", $level, [
                    'source' => $source,
                    'view' => $view,
                    'error' => $error,
                ]);
            }
            return new EntityListResult(error: $error);
        }

        return new EntityListResult(error: $contract['error_state'] ?? 'Data source unavailable.');
    }

    /**
     * Check if a given source is known (has a registered entity type or view contract).
     */
    public function sourceExists(string $source): bool
    {
        $parsed = $this->parseSource($source);
        return $parsed['entity_type'] !== '';
    }

    /**
     * Resolve a single entity detail (ikb_entity_detail path).
     *
     * Calls entity.get.{type} via the capability bus, normalises the
     * result, and returns the entity with its view contract.
     *
     * @param array<string, mixed> $overrides
     * @return array{entity: array|null, view: array, source: array, error: string|null}
     */
    public function resolveDetail(string $source, string $entityId, string $view = 'detailed', array $overrides = []): array
    {
        $parsed = $this->parseSource($source);
        $entityType = $parsed['entity_type'];

        if ($entityType === '') {
            return $this->detailErrorResult('Invalid source: entity type is empty.');
        }

        $contract = $this->viewContract($entityType, $view);
        if ($contract === null) {
            return $this->detailErrorResult("No view contract for '{$entityType}.{$view}'.");
        }

        $requiredCap = $contract['capability'] ?? null;
        if ($requiredCap !== null && \function_exists('app')) {
            $app = \app();
            if ($app !== null && method_exists($app, 'capabilities') && !$app->capabilities()->has($requiredCap)) {
                return $this->detailErrorResult("Insufficient permissions for '{$requiredCap}'.");
            }
        }

        $sanitizedType = str_replace('.', '_', $entityType);
        $capabilityId = "entity.get.{$sanitizedType}";
        $entity = null;
        $error = null;

        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'cap')) {
                $result = $app->cap()->call($capabilityId, [
                    'entity_type' => $entityType,
                    'id'          => $entityId,
                    'view'        => $view,
                ] + $overrides, [
                    'caller' => ['module' => 'kernel'],
                    'mode'   => 'first',
                    'timeout_ms' => $contract['timeout_ms'] ?? 10000,
                ]);
                if (is_array($result)) {
                    // Strip capability envelope keys; keep entity data
                    $entity = $result;
                    // If the capability handler wrapped data in a 'data' key (standard convention),
                    // unwrap it so field access works directly (e.g., $entity['name'] not $entity['data']['name'])
                    if (is_array($entity) && array_key_exists('data', $entity) && count($entity) <= 3) {
                        $entity = $entity['data'];
                    }
                    unset($entity['ok'], $entity['error'], $entity['message']);
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (\function_exists('write_log')) {
                $level = str_contains($error, 'not found') ? 'info' : 'warning';
                \write_log("EntityViewResolver: detail fetch failed for '{$capabilityId}' id={$entityId}", $level, [
                    'source' => $source,
                    'id'     => $entityId,
                    'error'  => $error,
                ]);
            }
        }

        if ($entity === null || empty($entity)) {
            return $this->detailErrorResult($error ?? 'Entity not found.');
        }

        return [
            'entity' => $entity,
            'view'   => $contract,
            'source' => $parsed,
            'error'  => null,
        ];
    }

    /**
     * List all registered view contract keys.
     *
     * @return string[]
     */
    public function registeredViews(): array
    {
        return array_keys($this->viewContracts);
    }

    /**
     * Return the operationally registered view contracts for diagnostics.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registeredViewContracts(): array
    {
        $contracts = $this->viewContracts;
        ksort($contracts);
        return $contracts;
    }

    /**
     * Validate a requested sort field against the view contract's allowlist.
     *
     * Returns the sort field if allowed, or the default sort field if not.
     * This prevents arbitrary user-supplied sort parameters from reaching
     * the SQL ORDER BY clause.
     *
     * @param string      $entityType Entity type (e.g. 'guidance_case')
     * @param string      $view       View name (e.g. 'table')
     * @param string|null $requested  Sort field from the request (null = use default)
     * @param string|null $direction  Sort direction (validated to asc/desc)
     * @return array{field: string, direction: string}
     */
    public function validateSort(string $entityType, string $view, ?string $requested, ?string $direction = null): array
    {
        $contract = $this->viewContract($entityType, $view);
        $sortable = $contract['sortable_fields'] ?? [];
        $defaultSort = $contract['sort'] ?? ['field' => 'created_at', 'direction' => 'desc'];

        $field = $defaultSort['field'];
        $dir = in_array((string)$direction, ['asc', 'desc'], true) ? (string)$direction : $defaultSort['direction'];

        if ($requested !== null && $requested !== '') {
            if (isset($sortable[$requested]) || in_array($requested, $sortable, true)) {
                $field = $requested;
            } elseif (empty($sortable)) {
                // No allowlist defined — allow any field (backward compat)
                $field = $requested;
            }
            // If not allowed and allowlist exists, fall back to default
        }

        return ['field' => $field, 'direction' => $dir];
    }

    /**
     * Get the sortable fields allowlist for a view contract.
     *
     * @return array<string, string> field_name => sort_key (or field_name => field_name)
     */
    public function getSortableFields(string $entityType, string $view): array
    {
        $contract = $this->viewContract($entityType, $view);
        return $contract['sortable_fields'] ?? [];
    }

    // ── Internal helpers ──

    private function viewKey(string $entityType, string $view): string
    {
        return trim($entityType) . '.' . trim($view);
    }

    /**
     * @deprecated P2.1 Built-in defaults are being migrated to module-level
     *             registrations. This method is kept as a backward-compat
     *             fallback for entity types that no module has claimed yet.
     *             Logs a warning when invoked so migration progress can
     *             be tracked.
     * @return array<string, mixed>|null
     */
    private function builtinDefaults(string $entityType, string $view): ?array
    {
        // Log deprecation warning to track unresolved entity types
        if (\function_exists('write_log')) {
            \write_log("EntityViewResolver: built-in default used for '{$entityType}.{$view}' — migrate to module-level registration (P2.1)", 'warning', [
                'entity_type' => $entityType,
                'view' => $view,
            ]);
        }

        $compactDefaults = [
            // Legacy backward-compat aliases (short names)
            'orders' => ['fields' => ['id', 'status', 'total', 'created_at'], 'actions' => ['view'], 'limit' => 10, 'empty_state' => 'No orders yet.'],
            'products' => ['fields' => ['id', 'name', 'price', 'image'], 'actions' => ['view', 'add_to_cart'], 'limit' => 20, 'empty_state' => 'No products found.'],
            'cases' => ['fields' => ['id', 'title', 'status', 'updated_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No cases found.'],
            'ledger' => ['fields' => ['id', 'entry_type', 'amount', 'created_at'], 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No ledger entries.'],
            'appointments' => ['fields' => ['id', 'title', 'date', 'status'], 'actions' => ['view', 'cancel'], 'limit' => 10, 'empty_state' => 'No appointments.'],
            'tickets' => ['fields' => ['id', 'subject', 'status', 'created_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No tickets.'],
            'weather' => ['fields' => ['date', 'high_c', 'low_c', 'condition'], 'actions' => [], 'limit' => 5, 'empty_state' => 'No weather data.'],
        ];

        $base = $compactDefaults[$entityType] ?? ['fields' => '*', 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No records found.'];

        return [
            'entity_type' => $entityType,
            'view' => $view,
            'fields' => $base['fields'] ?? '*',
            'actions' => $base['actions'] ?? [],
            'action_urls' => $base['action_urls'] ?? [],
            'action_methods' => $base['action_methods'] ?? [],
            'action_confirm' => $base['action_confirm'] ?? [],
            'action_show_if' => $base['action_show_if'] ?? [],
            'action_labels' => $base['action_labels'] ?? [],
            'renderers' => $base['renderers'] ?? [],
            'field_contracts' => $base['field_contracts'] ?? [],
            'limit' => $base['limit'] ?? 25,
            'sort' => ['field' => 'created_at', 'direction' => 'desc'],
            'empty_state' => $base['empty_state'] ?? 'No records found.',
            'error_state' => 'Unable to load data.',
            'exportable' => false,
            'capability' => null,
            'provider' => 'kernel.builtin',
        ];
    }

    /**
     * Get a generic fallback contract for any entity type + view combination.
     * Uses '*' for fields so the template can render whatever data is available.
     * This is the absolute last resort when no view contract exists.
     *
     * @return array<string, mixed>
     */
    private function genericFallbackContract(string $entityType, string $view): array
    {
        if (\function_exists('write_log')) {
            \write_log("EntityViewResolver: generic fallback used for '{$entityType}.{$view}' — no view contract registered", 'warning', [
                'entity_type' => $entityType,
                'view' => $view,
            ]);
        }

        return [
            'entity_type'     => $entityType,
            'view'            => $view,
            'fields'          => '*',
            'actions'         => ['view'],
            'action_urls'     => [],
            'action_methods'  => [],
            'action_confirm'  => [],
            'action_show_if'  => [],
            'action_labels'   => [],
            'renderers'       => [],
            'field_contracts' => [],
            'limit'           => 25,
            'sort'            => ['field' => 'created_at', 'direction' => 'desc'],
            'empty_state'     => 'No records found.',
            'error_state'     => 'Unable to load data.',
            'exportable'      => false,
            'capability'      => null,
            'provider'        => 'kernel.generic',
            'key_field'       => null,
            'timeout_ms'      => 10000,
        ];
    }

    /**
     * @return array{rows: array, total: int, view: array, source: array, error: string}
     */
    private function errorResult(string $message): array
    {
        return [
            'rows' => [],
            'total' => 0,
            'view' => ['empty_state' => $message, 'error_state' => $message],
            'source' => ['entity_type' => '', 'qualifier' => ''],
            'error' => $message,
        ];
    }

    /**
     * @return array{entity: null, view: array, source: array, error: string}
     */
    private function detailErrorResult(string $message): array
    {
        return [
            'entity' => null,
            'view'   => ['empty_state' => $message, 'error_state' => $message],
            'source' => ['entity_type' => '', 'qualifier' => ''],
            'error'  => $message,
        ];
    }

    /**
     * Check if a value is a list of associative arrays (e.g. rows from a DB query).
     */
    private function isListOfAssocArrays(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) return false;
        if (!isset($value[0]) || !is_array($value[0])) return false;
        // Must be a sequential array (0-indexed)
        return array_keys($value) === range(0, count($value) - 1);
    }
}
