<?php

declare(strict_types=1);

/**
 * Moto Inventory — Migration Test (disposable tenant DB).
 *
 * Verifies a clean install on a fresh tenant database, idempotent rerun,
 * expected tables/indexes/foreign keys, MySQL 5.7 compatibility of the SQL,
 * and `_migrations` registration.
 *
 * Run: php tests/moto_inventory_migration_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-migration', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/database/migrations/001_moto_inventory_core.sql');
$h->fingerprint('modules/moto-inventory/database/migrations/002_moto_inventory_sales_and_movements.sql');
$h->fingerprint('modules/moto-inventory/database/migrations/003_moto_inventory_import_audit_and_idempotency.sql');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenant provisioned', false, $e->getMessage());
    $h->gap('Migration integration requires MySQL — skipped');
    $h->done();
}

$pdo = $tenant['pdo'];

$h->section('Clean install');

$expectedTables = [
    'moto_branches', 'moto_user_branches', 'moto_brands', 'moto_products',
    'moto_stock_movements', 'moto_sales', 'moto_sale_items', 'moto_imports',
    'moto_import_rows', 'moto_audit_log', 'moto_idempotency_keys',
    'moto_preferences', 'moto_backups',
];
foreach ($expectedTables as $table) {
    $exists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
    )->fetchColumn();
    $h->test("table created: {$table}", $exists === 1);
}

$h->section('Indexes & foreign keys');

$h->test('products unique (tenant,branch,brand,part)', (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_products' AND INDEX_NAME = 'uq_moto_product'"
)->fetchColumn() > 0);
$h->test('sales unique idempotency index', (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_sales' AND INDEX_NAME = 'uq_moto_sale_idem'"
)->fetchColumn() > 0);
$h->test('movement product index', (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_stock_movements' AND INDEX_NAME = 'idx_moto_movement_product'"
)->fetchColumn() > 0);

$fkCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'moto_%'"
)->fetchColumn();
$h->test('foreign keys enforced (InnoDB)', $fkCount >= 5);

$engine = (string)$pdo->query(
    "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_products'"
)->fetchColumn();
$h->test('tables use InnoDB', $engine === 'InnoDB');

$charset = (string)$pdo->query(
    "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_products'"
)->fetchColumn();
$h->test('utf8mb4 collation', str_starts_with($charset, 'utf8mb4_'));

$h->section('Idempotent rerun');

$runner = new \Ikabud\Kernel\Database\MigrationRunner($pdo);
$rerun = $runner->migrate('moto-inventory');
$h->test('rerun executes nothing', $rerun === []);

$regCount = (int)$pdo->query("SELECT COUNT(*) FROM `_migrations` WHERE module = 'moto-inventory'")->fetchColumn();
$h->test('migrations registered in _migrations (3)', $regCount === 3);

$h->section('Kernel-users provisioning bootstrap');

$hasUsersBefore = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
)->fetchColumn();
$h->test('fresh tenant DB has no users table yet', $hasUsersBefore === 0);

$bootstrapped = tenantEnsureKernelUserTable($pdo, 'moto-inventory');
$h->test('tenantEnsureKernelUserTable bootstraps users for kernel-users module', $bootstrapped === true);

$usersCols = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$h->test('users has password_hash column', in_array('password_hash', $usersCols, true));
$h->test('users has is_active column', in_array('is_active', $usersCols, true));
$h->test('users has role column', in_array('role', $usersCols, true));
$h->test('users has token_version column', in_array('token_version', $usersCols, true));

$idempotent = tenantEnsureKernelUserTable($pdo, 'moto-inventory');
$h->test('bootstrap is idempotent (no-op when users exists)', $idempotent === false);

$noop = tenantEnsureKernelUserTable($pdo, 'cms');
$h->test('auth_owned entry module (cms) does NOT get a kernel users table', $noop === false);

$h->section('moto_ctx permission shape (regression)');

app()->setUser(['id' => 1, 'name' => 'Test Admin', 'role' => 'admin', 'source' => 'kernel']);
app()->tenant()->setTenantId($tenant['tenant_id']);
$mctx = moto_ctx();
$h->test('permissions is a list of permission string ids', is_array($mctx['permissions'] ?? null)
    && count($mctx['permissions']) === 7
    && in_array('moto_inventory.manage', $mctx['permissions'], true));
$h->test('sell gate passes for admin (strict string match)', in_array('moto_inventory.sell', $mctx['permissions'], true));
$h->test('audit gate passes for admin', in_array('moto_inventory.view_audit', $mctx['permissions'], true));

$h->section('MySQL 5.7 compatibility');

$migrationSql = '';
foreach (['001_moto_inventory_core.sql', '002_moto_inventory_sales_and_movements.sql', '003_moto_inventory_import_audit_and_idempotency.sql'] as $file) {
    $migrationSql .= (string)file_get_contents($base . '/modules/moto-inventory/database/migrations/' . $file);
}
$h->test('no window functions', !preg_match('/OVER\s*\(/i', $migrationSql));
$h->test('no CTEs', !preg_match('/\bWITH\b\s+[A-Za-z_][A-Za-z0-9_]*\s+AS\s*\(/i', $migrationSql));
$h->test('no JSON_TABLE', !preg_match('/JSON_TABLE/i', $migrationSql));
$h->test('no CHECK constraints', !preg_match('/\bCHECK\s*\(/i', $migrationSql));
$h->test('no EXCEPT/INTERSECT', !preg_match('/\b(EXCEPT|INTERSECT)\b/i', $migrationSql));
$h->test('every CREATE TABLE ends with InnoDB utf8mb4', substr_count($migrationSql, 'CREATE TABLE IF NOT EXISTS') === substr_count($migrationSql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));

$h->section('Money-safe columns');

$priceType = (string)$pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_sale_items' AND COLUMN_NAME = 'price'"
)->fetchColumn();
$h->test('sale item price is DECIMAL(14,2)', $priceType === 'decimal(14,2)');
$qtyType = (string)$pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_stock_movements' AND COLUMN_NAME = 'quantity'"
)->fetchColumn();
$h->test('movement quantity is DECIMAL(14,4)', $qtyType === 'decimal(14,4)');
$utcCols = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'moto_sales' AND COLUMN_NAME IN ('created_at','updated_at','voided_at')"
)->fetchColumn();
$h->test('sales has timestamp columns', $utcCols >= 2);

$tenant['cleanup']();
$h->done();
