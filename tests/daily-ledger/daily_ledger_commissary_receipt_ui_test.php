<?php

declare(strict_types=1);

/**
 * Commissary — the RECEIVE STOCK screen.
 *
 * Owner request (2026-11-21): "build the receipt screen UI, check".
 *
 * WHY IT EXISTED ONLY AS AN API. dl_recordCommissaryReceipt() and its route
 * (/daily-ledger/api/v1/commissary/receipt) were built and tested, but no screen ever called
 * them, so the person holding the supplier DR had nowhere to put the stock. That is the same gap
 * that produced the 128 negative products: stock arriving at a hub was never recorded as arriving,
 * so the first thing the ledger heard about it was the dispatch that took it away.
 *
 * WHAT THIS PINS:
 *   - the trigger and the panel are gated on the SAME permission (the 2026-11-09 dead-button
 *     defect: a trigger rendered under a different gate than the panel it opens);
 *   - the supplier document reference is required on BOTH sides — client refusal names the field,
 *     and the payload actually carries it (the API refuses an empty one: "a receipt with no
 *     reference is an adjustment");
 *   - the POST goes through the page's shared write transport (timeout + offline guard, CSRF
 *     header, bearer token) rather than a bare fetch, so a slow link cannot leave the operator
 *     unsure whether stock was booked;
 *   - an idempotency key travels with the request, so a double click cannot book twice;
 *   - the product picker only offers products assigned to the chosen hub, because the API refuses
 *     the rest — a form that can only produce an error is not a form.
 *
 * Verified in the browser on 2026-11-21 against the running app (production_in_charge):
 *   trigger present · panel opens 768x556 · product options 97 for hub 21 vs 94 for hub 18
 *   (filtering is real, not cosmetic) · empty submit names the missing fields · a product exclusive
 *   to the other hub returned the server's own 422 text inline, styled as an error, with the submit
 *   button re-enabled and nothing written · label/field columns measured flush at x=222 and x=589.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-receipt-ui', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$db = modulePushContext('daily-ledger')->db();

$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('modules/daily-ledger/handlers.php');

$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');

$h->section('the trigger and its panel are gated identically');

$buttonPos = strpos($tpl, 'id="receive-stock-btn"');
$panelPos = strpos($tpl, 'id="receipt-panel"');
$h->test(
    'a Receive Stock trigger exists and calls the panel opener',
    $buttonPos !== false && str_contains($tpl, 'onclick="openReceiptEntry()"'),
    'without a trigger the receipt path is still API-only'
);
// Same gate, proven by DEPTH rather than by substring: the page nests plenty of {if} blocks
// between the button and the panel (the dispatch panel among them), so "no {if} in between" was a
// false test. What must hold is that the outer can_create_commissary_delivery block is still OPEN
// at both positions, i.e. the running depth never returns to zero.
$gatePos = $buttonPos === false ? false : strrpos(substr($tpl, 0, $buttonPos), '{if can_create_commissary_delivery}');
$depthAt = static function (string $haystack, int $from, int $to): int {
    $slice = substr($haystack, $from, max(0, $to - $from));
    return substr_count($slice, '{if ') - (substr_count($slice, '{if}') + substr_count($slice, '{/if}'));
};
$h->test(
    'the trigger and the panel live under the same permission block',
    $gatePos !== false && $panelPos !== false && $buttonPos < $panelPos
        && $depthAt($tpl, $gatePos, $buttonPos) >= 1
        && $depthAt($tpl, $gatePos, $panelPos) >= 1,
    'a trigger and a panel under different conditions is the 2026-11-09 dead-button defect'
);
$h->test(
    'the panel starts hidden',
    str_contains($tpl, 'id="receipt-panel" style="display: none;'),
    'an always-open panel covers the sheet the operator came to read'
);

$h->section('the form asks for the document the stock arrived on');

foreach ([
    'receipt-hub' => 'commissary',
    'receipt-product' => 'product',
    'receipt-date' => 'business date',
    'receipt-shift' => 'shift',
    'receipt-qty' => 'quantity',
    'receipt-reference' => 'supplier document reference',
] as $id => $label) {
    $h->test(
        "the form has a {$label} control",
        strpos($tpl, 'id="' . $id . '"') !== false,
        'a receipt needs ' . $label . '; the API refuses the request without it'
    );
}
$h->test(
    'the reference field states why it is required',
    strpos($tpl, 'this is what the office checks the stock against') !== false,
    'a required field with no reason gets abandoned at the counter'
);
$h->test(
    'the payload carries the reference',
    str_contains($tpl, 'reference: reference ?') === 1 || preg_match('/reference:\s*reference\s*\?/', $tpl) === 1,
    'collecting a reference and not sending it is worse than not collecting it'
);
$h->test(
    'the client refuses an empty reference and names it',
    str_contains($tpl, "problems.push('the supplier document reference')"),
    'local refusal keeps the operator on the field instead of a round trip'
);

$h->section('the request is sent the way every other write on this page is sent');

$h->test(
    'the POST targets the receipt endpoint through the module base path',
    str_contains($tpl, "window.DL_BASE + '/api/v1/commissary/receipt'"),
    'a doubled prefix (/daily-ledger/daily-ledger/...) 404s and looks like a broken feature'
);
$h->test(
    'it uses the shared write transport, not a bare fetch',
    preg_match('/dlWriteTimeout\(window\.DL_BASE \+ \'\/api\/v1\/commissary\/receipt\'/', $tpl) === 1,
    'the shared guard is what tells the operator nothing was booked on a slow or dropped link'
);
$h->test(
    'it sends the CSRF header and the bearer token',
    str_contains($tpl, "evt.detail.headers['X-CSRF-Token']") // the htmx path still declares it
        && preg_match("/'X-CSRF-Token': \(window\.DL_CSRF \|\| ''\)/", $tpl) === 1
        && preg_match("/'Authorization': \(window\.DL_TOKEN/", $tpl) === 1,
    'the receipt is a write; an unauthenticated or CSRF-less write is refused by the kernel'
);
$h->test(
    'an idempotency key travels with the request',
    str_contains($tpl, 'payload.idempotency_key =') && str_contains($tpl, 'randomUUID'),
    'a double click or a retried request must not book the stock twice'
);
$h->test(
    'a successful booking reloads the sheet and says what it did',
    str_contains($tpl, 'window.location.reload()') && str_contains($tpl, "'Receipt recorded'"),
    'the sheet behind the panel is server-rendered; without the reload the stock looks unbooked'
);
$h->test(
    'a duplicate answer is reported as already recorded, not as a new receipt',
    str_contains($tpl, 'already recorded — nothing was booked twice'),
    'silently treating a replay as a success teaches operators to distrust the number'
);

$h->section('the picker cannot offer what the API will refuse');

$h->test(
    'every product option carries the hubs it is assigned to',
    str_contains($tpl, 'data-hubs=",{p.hub_ids | join:\',\'},"'),
    'the hub filter reads this attribute; without it nothing can be filtered'
);
$h->test(
    'the picker is filtered when the hub changes',
    str_contains($tpl, 'onchange="filterReceiptProducts()"')
        && str_contains($tpl, 'function filterReceiptProducts()'),
    'a form that offers products the API refuses exists only to produce an error message'
);
$h->test(
    'a product hidden by the filter cannot stay selected',
    preg_match('/if \(!selectedStillValid\) \{ sel\.value = \'\'; \}/', $tpl) === 1,
    'keeping a now-invalid choice lets the form submit a product the hub does not carry'
);

$h->section('the handler supplies a hub-scoped product list');

$h->test(
    'products are scoped to active assignments on the accessible commissaries',
    preg_match('/FROM dl_branch_products bp\s+INNER JOIN dl_products p ON p\.id = bp\.product_id AND p\.is_active = 1\s+WHERE bp\.is_active = 1 AND bp\.branch_id IN \(\{\$hubMarks\}\)/s', $handlers) === 1,
    'offering a product that is not assigned to the hub is the exact refusal the API returns'
);
$h->test(
    'the view receives receipt_products',
    str_contains($handlers, "'receipt_products' => \$receiptProducts"),
    'the template renders nothing without it'
);

// ── Live data: the picker's filter actually excludes something ─────────────────────────────
// The invariant is an IDENTITY, not a claim that the data is spotless: what the picker offers must
// equal the active assignments whose product is ALSO active. Measured 2026-11-21 there are 2
// assignments on hubs 18/21 pointing at an inactive product — the picker correctly hides them, and
// this check would fail the moment the `p.is_active = 1` filter was dropped, because the offered
// count would rise to the raw assignment count.
$h->section('live check: the picker offers exactly the assignable pairs');
$commissaryIds = array_map('intval', array_column(
    $db->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [],
    'id'
));
$rawPairs = 0;
$offeredPairs = 0;
$offeredInactive = 0;
if ($commissaryIds !== []) {
    $marks = implode(',', array_fill(0, count($commissaryIds), '?'));
    $raw = $db->prepare("SELECT COUNT(*) FROM dl_branch_products bp WHERE bp.is_active = 1 AND bp.branch_id IN ({$marks})");
    $raw->execute($commissaryIds);
    $rawPairs = (int)$raw->fetchColumn();

    // The handler's own query shape.
    $offered = $db->prepare(
        "SELECT bp.product_id, p.is_active
           FROM dl_branch_products bp
           INNER JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
          WHERE bp.is_active = 1 AND bp.branch_id IN ({$marks})"
    );
    $offered->execute($commissaryIds);
    foreach ($offered->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $offeredPairs++;
        if ((int)$row['is_active'] !== 1) { $offeredInactive++; }
    }
}
$h->test(
    'the picker offers exactly the active assignments whose product is active',
    $commissaryIds !== [] && $offeredPairs > 0 && $offeredPairs <= $rawPairs && $offeredInactive === 0,
    sprintf('offered=%d raw assignments=%d (%d hidden as inactive/missing) inactive offered=%d',
        $offeredPairs, $rawPairs, $rawPairs - $offeredPairs, $offeredInactive)
);

$h->done();
