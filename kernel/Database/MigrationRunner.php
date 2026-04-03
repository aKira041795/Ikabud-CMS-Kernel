<?php
/**
 * Ikabud Kernel — Migration Runner
 * 
 * Tracks per-module migration state in a `_migrations` table.
 * Migrations are SQL files in each module's `migrations/` directory,
 * executed in filename order (e.g. 001_create_tables.sql, 002_add_index.sql).
 * 
 * Features:
 *   - Per-module tracking (knows which module ran which migration)
 *   - Ordered execution (sorted by filename)
 *   - Skip already-applied migrations (idempotent)
 *   - Rollback support via companion _down files (001_create_tables.down.sql)
 *   - Dry-run mode for previewing pending migrations
 *   - Kernel-level migrations in migrations/ (module = '_kernel')
 * 
 * Usage:
 *   $runner = new MigrationRunner($pdo);
 *   $runner->migrate('sms');                    // Run pending for one module
 *   $runner->migrateAll();                      // Run pending for all enabled modules
 *   $runner->rollback('sms');                   // Rollback last batch for module
 *   $runner->status('sms');                     // Get migration status
 *   $runner->pending();                         // List all pending migrations
 * 
 * @package Ikabud\Kernel\Database
 * @version 1.0.0
 */

namespace Ikabud\Kernel\Database;

use PDO;

class MigrationRunner
{
    private PDO $pdo;
    private string $modulesPath;
    private string $kernelMigrationsPath;
    private string $controlMigrationsPath;

    private const TABLE = '_migrations';

    public function __construct(PDO $pdo, ?string $modulesPath = null, ?string $kernelMigrationsPath = null, ?string $controlMigrationsPath = null)
    {
        $this->pdo = $pdo;
        $this->modulesPath = $modulesPath ?? (defined('BASE_PATH') ? BASE_PATH . '/modules' : './modules');
        $this->kernelMigrationsPath = $kernelMigrationsPath ?? (defined('BASE_PATH') ? BASE_PATH . '/migrations' : './migrations');
        $this->controlMigrationsPath = $controlMigrationsPath ?? (defined('BASE_PATH') ? BASE_PATH . '/control-migrations' : './control-migrations');
        $this->ensureTable();
    }

    /**
     * Create the _migrations tracking table if it doesn't exist.
     */
    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(80) NOT NULL,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_module_migration (module, migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Run all pending migrations for a specific module.
     * Returns array of executed migration filenames.
     */
    public function migrate(string $moduleId): array
    {
        $pending = $this->getPending($moduleId);
        if (empty($pending)) {
            return [];
        }

        $batch = $this->getNextBatch($moduleId);
        $executed = [];

        foreach ($pending as $file) {
            $sql = file_get_contents($file['path']);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // Execute multi-statement SQL.
            // Idempotent DDL errors (column/key/table already exists) are treated as
            // success so that migrations applied manually outside the runner can still
            // be recorded in the tracking table on the next run.
            try {
                $this->executeSql($sql);
            } catch (\PDOException $e) {
                $mysqlCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
                $idempotentCodes = [
                    1060, // Duplicate column name  — ADD COLUMN on existing column
                    1061, // Duplicate key name     — ADD INDEX on existing index
                    1050, // Table already exists   — CREATE TABLE without IF NOT EXISTS
                ];
                if (!in_array($mysqlCode, $idempotentCodes, true)) {
                    throw $e;
                }
            }

            // Record in tracking table
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO `" . self::TABLE . "` (module, migration, batch) VALUES (?, ?, ?)"
            );
            $stmt->execute([$moduleId, $file['key'], $batch]);
            $executed[] = $file['key'];
        }

        return $executed;
    }

    /**
     * Backward-compatible runner for legacy CLI scripts.
     *
     * Accepts module migration directory paths (for example,
     * /path/to/project/modules/daily-ledger/database/migrations)
     * and maps them to module ids before calling migrate().
     *
     * @param array<int, string> $migrationDirs
     * @return array<int, string>
     */
    public function run(array $migrationDirs): array
    {
        $executed = [];

        foreach ($migrationDirs as $dir) {
            if (!is_string($dir) || trim($dir) === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', rtrim($dir, '/'));

            if (preg_match('#/modules/([^/]+)/#', $normalized, $m)) {
                $moduleId = (string)($m[1] ?? '');
                if ($moduleId === '') {
                    continue;
                }
                $moduleExecuted = $this->migrate($moduleId);
                foreach ($moduleExecuted as $migration) {
                    $executed[] = $migration;
                }
                continue;
            }

            throw new \RuntimeException(
                "Unsupported migration directory '{$dir}'. Use module paths under modules/<module-id>/... or call migrate(<module-id>) directly."
            );
        }

        return $executed;
    }

    /**
     * Run pending migrations for ALL enabled modules + kernel.
     * Returns ['module_id' => ['file1.sql', 'file2.sql'], ...]
     */
    public function migrateAll(): array
    {
        $results = [];

        // Kernel migrations first
        if (is_dir($this->kernelMigrationsPath)) {
            $kernelResult = $this->migrate('_kernel');
            if ($kernelResult) {
                $results['_kernel'] = $kernelResult;
            }
        }

        // Module migrations
        if (!is_dir($this->modulesPath)) {
            return $results;
        }

        foreach (scandir($this->modulesPath) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $manifestPath = $this->modulesPath . '/' . $entry . '/module.json';
            if (!is_file($manifestPath)) continue;

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $moduleId = $manifest['id'] ?? $entry;

            $moduleResult = $this->migrate($moduleId);
            if ($moduleResult) {
                $results[$moduleId] = $moduleResult;
            }
        }

        return $results;
    }

    /**
     * Rollback the last batch of migrations for a module.
     * Requires companion .down.sql files.
     * Returns array of rolled-back migration filenames.
     */
    public function rollback(string $moduleId, int $steps = 1): array
    {
        $lastBatch = $this->getLastBatch($moduleId);
        if ($lastBatch === 0) {
            return [];
        }

        $rolled = [];
        $targetBatch = max(1, $lastBatch - $steps + 1);

        // Get migrations in reverse order for the target batches
        $stmt = $this->pdo->prepare(
            "SELECT migration, batch FROM `" . self::TABLE . "` 
             WHERE module = ? AND batch >= ? 
             ORDER BY batch DESC, id DESC"
        );
        $stmt->execute([$moduleId, $targetBatch]);
        $migrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $migrationsDir = $this->getMigrationsDir($moduleId);

        foreach ($migrations as $row) {
            $downFile = $migrationsDir . '/' . str_replace('.sql', '.down.sql', $row['migration']);
            if (!is_file($downFile)) {
                throw new \RuntimeException(
                    "Rollback file not found: {$downFile}. Cannot rollback '{$row['migration']}' for module '{$moduleId}'."
                );
            }

            $sql = file_get_contents($downFile);
            if ($sql !== false && trim($sql) !== '') {
                $this->executeSql($sql);
            }

            // Remove from tracking
            $delStmt = $this->pdo->prepare(
                "DELETE FROM `" . self::TABLE . "` WHERE module = ? AND migration = ?"
            );
            $delStmt->execute([$moduleId, $row['migration']]);
            $rolled[] = $row['migration'];
        }

        return $rolled;
    }

    /**
     * Get migration status for a module.
     * Returns ['applied' => [...], 'pending' => [...]]
     */
    public function status(string $moduleId): array
    {
        $applied = $this->getApplied($moduleId);
        $pending = $this->getPending($moduleId);

        return [
            'applied' => $applied,
            'pending' => array_map(fn($f) => is_array($f) ? basename((string)($f['path'] ?? '')) : '', $pending),
        ];
    }

    /**
     * Get all pending migrations across all modules.
     * Returns ['module_id' => ['file1.sql', ...], ...]
     */
    public function pending(): array
    {
        $result = [];

        // Kernel
        if (is_dir($this->kernelMigrationsPath)) {
            $kernelPending = $this->getPending('_kernel');
            if ($kernelPending) {
                $result['_kernel'] = array_map(fn($f) => is_array($f) ? basename((string)($f['path'] ?? '')) : '', $kernelPending);
            }
        }

        // Modules
        if (is_dir($this->modulesPath)) {
            foreach (scandir($this->modulesPath) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $manifestPath = $this->modulesPath . '/' . $entry . '/module.json';
                if (!is_file($manifestPath)) continue;

                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $moduleId = $manifest['id'] ?? $entry;

                $modulePending = $this->getPending($moduleId);
                if ($modulePending) {
                    $result[$moduleId] = array_map(fn($f) => is_array($f) ? basename((string)($f['path'] ?? '')) : '', $modulePending);
                }
            }
        }

        return $result;
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Get list of applied migration names for a module.
     */
    private function getApplied(string $moduleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT migration, batch, executed_at FROM `" . self::TABLE . "` WHERE module = ? ORDER BY id"
        );
        $stmt->execute([$moduleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get pending (unapplied) migration files for a module.
     * Returns [['name' => '001_foo.sql', 'path' => '/full/path/001_foo.sql'], ...]
     */
    private function getPending(string $moduleId): array
    {
        $sources = $this->getMigrationSources($moduleId);
        if (empty($sources)) {
            return [];
        }

        // Get already-applied keys
        $stmt = $this->pdo->prepare(
            "SELECT migration FROM `" . self::TABLE . "` WHERE module = ?"
        );
        $stmt->execute([$moduleId]);
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $appliedSet = array_flip($applied);

        // Scan migration files (only .sql, not .down.sql)
        $files = [];
        foreach ($sources as $src) {
            if ($src['type'] === 'dir') {
                $dir = $src['path'];
                if (!is_dir($dir)) continue;
                foreach (scandir($dir) as $file) {
                    if (!str_ends_with($file, '.sql') || str_ends_with($file, '.down.sql')) {
                        continue;
                    }
                    $migrationKey = $this->buildMigrationKey($moduleId, $file);
                    if (isset($appliedSet[$migrationKey])) {
                        continue;
                    }
                    $files[] = ['key' => $migrationKey, 'path' => $dir . '/' . $file];
                }
                continue;
            }

            if ($src['type'] === 'file') {
                $filePath = $src['path'];
                if (!is_file($filePath)) continue;
                $base = basename($filePath);
                if (!str_ends_with($base, '.sql') || str_ends_with($base, '.down.sql')) {
                    continue;
                }
                $migrationKey = $this->buildMigrationKey($moduleId, $base);
                if (isset($appliedSet[$migrationKey])) {
                    continue;
                }
                $files[] = ['key' => $migrationKey, 'path' => $filePath];
            }
        }

        // Dedupe (a migration can be discovered both via manifest-listed files and conventional dirs)
        $deduped = [];
        foreach ($files as $f) {
            if (!is_array($f)) continue;
            $k = (string)($f['key'] ?? '');
            if ($k === '') continue;
            if (isset($deduped[$k])) continue;
            $deduped[$k] = $f;
        }
        $files = array_values($deduped);

        // Sort by filename (which is why we use numeric prefixes)
        usort($files, fn($a, $b) => strcmp($a['key'], $b['key']));

        return $files;
    }

    /**
     * Get migrations directory for a module.
     */
    private function getMigrationsDir(string $moduleId): string
    {
        if ($moduleId === '_kernel') {
            return $this->kernelMigrationsPath;
        }
        if ($moduleId === '_control') {
            return $this->controlMigrationsPath;
        }
        return $this->modulesPath . '/' . $moduleId . '/migrations';
    }

    /**
     * Return migration sources for a module.
     * - Kernel: single dir
     * - Module: manifest-listed migrations (if present) + common fallback dirs
     */
    private function getMigrationSources(string $moduleId): array
    {
        if ($moduleId === '_kernel') {
            return [['type' => 'dir', 'path' => $this->kernelMigrationsPath]];
        }

        if ($moduleId === '_control') {
            return [['type' => 'dir', 'path' => $this->controlMigrationsPath]];
        }

        $sources = [];

        $manifestPath = $this->modulesPath . '/' . $moduleId . '/module.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && !empty($manifest['migrations']) && is_array($manifest['migrations'])) {
                foreach ($manifest['migrations'] as $rel) {
                    $rel = ltrim((string) $rel, '/');
                    $sources[] = ['type' => 'file', 'path' => $this->modulesPath . '/' . $moduleId . '/' . $rel];
                }
            }
        }

        // Back-compat / conventional directories
        $sources[] = ['type' => 'dir', 'path' => $this->modulesPath . '/' . $moduleId . '/migrations'];
        $sources[] = ['type' => 'dir', 'path' => $this->modulesPath . '/' . $moduleId . '/database/migrations'];

        // Dedupe by path
        $seen = [];
        $out = [];
        foreach ($sources as $s) {
            $p = $s['path'];
            if (isset($seen[$p])) continue;
            $seen[$p] = true;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Build the key stored in _migrations.migration.
     * For modules we namespace by filename so manifest-listed migrations don't collide.
     */
    private function buildMigrationKey(string $moduleId, string $baseFilename): string
    {
        // For back-compat: store EXACTLY the base filename in _migrations.
        // (Older installs used raw filenames like '001_initial.sql'.)
        return $baseFilename;
    }

    /**
     * Get the next batch number for a module.
     */
    private function getNextBatch(string $moduleId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(batch), 0) + 1 FROM `" . self::TABLE . "` WHERE module = ?"
        );
        $stmt->execute([$moduleId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get the last batch number for a module.
     */
    private function getLastBatch(string $moduleId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(batch), 0) FROM `" . self::TABLE . "` WHERE module = ?"
        );
        $stmt->execute([$moduleId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Execute multi-statement SQL (handles statements separated by semicolons).
     */
    private function executeSql(string $sql): void
    {
        // Split on semicolons, but not those inside quotes/comments
        // For simplicity, use PDO's exec for multi-statement (MySQL supports it)
        $this->pdo->exec($sql);
    }
}
