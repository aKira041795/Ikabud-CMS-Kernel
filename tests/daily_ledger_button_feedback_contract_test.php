<?php

declare(strict_types=1);

$pass = 0;
$fail = 0;
$errors = [];

function dlFeedbackTest(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== DAILY LEDGER BUTTON FEEDBACK CONTRACT TEST ===\n\n";

$deliveries = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/admin/deliveries.disyl');
$sellingAccountsPath = __DIR__ . '/../templates/modules/daily-ledger/admin/selling-accounts.disyl';
$sellingAccounts = is_file($sellingAccountsPath) ? (string)file_get_contents($sellingAccountsPath) : '';
$priceGroups = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/admin/price-groups.disyl');

dlFeedbackTest(
    'deliveries button actions avoid alert dialogs',
    !str_contains($deliveries, 'alert(')
);
dlFeedbackTest(
    'deliveries create action shows success toast',
    str_contains($deliveries, "showToast((j && j.message) || 'Delivery record created', 'success');")
);
dlFeedbackTest(
    'deliveries finalize action shows failure toast',
    str_contains($deliveries, "showToast((j && j.error) || 'Failed to finalize delivery', 'error');")
);
dlFeedbackTest(
    'deliveries provenance review shows success toast',
    str_contains($deliveries, 'Paper DR review reopened')
        && str_contains($deliveries, "showToast((j && j.message) || successMessage, 'success');")
);

dlFeedbackTest(
    'selling accounts button actions avoid alert dialogs',
    $sellingAccounts === '' || !str_contains($sellingAccounts, 'alert(')
);
dlFeedbackTest(
    'selling accounts create action shows success toast',
    $sellingAccounts === '' || str_contains($sellingAccounts, "showToast((j && j.message) || 'Selling account created', 'success');")
);
dlFeedbackTest(
    'selling accounts update action shows failure toast',
    $sellingAccounts === '' || str_contains($sellingAccounts, "showToast((j && j.error) || 'Failed to update selling account', 'error');")
);

dlFeedbackTest(
    'price groups button actions avoid alert dialogs',
    !str_contains($priceGroups, 'alert(')
);
dlFeedbackTest(
    'price groups save prices shows success toast',
    str_contains($priceGroups, "showToast('Prices saved', 'success');")
);
dlFeedbackTest(
    'price groups create action shows success toast',
    str_contains($priceGroups, "showToast((j && j.message) || 'Price group created', 'success');")
);
dlFeedbackTest(
    'price groups update action shows failure toast',
    str_contains($priceGroups, "showToast(err.message || 'Failed to update price group', 'error');")
);

echo "\n" . str_repeat('-', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
