<?php

declare(strict_types=1);

/**
 * Pullout / return classification for the commissary product ledger.
 *
 * Owner ruling: wastage may be the pullouts whose reason is SPOILAGE (and
 * DAMAGE). Apart from those, pullouts and withdrawals must NOT be understood as
 * wastage. Goods consumed at the branch never came back, so they must not credit
 * stock either -- hence a three-way decision, not saleable/unsaleable.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-pullout-classification', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

$h->section('True loss -> wastage_qty');
foreach (['spoilage', 'damage'] as $reason) {
    $h->test("'{$reason}' classifies as wastage", dl_classifyPulloutReturnReason($reason) === 'wastage');
}

$h->section('Consumed at the branch -> neither wastage nor stock');
foreach (['staff_meal', 'sampling', 'testing', 'promo', 'donation'] as $reason) {
    $h->test("'{$reason}' is consumed_no_delta (not wastage, no stock credit)",
        dl_classifyPulloutReturnReason($reason) === 'consumed_no_delta');
}

$h->section('Genuine returns -> produced_qty (unchanged behaviour)');
foreach ([null, '', 'manual_adjustment', 'other', 'encoder_omission'] as $reason) {
    $label = $reason === null ? '(null)' : "'{$reason}'";
    $h->test("{$label} returns goods to stock", dl_classifyPulloutReturnReason($reason) === 'returned_saleable');
}

$h->section('Rule shape');
$wastageTotal = 0;
$allReasons = ['spoilage', 'damage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation',
               'manual_adjustment', 'other', 'encoder_omission', '', null];
foreach ($allReasons as $reason) {
    if (dl_classifyPulloutReturnReason($reason) === 'wastage') { $wastageTotal++; }
}
$h->test('exactly two reasons may ever reach wastage_qty', $wastageTotal === 2, "got={$wastageTotal}");
$h->test('no reason maps outside the three allowed classifications', (function () use ($allReasons): bool {
    $allowed = ['wastage', 'returned_saleable', 'consumed_no_delta'];
    foreach ($allReasons as $reason) {
        if (!in_array(dl_classifyPulloutReturnReason($reason), $allowed, true)) { return false; }
    }
    return true;
})());
$h->test('matching is case- and whitespace-insensitive',
    dl_classifyPulloutReturnReason('SPOILAGE') === 'wastage'
    && dl_classifyPulloutReturnReason('  Spoilage ') === 'wastage'
    && dl_classifyPulloutReturnReason('Damage') === 'wastage');

$h->section('Sheet balance shares one basis with calc_variance');
$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test('sheet ledger SQL sums wastage_qty', str_contains($handlers, 'SUM(wastage_qty) AS wastage_qty'));
$h->test('book_balance subtracts wastage, matching the migration-064 variance basis',
    str_contains($handlers, "'book_balance' => \$begQty + \$addtlQty - \$total - \$wastageQty"));
$h->test('the pre-fill for ACTUAL BAL subtracts wastage too',
    substr_count($handlers, '$begQty + $addtlQty - $total - $wastageQty') >= 2);
$h->test('the old three-column basis is gone',
    !str_contains($handlers, "'book_balance' => \$begQty + \$addtlQty - \$total,"));
$h->test('the handler delegates to the extracted helper',
    str_contains($handlers, 'dl_classifyPulloutReturnReason($reasonCode)'));
$h->test('the old hardcoded 7-reason wastage list is gone',
    !str_contains($handlers, 'unsaleableReasons'));

$h->done();
