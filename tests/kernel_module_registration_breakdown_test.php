<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/http/perf-attribution.php';

$pass = 0;
$fail = 0;

function moduleRegistrationAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$subPhases = [
    'module_reg_helpers_load',
    'module_reg_capability_validate',
    'module_reg_capability_register',
    'module_reg_entity_context',
    'module_reg_entity_sources',
    'module_reg_route_merge',
];

$fresh = kernelPerfProbeRequestAttribution();
$allNull = true;
foreach ($subPhases as $phase) {
    $allNull = $allNull && array_key_exists($phase, $fresh['phases']) && $fresh['phases'][$phase] === null;
}
foreach ($fresh['registration_deltas'] as $delta) {
    $allNull = $allNull && $delta === null;
}
moduleRegistrationAssert('unmarked sub-segments report null, not zero', $allNull, json_encode($fresh['registration_deltas']));

kernelPerfMarkRequestPhase('unknown_module_registration_phase');
$afterUnknown = kernelPerfProbeRequestAttribution();
moduleRegistrationAssert('unknown phase name is refused', $afterUnknown === $fresh);

kernelPerfMarkRequestPhase('module_routes_discovery');
usleep(10_000);
kernelPerfPublishModuleRegistrationBreakdown([
    'helpers_load' => 1_000_000,
    'capability_validate' => 1_000_000,
    'capability_register' => 1_000_000,
    'entity_context' => 1_000_000,
    'entity_sources' => 1_000_000,
    'route_merge' => 1_000_000,
], ['files' => 3, 'bytes' => 4096]);
$marked = kernelPerfProbeRequestAttribution();

$allMarked = true;
foreach ($subPhases as $phase) {
    $allMarked = $allMarked && is_float($marked['phases'][$phase]);
}
foreach ($marked['registration_deltas'] as $delta) {
    $allMarked = $allMarked && is_float($delta);
}
moduleRegistrationAssert(
    'every sub-segment can be marked and appears in seam output',
    $allMarked && $marked['registration_include_cost'] === ['files' => 3, 'bytes' => 4096],
    json_encode($marked['registration_deltas'])
);

$monotonic = true;
$ordered = array_merge(['module_routes_discovery'], $subPhases, ['module_routes_registration']);
for ($i = 1; $i < count($ordered); $i++) {
    if ($marked['phases'][$ordered[$i]] < $marked['phases'][$ordered[$i - 1]]) {
        $monotonic = false;
        break;
    }
}
moduleRegistrationAssert('sub-segment marks are monotonic', $monotonic, json_encode($marked['phases']));

$beforeSecondPublish = $marked;
usleep(2_000);
kernelPerfPublishModuleRegistrationBreakdown(['helpers_load' => 99_000_000]);
$afterSecondPublish = kernelPerfProbeRequestAttribution();
moduleRegistrationAssert('marking the breakdown twice is idempotent', $afterSecondPublish === $beforeSecondPublish);

$sum = array_sum($marked['registration_deltas']);
$parent = $marked['module_route_deltas']['registration'];
moduleRegistrationAssert(
    'sub-segments sum to module_routes_registration',
    is_float($parent) && abs($sum - $parent) < 0.000001,
    json_encode(['sum' => $sum, 'parent' => $parent, 'diff' => $sum - (float)$parent])
);

echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
