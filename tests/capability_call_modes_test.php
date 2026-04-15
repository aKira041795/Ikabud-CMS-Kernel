<?php
/**
 * R30 — Pipeline/Fanout Mode Capability Tests
 *
 * Verifies CapabilityBus call modes: first, pipeline, fanout.
 * Uses isolated CapabilityRegistry (no DB required).
 *
 * Run: php tests/capability_call_modes_test.php
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

echo "=== Capability Call Modes ===\n\n";

// ── First Mode ──
// Priority is DESC — higher number = picked first.
echo "--- first mode ---\n";
$reg = new CapabilityRegistry();
$bus = new CapabilityBus($reg);

$reg->register('test.first@1', 'provider-a', fn($p) => ['source' => 'a', 'val' => ($p['x'] ?? 0) + 1], 10, ['first']);
$reg->register('test.first@1', 'provider-b', fn($p) => ['source' => 'b', 'val' => ($p['x'] ?? 0) + 100], 20, ['first']);

$result = $bus->call('test.first@1', ['x' => 5], ['caller' => 'kernel', 'mode' => 'first']);
t('first mode returns highest-priority provider result', ($result['source'] ?? '') === 'b');
t('first mode payload passed correctly', ($result['val'] ?? 0) === 105);

// ── Pipeline Mode ──
// Priority DESC: step-3 (30) → step-2 (20) → step-1 (10)
// Computation: (5*3 + 10) * 2 = 50
echo "\n--- pipeline mode ---\n";
$reg2 = new CapabilityRegistry();
$bus2 = new CapabilityBus($reg2);

$reg2->register('test.pipe@1', 'step-1', function ($p) {
    $p['steps'][] = 'step-1';
    $p['value'] = ($p['value'] ?? 0) * 2;
    return $p;
}, 10, ['pipeline']);

$reg2->register('test.pipe@1', 'step-2', function ($p) {
    $p['steps'][] = 'step-2';
    $p['value'] = ($p['value'] ?? 0) + 10;
    return $p;
}, 20, ['pipeline']);

$reg2->register('test.pipe@1', 'step-3', function ($p) {
    $p['steps'][] = 'step-3';
    $p['value'] = ($p['value'] ?? 0) * 3;
    return $p;
}, 30, ['pipeline']);

$result2 = $bus2->call('test.pipe@1', ['value' => 5, 'steps' => []], ['caller' => 'kernel', 'mode' => 'pipeline']);
t('pipeline chains through all providers in priority DESC order', ($result2['steps'] ?? []) === ['step-3', 'step-2', 'step-1']);
t('pipeline value computed correctly (5*3+10)*2=50', ($result2['value'] ?? 0) === 50);

// Pipeline: null return means "no change" — preserved value flows to next step
$reg3 = new CapabilityRegistry();
$bus3 = new CapabilityBus($reg3);
// Execution order: c (30) → b (20) → a (10)
$reg3->register('test.pipe.null@1', 'a', fn($p) => array_merge($p, ['from_a' => true]), 10, ['pipeline']);
$reg3->register('test.pipe.null@1', 'b', fn($p) => null, 20, ['pipeline']); // no change
$reg3->register('test.pipe.null@1', 'c', fn($p) => array_merge($p, ['from_c' => true]), 30, ['pipeline']);

$result3 = $bus3->call('test.pipe.null@1', ['x' => 42], ['caller' => 'kernel', 'mode' => 'pipeline']);
t('pipeline null return preserves previous value', ($result3['from_c'] ?? false) === true);
t('pipeline continues after null return', ($result3['from_a'] ?? false) === true);

// ── Fanout Mode ──
// Returns {results: {provider: result, ...}, errors: {...}}
echo "\n--- fanout mode ---\n";
$reg4 = new CapabilityRegistry();
$bus4 = new CapabilityBus($reg4);

$reg4->register('test.fanout@1', 'notifier-a', fn($p) => ['channel' => 'email', 'sent' => true], 10, ['fanout']);
$reg4->register('test.fanout@1', 'notifier-b', fn($p) => ['channel' => 'sms', 'sent' => true], 20, ['fanout']);
$reg4->register('test.fanout@1', 'notifier-c', fn($p) => ['channel' => 'push', 'sent' => true], 30, ['fanout']);

$result4 = $bus4->call('test.fanout@1', ['user' => 1], ['caller' => 'kernel', 'mode' => 'fanout']);
$results4 = $result4['results'] ?? [];
t('fanout returns results for all providers', is_array($results4) && count($results4) === 3);
$channels = array_column($results4, 'channel');
sort($channels);
t('fanout hits all providers', $channels === ['email', 'push', 'sms']);

// Fanout: non-strict — partial failure
$reg5 = new CapabilityRegistry();
$bus5 = new CapabilityBus($reg5);
$reg5->register('test.fanout.partial@1', 'ok-1', fn($p) => ['ok' => true], 10, ['fanout']);
$reg5->register('test.fanout.partial@1', 'fail-1', function ($p) { throw new \RuntimeException('boom'); }, 20, ['fanout']);
$reg5->register('test.fanout.partial@1', 'ok-2', fn($p) => ['ok' => true, 'n' => 2], 30, ['fanout']);

$result5 = $bus5->call('test.fanout.partial@1', [], ['caller' => 'kernel', 'mode' => 'fanout', 'strict' => false]);
$successCount = count($result5['results'] ?? []);
$errorCount = count($result5['errors'] ?? []);
t('fanout non-strict continues past failure', $successCount === 2 && $errorCount === 1);

// Fanout: strict — failure throws
$threw = false;
try {
    $bus5->call('test.fanout.partial@1', [], ['caller' => 'kernel', 'mode' => 'fanout', 'strict' => true]);
} catch (CapabilityCallException $e) {
    $threw = true;
}
t('fanout strict mode throws on first failure', $threw);

// ── Mode unsupported ──
// Pipeline silently skips providers that don't list 'pipeline' in their modes,
// returning null when no provider was executed.
echo "\n--- mode enforcement ---\n";
$reg6 = new CapabilityRegistry();
$bus6 = new CapabilityBus($reg6);
$reg6->register('test.modeonly@1', 'only-first', fn($p) => $p, 10, ['first']); // only first

$result6 = $bus6->call('test.modeonly@1', ['val' => 99], ['caller' => 'kernel', 'mode' => 'pipeline']);
t('pipeline on first-only provider returns null (skipped)', $result6 === null);

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
