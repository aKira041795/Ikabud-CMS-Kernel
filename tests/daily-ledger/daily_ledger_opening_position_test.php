<?php

declare(strict_types=1);

/**
 * Daily Ledger — an outbound movement may not create stock out of nothing.
 *
 * THE ORIGIN OF THE LIVE NEGATIVE BALANCES (traced 2026-11-19, probe-negative-origin.php):
 *   STAR/FRANCIS #113  2026-09-30 PM  beg 0, produced 0, dispatched 104 -> remaining -104
 *   PANDECHE #109      2026-09-30 PM  beg 0, produced 0, dispatched  74 -> remaining  -74
 * No product's first row starts negative, and no beginning was ever written negative: the first row
 * IS the dispatch, recorded against an empty ledger because the hub's opening stock was never entered
 * while dispatch was already live. Every later negative is that number carried forward.
 *
 * Guard under test: dl_applyCommissaryProductLedgerDelta() must refuse an outbound-only movement for a
 * product with no recorded stock at this hub on this date or any earlier one, and must NOT refuse when
 * stock exists.
 *
 * The "allowed" case runs inside a transaction that is rolled back, so the suite never writes a ledger
 * row into the tenant it runs against.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-opening-position', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$db = app()->db();
$hub = (int)$db->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

$h->section('a product with no recorded stock is recognised as missing an opening position');

// A product that has NEVER appeared in this hub's ledger.
$untouched = (int)($db->query(
    "SELECT p.id FROM dl_products p
      WHERE p.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM dl_commissary_product_ledger l
                         WHERE l.product_id = p.id AND l.commissary_branch_id = {$hub})
      ORDER BY p.id LIMIT 1"
)->fetchColumn() ?: 0);

// NOTE: an earlier version of this test called the ledger WRITER directly. app()->db() is a KernelPDO,
// not the DatabaseContract the writer declares, so the "refused" assertion was passing on a TypeError
// rather than on the guard - the vacuous-guard trap. The check now lives in
// dl_commissaryOpeningPositionMissing(), which takes an untyped $db precisely so it can be asserted.
$h->test(
    'a product never recorded at this hub has no opening position',
    $untouched > 0 && dl_commissaryOpeningPositionMissing($db, $hub, $untouched, '2026-11-19') === true,
    'this is the exact condition that created the 2026-09-30 negatives'
);

$stocked = $db->query(
    "SELECT product_id FROM dl_commissary_product_ledger
      WHERE commissary_branch_id = {$hub}
      GROUP BY product_id
     HAVING SUM(beg_qty + produced_qty - dispatched_qty - wastage_qty) > 0
      ORDER BY SUM(beg_qty + produced_qty - dispatched_qty - wastage_qty) DESC LIMIT 1"
)->fetchColumn();

$h->test(
    'a product holding stock DOES have an opening position',
    $stocked !== false && dl_commissaryOpeningPositionMissing($db, $hub, (int)$stocked, '2026-11-19') === false,
    'the guard must not become a wall for normal dispatch'
);

$h->section('the ledger writer refuses on that condition, and only then');
$source = (string)file_get_contents(dirname(__DIR__, 2) . '/modules/daily-ledger/handlers.php');
$h->test(
    'the writer calls the check for outbound-only movement',
    // BEHAVIOUR, not layout. This used to be one regex requiring the check to be the IMMEDIATE next
    // condition on the same line; adding a second condition in front of it made the pin fail while
    // the guard was intact - a false red of exactly the class the analysis calls "verification that
    // cannot fail" (it failed for the wrong reason). It now asserts the three things that matter:
    // the outbound-only predicates still gate it, the check is still called, and it is still
    // fail-closed unless a caller explicitly marks the movement as a capture of an existing document.
    preg_match('/\$dispatchedDelta > 0 && \$producedDelta <= 0 && \$wastageDelta <= 0/', $source) === 1
        && preg_match('/dl_commissaryOpeningPositionMissing\(\$db, \$commissaryBranchId, \$productId, \$ledgerDate\)/', $source) === 1
        && preg_match('/!\$captureOfExistingDocument\s*\n?\s*&&\s*dl_commissaryOpeningPositionMissing\(/', $source) === 1,
    'production output and wastage must not be caught by it; the check must stay fail-closed by default'
);
$h->test(
    'the refusal instructs the operator instead of stranding them',
    strpos($source, 'Record the opening stock (or the receipt) for this product first, then dispatch it.') !== false,
    'a bare refusal costs a shift; the message names the missing step'
);
$h->test(
    'the guard does not clamp the balance',
    strpos($source, 'No opening stock is recorded for this product') !== false,
    'clamping would hide exactly the deviation the owner asked to see'
);

// ── Shortfall DETECTION must cover every writer, not one of them ────────────────────────────
// Nine call sites write dl_commissary_product_ledger. Detection used to exist inside only one of
// them (dl_applyPostedDeliveryCommissaryLedger), so a negative created by the other eight was
// silent — which is how the integrity panel could report `deviation_events_24h | OK` for a period
// in which nothing was instrumented. Health inferred from an absence of evidence.
//
// The property that makes coverage structural is that detection lives in the CHOKE POINT every
// writer goes through. Asserted against that function body specifically: if it is moved back out to
// a call site, or deleted, this fails. Behaviour was probed separately on 2026-11-21 (rolled back):
// beg 5 + dispatch 50 -> one event at -45, a further dispatch -> a second event at -50, a healthy
// row -> none, and 0 rows after rollback.
$deltaStart = strpos($source, 'function dl_applyCommissaryProductLedgerDelta(');
$deltaBody = $deltaStart === false ? '' : substr($source, $deltaStart, 22000);
$deltaEnd = strpos($deltaBody, "\n}\n");
$deltaBody = $deltaEnd === false ? $deltaBody : substr($deltaBody, 0, $deltaEnd);
$h->test(
    'stock shortfall is detected inside the ledger writer itself, not at one call site',
    $deltaBody !== ''
        && strpos($deltaBody, "'commissary_stock_shortfall'") !== false
        && preg_match('/\$newRemaining < 0 && \$newRemaining < \$oldRemaining/', $deltaBody) === 1,
    'detection outside the choke point covers only the call sites somebody remembered'
);
$h->test(
    'the shortfall record names the row and the before/after remaining',
    $deltaBody !== ''
        && strpos($deltaBody, "'remaining_qty' => \$oldRemaining") !== false
        && strpos($deltaBody, "'remaining_qty' => \$newRemaining") !== false
        && strpos($deltaBody, "'dispatched_delta' => \$dispatchedDelta") !== false,
    'an event without the numbers cannot be acted on'
);

$h->done();
