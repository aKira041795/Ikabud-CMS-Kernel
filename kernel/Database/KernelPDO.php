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
    /** @var array<string, bool> */
    private static array $moduleOriginCache = [];

    /**
     * Typed escalation counter — replaces the open string-based
     * '_kernel_db_unguarded' request-context flag (removed in 4.0.0). Only
     * kernel-internal code (IntegrationBridge, module-manager helpers) should
     * call these methods. Modules cannot reach the static class directly without
     * importing the kernel namespace, which module isolation discourages.
     */
    private static int $escalationDepth = 0;

    private static function isDirectModuleCaller(): bool
    {
        $modulesRoot = defined('BASE_PATH') ? (rtrim((string)BASE_PATH, '/') . '/modules/') : null;
        if ($modulesRoot === null) {
            return false;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $callerFile = $trace[1]['file'] ?? null;

        return is_string($callerFile) && str_starts_with($callerFile, $modulesRoot);
    }

    public static function kernelEscalationEnter(): void
    {
        if (self::isDirectModuleCaller()) {
            if (function_exists('write_log')) {
                \write_log('Blocked direct module DB escalation request', 'warning', [
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4),
                ]);
            }
            return;
        }

        self::$escalationDepth++;
    }

    public static function kernelEscalationLeave(): void
    {
        if (self::isDirectModuleCaller()) {
            return;
        }

        self::$escalationDepth = max(0, self::$escalationDepth - 1);
    }

    private static function isKernelEscalated(): bool
    {
        return self::$escalationDepth > 0;
    }

    /** @param array<mixed> $options */
    public function __construct(string $dsn, string $username = '', string $password = '', array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
        
        // Phase 3B: Database Interceptor Seam (statement level)
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [KernelPDOStatement::class, []]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->enforceModuleAccess($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->enforceModuleAccess($query);

        $start = microtime(true);
        if ($fetchMode === null) {
            $res = parent::query($query);
        } else {
            $res = parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        if (function_exists('app')) {
            try { app()->events()->fire('kernel.database.query.after', ['sql' => $query, 'duration_ms' => (microtime(true) - $start) * 1000, 'source' => 'pdo_query']); } catch (\Throwable $ignored) {}
        }

        return $res;
    }

    public function exec(string $statement): int|false
    {
        $this->enforceModuleAccess($statement);

        $start = microtime(true);
        $res = parent::exec($statement);

        if (function_exists('app')) {
            try { app()->events()->fire('kernel.database.query.after', ['sql' => $statement, 'duration_ms' => (microtime(true) - $start) * 1000, 'source' => 'pdo_exec']); } catch (\Throwable $ignored) {}
        }

        return $res;
    }

    private function enforceModuleAccess(string $sql): void
    {
        // Kernel infrastructure may temporarily suppress enforcement for its own
        // cross-cutting DB operations (e.g. tenant_module_settings CRUD).
        // Use the typed static counter; the legacy '_kernel_db_unguarded'
        // request-context flag was removed in kernel 4.0.0.
        if (self::isKernelEscalated()) {
            return;
        }

        // When running inside a module handler, module-manager sets a global active ModuleContext.
        $ctx = \kernel_request_context_get('_activeModuleContext');
        if (!is_object($ctx) || !method_exists($ctx, 'db')) {
            return;
        }

        // Only enforce when the call site is within a module.
        // This preserves kernel internals (audit logging, auth, etc.) that legitimately
        // touch kernel tables during module handler execution.
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $moduleOrigin = false;
        $modulesRoot = defined('BASE_PATH') ? (rtrim((string)BASE_PATH, '/') . '/modules/') : null;
        $signatureParts = [];
        foreach ($bt as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '' || $file === __FILE__) {
                continue;
            }

            $signatureParts[] = $file . ':' . (int)($frame['line'] ?? 0);
            if (count($signatureParts) >= 4) {
                break;
            }
        }

        $cacheKey = implode('|', $signatureParts);
        if ($cacheKey !== '' && array_key_exists($cacheKey, self::$moduleOriginCache)) {
            $moduleOrigin = self::$moduleOriginCache[$cacheKey];
        }

        if ($modulesRoot) {
            if ($cacheKey === '' || !array_key_exists($cacheKey, self::$moduleOriginCache)) {
                foreach ($bt as $frame) {
                    $file = $frame['file'] ?? null;
                    if (is_string($file) && str_starts_with($file, $modulesRoot)) {
                        $moduleOrigin = true;
                        break;
                    }
                }

                if ($cacheKey !== '') {
                    if (count(self::$moduleOriginCache) >= 256) {
                        self::$moduleOriginCache = [];
                    }
                    self::$moduleOriginCache[$cacheKey] = $moduleOrigin;
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
