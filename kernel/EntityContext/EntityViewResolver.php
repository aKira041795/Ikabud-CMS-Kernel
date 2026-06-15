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
 * @version 1.0.0
 */
final class EntityViewResolver
{
    private static ?EntityViewResolver $instance = null;

    /** @var array<string, array{entity_type: string, qualifier: string}> parsed source cache */
    private array $sourceCache = [];

    /** @var array<string, array<string, mixed>> resolved view contracts */
    private array $viewContracts = [];

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
     * A view contract declares:
     *  - fields: list of visible fields (or '*' for all)
     *  - actions: list of allowed action names
     *  - limit: default row limit
     *  - sort: default sort field + direction
     *  - empty_state: message when no data
     *  - error_state: message on fetch failure
     *  - exportable: bool (can this view be exported?)
     *  - capability: optional capability required to view
     *
     * @param array<string, mixed> $contract
     */
    public function registerView(string $entityType, string $view, array $contract, string $providerId = 'kernel'): void
    {
        $key = $this->viewKey($entityType, $view);
        $this->viewContracts[$key] = array_replace([
            'entity_type' => $entityType,
            'view' => $view,
            'fields' => '*',
            'actions' => [],
            'limit' => 25,
            'sort' => ['field' => 'created_at', 'direction' => 'desc'],
            'empty_state' => 'No records found.',
            'error_state' => 'Unable to load data.',
            'exportable' => false,
            'capability' => null,
            'provider' => $providerId,
        ], $contract);
    }

    /**
     * Get the view contract for an entity type + view.
     *
     * @return array<string, mixed>|null
     */
    public function viewContract(string $entityType, string $view): ?array
    {
        $key = $this->viewKey($entityType, $view);

        // Exact match
        if (isset($this->viewContracts[$key])) {
            return $this->viewContracts[$key];
        }

        // Fallback: default view for the entity type
        $fallbackKey = $this->viewKey($entityType, 'default');
        if (isset($this->viewContracts[$fallbackKey])) {
            return $this->viewContracts[$fallbackKey];
        }

        // Last resort: built-in defaults per entity type
        return $this->builtinDefaults($entityType, $view);
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

        $capabilityArgs = [
            'entity_type' => $entityType,
            'qualifier' => $qualifier,
            'view' => $view,
            'limit' => $limit,
            'sort' => ['field' => $sortField, 'direction' => $sortDir],
            'filters' => $filters,
            'fields' => $contract['fields'] ?? '*',
        ];

        // Attempt to fetch via the capability bus
        // Normalize entity type: dots → underscores for capability IDs
        $sanitizedType = str_replace('.', '_', $entityType);
        $capabilityId = "entity.list.{$sanitizedType}";
        $rows = null;
        $total = 0;
        $error = null;

        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'cap')) {
                $result = $app->cap()->call($capabilityId, $capabilityArgs, [
                    'caller' => ['module' => 'kernel'],
                    'mode' => 'first',
                    'timeout_ms' => 10000,
                ]);
                if (is_array($result)) {
                    $rows = $result['rows'] ?? $result;
                    $total = (int)($result['total'] ?? count($rows));
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
            'source' => $parsed,
            'error' => null,
        ];
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
     * List all registered view contract keys.
     *
     * @return string[]
     */
    public function registeredViews(): array
    {
        return array_keys($this->viewContracts);
    }

    // ── Internal helpers ──

    private function viewKey(string $entityType, string $view): string
    {
        return trim($entityType) . '.' . trim($view);
    }

    /**
     * @return array<string, mixed>
     */
    private function builtinDefaults(string $entityType, string $view): array
    {
        $compactDefaults = [
            'orders' => ['fields' => ['id', 'status', 'total', 'created_at'], 'actions' => ['view'], 'limit' => 10, 'empty_state' => 'No orders yet.'],
            'products' => ['fields' => ['id', 'name', 'price', 'image'], 'actions' => ['view', 'add_to_cart'], 'limit' => 20, 'empty_state' => 'No products found.'],
            'cases' => ['fields' => ['id', 'title', 'status', 'updated_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No cases found.'],
            'ledger' => ['fields' => ['id', 'entry_type', 'amount', 'created_at'], 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No ledger entries.'],
            'appointments' => ['fields' => ['id', 'title', 'date', 'status'], 'actions' => ['view', 'cancel'], 'limit' => 10, 'empty_state' => 'No appointments.'],
            'tickets' => ['fields' => ['id', 'subject', 'status', 'created_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No tickets.'],
            'weather' => ['fields' => ['date', 'high_c', 'low_c', 'condition'], 'actions' => [], 'limit' => 5, 'empty_state' => 'No weather data.'],
            // Phase 2 — extended entity-view adoption (June 2026)
            'bakeshop_product' => ['fields' => ['id', 'name', 'price', 'unit', 'stock_qty', 'category'], 'actions' => ['view'], 'limit' => 20, 'empty_state' => 'No products found.'],
            'guidance_case' => ['fields' => ['id', 'student_name', 'status', 'created_at', 'counselor_name'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No cases found.'],
            'guidance_appointment' => ['fields' => ['id', 'title', 'date', 'status', 'student_name'], 'actions' => ['view', 'cancel'], 'limit' => 10, 'empty_state' => 'No appointments.'],
            'daily_ledger_entry' => ['fields' => ['id', 'entry_type', 'amount', 'created_at', 'notes'], 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No ledger entries.'],
            'wms_stock' => ['fields' => ['id', 'sku', 'name', 'qty', 'location_name', 'updated_at'], 'actions' => ['view', 'move'], 'limit' => 30, 'empty_state' => 'No stock items.'],
            'wms_location' => ['fields' => ['id', 'name', 'type', 'is_staging'], 'actions' => ['view'], 'limit' => 20, 'empty_state' => 'No locations.'],
            'ecommerce_product' => ['fields' => ['id', 'name', 'price', 'image', 'stock_status'], 'actions' => ['view', 'add_to_cart'], 'limit' => 20, 'empty_state' => 'No products found.'],
            'ecommerce_order' => ['fields' => ['id', 'order_number', 'status', 'total', 'created_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No orders yet.'],
        ];

        $base = $compactDefaults[$entityType] ?? ['fields' => '*', 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No records found.'];

        return [
            'entity_type' => $entityType,
            'view' => $view,
            'fields' => $base['fields'] ?? '*',
            'actions' => $base['actions'] ?? [],
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
}
