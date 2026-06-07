<?php
/**
 * Kernel OS + DiSyL — Critical Path Load Test
 *
 * Usage: php tests/kernel_load_test.php [iterations=100]
 */

require_once __DIR__ . '/../bootstrap.php';

$iterations = max(1, (int)($argv[1] ?? 100));

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Kernel OS + DiSyL — Load Test  ({$iterations} iterations)       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

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

echo "Warming up...\n";
foreach ($benchmarks as $name => $fn) { for ($i = 0; $i < 5; $i++) $fn(); }

foreach ($benchmarks as $name => $fn) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $fn(); }
    $elapsed = (hrtime(true) - $start) / 1e6;
    $results[$name] = $elapsed;
    printf("  %-25s %8.2f ms  (%.3f ms/call)\n", $name, $elapsed, $elapsed / $iterations);
}

echo "\n" . str_repeat('─', 55) . "\n";
$total = array_sum($results);
foreach ($results as $name => $ms) {
    printf("  %-25s %8.2f ms  %5.1f%%\n", $name, $ms, $total > 0 ? ($ms / $total) * 100 : 0);
}
printf("  %-25s %8.2f ms\n", 'TOTAL:', $total);
echo "\n✓ Complete.\n";
