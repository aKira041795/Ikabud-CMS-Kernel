<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * ReadContractRegistry — governs cross-module reads_tables declarations.
 *
 * Every module that declares reads_tables in module.json creates an implicit
 * contract with the table's owning module. This registry snapshots the column
 * schema at module load time and detects drift when migrations change a table
 * that another module reads.
 *
 * This is NOT an enforcement layer (ModuleDB already blocks undeclared reads).
 * It is an OBSERVABILITY layer: schema drift warnings, deprecation notices,
 * and contract introspection for superadmin tooling.
 *
 * IMPORTANT: The registry is populated during module route loading, NOT during
 * module discovery. Calling registerReadContract() only happens if modules are
 * fully enabled and their routes loaded. If the registry appears empty, verify
 * that getEnabledModules() has been called and loadModuleRoutes() has executed
 * for the relevant modules.
 *
 * Usage (called from module-manager.php during module discovery):
 *   $registry = ReadContractRegistry::getInstance();
 *   $registry->registerReadContract($readerModuleId, $tableName, $db);
 *   $registry->checkDrift($db);  // warns if any read contract tables changed
 */
final class ReadContractRegistry
{
    private static ?ReadContractRegistry $instance = null;

    /**
     * Per-process cache of the resolved DB schema name, so the snapshot cache
     * key is DB-scoped without re-running SELECT DATABASE() per table.
     */
    private static ?string $schemaName = null;

    /**
     * @var array<string, array<string, array{
     *     reader: string,
     *     table: string,
     *     owner: string|null,
     *     columns: array<int, array{Field: string, Type: string, Null: string, Key: string, Default: string|null, Extra: string}>,
     *     snapshot_at: string,
     *     version: int
     * }>>
     *     reader_module_id => [table_name => contract]
     */
    private array $contracts = [];

    /**
     * @var array<string, string> table_name => owner_module_id
     */
    private array $tableOwners = [];

    /**
     * @var array<string, string> table_name => reader_module_id
     * TaTrack deprecations declared via reads_tables_deprecated in module.json
     */
    private array $deprecatedReads = [];

    /**
     * @var bool Whether drift has been checked this request
     */
    private bool $driftChecked = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset all internal state (for tests).
     */
    public function reset(): void
    {
        self::$instance = null;
    }

    // ── Ownership Registration ──────────────────────────────────────────

    /**
     * Register a module as the owner of a table.
     * Called during module discovery when owns_tables is parsed.
     */
    public function registerTableOwner(string $tableName, string $ownerModuleId): void
    {
        $tableName = strtolower(trim($tableName));
        if ($tableName === '') {
            return;
        }

        // First registrant wins (duplicate owns_tables is a manifest error,
        // but we handle it gracefully).
        if (!isset($this->tableOwners[$tableName])) {
            $this->tableOwners[$tableName] = $ownerModuleId;
        }
    }

    // ── Read Contract Registration ──────────────────────────────────────

    /**
     * Register a read contract: module $readerId declares it reads $tableName.
     * Snapshots the current column list from the database.
     *
     * @param \PDO $db The database connection (kernel escalation assumed by caller)
     */
    public function registerReadContract(string $readerModuleId, string $tableName, \PDO $db): void
    {
        $tableName = strtolower(trim($tableName));
        if ($tableName === '' || $readerModuleId === '') {
            return;
        }

        // Avoid duplicate snapshots within a request
        if (isset($this->contracts[$readerModuleId][$tableName])) {
            return;
        }

        $columns = $this->snapshotColumns($db, $tableName);
        $owner = $this->tableOwners[$tableName] ?? null;

        if (!isset($this->contracts[$readerModuleId])) {
            $this->contracts[$readerModuleId] = [];
        }

        $this->contracts[$readerModuleId][$tableName] = [
            'reader'      => $readerModuleId,
            'table'       => $tableName,
            'owner'       => $owner,
            'columns'     => $columns,
            'snapshot_at' => date('c'),
            'version'     => 1,
        ];
    }

    // ── Deprecation Tracking ────────────────────────────────────────────

    /**
     * Mark a read contract as deprecated.
     * Called when a module declares reads_tables_deprecated in module.json.
     */
    public function markDeprecatedRead(string $readerModuleId, string $tableName): void
    {
        $tableName = strtolower(trim($tableName));
        if ($tableName === '' || $readerModuleId === '') {
            return;
        }

        $key = $readerModuleId . ':' . $tableName;
        $this->deprecatedReads[$key] = $readerModuleId;
    }

    /**
     * Check if a read contract is deprecated.
     */
    public function isDeprecated(string $readerModuleId, string $tableName): bool
    {
        $key = $readerModuleId . ':' . strtolower(trim($tableName));
        return isset($this->deprecatedReads[$key]);
    }

    // ── Drift Detection ─────────────────────────────────────────────────

    /**
     * Compare all registered read contracts against the current database
     * schema. Logs a warning for each table whose column list has changed
     * since the snapshot was taken.
     *
     * Called once per request after all modules are loaded.
     */
    public function checkDrift(\PDO $db): void
    {
        if ($this->driftChecked) {
            return;
        }
        $this->driftChecked = true;

        if (!function_exists('write_log')) {
            return;
        }

        foreach ($this->contracts as $readerId => $tables) {
            foreach ($tables as $tableName => $contract) {
                // Skip deprecated reads entirely — they are known to be
                // stale (table may live in a different database or no longer exist).
                if ($this->isDeprecated($readerId, $tableName)) {
                    continue;
                }

                $currentColumns = $this->snapshotColumns($db, $tableName);

                if ($currentColumns === null) {
                    if (empty($contract['columns'])) {
                        continue;
                    }

                    // Table no longer exists
                    write_log(
                        "Read contract drift: table '{$tableName}' (read by '{$readerId}') no longer exists",
                        'warning',
                        [
                            'reader' => $readerId,
                            'table'  => $tableName,
                            'owner'  => $contract['owner'],
                            'drift'  => 'table_missing',
                        ]
                    );
                    continue;
                }

                $drift = $this->compareColumnLists($contract['columns'], $currentColumns);
                if ($drift !== null) {
                    $deprecated = $this->isDeprecated($readerId, $tableName);
                    write_log(
                        "Read contract drift: table '{$tableName}' (read by '{$readerId}') schema changed" .
                        ($deprecated ? ' [DEPRECATED]' : ''),
                        'warning',
                        [
                            'reader'     => $readerId,
                            'table'      => $tableName,
                            'owner'      => $contract['owner'],
                            'drift'      => $drift,
                            'deprecated' => $deprecated,
                            'snapshot_at' => $contract['snapshot_at'],
                        ]
                    );
                }
            }
        }
    }

    // ── Introspection ───────────────────────────────────────────────────

    /**
     * Get all registered read contracts.
     *
     * @return array<string, array<string, array>>
     */
    public function all(): array
    {
        return $this->contracts;
    }

    /**
     * Get read contracts for a specific reader module.
     *
     * @return array<string, array>
     */
    public function forReader(string $readerModuleId): array
    {
        return $this->contracts[$readerModuleId] ?? [];
    }

    /**
     * Get all modules that read a specific table.
     *
     * @return array<int, string>
     */
    public function readersOf(string $tableName): array
    {
        $tableName = strtolower(trim($tableName));
        $readers = [];
        foreach ($this->contracts as $readerId => $tables) {
            if (isset($tables[$tableName])) {
                $readers[] = $readerId;
            }
        }
        return $readers;
    }

    /**
     * Get the owner module of a table, if known.
     */
    public function ownerOf(string $tableName): ?string
    {
        return $this->tableOwners[strtolower(trim($tableName))] ?? null;
    }

    /**
     * Get all deprecated read contracts.
     *
     * @return array<string, string>
     */
    public function deprecatedReads(): array
    {
        return $this->deprecatedReads;
    }

    // ── Internal ────────────────────────────────────────────────────────

    /**
     * Snapshot the column list of a table.
     *
     * Column definitions are cached in APCu (keyed by schema + table) because
     * registerReadContract() and checkDrift() both re-read every read-contract
     * table on every request — ~146 SHOW COLUMNS queries per request across
     * 68 modules. Schemas only change on migration, so caching is safe.
     *
     * Gracefully falls back to a live SHOW COLUMNS when APCu is unavailable
     * (e.g. some shared-hosting configs such as Bluehost disable it), so this
     * is purely an optimization with no behavior change.
     *
     * @return array<int, array>|null Column definitions, or null if table doesn't exist
     */
    private function snapshotColumns(\PDO $db, string $tableName): ?array
    {
        try {
            $dbName = self::$schemaName;
            if ($dbName === null) {
                $dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
                self::$schemaName = $dbName;
            }
            $cacheKey = 'ikabud:read_contract_cols_v1:' . $dbName . ':' . strtolower($tableName);

            if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
                $cached = apcu_fetch($cacheKey, $hit);
                if ($hit && is_array($cached) && array_key_exists('cols', $cached)) {
                    return $cached['cols'];
                }
            }

            $stmt = $db->query("SHOW COLUMNS FROM `{$tableName}`");
            if ($stmt === false) {
                return null;
            }
            $cols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $result = is_array($cols) ? $cols : null;

            // Cache successful snapshots only; missing tables are re-probed so
            // a later migration that creates the table is picked up promptly.
            if ($result !== null && function_exists('apcu_store') && ini_get('apc.enabled')) {
                apcu_store($cacheKey, ['cols' => $result], 3600);
            }
            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Compare two column snapshots and return a drift description, or null if identical.
     *
     * @param array<int, array> $snapshot
     * @param array<int, array> $current
     * @return array{added: array<int,string>, removed: array<int,string>, changed: array<int,string>}|null
     */
    private function compareColumnLists(array $snapshot, array $current): ?array
    {
        $snapNames = [];
        $snapTypes = [];
        foreach ($snapshot as $col) {
            if (is_array($col) && isset($col['Field'])) {
                $name = (string)$col['Field'];
                $snapNames[] = $name;
                $snapTypes[$name] = (string)($col['Type'] ?? '');
            }
        }

        $currNames = [];
        $currTypes = [];
        foreach ($current as $col) {
            if (is_array($col) && isset($col['Field'])) {
                $name = (string)$col['Field'];
                $currNames[] = $name;
                $currTypes[$name] = (string)($col['Type'] ?? '');
            }
        }

        $added = array_values(array_diff($currNames, $snapNames));
        $removed = array_values(array_diff($snapNames, $currNames));
        $changed = [];

        foreach ($snapNames as $name) {
            if (isset($snapTypes[$name]) && isset($currTypes[$name]) && $snapTypes[$name] !== $currTypes[$name]) {
                $changed[] = "{$name}: {$snapTypes[$name]} → {$currTypes[$name]}";
            }
        }

        if ($added === [] && $removed === [] && $changed === []) {
            return null;
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }
}
