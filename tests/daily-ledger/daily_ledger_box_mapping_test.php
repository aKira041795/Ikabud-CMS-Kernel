<?php

declare(strict_types=1);

/**
 * Daily Ledger — box mapping rules (which box does a product consume when it sells?).
 *
 * OWNER DIRECTION 2026-11-19: "at products, a new view for box mapping ... assigned to a product so
 * that when a qty shows how many were sold in a shift, the box mapped is also auto deducted at the
 * branch." Boxes belong to BRANCHES (same day: "PIC only produces products; boxes of any kind belong
 * to the branches"), so this mapping is the ONLY link between a cake sale and box stock.
 *
 * Nothing is deducted yet — this test covers the mapping rules only.
 *
 * Each assertion names what breaks if it regresses:
 * - an INACTIVE product accepting a mapping = the box attaches to a SKU nobody sells, deducts nothing,
 *   and the live sibling looks like it consumes no boxes (measured 2026-11-20: product #227 is inactive
 *   while #187 sells);
 * - self-mapping or a box consuming a box = a chain that deducts twice for one sale;
 * - units_per_box below 1 = the deduction inverts or divides by zero. Measured 2026-11-20: pcs_per_pack
 *   is NULL on every product row, so a box holding twelve mamon would otherwise deduct twelve boxes;
 * - clearing a box must reset the ratio to 1, or a stale 12 applies the moment a box is re-attached.
 *
 * Fixtures are synthetic products in the 997xx id range and are removed in the finally block.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-box-mapping', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$db = app()->db();
$passed = 0;
$failed = 0;

function checkBox(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "box mapping — storage and rules\n";

// ── storage ───────────────────────────────────────────────────────────────────────────────
$cols = $db->query(
    "SELECT column_name, column_type, is_nullable, column_default
       FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'dl_products'
        AND column_name IN ('is_box','box_product_id','units_per_box')"
)->fetchAll(PDO::FETCH_ASSOC);
// information_schema column names come back UPPERCASE on MySQL; reading only the lowercase form
// raised "Undefined array key" warnings that the harness correctly failed on.
$colMap = [];
foreach ($cols as $c) {
    $key = strtolower((string)($c['COLUMN_NAME'] ?? $c['column_name'] ?? ''));
    $colMap[$key] = [
        'column_name' => $key,
        'column_type' => (string)($c['COLUMN_TYPE'] ?? $c['column_type'] ?? ''),
        'is_nullable' => (string)($c['IS_NULLABLE'] ?? $c['is_nullable'] ?? ''),
        'column_default' => $c['COLUMN_DEFAULT'] ?? $c['column_default'] ?? null,
    ];
}
checkBox('is_box column exists', isset($colMap['is_box']));
checkBox('box_product_id column exists', isset($colMap['box_product_id']));
checkBox('units_per_box column exists', isset($colMap['units_per_box']));
checkBox(
    'units_per_box defaults to 1 (never an assumed ratio)',
    isset($colMap['units_per_box']) && (string)$colMap['units_per_box']['column_default'] === '1',
    'default=' . ($colMap['units_per_box']['column_default'] ?? 'none')
);

// ── fixtures ─────────────────────────────────────────────────────────────────────────────
$catId = (int)$db->query('SELECT id FROM dl_product_categories ORDER BY id LIMIT 1')->fetchColumn();
$fixtures = [99701 => 'TEST CAKE A', 99702 => 'TEST BOX B', 99703 => 'TEST PLAIN C', 99704 => 'TEST OFF D'];
$cleanup = function () use ($db, $fixtures): void {
    $ids = implode(',', array_keys($fixtures));
    $db->exec("DELETE FROM dl_products WHERE id IN ({$ids})");
};
$cleanup();

$ins = $db->prepare(
    'INSERT INTO dl_products (id, name, sku, category_id, product_category, is_active, is_box, box_product_id, units_per_box, sort_order)
     VALUES (:id, :name, :sku, :cat, :pcat, :active, 0, NULL, 1, 0)'
);
foreach ($fixtures as $id => $name) {
    $ins->execute([
        ':id' => $id,
        ':name' => $name,
        ':sku' => 'TST' . $id,
        ':cat' => $catId,
        ':pcat' => 'other',
        ':active' => $id === 99704 ? 0 : 1,
    ]);
}

try {
    // ── 1. marking a product as a box ─────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99702, 'is_box' => 1], null);
    checkBox('mark a product as a box', ($r['ok'] ?? false) === true && (int)($r['is_box'] ?? 0) === 1, json_encode($r));

    // ── 2. nothing else is a box yet ──────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'box_product_id' => 99703], null);
    checkBox(
        'refuse a target that is not marked as a box (TARGET_NOT_A_BOX)',
        ($r['code'] ?? '') === 'TARGET_NOT_A_BOX',
        (string)($r['code'] ?? 'no code')
    );

    // ── 3. self-mapping ───────────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'box_product_id' => 99701], null);
    checkBox('refuse a product as its own box (BOX_IS_SELF)', ($r['code'] ?? '') === 'BOX_IS_SELF', (string)($r['code'] ?? 'no code'));

    // ── 4. a box cannot consume a box ─────────────────────────────────────────────────────
    $r = $db->prepare('UPDATE dl_products SET is_box = 1 WHERE id = 99703');
    $r->execute();
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99702, 'box_product_id' => 99703], null);
    checkBox('refuse a box consuming a box (BOX_CANNOT_CONSUME_BOX)', ($r['code'] ?? '') === 'BOX_CANNOT_CONSUME_BOX', (string)($r['code'] ?? 'no code'));
    $db->exec('UPDATE dl_products SET is_box = 0 WHERE id = 99703');

    // ── 5. inactive product ───────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99704, 'box_product_id' => 99702], null);
    checkBox('refuse mapping an INACTIVE product (PRODUCT_INACTIVE)', ($r['code'] ?? '') === 'PRODUCT_INACTIVE', (string)($r['code'] ?? 'no code'));

    // ── 6. ratio below 1 ──────────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'box_product_id' => 99702, 'units_per_box' => 0], null);
    checkBox('refuse pieces-per-box of 0 (UNITS_PER_BOX_INVALID)', ($r['code'] ?? '') === 'UNITS_PER_BOX_INVALID', (string)($r['code'] ?? 'no code'));

    // ── 7. happy path ─────────────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'box_product_id' => 99702, 'units_per_box' => 1], null);
    checkBox('map a cake to the box, 1 per box', ($r['ok'] ?? false) === true && (int)$r['box_product_id'] === 99702, json_encode($r));

    // ── 8. the pack case ──────────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'units_per_box' => 12], null);
    $row = $db->query('SELECT box_product_id, units_per_box FROM dl_products WHERE id = 99701')->fetch(PDO::FETCH_ASSOC);
    checkBox(
        'twelve pieces per box is stored, not assumed to be one',
        (int)$row['units_per_box'] === 12 && (int)$row['box_product_id'] === 99702,
        json_encode($row)
    );

    // ── 9. clearing resets the ratio ──────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 99701, 'box_product_id' => 0], null);
    $row = $db->query('SELECT box_product_id, units_per_box FROM dl_products WHERE id = 99701')->fetch(PDO::FETCH_ASSOC);
    checkBox(
        'clearing the box resets pieces-per-box to 1 (no stale ratio survives)',
        $row['box_product_id'] === null && (int)$row['units_per_box'] === 1,
        json_encode($row)
    );

    // ── 10. missing product ───────────────────────────────────────────────────────────────
    $r = dl_saveProductBoxMappingCore($db, ['product_id' => 999999, 'box_product_id' => 99702], null);
    checkBox('refuse an unknown product (404)', (int)($r['status'] ?? 0) === 404, (string)($r['code'] ?? 'no code'));

    // ── 11. audit trail ───────────────────────────────────────────────────────────────────
    $audited = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'product_box_mapping'")->fetchColumn();
    checkBox('every change is audited', $audited >= 3, "rows={$audited}");
} finally {
    $cleanup();
}

$total = $passed + $failed;
echo "\n  {$passed}/{$total} passed\n";
$h->done($failed === 0);
exit($failed === 0 ? 0 : 1);
