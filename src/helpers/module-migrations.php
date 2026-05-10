<?php

declare(strict_types=1);

function tenantSafeKernelMigrationFiles(): array
{
    $artifacts = [
        '001_kernel_events_and_triggers.sql' => BASE_PATH . '/migrations/001_kernel_events_and_triggers.sql',
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

    $files = [];
    foreach ($artifacts as $artifactName => $fullPath) {
        if (is_file($fullPath)) {
            $files[] = $artifactName;
        }
    }

    return $files;
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
    $enabled = getEnabledModules();
    if (empty($enabled)) {
        return [];
    }

    $entryModuleId = trim((string)$entryModuleId);
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
    foreach (tenantProvisionEntryBundleModules($entryModuleId) as $seedModuleId) {
        $seedModuleId = trim((string)$seedModuleId);
        if ($seedModuleId === '' || !isset($enabled[$seedModuleId]) || isset($selected[$seedModuleId])) {
            continue;
        }
        $selected[$seedModuleId] = true;
        $queue[] = $seedModuleId;
    }

    while (!empty($queue)) {
        $current = array_shift($queue);
        if (!is_string($current) || !isset($enabled[$current])) {
            continue;
        }

        $manifest = $enabled[$current];

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

    }

    if (isset($enabled['anti-spam']) && !isset($selected['anti-spam'])) {
        $selected['anti-spam'] = true;
    }

    // Reverse-dependency pass: include enabled modules whose `depends` list
    // references any already-selected module.  This ensures modules that
    // declare dependence on the entry module (e.g. ecommerce depends on cms)
    // are provisioned for the tenant instead of being silently skipped.
    $changed = true;
    while ($changed) {
        $changed = false;
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
                if ($dep !== '' && isset($selected[$dep])) {
                    $selected[$moduleId] = true;
                    $changed = true;
                    break;
                }
            }
        }
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

function tenantSyncKernelMigrations(PDO $db, ?array $preloadedApplied = null): array
{
    $artifacts = [
        '001_kernel_events_and_triggers.sql' => BASE_PATH . '/migrations/001_kernel_events_and_triggers.sql',
        '006_kernel_workflow_tables.sql' => BASE_PATH . '/database/migrations/006_kernel_workflow_tables.sql',
        '007_kernel_runtime_tables.sql' => BASE_PATH . '/database/migrations/007_kernel_runtime_tables.sql',
        '010_integration_bridge.sql' => BASE_PATH . '/database/migrations/010_integration_bridge.sql',
        '011_integration_bridge_hardening.sql' => BASE_PATH . '/database/migrations/011_integration_bridge_hardening.sql',
        '012_kernel_trigger_execution_history.sql' => BASE_PATH . '/database/migrations/012_kernel_trigger_execution_history.sql',
        '013_kernel_trigger_execution_history_module_idx.sql' => BASE_PATH . '/database/migrations/013_kernel_trigger_execution_history_module_idx.sql',
        '014_integration_modes.sql' => BASE_PATH . '/database/migrations/014_integration_modes.sql',
        '017_audit_logs_actor_module.sql' => BASE_PATH . '/database/migrations/017_audit_logs_actor_module.sql',
        '018_audit_logs_actor_columns_ensure.sql' => BASE_PATH . '/database/migrations/018_audit_logs_actor_columns_ensure.sql',
    ];

    $applied = $preloadedApplied !== null ? ($preloadedApplied['_kernel'] ?? []) : tenantAppliedModuleMigrations($db, '_kernel');
    $executed = [];

    foreach ($artifacts as $artifactName => $fullPath) {
        if (isset($applied[$artifactName]) || !is_file($fullPath)) {
            continue;
        }

        $sql = (string) file_get_contents($fullPath);
        if (trim($sql) === '') {
            continue;
        }

        $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
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
                ];
                if (!in_array($mysqlCode, $idempotentCodes, true)) {
                    throw $e;
                }
            }
        }
        tenantRecordModuleMigration($db, '_kernel', $artifactName);
        $applied[$artifactName] = true;
        $executed[] = $artifactName;
    }

    return $executed;
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

        $sql = (string)file_get_contents($fullPath);
        if (trim($sql) !== '') {
            $db->exec($sql);
        }

        tenantRecordModuleMigration($db, $moduleId, $artifactName);
        $applied[$artifactName] = true;
        $executed[] = $artifactName;
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

            $kernelApplied = tenantSyncKernelMigrations($db, $allApplied);
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
            $kernelApplied = tenantSyncKernelMigrations($db, $allApplied);
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
            if (!in_array($requestedModuleId, $plannedModules, true)) {
                return [
                    'ok' => true,
                    'tenant_id' => $tenantId,
                    'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
                    'modules' => $results,
                    'skipped' => 'module_not_in_plan',
                ];
            }

            $manifest = $allModules[$requestedModuleId] ?? null;
            if (!is_array($manifest)) {
                return [
                    'ok' => false,
                    'error' => 'Module manifest unavailable',
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

function tenantMigrationSyncPlanFingerprint(int $tenantId, ?string $entryModuleId = null): string
{
    if ($tenantId <= 0) {
        return 'tenant-invalid';
    }

    $entryModuleId = $entryModuleId !== null ? trim($entryModuleId) : tenantEntryModuleIdForTenant($tenantId);
    $plannedModules = tenantProvisionModulePlan($entryModuleId !== '' ? $entryModuleId : null);
    $allModules = discoverModules();

    $modules = [];
    foreach ($plannedModules as $moduleId) {
        $manifest = $allModules[$moduleId] ?? null;
        if (!is_array($manifest)) {
            continue;
        }

        $modules[(string)$moduleId] = [
            'migrations' => array_values(array_map('strval', is_array($manifest['migrations'] ?? null) ? $manifest['migrations'] : [])),
            'seeds' => array_values(array_map('strval', is_array($manifest['seeds'] ?? null) ? $manifest['seeds'] : [])),
        ];
    }

    ksort($modules);

    return hash('sha256', serialize([
        'tenant_id' => $tenantId,
        'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
        'kernel' => tenantSafeKernelMigrationFiles(),
        'modules' => $modules,
    ]));
}

function syncTenantMigrationsForCurrentRequest(): array
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }

    if (!moduleTenantSettingsModeEnabled()) {
        $done = ['ok' => true, 'skipped' => 'tenant_mode_disabled'];
        return $done;
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $tenantId <= 0) {
        $done = ['ok' => true, 'skipped' => 'tenant_unresolved'];
        return $done;
    }

    $syncEnabledRaw = $_ENV['APP_REQUEST_TENANT_MIGRATION_SYNC'] ?? '1';
    $syncEnabled = filter_var($syncEnabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($syncEnabled === false) {
        $done = ['ok' => true, 'skipped' => 'request_sync_disabled'];
        return $done;
    }

    $entryModuleId = tenantEntryModuleIdForTenant($tenantId);
    $entryModuleId = is_string($entryModuleId) ? trim($entryModuleId) : '';

    $syncTtl = max(0, (int)($_ENV['APP_REQUEST_TENANT_MIGRATION_SYNC_TTL'] ?? 300));
    if (PHP_SAPI !== 'cli' && $syncTtl > 0) {
        $cacheKey = 'tenant_migration_sync:' . $tenantId . ':' . tenantMigrationSyncPlanFingerprint($tenantId, $entryModuleId);
        $cached = app()->cache()->get('kernel_tenant_request_sync', $cacheKey);
        if (is_array($cached) && !empty($cached['ok'])) {
            $done = [
                'ok' => true,
                'skipped' => 'recent_sync',
                'tenant_id' => $tenantId,
                'last_checked_at' => $cached['checked_at'] ?? null,
            ];
            return $done;
        }
    }

    $done = syncTenantMigrationsForTenant($tenantId, $entryModuleId !== '' ? $entryModuleId : null);

    if (PHP_SAPI !== 'cli' && $syncTtl > 0 && !empty($done['ok'])) {
        app()->cache()->set(
            'kernel_tenant_request_sync',
            'tenant_migration_sync:' . $tenantId . ':' . tenantMigrationSyncPlanFingerprint($tenantId, $entryModuleId),
            [
                'ok' => true,
                'checked_at' => date('c'),
                'tenant_id' => $tenantId,
            ],
            $syncTtl
        );
    }

    return $done;
}

/**
 * Validate capabilities block in a module manifest.
 * Returns:
 *  - ['ok'=>true, 'exposes'=>array, 'depends'=>string[], 'policy'=>array]
 *  - ['ok'=>false, 'error'=>'...']
 */
