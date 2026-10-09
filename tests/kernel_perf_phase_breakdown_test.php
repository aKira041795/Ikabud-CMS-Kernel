<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/http/perf-attribution.php';

$pass = 0;
$fail = 0;

function phaseBreakdownAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$segments = ['session', 'core_routes', 'settings_preload', 'module_routes', 'dispatch_hooks', 'route_match'];
$moduleRouteSegments = ['module_routes_discovery', 'module_routes_registration', 'module_routes_event_flush', 'module_routes_contract_drift'];
$allSegments = array_merge($segments, $moduleRouteSegments);
$fresh = kernelPerfProbeRequestAttribution();

$allPresentAndNull = true;
foreach ($allSegments as $segment) {
    $allPresentAndNull = $allPresentAndNull
        && array_key_exists($segment, $fresh['phases'])
        && $fresh['phases'][$segment] === null;
}
phaseBreakdownAssert('all new cumulative segments exist and begin unmarked', $allPresentAndNull, json_encode($fresh['phases']));
phaseBreakdownAssert(
    'an unmarked segment and its delta are null, not zero',
    $fresh['phases']['route_match'] === null && $fresh['phase_deltas']['route_match'] === null,
    json_encode(['phase' => $fresh['phases']['route_match'], 'delta' => $fresh['phase_deltas']['route_match']])
);

kernelPerfMarkRequestPhase('genuinely_unknown_phase');
$afterUnknown = kernelPerfProbeRequestAttribution();
phaseBreakdownAssert('unknown phase is refused', $afterUnknown === $fresh, json_encode($afterUnknown['phases']));

kernelPerfMarkRequestPhase('boot');
$runtimeOrder = array_merge(
    ['session', 'core_routes', 'settings_preload'],
    $moduleRouteSegments,
    ['module_routes', 'dispatch_hooks', 'route_match', 'dispatch']
);
foreach ($runtimeOrder as $segment) {
    usleep(1_000);
    kernelPerfMarkRequestPhase($segment);
}
$marked = kernelPerfProbeRequestAttribution();

$allMarked = true;
foreach ($allSegments as $segment) {
    $allMarked = $allMarked && is_float($marked['phases'][$segment]);
}
foreach ($segments as $segment) {
    $allMarked = $allMarked && is_float($marked['phase_deltas'][$segment]);
}
foreach ($marked['module_route_deltas'] as $delta) {
    $allMarked = $allMarked && is_float($delta);
}
phaseBreakdownAssert('every new segment can be marked and appears in seam output', $allMarked, json_encode($marked));

$orderedNames = array_merge(['boot'], $runtimeOrder);
$monotonic = true;
for ($i = 1; $i < count($orderedNames); $i++) {
    if ($marked['phases'][$orderedNames[$i]] < $marked['phases'][$orderedNames[$i - 1]]) {
        $monotonic = false;
        break;
    }
}
phaseBreakdownAssert('marks made later have monotonic cumulative values', $monotonic, json_encode($marked['phases']));

$firstSession = $marked['phases']['session'];
usleep(2_000);
kernelPerfMarkRequestPhase('session');
$secondSession = kernelPerfProbeRequestAttribution()['phases']['session'];
phaseBreakdownAssert('marking a segment twice is idempotent', $firstSession === $secondSession, json_encode([$firstSession, $secondSession]));

$indexSource = file_get_contents(__DIR__ . '/../public/index.php');
$sourceOrder = true;
$lastPosition = -1;
$frontControllerOrder = array_merge(['boot'], $segments, ['dispatch']);
foreach ($frontControllerOrder as $phase) {
    $position = strpos((string)$indexSource, "kernelPerfMarkRequestPhase('{$phase}')");
    if ($position === false || $position <= $lastPosition) {
        $sourceOrder = false;
        break;
    }
    $lastPosition = $position;
}
phaseBreakdownAssert('front-controller marks occur in dispatch order', $sourceOrder);

$sum = (float)$marked['phases']['boot'];
foreach ($marked['phase_deltas'] as $delta) {
    $sum += (float)$delta;
}
phaseBreakdownAssert(
    'boot plus segment deltas equals dispatch cumulative total',
    abs($sum - (float)$marked['phases']['dispatch']) < 0.000001,
    json_encode(['sum' => $sum, 'dispatch' => $marked['phases']['dispatch']])
);

// The perf pages take their headline "Total wall time" from the SAME origin as these marks. Before
// 2026-10-09 they used a mark taken inside the handler, after auth, which produced an impossible
// reading — locally total_ms = 134.76 printed beside phase:dispatch = 260.79, a total smaller than one
// of its own phases. This pins the shared origin and the floor that bug violated.
$elapsed = kernelPerfRequestElapsedMs();
phaseBreakdownAssert(
    'request elapsed shares the phase origin and cannot precede the last mark',
    $elapsed !== null && $elapsed >= (float)$marked['phases']['dispatch'],
    json_encode(['elapsed_ms' => $elapsed, 'dispatch' => $marked['phases']['dispatch']])
);

// null, never 0. With no attribution state there was no measurement, and returning 0.0 would report
// that the request took no time — the same unrepresentable-cost trap the phase marks avoid.
$savedAttributionState = $GLOBALS['kernel_perf_request_attribution'] ?? null;
unset($GLOBALS['kernel_perf_request_attribution']);
$elapsedWithoutState = kernelPerfRequestElapsedMs();
$GLOBALS['kernel_perf_request_attribution'] = $savedAttributionState;
phaseBreakdownAssert(
    'elapsed reports null rather than zero when the attribution state is absent',
    $elapsedWithoutState === null,
    var_export($elapsedWithoutState, true)
);

echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
