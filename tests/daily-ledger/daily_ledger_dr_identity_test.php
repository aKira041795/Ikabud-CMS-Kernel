<?php

declare(strict_types=1);

/**
 * Daily Ledger — a paper DR identifies ONE delivery, scoped to the destination.
 *
 * OWNER REPORT (2026-10-23): "the point we're fixing here is the double entry of receiving
 * and dispatch of same DR number and items + qty." Answers to the three blocking questions:
 *   1. each DR is for a specific delivery — new deliveries get new DRs (so no second receipt);
 *   2. a pending receipt is auto-accepted when the day closes;
 *   3. a quantity disagreement becomes a variance an admin investigates.
 *
 * MEASURED BEFORE THIS FIX (live fixture, 2026-10-22):
 *   DR 7871 at branch 15 on 16/10 — two rows, identical 8 item lines / 290 pcs, received TWICE
 *     (02:23 and 05:54, same user) => 290 pcs of stock counted twice.
 *   DR 434 at branch 24 on 18/10 — two rows, identical 10 lines / 51 pcs, one received and one
 *     left pending => a phantom delivery waiting to double-count.
 *   Both pairs had the SAME destination, the SAME date and the SAME number, and DIFFERENT
 *   origins (`commissary:21 -> 24` vs `branch:20 -> 24`). Every duplicate guard keyed on the
 *   sender, so each side was blind to the other's row.
 *
 * Each assertion names what breaks if it regresses:
 * - putting the origin back in the duplicate key: the two ends disagree about the sender, so
 *   neither guard can see the other's delivery and one load becomes two (this is the bug);
 * - comparing the DR as raw text: the number is copied off paper, so case and stray spaces
 *   let the same DR slip past the guard;
 * - dropping `status <> voided`: a voided attempt blocks a legitimate re-entry;
 * - removing the destination lock: check-then-insert stays racy.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dr-identity', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$handlerFile = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveryFile = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');

// ---------------------------------------------------------------- normalisation

$h->section('the DR comparison form');

$h->test(
    'case is ignored',
    dl_normalizeDrKey('abc123') === dl_normalizeDrKey('ABC123'),
    'the same paper DR typed in different case would look like a different delivery'
);
$h->test(
    'stray spaces are ignored',
    dl_normalizeDrKey('  DR 434 ') === dl_normalizeDrKey('DR434'),
    'a space typed from the paper would slip the duplicate guard'
);
$h->test(
    'an empty value stays empty so callers can bail out',
    dl_normalizeDrKey('   ') === '',
    'a blank DR would be treated as a real key and match every other blank'
);
$h->test(
    'meaningful punctuation survives',
    dl_normalizeDrKey('AUTO-20/10/2026-1') === 'AUTO-20/10/2026-1',
    'auto-DR labels would collide with each other if separators were stripped'
);

// ---------------------------------------------------------------- the helper

$h->section('destination-scoped identity');

$helperStart = strpos($deliveryFile, 'function dl_findDeliveryByDrAtDestination(');
$helperEnd = $helperStart === false ? false : strpos($deliveryFile, "\nfunction ", $helperStart + 1);
$helperBody = $helperStart === false
    ? ''
    : substr($deliveryFile, $helperStart, ($helperEnd === false ? strlen($deliveryFile) : $helperEnd) - $helperStart);

$h->test('the shared lookup exists', $helperBody !== '' && strlen($helperBody) > 400);

$h->test(
    'the duplicate key does NOT include the sender',
    !str_contains($helperBody, 'origin_type =')
        && !str_contains($helperBody, 'origin_id =')
        && !str_contains($helperBody, 'consignee_id ='),
    'the two ends disagree about who sent the load, so a sender-based key lets each side miss the other'
);
$h->test(
    'voided deliveries do not block a re-entry',
    str_contains($helperBody, 'status <> "voided"'),
    'a voided attempt would permanently reserve its DR'
);
$h->test(
    'the comparison happens in SQL on a normalised value',
    str_contains($helperBody, 'REPLACE(UPPER(TRIM(dr_number))'),
    'a raw equality match would miss the same DR typed with different spacing'
);

// ---------------------------------------------------------------- live behaviour

$h->section('live behaviour on this fixture');

// Fixture-independent probe: take whatever DR the database actually holds.
$probe = $db->query(
    'SELECT destination_id, delivery_date, dr_number
       FROM dl_deliveries
      WHERE dr_number IS NOT NULL AND TRIM(dr_number) <> ""
        AND status <> "voided"
        AND destination_type = "branch"
      ORDER BY id DESC
      LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$h->test('a real delivery with a DR exists to probe', is_array($probe), 'the fixture has no DR-bearing delivery');

if (is_array($probe)) {
    $dest = (int) $probe['destination_id'];
    $date = (string) $probe['delivery_date'];
    $dr = (string) $probe['dr_number'];

    $found = dl_findDeliveryByDrAtDestination($db, $dest, $date, $dr);
    $h->test(
        'the owning delivery is found by its DR',
        is_array($found),
        'the guard would not fire and a second delivery with the same DR could be created'
    );

    $variant = dl_findDeliveryByDrAtDestination($db, $dest, $date, ' ' . strtolower($dr) . ' ');
    $h->test(
        'the same DR typed in lower case with spaces is still found',
        is_array($variant) && (int) $variant['id'] === (int) ($found['id'] ?? 0),
        'a re-typed DR would be treated as a new delivery — the double-entry case'
    );

    $h->test(
        'a different destination is not treated as the same delivery',
        dl_findDeliveryByDrAtDestination($db, $dest + 100000, $date, $dr) === null,
        'split shipments (one DR to two branches) would be refused as duplicates'
    );

    $h->test(
        'an unknown DR finds nothing',
        dl_findDeliveryByDrAtDestination($db, $dest, $date, 'ZZ-NOT-A-REAL-DR-9999') === null,
        'every dispatch would be refused'
    );
}

// The two measured incidents. Kept as explicit cases because they are the proof
// that the destination scope - not the sender - is what makes the two ends meet:
// both pairs have different origins, so a sender-keyed guard returns nothing for
// whichever origin it was not told about.
$h->test(
    'the DR 434 pair is visible to the guard (recorded as commissary:21 AND branch:20)',
    dl_findDeliveryByDrAtDestination($db, 24, '2026-10-18', '434') !== null,
    'the second encoder could create a phantom delivery again'
);
$h->test(
    'the DR 7871 pair is visible to the guard (290 pcs was received twice)',
    dl_findDeliveryByDrAtDestination($db, 15, '2026-10-16', '7871') !== null,
    'the 290 pcs double receipt could happen again'
);

// ---------------------------------------------------------------- the wiring

$h->section('every writer consults it');

$h->test(
    'the receive path resolves the DR through the shared lookup',
    str_contains($handlerFile, '$existing = dl_findDeliveryByDrAtDestination('),
    'a receipt could create a second delivery for a DR the branch already holds'
);
$h->test(
    'the cashier dispatch consults the destination',
    str_contains($handlerFile, 'dl_findDeliveryByDrAtDestination($ctx->db(), (int)$destId, $deliveryDate, $drNumber)'),
    'a dispatch could duplicate a paper capture recorded by the receiving branch'
);
$h->test(
    'the commissary dispatch consults the destination',
    str_contains($handlerFile, 'dl_findDeliveryByDrAtDestination($db, $destinationBranchId, $ledgerDate, $drNumber)'),
    'a dispatch could duplicate a receipt the cashier already captured'
);
$h->test(
    'the receive path re-checks under the destination lock',
    substr_count($handlerFile, 'SELECT id FROM dl_branches WHERE id = :id FOR UPDATE') >= 3,
    'two cashiers could both see "no existing capture" and both insert'
);

// ---------------------------------------------------------------- variance

$h->section('a quantity disagreement becomes a variance');

$h->test(
    'identical lines produce no variance',
    dl_describeDrLineDifference([22 => 5, 23 => 2], [23 => 2, 22 => 5]) === '',
    'every re-submitted dispatch would be flagged as a disagreement'
);
$h->test(
    'a quantity difference names both numbers',
    ($d = dl_describeDrLineDifference([22 => 5], [22 => 3])) !== ''
        && str_contains($d, 'recorded 5')
        && str_contains($d, 'says 3'),
    'the admin would be told there is a difference but not what it is'
);
$h->test(
    'a product only on the dispatch is reported',
    str_contains(dl_describeDrLineDifference([22 => 5], [22 => 5, 99 => 1]), 'product 99'),
    'goods claimed by the dispatch but not received would vanish into a bare refusal'
);
$h->test(
    'a product only on the record is reported',
    ($d = dl_describeDrLineDifference([22 => 5, 77 => 4], [22 => 5])) !== ''
        && str_contains($d, 'product 77'),
    'goods received but not dispatched would vanish into a bare refusal'
);

// Anything NOT awaiting review must stay untouched. The fixture happens to hold
// no 'accepted' row, so probe whatever settled state it does hold.
$verifiedProbe = $db->query(
    'SELECT id, provenance_status FROM dl_deliveries
      WHERE COALESCE(provenance_status, "") <> "paper_dr_pending" AND status <> "voided" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (is_array($verifiedProbe)) {
    $before = (string) ($verifiedProbe['provenance_status'] ?? '');
    dl_flagDrDiscrepancy($db, $verifiedProbe, [22 => 5], [22 => 3], 'probe');
    $after = (string) $db->query('SELECT COALESCE(provenance_status, "") FROM dl_deliveries WHERE id = ' . (int) $verifiedProbe['id'])->fetchColumn();
    $h->test(
        'a settled delivery is not silently re-opened',
        $after === $before,
        'someone else typing the wrong quantity would un-settle reviewed records'
    );
} else {
    $h->test('a settled delivery exists to probe', false, 'fixture has no settled delivery');
}

// ---------------------------------------------------------------- day close

$h->section('a closed day settles provenance');

$autoStart = strpos($deliveryFile, 'function dl_autoAcceptPendingProvenance(');
$autoEnd = $autoStart === false ? false : strpos($deliveryFile, "\nfunction ", $autoStart + 1);
$autoBody = $autoStart === false
    ? ''
    : substr($deliveryFile, $autoStart, ($autoEnd === false ? strlen($deliveryFile) : $autoEnd) - $autoStart);

$h->test('the sweep exists', $autoBody !== '' && strlen($autoBody) > 300);
$h->test(
    'it only moves awaiting-review deliveries',
    str_contains($autoBody, '"paper_dr_pending"'),
    'a sweep would accept records nobody ever looked at'
);
$h->test(
    'it never sweeps a disagreement',
    !str_contains($autoBody, 'discrepant'),
    'closing the day would bury the variance the admin was asked to investigate'
);
$h->test(
    'closing a day runs it',
    str_contains($handlerFile, 'dl_autoAcceptPendingProvenance($ctx->db(), $branchId, $date, $userId)'),
    'a pending receipt would stay pending forever — how DR 434 lingered from 18/10'
);

// ---------------------------------------------------------------- no silent money

$h->section('verifying never moves stock');

// Owner decision 2026-10-23: "leave it until an admin resolves it." The debit
// stays where the capture put it; resolving the paper trail is not a financial
// correction. This assertion exists so nobody adds the move as a side effect of
// ticking a record as verified.
$reviewStart = strpos($deliveryFile, 'function apiReviewDeliveryProvenance(');
$reviewEnd = $reviewStart === false ? false : strpos($deliveryFile, "\nfunction ", $reviewStart + 1);
$reviewBody = $reviewStart === false
    ? ''
    : substr($deliveryFile, $reviewStart, ($reviewEnd === false ? strlen($deliveryFile) : $reviewEnd) - $reviewStart);

$h->test('the verification handler was found', $reviewBody !== '' && strlen($reviewBody) > 1500);
foreach ([
    'dl_applyPostedDeliveryCommissaryLedger',
    'dl_reversePostedDeliveryCommissaryLedger',
    'dl_applyCommissaryProductLedgerDelta',
] as $ledgerCall) {
    $h->test(
        'verification does not call ' . $ledgerCall,
        !str_contains($reviewBody, $ledgerCall),
        'ticking a record as verified would silently move the commissary debit'
    );
}

$h->done();
