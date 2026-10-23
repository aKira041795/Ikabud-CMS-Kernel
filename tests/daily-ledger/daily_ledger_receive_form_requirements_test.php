<?php

declare(strict_types=1);

/**
 * Daily Ledger — the paper-DR form says what is still missing.
 *
 * OWNER REPORT (2026-10-23): "cashier modal must show user what to complete when adding paper
 * DR. right now, i have to guess which option must i complete before the receive button is
 * successfully activated."
 *
 * Measured cause: the Save button was gated on canSubmitPaper(), which only checked the two
 * SHIFTS. So the button "activated" with a paper DR number, both dates and every item line
 * still empty, and pressing it produced an error instead. The cashier had to press Save to
 * discover what the form wanted.
 *
 * The fix is one list driving both things: paperMissing() feeds the on-screen checklist AND
 * canSubmitPaper(), so "the button is enabled" and "this will save" cannot disagree.
 *
 * Each assertion names what breaks if it regresses.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receive-form-requirements', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
$tpl = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');

$h->section('one list drives the button and the message');

$h->test(
    'the requirement list exists',
    str_contains($tpl, 'paperMissing() {'),
    'the form would go back to failing only after Save is pressed'
);
$h->test(
    'the disabled state is derived from that list',
    str_contains($tpl, 'return this.paperMissing().length === 0;'),
    'the button could enable while fields are still missing — the reported bug'
);
$h->test(
    'the Save button binds to canSubmitPaper',
    str_contains($tpl, 'x-bind:disabled="paperSaving || !canSubmitPaper()"'),
    'the button would be clickable with an incomplete form'
);

$h->section('every rule validatePaperDelivery enforces is listed');

foreach ([
    'receiving shift' => "missing.push('Receiving shift')",
    'production shift' => "missing.push('Production shift (from paper DR)')",
    'sending branch' => "missing.push('Sending branch')",
    'paper DR number' => "missing.push('Paper DR number')",
    'date sent' => "missing.push('Date sent')",
    'date received' => "missing.push('Date received')",
    'at least one usable item' => 'At least one item with a product and a quantity',
] as $rule => $needle) {
    $h->test(
        'it names the ' . $rule,
        str_contains($tpl, $needle),
        'the cashier would be told to save, then refused for something never mentioned'
    );
}

$h->section('the message is visible and announced');

$h->test(
    'the checklist is a live region',
    str_contains($tpl, 'role="status" aria-live="polite"'),
    'a screen reader user would get no announcement when the form becomes complete'
);
$h->test(
    'the checklist turns from warning to ready',
    str_contains($tpl, 'Still needed before you can save:')
        && str_contains($tpl, 'ready to save'),
    'the cashier could not tell the form is now complete'
);

$h->section('required fields are marked');

foreach (['Sending branch', 'Date sent', 'Date received', 'Paper DR Number', 'Production shift (from paper DR)'] as $label) {
    $h->test(
        'the ' . $label . ' label carries a required mark',
        (bool) preg_match('/' . preg_quote($label, '/') . '\s*<span class="text-rose-500">\*<\/span>/', $tpl),
        'a required field would look optional next to the others'
    );
}

$h->done();
