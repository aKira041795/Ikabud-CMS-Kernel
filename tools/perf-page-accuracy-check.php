<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/http/page-handlers.php';

$passed = 0;
$failed = 0;

$assert = static function (string $description, bool $condition, string $detail = '') use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo 'PASS: ' . $description . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
        return;
    }

    $failed++;
    echo 'FAIL: ' . $description . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$rows = kernelPerfPageRows([
    'total_ms' => 153.67,
    'probe_self_ms' => 122.89,
    'module_discover_cold_state' => 'not_run',
    'cache_hit_rate_pct' => 8.87,
]);

$findRow = static function (callable $matches) use ($rows): ?array {
    foreach ($rows as $row) {
        if ($matches((string)$row[0], $row)) {
            return $row;
        }
    }
    return null;
};

$requestWork = $findRow(static fn(string $label): bool => str_contains(strtolower($label), 'request work'));
$assert(
    'request work equals total minus probe self within 0.01 ms',
    is_array($requestWork) && is_numeric($requestWork[1]) && abs((float)$requestWork[1] - 30.78) <= 0.01,
    is_array($requestWork) ? 'value=' . (string)$requestWork[1] . ' ms' : 'row missing'
);

$total = $findRow(static fn(string $label): bool => str_starts_with($label, 'Total wall time'));
$assert(
    'total wall-time label states that probe overhead is included',
    is_array($total) && str_contains(strtolower((string)$total[0]), 'includes probe overhead'),
    is_array($total) ? 'label=' . $total[0] : 'row missing'
);

$cold = $findRow(static fn(string $label): bool => str_contains(strtolower($label), 'cold scan'));
$assert(
    'default cold-scan row says the probe did not run and explains how to run it',
    is_array($cold) && str_contains(strtolower((string)$cold[1]), 'not run') && str_contains((string)$cold[1], '?deep=1'),
    is_array($cold) ? 'value=' . $cold[1] : 'row missing'
);

$handlerSource = (string)file_get_contents(dirname(__DIR__) . '/src/http/page-handlers.php');
$probeSource = (string)file_get_contents(dirname(__DIR__) . '/src/http/perf-probe.php');
$handlerDeepBranch = preg_match('/if \(\$deepProbe\)\s*\{[^}]*kernelPerfProbeColdModuleDiscover\(true\)/s', $handlerSource) === 1;
$probeDeepBranch = preg_match('/if \(\$deep\)\s*\{\s*try\s*\{[^}]*discoverModulesScanAll\(\)/s', $probeSource) === 1;
$assert(
    'discoverModulesScanAll is reachable from the perf page only through the deep branch',
    $handlerDeepBranch && $probeDeepBranch,
    'handler_branch=' . ($handlerDeepBranch ? 'yes' : 'no') . ', probe_branch=' . ($probeDeepBranch ? 'yes' : 'no')
);

$render = $findRow(static fn(string $label): bool => str_starts_with($label, 'Request phase: render'));
$assert(
    'raw-HTML render phase is structurally not applicable, not unmeasured',
    is_array($render) && str_contains(strtolower((string)$render[0]), 'raw html')
        && $render[1] === 'not applicable' && $render[1] !== 'not measured',
    is_array($render) ? 'label=' . $render[0] . ', value=' . $render[1] : 'row missing'
);

$shutdown = $findRow(static fn(string $label): bool => str_starts_with($label, 'Request phase: shutdown'));
$assert(
    'post-response shutdown phase is structurally not applicable, not unmeasured',
    is_array($shutdown) && str_contains(strtolower((string)$shutdown[0]), 'after response')
        && $shutdown[1] === 'not applicable' && $shutdown[1] !== 'not measured',
    is_array($shutdown) ? 'label=' . $shutdown[0] . ', value=' . $shutdown[1] : 'row missing'
);

$boot = $findRow(static fn(string $label): bool => $label === 'Request phase: boot');
$assert(
    'an absent payload key is not collected and remains distinct from not applicable',
    is_array($boot) && $boot[1] === 'not collected' && is_array($render) && $boot[1] !== $render[1],
    is_array($boot) ? 'absent=' . $boot[1] . ', structural=' . ($render[1] ?? 'missing') : 'row missing'
);

$cacheHit = $findRow(static fn(string $label): bool => str_contains(strtolower($label), 'cache hit rate'));
$assert(
    'cache hit-rate row names the page-cache scope in its label and value',
    is_array($cacheHit) && str_contains(strtolower((string)$cacheHit[0]), 'page cache')
        && str_contains(strtolower((string)$cacheHit[2]), 'page cache'),
    is_array($cacheHit) ? 'label=' . $cacheHit[0] . ', unit=' . $cacheHit[2] : 'row missing'
);

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
