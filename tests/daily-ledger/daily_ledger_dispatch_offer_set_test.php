<?php

declare(strict_types=1);

/**
 * Daily Ledger — the show/hide combo filters the DISPATCH selection, per branch (owner model 2026-11-19).
 *
 * OWNER DIRECTION:
 *   "1. branches/commis have show/hide products - makes it
 *    2. the combo above filters the product selection at dropdown per branch/commis
 *    we're checking this wiring vis-a-vis new delivery modal and edit dispatch at PIC"
 *
 * THE DEFECT THIS PINS: the New Delivery modal listed products from the STOCK LEDGER and never
 * consulted dl_branch_products, so hiding a product for a branch had no effect on what could be sent
 * to it. Measured 2026-11-19 at RIZAL-COMMIS: 41 rows offered, 14 of them not shown by Fishport.
 *
 * Each assertion names what breaks if it regresses:
 * - a hidden destination pair must refuse, or a load becomes a row the store's sheet hides;
 * - a shown pair must not refuse, or the gate becomes a wall;
 * - packaging must not refuse, or "With box?" (shipped 2026-11-19) stops working on a store that has
 *   hidden the boxes - the boxes ARE hidden at the hub and travel with cakes;
 * - a ZERO quantity must never refuse, because that is how a correction REMOVES a wrong line and a
 *   refusal would trap it on the dispatch;
 * - the hub's own list must NOT gate, because hiding there means "not produced here": gating on it
 *   refused to ship 2,736 on-hand Toasted Mamon and 653 PANDESAL that the branches still show.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-offer-set', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$db = app()->db();
$base = dirname(__DIR__, 2);
$handlerSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$editSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$templateSource = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

$h->section('packaging is recognised, goods are not');

$h->test(
    'a cake box in category `other` is packaging',
    dl_isPackagingProduct(['product_name' => '10X10 BOX @40', 'product_category' => 'other']) === true,
    'the boxes travel with cakes but are hidden at the hub; without this they stop being dispatchable'
);
$h->test(
    'a cake is not packaging',
    dl_isPackagingProduct(['product_name' => 'BDAY CAKE 1 LAYER', 'product_category' => 'cake']) === false,
    'a cake must pass the destination gate like any other good'
);
$h->test(
    'a non-box `other` product is not packaging',
    dl_isPackagingProduct(['product_name' => 'ECO BAG LARGE', 'product_category' => 'other']) === false,
    'category `other` is not a synonym for packaging - ECO BAG, MILO SACHET and PANDESAL share it'
);

// OWNER 2026-11-19: "cake boxes are at branches, commis don't have that since it's not produced by the
// commissary". The data already says this - all 9 boxes are shown at 24-27 branches and hidden at the
// hubs - so the DR modal listing none of them is the model, not a gap. This guards the name-based
// predicate against a NEW box that does not carry BOX in its name, which would silently stop being
// recognised as packaging.
$catalogueBoxes = $db->query(
    "SELECT id, name AS product_name, product_category FROM dl_products
      WHERE is_active = 1 AND product_category = 'other' AND name LIKE '%box%'"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$unrecognised = array_filter($catalogueBoxes, static fn(array $r): bool => !dl_isPackagingProduct($r));
$h->test(
    'every box in the catalogue is recognised as packaging',
    $catalogueBoxes !== [] && $unrecognised === [],
    'a box renamed without the word BOX would stop being packaging: '
    . implode(', ', array_column($unrecognised, 'product_name'))
);

$h->section('the destination gate answers for the RECEIVING branch');

$destRow = $db->query(
    "SELECT id, name FROM dl_branches WHERE is_active = 1 AND is_commissary = 0 ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$destId = (int)($destRow['id'] ?? 0);
$destName = (string)($destRow['name'] ?? '');

$hiddenProduct = $db->prepare(
    "SELECT p.id, p.name AS product_name, p.product_category
       FROM dl_products p
       JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid
      WHERE p.is_active = 1 AND bp.is_active = 0 AND p.product_category <> 'other'
      ORDER BY p.id LIMIT 1"
);
$hiddenProduct->execute([':bid' => $destId]);
$hiddenRow = $hiddenProduct->fetch(PDO::FETCH_ASSOC) ?: null;

$shownProduct = $db->prepare(
    "SELECT p.id, p.name AS product_name, p.product_category
       FROM dl_products p
       JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid
      WHERE p.is_active = 1 AND bp.is_active = 1
      ORDER BY p.id LIMIT 1"
);
$shownProduct->execute([':bid' => $destId]);
$shownRow = $shownProduct->fetch(PDO::FETCH_ASSOC) ?: null;

$originId = (int)($db->query("SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

if ($destId > 0 && $hiddenRow) {
    $refusal = dl_dispatchAssignmentRefusal($db, $originId, $destId, $hiddenRow);
    $h->test(
        'a product the destination HIDES is refused, and the refusal names the branch',
        $refusal !== null && strpos($refusal, $destName) !== false,
        'this is the whole point: 14 of the 41 rows offered at RIZAL-COMMIS were not on Fishport\'s list'
    );
} else {
    $h->test('a product the destination HIDES is refused', false, 'no hidden destination pair to test against');
}

if ($destId > 0 && $shownRow) {
    $h->test(
        'a product the destination SHOWS is allowed',
        dl_dispatchAssignmentRefusal($db, $originId, $destId, $shownRow) === null,
        'a gate that refuses everything is not a filter'
    );
} else {
    $h->test('a product the destination SHOWS is allowed', false, 'no shown destination pair to test against');
}

$h->test(
    'packaging is never refused by the destination gate',
    dl_dispatchAssignmentRefusal($db, $originId, $destId,
        ['id' => 999999, 'product_name' => 'HALF ROLL BOX @40', 'product_category' => 'other']) === null,
    'the branches show the boxes today, but a store that hid them must still be able to receive one with a cake'
);

$h->test(
    'a dispatch to the hub itself raises no assignment question',
    dl_dispatchAssignmentRefusal($db, $originId, $originId, $shownRow ?: ['id' => 1]) === null,
    'destination === origin is the caller\'s own validation error, not an assignment one'
);

$h->section('the row list is the SET list, not the stock ledger');

// USER FEEDBACK 2026-11-19 (operator, verbatim): "kong mgdeliver c commi dili tanan product moshow,
// dapat kong unsay nakaset nga product nila mao pod ila makita inig mag dr na sila ... inig mag new dr
// na bisan katong ako na gihide makita sya, dayun dghan kayu ang wala".
// That is two defects in one list: hidden products appearing, and set products missing. Both came from
// deriving the rows from the movement ledger instead of the assortment.
$offerHubId = (int)($db->query("SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
$offerRows = $offerHubId > 0 ? dl_dispatchOfferRows($db, [$offerHubId], date('Y-m-d')) : [];
$setCount = (int)($db->query(
    "SELECT COUNT(*) FROM dl_branch_products bp
       JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
      WHERE bp.branch_id = " . (int)$offerHubId . " AND bp.is_active = 1"
)->fetchColumn() ?: 0);
$sheetCount = $offerHubId > 0 ? count(dl_fetchProductionSheetProducts($db, $offerHubId)) : -1;

$h->test(
    'the modal offers exactly what the hub has SET to show',
    $offerRows !== [] && count($offerRows) === $setCount,
    'the operator compares the DR list against "record additional"; a different count is the defect '
    . 'they reported (offered ' . count($offerRows) . ', set ' . $setCount . ')'
);
$h->test(
    'the DR list and the "record additional" list are the same list',
    count($offerRows) === $sheetCount,
    'these are two doors onto the same shelf; when they disagreed the hidings meant nothing '
    . '(DR ' . count($offerRows) . ' vs record-additional ' . $sheetCount . ')'
);
$h->test(
    'a product hidden for the hub cannot leak into the DR list',
    $offerRows !== [] && count(array_filter($offerRows, static fn(array $r): bool => !$r['shown_at_origin'])) === 0,
    'this is the literal complaint: "bisan katong ako na gihide makita sya"'
);
$zeroStockRows = array_filter($offerRows, static fn(array $r): bool => (int)$r['on_hand'] <= 0);
// An INDEPENDENT signal, not a restatement of the rows: products the hub has set that have never
// produced a single ledger row. The old list could not contain these by construction.
$neverMovedIds = array_map('intval', $db->query(
    "SELECT bp.product_id
       FROM dl_branch_products bp
       JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
       LEFT JOIN dl_commissary_product_ledger cpl
              ON cpl.commissary_branch_id = bp.branch_id AND cpl.product_id = bp.product_id
      WHERE bp.branch_id = " . (int)$offerHubId . " AND bp.is_active = 1 AND cpl.id IS NULL"
)->fetchAll(PDO::FETCH_COLUMN) ?: []);
$offeredIds = array_map(static fn(array $r): int => (int)$r['product_id'], $offerRows);
$h->test(
    'a set product that has NEVER moved still gets a row',
    count(array_diff($neverMovedIds, $offeredIds)) === 0,
    'this is the other half of the complaint: "dghan kayu ang wala". '
    . count($neverMovedIds) . ' set product(s) here have no ledger row at all'
);
$h->test(
    'stock is not used to decide what is listed',
    $offerRows !== [] && array_diff(
        array_map('intval', $db->query(
            "SELECT bp.product_id FROM dl_branch_products bp
               JOIN dl_products p ON p.id = bp.product_id AND p.is_active = 1
              WHERE bp.branch_id = " . (int)$offerHubId . " AND bp.is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN) ?: []),
        $offeredIds
    ) === [],
    'every set product must have a row whatever its stock. Formulated as coverage rather than as '
    . '"some rows have no stock", which would pass or fail on the size of the fixture: '
    . count($zeroStockRows) . ' of ' . count($offerRows) . ' rows carry no stock today'
);

$h->section('the wiring the modal depends on exists');

$h->test(
    'the prefill answers which products the destination shows',
    strpos($handlerSource, "'shown_product_ids' => \$shownProductIds") !== false,
    'without this payload the modal cannot narrow itself when a destination is chosen'
);
$h->test(
    'the New Delivery save refuses an unshown line before opening a transaction',
    strpos($handlerSource, 'dl_dispatchAssignmentRefusal(') !== false
        && strpos($handlerSource, "'code' => 'PRODUCT_NOT_ASSIGNED'") !== false,
    'the modal is not the defence: a stale tab or a hand-made payload must obey the same rule'
);
$h->test(
    'the correction path applies the same gate',
    substr_count($editSource, 'dl_dispatchAssignmentRefusal(') >= 1,
    'a correction can ADD a line, so a rule that only covers the first send dissolves on the second'
);
$h->test(
    'a zero quantity is skipped by the correction gate',
    strpos($editSource, '$desired[$editPid] ?? 0) <= 0') !== false,
    'zero is how a wrong line is REMOVED; refusing it would trap the line on the dispatch'
);
$h->test(
    'the modal hides rows the destination does not show',
    strpos($templateSource, 'notShownAtDestination') !== false
        && strpos($templateSource, 'shown_product_ids') !== false,
    'the row list is the hub\'s assortment, which knows nothing about the destination'
);
$h->test(
    'the row list no longer derives from the movement ledger',
    strpos($handlerSource, 'FROM dl_commissary_product_ledger cpl
           INNER JOIN dl_products p ON p.id = cpl.product_id AND p.is_active = 1') === false,
    'that derivation is exactly why hidden products appeared and unstocked ones vanished'
);

$h->done();
