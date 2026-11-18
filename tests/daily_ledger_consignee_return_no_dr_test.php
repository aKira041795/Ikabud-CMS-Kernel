<?php

declare(strict_types=1);

/**
 * Concern #2 (owner, 2026-11-17): a consignee return INTO a branch must not demand a DR number.
 *
 * "c lee plaza nag pull-out uli sa baron wala man gud d.i ni dr#, pwd sa walaon na lang ning
 *  mangayu sya ug dr# ky d nmn mi kademand sa lee nga need ug dr number. para makaadd ra dayun
 *  si baron."
 *
 * The account returns goods it pulled out; the supplier cannot demand a delivery receipt from
 * the account, so the receipt number is not obtainable and blocking on it stops the return from
 * ever being recorded.
 *
 * This asserts the OUTCOME: neither side refuses a branch-entering consignee return that omits
 * `dr_number`. The live HTTP round-trip is covered by the follow-up integration test; this
 * contract test is what the pre-dispatch gate can run without a server.
 */

$pass = 0;
$fail = 0;
$errors = [];

function dlNoDrTest(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "\n=== DAILY LEDGER: CONSIGNEE RETURN WITHOUT DR ===\n\n";

$handlerPath = __DIR__ . '/../modules/daily-ledger/handlers-deliveries.php';
$modalPath = __DIR__ . '/../templates/modules/daily-ledger/cashier/receive_modal.disyl';

foreach ([$handlerPath, $modalPath] as $path) {
    dlNoDrTest(
        'source present: ' . basename($path),
        is_file($path),
        $path
    );
}

$handler = is_file($handlerPath) ? (string)file_get_contents($handlerPath) : '';
$modal = is_file($modalPath) ? (string)file_get_contents($modalPath) : '';

// Server: the branch-entering guard that refuses an empty DR must not exist.
dlNoDrTest(
    'server accepts a consignee return that enters a branch without a DR number',
    $handler !== '' && !str_contains(
        $handler,
        'A Delivery Receipt number is required when returned stock enters a branch.'
    ),
    'apiRecordConsigneeReturn still refuses an empty dr_number'
);

// Server: the DR is still accepted when one IS supplied (we relaxed the rule, we did not remove
// the concept). A future edit that deletes the input entirely would be a different behaviour.
dlNoDrTest(
    'server still accepts a supplied DR number',
    $handler !== '' && str_contains($handler, "\$drNumber = trim((string)(\$input['dr_number']")
);

// Client: the cashier must be able to submit without typing a DR.
dlNoDrTest(
    'cashier return submission does not block on an empty DR number',
    $modal !== '' && !str_contains($modal, '!this.returnDr.trim()'),
    'receive_modal.disyl still refuses to submit when the DR field is empty'
);

// Client: an empty DR must not be sent as an empty string (an empty string is a value, not an
// absent one, and would look like a real receipt in any later report).
dlNoDrTest(
    'cashier return omits dr_number when it is blank instead of sending an empty string',
    $modal !== '' && !str_contains($modal, "dr_number: this.returnDr.trim(),")
);

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);
