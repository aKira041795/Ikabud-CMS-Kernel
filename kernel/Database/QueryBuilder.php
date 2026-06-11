<?php
/**
 * Ikabud Kernel — Lightweight Query Builder
 * 
 * Fluent API for SELECT / INSERT / UPDATE / DELETE with automatic
 * tenant_id scoping when multi-tenancy is active.
 * 
 * Usage:
 *   $rows = db()->table('products')->where('is_active', 1)->get();
 *   db()->table('products')->insert(['name' => 'Pandesal', 'price' => 5.00]);
 *   db()->table('products')->where('id', 7)->update(['price' => 6.00]);
 *   db()->table('products')->where('id', 7)->delete();
 *   $count = db()->table('products')->where('is_active', 1)->count();
 *   $row   = db()->table('products')->where('id', 7)->first();
 * 
 * Joins:
 *   db()->table('daily_ledger l')
 *       ->join('products p', 'p.id = l.product_id')
 *       ->leftJoin('users u', 'u.id = l.encoded_by')
 *       ->select('l.*', 'p.name as product_name', 'u.full_name')
 *       ->where('l.ledger_date', '2025-01-01')
 *       ->get();
 * 
 * Raw:
 *   db()->raw('SELECT * FROM products WHERE price > ?', [10.00]);
 * 
 * Tenant scoping:
 *   When a tenant_id is set on the builder (via setTenantId or auto-injected
 *   by the App layer), every query automatically adds a WHERE tenant_id = ?
 *   clause, and every INSERT automatically includes the tenant_id column.
 *   Call ->unscoped() to bypass for cross-tenant queries (admin use).
 * 
 * @package Ikabud\Kernel\Database
 * @version 1.0.0
 */

namespace Ikabud\Kernel\Database;

use PDO;
use PDOStatement;

class QueryBuilder
{
    private PDO $pdo;
    private ?int $tenantId = null;
    private bool $tenantScoped = true;

    // Query state
    private string $table = '';
    private string $tableAlias = '';
    private array $selects = [];
    private array $joins = [];
    private array $wheres = [];
    private array $bindings = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private ?string $having = null;
    private array $havingBindings = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;

    public function __construct(PDO $pdo, ?int $tenantId = null)
    {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
    }

    // ── Factory ──────────────────────────────────────────────────────

    /**
     * Set the target table. Supports aliasing: 'products p'
     */
    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->reset();

        // Parse alias: "products p" or "products AS p"
        if (preg_match('/^(\S+)\s+(?:AS\s+)?(\S+)$/i', $table, $m)) {
            $clone->table = $m[1];
            $clone->tableAlias = $m[2];
        } else {
            $clone->table = $table;
            $clone->tableAlias = '';
        }

        return $clone;
    }

    /**
     * Set tenant ID for automatic scoping.
     */
    public function setTenantId(?int $id): self
    {
        $this->tenantId = $id;
        return $this;
    }

    /**
     * Bypass tenant scoping for this query.
     */
    public function unscoped(): self
    {
        $this->tenantScoped = false;
        return $this;
    }

    // ── SELECT ───────────────────────────────────────────────────────

    /**
     * Specify columns to select.
     */
    public function select(string ...$columns): self
    {
        $this->selects = $columns;
        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    // ── JOIN ─────────────────────────────────────────────────────────

    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $this->joins[] = strtoupper($type) . ' JOIN ' . $table . ' ON ' . $on;
        return $this;
    }

    public function leftJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    public function rightJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'RIGHT');
    }

    // ── WHERE ────────────────────────────────────────────────────────

    /**
     * Add a WHERE condition.
     * 
     * Signatures:
     *   where('status', 'active')           → status = ?
     *   where('price', '>', 10)             → price > ?
     *   where('id', 'IN', [1,2,3])          → id IN (?,?,?)
     *   where('deleted_at', 'IS NULL')       → deleted_at IS NULL
     *   where('deleted_at', 'IS NOT NULL')   → deleted_at IS NOT NULL
     *   whereRaw('YEAR(created_at) = ?', [2025])
     */
    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        if ($value === null && $operatorOrValue !== null) {
            // Two-arg: where('col', 'val') → col = ?
            if (is_string($operatorOrValue) && in_array(strtoupper($operatorOrValue), ['IS NULL', 'IS NOT NULL'], true)) {
                $this->wheres[] = $column . ' ' . strtoupper($operatorOrValue);
                return $this;
            }
            $this->wheres[] = $column . ' = ?';
            $this->bindings[] = $operatorOrValue;
        } else {
            // Three-arg: where('col', '>', val) or where('col', 'IN', [1,2,3])
            $op = strtoupper((string) $operatorOrValue);
            if ($op === 'IN' || $op === 'NOT IN') {
                if (!is_array($value) || empty($value)) {
                    // Empty IN → always false
                    $this->wheres[] = ($op === 'IN') ? '0=1' : '1=1';
                } else {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $this->wheres[] = $column . ' ' . $op . ' (' . $placeholders . ')';
                    foreach ($value as $v) {
                        $this->bindings[] = $v;
                    }
                }
            } elseif ($op === 'BETWEEN') {
                $this->wheres[] = $column . ' BETWEEN ? AND ?';
                $this->bindings[] = $value[0];
                $this->bindings[] = $value[1];
            } elseif ($op === 'LIKE') {
                $this->wheres[] = $column . ' LIKE ?';
                $this->bindings[] = $value;
            } else {
                $this->wheres[] = $column . ' ' . $operatorOrValue . ' ?';
                $this->bindings[] = $value;
            }
        }
        return $this;
    }

    /**
     * Add a raw WHERE clause.
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = $sql;
        foreach ($bindings as $b) {
            $this->bindings[] = $b;
        }
        return $this;
    }

    /**
     * WHERE column IS NULL.
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = $column . ' IS NULL';
        return $this;
    }

    /**
     * WHERE column IS NOT NULL.
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = $column . ' IS NOT NULL';
        return $this;
    }

    // ── ORDER / GROUP / LIMIT ────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy[] = $column . ' ' . $dir;
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    public function having(string $sql, array $bindings = []): self
    {
        $this->having = $sql;
        $this->havingBindings = $bindings;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    // ── Pagination helper ────────────────────────────────────────────

    /**
     * Paginate results. Returns ['data' => [...], 'total' => N, 'page' => N, 'pages' => N, 'per_page' => N]
     */
    public function paginate(int $perPage = 25, int $page = 1): array
    {
        $page = max(1, $page);
        $total = $this->count();
        $pages = (int) ceil($total / $perPage);

        $data = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    // ── Terminal Methods ─────────────────────────────────────────────

    /**
     * Execute SELECT and return all rows.
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->execute($sql, $bindings, true);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Execute SELECT and return first row or null.
     */
    public function first(): ?array
    {
        $this->limit = 1;
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->execute($sql, $bindings, true);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Return a single column value from the first row.
     */
    public function value(string $column): mixed
    {
        $this->selects = [$column];
        $this->limit = 1;
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->execute($sql, $bindings, true);
        $val = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * Return an array of a single column's values.
     */
    public function pluck(string $column): array
    {
        $this->selects = [$column];
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->execute($sql, $bindings, true);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * COUNT query.
     */
    public function count(string $column = '*'): int
    {
        $saved = $this->selects;
        $savedLimit = $this->limit;
        $savedOffset = $this->offset;
        $savedOrder = $this->orderBy;

        $this->selects = ["COUNT({$column}) as _cnt"];
        $this->limit = null;
        $this->offset = null;
        $this->orderBy = [];

        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->execute($sql, $bindings, true);
        $result = (int) $stmt->fetchColumn();

        $this->selects = $saved;
        $this->limit = $savedLimit;
        $this->offset = $savedOffset;
        $this->orderBy = $savedOrder;

        return $result;
    }

    /**
     * Check if any rows match.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ── INSERT ───────────────────────────────────────────────────────

    /**
     * Insert a single row. Returns the last insert ID.
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data = $this->injectTenant($data);
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$placeholders})";
        $this->execute($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert multiple rows. Returns number of rows inserted.
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if (empty($rows)) return 0;

        // Inject tenant into each row
        $rows = array_map(fn($r) => $this->injectTenant($r), $rows);

        $columns = array_keys($rows[0]);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES {$allPlaceholders}";
        $stmt = $this->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Insert or update on duplicate key.
     * @param array<string, mixed> $data     Columns to insert
     * @param array<string, mixed> $update   Columns to update on conflict
     */
    public function upsert(array $data, array $update): int
    {
        $data = $this->injectTenant($data);
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        $updateParts = [];
        $updateBindings = [];
        foreach ($update as $col => $val) {
            $updateParts[] = "`{$col}` = ?";
            $updateBindings[] = $val;
        }

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$placeholders}) "
             . "ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

        $stmt = $this->execute($sql, array_merge(array_values($data), $updateBindings));
        return (int) $this->pdo->lastInsertId();
    }

    // ── UPDATE ───────────────────────────────────────────────────────

    /**
     * Update rows matching the current WHERE conditions.
     * @param array<string, mixed> $data
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        if (empty($data)) return 0;

        $setParts = [];
        $setBindings = [];
        foreach ($data as $col => $val) {
            $setParts[] = "`{$col}` = ?";
            $setBindings[] = $val;
        }

        $whereClause = $this->buildWhereClause();
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts) . $whereClause;
        $allBindings = array_merge($setBindings, $this->bindings);

        $stmt = $this->execute($sql, $allBindings, true);
        return $stmt->rowCount();
    }

    /**
     * Increment a numeric column.
     */
    public function increment(string $column, int $amount = 1, array $extra = []): int
    {
        $setParts = ["`{$column}` = `{$column}` + ?"];
        $setBindings = [$amount];
        foreach ($extra as $col => $val) {
            $setParts[] = "`{$col}` = ?";
            $setBindings[] = $val;
        }

        $whereClause = $this->buildWhereClause();
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts) . $whereClause;
        $stmt = $this->execute($sql, array_merge($setBindings, $this->bindings), true);
        return $stmt->rowCount();
    }

    /**
     * Decrement a numeric column.
     */
    public function decrement(string $column, int $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    // ── DELETE ────────────────────────────────────────────────────────

    /**
     * Delete rows matching the current WHERE conditions.
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        $whereClause = $this->buildWhereClause();
        if ($whereClause === '') {
            throw new \RuntimeException('Refusing to DELETE without a WHERE clause. Use deleteAll() to confirm.');
        }

        $sql = "DELETE FROM `{$this->table}`" . $whereClause;
        $stmt = $this->execute($sql, $this->bindings, true);
        return $stmt->rowCount();
    }

    /**
     * Explicitly delete all rows (no WHERE required).
     */
    public function deleteAll(): int
    {
        $this->tenantScoped = false; // intentional — explicit call
        $sql = "DELETE FROM `{$this->table}`";
        $stmt = $this->execute($sql, []);
        return $stmt->rowCount();
    }

    // ── RAW ──────────────────────────────────────────────────────────

    /**
     * Execute a raw SQL query.
     * @return array<int, array<string, mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Execute a raw statement (INSERT/UPDATE/DELETE).
     * @return int Affected rows
     */
    public function rawExec(string $sql, array $bindings = []): int
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    // ── Transaction helpers ──────────────────────────────────────────

    /**
     * Execute a callback within a database transaction.
     * Commits on success, rolls back on exception.
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function reset(): void
    {
        $this->selects = [];
        $this->joins = [];
        $this->wheres = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = null;
        $this->havingBindings = [];
        $this->limit = null;
        $this->offset = null;
        $this->distinct = false;
        $this->tenantScoped = true;
    }

    /**
     * Build the full SELECT SQL + bindings.
     */
    private function buildSelect(): array
    {
        $cols = empty($this->selects) ? '*' : implode(', ', $this->selects);
        $distinct = $this->distinct ? 'DISTINCT ' : '';

        $tableRef = $this->tableAlias
            ? "`{$this->table}` {$this->tableAlias}"
            : "`{$this->table}`";

        $sql = "SELECT {$distinct}{$cols} FROM {$tableRef}";

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $bindings = $this->bindings;

        $whereClause = $this->buildWhereClause();
        $sql .= $whereClause;

        if ($this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->having !== null) {
            $sql .= ' HAVING ' . $this->having;
            $bindings = array_merge($bindings, $this->havingBindings);
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return [$sql, $bindings];
    }

    /**
     * Build WHERE clause including tenant scoping.
     */
    private function buildWhereClause(): string
    {
        $parts = $this->wheres;

        // Auto-inject tenant scoping
        if ($this->tenantId !== null && $this->tenantScoped) {
            $col = $this->tableAlias ? "{$this->tableAlias}.tenant_id" : 'tenant_id';
            // Prepend tenant condition (highest priority)
            array_unshift($parts, "{$col} = ?");
            // Tenant binding will be added at execution time via getAllBindings
        }

        if (empty($parts)) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $parts);
    }

    /**
     * Get all bindings including tenant_id.
     */
    private function getAllBindings(array $bindings): array
    {
        if ($this->tenantId !== null && $this->tenantScoped) {
            array_unshift($bindings, $this->tenantId);
        }
        return $bindings;
    }

    /**
     * Inject tenant_id into INSERT data if scoping is active.
     */
    private function injectTenant(array $data): array
    {
        if ($this->tenantId !== null && $this->tenantScoped && !isset($data['tenant_id'])) {
            $data['tenant_id'] = $this->tenantId;
        }
        return $data;
    }

    /**
     * Execute a prepared statement.
     * Only prepend tenant binding when the SQL has a tenant WHERE clause
     * (SELECT/UPDATE/DELETE), not for INSERT/UPSERT which use injectTenant().
     */
    private function execute(string $sql, array $bindings, bool $hasTenantWhere = false): PDOStatement
    {
        $finalBindings = $hasTenantWhere ? $this->getAllBindings($bindings) : $bindings;

        if (function_exists('app')) {
            try {
                $ctx = app()->hooks()->filter('kernel.database.query.before', ['sql' => $sql, 'bindings' => $finalBindings, 'table' => $this->table]);
                if (is_array($ctx)) {
                    $sql = $ctx['sql'] ?? $sql;
                    $finalBindings = $ctx['bindings'] ?? $finalBindings;
                }
            } catch (\Throwable $e) { write_log('db_event_error', 'warning', ['error' => $e->getMessage(), 'source' => 'querybuilder_before']); }
        }
        $start = microtime(true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($finalBindings);

        if (function_exists('app')) {
            try { app()->events()->fire('kernel.database.query.after', ['sql' => $sql, 'table' => $this->table, 'duration_ms' => (microtime(true) - $start) * 1000]); } catch (\Throwable $e) { write_log('db_event_error', 'warning', ['error' => $e->getMessage(), 'source' => 'querybuilder_after']); }
        }

        return $stmt;
    }
}
