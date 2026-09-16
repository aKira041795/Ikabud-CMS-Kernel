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

// ── Finalized-shift editability contract ───────────────────────────────────
// A finalized shift refuses writes (dl_assertShiftMutable) while its day may still
// read as open. The ledger must therefore lock those cells up front and offer the
// audited reopen, instead of letting the edit fail into a red flash after the fact
// and queueing a permanent 403 for infinite 30s retries.
$rowsPartial = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');
$helpers = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/helpers.php');

dlFeedbackTest(
    'finalized shift is a deterministic rejection rather than a retry',
    str_contains($classifierSource, "'finalized'") && str_contains($classifierSource, "'locked'")
);
dlFeedbackTest(
    'beginning and ending cells lock when the viewed shift is finalized',
    substr_count($rowsPartial, "|| shift_status == 'finalized'}disabled") === 2
);
dlFeedbackTest(
    'add-stock and withdraw triggers lock when the viewed shift is finalized',
    substr_count($rowsPartial, "&& shift_status != 'finalized'") === 2
);
dlFeedbackTest(
    'ledger cells snapshot the rendered server value on focus',
    substr_count($rowsPartial, 'onfocus="dlSnapshotCell(this); this.select()"') === 2
        && str_contains($ledger, 'function dlSnapshotCell(input)')
        && str_contains($ledger, 'function revertCellToServerValue(input)')
);
dlFeedbackTest(
    'a rejected field save rolls the cell back to the server value',
    str_contains($ledger, 'revertCellToServerValue(input)')
        && str_contains($ledger, 'input.dataset.serverValue = input.value;')
        && str_contains($ledger, 'computeSales(productId);')
);
dlFeedbackTest(
    'a rejected field save surfaces the server reason',
    str_contains($ledger, "window.showToast(res.error || 'Change rejected', 'error')")
);
dlFeedbackTest(
    'quarantine is reserved for rejections the cell could not roll back',
    str_contains($ledger, 'if (!restored) {')
        && str_contains($ledger, "quarantinePendingEntries([buildPendingPayload(productId, field, value)], 'server-rejected', PENDING_KEY);")
);
dlFeedbackTest(
    'rows partial receives the viewed shift lifecycle so the HTMX swap cannot re-enable locked cells',
    substr_count($handlers, "'shift_status' => \$shiftStatus,") >= 2
        && str_contains($handlers, 'dl_getShiftStatus($ctx->db(), (int)$branchId, $ledgerDate, $shift)')
);
dlFeedbackTest(
    'rows render contract carries an editable-by-default shift_status',
    str_contains($helpers, "'shift_status' => 'open',")
);
dlFeedbackTest(
    'ledger explains a finalized shift while the day is still open',
    str_contains($ledger, "{if shift_status == 'finalized' && day_status != 'closed'}")
        && str_contains($ledger, 'is finalized.')
);
dlFeedbackTest(
    'finalized shift offers the audited reopen only to override roles',
    preg_match("/\{if shift_status == 'finalized' && day_status != 'closed'\}(.*?)\{\/if\}/s", $ledger, $banner) === 1
        && str_contains($banner[1], '{if can_ledger_override}')
        && str_contains($banner[1], 'onclick="reopenDay()"')
);
dlFeedbackTest(
    'ledger exposes the shift lifecycle to client-side gating',
    str_contains($ledger, "var SHIFT_STATUS = '{shift_status}';")
        && str_contains($ledger, 'window.SHIFT_STATUS = SHIFT_STATUS;')
);
dlFeedbackTest(
    'reopen prompt distinguishes a shift reopen from a day reopen',
    str_contains($ledger, "String(window.SHIFT_STATUS || '') === 'finalized'")
        && str_contains($ledger, "showToast(isShiftReopen ? (SHIFT + ' shift reopened') : 'Day reopened');")
);

// ── Cloud connectivity contract ────────────────────────────────────────────
// A slow-but-healthy shared-host response used to be reported as "Offline", which
// stops drainPendingWork() and disables day-close/POS, so a false negative looked
// exactly like "the cloud keeps dropping and takes ages to sync again".
$probeStart = strpos($ledger, 'function probeCloud()');
$probeEnd = strpos($ledger, 'cloudProbeInterval = setInterval', $probeStart ?: 0);
$probeSource = ($probeStart !== false && $probeEnd !== false)
    ? substr($ledger, $probeStart, $probeEnd - $probeStart)
    : '';

dlFeedbackTest(
    'cloud probe timeout accommodates shared-hosting render times',
    str_contains($ledger, 'var CLOUD_PROBE_TIMEOUT_MS = 10000;')
        && str_contains($probeSource, '}, CLOUD_PROBE_TIMEOUT_MS)')
        && !str_contains($probeSource, '}, 4000)')
);
dlFeedbackTest(
    'one slow probe cannot declare the cloud offline',
    str_contains($ledger, 'function markCloudProbeFailure()')
        && str_contains($ledger, 'var CLOUD_PROBE_FAILURE_LIMIT = 2;')
        && str_contains($ledger, 'if (!cloudProbeAnswered || cloudProbeFailures >= CLOUD_PROBE_FAILURE_LIMIT) {')
);
dlFeedbackTest(
    'every inconclusive probe failure routes through the hysteresis helper',
    substr_count($probeSource, 'return markCloudProbeFailure();') === 3
        // The two remaining direct assignments are the decisive browser signals:
        // navigator.onLine and the window 'offline' event.
        && substr_count($probeSource, 'cloudOnline = false;') === 2
);
dlFeedbackTest(
    'the browser offline signal stays decisive',
    str_contains($probeSource, 'if (!navigator.onLine) {')
        && str_contains($probeSource, 'cloudProbeAnswered = true;')
);
dlFeedbackTest(
    'a successful probe clears the failure counter and restores online',
    str_contains($ledger, 'function markCloudProbeSuccess()')
        && str_contains($ledger, "cloudProbeFailures = 0;\n        cloudOnline = true;")
        && str_contains($probeSource, 'markCloudProbeSuccess();')
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
