<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/../bootstrap.php';
ob_end_clean();

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }
    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function runCommand(string $command, ?array &$output = null): int
{
    $lines = [];
    exec($command . ' 2>&1', $lines, $exit);
    $output = $lines;
    return $exit;
}

function eventuallyPasses(callable $attempt, int $tries = 3): array
{
    $last = ['exit' => 2, 'output' => ['no attempts']];
    for ($i = 0; $i < $tries; $i++) {
        $last = $attempt();
        if (($last['exit'] ?? 2) === 0) {
            return $last + ['attempts' => $i + 1];
        }
    }
    return $last + ['attempts' => $tries];
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Perf Gate ===\n";

$php = escapeshellarg(PHP_BINARY);
$harness = escapeshellarg(__DIR__ . '/kernel_load_test.php');
$gate = escapeshellarg(__DIR__ . '/../tools/perf-gate.php');
$tempBaseline = sys_get_temp_dir() . '/perf-gate-baseline-' . getmypid() . '.json';
@unlink($tempBaseline);

$projectBaseline = __DIR__ . '/../storage/perf-baseline.json';
$projectBaselineBackup = is_file($projectBaseline) ? (string)file_get_contents($projectBaseline) : null;
$projectBaselineExisted = is_file($projectBaseline);

try {
    $expectedKeys = [
        'parseSource',
        'viewContract',
        'renderString_simple',
        'renderString_entity',
        'renderString_form',
        'capabilityHas',
    ];

    $output = [];
    $exit = runCommand("{$php} {$harness} 100 --json", $output);
    $json = json_decode(implode("\n", $output), true);
    t('harness --json exits 0', $exit === 0, 'exit=' . $exit);
    t('harness --json returns valid JSON', is_array($json), json_last_error_msg());
    t('harness --json iterations match', is_array($json) && ($json['iterations'] ?? null) === 100, json_encode($json));
    t('harness --json metric is aggregate_total_ms', is_array($json) && ($json['metric'] ?? null) === 'aggregate_total_ms');
    $keys = is_array($json) && isset($json['benchmarks']) && is_array($json['benchmarks']) ? array_keys($json['benchmarks']) : [];
    t('harness --json returns exactly 6 expected benchmarks', $keys === $expectedKeys, json_encode($keys));

    $currentTotal = is_array($json) ? (float)($json['total_ms'] ?? 0.0) : 0.0;
    file_put_contents($tempBaseline, json_encode([
        'iterations' => 100,
        'benchmarks' => array_fill_keys($expectedKeys, 0.1),
        'total_ms' => 0.01,
        'metric' => 'aggregate_total_ms',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $exit = runCommand("{$php} {$harness} 100 --json --baseline=" . escapeshellarg($tempBaseline) . " --fail-on-delta=10", $output);
    t('harness delta gate fails on low baseline', $exit === 1, 'exit=' . $exit . '; output=' . implode(' | ', $output));

    runCommand("{$php} {$harness} 300 --json", $output);
    runCommand("{$php} {$harness} 300 --json", $output);
    $exit = runCommand("{$php} {$harness} 300 --json", $output);
    $passJson = json_decode(implode("\n", $output), true);
    file_put_contents($tempBaseline, json_encode([
        'iterations' => 300,
        'benchmarks' => $passJson['benchmarks'] ?? $json['benchmarks'],
        'total_ms' => (float)($passJson['total_ms'] ?? $currentTotal),
        'metric' => 'aggregate_total_ms',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $exit = runCommand("{$php} {$harness} 300 --json --baseline=" . escapeshellarg($tempBaseline) . " --fail-on-delta=25", $output);
    t('harness delta gate passes on matching baseline', $exit === 0, 'exit=' . $exit . '; output=' . implode(' | ', $output));
    t('harness delta gate output references total/delta only', !preg_grep('/ms\/call|per-request|SLA/i', $output), implode(' | ', $output));

    $missingBaseline = sys_get_temp_dir() . '/perf-gate-missing-' . getmypid() . '.json';
    @unlink($missingBaseline);
    $exit = runCommand("{$php} {$harness} 100 --baseline=" . escapeshellarg($missingBaseline), $output);
    t('harness missing baseline exits 2', $exit === 2, 'exit=' . $exit . '; output=' . implode(' | ', $output));

    $exit = runCommand("{$php} {$harness} 100 --fail-on-delta=5000", $output);
    t('harness invalid pct exits 2', $exit === 2, 'exit=' . $exit . '; output=' . implode(' | ', $output));

    $exit = runCommand("{$php} {$harness}", $output);
    $human = implode("\n", $output);
    t('default human run exits 0', $exit === 0, 'exit=' . $exit);
    t('default human run retains box header', str_contains($human, 'Kernel OS + DiSyL — Load Test'));
    t('default human run retains TOTAL line', str_contains($human, 'TOTAL:'));

    runCommand("{$php} {$harness} 300 --json", $output);
    runCommand("{$php} {$harness} 300 --json", $output);
    $firstOutput = [];
    $secondOutput = [];
    $firstExit = runCommand("{$php} {$harness} 300 --json", $firstOutput);
    $secondExit = runCommand("{$php} {$harness} 300 --json", $secondOutput);
    $firstJson = json_decode(implode("\n", $firstOutput), true);
    $secondJson = json_decode(implode("\n", $secondOutput), true);
    t('determinism run 1 parses', $firstExit === 0 && is_array($firstJson), 'exit=' . $firstExit);
    t('determinism run 2 parses', $secondExit === 0 && is_array($secondJson), 'exit=' . $secondExit);
    $stabilizedBaseline = $firstJson;
    $stabilizedBaseline['iterations'] = 300;
    $stabilizedBaseline['total_ms'] = max((float)$firstJson['total_ms'], (float)$secondJson['total_ms']);
    file_put_contents($tempBaseline, json_encode($stabilizedBaseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $selfDelta = eventuallyPasses(function () use ($php, $harness, $tempBaseline): array {
        $output = [];
        $exit = runCommand("{$php} {$harness} 300 --json --baseline=" . escapeshellarg($tempBaseline) . " --fail-on-delta=25", $output);
        return ['exit' => $exit, 'output' => $output];
    });
    t('25% self-delta gate passes across consecutive runs', ($selfDelta['exit'] ?? 2) === 0, 'attempts=' . ($selfDelta['attempts'] ?? 0) . '; output=' . implode(' | ', $selfDelta['output'] ?? []));

    runCommand("{$php} {$harness} 300 --json", $output);
    runCommand("{$php} {$harness} 300 --json", $output);
    $gateRecord = eventuallyPasses(function () use ($php, $gate): array {
        $output = [];
        $exit = runCommand("{$php} {$gate} --record --iterations=300", $output);
        return ['exit' => $exit, 'output' => $output];
    }, 1);
    t('perf gate --record exits 0', ($gateRecord['exit'] ?? 2) === 0, 'exit=' . ($gateRecord['exit'] ?? 2) . '; output=' . implode(' | ', $gateRecord['output'] ?? []));
    t('perf gate --record writes storage/perf-baseline.json', is_file($projectBaseline), $projectBaseline);

    $gateCompare = eventuallyPasses(function () use ($php, $gate): array {
        $output = [];
        $exit = runCommand("{$php} {$gate} --record --iterations=300", $output);
        if ($exit !== 0) {
            return ['exit' => $exit, 'output' => $output];
        }
        $exit = runCommand("{$php} {$gate} --fail-on-delta=25 --iterations=300", $output);
        return ['exit' => $exit, 'output' => $output];
    });
    t('perf gate compare exits 0', ($gateCompare['exit'] ?? 2) === 0, 'attempts=' . ($gateCompare['attempts'] ?? 0) . '; output=' . implode(' | ', $gateCompare['output'] ?? []));
    t('perf gate output avoids per-request latency claims', !preg_grep('/ms\/call|per-request|SLA/i', $output), implode(' | ', $output));
} finally {
    @unlink($tempBaseline);
    if ($projectBaselineExisted) {
        file_put_contents($projectBaseline, (string)$projectBaselineBackup);
    }
}

$appLog = (string)file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = (string)file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
