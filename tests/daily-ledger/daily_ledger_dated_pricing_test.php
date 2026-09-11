<?php

declare(strict_types=1);

/** Daily Ledger — dated base prices and mutable-ledger repricing. */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dated-pricing', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();
$originalSettings = getModuleSettings('daily-ledger');
dlPersistModuleSettings(array_merge((array)$originalSettings, ['price_groups_enabled' => '1']));

$branchId = 99171;
$productId = 99171;
$dates = ['2031-01-10', '2031-01-15', '2031-01-16', '2031-01-17'];

$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_product_price_history WHERE product_id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_price_groups WHERE id = :g', [':g' => $productId]);

$db->execute(
    "INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, 'T-DATE', 'Dated Price Test', 'Test', 'self_managed', 0, 1)",
    [':id' => $branchId]
);
$db->execute(
    "INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, 'DATE-PRICE', 'Dated Price Product', 10.00, 0, 1)",
    [':id' => $productId]
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);
$db->execute(
    'INSERT INTO dl_product_price_history (product_id, price, effective_at) VALUES
     (:p1, 10.00, "2031-01-01 00:00:00"), (:p2, 20.00, "2031-01-15 00:00:00")',
    [':p1' => $productId, ':p2' => $productId]
);

$h->section('Effective-date validation and resolution');
$h->test('omitted effective_from defaults to today', dl_normalizeEffectiveFrom(null, '2031-01-20') === '2031-01-20');
$h->test('valid effective_from is accepted', dl_normalizeEffectiveFrom('2031-01-15', '2031-01-20') === '2031-01-15');
$h->test('malformed effective_from is rejected', dl_normalizeEffectiveFrom('2031-02-30', '2031-01-20') === null);
$h->test('base price before boundary uses prior history', dl_resolveProductPrice($productId, null, '2031-01-14') === 10.0);
$h->test('base price on boundary uses new history', dl_resolveProductPrice($productId, null, '2031-01-15') === 20.0);
$h->test('base price after boundary uses new history', dl_resolveProductPrice($productId, null, '2031-01-16') === 20.0);
$priceGroupId = $productId;
$db->execute(
    "INSERT INTO dl_price_groups (id, name, type, is_default, is_active)
     VALUES (:g, 'Dated Price Test Group', 'branch', 0, 1)",
    [':g' => $priceGroupId]
);
$db->execute(
    "INSERT INTO dl_product_prices (product_id, price_group_id, selling_price, effective_from, effective_to, is_active)
     VALUES (:p, :g, 77.00, '2031-01-01', NULL, 1)",
    [':p' => $productId, ':g' => $priceGroupId]
);
$h->test('matching price-group window retains precedence over dated base history', dl_resolveProductPrice($productId, $priceGroupId, '2031-01-16') === 77.0);
$db->execute('DELETE FROM dl_product_prices WHERE product_id = :p', [':p' => $productId]);

$h->section('Postdated readers and lazy current-price promotion');
$effectiveSql = dl_effectivePriceSql('p', ':reader_at');
$readEffective = static function (string $atDate) use ($db, $productId, $effectiveSql): float {
    $stmt = $db->prepare('SELECT ' . $effectiveSql . ' AS current_price FROM dl_products p WHERE p.id = :pid');
    $stmt->execute([':reader_at' => $atDate, ':pid' => $productId]);
    return (float)$stmt->fetchColumn();
};
$storedPrice = static function () use ($db, $productId): float {
    $stmt = $db->prepare('SELECT current_price FROM dl_products WHERE id = :pid');
    $stmt->execute([':pid' => $productId]);
    return (float)$stmt->fetchColumn();
};
$readPriceAndLabel = static function (string $atDate) use ($db, $productId): array {
    $priceSql = dl_effectivePriceSql('p', ':list_price_at');
    $stmt = $db->prepare(
        'SELECT ' . $priceSql . ' AS current_price,
                (SELECT DATE(ph.effective_at) FROM dl_product_price_history ph
                  WHERE ph.product_id = p.id AND ph.effective_at < DATE_ADD(:list_label_at, INTERVAL 1 DAY)
                  ORDER BY ph.effective_at DESC, ph.id DESC LIMIT 1) AS current_price_effective_from
           FROM dl_products p WHERE p.id = :pid'
    );
    $stmt->execute([':list_price_at' => $atDate, ':list_label_at' => $atDate, ':pid' => $productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$beforePair = $readPriceAndLabel('2031-01-14');
$h->test('postdated history leaves stored current_price unchanged before its date', $storedPrice() === 10.0);
$h->test('shared expression keeps the prior price before the postdated boundary', $readEffective('2031-01-14') === 10.0);
$h->test('shown pre-boundary price is paired with its own effective date', (float)($beforePair['current_price'] ?? 0) === 10.0 && ($beforePair['current_price_effective_from'] ?? null) === '2031-01-01');
$h->test('early promotion is a no-op', dl_promoteCurrentPrices('2031-01-14') === 0 && $storedPrice() === 10.0);
$afterPair = $readPriceAndLabel('2031-01-15');
$h->test('shared expression and label switch together on the effective date', (float)($afterPair['current_price'] ?? 0) === 20.0 && ($afterPair['current_price_effective_from'] ?? null) === '2031-01-15');
$h->test('effective-date promotion applies the new stored price', dl_promoteCurrentPrices('2031-01-15') === 1 && $storedPrice() === 20.0);
$h->test('promotion is idempotent', dl_promoteCurrentPrices('2031-01-15') === 0 && $storedPrice() === 20.0);

$insertLedger = $db->prepare(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end)
     VALUES (:b, :p, :d, :s, 10.00, 10, 0, 0, 5)'
);
foreach ($dates as $date) {
    $insertLedger->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => 'AM']);
}
$db->execute(
    "INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, '2031-01-16', 'closed')",
    [':b' => $branchId]
);
$db->execute(
    "INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance, frozen_at)
     VALUES (:b, :p, '2031-01-17', 'ending', 'AM', 1, CURRENT_TIMESTAMP)",
    [':b' => $branchId, ':p' => $productId]
);

$h->section('Reprice range and immutable-day skips');
$summary = dl_repriceProductLedgerRows($db, $productId, '2031-01-15');
$h->test('one mutable row repriced', $summary['updated_rows'] === 1);
$h->test('closed and frozen rows counted as skipped', $summary['skipped_rows'] === 2 && count($summary['skipped_days']) === 2);
$prices = [];
$stmt = $db->prepare('SELECT ledger_date, price_snapshot FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p ORDER BY ledger_date');
$stmt->execute([':b' => $branchId, ':p' => $productId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $prices[(string)$row['ledger_date']] = (float)$row['price_snapshot'];
}
$h->test('row before effective range keeps prior snapshot', ($prices['2031-01-10'] ?? 0.0) === 10.0);
$h->test('open row on boundary gets resolved price', ($prices['2031-01-15'] ?? 0.0) === 20.0);
$h->test('closed row is not rewritten', ($prices['2031-01-16'] ?? 0.0) === 10.0);
$h->test('frozen row is not rewritten', ($prices['2031-01-17'] ?? 0.0) === 10.0);
$displayWithSnapshot = dl_applyLedgerDisplayPrices([
    ['product_id' => $productId, 'current_price' => 999.0, 'price_snapshot' => 10.0],
], $branchId, '2031-01-16');
$displayWithoutSnapshot = dl_applyLedgerDisplayPrices([
    ['product_id' => $productId, 'current_price' => 999.0, 'price_snapshot' => null],
], $branchId, '2031-01-15');
$h->test('ledger display prefers its stored snapshot', $displayWithSnapshot[0]['current_price'] === 10.0);
$h->test('ledger display resolves the viewed date when no row snapshot exists', $displayWithoutSnapshot[0]['current_price'] === 20.0);

$db->execute('DELETE FROM dl_product_price_history WHERE product_id = :p', [':p' => $productId]);
$db->execute('UPDATE dl_products SET current_price = 33.00 WHERE id = :p', [':p' => $productId]);
$h->test('effective-price expression falls back to current_price without history', $readEffective('2031-01-15') === 33.0);

$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b OR product_id = :p', [':b' => $branchId, ':p' => $productId]);
$db->execute('DELETE FROM dl_product_price_history WHERE product_id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_price_groups WHERE id = :g', [':g' => $priceGroupId]);
dlPersistModuleSettings((array)$originalSettings);
modulePopContext();

$h->done();
