<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/perf-probe.php';

$pass = 0;
$fail = 0;
$skip = 0;

function perfProbeAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

/**
 * Report an assertion that could not be exercised in this environment.
 *
 * A conditional assertion written as `$a || $b` passes without evaluating
 * anything when the guard is false, so a green line falsely reads as coverage.
 * Reporting SKIP keeps the assertion in the suite while refusing to claim it ran.
 */
function perfProbeSkip(string $name, string $reason): void
{
    global $skip;
    echo 'SKIP: ' . $name . ' — ' . $reason . PHP_EOL;
    $skip++;
}

// A cold scan walks the module tree recursively, reads and schema-validates every
// module.json. It cannot complete inside a millisecond at this repository's module
// count, so the floor is what makes the assertion falsifiable: an implementation
// that hands back the memoised result returns in microseconds and must FAIL here.
// `> 0` would not discriminate — a memo hit is small, not zero.
const PERF_PROBE_COLD_SCAN_FLOOR_MS = 1.0;

$first = kernelPerfProbeColdModuleDiscover();
$second = kernelPerfProbeColdModuleDiscover();
perfProbeAssert('first cold module scan is a real scan, not a memo hit', ($first['ms'] ?? 0) > PERF_PROBE_COLD_SCAN_FLOOR_MS, json_encode($first));
perfProbeAssert('second cold module scan is not memoised', ($second['ms'] ?? 0) > PERF_PROBE_COLD_SCAN_FLOOR_MS, json_encode($second));
perfProbeAssert('cold module scan finds modules', ($first['modules'] ?? 0) > 0 && ($second['modules'] ?? 0) > 0);

$settings = kernelPerfProbeSettingsPreload();
perfProbeAssert('settings preload reports an honest state', in_array($settings['state'] ?? '', ['measured', 'no_tenant'], true), json_encode($settings));

// The duration assertion is only meaningful where a tenant context exists. In
// single-tenant mode (moduleTenantSettingsModeEnabled() false) the seam returns
// `no_tenant` and there is no work to time, so asserting > 0 here would be
// unfalsifiable. Report SKIP rather than a PASS that proves nothing.
if (($settings['state'] ?? '') === 'measured') {
    perfProbeAssert('measured settings preload has measurable duration', ($settings['ms'] ?? 0) > 0, json_encode($settings));
} else {
    perfProbeSkip(
        'measured settings preload has measurable duration',
        'no tenant context in this environment (state=' . ($settings['state'] ?? '?') . ')'
    );
}

$render = kernelPerfProbeDisylRender();
perfProbeAssert('DiSyL probe renders successfully', ($render['ok'] ?? false) === true, json_encode($render));
perfProbeAssert('DiSyL probe exercises extends', ($render['extends'] ?? false) === true, json_encode($render));

echo "Total: " . ($pass + $fail + $skip) . " PASS: {$pass} FAIL: {$fail} SKIP: {$skip}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
