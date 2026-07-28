<?php

declare(strict_types=1);

/**
 * Bakeshop — Transfers, Stocktakes, Recipe Versions Integration Test
 *
 * Tests migration 020 tables and the three new services.
 *
 * Usage: php tests/bakeshop_transfers_stocktakes_recipes_test.php
 */

ob_start();
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/admin/bakeshop';
require __DIR__ . '/../bootstrap.php';
ob_clean();
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';
require_once __DIR__ . '/../modules/bakeshop/Services/InventoryLedgerService.php';
require_once __DIR__ . '/../modules/bakeshop/Services/TransferService.php';
require_once __DIR__ . '/../modules/bakeshop/Services/StocktakeService.php';
require_once __DIR__ . '/../modules/bakeshop/Services/RecipeVersionService.php';

$pass = 0; $fail = 0;
function bt(string $l, bool $o, string $d = ''): void { global $pass, $fail; if ($o) { $pass++; echo "  \u{2713} {$l}\n"; } else { $fail++; echo "  \u{2717} {$l}" . ($d !== '' ? " \u{2014} {$d}" : '') . "\n"; } }
function section(string $t): void { echo "\n\u{2500}\u{2500} {$t} \u{2500}\u{2500}\n"; }

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');
echo "\n=== BAKESHOP TRANSFERS / STOCKTAKES / RECIPES TEST ===\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// Setup test data
$kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
$branch1 = bakeshopDeliveriesCreateBranch(['code' => 'TSR-A-' . substr($suffix,0,4), 'name' => 'TSR Branch A', 'address' => 'A']);
$branch1Id = (int)($branch1['id'] ?? 0);
$branch2 = bakeshopDeliveriesCreateBranch(['code' => 'TSR-B-' . substr($suffix,0,4), 'name' => 'TSR Branch B', 'address' => 'B']);
$branch2Id = (int)($branch2['id'] ?? 0);
$db->prepare('INSERT INTO bakeshop_ingredients (sku, name, default_unit_id, is_active, created_at, updated_at) VALUES (:s,:n,:uid,1,NOW(),NOW())')->execute([':s'=>'TSR-ING-'.$suffix, ':n'=>'TSR Ingredient', ':uid'=>$kgUnitId]);
$ingId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO bakeshop_products (sku, name, category, default_yield_qty, default_yield_unit_id, is_active, created_at, updated_at) VALUES (:s,:n,:c,1,:uid,1,NOW(),NOW())')->execute([':s'=>'TSR-PRD-'.$suffix, ':n'=>'TSR Product', ':c'=>'Bread', ':uid'=>$kgUnitId]);
$prodId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO bakeshop_product_recipe (product_id, ingredient_id, qty, unit_id, created_at, updated_at) VALUES (:pid,:iid,0.5,:uid,NOW(),NOW())')->execute([':pid'=>$prodId, ':iid'=>$ingId, ':uid'=>$kgUnitId]);
bt('test data seeded', $branch1Id > 0 && $branch2Id > 0 && $ingId > 0 && $prodId > 0);

// ═══════════════════════════════════════════════════════════════
// 1. Transfer Service
// ═══════════════════════════════════════════════════════════════
section('1. Transfer Service');

$svc = new BakeshopTransferService();
$draft = $svc->createDraft(['branch_id' => $branch1Id, 'destination_branch_id' => $branch2Id, 'transfer_date' => date('Y-m-d')]);
$transferId = (int)($draft['id'] ?? 0);
bt('draft transfer created', $transferId > 0);

$svc->addItem($transferId, $ingId, 10.0, $kgUnitId, 25.0);
bt('item added to transfer', true);

$caught = false;
try { $svc->dispatch($transferId, 1); } catch (\RuntimeException $e) { $caught = str_contains($e->getMessage(), 'Insufficient'); }
bt('dispatch rejected without stock', $caught);

// Add stock via ledger
$ledger = new BakeshopInventoryLedgerService();
$ledger->recordMovement(['branch_id'=>$branch1Id, 'ingredient_id'=>$ingId, 'movement_type'=>'receipt', 'reference_type'=>'test', 'reference_id'=>1, 'qty'=>100, 'unit_id'=>$kgUnitId, 'unit_cost'=>20]);

$dispatched = $svc->dispatch($transferId, 1);
bt('dispatch succeeds with stock', ($dispatched['status'] ?? '') === 'dispatched');
$balanceA = $ledger->getBalance($branch1Id, $ingId);
bt('source branch debited after dispatch', abs($balanceA - 90) < 0.001, "got {$balanceA}");

$received = $svc->receive($transferId, 2);
bt('receive succeeds', ($received['status'] ?? '') === 'received');
$balanceB = $ledger->getBalance($branch2Id, $ingId);
bt('destination branch credited after receive', abs($balanceB - 10) < 0.001, "got {$balanceB}");

// ═══════════════════════════════════════════════════════════════
// 2. Stocktake Service
// ═══════════════════════════════════════════════════════════════
section('2. Stocktake Service');

$ssvc = new BakeshopStocktakeService($ledger);
$session = $ssvc->createDraft($branch1Id, date('Y-m-d'));
$sessionId = (int)($session['id'] ?? 0);
bt('stocktake session created', $sessionId > 0);
bt('session has items', count($session['items'] ?? []) > 0);

// Find the item for our ingredient
$item = null;
foreach ($session['items'] as $si) { if ((int)$si['ingredient_id'] === $ingId) { $item = $si; break; } }
bt('session includes test ingredient', $item !== null);
bt('expected_qty matches ledger balance', $item !== null && abs((float)$item['expected_qty'] - 90) < 0.001, "got " . ($item['expected_qty'] ?? '?'));

if ($item) {
    $ssvc->recordCount($sessionId, (int)$item['id'], 85, 1);
    bt('count recorded', true);
}

$counted = $ssvc->markCounted($sessionId, 1);
bt('session marked counted', ($counted['status'] ?? '') === 'counted');

$reviewed = $ssvc->markReviewed($sessionId, 2);
bt('session marked reviewed', ($reviewed['status'] ?? '') === 'reviewed');

$posted = $ssvc->post($sessionId, 3);
bt('session posted', ($posted['status'] ?? '') === 'posted');
$balAfterStocktake = $ledger->getBalance($branch1Id, $ingId);
bt('balance adjusted after stocktake post', abs($balAfterStocktake - 85) < 0.001, "got {$balAfterStocktake}");

// ═══════════════════════════════════════════════════════════════
// 3. Recipe Version Service
// ═══════════════════════════════════════════════════════════════
section('3. Recipe Version Service');

$rsvc = new BakeshopRecipeVersionService();
$headerId = $rsvc->snapshot($prodId, 'Initial version');
bt('recipe version snapshot created', $headerId > 0);

$latest = $rsvc->getLatestVersion($prodId);
bt('latest version retrievable', $latest !== null);
bt('version has lines', count($latest['lines'] ?? []) > 0);
bt('version line qty matches recipe', abs((float)($latest['lines'][0]['qty'] ?? 0) - 0.5) < 0.001);

$headerId2 = $rsvc->snapshot($prodId, 'Second version');
bt('second version created', $headerId2 > 0 && $headerId2 !== $headerId);

$history = $rsvc->getVersionHistory($prodId);
bt('version history has 2 entries', count($history) === 2);

$v1 = $rsvc->getVersion($headerId);
bt('specific version retrievable by id', $v1 !== null && (int)($v1['id'] ?? 0) === $headerId);

// ═══════════════════════════════════════════════════════════════
// 4. Log Check
// ═══════════════════════════════════════════════════════════════
section('4. Log Check');
$appLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');
bt('no critical errors in app.log', substr_count($appLog, '[critical]') === 0);
bt('no critical errors in error.log', substr_count($errorLog, '[critical]') === 0);

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════
section('Cleanup');
$db->prepare('DELETE FROM bakeshop_inventory_movements WHERE branch_id IN (?, ?)')->execute([$branch1Id, $branch2Id]);
$db->prepare('DELETE FROM bakeshop_recipe_version_lines WHERE recipe_header_id IN (?, ?)')->execute([$headerId, $headerId2]);
$db->prepare('DELETE FROM bakeshop_recipe_headers WHERE product_id = ?')->execute([$prodId]);
$db->prepare('DELETE FROM bakeshop_stocktake_items WHERE session_id = ?')->execute([$sessionId]);
$db->prepare('DELETE FROM bakeshop_stocktake_sessions WHERE id = ?')->execute([$sessionId]);
$db->prepare('DELETE FROM bakeshop_transfer_items WHERE transfer_id = ?')->execute([$transferId]);
$db->prepare('DELETE FROM bakeshop_transfers WHERE id = ?')->execute([$transferId]);
$db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$prodId]);
$db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$prodId]);
$db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$ingId]);
$db->prepare('DELETE FROM bakeshop_branches WHERE id IN (?, ?)')->execute([$branch1Id, $branch2Id]);
bt('cleanup done', true);

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 55) . "\n";
echo "  RESULTS\n  {$pass}/" . ($pass + $fail) . " passed, {$fail} failed\n";
echo str_repeat('═', 55) . "\n";
if ($fail > 0) exit(1);
echo "  \u{1f389} All tests passed.\n\n";
exit(0);
