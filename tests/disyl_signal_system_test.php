<?php
/**
 * R34 — SignalSystem Reactive Graph Test
 *
 * Verifies Signal→Computed→Effect dependency tracking, batching,
 * and cleanup in the DiSyL reactive primitives.
 *
 * Run: php tests/disyl_signal_system_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Autoload the reactive classes
require_once __DIR__ . '/../kernel/DiSyL/Reactive/SignalSystem.php';

use Ikabud\Kernel\DiSyL\Reactive\Signal;
use Ikabud\Kernel\DiSyL\Reactive\Computed;
use Ikabud\Kernel\DiSyL\Reactive\Effect;
use Ikabud\Kernel\DiSyL\Reactive\ReactiveContext;

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

echo "=== Signal System Reactive Graph ===\n\n";

// ── Test 1: Basic Signal get/set ──
echo "--- Signal basics ---\n";
$count = new Signal(0);
t('initial value', $count->get() === 0);
$count->set(5);
t('set updates value', $count->get() === 5);
$count->update(fn($v) => $v + 1);
t('update applies function', $count->get() === 6);

// ── Test 2: Signal subscription ──
$notifications = [];
$unsub = $count->subscribe(function ($val) use (&$notifications) {
    $notifications[] = $val;
});
$count->set(10);
$count->set(20);
t('subscriber notified on set', count($notifications) === 2);
t('subscriber receives new values', $notifications === [10, 20]);

$unsub(); // unsubscribe
$count->set(30);
t('unsubscribed callback not called', count($notifications) === 2);

// ── Test 3: Computed — derived signal ──
echo "\n--- Computed ---\n";
$a = new Signal(3);
$b = new Signal(4);
$sum = new Computed(function () use ($a, $b) {
    return $a->get() + $b->get();
});

t('computed initial value', $sum->get() === 7);

$a->set(10);
t('computed updates when dependency changes', $sum->get() === 14);

$b->set(6);
t('computed updates for second dependency', $sum->get() === 16);

// ── Test 4: Computed chain ──
$doubled = new Computed(function () use ($sum) {
    return $sum->get() * 2;
});
t('chained computed', $doubled->get() === 32);

$a->set(1);
// sum = 1 + 6 = 7, doubled = 14
t('chained computed propagates', $doubled->get() === 14);

// ── Test 5: Effect — side effects ──
echo "\n--- Effect ---\n";
$log = [];
$name = new Signal('Alice');

$effect = new Effect(function () use ($name, &$log) {
    $log[] = 'Hello ' . $name->get();
});
t('effect runs immediately', count($log) === 1 && $log[0] === 'Hello Alice');

$name->set('Bob');
t('effect re-runs on dependency change', count($log) >= 2);
t('effect receives updated value', end($log) === 'Hello Bob');

$effect->dispose();
$name->set('Charlie');
t('disposed effect does not re-run', end($log) === 'Hello Bob');

// ── Test 6: Batching ──
echo "\n--- Batching ---\n";
$x = new Signal(1);
$y = new Signal(2);
$batchLog = [];

$batchEffect = new Effect(function () use ($x, $y, &$batchLog) {
    $batchLog[] = $x->get() + $y->get();
});
// Initial run: 1 + 2 = 3
t('batch effect initial', count($batchLog) === 1 && $batchLog[0] === 3);

ReactiveContext::batch(function () use ($x, $y) {
    $x->set(10);
    $y->set(20);
});

// After batch: should have exactly 1 additional run with 10 + 20 = 30
$lastVal = end($batchLog);
t('batch coalesces updates', $lastVal === 30);

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
