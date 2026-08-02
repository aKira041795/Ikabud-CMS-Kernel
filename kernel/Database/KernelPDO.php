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

    /** @var array<string, bool> */
    private static array $runtimeRepairAttempts = [];

    /** @var string[] */
    private const SELF_HEAL_RUNTIME_TABLES = [
        'audit_logs',
        'rate_limits',
        'refresh_tokens',
        'kernel_password_resets',
        'workflow_definitions',
        'workflow_instances',
        'workflow_transition_logs',
        'kernel_events',
        'kernel_event_triggers',
        'kernel_integrations',
        'kernel_integration_logs',
        'kernel_trigger_executions',
    ];

    /**
     * Typed escalation counter — replaces the open string-based
     * '_kernel_db_unguarded' request-context flag (removed in 4.0.0). Only
     * kernel-internal code (IntegrationBridge, module-manager helpers) should
     * call these methods. Modules cannot reach the static class directly without
     * importing the kernel namespace, which module isolation discourages.
     */
    private static int $escalationDepth = 0;

    /**
     * Explicitly set module context — replaces debug_backtrace() for origin detection.
     * Set by App::setActiveModule() / module-manager / ModuleDB before handler dispatch
     * or database operations. When set, isDirectModuleCaller() returns true without
     * performing a stack walk.
     */
    private static ?string $activeModule = null;

    public static function setActiveModule(?string $moduleId): void
    {
        self::$activeModule = $moduleId;
    }

    public static function getActiveModule(): ?string
    {
        return self::$activeModule;
    }

    private static function isDirectModuleCaller(): bool
    {
        // NOTE: Does NOT use self::$activeModule fast path.
        // The backtrace is the authoritative check because kernelEscalationEnter()
        // must distinguish direct module callers from kernel callers even when a
        // module handler context is active. Kernel code (e.g., audit logging via
        // kernel.audit.record@1) legitimately calls escalationEnter() while a
        // module is executing — the fast path would wrongly block it.
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

        try {
            return parent::prepare($query, $options);
        } catch (\Throwable $e) {
            if (!$this->tryRepairRuntimeArtifacts($query, $e)) {
                throw $e;
            }

            return parent::prepare($query, $options);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->enforceModuleAccess($query);

        $start = microtime(true);
        $runQuery = function () use ($query, $fetchMode, $fetchModeArgs): PDOStatement|false {
            if ($fetchMode === null) {
                return parent::query($query);
            }

            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        };

        try {
            $res = $runQuery();
        } catch (\Throwable $e) {
            if (!$this->tryRepairRuntimeArtifacts($query, $e)) {
                throw $e;
            }
            $start = microtime(true);
            $res = $runQuery();
        }

        if (function_exists('app')) {
            try { app()->events()->fire('kernel.database.query.after', ['sql' => $query, 'duration_ms' => (microtime(true) - $start) * 1000, 'source' => 'pdo_query']); } catch (\Throwable $e) { write_log('db_event_error', 'warning', ['error' => $e->getMessage(), 'source' => 'pdo_query']); }
        }

        return $res;
    }

    public function exec(string $statement): int|false
    {
        $this->enforceModuleAccess($statement);

        $start = microtime(true);
        try {
            $res = parent::exec($statement);
        } catch (\Throwable $e) {
            if (!$this->tryRepairRuntimeArtifacts($statement, $e)) {
                throw $e;
            }
            $start = microtime(true);
            $res = parent::exec($statement);
        }

        if (function_exists('app')) {
            try { app()->events()->fire('kernel.database.query.after', ['sql' => $statement, 'duration_ms' => (microtime(true) - $start) * 1000, 'source' => 'pdo_exec']); } catch (\Throwable $e) { write_log('db_event_error', 'warning', ['error' => $e->getMessage(), 'source' => 'pdo_exec']); }
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

        // Fast path: explicit module context is set via setActiveModule().
        // This avoids the expensive debug_backtrace() entirely.
        if (self::$activeModule !== null) {
            try {
                $db = $ctx->db();
                if (is_object($db) && method_exists($db, 'assertAccess')) {
                    $db->assertAccess($sql);
                }
            } catch (\Throwable $e) {
                throw $e;
            }
            return;
        }

        // Fallback: backtrace-based origin detection (deprecated path).
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
                    if (count(self::$moduleOriginCache) >= 1024) {
                        self::$moduleOriginCache = [];
                    }
                    self::$moduleOriginCache[$cacheKey] = $moduleOrigin;
                }
            }
        }

        if (!$moduleOrigin) {
            return;
        }

        // Log deprecation warning when backtrace fallback is triggered
        if (function_exists('write_log')) {
            \write_log('KernelPDO: debug_backtrace fallback used in enforceModuleAccess — activeModule not set', 'warning');
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

    public static function tryRepairCurrentConnectionForSql(string $sql, \Throwable $error): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        try {
            $db = app()->db();
        } catch (\Throwable) {
            return false;
        }

        if (!$db instanceof PDO) {
            return false;
        }

        return self::tryRepairRuntimeArtifactsOnConnection($db, $sql, $error);
    }

    private function tryRepairRuntimeArtifacts(string $sql, \Throwable $error): bool
    {
        return self::tryRepairRuntimeArtifactsOnConnection($this, $sql, $error);
    }

    private static function tryRepairRuntimeArtifactsOnConnection(PDO $db, string $sql, \Throwable $error): bool
    {
        $table = self::repairableKernelRuntimeTable($sql, $error);
        if ($table === null) {
            return false;
        }

        if (!function_exists('tenantRepairKernelRuntimeArtifacts') && (!function_exists('tenantAllAppliedMigrations') || !function_exists('tenantSyncKernelMigrations'))) {
            return false;
        }

        $attemptKey = spl_object_id($db) . ':' . $table;
        if (isset(self::$runtimeRepairAttempts[$attemptKey])) {
            return false;
        }
        self::$runtimeRepairAttempts[$attemptKey] = true;

        if (function_exists('write_log')) {
            write_log('Attempting kernel runtime migration self-heal', 'warning', [
                'table' => $table,
                'sqlstate' => $error instanceof \PDOException ? (string)$error->getCode() : null,
                'driver_code' => $error instanceof \PDOException ? (int)($error->errorInfo[1] ?? 0) : 0,
            ]);
        }

        self::kernelEscalationEnter();
        try {
            $repairDb = self::freshRuntimeRepairConnection($db);
            if (function_exists('tenantRepairKernelRuntimeArtifacts')) {
                tenantRepairKernelRuntimeArtifacts($repairDb);
            } else {
                $applied = tenantAllAppliedMigrations($repairDb);
                tenantSyncKernelMigrations($repairDb, is_array($applied) ? $applied : null);
            }
            return true;
        } catch (\Throwable $syncError) {
            if (function_exists('write_log')) {
                write_log('Kernel runtime migration self-heal failed', 'error', [
                    'table' => $table,
                    'error' => $syncError->getMessage(),
                    'exception' => get_class($syncError),
                    'trace' => $syncError->getTraceAsString(),
                ]);
            }
            return false;
        } finally {
            self::kernelEscalationLeave();
        }
    }

    private static function freshRuntimeRepairConnection(PDO $fallback): PDO
    {
        if (!function_exists('app')) {
            return $fallback;
        }

        try {
            $tenantId = null;
            $tenant = app()->tenant();
            if (is_object($tenant) && method_exists($tenant, 'current')) {
                $tenantId = $tenant->current();
            }

            if (is_int($tenantId) && $tenantId > 0 && method_exists(app(), 'reconnectDbForTenant')) {
                $tenantDb = app()->reconnectDbForTenant($tenantId);
                if ($tenantDb instanceof PDO) {
                    return $tenantDb;
                }
            }

            if (method_exists(app(), 'reconnectDb')) {
                $db = app()->reconnectDb();
                if ($db instanceof PDO) {
                    return $db;
                }
            }
        } catch (\Throwable $connectionError) {
            if (function_exists('write_log')) {
                write_log('Kernel runtime migration repair connection unavailable', 'warning', [
                    'error' => $connectionError->getMessage(),
                ]);
            }
            return $fallback;
        }

        return $fallback;
    }

    private static function repairableKernelRuntimeTable(string $sql, \Throwable $error): ?string
    {
        if (!self::isRepairableSchemaDrift($error)) {
            return null;
        }

        $normalizedSql = strtolower(str_replace('`', '', $sql));
        foreach (self::SELF_HEAL_RUNTIME_TABLES as $table) {
            if (preg_match('/\\b' . preg_quote($table, '/') . '\\b/', $normalizedSql)) {
                return $table;
            }
        }

        return null;
    }

    private static function isRepairableSchemaDrift(\Throwable $error): bool
    {
        $driverCode = 0;
        $sqlState = '';
        if ($error instanceof \PDOException) {
            $sqlState = strtoupper((string)$error->getCode());
            $driverCode = (int)($error->errorInfo[1] ?? 0);
        }

        if ($driverCode === 1146 || $driverCode === 1054) {
            return true;
        }

        if ($sqlState === '42S02' || $sqlState === '42S22') {
            return true;
        }

        $message = strtolower($error->getMessage());
        return str_contains($message, "doesn't exist") || str_contains($message, 'unknown column');
    }
}
