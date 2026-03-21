<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Database;

use PDO;
use PDOStatement;

/**
 * KernelPDO
 *
 * A guarded PDO subclass that enforces ModuleDB table-access rules whenever
 * code is executing inside a module handler (i.e., when a ModuleContext is active).
 *
 * This prevents modules from bypassing ModuleContext by calling app()->db() directly.
 */
final class KernelPDO extends PDO
{
    /** @param array<mixed> $options */
    public function __construct(string $dsn, string $username = '', string $password = '', array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->enforceModuleAccess($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->enforceModuleAccess($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->enforceModuleAccess($statement);
        return parent::exec($statement);
    }

    private function enforceModuleAccess(string $sql): void
    {
        // Kernel infrastructure may temporarily suppress enforcement for its own
        // cross-cutting DB operations (e.g. tenant_module_settings CRUD).
        if ($GLOBALS['_kernel_db_unguarded'] ?? false) {
            return;
        }

        // When running inside a module handler, module-manager sets a global active ModuleContext.
        $ctx = $GLOBALS['_activeModuleContext'] ?? null;
        if (!is_object($ctx) || !method_exists($ctx, 'db')) {
            return;
        }

        // Only enforce when the call site is within a module.
        // This preserves kernel internals (audit logging, auth, etc.) that legitimately
        // touch kernel tables during module handler execution.
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $moduleOrigin = false;
        $modulesRoot = defined('BASE_PATH') ? (rtrim((string)BASE_PATH, '/') . '/modules/') : null;
        if ($modulesRoot) {
            foreach ($bt as $frame) {
                $file = $frame['file'] ?? null;
                if (is_string($file) && str_starts_with($file, $modulesRoot)) {
                    $moduleOrigin = true;
                    break;
                }
            }
        }
        if (!$moduleOrigin) {
            return;
        }

        try {
            $db = $ctx->db();
            if (is_object($db) && method_exists($db, 'assertAccess')) {
                $db->assertAccess($sql);
            }
        } catch (\Throwable $e) {
            // Re-throw so unauthorized access fails loudly and safely.
            throw $e;
        }
    }
}
