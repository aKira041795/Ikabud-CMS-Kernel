<?php

declare(strict_types=1);

/**
 * Moto Inventory — test support helpers.
 *
 * Bootstraps the app for CLI integration tests and provisions a disposable
 * tenant database (created, migrated, and dropped per run) so module tests
 * never touch real tenant data.
 */

// Server context MUST be set at file scope (before bootstrap.php runs) and
// must override CLI defaults — setting these inside a function, or letting an
// empty CLI value survive, changes app config loading and breaks the
// control-plane connection.
$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'applicationos.test';

/**
 * NOTE: bootstrap.php MUST be required in GLOBAL scope (top of the test file),
 * not from inside a function — its $config only becomes visible to app() via
 * the global scope. Tests require this chain at file scope:
 *
 *   require_once __DIR__ . '/moto_inventory_test_helper.php';
 *   require_once __DIR__ . '/../bootstrap.php';
 *   require_once __DIR__ . '/../src/helpers/module-manager.php';
 *   require_once __DIR__ . '/../modules/moto-inventory/helpers.php';
 *   require_once __DIR__ . '/../modules/moto-inventory/handlers.php';
 */

/**
 * Build a plain PDO with the configured DB credentials.
 */
function moto_test_root_pdo(): PDO
{
    $host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
    $port = (string)($_ENV['DB_PORT'] ?? '3306');
    $user = (string)($_ENV['DB_USERNAME'] ?? 'root');
    $pass = (string)($_ENV['DB_PASSWORD'] ?? '');
    $charset = (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4');

    return new PDO(
        "mysql:host={$host};port={$port};charset={$charset}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/**
 * Provision a disposable tenant + fresh database, run the module migrations,
 * and return a handle with a cleanup closure.
 *
 * @return array{tenant_id:int, tenant_key:string, pdo:PDO, cleanup:callable}
 */
function moto_test_create_tenant(): array
{
    $suffix = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
    $tenantKey = 'moto-test-' . $suffix;
    $dbName = 'moto_test_' . $suffix;
    $domain = 'moto-' . $suffix . '.test';

    $root = moto_test_root_pdo();
    $root->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Register the tenant in the control plane.
    $appDb = app()->controlDb();
    $stmt = $appDb->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:key, :status, :entry)');
    $stmt->execute([':key' => $tenantKey, ':status' => 'active', ':entry' => null]);
    $tenantId = (int)$appDb->lastInsertId();

    $dStmt = $appDb->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :dom)');
    $dStmt->execute([':tid' => $tenantId, ':dom' => $domain]);

    $cStmt = $appDb->prepare(
        'INSERT INTO kernel_tenant_db_connections (tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset)
         VALUES (:tid, :driver, :host, :port, :dbname, :user, :pass, :charset)'
    );
    $cStmt->execute([
        ':tid'     => $tenantId,
        ':driver'  => 'mysql',
        ':host'    => (string)($_ENV['DB_HOST'] ?? '127.0.0.1'),
        ':port'    => (string)($_ENV['DB_PORT'] ?? '3306'),
        ':dbname'  => $dbName,
        ':user'    => (string)($_ENV['DB_USERNAME'] ?? 'root'),
        ':pass'    => (string)($_ENV['DB_PASSWORD'] ?? ''),
        ':charset' => 'utf8mb4',
    ]);

    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ";port=" . ($_ENV['DB_PORT'] ?? '3306') . ";dbname={$dbName};charset=utf8mb4",
        (string)($_ENV['DB_USERNAME'] ?? 'root'),
        (string)($_ENV['DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Run the module migrations on the fresh tenant DB.
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($pdo);
    $runner->migrate('moto-inventory');

    $cleanup = static function () use ($tenantId, $dbName, $appDb, $root, $tenantKey, &$pdo): void {
        try {
            $pdo = null; // release the tenant connection so DROP DATABASE succeeds
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $stmt = $appDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tid');
            $stmt->execute([':tid' => $tenantId]);
            $stmt = $appDb->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tid');
            $stmt->execute([':tid' => $tenantId]);
            $stmt = $appDb->prepare('DELETE FROM kernel_tenants WHERE id = :tid');
            $stmt->execute([':tid' => $tenantId]);
        } catch (\Throwable $e) {
            // Best-effort cleanup.
        }
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        } catch (\Throwable $e) {
            // Best-effort cleanup.
        }
    };

    return ['tenant_id' => $tenantId, 'tenant_key' => $tenantKey, 'pdo' => $pdo, 'cleanup' => $cleanup];
}

/**
 * Build a test ctx (admin with all permissions) for a tenant.
 */
function moto_test_admin_ctx(int $tenantId, array $branchIds = []): array
{
    $perms = [
        'moto_inventory.manage', 'moto_inventory.sell', 'moto_inventory.void',
        'moto_inventory.view_cost', 'moto_inventory.view_profit',
        'moto_inventory.view_audit', 'moto_inventory.view_all_branches',
    ];

    return [
        'tenant_id' => $tenantId,
        'user'      => ['id' => 1, 'name' => 'Test Admin', 'role' => 'admin', 'source' => 'kernel'],
        'user_id'   => 1,
        'actor_name'=> 'Test Admin',
        'role'      => 'admin',
        'permissions' => $perms,
        'view_all_branches' => true,
        'branch_ids' => $branchIds,
    ];
}

/**
 * Build a test ctx for a limited user (cashier-like).
 */
function moto_test_cashier_ctx(int $tenantId, array $branchIds = []): array
{
    return [
        'tenant_id' => $tenantId,
        'user'      => ['id' => 2, 'name' => 'Test Cashier', 'role' => 'cashier', 'source' => 'kernel'],
        'user_id'   => 2,
        'actor_name'=> 'Test Cashier',
        'role'      => 'cashier',
        'permissions' => ['moto_inventory.sell'],
        'view_all_branches' => false,
        'branch_ids' => $branchIds,
    ];
}
