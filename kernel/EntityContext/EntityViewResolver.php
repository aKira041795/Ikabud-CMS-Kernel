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
            // P4: sortable field declarations — field_name => sort_key (DB column)
            'sortable_fields' => [],
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
                    'timeout_ms' => 10000,
                ]);
                if (is_array($result)) {
                    // Strip capability envelope keys; keep entity data
                    $entity = $result;
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
            'wms_location' => ['fields' => ['id', 'name', 'type', 'is_staging'], 'actions' => ['edit'], 'limit' => 20, 'empty_state' => 'No locations.'],
            'ecommerce_product' => ['fields' => ['id', 'name', 'price', 'image', 'stock_status'], 'actions' => ['view', 'add_to_cart'], 'limit' => 20, 'empty_state' => 'No products found.'],
            'ecommerce_order' => ['fields' => ['id', 'order_number', 'status', 'total', 'created_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No orders yet.'],
            // Phase 3 — attendance-wage entity-view adoption (June 2026)
            'attendance_record' => ['fields' => ['id', 'employee_name', 'store_name', 'clock_in', 'clock_out', 'hours', 'status'], 'actions' => ['view', 'edit'], 'action_urls' => ['view' => '/admin/attendance?record={id}', 'edit' => '/admin/attendance?record={id}'], 'renderers' => ['clock_in' => 'datetime:time', 'clock_out' => 'datetime:time', 'hours' => 'string', 'status' => 'badge:{"active":"Clocked In|green","completed":"Done|blue","edited":"Edited|amber"}'], 'field_contracts' => ['hours' => ['editable' => 'true', 'update_capability' => 'attendance.record.hours.update@1']], 'limit' => 30, 'empty_state' => 'No attendance records found.'],
            'employee_profile' => ['fields' => ['id', 'first_name', 'last_name', 'position', 'department', 'salary_type', 'employment_status'], 'actions' => ['view', 'edit'], 'action_urls' => ['view' => '/admin/wage/employees/{id}/view', 'edit' => '/admin/wage/employees/{id}'], 'action_labels' => ['view' => 'View'], 'renderers' => ['salary_type' => 'badge:{"hourly":"Hourly|blue","daily":"Daily|amber","monthly":"Monthly|purple","fixed":"Fixed|gray"}', 'employment_status' => 'badge:{"probationary":"Probationary|amber","regular":"Regular|green","contractual":"Contractual|blue","part_time":"Part-Time|gray"}'], 'limit' => 25, 'empty_state' => 'No employee profiles yet.'],
            'payroll_period' => ['fields' => ['id', 'period_name', 'start_date', 'end_date', 'status', 'total_net_pay'], 'actions' => ['view'], 'action_urls' => ['view' => '/admin/wage/reports/{id}'], 'renderers' => ['total_net_pay' => 'money:2', 'status' => 'badge:{"draft":"Draft|gray","processing":"Processing|blue","approved":"Approved|green","completed":"Completed|green","cancelled":"Cancelled|red"}'], 'limit' => 12, 'empty_state' => 'No payroll periods yet.'],
            'salary_computation' => ['fields' => ['id', 'employee_name', 'period_name', 'gross_pay', 'total_deductions', 'net_pay', 'status'], 'actions' => ['view', 'approve'], 'action_urls' => ['view' => '/admin/wage/computations?id={id}', 'approve' => '/admin/wage/computations?id={id}'], 'renderers' => ['gross_pay' => 'money:2', 'total_deductions' => 'money:2', 'net_pay' => 'money:2', 'status' => 'badge:{"computed":"Computed|amber","approved":"Approved|green","paid":"Paid|blue","cancelled":"Cancelled|red"}'], 'limit' => 25, 'empty_state' => 'No salary computations found.'],
            'salary_adjustment' => ['fields' => ['id', 'employee_name', 'adjustment_type', 'amount', 'status', 'effective_date', 'approval_date', 'applied_date'], 'actions' => ['edit', 'view', 'approve'], 'action_urls' => ['edit' => '/admin/wage/adjustments/{id}', 'view' => '/admin/wage/adjustments/{id}', 'approve' => '/api/v1/wage/adjustments/{id}/approve'], 'action_methods' => ['approve' => 'post'], 'action_confirm' => ['approve' => 'Approve this adjustment?'], 'action_show_if' => ['edit' => 'status == "pending"', 'view' => 'status != "pending"', 'approve' => 'status == "pending"'], 'renderers' => ['amount' => 'money:2', 'status' => 'badge:{"pending":"Pending|amber","approved":"Approved|green","applied":"Applied|blue","rejected":"Rejected|red"}'], 'limit' => 20, 'empty_state' => 'No salary adjustments found.'],
            'employee_deduction' => ['fields' => ['id', 'employee_name', 'amount', 'description', 'status', 'deduction_date', 'source'], 'actions' => ['view'], 'action_urls' => ['view' => '/admin/wage/deductions?id={id}'], 'renderers' => ['amount' => 'money:2', 'status' => 'badge:{"pending":"Pending|amber","approved":"Approved|green","deducted":"Deducted|blue"}'], 'limit' => 20, 'empty_state' => 'No employee deductions found.'],
            'holiday' => ['fields' => ['id', 'holiday_name', 'holiday_date', 'holiday_type', 'pay_multiplier'], 'actions' => ['edit', 'delete'], 'action_urls' => ['edit' => '/admin/wage/holidays?edit={id}', 'delete' => '/api/v1/wage/holidays/{id}/delete'], 'action_methods' => ['delete' => 'post'], 'action_confirm' => ['delete' => 'Delete this holiday?'], 'renderers' => ['holiday_date' => 'datetime:date', 'holiday_type' => 'badge:{"regular":"Regular|green","special":"Special|blue","special_working":"Working|amber"}'], 'limit' => 30, 'empty_state' => 'No holidays configured.'],
            'cash_advance' => ['fields' => ['id', 'employee_name', 'amount', 'balance', 'status', 'request_date', 'approved_at'], 'actions' => ['view', 'approve'], 'action_urls' => ['view' => '/admin/wage/cash-advances?id={id}', 'approve' => '/api/v1/wage/cash-advances/{id}/approve'], 'action_methods' => ['approve' => 'post'], 'action_show_if' => ['approve' => 'status == "pending"'], 'renderers' => ['amount' => 'money:2', 'balance' => 'money:2', 'status' => 'badge:{"pending":"Pending|amber","approved":"Approved|green","active":"Active|blue","paid":"Paid|green","rejected":"Rejected|red"}', 'request_date' => 'datetime:date', 'approved_at' => 'datetime:date'], 'limit' => 20, 'empty_state' => 'No cash advance requests.'],
            'employee_schedule' => ['fields' => ['id', 'employee_name', 'position', 'department', 'days_label', 'shift_type', 'dayoff_count', 'total_days'], 'actions' => ['edit'], 'action_urls' => ['edit' => '/admin/wage/schedules?id={id}'], 'renderers' => ['shift_type' => 'badge:{"day":"Day|blue","night":"Night|purple","rotating":"Rotating|amber"}'], 'limit' => 30, 'empty_state' => 'No employee schedules yet.'],
            'office_location' => ['fields' => ['id', 'name', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active'], 'actions' => ['edit', 'delete'], 'action_urls' => ['edit' => '/admin/wage/locations/{id}', 'delete' => '/api/v1/wage/locations/{id}/delete'], 'action_methods' => ['delete' => 'post'], 'action_confirm' => ['delete' => 'Delete this location?'], 'renderers' => ['is_active' => 'boolean'], 'limit' => 50, 'empty_state' => 'No office locations configured yet.'],
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
