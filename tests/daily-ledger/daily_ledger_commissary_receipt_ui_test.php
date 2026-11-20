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
    strpos($tpl, 'the paper the returned items travelled on') !== false,
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
    str_contains($tpl, 'window.location.reload()') && str_contains($tpl, "'Return recorded'"),
    'the sheet behind the panel is server-rendered; without the reload the stock looks unbooked'
);
$h->test(
    'a duplicate answer is reported as already recorded, not as a new receipt',
    str_contains($tpl, 'already recorded — nothing was counted twice'),
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
    str_contains($tpl, 'onchange="filterReceiptProducts(); buildReceiptSources()"')
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

$h->section('the REWORK panel — the half that turns returns into stock');

$reworkBtnPos = strpos($tpl, 'id="rework-btn"');
$reworkPanelPos = strpos($tpl, 'id="rework-panel"');
$h->test(
    'a Rework Returns trigger exists and opens its panel',
    $reworkBtnPos !== false && str_contains($tpl, 'onclick="openReworkEntry()"'),
    'without it returns pile up in the holding inventory with no way to convert them'
);
$h->test(
    'the rework panel starts hidden',
    str_contains($tpl, 'id="rework-panel" style="display: none;'),
    'an always-open panel covers the sheet'
);
$h->test(
    'the rework POST targets the rework endpoint through the module base path',
    str_contains($tpl, "window.DL_BASE + '/api/v1/commissary/rework'"),
    'a doubled prefix 404s and looks like a broken feature'
);
$h->test(
    'it uses the shared write transport with CSRF and bearer token',
    preg_match("/dlWriteTimeout\(window\.DL_BASE \+ '\/api\/v1\/commissary\/rework'/", $tpl) === 1
        && preg_match("/'X-CSRF-Token': \(window\.DL_CSRF \|\| ''\)/", $tpl) === 1
        && preg_match("/'Authorization': \(window\.DL_TOKEN/", $tpl) === 1,
    'the rework writes stock; it must go through the same guarded transport'
);
$h->test(
    'the rework picker only offers products that actually have items waiting',
    str_contains($tpl, 'data-awaiting="{r.awaiting}"')
        && str_contains($tpl, 'data-hub="{r.branch_id}"')
        && str_contains($tpl, 'onchange="filterReworkInputs()"'),
    'offering a product with nothing waiting is a form built to produce a refusal'
);
$h->test(
    'the panel states that a returned item is not stock and only the output becomes stock',
    str_contains($tpl, 'Reworking <span class="font-semibold">consumes</span> the returned items')
        || str_contains($tpl, 'consumes</span> the returned items'),
    'the owner rule must be visible on the screen, not only in the API'
);
$h->test(
    'the client refuses reworking a product into itself',
    str_contains($tpl, 'reworking into itself creates stock from nothing'),
    'self-rework would create stock out of nothing; the API refuses it and the form should too'
);
$h->test(
    'the handler supplies only positive awaiting quantities',
    str_contains($handlers, "status = 'awaiting_rework' AND quantity > 0")
        && str_contains($handlers, "'rework_returns' => \$reworkReturns"),
    'the panel reads its options from this payload'
);
$h->test(
    'the output picker is the hub-scoped product list, so both sides are hub-valid',
    str_contains($tpl, 'id="rework-output-product"') && str_contains($tpl, 'data-hubs=",{p.hub_ids | join:\',\'},"'),
    'reworking into a product the hub does not carry is refused by the API'
);

// ── FUSED PULLOUT → RETURN ───────────────────────────────────────────────────────────────────
// Owner direction 2026-11-21: "fuse record pullout and record return (pullout) contextually".
// A branch pullout ALREADY creates a delivery addressed to the hub, so the hub should confirm that
// document rather than retype it. Measured the same day: 613 such deliveries (55,785 units) to hubs
// 18 and 21, and ZERO in the returns pile — the retyping is why.
$h->test(
    'the return panel asks what is being recorded, instead of assuming it must be typed',
    str_contains($tpl, 'id="receipt-source"') && str_contains($tpl, 'onchange="applyReceiptSource()"'),
    'without a source selector the branch document and a retyped copy are indistinguishable'
);
$h->test(
    'the typed fields are separable from the pullout block, so the two do not compete',
    str_contains($tpl, 'id="receipt-manual-fields"') && str_contains($tpl, 'id="receipt-pullout-block"'),
    'both blocks visible at once is the overlap this fusion removes'
);
$h->test(
    'a recorded branch pullout is the DEFAULT source, not the fallback',
    str_contains($tpl, "sel.value = firstAllowed !== null ? firstAllowed : 'manual';")
        && str_contains($tpl, "<option value=\"manual\" data-hub=\"\" data-lines=\"\">Type it in"),
    'defaulting to the typed form is what left 613 pullouts unconfirmed'
);
$h->test(
    'the pullout options are scoped to the selected hub',
    str_contains($tpl, 'var allowed = (optHub === hubId);')
        && str_contains($tpl, 'data-hub="{ip.destination_id}"')
        && str_contains($handlers, "'destination_id' => (int)\$h['destination_id'],"),
    "another hub's pullout is not this hub's to confirm"
);
$h->test(
    'the pullout list is rendered by DiSyL, not assembled from JSON inside a script block',
    !str_contains($tpl, '{json_encode(inbound_pullouts)}')
        && str_contains($tpl, '{foreach inbound_pullouts as ip}'),
    'a DiSyL tag inside a script block is emitted literally and silently yields an empty picker'
);
$h->test(
    'choosing a pullout submits its delivery id and sends no product, quantity or reference',
    str_contains($tpl, 'payload.delivery_id = deliveryId;')
        && str_contains($tpl, 'delete payload.product_id;')
        && str_contains($tpl, 'delete payload.quantity;')
        && str_contains($tpl, 'delete payload.reference;'),
    'sending a typed product alongside a delivery id would let the two disagree'
);
$h->test(
    'the handler refuses a pullout addressed to a different commissary',
    str_contains($handlers, "(int)\$head['destination_id'] !== \$hubId")
        && str_contains($handlers, "'That pullout was sent to a different commissary.'"),
    'a refusal that names the reason beats a silently wrong pile'
);
$h->test(
    'confirming a pullout writes to the returns pile and never to the ledger',
    str_contains($handlers, 'function dl_recordInboundPulloutReturn')
        && str_contains($handlers, 'INSERT INTO dl_commissary_returned_items')
        && !str_contains($handlers, 'dl_applyCommissaryProductLedgerDelta($user, $hubId, $pid'),
    'owner rule: a returned item is not stock — rework is the only thing that creates stock'
);
$h->test(
    'confirming the same pullout twice adds nothing',
    str_contains($handlers, 'WHERE delivery_id = :d AND product_id = :p')
        && str_contains($handlers, "'duplicate' => \$written === 0 && \$already > 0"),
    'a double click must not double the rework pile'
);
$h->test(
    'hubs already confirmed pullouts are excluded in SQL, so the limit counts outstanding work',
    str_contains($handlers, 'd.id NOT IN (SELECT delivery_id FROM dl_commissary_returned_items WHERE delivery_id IS NOT NULL)'),
    'filtering after the LIMIT lets finished work eat the quota and starve a hub'
);
$h->test(
    'the inbound pullout query runs per hub, not once with a global limit',
    str_contains($handlers, 'foreach ($availableCommissaryIds as $hubForPullouts)')
        && str_contains($handlers, 'AND d.destination_id = :hub'),
    'measured 2026-11-21: the 40 most recent pullouts were all one hub, so the other hub saw none'
);

$h->section('One pullout button, not two');

/*
 * Owner 2026-11-21: "we have two button for pullout".
 *
 * The page carried two ways to record stock arriving at the hub FROM a branch, and they disagreed
 * about what the arrival is. "Record pull-out" posted withdrawal_type=pullout with a target hub,
 * which reaches dl_persistPulloutReturnToCommissary and credits the hub's stock ledger -- 2,180
 * such rows carrying 35,045 units were credited as saleable. The returns panel parks the same
 * arrival as awaiting-rework stock that the PIC must identify first.
 *
 * The owner's rule ("a returned product does not add to the qty of stocks") makes the returns panel
 * correct, so the pullout type is gone from the commissary entry modal. These assertions exist
 * because the cost of re-adding it is silent: the button comes back, somebody uses the familiar one,
 * and returned goods quietly become dispatchable stock again.
 */
$h->test(
    'the commissary entry modal no longer offers a pullout type',
    !preg_match('/<option value="pullout">\s*Pull-out\s*<\/option>/', $tpl),
    'pullout credits the hub stock ledger through dl_persistPulloutReturnToCommissary; returns must not'
);
$h->test(
    'the branch/consignee source selector is gone with the pullout type',
    !str_contains($tpl, 'id="commissary-entry-source"')
        && !str_contains($tpl, 'id="commissary-entry-source-wrap"'),
    'it only ever applied to a pullout, and its consignee half carried 0 rows across all history'
);
$h->test(
    'no button is labelled "Record pull-out" any more',
    !preg_match('/>\s*Record pull-out\s*</i', $tpl),
    'a button whose label promises a pullout it can no longer record is the trap, not the fix'
);
$h->test(
    'exactly one control records a hub-side pullout',
    substr_count($tpl, 'onclick="openReceiptEntry()"') === 1,
    'two entry points for one event is how the two stock models coexisted'
);
$h->test(
    'the surviving pullout control is the returns panel, and it is gated on the delivery permission',
    preg_match('/id="receive-stock-btn"[^>]*onclick="openReceiptEntry\(\)"/', $tpl) === 1
        && preg_match('/\{if can_create_commissary_delivery\}<button[^>]*id="receive-stock-btn"/', $tpl) === 1,
    'an ungated entry point writes a permission-refused POST and reads as a broken page'
);
$h->test(
    'charges and damaged-to-rework keep their own entry, so consolidation loses no capability',
    str_contains($tpl, '<option value="charge">Charges</option>')
        && str_contains($tpl, '<option value="other">Other &mdash; damaged product to be reworked</option>'),
    'charge carried 4,032 rows; removing it with the pullout type would delete a live path'
);

$h->done();
