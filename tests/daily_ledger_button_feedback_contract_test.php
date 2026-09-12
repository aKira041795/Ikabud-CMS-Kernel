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
$ledger = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/ledger.disyl');
$handlers = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/handlers.php');

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

$requireAuthStart = strpos($handlers, 'function dlRequireAuth(');
$requireAuthEnd = strpos($handlers, 'function dlAuthenticatedHomeRedirect(', $requireAuthStart ?: 0);
$requireAuthSource = ($requireAuthStart !== false && $requireAuthEnd !== false)
    ? substr($handlers, $requireAuthStart, $requireAuthEnd - $requireAuthStart)
    : '';
dlFeedbackTest(
    'dlRequireAuth API 401 payloads identify an expired session',
    substr_count($requireAuthSource, "'error' => 'Auth required', 'code' => 'session_expired'") === 2
);
dlFeedbackTest(
    'ledger defines one session-expiry handler that redirects to login',
    substr_count($ledger, 'window.dlSessionExpired = function(meta)') === 1
        && str_contains($ledger, "window.location.assign('/daily-ledger/login')")
        && str_contains($ledger, 'clearInterval(cloudProbeInterval)')
);
$classifierStart = strpos($ledger, 'function shouldQueueOperationFailure(');
$classifierEnd = strpos($ledger, 'function replayPendingOperations(', $classifierStart ?: 0);
$classifierSource = ($classifierStart !== false && $classifierEnd !== false)
    ? substr($ledger, $classifierStart, $classifierEnd - $classifierStart)
    : '';
dlFeedbackTest(
    'Auth required is retryable rather than a deterministic rejection',
    str_contains($classifierSource, 'isSessionExpiredResponse(res)')
        && !str_contains($classifierSource, "'Auth required'")
);
dlFeedbackTest(
    'ledger API JSON responses retain their HTTP status',
    str_contains($ledger, 'body.__status = response.status')
        && substr_count($ledger, '.then(dlResponseJson)') >= 10
        && str_contains($ledger, 'return dlResponseJson(response).then(function(result)')
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
