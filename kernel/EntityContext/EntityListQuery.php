<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * EntityListQuery — safe entity-list query builder for capability handlers.
 *
 * Replaces hardcoded SQL SELECT in entity.list.* handlers with a field-whitelist
 * approach where the view contract (via $payload['fields']) is the single source
 * of truth for which columns are queried.
 *
 * Usage in a module handler:
 *
 *   function my_cap_entity_list_orders_1(mixed $payload, ...): array {
 *       $db = module('my-module')->db();
 *       return EntityListQuery::run($db, 'orders', [
 *           'id'         => 'order_id',
 *           'customer'   => 'customer_name',
 *           'total'      => 'ROUND(total_cents/100, 2)',
 *           'status'     => 'order_status',
 *           'created_at' => 'created_at',
 *       ], $payload, 'tenant_id = :tid', [':tid' => tenantId()]);
 *   }
 *
 * The column map is a whitelist — only fields declared here can appear in
 * the SELECT.  If $payload['fields'] is ['*'] or missing, all mapped columns
 * are selected.
 */
class EntityListQuery
{
    /**
     * Run an entity-list query built from the payload fields and a column map.
     *
     * @param \PDO   $db          Database connection (module-scoped).
     * @param string $from        FROM clause — table name or "table JOIN …".
     * @param array  $columnMap   field_name => SQL expression (whitelist).
     * @param array  $payload     The raw $payload from the capability handler
     *                            (must contain 'fields', 'sort', 'limit', 'filters').
     * @param string $baseWhere   Optional base WHERE fragment (without "WHERE").
     * @param array  $baseParams  Bound params for $baseWhere.
     * @param array  $allowedSort Explicit list of sortable columns (defaults to
     *                            array_keys($columnMap)).
     * @return array{rows: array<int, array>, total: int, error?: string}
     */
    public static function run(
        \PDO $db,
        string $from,
        array $columnMap,
        array $payload,
        string $baseWhere = '',
        array $baseParams = [],
        array $allowedSort = []
    ): array {
        // ── 1. Resolve requested fields ──
        $requested = $payload['fields'] ?? '*';
        if ($requested === '*' || $requested === ['*']) {
            $fields = array_keys($columnMap);
        } elseif (is_array($requested)) {
            // Only keep fields that exist in the column map (whitelist)
            $fields = array_values(array_filter(
                $requested,
                fn($f) => is_string($f) && array_key_exists($f, $columnMap)
            ));
            // If nothing survived, fall back to all mapped columns
            if (empty($fields)) {
                $fields = array_keys($columnMap);
            }
        } else {
            $fields = array_keys($columnMap);
        }

        // ── 2. Build SELECT clause ──
        $selectParts = [];
        foreach ($fields as $field) {
            $expr = $columnMap[$field];
            // If the expression is a plain column name (no functions/operators),
            // quote it.  Otherwise use it as-is (the handler is responsible for
            // safe SQL in expressions).
            if ($expr === $field || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expr)) {
                $selectParts[] = "`{$expr}` AS `{$field}`";
            } else {
                $selectParts[] = "{$expr} AS `{$field}`";
            }
        }
        $selectClause = implode(', ', $selectParts);

        // ── 3. Build WHERE clause ──
        $whereClauses = [];
        $params = $baseParams;

        if ($baseWhere !== '') {
            $whereClauses[] = "({$baseWhere})";
        }

        // Merge payload filters
        $filters = $payload['filters'] ?? [];
        if (is_array($filters)) {
            foreach ($filters as $key => $value) {
                // Skip fields not in column map (prevent SQL injection via filter keys)
                if (!array_key_exists($key, $columnMap)) {
                    continue;
                }
                $colExpr = $columnMap[$key];
                // Only allow simple column-name filters (not expressions)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colExpr)) {
                    continue;
                }
                if (is_array($value)) {
                    // IN (…) filter
                    if (empty($value)) { continue; }
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $ph = ":f_{$key}_{$i}";
                        $placeholders[] = $ph;
                        $params[$ph] = $v;
                    }
                    $inList = implode(', ', $placeholders);
                    $whereClauses[] = "`{$colExpr}` IN ({$inList})";
                } else {
                    $ph = ":f_{$key}";
                    $whereClauses[] = "`{$colExpr}` = {$ph}";
                    $params[$ph] = $value;
                }
            }
        }

        $whereSQL = '';
        if (!empty($whereClauses)) {
            $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
        }

        // ── 4. Build ORDER BY ──
        $sort = $payload['sort'] ?? [];
        $sortField = $sort['field'] ?? '';
        $sortDir = strtoupper($sort['direction'] ?? 'DESC');
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }

        $effectiveAllowedSort = !empty($allowedSort)
            ? $allowedSort
            : array_keys($columnMap);

        $orderSQL = '';
        if ($sortField !== '' && in_array($sortField, $effectiveAllowedSort, true)) {
            $colExpr = $columnMap[$sortField] ?? $sortField;
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colExpr)) {
                $orderSQL = "ORDER BY `{$colExpr}` {$sortDir}";
            } else {
                $orderSQL = "ORDER BY {$colExpr} {$sortDir}";
            }
        }

        // ── 5. Build LIMIT ──
        $limit = max(1, min((int)($payload['limit'] ?? 25), 1000));

        // ── 6. Execute query ──
        try {
            $sql = "SELECT {$selectClause} FROM {$from} {$whereSQL} {$orderSQL} LIMIT {$limit}";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Count (uses same WHERE, no ORDER/LIMIT)
            $countSQL = "SELECT COUNT(*) FROM {$from} {$whereSQL}";
            $countStmt = $db->prepare($countSQL);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            return ['rows' => $rows, 'total' => $total];
        } catch (\Throwable $e) {
            if (\function_exists('write_log')) {
                \write_log("EntityListQuery: query failed for '{$from}'", 'error', [
                    'sql' => $sql ?? 'N/A',
                    'error' => $e->getMessage(),
                    'fields' => $fields,
                ]);
            }
            return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }
}
