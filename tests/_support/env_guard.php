<?php

declare(strict_types=1);

function testEnvironmentSkip(string $reason): never
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

function requireWritableCompiledCache(): void
{
    $cacheDir = dirname(__DIR__, 2) . '/storage/cache/compiled';
    if (!is_dir($cacheDir)) {
        testEnvironmentSkip('compiled template cache directory is missing');
    }

    $probe = tempnam($cacheDir, 'write-probe-');
    if ($probe === false || dirname($probe) !== $cacheDir) {
        if ($probe !== false) {
            @unlink($probe);
        }
        testEnvironmentSkip('compiled template cache is not writable by the current process');
    }

    @unlink($probe);
}

function requireWritableCacheDirectory(string $cacheDir, string $label): void
{
    if (is_dir($cacheDir)) {
        $probe = tempnam($cacheDir, 'write-probe-');
        if ($probe === false || dirname($probe) !== $cacheDir) {
            if ($probe !== false) {
                @unlink($probe);
            }
            testEnvironmentSkip("{$label} is not writable by the current process");
        }
        @unlink($probe);
        return;
    }

    $parent = dirname($cacheDir);
    if (!is_dir($parent) || !is_writable($parent)) {
        testEnvironmentSkip("{$label} cannot be created by the current process");
    }
}

/**
 * Require the baseline seed and a currently active row for every governed capability used by a test.
 *
 * @param list<array{capability_id: string, provider: string}> $requirements
 */
function requireCapabilityAuthorizationPolicies(PDO $db, array $requirements): void
{
    $baseline = $db->prepare(
        'SELECT COUNT(*) FROM capability_authorization_policies '
        . 'WHERE capability_id = ? AND capability_version = ? AND provider = ? AND policy_version = 1'
    );
    $active = $db->prepare(
        'SELECT COUNT(*) FROM capability_authorization_policies '
        . 'WHERE capability_id = ? AND capability_version = ? AND provider = ? AND is_active = 1'
    );

    foreach ($requirements as $requirement) {
        $capabilityId = $requirement['capability_id'];
        $provider = $requirement['provider'];
        $parameters = [$capabilityId, '1', $provider];

        $baseline->execute($parameters);
        if ((int) $baseline->fetchColumn() === 0) {
            testEnvironmentSkip("required capability authorization policy is absent: {$capabilityId} for {$provider} (policy version 1)");
        }

        $active->execute($parameters);
        if ((int) $active->fetchColumn() === 0) {
            testEnvironmentSkip("required active capability authorization policy is absent: {$capabilityId} for {$provider}");
        }
    }
}

/**
 * Require module-owned tables before a contract test performs fixture mutations.
 *
 * @param list<string> $tableNames
 */
function requireDatabaseTables(PDO $db, array $tableNames): void
{
    $query = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach ($tableNames as $tableName) {
        $query->execute([$tableName]);
        if ((int) $query->fetchColumn() === 0) {
            testEnvironmentSkip("required database table is absent: {$tableName}");
        }
    }
}

/** @param list<string> $moduleIds */
function requireTenantModulesActive(int $tenantId, array $moduleIds): void
{
    foreach ($moduleIds as $moduleId) {
        if (!moduleIsActive($moduleId, $tenantId)) {
            testEnvironmentSkip("tenant {$tenantId} does not activate required CLI module {$moduleId}");
        }
    }
}

function requireTwoDistinctDedicatedTenantDatabases(): void
{
    $tenantIds = app()->controlDb()
        ->query('SELECT tenant_id FROM kernel_tenant_db_connections ORDER BY tenant_id ASC LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($tenantIds) < 2) {
        testEnvironmentSkip('fewer than two tenant databases resolve in CLI');
    }

    $baseName = (string) app()->db()->query('SELECT DATABASE()')->fetchColumn();
    $names = [];
    foreach ($tenantIds as $tenantId) {
        try {
            $database = app()->dbForTenant((int) $tenantId);
            $names[] = $database instanceof PDO
                ? (string) $database->query('SELECT DATABASE()')->fetchColumn()
                : '';
        } catch (Throwable) {
            $names[] = '';
        }
    }

    if ($names[0] === '' || $names[1] === '' || $names[0] === $baseName || $names[1] === $baseName || $names[0] === $names[1]) {
        testEnvironmentSkip('two distinct dedicated tenant databases do not resolve in CLI');
    }
}

function requireTenantFixture(int $tenantId): void
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return;
    }

    try {
        $database = app()->dbForTenant($tenantId);
    } catch (Throwable) {
        $database = null;
    }

    if ($database === null) {
        testEnvironmentSkip("tenant {$tenantId} has no resolvable database configuration");
    }
}

/**
 * Refuse to run a test against a provisioned (live) tenant database.
 *
 * Tenants always own a unique database, and module tests must never read, write or
 * delete real tenant data. Resolution is deliberately conservative: only databases
 * registered in `kernel_tenant_db_connections`, other than the base/control app
 * database, are treated as live. Synthetic fixture tenants and CI (which provisions
 * no tenant databases) therefore keep running normally.
 */
function requireNotLiveTenantDatabase(): void
{
    $baseName = strtolower(trim((string) ($_ENV['DB_DATABASE'] ?? '')));

    try {
        $live = app()->controlDb()
            ->query('SELECT db_name FROM kernel_tenant_db_connections')
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return;
    }

    $live = array_values(array_filter(
        array_map(static fn (mixed $name): string => strtolower(trim((string) $name)), $live),
        static fn (string $name): bool => $name !== '' && $name !== $baseName
    ));

    if ($live === []) {
        return;
    }

    try {
        $current = strtolower(trim((string) app()->db()->query('SELECT DATABASE()')->fetchColumn()));
    } catch (Throwable) {
        return;
    }

    // No escape hatch: a provisioned tenant database is refused unconditionally.
    // A per-test bypass here would re-open the failure this guard exists to prevent —
    // module tests once destroyed a live tenant's posts.
    if (in_array($current, $live, true)) {
        testEnvironmentSkip("resolved database '{$current}' is a provisioned tenant database; refusing to run module tests against live tenant data");
    }
}
