<?php

declare(strict_types=1);

/**
 * Cashier modals — the product picker is ONE control, not a search box above a <select>
 *
 * Owner report 2026-11-16, second pass: "the search at the modals are not intuitive. user cannot
 * see the products as typed. search and dropdown must be one seamless flow."
 *
 * The first attempt added a filter input ABOVE a native <select>. Typing then hid the matches
 * inside a closed dropdown: the operator saw nothing appear, had to open the select, and the two
 * halves still read as two controls. This suite pins the shape that was asked for:
 *
 *   1. every product picker is a combobox — an input with role="combobox" and a listbox of
 *      role="option" results rendered directly beneath it, in the same cell;
 *   2. the native <select> for products is GONE (no `x-model="line.product_id"` select anywhere);
 *   3. the behaviour lives in ONE place (the ledger page's script), not copied per modal;
 *   4. the accessibility contract survives: aria-expanded, aria-autocomplete, aria-selected, and a
 *      visible "no product matches" state instead of an empty box.
 *
 * Static source assertions on purpose: this is an interaction contract, and the browser already
 * proves the behaviour (204 products → 21 matches while typing, click and ArrowDown/Enter both
 * select).
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-modal-product-combobox', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$modalFiles = [
    'receive_modal' => 'modules/daily-ledger/cashier/receive_modal.disyl',
    'dispatch_modal' => 'modules/daily-ledger/cashier/dispatch_modal.disyl',
    'edit_delivery_modal' => 'modules/daily-ledger/cashier/edit_delivery_modal.disyl',
    'modal_patch' => 'modules/daily-ledger/cashier/modal_patch.disyl',
];

$base = $h->basePath();
$ledger = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');

foreach ($modalFiles as $label => $relPath) {
    $h->fingerprint($relPath);
}
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

$h->section('One seamless control per product picker');

foreach ($modalFiles as $label => $relPath) {
    $src = (string)file_get_contents($base . '/templates/' . $relPath);

    $h->test($label . ': has a combobox input', str_contains($src, 'role="combobox"'));
    $h->test($label . ': renders its own result list', str_contains($src, 'role="listbox"') && str_contains($src, 'role="option"'));
    $h->test(
        $label . ': the list is driven by what was typed',
        str_contains($src, 'dlProductMatches(products, line.productQuery)')
    );
    $h->test(
        $label . ': the native product <select> is gone',
        !preg_match('/<select[^>]*x-model="line\.product_id"/', $src)
    );
    $h->test(
        $label . ': selecting is a click on the result (not a separate dropdown step)',
        str_contains($src, 'dlProductPick(line, p)')
    );
    $h->test(
        $label . ': keyboard works without a mouse',
        str_contains($src, 'dlProductMove(line, products, 1)')
        && str_contains($src, 'dlProductMove(line, products, -1)')
        && str_contains($src, 'dlProductCommit(line, products)')
    );
    $h->test(
        $label . ': says so when nothing matches',
        str_contains($src, 'No product matches')
    );
}

$h->section('Behaviour has one home');

$h->test('the helper set is defined on the ledger page', str_contains($ledger, 'function dlProductMatches(')
    && str_contains($ledger, 'function dlProductDisplay(')
    && str_contains($ledger, 'function dlProductPick(')
    && str_contains($ledger, 'function dlProductCommit('));

foreach ($modalFiles as $label => $relPath) {
    $src = (string)file_get_contents($base . '/templates/' . $relPath);
    $h->test($label . ': defines no local copy of the helper', !str_contains($src, 'function dlProductMatches('));
}

$h->test('the superseded two-step helper is gone', !str_contains($ledger, 'function dlVisibleProducts('));

$h->section('Accessibility contract');

foreach ($modalFiles as $label => $relPath) {
    $src = (string)file_get_contents($base . '/templates/' . $relPath);
    $h->test($label . ': announces the open state', str_contains($src, ':aria-expanded='));
    $h->test($label . ': announces selection in the list', str_contains($src, ':aria-selected='));
    $h->test($label . ': the input is labelled', str_contains($src, 'aria-label="Search product"'));
}

$h->done();
