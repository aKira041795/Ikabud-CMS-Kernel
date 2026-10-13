<?php

declare(strict_types=1);

/**
 * Daily Ledger — editing a production BEG must move the balance data (behavioural).
 *
 * Owner report: editing the beginning on the commissary sheet saved the value but
 * left the suggested actual count (Book Balance = BEG + Produced − Dispatched −
 * Wastage) stale — "it will not update the balance data". This suite runs the REAL
 * saveProductionBeg() script from the DiSyL template in Node and asserts:
 *
 *   AC1 every save reaches the material endpoint with the edited BEG value
 *   AC2 an untouched uncounted row: the prefill, suggestion and tooltip all move
 *       by the same delta the BEG edit applied
 *   AC3 an entry the operator typed survives the BEG edit untouched
 *   AC4 a recorded count survives; the suggestion still moves
 *   AC5 the harness can fail: with the suggestion update removed, the same run
 *       reports the stale value (mutation check)
 *
 * No DB, no browser. The harness stubs the DOM and the write pipe; DiSyL
 * placeholders are substituted the way the renderer would.
 */

$base = dirname(__DIR__, 2);
$template = $base . '/templates/modules/daily-ledger/admin/commissary.disyl';
$harness = $base . '/tests/daily-ledger/daily_ledger_beg_balance_harness.js';

$pass = 0;
$fail = 0;
$errors = [];

function dlBegChk(string $label, bool $ok, string $detail = ''): void
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

function dlBegRun(string $node, string $harness, string $template): array
{
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($template) . ' 2>&1';
    $out = trim((string)shell_exec($cmd));
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : ['__raw' => $out];
}

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !is_file($template) || !is_file($harness)) {
    fwrite(STDERR, "FATAL: node, template or harness missing (node={$node}, template="
        . (is_file($template) ? 'yes' : 'no') . ", harness=" . (is_file($harness) ? 'yes' : 'no') . ")\n");
    exit(2);
}

echo "\n=== DAILY LEDGER — PRODUCTION BEG EDIT MOVES THE BALANCE (behavioural) ===\n\n";

$r = dlBegRun($node, $harness, $template);

dlBegChk(
    'AC1 saves reach the material endpoint with the edited values',
    ($r['savedBegValues'] ?? null) === [8, 6, 7],
    json_encode($r['savedBegValues'] ?? $r)
);
dlBegChk(
    'AC2 untouched uncounted row: prefill + suggestion + tooltip move with BEG',
    ($r['untouched']['actualValue'] ?? '') === '8'
        && ($r['untouched']['actualSuggestion'] ?? '') === '8'
        && str_contains((string)($r['untouched']['actualTitle'] ?? ''), 'Suggested book balance: 8'),
    json_encode($r['untouched'] ?? null)
);
dlBegChk(
    'AC3 an operator-typed entry survives the BEG edit untouched',
    ($r['operatorEntry']['actualValue'] ?? '') === '99'
        && ($r['operatorEntry']['actualSuggestion'] ?? '') === '6',
    json_encode($r['operatorEntry'] ?? null)
);
dlBegChk(
    'AC4 a recorded count survives; the suggestion still moves',
    ($r['counted']['actualValue'] ?? '') === '12'
        && ($r['counted']['actualSuggestion'] ?? '') === '7',
    json_encode($r['counted'] ?? null)
);

// Mutation check: remove the suggestion update; the run must then report stale data.
$mutated = tempnam(sys_get_temp_dir(), 'dl_beg_mut_') . '.disyl';
try {
    $replacements = 0;
    $mutatedSource = str_replace(
        'var newSuggestion = oldSuggestion + delta;',
        'var newSuggestion = oldSuggestion;',
        (string)file_get_contents($template),
        $replacements
    );
    dlBegChk('mutation applied to exactly one line', $replacements === 1, 'replacements=' . $replacements);
    file_put_contents($mutated, $mutatedSource);
    $m = dlBegRun($node, $harness, $mutated);
    dlBegChk(
        'AC5 harness fails when the suggestion update is removed (mutation check)',
        ($m['untouched']['actualSuggestion'] ?? '') !== '8',
        json_encode($m['untouched'] ?? $m)
    );
} finally {
    @unlink($mutated);
}

echo "\n" . str_repeat('─', 38) . "\n";
echo "  RESULTS\n";
echo "  {$pass}/" . ($pass + $fail) . " passed\n";
if ($errors !== []) {
    echo "\n  ❌ FAILURES:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n  Suite: daily-ledger-beg-balance-update\n";

exit($fail === 0 ? 0 : 1);
