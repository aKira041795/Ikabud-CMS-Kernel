<?php

declare(strict_types=1);

/**
 * Daily Ledger — Dispatch-to-Branch modal: the destination resolves the commissary.
 *
 * OWNER SPEC (2026-10-22):
 *   1. A selected branch OR consignee must resolve to its assigned commissary; the items
 *      listed and every quantity shown come from THAT commissary.
 *   2. Modal used first: all available items are listed; entering qty emits a sheet entry.
 *   3. Sheet set first: the modal shows only the items already carrying a quantity.
 *
 * Each assertion names what breaks if it regresses:
 * - an unregistered route: the modal silently prefills nothing (no error, no seed);
 * - a missing handler: the fetch 404s while the status line still renders;
 * - trusting a client-supplied commissary: the wrong source's stock gets listed;
 * - no consignee destination: half of rule 1 is unreachable from the UI;
 * - no mode field: the modal cannot tell "show everything" from "show only the sheet".
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-prefill', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('wiring');

$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$h->test(
    'the prefill endpoint is routed',
    str_contains($routes, '/daily-ledger/api/v1/commissary/dispatch-prefill')
        && str_contains($routes, 'daily-ledger:apiCommissaryDispatchPrefill'),
    'the modal fetches a route that does not exist and seeds nothing'
);
$h->test(
    'apiCommissaryDispatchPrefill() exists',
    function_exists('apiCommissaryDispatchPrefill'),
    'the route would point at an undefined handler'
);

$template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$h->test(
    'the modal calls the prefill on destination change',
    str_contains($template, 'dispatchPrefillForBranch()'),
    'choosing a destination would leave every quantity blank'
);
$h->test(
    'the destination selector offers consignees too',
    str_contains($template, 'data-type="consignee"') && str_contains($template, '<optgroup label="Consignees">'),
    'rule 1 is only half reachable: a consignee could never be chosen'
);
$h->test(
    'the modal branches on the sheet mode',
    str_contains($template, "res.mode === 'sheet'"),
    'sheet-first and dispatch-first would render identically'
);
$h->test(
    'the modal reports sheet lines it could not render',
    str_contains($template, 'are not on this commissary'),
    'a sheet holding items with no on-hand row would understate itself silently'
);

$h->section('source resolution');

$handlerFile = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$start = strpos($handlerFile, 'function apiCommissaryDispatchPrefill');
$body = $start === false ? '' : substr($handlerFile, $start, 7200);

$h->test('the handler body was found', $body !== '' && strlen($body) > 500);
$h->test(
    'it resolves both branch and consignee assignments',
    str_contains($body, 'FROM dl_consignees') && str_contains($body, 'FROM dl_branches'),
    'one of the two destination kinds would have no source'
);
$h->test(
    'the commissary comes from the assignment, not the request',
    str_contains($body, 'assigned_commissary_id')
        && !preg_match('/\$input\[.commissary_(id|branch_id).\]/', $body),
    'a client could name any source and the wrong stock would be listed'
);
$h->test(
    'it reports the mode the modal switches on',
    str_contains($body, "'mode' =>") && str_contains($body, "'sheet'") && str_contains($body, "'available'"),
    'the modal could not tell show-all from show-sheet'
);
$h->test(
    'both directions read the same posted-dispatch rows',
    str_contains($body, "d.status = 'posted'")
        && str_contains($body, 'd.destination_type = :destination_type')
        && str_contains($body, 'd.destination_id = :destination_id'),
    'the modal and the sheet would be describing different things'
);

$h->section('dispatcher attribution');

// The receiver must know WHO sent the stock and WHICH shift. A paper DR carries both on
// the document; an electronic dispatch used to record neither, so the cashier's receive
// modal showed "not recorded (historical)".
$dispatchStart = strpos($handlerFile, 'function apiCommissaryDispatch(');
// Bound the body by the NEXT top-level function, not by a byte count. A fixed
// window (9000) silently truncated the handler the moment the duplicate-DR
// guard added comments above the INSERT, and these assertions failed while the
// behaviour they describe was untouched. The intent is "this function does X",
// so measure the function, not its length.
$dispatchEnd = $dispatchStart === false ? false : strpos($handlerFile, "\nfunction ", $dispatchStart + 1);
$dispatchLength = $dispatchStart === false
    ? 0
    : (($dispatchEnd === false ? strlen($handlerFile) : $dispatchEnd) - $dispatchStart);
$dispatchBody = $dispatchStart === false ? '' : substr($handlerFile, $dispatchStart, $dispatchLength);
$h->test('the dispatch handler body was found', $dispatchBody !== '' && strlen($dispatchBody) > 2000);
$h->test(
    'the dispatch records the production shift',
    str_contains($dispatchBody, ':dispatch_shift')
        && str_contains($dispatchBody, 'production_shift'),
    'the receiver would not know which shift sent the stock'
);
$h->test(
    'the dispatch records who sent it',
    str_contains($dispatchBody, 'created_by_name_snapshot'),
    'the receiver would see "not recorded" instead of a name'
);
$h->test(
    'an unnamed shift falls back to the running shift, never NULL',
    str_contains($dispatchBody, 'dl_currentShift()'),
    'a dispatch with no shift chosen would leave the field empty'
);

$commissaryTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$h->test(
    'the modal offers an AM/PM shift',
    str_contains($commissaryTpl, 'id="dispatch-shift"') && str_contains($commissaryTpl, 'production_shift: dispatchShift'),
    'the sender could not state the shift'
);

$receiveTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->test(
    'the receiver renders the dispatcher and the shift',
    str_contains($receiveTpl, 'dispatching_cashier_name') && str_contains($receiveTpl, 'g.production_shift'),
    'the information would be stored but never shown, so the change buys nothing'
);

$h->section('fixture preconditions');
$consignee = $db->query(
    'SELECT c.id, c.assigned_commissary_id
       FROM dl_consignees c
       INNER JOIN dl_branches b ON b.id = c.assigned_commissary_id AND b.is_commissary = 1 AND b.is_active = 1
      WHERE c.is_active = 1 LIMIT 1'
);
$consigneeRow = $consignee ? $consignee->fetch(PDO::FETCH_ASSOC) : false;
$h->test(
    'at least one active consignee resolves to an active commissary',
    is_array($consigneeRow),
    'the consignee half of rule 1 would be unexercisable'
);

$assignedBranch = $db->query(
    'SELECT d.id
       FROM dl_branches d
       INNER JOIN dl_branches c ON c.id = d.assigned_commissary_id AND c.is_commissary = 1 AND c.is_active = 1
      WHERE d.is_active = 1 AND d.is_commissary = 0 LIMIT 1'
);
$assignedBranchRow = $assignedBranch ? $assignedBranch->fetch(PDO::FETCH_ASSOC) : false;
$h->test(
    'at least one active branch resolves to an active commissary',
    is_array($assignedBranchRow),
    'the branch half of rule 1 would be unexercisable'
);

$h->section('sheet-only products');

// OWNER REPORT (2026-10-22): a quantity entered on the daily sheet for Miputak did not
// appear in the dispatch modal. The on-hand table keeps only products whose CUMULATIVE
// historical remaining is positive, so one negative day hides a product that still has
// stock today - and the sheet line then has no row to land in.
$h->test(
    'the prefill returns product details for sheet lines',
    str_contains($body, "'items' => \$items,") && str_contains($body, "'on_hand' => 0"),
    'a sheet line whose product has no on-hand row would render nothing'
);
$h->test(
    'on-hand is the day\'s physical balance, not the cumulative sum',
    str_contains($body, 'SUM(beg_qty + produced_qty - dispatched_qty - wastage_qty)'),
    'a negative historical day would report a stocked product as unusable'
);
$h->test(
    'the modal renders sheet-only products as rows',
    str_contains($template, 'dispatch-injected-row'),
    'the reported symptom: the sheet quantity exists but nothing appears'
);
$h->test(
    'injected rows are cleared before the next destination',
    str_contains($template, "querySelectorAll('tr.dispatch-injected-row')"),
    'one destination\'s sheet would leak into the next one\'s list'
);

$h->done();

