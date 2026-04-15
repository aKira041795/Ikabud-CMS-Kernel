<?php
/**
 * R29 — Circuit Breaker Failure/Recovery Test
 *
 * Verifies CapabilityBus breaker opens after threshold failures,
 * blocks during cooldown, and recovers after cooldown expires.
 *
 * Run: php tests/capability_circuit_breaker_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Capabilities\CapabilityCallException;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Circuit Breaker Failure/Recovery ===\n";

// ── Setup: isolated registry + bus with low thresholds ──
$registry = new CapabilityRegistry();
$bus = new CapabilityBus($registry);

$callCount = 0;
$shouldFail = true;

$registry->register(
    'test.breaker@1',
    'test-provider',
    function ($payload) use (&$callCount, &$shouldFail) {
        $callCount++;
        if ($shouldFail) {
            throw new \RuntimeException('Simulated failure');
        }
        return ['ok' => true, 'call' => $callCount];
    },
    priority: 10,
    modes: ['first'],
    meta: [
        'breaker_threshold' => 3,
        'breaker_window_sec' => 60,
        'breaker_cooldown_sec' => 1, // 1 second for fast test
    ]
);

// ── Test 1: Calls succeed before threshold ──
$shouldFail = true;
$callCount = 0;
$exCount = 0;
for ($i = 0; $i < 2; $i++) {
    try {
        $bus->call('test.breaker@1', ['i' => $i], ['caller' => 'kernel']);
    } catch (\Throwable $e) {
        $exCount++;
    }
}
t('first 2 failures pass through (not yet tripped)', $exCount === 2 && $callCount === 2);

// ── Test 2: 3rd failure trips the breaker ──
try {
    $bus->call('test.breaker@1', ['trip' => true], ['caller' => 'kernel']);
} catch (\Throwable $e) {
    $exCount++;
}
t('3rd call executes provider (trips breaker)', $callCount === 3);

// ── Test 3: Subsequent calls blocked without reaching provider ──
$callsBefore = $callCount;
$blockedEx = null;
try {
    $bus->call('test.breaker@1', ['blocked' => true], ['caller' => 'kernel']);
} catch (CapabilityCallException $e) {
    $blockedEx = $e;
}
t('breaker blocks call (provider not invoked)', $callCount === $callsBefore);
t('throws CapabilityCallException', $blockedEx instanceof CapabilityCallException);
t('exception message mentions circuit open', $blockedEx !== null && str_contains($blockedEx->getMessage(), 'circuit open'));

// ── Test 4: After cooldown, half-open allows one request ──
sleep(2); // Wait for 1-second cooldown to expire
$shouldFail = false;
$result = null;
try {
    $result = $bus->call('test.breaker@1', ['recover' => true], ['caller' => 'kernel']);
} catch (\Throwable $e) {
    // Should not happen
}
t('after cooldown, call succeeds (half-open recovery)', is_array($result) && ($result['ok'] ?? false) === true);

// ── Test 5: Breaker is now closed — subsequent calls work ──
$result2 = null;
try {
    $result2 = $bus->call('test.breaker@1', ['normal' => true], ['caller' => 'kernel']);
} catch (\Throwable $e) {
    // Should not happen
}
t('breaker closed after recovery — next call works', is_array($result2) && ($result2['ok'] ?? false) === true);

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
