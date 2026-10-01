<?php

declare(strict_types=1);

/**
 * Daily Ledger — auto-DR receive: offline guard, proven behaviourally.
 *
 * The guard this protects was deleted by febe6029 and its old test
 * (`strpos($tpl, 'autoDrBlockedOffline') !== false`) stayed green, because a
 * string test says nothing about behaviour. This suite runs the REAL
 * receiveModal() script in Node and asserts what submitPaperDelivery() does:
 *
 *   - an auto-DR receipt cannot be queued offline and the cashier is told why;
 *   - a paper-DR receipt is still allowed to attempt the online write;
 *   - the receive write path never falls back to a device queue;
 *   - the assertion FAILS when the guard is removed (mutation check).
 *
 * No DB, no notifications, no live rows. The only file written is a temporary
 * mutated copy of the template, removed in finally.
 */

$base = dirname(__DIR__, 2);
$template = $base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl';
$harness = $base . '/tests/daily-ledger/receive_modal_offline_guard_harness.js';

$pass = 0;
$fail = 0;
$errors = [];

function dlGuard(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓  {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * @return array<string,mixed>
 */
function dlGuardRun(string $node, string $harness, string $template, string $mode): array
{
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg($template) . ' ' . escapeshellarg($mode) . ' 2>&1';
    $out = shell_exec($cmd);
    $decoded = json_decode(trim((string)$out), true);
    if (!is_array($decoded)) {
        return ['__raw' => trim((string)$out)];
    }
    return $decoded;
}

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !is_file($template) || !is_file($harness)) {
    fwrite(STDERR, "FATAL: node, template or harness missing (node={$node}, template="
        . (is_file($template) ? 'yes' : 'no') . ", harness=" . (is_file($harness) ? 'yes' : 'no') . ")\n");
    exit(2);
}

echo "\n=== DAILY LEDGER — AUTO-DR RECEIVE OFFLINE GUARD (behavioural) ===\n\n";

$offline = dlGuardRun($node, $harness, $template, 'auto-dr-offline');
dlGuard(
    'offline + auto-DR is refused before any write',
    ($offline['blocked'] ?? false) === true && ($offline['writeCalled'] ?? true) === false,
    json_encode($offline)
);
dlGuard(
    'offline + auto-DR never reaches the device queue',
    ($offline['enqueueCalled'] ?? true) === false,
    json_encode($offline)
);
dlGuard(
    'offline + auto-DR tells the cashier the reason',
    str_contains((string)($offline['errorMsg'] ?? ''), 'requires connectivity')
        && str_contains((string)($offline['errorMsg'] ?? ''), 'enter the paper DR number'),
    json_encode($offline)
);
dlGuard(
    'offline + auto-DR does not leave the save spinner running',
    ($offline['paperSaving'] ?? true) === false,
    json_encode($offline)
);

$online = dlGuardRun($node, $harness, $template, 'auto-dr-online');
dlGuard(
    'online + auto-DR still attempts the write (the server mints the DR)',
    ($online['writeCalled'] ?? false) === true && ($online['blocked'] ?? true) === false,
    json_encode($online)
);
dlGuard(
    'a successful auto-DR write is never buffered on the device',
    ($online['enqueueCalled'] ?? true) === false,
    json_encode($online)
);

$paper = dlGuardRun($node, $harness, $template, 'paper-offline');
dlGuard(
    'offline + paper DR is not caught by the auto-DR guard',
    ($paper['writeCalled'] ?? false) === true,
    json_encode($paper)
);
dlGuard(
    'offline + paper DR is still never buffered',
    ($paper['enqueueCalled'] ?? true) === false,
    json_encode($paper)
);

$serverFail = dlGuardRun($node, $harness, $template, 'auto-dr-server-fail-online');
dlGuard(
    'a retryable server failure reports, and never queues, an auto-DR receive',
    ($serverFail['writeCalled'] ?? false) === true
        && ($serverFail['enqueueCalled'] ?? true) === false
        && str_contains((string)($serverFail['errorMsg'] ?? ''), 'NOT saved'),
    json_encode($serverFail)
);

// ── Revert-failing check ────────────────────────────────────────────────────
// Strip the guard invocation from a temporary copy and re-run the exact same
// offline scenario. If the assertions above were a tautology, this would still
// be "blocked"; it must NOT be.
$tmpTemplate = sys_get_temp_dir() . '/dl-receive-guard-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.disyl';
try {
    $tpl = (string)file_get_contents($template);
    $stripped = str_replace('this.autoDrBlockedOffline()', 'false', $tpl);
    dlGuard(
        'mutation actually removed the guard call(s)',
        $stripped !== $tpl && strpos($stripped, 'this.autoDrBlockedOffline()') === false,
        'strip failed'
    );
    file_put_contents($tmpTemplate, $stripped);

    $mutated = dlGuardRun($node, $harness, $tmpTemplate, 'auto-dr-offline');
    dlGuard(
        'REVERT-FAILING: removing the guard makes the offline auto-DR write proceed',
        ($mutated['writeCalled'] ?? false) === true && ($mutated['blocked'] ?? true) === false,
        json_encode($mutated)
    );
} finally {
    if (is_file($tmpTemplate)) {
        @unlink($tmpTemplate);
    }
}

echo "\n── Result ──\n";
echo "  Result: {$pass} passed, {$fail} failed\n\n";
if ($errors !== []) {
    echo "  Failures:\n";
    foreach ($errors as $e) {
        echo "    - {$e}\n";
    }
    echo "\n";
    exit(1);
}
echo "  OK\n";
exit(0);
