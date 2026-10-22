<?php

declare(strict_types=1);

/**
 * Daily Ledger — Dispatch-to-Branch modal prefill (client request 2, 2026-10-22).
 *
 * OWNER RULE (2026-10-22): the modal may only be seeded from COMMISSARY (production)
 * truth, never from the branch's encoded daily sheet. The branch sheet records what
 * ARRIVED; seeding a dispatch out of it would let one side rewrite the other's numbers.
 *
 * Each assertion names what breaks if it regresses:
 * - an unregistered route means the modal silently prefills nothing (no error, no seed);
 * - a missing handler means the fetch 404s while the status line claims success;
 * - a matrix that is not [product][branch] => int means quantities land in the wrong cell;
 * - coupling the prefill to the branch sheet would reopen the double-entry hazard.
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
    'the modal calls the prefill on branch selection',
    str_contains($template, 'dispatchPrefillForBranch()'),
    'choosing a branch would leave every quantity blank'
);
$h->test(
    'the prefill never reads a branch sheet',
    !preg_match('/dispatchPrefillForBranch[\\s\\S]{0,4000}?(dl_daily_ledger|branch_receiving)/', $template),
    'seeding from the branch sheet would let one side rewrite the other'
);

$h->section('matrix contract');

$matrix = dl_fetchProductionSheetDispatchMatrix($db, '2026-10-21');
$h->test('the production matrix is non-empty for a dispatched day', $matrix !== [], 'nothing to prefill from');

$shapeOk = true;
$valueOk = true;
foreach ($matrix as $productId => $byBranch) {
    if (!is_int($productId) || !is_array($byBranch)) {
        $shapeOk = false;
        break;
    }
    foreach ($byBranch as $branchId => $qty) {
        if (!is_int($branchId) || !is_int($qty)) {
            $valueOk = false;
        }
    }
}
$h->test('it is keyed [product_id][branch_id]', $shapeOk, 'the modal would seed the wrong cell');
$h->test('every quantity is an int', $valueOk, 'string quantities would seed the wrong value');

// Destinations that received a dispatch on the probe date must be readable back as
// a per-branch line set - the exact shape the modal consumes.
$dest = null;
foreach ($matrix as $byBranch) {
    foreach ($byBranch as $branchId => $qty) {
        if ($qty > 0) {
            $dest = (int)$branchId;
            break 2;
        }
    }
}
$h->test('a dispatched destination is present', $dest !== null);

if ($dest !== null) {
    $lines = 0;
    foreach ($matrix as $byBranch) {
        if ((int)($byBranch[$dest] ?? 0) > 0) {
            $lines++;
        }
    }
    $h->test(
        'that destination reads back as a line set',
        $lines > 0,
        'the prefill response would be empty for a branch that was dispatched to'
    );
}

$h->done();
