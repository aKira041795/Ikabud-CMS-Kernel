<?php
/**
 * DC Cafe — Box (variable product) composition test.
 *
 * Guards the invariants that matter for boxed doughnut orders:
 *   1. the component table is declared in the manifest (ModuleDB denies otherwise)
 *   2. boxes hold no stock of their own, so components are not double-deducted
 *   3. component movements stay movement_type='sale' so void can restore them
 *   4. box consumption is reported as pullout and rebalances the worksheet
 *      without disturbing the manually entered pullout cell
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-box', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../src/helpers/module-migrations.php';
require_once __DIR__ . '/../../modules/dc-cafe/handlers-inventory.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers/boxes.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/handlers-inventory.php');
$h->fingerprint('modules/dc-cafe/helpers/boxes.php');
$h->fingerprint('modules/dc-cafe/database/migrations/032_add_movement_consumption_channel.sql');
$h->fingerprint('modules/dc-cafe/database/migrations/033_create_box_composition.sql');
$h->fingerprint('templates/modules/dc-cafe/pos/index.disyl');
$h->fingerprint('templates/modules/dc-cafe/inventory/ledger.disyl');

// ── 1. Manifest declares the new table ──
// Regression guard: an undeclared table is denied by ModuleDB at runtime with a
// 500, which is exactly how the first build of this feature failed.
$h->section('Manifest Declarations');
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json'), true);
$ownsTables = $manifest['owns_tables'] ?? [];
$h->test('dc_product_components declared in owns_tables', in_array('dc_product_components', $ownsTables, true));
$h->test(
    'box migrations registered in manifest',
    in_array('database/migrations/033_create_box_composition.sql', $manifest['migrations'] ?? [], true)
    && in_array('database/migrations/032_add_movement_consumption_channel.sql', $manifest['migrations'] ?? [], true)
);

$routes = require __DIR__ . '/../../modules/dc-cafe/routes.php';
$h->test('box-options route registered', isset($routes['GET']['/dc-cafe/api/v1/products/{id}/box-options']));

// ── 2. Schema ──
$h->section('Schema');
$db = app()->db();
$h->test('DB connection available', $db instanceof PDO);
if (!$db instanceof PDO) {
    $h->done();
}

$componentCols = $db->query(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dc_product_components'"
)->fetchAll(PDO::FETCH_COLUMN);
$h->test('dc_product_components exists', $componentCols !== []);
$h->test(
    'dc_product_components has slot + component columns',
    in_array('slot_no', $componentCols, true) && in_array('component_product_id', $componentCols, true)
);

$movementCols = $db->query(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dc_product_stock_movements'"
)->fetchAll(PDO::FETCH_COLUMN);
$h->test(
    'movement table carries box provenance columns',
    in_array('consumption_channel', $movementCols, true) && in_array('bundle_product_id', $movementCols, true)
);

$productCols = $db->query(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dc_products'"
)->fetchAll(PDO::FETCH_COLUMN);
$h->test(
    'product table carries box configuration columns',
    in_array('slot_count', $productCols, true)
    && in_array('component_category_id', $productCols, true)
    && in_array('component_min_price', $productCols, true)
    && in_array('component_max_price', $productCols, true)
);

// ── 3. Boxes hold no stock of their own ──
$h->section('Box Configuration');
$boxes = $db->query(
    "SELECT product_id, name, slot_count, has_stock
     FROM dc_products WHERE slot_count IS NOT NULL AND is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);
$h->test('at least one box is configured', count($boxes) > 0);

$doubleDeduct = [];
foreach ($boxes as $box) {
    if ((int) $box['has_stock'] === 1) {
        $doubleDeduct[] = $box['name'];
    }
}
$h->test(
    'no box tracks its own stock (would double-deduct components)',
    $doubleDeduct === [],
    $doubleDeduct === [] ? '' : 'has_stock=1 on: ' . implode(', ', $doubleDeduct)
);

$badSlots = [];
foreach ($boxes as $box) {
    if ((int) $box['slot_count'] <= 0) {
        $badSlots[] = $box['name'];
    }
}
$h->test('every box declares a positive slot count', $badSlots === []);

// ── 4. Void safety: component movements must remain restorable ──
// The void handler selects restore candidates with movement_type = 'sale'.
// Tags are provenance on top of that, never a replacement for it.
$h->section('Void Restore Contract');
$ordersSource = (string) file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');
$h->test(
    'void selects restore candidates by movement_type = sale',
    str_contains($ordersSource, "movement_type = 'sale'")
);
$h->test(
    'box component movements are written with movement_type sale',
    (bool) preg_match("/consumption_channel, bundle_product_id, reference_type, reference_id[\s\S]{0,200}'sale', 'bundle'/", $ordersSource)
);

// ── 5. Reconciliation maths ──
$h->section('Reconciliation Balances With Box Pullout');
$noBox = _dcInventoryDerivedMetrics(10.0, 0.0, 0.0, 4.0, 0.0, 0.0, 0.0);
$h->test(
    'without box pullout the worksheet shows phantom shrinkage',
    abs((float) $noBox['calculated_sales_qty'] - 6.0) < 0.001,
    json_encode($noBox)
);

$withBox = _dcInventoryDerivedMetrics(10.0, 0.0, 0.0, 4.0, 0.0, 0.0, 6.0);
$h->test(
    'box components are absorbed as pullout',
    abs((float) $withBox['calculated_sales_qty'] - 0.0) < 0.001,
    json_encode($withBox)
);
$h->test(
    'sales variance returns to zero',
    abs((float) $withBox['sales_variance_qty'] - 0.0) < 0.001,
    json_encode($withBox)
);

// Manual pullout stays additive and independently editable.
$mixed = _dcInventoryDerivedMetrics(20.0, 0.0, 2.0, 10.0, 2.0, 10.0, 6.0);
$h->test(
    'manual pullout remains additive to box pullout',
    abs((float) $mixed['calculated_sales_qty'] - 2.0) < 0.001,
    json_encode($mixed)
);

// Backwards compatibility: the 6-argument call form still behaves as before.
$legacy = _dcInventoryDerivedMetrics(10.0, 5.0, 2.0, 4.0, 8.0, 4.0);
$h->test(
    'six-argument call keeps original semantics',
    abs((float) $legacy['calculated_sales_qty'] - 9.0) < 0.001,
    json_encode($legacy)
);

// ── 6. Manual pullout cell is untouched ──
$h->section('Manual Pullout Cell Preserved');
$ledger = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/inventory/ledger.disyl');
$h->test(
    'pullout cell is still an editable input bound to pullout_qty',
    (bool) preg_match('/x-model\.number="row\.pullout_qty"[\s\S]{0,200}@input="queueSave\(row\)"/', $ledger)
);
$h->test('box pullout is rendered read-only', str_contains($ledger, 'boxPullout(row)'));
$h->test(
    'the worksheet never writes a derived box value back',
    !str_contains($ledger, 'queueSave(row.box_pullout_qty)')
);

// ── 7. POS picker contract ──
$h->section('POS Box Picker');
$pos = (string) file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/pos/index.disyl');
$h->test('POS routes box taps to the picker', str_contains($pos, 'if (product.is_box)'));
$h->test(
    'standard set is added in one tap when fully stocked',
    str_contains($pos, '!d.requires_selection')
);
$h->test('picker blocks confirm until every slot is filled', str_contains($pos, ':disabled="!boxFilled"'));
$h->test('composition is sent as customizations.box.components', str_contains($pos, 'box.components = componentIds'));

$h->done();
