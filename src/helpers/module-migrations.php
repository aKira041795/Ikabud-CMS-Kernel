<?php

declare(strict_types=1);

function tenantEntryModuleUsesKernelUsers(?string $entryModuleId): bool
{
    $entryModuleId = trim((string)$entryModuleId);
    if ($entryModuleId === '') {
        return true;
    }

    $allModules = discoverModules();
    $manifest = $allModules[$entryModuleId] ?? null;
    if (!is_array($manifest)) {
        return true;
    }

    $authOwned = $manifest['auth_owned'] ?? null;
    if (!is_array($authOwned)) {
        return true;
    }

    $usersTable = strtolower(trim((string)($authOwned['users_table'] ?? 'users')));
    return $usersTable === '' || $usersTable === 'users';
}

function tenantSafeKernelMigrationArtifacts(?string $entryModuleId = null): array
{
    $artifacts = [
        '001_kernel_events_and_triggers.sql' => BASE_PATH . '/migrations/001_kernel_events_and_triggers.sql',
        '006_kernel_job_queue.sql' => BASE_PATH . '/migrations/006_kernel_job_queue.sql',
        '006_kernel_workflow_tables.sql' => BASE_PATH . '/database/migrations/006_kernel_workflow_tables.sql',
        '007_kernel_runtime_tables.sql' => BASE_PATH . '/database/migrations/007_kernel_runtime_tables.sql',
        '007_tenant_module_settings.sql' => BASE_PATH . '/database/migrations/007_tenant_module_settings.sql',
        '010_integration_bridge.sql' => BASE_PATH . '/database/migrations/010_integration_bridge.sql',
        '011_integration_bridge_hardening.sql' => BASE_PATH . '/database/migrations/011_integration_bridge_hardening.sql',
        '012_kernel_trigger_execution_history.sql' => BASE_PATH . '/database/migrations/012_kernel_trigger_execution_history.sql',
        '013_kernel_trigger_execution_history_module_idx.sql' => BASE_PATH . '/database/migrations/013_kernel_trigger_execution_history_module_idx.sql',
        '014_integration_modes.sql' => BASE_PATH . '/database/migrations/014_integration_modes.sql',
        '015_users_token_version.sql' => BASE_PATH . '/database/migrations/015_users_token_version.sql',
        '017_audit_logs_actor_module.sql' => BASE_PATH . '/database/migrations/017_audit_logs_actor_module.sql',
        '018_audit_logs_actor_columns_ensure.sql' => BASE_PATH . '/database/migrations/018_audit_logs_actor_columns_ensure.sql',
        '019_kernel_password_resets.sql' => BASE_PATH . '/database/migrations/019_kernel_password_resets.sql',
    ];

    if (!tenantEntryModuleUsesKernelUsers($entryModuleId)) {
        unset($artifacts['015_users_token_version.sql'], $artifacts['019_kernel_password_resets.sql']);
    }

    return $artifacts;
}

function tenantSafeKernelMigrationFiles(?string $entryModuleId = null): array
{
    $files = [];
    foreach (tenantSafeKernelMigrationArtifacts($entryModuleId) as $artifactName => $fullPath) {
        if (is_file($fullPath)) {
            $files[] = $artifactName;
        }
    }

    return $files;
}

function tenantDatabaseHasTable(PDO $db, string $tableName): bool
{
    $tableName = trim($tableName);
    if ($tableName === '') {
        return false;
    }

    try {
        $driver = strtolower((string)($db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql'));
        if ($driver === 'sqlite') {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
            $stmt->execute([':table_name' => $tableName]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
        );
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Inspecting the schema must never masquerade "query failed" as
        // "table absent": log the failure so operators can distinguish a
        // real missing table from a permission/connection problem.
        if (function_exists('write_log')) {
            write_log('tenant_database_has_table_error', 'warning', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }
}

function tenantFilterKernelUserArtifacts(array $artifacts, PDO $db, ?string $entryModuleId = null): array
{
    $usesKernelUsers = tenantEntryModuleUsesKernelUsers($entryModuleId);
    $hasUsersTable = tenantDatabaseHasTable($db, 'users');

    // If the tenant entry module is not kernel-users based, or if the tenant DB
    // does not have a users table yet, skip user-dependent kernel artifacts.
    if (!$usesKernelUsers || !$hasUsersTable) {
        unset($artifacts['015_users_token_version.sql'], $artifacts['019_kernel_password_resets.sql']);
    }

    return $artifacts;
}

function tenantProvisionEntryBundleModules(?string $entryModuleId): array
{
    $entryModuleId = trim((string)$entryModuleId);
    if ($entryModuleId === '') {
        return [];
    }

    $ehrBundle = [
        'ehr',
        'ehr-core',
        'patient-registry',
        'encounters',
        'clinical-notes',
        'orders',
        'results',
        'prescriptions',
        'documents',
        'privacy-consent',
        'scheduling',
        'audit',
        'reporting',
        'billing-bridge',
        'patient-portal',
        'hospital-adt',
        'interoperability-bridge',
        'analytics-cds',
    ];
    $bundles = [
        'ehr' => $ehrBundle,
        'ehr-core' => $ehrBundle,
    ];

    return $bundles[$entryModuleId] ?? [$entryModuleId];
}

/**
 * Build the module migration plan for a tenant database.
 *
 * Rules:
 * - If there is no entry module, keep legacy behavior and include all enabled modules.
 * - If an entry module exists, include:
 *   1. the entry module itself
 *   2. modules that expose capabilities consumed by the current closure
 *   3. modules that explicitly allow the entry/closure modules as callers
 *   4. modules that hook into the entry module namespace (for example `cms.*`)
 *
 * Only modules with declared migrations are returned.
 *
 * @return array<int, string>
 */
function tenantProvisionModulePlan(?string $entryModuleId): array
{
    $entryModuleId = trim((string)$entryModuleId);
    // A new tenant has no module-settings state yet. Planning an explicit
    // entry-module bundle must therefore use manifests only; consulting
    // getEnabledModules() switches into the empty tenant database and can
    // trigger runtime reads before kernel migrations exist.
    $enabled = $entryModuleId !== '' ? discoverModules() : getEnabledModules();
    if (empty($enabled)) {
        return [];
    }

    if ($entryModuleId === '') {
        $all = [];
        foreach ($enabled as $moduleId => $manifest) {
            if (!empty($manifest['migrations']) && is_array($manifest['migrations'])) {
                $all[] = (string)$moduleId;
            }
        }
        sort($all);
        return $all;
    }

    $exposesByCapability = [];
    foreach ($enabled as $moduleId => $manifest) {
        $exposes = $manifest['capabilities']['exposes'] ?? [];
        if (!is_array($exposes)) {
            continue;
        }
        foreach ($exposes as $expose) {
            if (!is_array($expose)) {
                continue;
            }
            $capabilityId = trim((string)($expose['id'] ?? ''));
            if ($capabilityId === '') {
                continue;
            }
            if (!isset($exposesByCapability[$capabilityId])) {
                $exposesByCapability[$capabilityId] = [];
            }
            $exposesByCapability[$capabilityId][] = (string)$moduleId;
        }
    }

    $selected = [];
    $queue = [];
    $entryBundleRoots = [];
    foreach (tenantProvisionEntryBundleModules($entryModuleId) as $seedModuleId) {
        $seedModuleId = trim((string)$seedModuleId);
        if ($seedModuleId === '' || !isset($enabled[$seedModuleId]) || isset($selected[$seedModuleId])) {
            continue;
        }
        $selected[$seedModuleId] = true;
        $queue[] = $seedModuleId;
        $entryBundleRoots[$seedModuleId] = true;
    }

    $forwardExpand = static function (string $moduleId) use (&$selected, &$queue, $enabled, $exposesByCapability): void {
        if (!isset($enabled[$moduleId])) {
            return;
        }

        $manifest = $enabled[$moduleId];

        $moduleDepends = $manifest['depends'] ?? [];
        if (is_array($moduleDepends)) {
            foreach ($moduleDepends as $depModuleId) {
                $depModuleId = trim((string)$depModuleId);
                if ($depModuleId !== '' && isset($enabled[$depModuleId]) && !isset($selected[$depModuleId])) {
                    $selected[$depModuleId] = true;
                    $queue[] = $depModuleId;
                }
            }
        }

        $depends = $manifest['capabilities']['depends'] ?? [];
        if (is_array($depends)) {
            foreach ($depends as $capabilityId) {
                $capabilityId = trim((string)$capabilityId);
                if ($capabilityId === '') {
                    continue;
                }
                foreach ($exposesByCapability[$capabilityId] ?? [] as $providerModuleId) {
                    if (!isset($selected[$providerModuleId])) {
                        $selected[$providerModuleId] = true;
                        $queue[] = $providerModuleId;
                    }
                }
            }
        }

        $legacyConsumes = $manifest['consumes'] ?? [];
        if (is_array($legacyConsumes)) {
            foreach ($legacyConsumes as $capabilityId) {
                $capabilityId = trim((string)$capabilityId);
                if ($capabilityId === '') {
                    continue;
                }
                foreach ($exposesByCapability[$capabilityId] ?? [] as $providerModuleId) {
                    if (!isset($selected[$providerModuleId])) {
                        $selected[$providerModuleId] = true;
                        $queue[] = $providerModuleId;
                    }
                }
            }
        }
    };

    while (!empty($queue)) {
        $current = array_shift($queue);
        if (!is_string($current)) {
            continue;
        }
        $forwardExpand($current);
    }

    if (isset($enabled['anti-spam']) && !isset($selected['anti-spam'])) {
        $selected['anti-spam'] = true;
    }

    // Reverse-dependency pass: only include modules that explicitly depend on
    // the declared entry-bundle roots, not on every dependency discovered
    // during closure expansion. This keeps tenant entry selection deterministic
    // and avoids pulling unrelated module trees.
    $reverseSelected = [];
    foreach ($enabled as $moduleId => $candidate) {
        if (isset($selected[$moduleId])) {
            continue;
        }

        $moduleDeps = $candidate['depends'] ?? [];
        if (!is_array($moduleDeps)) {
            continue;
        }

        foreach ($moduleDeps as $dep) {
            $dep = trim((string)$dep);
            if ($dep !== '' && isset($entryBundleRoots[$dep])) {
                $selected[$moduleId] = true;
                $reverseSelected[] = $moduleId;
                break;
            }
        }
    }

    // Newly reverse-selected modules may bring their own required dependencies.
    // Run one more forward closure over them so the plan stays complete without
    // recursively discovering further reverse dependents.
    $queue = $reverseSelected;
    while (!empty($queue)) {
        $current = array_shift($queue);
        if (!is_string($current)) {
            continue;
        }
        $forwardExpand($current);
    }

    $planned = [];
    foreach (array_keys($selected) as $moduleId) {
        if (!isset($enabled[$moduleId])) {
            continue;
        }
        $manifest = $enabled[$moduleId];
        if (!empty($manifest['migrations']) && is_array($manifest['migrations'])) {
            $planned[] = (string)$moduleId;
        }
    }

    sort($planned);
    return $planned;
}

/**
 * Report module dependencies that cannot be resolved instead of silently
 * omitting them from the tenant plan.
 *
 * When an entry module is supplied, the report is scoped to that entry's
 * selected plan (entry roots + forward closure + reverse-selected dependents
 * and their forward closure), so it does not surface noise from unrelated
 * modules in the repository. Without an entry module (legacy mode) it scans
 * all enabled modules.
 *
 * @return array<int, array{module: string, depends: string}>
 */
function tenantProvisionPlanMissingDependencies(?string $entryModuleId): array
{
    $entryModuleId = trim((string)$entryModuleId);
    $modules = discoverModules();

    if ($entryModuleId === '') {
        $scopeIds = array_keys(getEnabledModules());
    } else {
        $scopeIds = tenantProvisionModulePlan($entryModuleId);
        if (!in_array($entryModuleId, $scopeIds, true) && isset($modules[$entryModuleId])) {
            $scopeIds[] = $entryModuleId;
        }
    }

    $missing = [];
    foreach ($scopeIds as $moduleId) {
        $moduleId = trim((string)$moduleId);
        $manifest = $modules[$moduleId] ?? null;
        if (!is_array($manifest)) {
            continue;
        }
        $deps = $manifest['depends'] ?? [];
        if (!is_array($deps)) {
            continue;
        }
        foreach ($deps as $dep) {
            $dep = trim((string)$dep);
            if ($dep !== '' && !isset($modules[$dep])) {
                $missing[] = ['module' => $moduleId, 'depends' => $dep];
            }
        }
    }
    return $missing;
}

/**
 * Canonical typed-confirmation phrase required before destructive scope
 * cleanup is allowed to run. Tied to the tenant so an operator confirms the
 * exact target rather than a generic "yes".
 */
function tenantScopeRepairConfirmationPhrase(int $tenantId): string
{
    return 'REPAIR TENANT ' . (int)$tenantId;
}

function tenantEntryModuleIdForTenant(int $tenantId): ?string
{
    if ($tenantId <= 0) {
        return null;
    }

    static $requestCache = [];
    if (array_key_exists($tenantId, $requestCache)) {
        return $requestCache[$tenantId];
    }

    if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
        $cacheKey = 'tenant:entry_module:' . $tenantId;
        $cached = apcu_fetch($cacheKey, $hit);
        if ($hit) {
            $resolved = is_string($cached) && $cached !== '' ? $cached : null;
            $requestCache[$tenantId] = $resolved;
            return $resolved;
        }
    }

    try {
        $stmt = app()->controlDb()->prepare('SELECT entry_module_id FROM kernel_tenants WHERE id = :tenant_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value)) {
            $requestCache[$tenantId] = null;
            return null;
        }
        $value = trim($value);
        $resolved = $value !== '' ? $value : null;
        $requestCache[$tenantId] = $resolved;
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            $cacheKey = 'tenant:entry_module:' . $tenantId;
            // Short TTL balances freshness with burst protection.
            apcu_store($cacheKey, $resolved ?? '', 30);
        }
        return $resolved;
    } catch (Throwable $e) {
        $requestCache[$tenantId] = null;
        return null;
    }
}

function resolveTenantIdForRuntimeOptions(array $options): ?int
{
    $tenantId = isset($options['tenant_id']) ? (int)$options['tenant_id'] : 0;
    if ($tenantId > 0) {
        return $tenantId;
    }

    $tenantKey = trim((string)($options['tenant_key'] ?? ''));
    if ($tenantKey !== '') {
        try {
            $stmt = app()->controlDb()->prepare(
                'SELECT id FROM kernel_tenants WHERE tenant_key = :tenant_key AND status = \'active\' ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([':tenant_key' => $tenantKey]);
            $resolvedTenantId = (int)($stmt->fetchColumn() ?: 0);
            if ($resolvedTenantId > 0) {
                return $resolvedTenantId;
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    $entryModuleId = trim((string)($options['tenant_entry_module'] ?? $options['entry_module_id'] ?? ''));
    if ($entryModuleId !== '') {
        try {
            $stmt = app()->controlDb()->prepare(
                'SELECT id FROM kernel_tenants WHERE entry_module_id = :entry_module_id AND status = \'active\' ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([':entry_module_id' => $entryModuleId]);
            $resolvedTenantId = (int)($stmt->fetchColumn() ?: 0);
            if ($resolvedTenantId > 0) {
                return $resolvedTenantId;
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

try {
    app()->capabilities()->register(
        'module.license.activate@1',
        'kernel',
        'kernelDefaultModuleLicenseActivationProvider',
        10,
        ['first']
    );
} catch (Throwable $e) {
}

function tenantMigrationDatabaseFingerprint(array $config): string
{
    $driver = strtolower(trim((string)($config['driver'] ?? 'mysql')));
    $host = strtolower(trim((string)($config['host'] ?? 'localhost')));
    $port = trim((string)($config['port'] ?? '3306'));
    $database = strtolower(trim((string)($config['database'] ?? $config['db_name'] ?? '')));

    return implode('|', [$driver, $host, $port, $database]);
}

/**
 * Normalize a DB host string for base-DB identity comparison so host aliases
 * (localhost / 127.0.0.1 / ::1 / DNS / unix-socket paths) cannot bypass
 * base-DB isolation.
 */
function tenantNormalizeDbHostForIdentity(string $host): string
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }
    // Unix socket path: normalize to a stable sentinel.
    if (str_starts_with($host, '/')) {
        return 'socket';
    }
    // Strip IPv6 brackets and any :port suffix.
    $host = preg_replace('/^\[|\]$/', '', $host) ?? $host;
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === '') {
        return '';
    }
    // Canonicalize loopback aliases.
    if ($host === '::1') {
        $host = '127.0.0.1';
    }
    return $host;
}

/**
 * Canonical base-DB resolution check (config-based, pre-connect).
 *
 * Normalizes driver, port, host aliases (localhost/127.0.0.1/::1/DNS), socket
 * identity, and db_name, then compares against the primary app DB. Returns true
 * when the tenant DB connection resolves to the kernel/base app DB.
 *
 * @param array<string, mixed> $config Keys: driver, host, port, db_name|database
 */
function tenantConnectionResolvesToBaseDb(array $config): bool
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return false;
    }

    $baseDriver = strtolower(trim((string) app()->config('database.driver', 'mysql')));
    $baseHost = tenantNormalizeDbHostForIdentity((string) app()->config('database.host', 'localhost'));
    $basePort = trim((string) app()->config('database.port', '3306'));
    $baseDb = strtolower(trim((string) app()->config('database.database', '')));
    if ($baseDb === '') {
        return false;
    }

    $driver = strtolower(trim((string)($config['driver'] ?? 'mysql')));
    $host = tenantNormalizeDbHostForIdentity((string)($config['host'] ?? ''));
    $port = trim((string)($config['port'] ?? '3306'));
    $db = strtolower(trim((string)($config['db_name'] ?? $config['database'] ?? '')));
    if ($db === '') {
        return false;
    }

    // Treat all loopback/socket aliases as equivalent so localhost and
    // 127.0.0.1 cannot slip past the base-DB guard.
    $hostMatches = $host === $baseHost;
    if (
        !$hostMatches
        && in_array($baseHost, ['localhost', '127.0.0.1', 'socket'], true)
        && in_array($host, ['localhost', '127.0.0.1', 'socket'], true)
    ) {
        $hostMatches = true;
    }

    return $driver === $baseDriver && $hostMatches && $port === $basePort && $db === $baseDb;
}

/**
 * Fail-closed base-DB rejection wrapper.
 *
 * Returns ['ok' => true] when the connection is safe (does NOT resolve to the
 * kernel/base app DB), or ['ok' => false, 'error' => ...] when it does. Used by
 * dbForTenant, provisioning, migration sync, seed/repair endpoints, and
 * db:set/upsert so the shared-DB capability stays discontinued.
 *
 * @param array<string, mixed> $config Keys: driver, host, port, db_name|database
 * @return array{ok: bool, error?: string}
 */
function tenantRejectBaseDbConnection(array $config): array
{
    if (tenantConnectionResolvesToBaseDb($config)) {
        return [
            'ok' => false,
            'error' => 'Tenant DB connection resolves to the kernel/base app DB. The shared-DB capability is discontinued — configure a dedicated tenant database.',
        ];
    }
    return ['ok' => true];
}

/**
 * Verify a LIVE tenant PDO does not resolve to the kernel/base app DB by
 * connected identity (SELECT DATABASE(), @@hostname, @@port) — not by
 * config-string equality. Fail-closed: returns true when the connected
 * identity matches the base DB or when it cannot be determined.
 */
function tenantConnectedDatabaseIsBaseDb(PDO $db): bool
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return false;
    }

    try {
        $row = $db->query('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return true; // cannot determine identity — fail closed
        }

        $connectedDb = strtolower(trim((string)($row['db'] ?? '')));
        $connectedHost = tenantNormalizeDbHostForIdentity((string)($row['host'] ?? ''));
        $connectedPort = trim((string)($row['port'] ?? ''));

        $baseHost = tenantNormalizeDbHostForIdentity((string) app()->config('database.host', 'localhost'));
        $basePort = trim((string) app()->config('database.port', '3306'));
        $baseDb = strtolower(trim((string) app()->config('database.database', '')));
        if ($connectedDb === '' || $baseDb === '') {
            return true; // cannot determine — fail closed
        }

        $hostMatches = $connectedHost === $baseHost;
        if (
            !$hostMatches
            && in_array($baseHost, ['localhost', '127.0.0.1', 'socket'], true)
            && in_array($connectedHost, ['localhost', '127.0.0.1', 'socket'], true)
        ) {
            $hostMatches = true;
        }

        return $connectedDb === $baseDb && $hostMatches && $connectedPort === $basePort;
    } catch (Throwable $e) {
        return true; // fail closed
    }
}

/**
 * Return tenants whose DB connection points somewhere other than the primary app DB.
 * These tenant databases are not covered by the base CLI migrate runner and must be
 * synchronized explicitly.
 *
 * @return array<int, array<string, mixed>>
 */
function tenantSeparateDatabaseMigrationTargets(): array
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return [];
    }

    $baseFingerprint = tenantMigrationDatabaseFingerprint([
        'driver' => (string) app()->config('database.driver', 'mysql'),
        'host' => (string) app()->config('database.host', 'localhost'),
        'port' => (string) app()->config('database.port', '3306'),
        'database' => (string) app()->config('database.database', ''),
    ]);

    try {
        $stmt = app()->controlDb()->query(
            'SELECT t.id, t.tenant_key, t.entry_module_id, c.db_driver, c.db_host, c.db_port, c.db_name'
            . ' FROM kernel_tenants t'
            . ' INNER JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id'
            . ' ORDER BY t.id ASC'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $targets = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $tenantId = (int)($row['id'] ?? 0);
        $dbHost = trim((string)($row['db_host'] ?? ''));
        $dbName = trim((string)($row['db_name'] ?? ''));
        if ($tenantId <= 0 || $dbHost === '' || $dbName === '') {
            continue;
        }

        $fingerprint = tenantMigrationDatabaseFingerprint([
            'driver' => (string)($row['db_driver'] ?? 'mysql'),
            'host' => $dbHost,
            'port' => (string)($row['db_port'] ?? '3306'),
            'database' => $dbName,
        ]);

        if ($fingerprint === $baseFingerprint) {
            continue;
        }

        $targets[] = [
            'tenant_id' => $tenantId,
            'tenant_key' => trim((string)($row['tenant_key'] ?? '')),
            'entry_module_id' => trim((string)($row['entry_module_id'] ?? '')),
            'db_host' => $dbHost,
            'db_port' => trim((string)($row['db_port'] ?? '3306')),
            'db_name' => $dbName,
            'fingerprint' => $fingerprint,
        ];
    }

    return $targets;
}

function tenantEnsureMigrationTrackingTable(PDO $db): void
{
    static $ensured = [];
    $key = spl_object_id($db);
    if (isset($ensured[$key])) {
        return;
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS `_migrations` ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
        . 'module VARCHAR(80) NOT NULL, '
        . 'migration VARCHAR(255) NOT NULL, '
        . 'batch INT UNSIGNED NOT NULL DEFAULT 1, '
        . 'executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE KEY uq_module_migration (module, migration)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $ensured[$key] = true;
}

function tenantAppliedModuleMigrations(PDO $db, string $moduleId): array
{
    tenantEnsureMigrationTrackingTable($db);

    try {
        $stmt = $db->prepare('SELECT migration FROM _migrations WHERE module = :module');
        $stmt->execute([':module' => $moduleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $stmt->closeCursor();
        $applied = [];
        foreach ($rows as $row) {
            $name = trim((string)$row);
            if ($name !== '') {
                $applied[$name] = true;
            }
        }
        return $applied;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Batch-load all applied migrations for all modules in one query.
 * Returns ['moduleId' => ['migration_name' => true, ...], ...].
 */
function tenantAllAppliedMigrations(PDO $db): array
{
    tenantEnsureMigrationTrackingTable($db);

    try {
        $stmt = $db->query('SELECT module, migration FROM _migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        $result = [];
        foreach ($rows as $row) {
            $mod = trim((string)($row['module'] ?? ''));
            $mig = trim((string)($row['migration'] ?? ''));
            if ($mod !== '' && $mig !== '') {
                $result[$mod][$mig] = true;
            }
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

function tenantRecordModuleMigration(PDO $db, string $moduleId, string $migrationName): void
{
    tenantEnsureMigrationTrackingTable($db);

    $batchStmt = $db->prepare('SELECT COALESCE(MAX(batch), 0) + 1 FROM _migrations WHERE module = :module');
    $batchStmt->execute([':module' => $moduleId]);
    $batch = (int)$batchStmt->fetchColumn();
    $batchStmt->closeCursor();
    if ($batch <= 0) {
        $batch = 1;
    }

    $stmt = $db->prepare('INSERT IGNORE INTO _migrations (module, migration, batch) VALUES (:module, :migration, :batch)');
    $stmt->execute([
        ':module' => $moduleId,
        ':migration' => $migrationName,
        ':batch' => $batch,
    ]);
}

/**
 * Ensure a tenant DB has the kernel `users` table when its entry module relies
 * on kernel authentication (i.e. the module is NOT auth_owned).
 *
 * Kernel-users-based tenants (for example moto-inventory, which keeps kernel
 * auth as the identity authority) need a `users` table for the kernel auth
 * provider to authenticate against. The tenant-safe kernel artifacts only
 * ALTER `users` (015 token_version, 019 password_resets) and assume the base
 * table already exists — a clone of the primary schema. Fresh tenant databases
 * are not schema clones, so this bootstrap closes that provisioning gap. It is
 * a no-op for auth_owned entry modules (cms, bakeshop, guidance, ...) whose
 * tenants use their own users tables, and it never seeds users.
 */
function tenantEnsureKernelUserTable(PDO $db, ?string $entryModuleId = null): bool
{
    if (!tenantEntryModuleUsesKernelUsers($entryModuleId)) {
        return false;
    }
    if (tenantDatabaseHasTable($db, 'users')) {
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
        email VARCHAR(191) NULL DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        role ENUM('admin','superadmin','manager','viewer') NOT NULL DEFAULT 'viewer',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        token_version INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_users_role (role),
        INDEX idx_users_active (is_active),
        UNIQUE KEY users_email_unique (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->exec($sql);
    tenantRecordModuleMigration($db, '_kernel', 'tenant_bootstrap_users');
    return true;
}

function tenantSyncKernelMigrations(PDO $db, ?array $preloadedApplied = null, ?string $entryModuleId = null): array
{
    // Kernel-users-based tenants must have a users table before the
    // user-dependent artifacts (015/019) can be applied.
    tenantEnsureKernelUserTable($db, $entryModuleId);

    $artifacts = tenantFilterKernelUserArtifacts(tenantSafeKernelMigrationArtifacts($entryModuleId), $db, $entryModuleId);

    $applied = $preloadedApplied !== null ? ($preloadedApplied['_kernel'] ?? []) : tenantAppliedModuleMigrations($db, '_kernel');
    $executed = [];

    foreach ($artifacts as $artifactName => $fullPath) {
        if (isset($applied[$artifactName]) || !is_file($fullPath)) {
            continue;
        }

        if (tenantApplySqlArtifact($db, '_kernel', $artifactName, $fullPath)) {
            $applied[$artifactName] = true;
            $executed[] = $artifactName;
        }
    }

    return $executed;
}

function tenantRepairKernelRuntimeArtifacts(PDO $db, ?string $entryModuleId = null): array
{
    $executed = [];

    tenantEnsureKernelUserTable($db, $entryModuleId);

    $artifacts = tenantFilterKernelUserArtifacts(tenantSafeKernelMigrationArtifacts($entryModuleId), $db, $entryModuleId);
    foreach ($artifacts as $artifactName => $fullPath) {
        if (tenantApplySqlArtifact($db, '_kernel', $artifactName, $fullPath)) {
            $executed[] = $artifactName;
        }
    }

    return $executed;
}

/**
 * Kernel-owned tables that module migrations must never CREATE/ALTER/DROP/
 * RENAME/TRUNCATE. These are managed exclusively by kernel artifacts.
 *
 * @return string[] Entries ending in '*' are prefixes (e.g. 'workflow_*').
 */
function tenantKernelOwnedTables(): array
{
    return [
        'audit_logs',
        'users',
        'rate_limits',
        'refresh_tokens',
        'workflow_*',
        'tenant_module_settings',
        '_migrations',
    ];
}

/**
 * Migration ownership gate (fail-closed).
 *
 * Preflights the full planned SQL for a MODULE migration and rejects:
 *   1. dynamic SQL (PREPARE/EXECUTE/DELIMITER) outright — never inspected;
 *   2. any CREATE/ALTER/DROP/RENAME/TRUNCATE targeting a kernel-owned table.
 *
 * Comment/quote/backtick/schema-qualifier parsing is defensive: detection is
 * done on comment-stripped SQL, table names are unquoted, and schema-qualified
 * references are handled. Kernel artifacts (moduleId '_kernel') are exempt.
 *
 * @return array{ok: bool, error?: string}
 */
function tenantMigrationOwnershipPreflight(string $sql, string $moduleId): array
{
    if ($moduleId === '_kernel') {
        return ['ok' => true];
    }

    // Strip line and block comments so commented-out DDL cannot trigger the
    // gate, and comment text cannot mask DDL.
    $stripped = preg_replace('/(--[^\n]*)|(\/\*[\s\S]*?\*\/)/', '', $sql) ?? $sql;

    $kernelOwned = tenantKernelOwnedTables();

    // Dynamic SQL (C13): we do NOT claim to inspect PREPARE/EXECUTE reliably,
    // so it is rejected whenever the migration text references a kernel-owned
    // table anywhere (conservative — dynamic DDL on a kernel table cannot be
    // proven safe statically). Module-owned dynamic patterns (e.g. MySQL 5.7
    // idempotent ALTERs on bakeshop_deliveries) that never mention a
    // kernel-owned table remain allowed so legitimate provisioning stays green.
    $hasDynamicSql = preg_match('/\b(PREPARE|EXECUTE|DELIMITER)\b/i', $stripped) === 1;
    if ($hasDynamicSql && tenantSqlReferencesKernelOwnedTable($stripped, $kernelOwned)) {
        return ['ok' => false, 'error' => 'Module migrations may not use dynamic SQL (PREPARE/EXECUTE/DELIMITER) that references kernel-owned tables'];
    }

    // Match DDL targeting a (possibly schema-qualified, backtick-quoted) table.
    $ddlPattern = '/\b(CREATE|ALTER|DROP|RENAME|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?\b/i';
    if (preg_match_all($ddlPattern, $stripped, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $table = strtolower(trim((string)($match[2] ?? ''), '`'));
            if ($table === '') {
                continue;
            }
            foreach ($kernelOwned as $owned) {
                $isPrefix = str_ends_with($owned, '*');
                $base = rtrim($owned, '*');
                $hit = $isPrefix ? str_starts_with($table, $base) : $table === $owned;
                if ($hit) {
                    return ['ok' => false, 'error' => "Module migration may not modify kernel-owned table '{$table}'"];
                }
            }
        }
    }

    return ['ok' => true];
}

/**
 * Whether SQL text references a kernel-owned table (exact word match for exact
 * names, prefix match for 'workflow_*'). Used to decide dynamic-SQL rejection.
 * Word boundaries prevent false positives on module tables like bakeshop_users
 * (which must NOT match the kernel 'users' table).
 *
 * @param string[] $kernelOwned Entries ending in '*' are prefixes.
 */
function tenantSqlReferencesKernelOwnedTable(string $sql, array $kernelOwned): bool
{
    $sql = (string)$sql;
    foreach ($kernelOwned as $owned) {
        if (str_ends_with($owned, '*')) {
            $base = preg_quote(rtrim($owned, '*'), '/');
            if (preg_match('/\b' . $base . '[A-Za-z0-9_]*\b/i', $sql) === 1) {
                return true;
            }
        } else {
            $quoted = preg_quote($owned, '/');
            if (preg_match('/\b' . $quoted . '\b/i', $sql) === 1) {
                return true;
            }
        }
    }
    return false;
}

function tenantApplySqlArtifact(PDO $db, string $moduleId, string $artifactName, string $fullPath): bool
{
    if (!is_file($fullPath)) {
        return false;
    }

    $sql = (string) file_get_contents($fullPath);
    if (trim($sql) === '') {
        return false;
    }

    // Migration ownership gate: reject module SQL that touches kernel-owned
    // tables or uses dynamic SQL (defense-in-depth over the declared artifacts).
    $ownership = tenantMigrationOwnershipPreflight($sql, $moduleId);
    if (empty($ownership['ok'])) {
        if (function_exists('write_log')) {
            write_log('tenant_migration_ownership_blocked', 'error', [
                'module' => $moduleId,
                'artifact' => $artifactName,
                'reason' => (string)($ownership['error'] ?? 'blocked'),
            ]);
        }
        throw new \RuntimeException('Migration blocked by ownership gate: ' . (string)($ownership['error'] ?? 'blocked'));
    }

    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
    // Dynamic idempotency branches must not return a result set. A prepared
    // `SELECT 1` executed through PDO::exec() leaves MySQL unbuffered and makes
    // the next migration statement fail with error 2014.
    $sql = preg_replace("/(['\"])SELECT\\s+1\\1/i", '$1DO 0$1', $sql) ?? $sql;
    $statements = array_filter(array_map('trim', explode(';', $sql)), static fn(string $statement): bool => $statement !== '');
    foreach ($statements as $statement) {
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            $mysqlCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            $idempotentCodes = [
                1050,
                1060,
                1061,
                1091,
            ];
            if (!in_array($mysqlCode, $idempotentCodes, true)) {
                throw $e;
            }
        }
    }

    tenantRecordModuleMigration($db, $moduleId, $artifactName);
    return true;
}

function applyModuleSqlArtifacts(PDO $db, string $moduleId, string $manifestKey, ?array $manifest = null, string $trackingPrefix = '', ?array $preloadedApplied = null): array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return [];
    }

    if ($manifest === null) {
        $allModules = discoverModules();
        $manifest = $allModules[$moduleId] ?? null;
    }
    if (!is_array($manifest)) {
        return [];
    }

    $declared = $manifest[$manifestKey] ?? [];
    if (!is_array($declared) || $declared === []) {
        return [];
    }

    $modulePath = rtrim((string)($manifest['_path'] ?? ''), '/');
    if ($modulePath === '') {
        return [];
    }

    $applied = $preloadedApplied !== null ? ($preloadedApplied[$moduleId] ?? []) : tenantAppliedModuleMigrations($db, $moduleId);
    $executed = [];

    foreach ($declared as $artifactPath) {
        $artifactPath = ltrim((string)$artifactPath, '/');
        if ($artifactPath === '') {
            continue;
        }

        $artifactName = $trackingPrefix . basename($artifactPath);
        if (isset($applied[$artifactName])) {
            continue;
        }

        $fullPath = BASE_PATH . '/' . $artifactPath;
        if (!is_file($fullPath)) {
            $fullPath = $modulePath . '/' . $artifactPath;
        }
        if (!is_file($fullPath)) {
            continue;
        }

        if (tenantApplySqlArtifact($db, $moduleId, $artifactName, $fullPath)) {
            $applied[$artifactName] = true;
            $executed[] = $artifactName;
        }
    }

    return $executed;
}

function tenantSyncModuleMigrations(PDO $db, string $moduleId, ?array $manifest = null, ?array $preloadedApplied = null): array
{
    return applyModuleSqlArtifacts($db, $moduleId, 'migrations', $manifest, '', $preloadedApplied);
}

function tenantSyncModuleSeeds(PDO $db, string $moduleId, ?array $manifest = null, ?array $preloadedApplied = null): array
{
    return applyModuleSqlArtifacts($db, $moduleId, 'seeds', $manifest, 'seed:', $preloadedApplied);
}

function tenantExpectedMigrationModules(?string $entryModuleId = null): array
{
    $entryModuleId = $entryModuleId !== null ? trim($entryModuleId) : '';
    $planned = tenantProvisionModulePlan($entryModuleId !== '' ? $entryModuleId : null);
    $expected = ['_kernel' => true];
    foreach ($planned as $moduleId) {
        $moduleId = trim((string)$moduleId);
        if ($moduleId !== '') {
            $expected[$moduleId] = true;
        }
    }
    return array_keys($expected);
}

function tenantUnexpectedMigratedModules(PDO $db, ?string $entryModuleId = null): array
{
    $expected = tenantExpectedMigrationModules($entryModuleId);
    $expectedMap = [];
    foreach ($expected as $moduleId) {
        $expectedMap[(string)$moduleId] = true;
    }

    try {
        $rows = $db->query('SELECT DISTINCT module FROM _migrations')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $unexpected = [];
    foreach ($rows as $row) {
        $moduleId = trim((string)($row['module'] ?? ''));
        if ($moduleId === '') {
            continue;
        }
        if (!isset($expectedMap[$moduleId])) {
            $unexpected[$moduleId] = true;
        }
    }

    return array_keys($unexpected);
}

function tenantEntryModuleFamilyPrefix(?string $entryModuleId): string
{
    $entryModuleId = trim((string)$entryModuleId);
    if ($entryModuleId === '') {
        return '';
    }

    // Authoritative family boundary: the manifest-declared `suite` (e.g.
    // "cms-akira"). Naming is not a contract; the suite field is.
    $allModules = discoverModules();
    $manifest = $allModules[$entryModuleId] ?? null;
    if (is_array($manifest)) {
        $suite = trim((string)($manifest['suite'] ?? ''));
        if ($suite !== '') {
            return $suite;
        }
    }

    // Legacy fallback only: first two hyphen-separated segments.
    if (preg_match('/^([a-z0-9]+-[a-z0-9]+)/', $entryModuleId, $matches)) {
        return (string)$matches[1];
    }

    return $entryModuleId;
}

/**
 * Read the module ids that the tenant has explicitly engaged with, based on
 * rows present in the tenant-scoped module settings table. These modules are
 * treated as tenant-enabled add-ons and are never candidates for cleanup.
 *
 * @return array<int, string>
 */
function tenantEnabledModuleIdsForTenant(PDO $db): array
{
    if (!moduleTenantSettingsTableExists($db)) {
        return [];
    }

    try {
        $stmt = $db->query('SELECT DISTINCT module_id FROM ' . moduleTenantSettingsTable());
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ids = [];
        foreach ($rows as $row) {
            $mid = trim((string)$row);
            if ($mid !== '') {
                $ids[$mid] = true;
            }
        }
        return array_keys($ids);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('tenant_enabled_modules_read_error', 'warning', [
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }
}

/**
 * Determine whether another module holds a dependency on this module's owned
 * tables or exposed capabilities. Such modules must never be auto-cleaned.
 */
function tenantModuleHasExternalReferences(string $moduleId, array $manifest, array $allModules): bool
{
    $ownedTables = array_map('strval', (array)($manifest['owns_tables'] ?? []));
    $exposedCaps = [];
    foreach ((array)(($manifest['capabilities'] ?? [])['exposes'] ?? []) as $expose) {
        $cid = is_string($expose) ? $expose : (string)($expose['id'] ?? '');
        if ($cid !== '') {
            $exposedCaps[$cid] = true;
        }
    }

    foreach ($allModules as $otherId => $other) {
        if ((string)$otherId === $moduleId || !is_array($other)) {
            continue;
        }

        $otherOwned = array_map('strval', (array)($other['owns_tables'] ?? []));
        $otherCoOwned = array_map('strval', (array)($other['co_owns_tables'] ?? []));
        $otherReads = array_map('strval', (array)($other['reads_tables'] ?? []));
        foreach ($ownedTables as $tableName) {
            if (
                in_array($tableName, $otherReads, true)
                || in_array($tableName, $otherCoOwned, true)
                || in_array($tableName, $otherOwned, true)
            ) {
                return true;
            }
        }

        foreach ((array)(($other['capabilities'] ?? [])['depends'] ?? []) as $dep) {
            $depId = is_string($dep) ? $dep : (string)($dep['id'] ?? '');
            if ($depId !== '' && isset($exposedCaps[$depId])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Pure decision logic for migration-scope drift. Separated from the DB work so
 * it can be unit-tested without a live tenant database.
 *
 * @param array<int, string> $unexpectedModules  modules present in _migrations but not in the entry plan
 * @param array<string, array<string, mixed>> $allModules
 * @param array<int, string> $tenantEnabledModules
 * @return array{
 *   cleanup_modules: array<int, string>,
 *   retained_modules: array<string, string>,
 *   would_drop_tables: array<int, string>,
 *   family_prefix: string,
 *   entry_module_id: string
 * }
 */
function tenantComputeMigrationScopeCleanup(array $unexpectedModules, ?string $entryModuleId, array $allModules, array $tenantEnabledModules): array
{
    $familyPrefix = tenantEntryModuleFamilyPrefix($entryModuleId);
    $familyPrefixDash = $familyPrefix !== '' ? $familyPrefix . '-' : '';
    $tenantEnabledMap = [];
    foreach ($tenantEnabledModules as $enabledModuleId) {
        $tenantEnabledMap[(string)$enabledModuleId] = true;
    }

    $cleanup = [];
    $retained = [];
    $wouldDrop = [];

    foreach ($unexpectedModules as $moduleId) {
        $moduleId = trim((string)$moduleId);
        if ($moduleId === '') {
            continue;
        }

        // Explicitly tenant-enabled add-ons are never cleanup candidates.
        if (isset($tenantEnabledMap[$moduleId])) {
            $retained[$moduleId] = 'tenant_enabled';
            continue;
        }

        // Same suite/family as the entry module is retained.
        if ($familyPrefixDash !== '' && str_starts_with($moduleId, $familyPrefixDash)) {
            $retained[$moduleId] = 'same_family';
            continue;
        }

        $manifest = $allModules[$moduleId] ?? null;
        if (!is_array($manifest)) {
            $retained[$moduleId] = 'manifest_unavailable';
            continue;
        }

        $ownsTables = $manifest['owns_tables'] ?? [];
        if (!is_array($ownsTables) || $ownsTables === []) {
            $retained[$moduleId] = 'no_owned_tables';
            continue;
        }

        if (tenantModuleHasExternalReferences($moduleId, $manifest, $allModules)) {
            $retained[$moduleId] = 'referenced';
            continue;
        }

        $cleanup[] = $moduleId;
        foreach ($ownsTables as $tableName) {
            $tableName = trim((string)$tableName);
            if ($tableName !== '') {
                $wouldDrop[] = $tableName;
            }
        }
    }

    return [
        'cleanup_modules' => array_values(array_unique($cleanup)),
        'retained_modules' => $retained,
        'would_drop_tables' => array_values(array_unique($wouldDrop)),
        'family_prefix' => $familyPrefix,
        'entry_module_id' => trim((string)$entryModuleId),
    ];
}

/**
 * Detect and (only when explicitly requested) repair migration-scope drift for
 * a tenant database.
 *
 * Safety model:
 * - Default (non-destructive): detect, report, and require explicit
 *   confirmation. Never touches tables or migration rows.
 * - Destructive mode requires `$destructive = true` AND a confirmation. The
 *   confirmation may be the typed phrase `"REPAIR TENANT {id}"` (preferred,
 *   see tenantScopeRepairConfirmationPhrase()) or an explicit boolean `true`
 *   for backward compatibility.
 * - Tenant-enabled add-on modules, same-suite modules, modules whose tables or
 *   capabilities are referenced elsewhere, and modules without owned tables
 *   are never cleanup candidates.
 * - A backup checkpoint (the migration rows that will be removed) is captured
 *   before any destructive step.
 * - Migration rows are only deleted AFTER every drop succeeds, so a partial
 *   failure never leaves untracked tables.
 *
 * @param bool|string $confirmed Typed phrase or boolean confirmation.
 * @param PDO|null $db Optional injected connection (used by tests).
 */
function tenantRepairMigrationScopeDrift(int $tenantId, ?string $entryModuleId = null, bool $destructive = false, bool|string $confirmed = false, ?PDO $db = null): array
{
    if ($tenantId <= 0) {
        return ['ok' => false, 'error' => 'Invalid tenant ID'];
    }

    if ($db === null) {
        $db = app()->dbForTenant($tenantId);
    }
    if (!$db) {
        return ['ok' => false, 'error' => 'Tenant DB connection unavailable', 'tenant_id' => $tenantId];
    }

    $entryModuleId = $entryModuleId !== null ? trim($entryModuleId) : tenantEntryModuleIdForTenant($tenantId);
    $entryModuleId = is_string($entryModuleId) ? trim($entryModuleId) : '';

    $unexpectedModules = tenantUnexpectedMigratedModules($db, $entryModuleId !== '' ? $entryModuleId : null);
    $tenantEnabled = tenantEnabledModuleIdsForTenant($db);
    $decision = tenantComputeMigrationScopeCleanup(
        $unexpectedModules,
        $entryModuleId !== '' ? $entryModuleId : null,
        discoverModules(),
        $tenantEnabled
    );

    $cleanupModules = (array)($decision['cleanup_modules'] ?? []);
    $expectedPhrase = tenantScopeRepairConfirmationPhrase($tenantId);
    $baseResult = [
        'ok' => true,
        'tenant_id' => $tenantId,
        'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
        'dry_run' => !$destructive,
        'changed' => false,
        'unexpected_modules' => array_values($unexpectedModules),
        'cleanup_modules' => $cleanupModules,
        'retained_modules' => (array)($decision['retained_modules'] ?? []),
        'would_drop_tables' => (array)($decision['would_drop_tables'] ?? []),
        'dropped_tables' => [],
        'deleted_migration_rows' => 0,
        'deleted_tenant_settings_rows' => 0,
        'family_prefix' => (string)($decision['family_prefix'] ?? ''),
        'tenant_enabled_modules' => array_values($tenantEnabled),
        'expected_confirmation' => $expectedPhrase,
    ];

    if ($cleanupModules === []) {
        return $baseResult;
    }

    if (!$destructive) {
        // Detect / report only. No confirmation is required for a dry run.
        return $baseResult;
    }

    if (!$confirmed) {
        return [
            'ok' => false,
            'error' => 'destructive scope repair requires explicit confirmation',
            'expected_confirmation' => $expectedPhrase,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'dry_run' => false,
            'changed' => false,
            'unexpected_modules' => array_values($unexpectedModules),
            'cleanup_modules' => $cleanupModules,
            'would_drop_tables' => (array)($decision['would_drop_tables'] ?? []),
        ];
    }

    if (is_string($confirmed) && strcasecmp(trim($confirmed), $expectedPhrase) !== 0) {
        return [
            'ok' => false,
            'error' => 'confirmation phrase does not match the tenant target',
            'expected_confirmation' => $expectedPhrase,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'dry_run' => false,
            'changed' => false,
            'unexpected_modules' => array_values($unexpectedModules),
            'cleanup_modules' => $cleanupModules,
            'would_drop_tables' => (array)($decision['would_drop_tables'] ?? []),
        ];
    }

    // Backup checkpoint: capture the migration rows that will be removed so an
    // operator can restore them if the cleanup was a mistake.
    $backup = [];
    try {
        $placeholders = implode(',', array_fill(0, count($cleanupModules), '?'));
        $backupStmt = $db->prepare(
            'SELECT module, migration, batch, executed_at FROM _migrations WHERE module IN (' . $placeholders . ')'
        );
        $backupStmt->execute($cleanupModules);
        $backup = $backupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $backupStmt->closeCursor();
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('tenant_scope_repair_backup_failed', 'warning', [
                'tenant_id' => $tenantId,
                'entry_module_id' => $entryModuleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $allModules = discoverModules();
    $droppedTables = [];

    try {
        $fkDisabled = false;
        try {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            $fkDisabled = true;
        } catch (Throwable $e) {
            // Driver without FK toggle (e.g. sqlite in tests) — proceed.
        }

        try {
            foreach ($cleanupModules as $moduleId) {
                $manifest = $allModules[$moduleId] ?? null;
                if (!is_array($manifest)) {
                    continue;
                }
                $ownsTables = $manifest['owns_tables'] ?? [];
                if (!is_array($ownsTables)) {
                    continue;
                }
                foreach ($ownsTables as $tableName) {
                    $tableName = trim((string)$tableName);
                    if ($tableName === '') {
                        continue;
                    }
                    if (!tenantDatabaseHasTable($db, $tableName)) {
                        continue;
                    }
                    $escaped = str_replace('`', '``', $tableName);
                    $db->exec('DROP TABLE IF EXISTS `' . $escaped . '`');
                    $droppedTables[] = $tableName;
                }
            }
        } finally {
            if ($fkDisabled) {
                try {
                    $db->exec('SET FOREIGN_KEY_CHECKS=1');
                } catch (Throwable $e) {
                    // Best-effort restore; nothing else to do.
                }
            }
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('tenant_scope_repair_drop_failed', 'error', [
                'tenant_id' => $tenantId,
                'entry_module_id' => $entryModuleId,
                'error' => $e->getMessage(),
                'dropped_tables_so_far' => $droppedTables,
            ]);
        }
        // Do NOT delete migration rows on a partial failure — leave them so the
        // module is still tracked and can be repaired manually.
        return [
            'ok' => false,
            'error' => 'scope repair drop failed: ' . $e->getMessage(),
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'dry_run' => false,
            'changed' => false,
            'dropped_tables' => $droppedTables,
            'deleted_migration_rows' => 0,
            'deleted_tenant_settings_rows' => 0,
            'cleanup_modules' => $cleanupModules,
            'backup' => $backup,
        ];
    }

    // All drops succeeded — now prune tracking rows.
    $deletedMigrationRows = 0;
    $deletedSettingsRows = 0;
    $placeholders = implode(',', array_fill(0, count($cleanupModules), '?'));
    try {
        $deleteMigrations = $db->prepare('DELETE FROM _migrations WHERE module IN (' . $placeholders . ')');
        $deleteMigrations->execute($cleanupModules);
        $deletedMigrationRows = (int)$deleteMigrations->rowCount();
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('tenant_scope_repair_migration_delete_failed', 'warning', [
                'tenant_id' => $tenantId,
                'entry_module_id' => $entryModuleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    if (tenantDatabaseHasTable($db, moduleTenantSettingsTable())) {
        try {
            $deleteSettings = $db->prepare('DELETE FROM ' . moduleTenantSettingsTable() . ' WHERE module_id IN (' . $placeholders . ')');
            $deleteSettings->execute($cleanupModules);
            $deletedSettingsRows = (int)$deleteSettings->rowCount();
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log('tenant_scope_repair_settings_delete_failed', 'warning', [
                    'tenant_id' => $tenantId,
                    'entry_module_id' => $entryModuleId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    return array_merge($baseResult, [
        'ok' => true,
        'dry_run' => false,
        'changed' => true,
        'dropped_tables' => $droppedTables,
        'deleted_migration_rows' => $deletedMigrationRows,
        'deleted_tenant_settings_rows' => $deletedSettingsRows,
        'backup' => $backup,
    ]);
}

function syncTenantMigrationsForTenant(int $tenantId, ?string $entryModuleId = null): array
{
    if ($tenantId <= 0) {
        return ['ok' => false, 'error' => 'Invalid tenant ID'];
    }

    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        return ['ok' => false, 'error' => 'Tenant DB connection unavailable'];
    }

    $entryModuleId = $entryModuleId !== null ? trim($entryModuleId) : tenantEntryModuleIdForTenant($tenantId);
    $plannedModules = tenantProvisionModulePlan($entryModuleId !== '' ? $entryModuleId : null);
    $allModules = discoverModules();
    $results = [];

    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            // Batch-load all applied migrations in one query instead of per-module SELECTs
            $allApplied = tenantAllAppliedMigrations($db);

            $kernelApplied = tenantSyncKernelMigrations($db, $allApplied, $entryModuleId !== '' ? $entryModuleId : null);
            if ($kernelApplied !== []) {
                $results['_kernel'] = $kernelApplied;
            }

            foreach ($plannedModules as $moduleId) {
                $manifest = $allModules[$moduleId] ?? null;
                if (!is_array($manifest)) {
                    continue;
                }
                $executed = tenantSyncModuleMigrations($db, $moduleId, $manifest, $allApplied);
                $seeded = tenantSyncModuleSeeds($db, $moduleId, $manifest, $allApplied);
                $applied = array_merge($executed, $seeded);
                if ($applied !== []) {
                    $results[$moduleId] = $applied;
                }
            }
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }

        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    }
}

/**
 * CLI-focused tenant migration sync that mirrors `php ikabud migrate` semantics.
 * It applies kernel + module migrations only, without tenant seed artifacts.
 */
function syncTenantCliMigrationsForTenant(int $tenantId, ?string $moduleId = null): array
{
    if ($tenantId <= 0) {
        return ['ok' => false, 'error' => 'Invalid tenant ID'];
    }

    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        return ['ok' => false, 'error' => 'Tenant DB connection unavailable', 'tenant_id' => $tenantId];
    }

    $entryModuleId = tenantEntryModuleIdForTenant($tenantId);
    $entryModuleId = is_string($entryModuleId) ? trim($entryModuleId) : '';
    $requestedModuleId = $moduleId !== null ? trim($moduleId) : '';
    $plannedModules = tenantProvisionModulePlan($entryModuleId !== '' ? $entryModuleId : null);
    $allModules = discoverModules();
    $results = [];

    try {
        $allApplied = tenantAllAppliedMigrations($db);

        if ($requestedModuleId === '' || $requestedModuleId === '_kernel') {
            $kernelApplied = tenantSyncKernelMigrations($db, $allApplied, $entryModuleId !== '' ? $entryModuleId : null);
            if ($kernelApplied !== []) {
                $results['_kernel'] = $kernelApplied;
            }

            if ($requestedModuleId === '_kernel') {
                return [
                    'ok' => true,
                    'tenant_id' => $tenantId,
                    'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
                    'modules' => $results,
                ];
            }
        }

        if ($requestedModuleId !== '') {
            // When a specific module is explicitly requested, bypass the
            // dependency-resolved plan check — the user knows what they want.
            // Just verify the module exists and has migrations.
            $manifest = $allModules[$requestedModuleId] ?? null;
            if (!is_array($manifest)) {
                return [
                    'ok' => false,
                    'error' => 'Module manifest unavailable or module not found',
                    'tenant_id' => $tenantId,
                    'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
                    'modules' => $results,
                ];
            }

            $executed = tenantSyncModuleMigrations($db, $requestedModuleId, $manifest, $allApplied);
            if ($executed !== []) {
                $results[$requestedModuleId] = $executed;
            }

            return [
                'ok' => true,
                'tenant_id' => $tenantId,
                'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
                'modules' => $results,
            ];
        }

        foreach ($plannedModules as $plannedModuleId) {
            $manifest = $allModules[$plannedModuleId] ?? null;
            if (!is_array($manifest)) {
                continue;
            }

            $executed = tenantSyncModuleMigrations($db, $plannedModuleId, $manifest, $allApplied);
            if ($executed !== []) {
                $results[$plannedModuleId] = $executed;
            }
        }

        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    }
}

/**
 * Validate capabilities block in a module manifest.
 * Returns:
 *  - ['ok'=>true, 'exposes'=>array, 'depends'=>string[], 'policy'=>array]
 *  - ['ok'=>false, 'error'=>'...']
 */
