<?php

declare(strict_types=1);

/**
 * Daily Ledger — a wide table must be scrollable without hunting for its scrollbar.
 *
 * Client report, 2026-11-14 (Variance area):
 *   "pwd ang scroll bar makita ra permi kanang dili nami muadto ug ubos para imove to the left/right"
 * and the owner's follow-up: make it DE FACTO for every view that scrolls sideways.
 *
 * The Daily Sheet had already solved this for itself (drag-to-pan + a scrollbar pinned to the bottom
 * of the window). This pins that the same affordance is now ONE shared module loaded by every shell,
 * rather than a per-page copy that the next view forgets.
 *
 * WHAT THIS PINS:
 *  1. The module exists as a static asset (loaded by path, so it is cached and reused, not inlined
 *     three times).
 *  2. It provides all three behaviours the sheet proved: mouse drag-to-pan, Shift+wheel, and a pinned
 *     bar kept in step in BOTH directions.
 *  3. It discovers tables that actually overflow instead of requiring per-view markup, which is what
 *     makes the behaviour de facto for views added later.
 *  4. It never takes over a container the Daily Sheet already drives - otherwise that page would end
 *     up with two scrollbars under the same table.
 *  5. Every shell in this module (the shared layout and the standalone pages that render their own
 *     <head>) loads it.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-horizontal-scroll', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();

$modulePath = $base . '/public/daily-ledger/assets/dl-scroll.js';
$h->section('one shared module, not a per-page copy');
$h->test('the horizontal-scroll module ships as a static asset', is_file($modulePath));
$module = is_file($modulePath) ? (string)file_get_contents($modulePath) : '';

$h->section('it does what the Daily Sheet proved');
$h->test(
    'drag-to-pan is mouse-only so tablets keep native touch panning',
    str_contains($module, "e.pointerType !== 'mouse'")
        && str_contains($module, 'pointerdown')
        && str_contains($module, 'scrollLeft = startLeft - dx')
);
$h->test(
    'a drag across a cell never swallows a click on a control',
    str_contains($module, 'closest(INTERACTIVE)')
        && str_contains($module, 'Math.abs(dx) + Math.abs(dy) < 4')
);
$h->test(
    'Shift+wheel scrolls sideways',
    str_contains($module, 'e.shiftKey') && str_contains($module, 'el.scrollLeft += delta')
);
$h->test(
    'the pinned bar is a REAL overflow container, not a drawn thumb',
    str_contains($module, 'overflow-x:auto') && str_contains($module, 'position:fixed')
);
$h->test(
    'the bar and the table stay in step in BOTH directions',
    str_contains($module, "if (Math.abs(bar.scrollLeft - activeTarget.scrollLeft) > 1)")
        && str_contains($module, "if (Math.abs(el.scrollLeft - bar.scrollLeft) > 1)")
);

$h->section('de facto: discovered, not wired per view');
$h->test(
    'tables that actually overflow are discovered on their own',
    str_contains($module, 'scrollableAncestor(')
        && str_contains($module, 'scrollWidth > el.clientWidth + 1')
        && str_contains($module, "document.querySelectorAll('table')")
);
$h->test(
    'no bar is drawn when there is nothing to scroll',
    str_contains($module, "bar.style.display = 'none'") || str_contains($module, 'bar.style.display = overflows')
);
$h->test(
    'it is re-evaluated when content, viewport or scroll position changes',
    str_contains($module, 'MutationObserver') && str_contains($module, 'ResizeObserver')
        && str_contains($module, 'IntersectionObserver')
);
$h->test(
    'scrolling inside a page panel is caught, not only window scrolling',
    // Measured 2026-11-14: pages scroll inside #main-content, so a plain window listener never fires
    // and the bar never appeared when the reader reached the table.
    str_contains($module, "document.addEventListener('scroll', scheduleRefresh, { capture: true")
);

$h->section('the Daily Sheet keeps exactly one scrollbar');
$h->test(
    'the module defers to a container the sheet already drives',
    str_contains($module, "dataset.panReady === '1'")
        && substr_count($module, "panReady === '1'") >= 2
);

$h->section('every shell loads it');
$shells = [
    'shared layout' => '/templates/modules/daily-ledger/layouts/app.disyl',
    'commissary sheet (standalone)' => '/templates/modules/daily-ledger/admin/commissary.disyl',
    'variance dashboard (standalone)' => '/templates/modules/daily-ledger/admin/variances.disyl',
];
foreach ($shells as $label => $relative) {
    $source = (string)file_get_contents($base . $relative);
    $h->test(
        $label . ' loads the shared module',
        str_contains($source, '/daily-ledger/assets/dl-scroll.js')
    );
}

$h->done();
