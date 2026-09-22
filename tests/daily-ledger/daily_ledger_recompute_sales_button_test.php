<?php

declare(strict_types=1);

/**
 * Daily Ledger — the "Recompute sales" control stays wired
 *
 * Sales is derived server-side (beg_bal + addtl - withdraw - bal_end), so there is no
 * stored total to repair; the control re-reads the four saved columns and repaints.
 * It exists because the figure on screen can be computed client-side from a cell the
 * server has not accepted yet, and that difference is invisible until a reload.
 *
 * These are template-source assertions: the behaviour is exercised in the browser, and
 * what erodes silently is the wiring between the button, the handler and the refresh
 * event. The template is compiled by DiSyL, so these read the source rather than
 * rendering it.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-recompute-sales-button', TestHarness::MODE_PURE);
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

$tpl = (string)file_get_contents($h->basePath() . '/templates/modules/daily-ledger/cashier/ledger.disyl');

$h->section('The control is present and wired');

$h->test('a button with the expected id exists', str_contains($tpl, 'id="recompute-sales-btn"'));
$h->test('the button is labelled in the operator\'s words', str_contains($tpl, 'Recompute sales'));
$h->test('clicking it calls the handler', (bool)preg_match('/id="recompute-sales-btn"[^>]*onclick="window\.dlRecomputeSales\(\)"/s', $tpl));
$h->test('the handler is defined on window', str_contains($tpl, 'window.dlRecomputeSales = function()'));

$h->section('The handler re-reads the saved rows');

// Scope to the function body so a mention elsewhere cannot satisfy the check.
$start = strpos($tpl, 'window.dlRecomputeSales = function()');
$end = strpos($tpl, "htmx.trigger(body, 'dl:rows-refresh');", $start === false ? 0 : $start);
$body = ($start === false || $end === false) ? '' : substr($tpl, $start, $end - $start);

$h->test('the handler body was located', $body !== '');

$h->test('it targets the ledger body', str_contains($body, "getElementById('ledger-body')"));
$h->test('it snapshots the sales cells before refreshing', str_contains($body, "querySelectorAll('.sales-computed')"));
$h->test('it refuses to start twice while busy', str_contains($body, "btn.dataset.busy === '1'"));
$h->test('it restores the button label when finished', str_contains($body, 'btn.textContent = label'));
$h->test('it reports how many cells moved', str_contains($body, 'changed++'));
$h->test('it handles a failed re-read rather than hanging', str_contains($body, 'htmx:responseError') && str_contains($body, 'htmx:sendError'));
$h->test('it has a timeout safety net', str_contains($body, 'setTimeout'));

// The trigger line sits after the body slice, so check it in the whole template.
$h->test('it dispatches the refresh event the tbody listens for', str_contains($tpl, "htmx.trigger(body, 'dl:rows-refresh');"));
$h->test('and that is the same event the tbody declares', str_contains($tpl, 'hx-trigger="dl:rows-refresh"'));

$h->section('It does not misrepresent what it did');

// The honest caveat has to survive: this control cannot make sales agree with a
// carried-forward beginning the server never recorded.
$h->test(
    'the source records that it repaints from SAVED values',
    str_contains($tpl, 'Re-read the saved values and repaint the Sales column')
);
$h->test(
    'the source records that a carried-forward beginning is not the recorded one',
    str_contains($tpl, 'data-orig-beg')
);

$h->done();
