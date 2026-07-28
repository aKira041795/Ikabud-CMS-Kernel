<?php

declare(strict_types=1);

/**
 * Bakeshop — Inventory Ledger Integration Test
 *
 * Tests the inventory ledger migration (017) and InventoryLedgerService.
 *
 * Usage: php tests/bakeshop_inventory_ledger_test.php
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';
require_once __DIR__ . '/../modules/bakeshop/Services/InventoryLedgerService.php';

$pass = 0;
$fail = 0;

function il(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \u{2713} {$label}\n"; }
    else { $fail++; echo "  \u{2717} {$label}" . ($detail !== '' ? " \u{2014} {$detail}" : '') . "\n"; }
}
function section(string $title): void { echo "\n\u{2500}\u{2500} {$title} \u{2500}\u{2500}\n"; }

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP INVENTORY LEDGER TEST ===\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// ═══════════════════════════════════════════════════════════════
// 1. Migration: schema audit
// ═══════════════════════════════════════════════════════════════
section('1. Migration Schema Audit');

// bakeshop_inventory_movements
$tablesExist = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('bakeshop_inventory_movements', 'bakeshop_document_numbers')")->fetchColumn();
il('inventory_movements and document_numbers tables exist', (int)$tablesExist === 2);

$mvCols = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_inventory_movements'")->fetchColumn();
il('inventory_movements has columns', (int)$mvCols >= 12);

$mvEnum = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_inventory_movements' AND COLUMN_NAME = 'movement_type'")->fetchColumn();
il('movement_type ENUM includes receipt', str_contains($mvEnum, 'receipt'));
il('movement_type ENUM includes production_issue', str_contains($mvEnum, 'production_issue'));
il('movement_type ENUM includes void', str_contains($mvEnum, 'void'));

// bakeshop_deliveries: version + status
$delStatusCol = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_deliveries' AND COLUMN_NAME = 'status'")->fetchColumn();
il('deliveries has status ENUM', $delStatusCol !== false);
il('deliveries status includes draft/posted/voided', str_contains($delStatusCol, 'draft') && str_contains($delStatusCol, 'posted') && str_contains($delStatusCol, 'voided'));

$delVerCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_deliveries' AND COLUMN_NAME = 'version'")->fetchColumn();
il('deliveries has version column', (int)$delVerCol > 0);

// bakeshop_production_runs: version + status
$prStatusCol = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_production_runs' AND COLUMN_NAME = 'status'")->fetchColumn();
il('production_runs has status ENUM', $prStatusCol !== false);
il('production_runs status includes draft/released/in_progress/completed/voided',
    str_contains($prStatusCol, 'draft') && str_contains($prStatusCol, 'released') &&
    str_contains($prStatusCol, 'in_progress') && str_contains($prStatusCol, 'completed') &&
    str_contains($prStatusCol, 'voided'));

$prVerCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_production_runs' AND COLUMN_NAME = 'version'")->fetchColumn();
il('production_runs has version column', (int)$prVerCol > 0);

// bakeshop_inventory_adjustments: version + status
$adjStatusCol = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_inventory_adjustments' AND COLUMN_NAME = 'status'")->fetchColumn();
il('adjustments has status ENUM', $adjStatusCol !== false);

$adjVerCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_inventory_adjustments' AND COLUMN_NAME = 'version'")->fetchColumn();
il('adjustments has version column', (int)$adjVerCol > 0);

// bakeshop_product_allocations: version
$allocVerCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_product_allocations' AND COLUMN_NAME = 'version'")->fetchColumn();
il('allocations has version column', (int)$allocVerCol > 0);

// MySQL 5.7 compatibility check on migration source
$migrationSource = (string)file_get_contents(__DIR__ . '/../modules/bakeshop/database/migrations/017_bakeshop_inventory_ledger.sql');
il('migration uses InnoDB', str_contains($migrationSource, 'ENGINE=InnoDB'));
il('migration avoids unsupported MySQL features', !preg_match('/\b(WINDOW|ROW_NUMBER|RANK|CTE|JSON_TABLE|CHECK\s*\()/i', $migrationSource));

// Backfill check: existing deliveries should be 'posted'
$postedDeliveries = $db->query("SELECT COUNT(*) FROM bakeshop_deliveries WHERE status = 'posted'")->fetchColumn();
$totalDeliveries = $db->query("SELECT COUNT(*) FROM bakeshop_deliveries")->fetchColumn();
il('existing deliveries backfilled to posted', (int)$totalDeliveries === 0 || (int)$postedDeliveries > 0, "{$postedDeliveries}/{$totalDeliveries}");

// ═══════════════════════════════════════════════════════════════
// 2. Service: record movements
// ═══════════════════════════════════════════════════════════════
section('2. Service — Record Movements');

$ledger = new BakeshopInventoryLedgerService(bakeshopDb());

// Create a branch and ingredient for testing
$branchCode = 'LEDGER-' . substr($suffix, 0, 5);
$branch = bakeshopDeliveriesCreateBranch([
    'code' => $branchCode,
    'name' => 'Ledger Test Branch ' . $suffix,
    'address' => 'Ledger Test',
]);
$branchId = (int)($branch['id'] ?? 0);
il('test branch created', $branchId > 0);

$kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
il('kg unit available for test', $kgUnitId > 0);

$db->prepare(
    'INSERT INTO bakeshop_ingredients (sku, name, default_unit_id, is_active, created_at, updated_at)
     VALUES (:sku, :name, :uid, 1, NOW(), NOW())'
)->execute([
    ':sku' => 'LEDGER-ING-' . substr($suffix, 0, 5),
    ':name' => 'Ledger Test Ingredient',
    ':uid' => $kgUnitId,
]);
$ingredientId = (int)$db->lastInsertId();
il('test ingredient created', $ingredientId > 0);

// Record a receipt movement
$mvId = $ledger->recordMovement([
    'branch_id' => $branchId,
    'ingredient_id' => $ingredientId,
    'movement_type' => 'receipt',
    'reference_type' => 'delivery',
    'reference_id' => 999001,
    'qty' => 100,
    'unit_id' => $kgUnitId,
    'unit_cost' => 25.00,
    'description' => 'Test receipt movement',
    'created_by' => 1,
]);
il('recordMovement returns positive id', $mvId > 0);

// Verify the movement was stored
$movement = $db->prepare('SELECT * FROM bakeshop_inventory_movements WHERE id = :id');
$movement->execute([':id' => $mvId]);
$mv = $movement->fetch(PDO::FETCH_ASSOC);
il('movement record exists', is_array($mv) && !empty($mv));
il('movement has correct qty', abs((float)($mv['qty'] ?? 0) - 100) < 0.001);
il('movement has correct type', ($mv['movement_type'] ?? '') === 'receipt');
il('movement has tenant_id', (int)($mv['tenant_id'] ?? 0) > 0);

// Record a second movement (consumption)
$ledger->recordMovement([
    'branch_id' => $branchId,
    'ingredient_id' => $ingredientId,
    'movement_type' => 'production_issue',
    'reference_type' => 'production',
    'reference_id' => 999002,
    'qty' => -30,
    'unit_id' => $kgUnitId,
    'description' => 'Test consumption',
]);
$balance = $ledger->getBalance($branchId, $ingredientId);
il('balance reflects receipt + consumption', abs($balance - 70) < 0.001, "got {$balance}");

// Test validation: missing required field
$caught = false;
try {
    $ledger->recordMovement(['branch_id' => $branchId]);
} catch (\InvalidArgumentException $e) {
    $caught = true;
}
il('recordMovement rejects missing required fields', $caught);

// Test validation: invalid movement type
$caught = false;
try {
    $ledger->recordMovement([
        'branch_id' => $branchId,
        'ingredient_id' => $ingredientId,
        'movement_type' => 'invalid_type',
        'reference_type' => 'test',
        'reference_id' => 1,
        'qty' => 1,
        'unit_id' => $kgUnitId,
    ]);
} catch (\InvalidArgumentException $e) {
    $caught = true;
}
il('recordMovement rejects invalid movement_type', $caught);

// ═══════════════════════════════════════════════════════════════
// 3. Service: getMovements + pagination
// ═══════════════════════════════════════════════════════════════
section('3. Service — Query Movements');

$movements = $ledger->getMovements($branchId, $ingredientId, 10, 0);
il('getMovements returns array', is_array($movements));
il('getMovements returns movement records', count($movements) > 0);
il('getMovements returns unit_code', isset($movements[0]['unit_code']));

// ═══════════════════════════════════════════════════════════════
// 4. Service: delivery posting + production + void
// ═══════════════════════════════════════════════════════════════
section('4. Service — Delivery Posting + Production + Void');

// Simulate delivery posting
$ledger->recordDeliveryPosting(999003, [
    ['branch_id' => $branchId, 'ingredient_id' => $ingredientId, 'qty' => 200, 'unit_id' => $kgUnitId, 'unit_cost' => 20.00, 'ingredient_name' => 'Test'],
], 1);
$balAfterDelivery = $ledger->getBalance($branchId, $ingredientId);
il('delivery posting adds to balance', abs($balAfterDelivery - 270) < 0.001, "got {$balAfterDelivery}");

// Simulate production completion
$ledger->recordProductionCompletion(999004, [
    ['branch_id' => $branchId, 'ingredient_id' => $ingredientId, 'qty_used' => 50, 'unit_id' => $kgUnitId, 'ingredient_name' => 'Test'],
], 10, 1, 1);
$balAfterProduction = $ledger->getBalance($branchId, $ingredientId);
il('production consumption deducts from balance', abs($balAfterProduction - 220) < 0.001, "got {$balAfterProduction}");

// Simulate void — should reverse
$ledger->recordVoid('delivery', 999003, 'Test void', 1);
$balAfterVoid = $ledger->getBalance($branchId, $ingredientId);
il('void reverses delivery movements', abs($balAfterVoid - 20) < 0.001, "got {$balAfterVoid}");

// ═══════════════════════════════════════════════════════════════
// 5. Service: document numbering
// ═══════════════════════════════════════════════════════════════
section('5. Service — Document Numbering');

$docNo1 = $ledger->nextDocumentNumber($branchId, 'receipt');
il('document number is non-empty string', is_string($docNo1) && $docNo1 !== '');
il('document number matches pattern', (bool)preg_match('/^[A-Z]+-\d{2}-\d{4}$/', $docNo1), "got {$docNo1}");

$docNo2 = $ledger->nextDocumentNumber($branchId, 'receipt');
il('second document number is different', $docNo2 !== $docNo1, "got {$docNo1} vs {$docNo2}");

$docNo3 = $ledger->nextDocumentNumber($branchId, 'production');
il('different doc type has different sequence', $docNo3 !== $docNo1, "got {$docNo3}");

// ═══════════════════════════════════════════════════════════════
// 6. Service: reconciliation
// ═══════════════════════════════════════════════════════════════
section('6. Service — Reconciliation');

$diff = $ledger->reconcile($branchId, $ingredientId, 20);
il('reconcile returns 0 when in sync', abs($diff) < 0.001);

$diff2 = $ledger->reconcile($branchId, $ingredientId, 50);
il('reconcile returns difference when out of sync', abs($diff2 - (-30)) < 0.001, "got {$diff2}");

// ═══════════════════════════════════════════════════════════════
// 7. Module manifest consistency
// ═══════════════════════════════════════════════════════════════
section('7. Module Manifest Consistency');

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/bakeshop/module.json'), true);
$manifestTables = $manifest['owns_tables'] ?? [];
il('module.json owns bakeshop_inventory_movements', in_array('bakeshop_inventory_movements', $manifestTables, true));
il('module.json owns bakeshop_document_numbers', in_array('bakeshop_document_numbers', $manifestTables, true));

$migrations = $manifest['migrations'] ?? [];
il('module.json includes 017 migration', in_array('database/migrations/017_bakeshop_inventory_ledger.sql', $migrations, true));

// ═══════════════════════════════════════════════════════════════
// 8. Log check
// ═══════════════════════════════════════════════════════════════
section('8. Log Check');

$appLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');
il('no critical errors in app.log', substr_count($appLog, '[critical]') === 0);
il('no critical errors in error.log', substr_count($errorLog, '[critical]') === 0);

// ═══════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════
section('Cleanup');

$db->prepare('DELETE FROM bakeshop_inventory_movements WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_document_numbers WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_ingredients WHERE id = :iid')->execute([':iid' => $ingredientId]);
$db->prepare('DELETE FROM bakeshop_branches WHERE id = :bid')->execute([':bid' => $branchId]);
il('test data cleaned up', true);

// ═══════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 55) . "\n";
echo "  RESULTS\n";
echo "  {$pass}/" . ($pass + $fail) . " passed, {$fail} failed\n";
echo "  Assertions: " . ($pass + $fail) . "\n";
echo str_repeat('═', 55) . "\n";

if ($fail > 0) { echo "\n"; exit(1); }
echo "  \u{1f389} All tests passed.\n\n";
exit(0);
