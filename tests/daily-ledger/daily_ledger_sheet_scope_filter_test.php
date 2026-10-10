<?php

declare(strict_types=1);

/**
 * The Daily Sheet's destination columns must obey the admin view scope.
 *
 * Owner report: "commissary view, does not filter branches/consignees by commissary or area".
 * Both halves were real. This oracle pins the consignee half, which was the subtler one:
 * dl_fetchProductionSheetConsigneeCells() used to receive no scope at all, so an AREA selection
 * could not remove an out-of-area consignee column even though the same scope filtered the branch
 * columns beside it.
 *
 * The MUST-REFUSE case is the important one. A test that only asserts "the in-area consignee is
 * present" passes just as happily when the filter does nothing at all, because the consignee is
 * present either way. So the last test asserts the two result sets genuinely DIFFER.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-sheet-scope-filter', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// Fixture ids are high and fixed so they cannot collide with real tenant rows.
$commissary = 99761;
$product    = 99762;
$areaA      = 99763;
$areaB      = 99764;
$consA      = 99765;
$consB      = 99766;
$date       = '2098-07-15';

$cleanup = static function () use ($db, $commissary, $product, $areaA, $areaB, $consA, $consB): void {
    $db->execute("DELETE FROM dl_consignee_products WHERE consignee_id IN ({$consA},{$consB}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_consignee_ledger WHERE consignee_id IN ({$consA},{$consB}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_consignee_ledger_effects WHERE consignee_id IN ({$consA},{$consB})");
    $db->execute("DELETE FROM dl_consignees WHERE id IN ({$consA},{$consB})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id = {$commissary} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_products WHERE id = {$product}");
    $db->execute("DELETE FROM dl_branches WHERE id = {$commissary}");
    $db->execute("DELETE FROM dl_areas WHERE id IN ({$areaA},{$areaB})");
};
$cleanup();

try {
    $db->prepare('INSERT INTO dl_areas (id, code, name, is_active) VALUES (?, ?, ?, 1)')
        ->execute([$areaA, 'PRB-A', 'Probe Area A']);
    $db->prepare('INSERT INTO dl_areas (id, code, name, is_active) VALUES (?, ?, ?, 1)')
        ->execute([$areaB, 'PRB-B', 'Probe Area B']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active, area_id) VALUES (?, ?, ?, 1, 1, ?)')
        ->execute([$commissary, 'PRB-C', 'Probe Commissary', $areaA]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12.00, 1)')
        ->execute([$product, 'PRB-P', 'Probe Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$commissary, $product]);

    // Two consignees, same commissary, DIFFERENT areas. The area is the only difference,
    // so any change in the returned set is attributable to it and nothing else.
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, area_id, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$consA, 'PRB-DA', 'Probe Consignee A', $commissary, $areaA]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, area_id, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$consB, 'PRB-DB', 'Probe Consignee B', $commissary, $areaB]);

    // The assignment rows are what put a consignee column on the sheet.
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$consA, $product]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$consB, $product]);

    $h->section('Consignee columns obey the AREA scope');

    $unscoped  = dl_fetchProductionSheetConsigneeCells($db, $date, $commissary, null, null);
    $areaAScope = dl_fetchProductionSheetConsigneeCells($db, $date, $commissary, null, $areaA);

    $idsUnscoped = array_map('intval', array_keys($unscoped['consignees'] ?? []));
    $idsAreaA    = array_map('intval', array_keys($areaAScope['consignees'] ?? []));

    $h->test(
        'an unscoped sheet still returns BOTH consignees (the fix must not narrow by default)',
        in_array($consA, $idsUnscoped, true) && in_array($consB, $idsUnscoped, true),
        'unscoped=[' . implode(',', $idsUnscoped) . ']'
    );

    $h->test(
        'an AREA scope keeps the in-area consignee',
        in_array($consA, $idsAreaA, true),
        'areaA=[' . implode(',', $idsAreaA) . ']'
    );

    $h->test(
        'an AREA scope REMOVES the out-of-area consignee',
        !in_array($consB, $idsAreaA, true),
        'areaA=[' . implode(',', $idsAreaA) . '] must not contain ' . $consB
    );

    // MUST-REFUSE. Without this, a filter that does nothing would still satisfy the two
    // assertions above, because the in-area consignee is present in both result sets.
    $h->test(
        'MUST-REFUSE: scoped and unscoped sets genuinely differ',
        $idsAreaA !== $idsUnscoped,
        'identical sets would prove the scope is ignored, not applied'
    );
} finally {
    $cleanup();
}

$h->done();
