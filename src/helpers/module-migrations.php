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
 * Scans the `depends` (module-id) list of every module in scope and returns the
 * references that have no discoverable manifest. Callers can surface these to
 * an operator rather than shipping an incomplete plan without explanation.
 *
 * @return array<int, array{module: string, depends: string}>
 */
function tenantProvisionPlanMissingDependencies(?string $entryModuleId): array
{
    $entryModuleId = trim((string)$entryModuleId);
    $modules = discoverModules();
    $scope = $entryModuleId !== '' ? $modules : getEnabledModules();

    $missing = [];
    foreach ($scope as $moduleId => $manifest) {
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
                $missing[] = ['module' => (string)$moduleId, 'depends' => $dep];
            }
        }
    }
    return $missing;
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

function tenantSyncKernelMigrations(PDO $db, ?array $preloadedApplied = null, ?string $entryModuleId = null): array
{
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

    $artifacts = tenantFilterKernelUserArtifacts(tenantSafeKernelMigrationArtifacts($entryModuleId), $db, $entryModuleId);
    foreach ($artifacts as $artifactName => $fullPath) {
        if (tenantApplySqlArtifact($db, '_kernel', $artifactName, $fullPath)) {
            $executed[] = $artifactName;
        }
    }

    return $executed;
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
 * - Destructive mode requires BOTH `$destructive = true` and
 *   `$confirmed = true` (a typed confirmation from an explicit admin action).
 * - Tenant-enabled add-on modules, same-suite modules, modules whose tables or
 *   capabilities are referenced elsewhere, and modules without owned tables
 *   are never cleanup candidates.
 * - A backup checkpoint (the migration rows that will be removed) is captured
 *   before any destructive step.
 * - Migration rows are only deleted AFTER every drop succeeds, so a partial
 *   failure never leaves untracked tables.
 *
 * @param PDO|null $db Optional injected connection (used by tests).
 */
function tenantRepairMigrationScopeDrift(int $tenantId, ?string $entryModuleId = null, bool $destructive = false, bool $confirmed = false, ?PDO $db = null): array
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
            'error' => 'destructive scope repair requires explicit confirmation (confirmed=true)',
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
