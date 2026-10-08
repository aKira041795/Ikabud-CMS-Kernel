<?php
/**
 * Consignee sales-mode acceptance probe (DRIVER).
 *
 * Owner, 2026-10-08: "a toggle at admin settings to set either all items considered sold (as an order
 * from consignee) or a consignee - bakeshop arrangement (account for sold pieces only)".
 *
 * A GATE: it must FAIL on the unchanged tree, where no such setting exists.
 *
 * It asserts OUTCOMES and, most importantly, the guard that the toggle is a LENS and not a WRITER:
 * the consignee ledger must be byte-identical in both modes. A "mode" that changes postings would
 * silently fork the books.
 *
 * The gate RESTORES the setting it toggles, and asserts that it did — it runs against a live tenant.
 *
 * Deliberately NOT a criterion here: rendering `commissary.disyl`, because `storage/cache/compiled` is
 * www-data-owned and the CLI cannot compile it, so a render assertion would be a permanent false red
 * (the page renders correctly live). That outcome is verified by the lane's oracle and the chair's
 * browser check.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$results = [];
function probe(string $label, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = [$label, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = app()->dbForTenant(207);

echo "== consignee sales-mode acceptance gate ==\n";

$KEY = 'consignee_sales_mode';

/** Snapshot every consignee ledger row so we can prove the toggle writes nothing. */
function ledgerSnapshot($db): string {
    $rows = $db->query('SELECT consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw FROM dl_consignee_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $effects = $db->query('SELECT id, delivery_id, delivery_item_id, consignee_id, product_id, quantity, effect_status FROM dl_consignee_ledger_effects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode([$rows, $effects]));
}

$original = dlModuleSettings()[$KEY] ?? null;
$snapshotBefore = ledgerSnapshot($db);

// A. the setting exists and defaults conservatively
$defaults = dlSettingsDefaults();
$hasKey = array_key_exists($KEY, $defaults);
probe('A the setting exists and defaults to consignment (never assume goods are sold)',
      $hasKey && (string)$defaults[$KEY] === 'consignment',
      'present=' . (int)$hasKey . ' default=' . var_export($defaults[$KEY] ?? null, true));

// B. it round-trips through the real persistence path (which verifies by read-back)
$rt = false;
if ($hasKey) {
    $rt = dlPersistModuleSettings([$KEY => 'order']) && (string)(dlModuleSettings(true)[$KEY] ?? '') === 'order';
}
probe('B the setting round-trips through dlPersistModuleSettings', $rt);

// C. an unknown value must coerce to consignment, NEVER to order
$coerced = 'n/a';
if ($hasKey) {
    dlPersistModuleSettings([$KEY => 'definitely-not-a-mode']);
    $coerced = (string)(dlModuleSettings(true)[$KEY] ?? '');
}
probe('C a garbage mode coerces to consignment, never to order', $hasKey && $coerced === 'consignment',
      'readback=' . var_export($coerced, true));

// D. PIN: flipping the mode writes nothing.
//
// WHAT THIS DOES AND DOES NOT PROVE (measured 2026-10-08, the hard way):
//   PROVES: setting the mode to 'order' and back leaves the consignee ledger byte-identical.
//   DOES NOT PROVE: that RENDERING the sheet in order mode writes nothing. That cannot be tested in
//   CLI at all. A mutation injected into the order-mode code DOES execute (verified with a marker), but
//   its write never lands, because the render fails on the www-data-owned compiled-template cache and
//   the request's transaction ROLLS BACK with it. The ledger therefore looks untouched whether the code
//   writes or not - a mutation here is invisible in CLI, in both directions.
// The render-path half of the lens property is verified instead by the lane's oracle (which renders
// both modes through a writable temp cache) and by the chair in a real browser, where the render
// succeeds and any write would commit.
if ($hasKey) {
    dlPersistModuleSettings([$KEY => 'order']);
    dlModuleSettings(true);
    shell_exec(sprintf('php %s 2>/dev/null', escapeshellarg(__DIR__ . '/lane-consignee-sales-mode-render-child.php')));
    $snapshotOrder = ledgerSnapshot($db);
    dlPersistModuleSettings([$KEY => 'consignment']);
    dlModuleSettings(true);
    shell_exec(sprintf('php %s 2>/dev/null', escapeshellarg(__DIR__ . '/lane-consignee-sales-mode-render-child.php')));
    $snapshotConsignment = ledgerSnapshot($db);
    probe('D pin: switching the mode writes nothing to the consignee ledger',
          $snapshotOrder === $snapshotBefore && $snapshotConsignment === $snapshotBefore,
          'order=' . (($snapshotOrder === $snapshotBefore) ? 'same' : 'CHANGED')
          . ' consignment=' . (($snapshotConsignment === $snapshotBefore) ? 'same' : 'CHANGED'));
} else {
    probe('D pin: switching the mode writes nothing to the consignee ledger', false,
          'skipped: no setting to toggle');
}

// restore whatever was there before, and prove it
if ($hasKey && $original !== null) {
    dlPersistModuleSettings([$KEY => (string)$original]);
}
$restored = $hasKey ? (string)(dlModuleSettings(true)[$KEY] ?? '') : (string)$original;
probe('E pin: the gate restored the previous setting', $hasKey ? ($restored === (string)$original) : true,
      'now=' . var_export($restored, true) . ' was=' . var_export($original, true));

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: the consignee sales mode exists, is safe by default, and writes nothing\n";
    exit(0);
}
echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
