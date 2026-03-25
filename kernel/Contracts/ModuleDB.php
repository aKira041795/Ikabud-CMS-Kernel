<?php
/**
 * Ikabud Kernel — Module-Scoped Database Gateway
 * 
 * Implements DatabaseContract with table ownership enforcement.
 * Every SQL query is parsed for table references and validated against
 * the module's declared owns_tables and reads_tables from module.json.
 * 
 * Rules:
 *   owns_tables  → SELECT, INSERT, UPDATE, DELETE allowed
 *   reads_tables → SELECT only
 *   undeclared   → DENIED, logged, RuntimeException thrown
 * 
 * DDL statements (CREATE, DROP, ALTER, TRUNCATE) are always denied.
 * 
 * @package Ikabud\Kernel\Contracts
 */

namespace Ikabud\Kernel\Contracts;

use PDO;
use PDOStatement;

class ModuleDB implements DatabaseContract
{
    private PDO $pdo;
    private string $moduleId;

    /** @var string[] Tables the module owns (full CRUD) */
    private array $ownsTables;

    /** @var string[] Tables the module may read (SELECT only) */
    private array $readsTables;

    /** @var bool When true, skip enforcement (for kernel-level calls) */
    private bool $unrestricted = false;

    /** @var string[] DDL keywords that are always forbidden */
    private const FORBIDDEN_KEYWORDS = ['CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE'];

    public function __construct(PDO $pdo, string $moduleId, array $ownsTables, array $readsTables)
    {
        $this->pdo = $pdo;
        $this->moduleId = $moduleId;
        $this->ownsTables = array_map('strtolower', $ownsTables);
        $this->readsTables = array_map('strtolower', $readsTables);
    }

    /**
     * Create an unrestricted instance (for kernel-level code, not modules).
     */
    public static function unrestricted(PDO $pdo): self
    {
        $instance = new self($pdo, '_kernel', [], []);
        $instance->unrestricted = true;
        return $instance;
    }

    // ── DatabaseContract Implementation ──────────────────────────────

    public function prepare(string $sql): PDOStatement
    {
        $this->enforceAccess($sql);
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $this->enforceAccess($sql);
        if (empty($params)) {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                throw new \RuntimeException("Query failed: {$sql}");
            }
            return $stmt;
        }
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Prepare failed: {$sql}");
        }
        $stmt->execute($params);
        return $stmt;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $this->enforceAccess($sql);
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Prepare failed: {$sql}");
        }
        return $stmt->execute($params);
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Assert that the given SQL statement is permitted under this module's
     * declared owns_tables and reads_tables rules.
     *
     * Intended for kernel-level wrappers (e.g., guarded PDO) to enforce the
     * same rules even when module code bypasses ModuleContext.
     *
     * @throws \RuntimeException on unauthorized access
     */
    public function assertAccess(string $sql): void
    {
        $this->enforceAccess($sql);
    }

    // ── Enforcement Engine ───────────────────────────────────────────

    /**
     * Parse a SQL statement and enforce table access rules.
     * 
     * @throws \RuntimeException on unauthorized access
     */
    private function enforceAccess(string $sql): void
    {
        if ($this->unrestricted) {
            return;
        }

        $normalized = $this->normalizeSql($sql);

        // Block all DDL
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $normalized)) {
                $this->deny("DDL statement '{$keyword}' is forbidden for modules", $sql);
            }
        }

        // Determine query type
        $queryType = $this->detectQueryType($normalized);
        $tables = $this->extractTables($normalized);

        foreach ($tables as $table) {
            $tableLower = strtolower($table);

            if (in_array($tableLower, $this->ownsTables, true)) {
                // Full CRUD — allowed for any query type
                continue;
            }

            if (in_array($tableLower, $this->readsTables, true)) {
                // Read-only — only SELECT allowed
                if ($queryType !== 'SELECT') {
                    $this->deny(
                        "Module '{$this->moduleId}' attempted {$queryType} on read-only table '{$table}'",
                        $sql
                    );
                }
                continue;
            }

            // Undeclared table — always denied
            $this->deny(
                "Module '{$this->moduleId}' accessed undeclared table '{$table}'. "
                . "Declare it in module.json owns_tables or reads_tables.",
                $sql
            );
        }
    }

    /**
     * Detect the primary operation type of the query.
     */
    private function detectQueryType(string $sql): string
    {
        $sql = ltrim($sql);
        if (preg_match('/^SELECT\b/i', $sql)) return 'SELECT';
        if (preg_match('/^INSERT\b/i', $sql)) return 'INSERT';
        if (preg_match('/^UPDATE\b/i', $sql)) return 'UPDATE';
        if (preg_match('/^DELETE\b/i', $sql)) return 'DELETE';
        if (preg_match('/^REPLACE\b/i', $sql)) return 'INSERT';
        return 'UNKNOWN';
    }

    /**
     * Extract table names from a SQL statement.
     * Handles: FROM, JOIN, INTO, UPDATE, INSERT INTO, DELETE FROM.
     * Strips aliases, backticks, and schema prefixes.
     */
    private function extractTables(string $sql): array
    {
        $tables = [];

        // Remove string literals and comments to avoid false matches
        $clean = preg_replace('/\'[^\']*\'/', "''", $sql);
        $clean = preg_replace('/--.*$/m', '', $clean);
        $clean = preg_replace('/\/\*.*?\*\//s', '', $clean);

        // Remove ON DUPLICATE KEY UPDATE ... clauses (not table references)
        $clean = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\b.*/is', '', $clean);

        // FROM / JOIN clauses: FROM table [alias], LEFT JOIN table [alias]
        if (preg_match_all('/(?:FROM|JOIN)\s+`?(\w+)`?/i', $clean, $m)) {
            $tables = array_merge($tables, $m[1]);
        }

        // INSERT INTO table
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $clean, $m)) {
            $tables[] = $m[1];
        }

        // UPDATE table (anchored to start — not mid-query UPDATE from ON DUPLICATE KEY)
        if (preg_match('/^\s*UPDATE\s+`?(\w+)`?/i', $clean, $m)) {
            $tables[] = $m[1];
        }

        // DELETE FROM table
        if (preg_match('/DELETE\s+FROM\s+`?(\w+)`?/i', $clean, $m)) {
            $tables[] = $m[1];
        }

        // Deduplicate
        return array_unique(array_filter($tables, fn($t) => !$this->isSqlKeyword($t)));
    }

    /**
     * Check if a token is a SQL keyword (not a table name).
     */
    private function isSqlKeyword(string $token): bool
    {
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'SET', 'VALUES', 'WHERE',
                     'AND', 'OR', 'NOT', 'NULL', 'INTO', 'FROM', 'JOIN', 'LEFT', 'RIGHT',
                     'INNER', 'OUTER', 'ON', 'AS', 'ORDER', 'BY', 'GROUP', 'HAVING',
                     'LIMIT', 'OFFSET', 'DISTINCT', 'COALESCE', 'CASE', 'WHEN', 'THEN',
                     'ELSE', 'END', 'CURRENT_TIMESTAMP', 'NOW', 'GREATEST', 'LEAST',
                     'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'IF', 'IGNORE', 'REPLACE',
                     'DUPLICATE', 'KEY'];
        return in_array(strtoupper($token), $keywords, true);
    }

    /**
     * Normalize SQL: collapse whitespace, trim.
     */
    private function normalizeSql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * Deny access: log the violation and throw.
     */
    private function deny(string $reason, string $sql): void
    {
        if (function_exists('write_log')) {
            write_log("ModuleDB DENIED: {$reason}", 'warning', [
                'module' => $this->moduleId,
                'sql'    => substr($sql, 0, 500),
            ]);
        }

        throw new \RuntimeException("Database access denied: {$reason}");
    }

    /**
     * Get the module ID this instance is scoped to.
     */
    public function getModuleId(): string
    {
        return $this->moduleId;
    }

    /**
     * Get declared owned tables.
     * @return string[]
     */
    public function getOwnsTables(): array
    {
        return $this->ownsTables;
    }

    /**
     * Get declared read-only tables.
     * @return string[]
     */
    public function getReadsTables(): array
    {
        return $this->readsTables;
    }
}
