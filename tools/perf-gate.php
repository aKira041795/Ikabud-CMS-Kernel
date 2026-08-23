<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$baselineFile = $projectRoot . '/storage/perf-baseline.json';
$iterations = 100;
$failOnDelta = 25.0;
$record = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--record') {
        $record = true;
        continue;
    }

    if (str_starts_with($arg, '--fail-on-delta=')) {
        $value = substr($arg, strlen('--fail-on-delta='));
        if (!is_numeric($value)) {
            fwrite(STDERR, "ERROR invalid --fail-on-delta value\n");
            exit(2);
        }
        $failOnDelta = (float)$value;
        continue;
    }

    if (str_starts_with($arg, '--iterations=')) {
        $value = substr($arg, strlen('--iterations='));
        if (!is_numeric($value)) {
            fwrite(STDERR, "ERROR invalid --iterations value\n");
            exit(2);
        }
        $iterations = max(1, (int)$value);
        continue;
    }

    fwrite(STDERR, "ERROR unknown argument: {$arg}\n");
    exit(2);
}

if ($failOnDelta < 0 || $failOnDelta > 1000) {
    fwrite(STDERR, "ERROR --fail-on-delta must be between 0 and 1000\n");
    exit(2);
}

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($projectRoot . '/tests/kernel_load_test.php')
    . ' ' . escapeshellarg((string)$iterations)
    . ' --json';

$lines = [];
exec($command . ' 2>&1', $lines, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "ERROR harness failed: " . implode("\n", $lines) . "\n");
    exit(2);
}

$payload = json_decode(implode("\n", $lines), true);
if (!is_array($payload) || !isset($payload['total_ms']) || ($payload['metric'] ?? null) !== 'aggregate_total_ms') {
    fwrite(STDERR, "ERROR harness returned invalid JSON\n");
    exit(2);
}

$currentTotal = (float)$payload['total_ms'];

if ($record) {
    if (@file_put_contents($baselineFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fwrite(STDERR, "ERROR unable to write baseline: {$baselineFile}\n");
        exit(2);
    }

    echo sprintf("RECORDED total_ms=%.2f file=%s\n", $currentTotal, $baselineFile);
    exit(0);
}

$baselineRaw = @file_get_contents($baselineFile);
$baseline = is_string($baselineRaw) ? json_decode($baselineRaw, true) : null;
$baselineTotal = is_array($baseline) ? ($baseline['total_ms'] ?? null) : null;
if (!is_numeric($baselineTotal) || (float)$baselineTotal <= 0) {
    fwrite(STDERR, "ERROR invalid or missing baseline: {$baselineFile}\n");
    exit(2);
}

$baselineTotal = (float)$baselineTotal;
$deltaPct = (($currentTotal - $baselineTotal) / $baselineTotal) * 100;
$limit = $baselineTotal * (1 + ($failOnDelta / 100));
if ($currentTotal > $limit) {
    echo sprintf("FAIL total_ms=%.2f baseline_total_ms=%.2f delta_pct=%+.2f%%\n", $currentTotal, $baselineTotal, $deltaPct);
    exit(1);
}

echo sprintf("PASS total_ms=%.2f baseline_total_ms=%.2f delta_pct=%+.2f%%\n", $currentTotal, $baselineTotal, $deltaPct);
exit(0);
