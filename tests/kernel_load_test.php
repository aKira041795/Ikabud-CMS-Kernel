<?php
/**
 * Kernel OS + DiSyL — Critical Path Load Test
 *
 * Usage: php tests/kernel_load_test.php [iterations=100]
 */

require_once __DIR__ . '/../bootstrap.php';

$args = array_slice($argv, 1);
$iterations = 100;
$jsonMode = false;
$baselineFile = null;
$failOnDelta = 25.0;

foreach ($args as $arg) {
    if ($arg === '--json') {
        $jsonMode = true;
        continue;
    }

    if (str_starts_with($arg, '--baseline=')) {
        $baselineFile = substr($arg, strlen('--baseline='));
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

    if ($arg !== '' && $arg[0] !== '-') {
        $iterations = max(1, (int)$arg);
        continue;
    }

    fwrite(STDERR, "ERROR unknown argument: {$arg}\n");
    exit(2);
}

if ($failOnDelta < 0 || $failOnDelta > 1000) {
    fwrite(STDERR, "ERROR --fail-on-delta must be between 0 and 1000\n");
    exit(2);
}

if (!$jsonMode) {
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║   Kernel OS + DiSyL — Load Test  ({$iterations} iterations)       ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
}

require_once __DIR__ . '/../modules/cms/helpers/58-entity-views.php';

$engine = app()->templates();
$resolver = app()->entityViews();
$caps = app()->capabilities();
$results = [];

$benchmarks = [
    'parseSource' => fn() => $resolver->parseSource('cms.post.recent'),
    'viewContract' => fn() => $resolver->viewContract('cms.post', 'card_grid'),
    'renderString_simple' => fn() => $engine->renderString('Hello {name}!', ['name' => 'World']),
    'renderString_entity' => fn() => $engine->renderString('{ikb_entity_list source="cms.post.recent" view="compact" limit="5" /}', []),
    'renderString_form' => fn() => $engine->renderString('{ikb_form action="test"}{ikb_stat_card label="T" value="42" /}{/ikb_form}', ['csrf_token' => 'x']),
    'capabilityHas' => fn() => $caps->has('cms.content.list@1'),
];

if (!$jsonMode) {
    echo "Warming up...\n";
}
foreach ($benchmarks as $name => $fn) { for ($i = 0; $i < 5; $i++) $fn(); }

foreach ($benchmarks as $name => $fn) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $fn(); }
    $elapsed = (hrtime(true) - $start) / 1e6;
    $results[$name] = $elapsed;
    if (!$jsonMode) {
        printf("  %-25s %8.2f ms  (%.3f ms/call)\n", $name, $elapsed, $elapsed / $iterations);
    }
}

$total = array_sum($results);

if ($jsonMode) {
    echo json_encode([
        'iterations' => $iterations,
        'benchmarks' => $results,
        'total_ms' => $total,
        'metric' => 'aggregate_total_ms',
    ], JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "\n" . str_repeat('─', 55) . "\n";
    foreach ($results as $name => $ms) {
        printf("  %-25s %8.2f ms  %5.1f%%\n", $name, $ms, $total > 0 ? ($ms / $total) * 100 : 0);
    }
    printf("  %-25s %8.2f ms\n", 'TOTAL:', $total);
    echo "\n✓ Complete.\n";
}

if ($baselineFile === null) {
    exit(0);
}

$baselineRaw = @file_get_contents($baselineFile);
$baseline = is_string($baselineRaw) ? json_decode($baselineRaw, true) : null;
$baselineTotal = is_array($baseline) ? ($baseline['total_ms'] ?? null) : null;
if (!is_numeric($baselineTotal) || (float)$baselineTotal <= 0) {
    fwrite(STDERR, "ERROR invalid baseline: {$baselineFile}\n");
    exit(2);
}

$baselineTotal = (float)$baselineTotal;
$deltaPct = (($total - $baselineTotal) / $baselineTotal) * 100;
$message = sprintf(
    "%s total_ms current=%.2f baseline=%.2f delta_pct=%+.2f%% limit=+%.2f%%\n",
    $total > $baselineTotal * (1 + ($failOnDelta / 100)) ? 'FAIL' : 'PASS',
    $total,
    $baselineTotal,
    $deltaPct,
    $failOnDelta
);

fwrite($jsonMode ? STDERR : STDOUT, $message);
exit(str_starts_with($message, 'FAIL') ? 1 : 0);
