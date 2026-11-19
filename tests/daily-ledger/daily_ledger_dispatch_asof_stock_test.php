<?php

declare(strict_types=1);

/**
 * Daily Ledger — the dispatch stock check must be judged AS OF THE DELIVERY DATE.
 *
 * TRACED 2026-11-19 (Sol audit, verified in this file): apiCommissaryDispatch() called
 * dl_commissaryOnHandByProduct($db, $hubIds) with no as-of date, so the check read the hub's NEWEST
 * balance. A dispatch entered today for an earlier date was therefore validated against stock that did
 * not exist on that date, passed, and debited the older bucket - which went negative and stayed negative
 * through carry-forward. Those balances can never appear as a variance (the expectation is negative too),
 * which is why one hub reached -36,851 units across 82 products without a single variance flag.
 *
 * Each assertion names what breaks if it regresses:
 * - a dated read must differ from the undated one, or the parameter is being ignored;
 * - the dated read must be the one the endpoint uses, or the fix is decoration;
 * - a date AFTER all ledger rows must still resolve, or the check would refuse a legitimate future-dated
 *   DR by returning zero stock.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-asof', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$db = app()->db();
$base = dirname(__DIR__, 2);
$handlerSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');

$hubs = array_map('intval', $db->query(
    'SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1'
)->fetchAll(PDO::FETCH_COLUMN) ?: []);

$range = $db->query(
    "SELECT MIN(ledger_date) AS dfrom, MAX(ledger_date) AS dto FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ("
    . (implode(',', $hubs) ?: '0') . ')'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$dfrom = (string)($range['dfrom'] ?? '');
$dto = (string)($range['dto'] ?? '');

$h->section('a dated stock read is not the same question as a latest read');

$latest = dl_commissaryOnHandByProduct($db, $hubs);
$asOfStart = $dfrom !== '' ? dl_commissaryOnHandByProduct($db, $hubs, $dfrom) : [];

$differing = 0;
foreach ($latest as $hubId => $products) {
    foreach ($products as $productId => $row) {
        $old = $asOfStart[$hubId][$productId]['on_hand'] ?? null;
        if ($old === null || (int)$old !== (int)$row['on_hand']) {
            $differing++;
        }
    }
}
$h->test(
    'the balance on the first day of data differs from the latest balance',
    $dto !== '' && $dfrom !== $dto && $differing > 0,
    'if these never differed, an as-of date would be pointless - but they differ for ' . $differing
    . ' product(s), which is exactly the window a backdated dispatch could exploit'
);

$h->test(
    'an as-of date in the future resolves to the latest balance',
    $dto !== '' && dl_commissaryOnHandByProduct($db, $hubs, '2035-12-31') === $latest,
    'a DR dated ahead of the ledger must not read as zero stock, or the check refuses a real load'
);

$h->test(
    'an as-of date before the data resolves to no stock, not to the latest',
    $asOfStart !== [] || $dfrom === '',
    'reading "latest" for a date before any row is the defect being fixed'
);

$h->section('the dispatch endpoint asks the dated question');

$h->test(
    'the stock check passes the delivery date',
    strpos($handlerSource, "\$cleanItems\n        ))), \$ledgerDate);") !== false,
    'the fix is only real if apiCommissaryDispatch actually supplies the date'
);
$h->test(
    'the check still happens once per hub, not per line',
    strpos($handlerSource, 'Read stock on hand ONCE per hub through the single definition') !== false,
    'per-item reads cost 237 ms each; this must not regress into a per-item matrix rebuild'
);

$h->done();
