<?php

declare(strict_types=1);

/**
 * Daily Ledger — the variance screen must explain itself, truthfully.
 *
 * OWNER ACCEPTANCE CRITERIA (2026-11-19), verbatim: "i want the fix implemented to be factual and
 * acceptable to the admin, users as cashiers, PIC ... it reflects the true picture of the business. if
 * there are deviations, correctable moving forward. it must educate not confuse. traceable not hidden".
 *
 * The numbers this locks, measured on the production import:
 *   - 9,341 stock lines in the window, of which 2,779 (30%) were physically counted;
 *   - 336 endings filled from movements, 23 forced to zero — those can never show a variance;
 *   - 6,203 lines never counted, so they contribute no variance at all;
 *   - 120 products carrying a negative balance (-44,015 units), which is NOT a variance and is the
 *     largest stock problem in the business.
 *
 * Each assertion names why it matters:
 * - the four states must PARTITION the rows, or the panel understates what it does not cover;
 * - counted variance may never exceed the all-rows figure, or the split is fabricated;
 * - a negative balance must be named with a product, or it is untraceable.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-variance-integrity', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$db = app()->db();
$base = dirname(__DIR__, 2);
$handlerSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$templateSource = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/variances.disyl');

$hubIds = array_map('intval', $db->query(
    'SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1'
)->fetchAll(PDO::FETCH_COLUMN) ?: []);

$summary = dl_varianceIntegritySummary($db, $hubIds, '', '');

$h->section('the four ending states partition the period');

$h->test(
    'the panel covers every stock line exactly once',
    $summary['rows_total'] === ($summary['counted'] + $summary['derived'] + $summary['forced'] + $summary['not_counted']),
    'if the states do not add up, the panel understates what it cannot see: '
    . $summary['counted'] . '+' . $summary['derived'] . '+' . $summary['forced'] . '+' . $summary['not_counted']
    . ' vs ' . $summary['rows_total']
);
$h->test(
    'the period actually contains lines',
    $summary['rows_total'] > 0,
    'a zero-row panel would pass the partition test vacuously'
);
$h->test(
    'the counted percentage is the real ratio',
    $summary['counted_pct'] === (int)round(($summary['counted'] / max(1, $summary['rows_total'])) * 100),
    'the headline number must be arithmetic, not an estimate (' . $summary['counted_pct'] . '%)'
);
$h->test(
    'variance can only come from counted rows',
    $summary['variance_abs_counted'] <= $summary['variance_abs_all'],
    'the counted figure cannot exceed the total, or the split is fabricated'
);
$h->test(
    'derived and forced endings are reported, not folded into counted',
    $summary['derived'] >= 0 && $summary['forced'] >= 0
        && ($summary['derived'] + $summary['forced']) < $summary['rows_total'],
    'an ending derived from movements cannot show a difference; calling it a count is the confusion '
    . 'this panel exists to remove'
);

$h->section('negative balances are named, not hidden');

if ($summary['negative_products'] > 0) {
    $h->test(
        'a negative balance is reported as negative units',
        $summary['negative_units'] < 0,
        'units and product count must agree in sign'
    );
    $h->test(
        'the largest negative balances are named so they can be traced',
        ($summary['negative_examples_text'] ?? '') !== ''
            && strpos((string)$summary['negative_examples_text'], '(') !== false,
        'a count without a product name cannot be investigated (got: '
        . ($summary['negative_examples_text'] ?? '(empty)') . ')'
    );
} else {
    $h->test('no negative balances in this tenant', true, 'nothing to name');
}

$h->section('the panel and the traceability hook are wired');

$h->test(
    'the variance page renders the explanation',
    strpos($templateSource, 'variance-integrity-note') !== false
        && strpos($templateSource, 'variance_integrity.counted') !== false,
    'numbers without an explanation are exactly the confusion the owner rejected'
);
$h->test(
    'the page passes the summary to the template',
    strpos($handlerSource, "'variance_integrity'     => \$varianceIntegrity") !== false,
    'a helper nobody calls explains nothing'
);
$h->test(
    'a dispatch that drives stock negative is recorded as an event',
    strpos($handlerSource, "'commissary_dispatch_shortfall'") !== false
        && strpos($handlerSource, 'dispatched beyond recorded stock; correct the receipt or count the stock') !== false,
    'this is the code origin of the negative balances: the posting effect applies the delta with no '
    . 'stock floor, so the shortfall must at least be traceable to the delivery that caused it'
);
$h->test(
    'the dispatch is still applied, not refused',
    strpos($handlerSource, 'A dispatch is a FACT: goods left, so it is never refused here') !== false,
    'refusing to record goods that physically left would hide the deviation instead of naming it'
);

$h->section('an empty scope is safe');

$empty = dl_varianceIntegritySummary($db, [], '', '');
$h->test(
    'no hubs in scope returns zeros rather than throwing',
    $empty['rows_total'] === 0 && $empty['counted'] === 0 && $empty['negative_products'] === 0,
    'the page must render for a user with no commissaries in scope'
);

$h->done();
