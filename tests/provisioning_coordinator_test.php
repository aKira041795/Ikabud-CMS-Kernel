<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/tenants';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/Services/TenantProvisioner.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';

use Ikabud\Kernel\Services\TenantProvisioner;

$pass = 0;
$fail = 0;
$errors = [];

function pct(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function pctShell(string $command): array
{
    $output = shell_exec($command . '; printf "\n__EXIT:%s" "$?"');
    $output = is_string($output) ? $output : '';
    $exitCode = 1;
    if (preg_match('/__EXIT:(\d+)\s*$/', $output, $matches)) {
        $exitCode = (int)($matches[1] ?? 1);
        $output = preg_replace('/\n?__EXIT:\d+\s*$/', '', $output) ?? $output;
    }
    return ['exit_code' => $exitCode, 'output' => trim($output)];
}

function pctFlattenCoordinator(array $details): array
{
    $rows = [];
    foreach ((array)($details['kernel'] ?? []) as $migration) {
        $rows[] = ['module' => '_kernel', 'migration' => (string)$migration];
    }
    foreach ((array)($details['modules'] ?? []) as $moduleId => $migrations) {
        foreach ((array)$migrations as $migration) {
            $rows[] = ['module' => (string)$moduleId, 'migration' => (string)$migration];
        }
    }
    return $rows;
}

function pctFetchLedger(PDO $db): array
{
    $stmt = $db->query('SELECT id, module, migration, batch FROM _migrations ORDER BY id ASC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function pctFetchStatus(PDO $controlDb, int $tenantId): string
{
    $stmt = $controlDb->prepare('SELECT status FROM kernel_tenants WHERE id = :tid LIMIT 1');
    $stmt->execute([':tid' => $tenantId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function pctFetchModuleEnabled(PDO $tenantDb, int $tenantId, string $moduleId): ?bool
{
    $stmt = $tenantDb->prepare('SELECT setting_value FROM tenant_module_settings WHERE tenant_id = :tid AND module_id = :mid AND setting_key = :skey LIMIT 1');
    $stmt->execute([':tid' => $tenantId, ':mid' => $moduleId, ':skey' => '_module_enabled']);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }
    $decoded = json_decode((string)$value, true);
    return is_bool($decoded) ? $decoded : null;
}

function pctCreateTenant(PDO $controlDb, string $tenantKey, string $entryModuleId, string $dbName, array $dbConfig, string $status = 'pending'): int
{
    $tenantStmt = $controlDb->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:k, :s, :e)');
    $tenantStmt->execute([':k' => $tenantKey, ':s' => $status, ':e' => $entryModuleId]);
    $tenantId = (int)$controlDb->lastInsertId();

    $connStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenant_db_connections '
        . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
        . 'VALUES (:tid, :drv, :host, :port, :name, :user, :pass, :charset, :cipher, :iv, :tag)'
    );
    $connStmt->execute([
        ':tid' => $tenantId,
        ':drv' => 'mysql',
        ':host' => (string)($dbConfig['host'] ?? '127.0.0.1'),
        ':port' => (string)($dbConfig['port'] ?? '3306'),
        ':name' => $dbName,
        ':user' => (string)($dbConfig['username'] ?? ''),
        ':pass' => (string)($dbConfig['password'] ?? ''),
        ':charset' => (string)($dbConfig['charset'] ?? 'utf8mb4'),
        ':cipher' => '',
        ':iv' => '',
        ':tag' => '',
    ]);

    return $tenantId;
}

function pctDropTenant(PDO $controlDb, PDO $rootPdo, int $tenantId, string $dbName): void
{
    $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName) ?? $dbName;
    if ($safeDbName !== '') {
        try {
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeDbName . '`');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $controlDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tid')->execute([':tid' => $tenantId]);
        $controlDb->prepare('DELETE FROM kernel_tenants WHERE id = :tid')->execute([':tid' => $tenantId]);
    } catch (Throwable $ignored) {
    }
}

function pctCreateDb(PDO $rootPdo, string $dbName): void
{
    $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName) ?? $dbName;
    $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeDbName . '`');
    $rootPdo->exec('CREATE DATABASE `' . $safeDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== PROVISIONING COORDINATOR TEST ===\n\n";

$fixtureModules = [
    'ztest-prov-entry' => [
        'manifest' => [
            'id' => 'ztest-prov-entry',
            'name' => 'ZTest Provision Entry',
            'version' => '1.0.0',
            'entry_module' => true,
            'depends' => ['ztest-prov-a-good', 'ztest-prov-b-bad'],
            'migrations' => ['database/migrations/001_entry.sql'],
        ],
        'files' => ['database/migrations/001_entry.sql' => "CREATE TABLE IF NOT EXISTS ztest_prov_entry (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"],
    ],
    'ztest-prov-a-good' => [
        'manifest' => [
            'id' => 'ztest-prov-a-good',
            'name' => 'ZTest Provision Good',
            'version' => '1.0.0',
            'migrations' => ['database/migrations/001_good.sql'],
        ],
        'files' => ['database/migrations/001_good.sql' => "CREATE TABLE IF NOT EXISTS ztest_prov_good (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"],
    ],
    'ztest-prov-b-bad' => [
        'manifest' => [
            'id' => 'ztest-prov-b-bad',
            'name' => 'ZTest Provision Bad',
            'version' => '1.0.0',
            'migrations' => ['database/migrations/001_bad.sql'],
        ],
        'files' => ['database/migrations/001_bad.sql' => "THIS IS INVALID SQL;\n"],
    ],
];
$createdFixtureDirs = [];
foreach ($fixtureModules as $moduleId => $fixture) {
    $moduleDir = BASE_PATH . '/modules/' . $moduleId;
    if (is_dir($moduleDir)) {
        continue;
    }
    @mkdir($moduleDir . '/database/migrations', 0775, true);
    file_put_contents($moduleDir . '/module.json', json_encode($fixture['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    foreach ($fixture['files'] as $relative => $content) {
        $path = $moduleDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content);
    }
    $createdFixtureDirs[] = $moduleDir;
}
unset($GLOBALS['_kernel_discovered_modules']);
register_shutdown_function(static function () use ($createdFixtureDirs): void {
    foreach ($createdFixtureDirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
    unset($GLOBALS['_kernel_discovered_modules']);
});

$controlDb = app()->controlDb();
$dbConfig = require BASE_PATH . '/config/database.php';
$rootDsn = 'mysql:host=' . ($dbConfig['host'] ?? '127.0.0.1') . ';port=' . ($dbConfig['port'] ?? '3306') . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4');
$rootPdo = new PDO($rootDsn, (string)($dbConfig['username'] ?? ''), (string)($dbConfig['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cleanup = [];

try {
    pct('coordinator facade exists', function_exists('tenantRunCoordinatedProvisionMigrations'));
    pct('CAS helper exists', function_exists('tenantCasStatus'));
    pct('service routes through coordinator facade', str_contains((string)file_get_contents(BASE_PATH . '/kernel/Services/TenantProvisioner.php'), 'tenantRunCoordinatedProvisionMigrations'));
    pct('CLI routes through coordinator facade', str_contains((string)file_get_contents(BASE_PATH . '/ikabud'), 'tenantRunCoordinatedProvisionMigrations'));

    $entryModule = 'bakeshop';
    $adminUser = 'coord-admin-' . bin2hex(random_bytes(3));
    $adminPass = 'Coord!' . bin2hex(random_bytes(4));
    $adminName = 'Coordinator Admin';

    $coordDbName = 'pct_coord_' . bin2hex(random_bytes(4));
    $syncDbName = 'pct_sync_' . bin2hex(random_bytes(4));
    $serviceDbName = 'pct_service_' . bin2hex(random_bytes(4));
    $cliDbName = 'pct_cli_' . bin2hex(random_bytes(4));

    $coordTenantId = pctCreateTenant($controlDb, 'pct-coord-' . bin2hex(random_bytes(4)), $entryModule, $coordDbName, $dbConfig);
    $syncTenantId = pctCreateTenant($controlDb, 'pct-sync-' . bin2hex(random_bytes(4)), $entryModule, $syncDbName, $dbConfig);
    $serviceTenantId = pctCreateTenant($controlDb, 'pct-service-' . bin2hex(random_bytes(4)), $entryModule, $serviceDbName, $dbConfig);
    $cliTenantId = pctCreateTenant($controlDb, 'pct-cli-' . bin2hex(random_bytes(4)), $entryModule, $cliDbName, $dbConfig);
    $cleanup = [
        [$coordTenantId, $coordDbName],
        [$syncTenantId, $syncDbName],
        [$serviceTenantId, $serviceDbName],
        [$cliTenantId, $cliDbName],
    ];

    pctCreateDb($rootPdo, $coordDbName);
    pctCreateDb($rootPdo, $syncDbName);

    $coordDb = tenantProvisioningPdoForTenant($coordTenantId);
    $syncDb = tenantProvisioningPdoForTenant($syncTenantId);
    pct('coordinator tenant DB reconnects', $coordDb instanceof PDO);
    pct('sync tenant DB reconnects', $syncDb instanceof PDO);

    $coordinated = [];
    $syncResult = [];
    $coordLedger = [];
    $syncLedger = [];
    if ($coordDb instanceof PDO && $syncDb instanceof PDO) {
        app()->tenant()->setTenantId($coordTenantId);
        $coordinated = tenantRunCoordinatedProvisionMigrations($coordDb, $entryModule);
        $coordLedger = pctFetchLedger($coordDb);

        app()->tenant()->setTenantId($syncTenantId);
        $syncResult = syncTenantCliMigrationsForTenant($syncTenantId);
        $syncLedger = pctFetchLedger($syncDb);
    }

    $service = new TenantProvisioner($controlDb);
    $serviceResult = $service->provision($serviceTenantId, [
        'admin_user' => $adminUser,
        'admin_pass' => $adminPass,
        'admin_name' => $adminName,
    ]);
    $serviceDb = tenantProvisioningPdoForTenant($serviceTenantId);
    $serviceLedger = $serviceDb instanceof PDO ? pctFetchLedger($serviceDb) : [];

    $cliResult = pctShell(
        'php ' . escapeshellarg(BASE_PATH . '/ikabud')
        . ' tenant:provision ' . escapeshellarg((string)$cliTenantId)
        . ' --admin-user=' . escapeshellarg($adminUser)
        . ' --admin-pass=' . escapeshellarg($adminPass)
        . ' --admin-name=' . escapeshellarg($adminName) . ' 2>&1'
    );
    $cliDb = tenantProvisioningPdoForTenant($cliTenantId);
    $cliLedger = $cliDb instanceof PDO ? pctFetchLedger($cliDb) : [];

    $plan = tenantProvisionModulePlan($entryModule);
    $flattenedCoordinator = pctFlattenCoordinator($coordinated);
    pct('coordinator returns a kernel artifact group', array_key_exists('kernel', $coordinated) && is_array($coordinated['kernel']), json_encode($coordinated, JSON_UNESCAPED_SLASHES));
    $encounteredModules = [];
    foreach ($coordLedger as $row) {
        $encounteredModules[] = (string)$row['module'];
    }
    $encounteredModules = array_values(array_unique($encounteredModules));
    $expectedModuleOrder = array_merge(['_kernel'], $plan);
    $expectedModuleOrder = array_values(array_intersect($expectedModuleOrder, $encounteredModules));
    pct('ledger module groups are _kernel then tenantProvisionModulePlan order', $encounteredModules === $expectedModuleOrder, json_encode([$encounteredModules, $expectedModuleOrder], JSON_UNESCAPED_SLASHES));

    $batchesMonotonic = true;
    $byModule = [];
    foreach ($coordLedger as $row) {
        $byModule[(string)$row['module']][] = (int)$row['batch'];
    }
    foreach ($byModule as $batches) {
        $expected = range(1, count($batches));
        if ($batches !== $expected) {
            $batchesMonotonic = false;
            break;
        }
    }
    pct('ledger batch ordering is monotonic per module', $batchesMonotonic, json_encode($byModule, JSON_UNESCAPED_SLASHES));

    $syncFlattened = [
        'kernel' => (array)($syncResult['modules']['_kernel'] ?? []),
        'modules' => array_diff_key((array)($syncResult['modules'] ?? []), ['_kernel' => true]),
    ];
    pct('tenant:migrate helper returns same artifact list as TenantProvisioner', pctFlattenCoordinator($syncFlattened) === pctFlattenCoordinator((array)($serviceResult['migration_details'] ?? [])), json_encode([$syncResult['modules'] ?? [], $serviceResult['migration_details'] ?? []], JSON_UNESCAPED_SLASHES));
    pct('tenant:migrate helper writes identical ledger rows as TenantProvisioner', $syncLedger === $serviceLedger, json_encode([$syncLedger, $serviceLedger], JSON_UNESCAPED_SLASHES));
    pct('TenantProvisioner succeeds on parity tenant', ($serviceResult['ok'] ?? false) === true, json_encode($serviceResult, JSON_UNESCAPED_SLASHES));
    pct('TenantProvisioner returns coordinator migration details', pctFlattenCoordinator((array)($serviceResult['migration_details'] ?? [])) === pctFlattenCoordinator($syncFlattened), json_encode($serviceResult['migration_details'] ?? [], JSON_UNESCAPED_SLASHES));
    pct('TenantProvisioner and CLI tenant:provision write identical ledger rows', $serviceLedger === $cliLedger, json_encode([$serviceLedger, $cliLedger], JSON_UNESCAPED_SLASHES));
    pct('CLI tenant:provision succeeds on parity tenant', (int)($cliResult['exit_code'] ?? 1) === 0, $cliResult['output']);
    pct('CLI tenant:provision writes identical ledger rows as tenant:migrate + TenantProvisioner', $cliLedger === $serviceLedger && $cliLedger === $syncLedger, json_encode([$cliLedger, $serviceLedger, $syncLedger], JSON_UNESCAPED_SLASHES));

    $verifyFailDbName = 'pct_verify_fail_' . bin2hex(random_bytes(4));
    $verifyFailTenantId = pctCreateTenant($controlDb, 'pct-verify-fail-' . bin2hex(random_bytes(4)), 'bakeshop', $verifyFailDbName, $dbConfig);
    $cleanup[] = [$verifyFailTenantId, $verifyFailDbName];

    $GLOBALS['tenant_provision_verify_override'] = static fn(): array => ['ok' => false, 'error' => 'forced verify failure'];
    $verifyFailService = new TenantProvisioner($controlDb);
    $verifyFailResult = $verifyFailService->provision($verifyFailTenantId, [
        'admin_user' => 'verify-fail-' . bin2hex(random_bytes(3)),
        'admin_pass' => 'Verify!' . bin2hex(random_bytes(4)),
        'admin_name' => 'Verify Fail',
    ]);
    unset($GLOBALS['tenant_provision_verify_override']);
    $verifyFailDb = tenantProvisioningPdoForTenant($verifyFailTenantId);
    pct('verification failure returns tenant to pending', pctFetchStatus($controlDb, $verifyFailTenantId) === 'pending', pctFetchStatus($controlDb, $verifyFailTenantId));
    pct('verification failure never marks entry module active', $verifyFailDb instanceof PDO && pctFetchModuleEnabled($verifyFailDb, $verifyFailTenantId, 'bakeshop') === false, json_encode(['enabled' => $verifyFailDb instanceof PDO ? pctFetchModuleEnabled($verifyFailDb, $verifyFailTenantId, 'bakeshop') : null, 'result' => $verifyFailResult], JSON_UNESCAPED_SLASHES));
    pct('verification failure returns service error', ($verifyFailResult['ok'] ?? true) === false, json_encode($verifyFailResult, JSON_UNESCAPED_SLASHES));

    $verifyRetryService = new TenantProvisioner($controlDb);
    $verifyRetryResult = $verifyRetryService->provision($verifyFailTenantId, [
        'admin_user' => 'verify-fail-retry-' . bin2hex(random_bytes(3)),
        'admin_pass' => 'VerifyRetry!' . bin2hex(random_bytes(4)),
        'admin_name' => 'Verify Retry',
    ]);
    $verifyRetryDb = tenantProvisioningPdoForTenant($verifyFailTenantId);
    pct('rerun after fixing verification succeeds', ($verifyRetryResult['ok'] ?? false) === true, json_encode($verifyRetryResult, JSON_UNESCAPED_SLASHES));
    pct('successful rerun marks tenant active', pctFetchStatus($controlDb, $verifyFailTenantId) === 'active', pctFetchStatus($controlDb, $verifyFailTenantId));
    pct('successful rerun marks entry module active', $verifyRetryDb instanceof PDO && pctFetchModuleEnabled($verifyRetryDb, $verifyFailTenantId, 'bakeshop') === true, json_encode(['enabled' => $verifyRetryDb instanceof PDO ? pctFetchModuleEnabled($verifyRetryDb, $verifyFailTenantId, 'bakeshop') : null], JSON_UNESCAPED_SLASHES));

    pct('CAS helper rejects wrong expected status on second transition', tenantCasStatus($controlDb, $verifyFailTenantId, 'pending', 'active') === false);

    $partialDbName = 'pct_partial_' . bin2hex(random_bytes(4));
    $partialTenantId = pctCreateTenant($controlDb, 'pct-partial-' . bin2hex(random_bytes(4)), 'ztest-prov-entry', $partialDbName, $dbConfig);
    $cleanup[] = [$partialTenantId, $partialDbName];
    $partialService = new TenantProvisioner($controlDb);
    $partialResult = $partialService->provision($partialTenantId);
    $partialDb = tenantProvisioningPdoForTenant($partialTenantId);
    $partialLedger = $partialDb instanceof PDO ? pctFetchLedger($partialDb) : [];
    $hasKernelRows = false;
    foreach ($partialLedger as $row) {
        if ((string)($row['module'] ?? '') === '_kernel') {
            $hasKernelRows = true;
            break;
        }
    }
    pct('partial module failure applies kernel ledger rows first', $hasKernelRows, json_encode($partialLedger, JSON_UNESCAPED_SLASHES));
    pct('partial module failure returns tenant to pending', pctFetchStatus($controlDb, $partialTenantId) === 'pending', pctFetchStatus($controlDb, $partialTenantId));
    pct('partially migrated module is not marked active', $partialDb instanceof PDO && pctFetchModuleEnabled($partialDb, $partialTenantId, 'ztest-prov-b-bad') === false, json_encode(['enabled' => $partialDb instanceof PDO ? pctFetchModuleEnabled($partialDb, $partialTenantId, 'ztest-prov-b-bad') : null, 'result' => $partialResult], JSON_UNESCAPED_SLASHES));
} finally {
    unset($GLOBALS['tenant_provision_verify_override']);
    foreach ($cleanup as [$tenantId, $dbName]) {
        pctDropTenant($controlDb, $rootPdo, (int)$tenantId, (string)$dbName);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
pct('app.log has no unexpected errors', $appLog === '' || !str_contains(strtolower($appLog), 'fatal'), $appLog);
pct('error.log has no unexpected errors', $errorLog === '' || !str_contains(strtolower($errorLog), 'fatal'), $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
