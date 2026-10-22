<?php

declare(strict_types=1);

/**
 * Daily Ledger — structured "adjustment required" refusal (client request 2, option A).
 *
 * Owner decision 2026-10-22: the adjustment contract is NOT relaxed. A second delivery into a
 * cell that already has an entry still requires type + reason + accountable person. What this
 * change adds is a MACHINE-READABLE refusal, so the dispatch modal can ask for those fields
 * instead of dead-ending on "Type is required to change a recorded entry."
 *
 * Scope note (stated, not implied): these assertions prove the signal type, its payload and its
 * wiring. They do NOT drive a real dispatch through the DB — that path needs a commissary
 * fixture and is covered by the existing dispatch suites plus the browser check on
 * baronledger.test.
 */

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../modules/daily-ledger/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== DAILY LEDGER — ADJUSTMENT-REQUIRED SIGNAL ===\n\n";

t('DlAdjustmentRequiredException exists', class_exists('DlAdjustmentRequiredException'));
t(
    'it extends RuntimeException (so existing catches keep working)',
    is_subclass_of('DlAdjustmentRequiredException', RuntimeException::class)
);

if (class_exists('DlAdjustmentRequiredException')) {
    $details = ['field' => 'type', 'types' => ['adjustment_add', 'adjustment_deduct'], 'reasons' => ['encoder_omission', 'other']];
    $e = new DlAdjustmentRequiredException('Type is required to change a recorded entry.', $details);

    t(
        'stable machine-readable code',
        $e->errorCode() === 'ADJUSTMENT_REQUIRED',
        var_export($e->errorCode(), true)
    );
    t('carries HTTP 422 in getCode()', $e->getCode() === 422, (string)$e->getCode());
    t(
        'carries the allowed types/reasons for the modal',
        ($e->details()['types'] ?? []) === $details['types'] && ($e->details()['reasons'] ?? []) === $details['reasons'],
        json_encode($e->details())
    );
    t(
        'details() is empty (not fatal) when none supplied',
        (new DlAdjustmentRequiredException('x'))->details() === []
    );
}

echo "\n── wiring ──\n";

$handlers = (string)@file_get_contents(__DIR__ . '/../../modules/daily-ledger/handlers.php');

t(
    'the dispatch endpoint emits code + adjustment payload',
    (bool)preg_match('/instanceof DlAdjustmentRequiredException\s*\)\s*\{\s*\$payload\[\'code\'\]\s*=\s*\$e->errorCode\(\);\s*\$payload\[\'adjustment\'\]\s*=\s*\$e->details\(\);/s', $handlers),
    'the modal cannot react without a machine-readable payload'
);

$typedThrows = substr_count($handlers, 'throw new DlAdjustmentRequiredException(');
t(
    'all four adjustment guards throw the typed refusal (branch + consignee, type + reason)',
    $typedThrows === 4,
    "found {$typedThrows} typed throws, expected 4"
);

t(
    'no bare RuntimeException remains for these two messages',
    !str_contains($handlers, "throw new \\RuntimeException('Type is required to change a recorded entry.');")
        && !str_contains($handlers, "throw new \\RuntimeException('Reason code is required to change a recorded entry.');")
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);
