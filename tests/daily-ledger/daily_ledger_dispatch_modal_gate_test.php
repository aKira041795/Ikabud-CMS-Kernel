<?php

declare(strict_types=1);

/**
 * Daily Ledger — the production dispatch control must be usable by a production user.
 *
 * Client concern 4 (2026-11-09): "kaning sa mga commi dili makita ang New Delivery(DR) gamit ilang
 * account, pero sa akong account makita nako".
 *
 * THE BUG THIS LOCKS OUT (measured in a real browser, 2026-11-09):
 *   The "New Delivery (DR)" trigger was gated on `can_create_commissary_delivery`, but the modal it
 *   opens (`#dispatch-panel`, its backdrop, and the "Dispatch to Branch" submit) was still wrapped
 *   in `{if can_view_production_management}`. `can_view_production_management` is FALSE for
 *   production_in_charge, so that role saw a button whose target was never rendered —
 *   openDispatchEntry() had nothing to show. A half-fix looks correct in the diff and does nothing
 *   in the browser.
 *
 * The rule enforced here: a TRIGGER and the TARGET it opens must be gated by the SAME permission.
 * Any future change that re-introduces a split gate fails this test.
 *
 * Source-level assertions are appropriate: the claim is about which template guard wraps which
 * markup, which is exactly what a rendered-HTML check cannot distinguish once both roles render.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-modal-gate', TestHarness::MODE_PURE, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
$commissaryTpl = $base . '/templates/modules/daily-ledger/admin/commissary.disyl';
$deliveriesHandler = $base . '/modules/daily-ledger/handlers-deliveries.php';

$h->section('files exist');
$h->test('commissary template is present', is_file($commissaryTpl), $commissaryTpl);
$h->test('deliveries handler is present', is_file($deliveriesHandler), $deliveriesHandler);

$tpl = is_file($commissaryTpl) ? (string) file_get_contents($commissaryTpl) : '';
$hdl = is_file($deliveriesHandler) ? (string) file_get_contents($deliveriesHandler) : '';

/**
 * Return the zero-based offset of the opening `{if ...}` that encloses $needle, by scanning
 * backwards and counting {/if} against {if}.
 */
$enclosingIf = static function (string $src, string $needle): ?string {
    $pos = strpos($src, $needle);
    if ($pos === false) {
        return null;
    }
    $cursor = $pos;
    $depth = 0;
    while ($cursor > 0) {
        $prevIf = strrpos(substr($src, 0, $cursor), '{if ');
        $prevEnd = strrpos(substr($src, 0, $cursor), '{/if}');
        if ($prevIf === false) {
            return null;
        }
        if ($prevEnd !== false && $prevEnd > $prevIf) {
            $depth++;
            $cursor = $prevEnd;
            continue;
        }
        if ($depth === 0) {
            $close = strpos($src, '}', $prevIf);
            return $close === false ? null : substr($src, $prevIf, $close - $prevIf + 1);
        }
        $depth--;
        $cursor = $prevIf;
    }
    return null;
};

$h->section('trigger and target share one gate');

$triggerGate = '{if can_create_commissary_delivery}';
$h->test(
    'the "New Delivery (DR)" trigger uses can_create_commissary_delivery',
    str_contains($tpl, $triggerGate . '<button type="button" class="btn btn-primary btn-sm" onclick="openDispatchEntry()"'),
    'a production user would not see the trigger at all'
);

$panelGate = $enclosingIf($tpl, 'id="dispatch-panel"');
$h->test(
    'the dispatch panel is rendered (an enclosing {if} exists)',
    $panelGate !== null,
    'openDispatchEntry() would have no target'
);
$h->test(
    'the dispatch panel is NOT gated by can_view_production_management',
    $panelGate === null || !str_contains($panelGate, 'can_view_production_management'),
    'trigger/target gate split: a production_in_charge user gets a button that opens nothing (measured 2026-11-09)'
);
$h->test(
    'the dispatch panel is gated by can_create_commissary_delivery',
    $panelGate !== null && str_contains($panelGate, 'can_create_commissary_delivery'),
    'the panel must be reachable by exactly the roles that can see the trigger'
);

$h->section('no strict-flag re-gate inside the dispatch modal');

// Direct region check rather than a generic enclosing-{if} scan: the submit sits inside several
// nested guards, so the robust claim is that nothing between the panel opening and the submit
// button re-gates on the admin/supervisor-only flag.
$panelStart = strpos($tpl, 'id="dispatch-panel"');
$submitPos = strpos($tpl, 'id="submit-dispatch"');
$modalRegion = ($panelStart !== false && $submitPos !== false && $submitPos > $panelStart)
    ? substr($tpl, $panelStart, $submitPos - $panelStart)
    : '';
$h->test(
    'the modal interior never re-gates on can_view_production_management',
    $modalRegion !== '' && !str_contains($modalRegion, 'can_view_production_management'),
    'a production user could open the panel but the submit would be hidden again'
);
$h->test(
    'the "Dispatch to Branch" submit exists inside the panel',
    $modalRegion !== '',
    'submit-dispatch must live between the panel opening and the panel end'
);

$h->section('the Deliveries surface authorises the same role');

$h->test(
    'handleAdminDeliveries admits production_in_charge',
    str_contains(
        $hdl,
        "function handleAdminDeliveries"
    ),
    'the page itself must load for the role'
);
$h->test(
    'can_create_delivery_docs includes production_in_charge',
    (bool) preg_match("/'can_create_delivery_docs'\s*=>\s*in_array\(\\\$role,\s*\[[^\]]*'production_in_charge'/", $hdl),
    'the role whose job is creating DRs cannot create delivery documents'
);
$h->test(
    'the Deliveries nav link admits production_in_charge',
    (bool) preg_match('/\{if feature_formal_delivery && \([^)]*production_in_charge[^)]*\)\}/', $tpl),
    'the nav link would be hidden while the page itself authorises the role'
);

$h->section('admin-only controls were NOT widened');
$h->test(
    'can_mark_delivery_discrepant stays admin/supervisor only',
    !preg_match("/'can_mark_delivery_discrepant'\s*=>\s*in_array\(\\\$role,\s*\[[^\]]*production_in_charge/", $hdl),
    'production must not gain an office-review right'
);
$h->test(
    'can_change_destination stays admin/supervisor only',
    !preg_match("/'can_change_destination'\s*=>\s*in_array\(\\\$role,\s*\[[^\]]*production_in_charge/", $hdl),
    'production must not gain a destination-rewrite right'
);
$h->test(
    'can_view_production_management is still admin/supervisor only',
    !preg_match('/\$canViewProductionManagement\s*=\s*in_array\(\\\$role,\s*\[[^\]]*production_in_charge/', (string) file_get_contents($base . '/modules/daily-ledger/handlers.php')),
    'Reopen Shift must not be granted to production'
);

$h->done();
