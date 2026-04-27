<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/Services/TenantProvisioner.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';

use Ikabud\Kernel\Services\TenantProvisioner;

$pass = 0;
$fail = 0;
$errors = [];

function btProvisionBehavior(string $label, bool $ok, string $detail = ''): void
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

function shellWithExitCode(string $command): array
{
    $output = shell_exec($command . '; printf "\n__EXIT:%s" "$?"');
    $output = is_string($output) ? $output : '';
    $exitCode = 1;

    if (preg_match('/__EXIT:(\d+)\s*$/', $output, $matches)) {
        $exitCode = (int)($matches[1] ?? 1);
        $output = preg_replace('/\n?__EXIT:\d+\s*$/', '', $output) ?? $output;
    }

    return [
        'exit_code' => $exitCode,
        'output' => trim($output),
    ];
}

function bakeshopProvisionUserCount(PDO $db, string $username): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM bakeshop_users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    return (int)$stmt->fetchColumn();
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP TENANT PROVISION BEHAVIOR TEST ===\n\n";

$controlDb = app()->controlDb();
$failFastTenantKey = 'bakeshop-provision-' . bin2hex(random_bytes(4));
$failFastTenantId = null;
$successTenantKey = 'bakeshop-provision-ok-' . bin2hex(random_bytes(4));
$successTenantId = null;
$successDbName = 'bakeshop_provision_ok_' . bin2hex(random_bytes(4));
$cliSuccessTenantKey = 'bakeshop-provision-cli-' . bin2hex(random_bytes(4));
$cliSuccessTenantId = null;
$cliSuccessDbName = 'bakeshop_provision_cli_' . bin2hex(random_bytes(4));

try {
    $tenantInsertStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:tenant_key, :status, :entry_module_id)'
    );
    $tenantInsertStmt->execute([
        ':tenant_key' => $failFastTenantKey,
        ':status' => 'active',
        ':entry_module_id' => 'bakeshop',
    ]);
    $failFastTenantId = (int)$controlDb->lastInsertId();

    $connectionInsertStmt = $controlDb->prepare(
        'INSERT INTO kernel_tenant_db_connections '
        . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
        . 'VALUES (:tenant_id, :db_driver, :db_host, :db_port, :db_name, :db_user, :db_pass, :db_charset, :cipher, :iv, :tag)'
    );
    $connectionInsertStmt->execute([
        ':tenant_id' => $failFastTenantId,
        ':db_driver' => 'mysql',
        ':db_host' => '127.0.0.1',
        ':db_port' => '3306',
        ':db_name' => 'bakeshop_provision_behavior_test',
        ':db_user' => 'bakeshop_provision_behavior_test',
        ':db_pass' => '',
        ':db_charset' => 'utf8mb4',
        ':cipher' => '',
        ':iv' => '',
        ':tag' => '',
    ]);

    $service = new TenantProvisioner($controlDb);
    $serviceResult = $service->provision($failFastTenantId, ['skip_db_create' => true]);

    btProvisionBehavior('service refuses bakeshop provisioning without named admin credentials', ($serviceResult['ok'] ?? true) === false, json_encode($serviceResult, JSON_UNESCAPED_SLASHES));
    btProvisionBehavior(
        'service error explains missing admin_user and admin_pass',
        str_contains(implode(' | ', (array)($serviceResult['errors'] ?? [])), 'admin_user and admin_pass'),
        json_encode($serviceResult, JSON_UNESCAPED_SLASHES)
    );

    $cliResult = shellWithExitCode(
        'php ' . escapeshellarg(BASE_PATH . '/ikabud')
        . ' tenant:provision ' . escapeshellarg((string)$failFastTenantId)
        . ' --skip-db-create 2>&1'
    );

    btProvisionBehavior('CLI exits non-zero when bakeshop admin credentials are omitted', (int)($cliResult['exit_code'] ?? 0) !== 0, $cliResult['output']);
    btProvisionBehavior(
        'CLI explains the missing named admin requirement',
        str_contains((string)($cliResult['output'] ?? ''), 'Bakeshop entry tenants require a named admin during provisioning.'),
        $cliResult['output']
    );

    $dbConfig = require BASE_PATH . '/config/database.php';
    $rootDsn = 'mysql:host=' . ($dbConfig['host'] ?? '127.0.0.1')
        . ';port=' . ($dbConfig['port'] ?? '3306')
        . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4');
    $rootPdo = new PDO(
        $rootDsn,
        (string)($dbConfig['username'] ?? ''),
        (string)($dbConfig['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $safeSuccessDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $successDbName) ?? $successDbName;
    $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeSuccessDbName . '`');

    $tenantInsertStmt->execute([
        ':tenant_key' => $successTenantKey,
        ':status' => 'active',
        ':entry_module_id' => 'bakeshop',
    ]);
    $successTenantId = (int)$controlDb->lastInsertId();

    $connectionInsertStmt->execute([
        ':tenant_id' => $successTenantId,
        ':db_driver' => 'mysql',
        ':db_host' => (string)($dbConfig['host'] ?? '127.0.0.1'),
        ':db_port' => (string)($dbConfig['port'] ?? '3306'),
        ':db_name' => $successDbName,
        ':db_user' => (string)($dbConfig['username'] ?? ''),
        ':db_pass' => (string)($dbConfig['password'] ?? ''),
        ':db_charset' => (string)($dbConfig['charset'] ?? 'utf8mb4'),
        ':cipher' => '',
        ':iv' => '',
        ':tag' => '',
    ]);

    $provisionedAdminUser = 'provision-admin-' . bin2hex(random_bytes(3));
    $provisionedAdminPass = 'Provision!' . bin2hex(random_bytes(4));
    $provisionedAdminName = 'Provisioned Bakeshop Admin';

    $successResult = $service->provision($successTenantId, [
        'admin_user' => $provisionedAdminUser,
        'admin_pass' => $provisionedAdminPass,
        'admin_name' => $provisionedAdminName,
    ]);

    btProvisionBehavior('service provisions bakeshop tenant with named admin credentials', ($successResult['ok'] ?? false) === true, json_encode($successResult, JSON_UNESCAPED_SLASHES));
    btProvisionBehavior('service success path reports no provisioning errors', empty($successResult['errors'] ?? []), json_encode($successResult, JSON_UNESCAPED_SLASHES));
    btProvisionBehavior('service success path runs at least one migration', (int)($successResult['migrations'] ?? 0) > 0, json_encode($successResult, JSON_UNESCAPED_SLASHES));

    $tenantDb = app()->reconnectDbForTenant($successTenantId);
    btProvisionBehavior('tenant DB can be reconnected through tenant DB factory after provisioning', $tenantDb instanceof PDO);

    if ($tenantDb instanceof PDO) {
        $tableExists = $tenantDb->query("SHOW TABLES LIKE 'bakeshop_users'")->fetchColumn();
        btProvisionBehavior('bakeshop_users table exists after provisioning', $tableExists !== false);

        $adminStmt = $tenantDb->prepare('SELECT username, full_name, role, password_hash, is_active FROM bakeshop_users WHERE username = :username LIMIT 1');
        $adminStmt->execute([':username' => $provisionedAdminUser]);
        $provisionedAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC);

        btProvisionBehavior('provisioning seeds the named admin into bakeshop_users', is_array($provisionedAdmin), json_encode($provisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('seeded admin has bakeshop admin role', ($provisionedAdmin['role'] ?? '') === 'admin', json_encode($provisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('seeded admin name is preserved', ($provisionedAdmin['full_name'] ?? '') === $provisionedAdminName, json_encode($provisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('seeded admin password is hashed and verifiable', is_string($provisionedAdmin['password_hash'] ?? null) && password_verify($provisionedAdminPass, (string)$provisionedAdmin['password_hash']), json_encode($provisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('seeded admin is active', (int)($provisionedAdmin['is_active'] ?? 0) === 1, json_encode($provisionedAdmin, JSON_UNESCAPED_SLASHES));

        $repeatSuccessResult = $service->provision($successTenantId, [
            'admin_user' => $provisionedAdminUser,
            'admin_pass' => $provisionedAdminPass,
            'admin_name' => $provisionedAdminName,
        ]);

        btProvisionBehavior('service reprovision succeeds for an already-provisioned bakeshop tenant', ($repeatSuccessResult['ok'] ?? false) === true, json_encode($repeatSuccessResult, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('service reprovision reports the existing bakeshop admin', str_contains(implode(' | ', (array)($repeatSuccessResult['log'] ?? [])), "User '{$provisionedAdminUser}' already exists in bakeshop_users"), json_encode($repeatSuccessResult, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('service reprovision does not duplicate the bakeshop admin row', bakeshopProvisionUserCount($tenantDb, $provisionedAdminUser) === 1, (string)bakeshopProvisionUserCount($tenantDb, $provisionedAdminUser));
    }

    $safeCliSuccessDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $cliSuccessDbName) ?? $cliSuccessDbName;
    $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeCliSuccessDbName . '`');

    $tenantInsertStmt->execute([
        ':tenant_key' => $cliSuccessTenantKey,
        ':status' => 'active',
        ':entry_module_id' => 'bakeshop',
    ]);
    $cliSuccessTenantId = (int)$controlDb->lastInsertId();

    $connectionInsertStmt->execute([
        ':tenant_id' => $cliSuccessTenantId,
        ':db_driver' => 'mysql',
        ':db_host' => (string)($dbConfig['host'] ?? '127.0.0.1'),
        ':db_port' => (string)($dbConfig['port'] ?? '3306'),
        ':db_name' => $cliSuccessDbName,
        ':db_user' => (string)($dbConfig['username'] ?? ''),
        ':db_pass' => (string)($dbConfig['password'] ?? ''),
        ':db_charset' => (string)($dbConfig['charset'] ?? 'utf8mb4'),
        ':cipher' => '',
        ':iv' => '',
        ':tag' => '',
    ]);

    $cliAdminUser = 'cli-admin-' . bin2hex(random_bytes(3));
    $cliAdminPass = 'CliProvision!' . bin2hex(random_bytes(4));
    $cliAdminName = 'CLI Provisioned Admin';

    $cliSuccessResult = shellWithExitCode(
        'php ' . escapeshellarg(BASE_PATH . '/ikabud')
        . ' tenant:provision ' . escapeshellarg((string)$cliSuccessTenantId)
        . ' --admin-user=' . escapeshellarg($cliAdminUser)
        . ' --admin-pass=' . escapeshellarg($cliAdminPass)
        . ' --admin-name=' . escapeshellarg($cliAdminName) . ' 2>&1'
    );

    btProvisionBehavior('CLI provisions bakeshop tenant with named admin credentials', (int)($cliSuccessResult['exit_code'] ?? 1) === 0, $cliSuccessResult['output']);
    btProvisionBehavior('CLI reports successful tenant DB connection', str_contains((string)($cliSuccessResult['output'] ?? ''), 'Connected to tenant database'), $cliSuccessResult['output']);
    btProvisionBehavior('CLI reports admin seed step', str_contains((string)($cliSuccessResult['output'] ?? ''), 'Seeding admin user'), $cliSuccessResult['output']);
    btProvisionBehavior('CLI reports seeded bakeshop admin row', str_contains((string)($cliSuccessResult['output'] ?? ''), "Admin user '{$cliAdminUser}' created in bakeshop_users"), $cliSuccessResult['output']);

    $cliTenantDb = app()->reconnectDbForTenant($cliSuccessTenantId);
    btProvisionBehavior('CLI-provisioned tenant DB reconnects through tenant DB factory', $cliTenantDb instanceof PDO);

    if ($cliTenantDb instanceof PDO) {
        $cliTableExists = $cliTenantDb->query("SHOW TABLES LIKE 'bakeshop_users'")->fetchColumn();
        btProvisionBehavior('CLI provisioning creates bakeshop_users table', $cliTableExists !== false);

        $cliAdminStmt = $cliTenantDb->prepare('SELECT username, full_name, role, password_hash, is_active FROM bakeshop_users WHERE username = :username LIMIT 1');
        $cliAdminStmt->execute([':username' => $cliAdminUser]);
        $cliProvisionedAdmin = $cliAdminStmt->fetch(PDO::FETCH_ASSOC);

        btProvisionBehavior('CLI provisioning seeds the named admin into bakeshop_users', is_array($cliProvisionedAdmin), json_encode($cliProvisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('CLI-seeded admin has bakeshop admin role', ($cliProvisionedAdmin['role'] ?? '') === 'admin', json_encode($cliProvisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('CLI-seeded admin name is preserved', ($cliProvisionedAdmin['full_name'] ?? '') === $cliAdminName, json_encode($cliProvisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('CLI-seeded admin password is hashed and verifiable', is_string($cliProvisionedAdmin['password_hash'] ?? null) && password_verify($cliAdminPass, (string)$cliProvisionedAdmin['password_hash']), json_encode($cliProvisionedAdmin, JSON_UNESCAPED_SLASHES));
        btProvisionBehavior('CLI-seeded admin is active', (int)($cliProvisionedAdmin['is_active'] ?? 0) === 1, json_encode($cliProvisionedAdmin, JSON_UNESCAPED_SLASHES));

        $cliRepeatResult = shellWithExitCode(
            'php ' . escapeshellarg(BASE_PATH . '/ikabud')
            . ' tenant:provision ' . escapeshellarg((string)$cliSuccessTenantId)
            . ' --admin-user=' . escapeshellarg($cliAdminUser)
            . ' --admin-pass=' . escapeshellarg($cliAdminPass)
            . ' --admin-name=' . escapeshellarg($cliAdminName) . ' 2>&1'
        );

        btProvisionBehavior('CLI reprovision succeeds for an already-provisioned bakeshop tenant', (int)($cliRepeatResult['exit_code'] ?? 1) === 0, $cliRepeatResult['output']);
        btProvisionBehavior('CLI reprovision reports the existing bakeshop admin', str_contains((string)($cliRepeatResult['output'] ?? ''), "User '{$cliAdminUser}' already exists in bakeshop_users"), $cliRepeatResult['output']);
        btProvisionBehavior('CLI reprovision does not duplicate the bakeshop admin row', bakeshopProvisionUserCount($cliTenantDb, $cliAdminUser) === 1, (string)bakeshopProvisionUserCount($cliTenantDb, $cliAdminUser));
    }

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
    btProvisionBehavior('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
    btProvisionBehavior('no error.log errors', $errorLog === '', $errorLog);
} finally {
    foreach ([$successDbName, $cliSuccessDbName] as $dbNameToDrop) {
        if ($dbNameToDrop === '') {
            continue;
        }

        try {
            $dbConfig = require BASE_PATH . '/config/database.php';
            $rootDsn = 'mysql:host=' . ($dbConfig['host'] ?? '127.0.0.1')
                . ';port=' . ($dbConfig['port'] ?? '3306')
                . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4');
            $rootPdo = new PDO(
                $rootDsn,
                (string)($dbConfig['username'] ?? ''),
                (string)($dbConfig['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbNameToDrop) ?? $dbNameToDrop;
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $safeDbName . '`');
        } catch (Throwable $e) {
        }
    }

    foreach ([$failFastTenantId, $successTenantId, $cliSuccessTenantId] as $tenantId) {
        if ($tenantId === null) {
            continue;
        }

        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
        $deleteStmt = $controlDb->prepare('DELETE FROM kernel_tenants WHERE id = :tenant_id');
        $deleteStmt->execute([':tenant_id' => $tenantId]);
    }
}

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