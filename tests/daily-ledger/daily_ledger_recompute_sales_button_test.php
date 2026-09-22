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

// Reading the ledger must not replace saved counts with inferred beginnings.
$h->test(
    'the source records that it repaints from SAVED values',
    str_contains($tpl, 'Re-read the saved values and repaint the Sales column')
);
$h->test(
    'carry-forward is explicit rather than an automatic write on refresh',
    str_contains($tpl, 'window.dlUsePreviousEnding = function(button)') && !str_contains($tpl, 'window.adoptPmBegBal();')
);

// One press has to carry the whole sheet forward, or the cashier opens the row link on
// every product. The rule stays the row link's rule, so anything it would not carry is
// not carried here either.
$h->section('One press carries the unrecorded beginnings forward');

$carryStart = strpos($tpl, 'function dlPendingCarryForward()');
$carryEnd = strpos($tpl, 'function dlCarryForwardBeginnings(', $carryStart === false ? 0 : $carryStart);
$carryBody = ($carryStart === false || $carryEnd === false) ? '' : substr($tpl, $carryStart, $carryEnd - $carryStart);

$h->test('the gather body was located', $carryBody !== '');
$h->test('it gathers the editable beginning cells', str_contains($carryBody, 'input[data-field="beg_bal"]') && str_contains($carryBody, 'if (input.disabled) return;'));
$h->test('it never overwrites a recorded beginning', str_contains($carryBody, 'data-orig-beg'));
$h->test('it reads the AM ending for PM and the previous ending for AM', str_contains($carryBody, "'data-am-end' : 'data-prev-end'"));
$h->test('it skips a row with no ending to carry', str_contains($carryBody, 'if (!(ending > 0) || productId <= 0) return;'));
// Only beg_bal travels: an absent addtl/withdraw/bal_end key is what preserves them.
$h->test('it sends begin balance alone, never the other counts', str_contains($carryBody, '{ product_id: productId, beg_bal: ending }'));
$h->test('that is the same field the row link writes', str_contains($tpl, "input.value = button.dataset.ending;"));

$batchStart = strpos($tpl, 'function dlCarryForwardBeginnings(carry, onDone)');
$batchEnd = strpos($tpl, 'window.dlRecomputeSales = function()', $batchStart === false ? 0 : $batchStart);
$batchBody = ($batchStart === false || $batchEnd === false) ? '' : substr($tpl, $batchStart, $batchEnd - $batchStart);

$h->test('the batch body was located', $batchBody !== '');
$h->test('it writes through the audited save-batch endpoint', str_contains($batchBody, "'/api/v1/cashier/ledger/save-batch'"));
$h->test('it is one request for the whole sheet', str_contains($batchBody, 'rows: carry'));
$h->test('it carries an idempotency key', str_contains($batchBody, 'idempotency_key:'));
$h->test('it stays online-only like every ledger write', str_contains($batchBody, 'window.dlWriteTimeout('));

$h->test('the button press starts the carry', str_contains($tpl, 'var carry = dlPendingCarryForward();'));
$h->test('a failed carry is reported, not silently refreshed past', str_contains($tpl, 'if (!ok) { settle(false); return; }'));

// The sheet runs to 174 products on a real branch, so the day's own action has to be
// reachable without scrolling - and whatever blocks a recompute has to be named.
$h->section('The day actions are pinned, and nothing exists twice');

$h->test('a sticky action bar wraps the day actions', str_contains($tpl, 'id="ledger-action-bar"') && str_contains($tpl, 'sticky top-0'));
$h->test('the bar holds the recompute control', (bool)preg_match('/id="ledger-action-bar".*?id="recompute-sales-btn"/s', $tpl));
$h->test('the bar closes an open day', (bool)preg_match('/id="ledger-action-bar".*?id="close-day-btn"[^>]*onclick="closeDay\(\)"/s', $tpl));
$h->test('the bar reopens a closed day, capability-gated', (bool)preg_match('/if can_ledger_override\s*\}\s*<button[^>]*id="reopen-day-btn"[^>]*onclick="reopenDay\(\)"/s', $tpl));
$h->test('the reopen lives behind the closed-day branch', (bool)preg_match('/\{if day_status == \'closed\'\}.*?id="reopen-day-btn"/s', $tpl));

// closeDay() resolves #close-day-btn by id, so a second copy would silently drive the
// wrong button (or the wrong one would sit under the operator's finger).
$h->test('exactly one day-close button', substr_count($tpl, 'id="close-day-btn"') === 1);
$h->test('exactly one day-reopen button', substr_count($tpl, 'id="reopen-day-btn"') === 1);
$h->test('exactly one recompute button', substr_count($tpl, 'id="recompute-sales-btn"') === 1);

$h->section('A blocked recompute names the block');

$h->test('a closed day points at the reopen', str_contains($tpl, "'This day is closed. Use Reopen Day (top bar) first"));
$h->test('a finalized shift points at the shift reopen', str_contains($tpl, "'The ' + SHIFT + ' shift is finalized. Reopen the shift first"));
$h->test('a read-only date is exported and checked', str_contains($tpl, 'var REFERENCE_ONLY = ') && str_contains($tpl, 'window.REFERENCE_ONLY = REFERENCE_ONLY;') && str_contains($tpl, "if (REFERENCE_ONLY) {"));
$h->test(
    'the block is tested before the sheet is re-read',
    ($guard = strpos($body, "DAY_STATUS === 'closed'")) !== false
        && ($read = strpos($body, "getElementById('ledger-body')")) !== false
        && $guard < $read
);

$h->done();
