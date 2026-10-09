<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/http/perf-attribution.php';

$pass = 0;
$fail = 0;

function attributionAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$fresh = kernelPerfProbeRequestAttribution();
attributionAssert('fresh request has zero queries', $fresh['db']['queries'] === 0, json_encode($fresh['db']));
attributionAssert('fresh request has exactly zero DB time', $fresh['db']['total_ms'] === 0.0, json_encode($fresh['db']));
attributionAssert('unmarked phase is null, not zero', $fresh['phases']['dispatch'] === null, json_encode($fresh['phases']));

// The accumulator must declare NO module. EventBus::fire() wraps any listener
// that declares one in moduleWithContext() -> moduleContextFor() ->
// discoverModules(), and discoverModules() sets its per-request memo only AFTER
// its per-module loop. A module-declaring listener that observes a query fired
// from inside that loop therefore re-enters discovery and recurses -- observed in
// error.log on 2026-10-09 as a 128 MB memory-exhaustion fatal under concurrent
// load. Passing 'kernel' here would silently reintroduce that path, so this
// assertion is the must-refuse for it.
$busReflection = new ReflectionClass(app()->events());
$listenersProp = $busReflection->getProperty('listeners');
// setAccessible() is a no-op since PHP 8.1 and deprecated since PHP 8.5 — omit it.
$dbEventListeners = $listenersProp->getValue(app()->events())['kernel.database.query.after'] ?? [];
$moduleOwners = [];
foreach ($dbEventListeners as $entry) {
    $owner = (string)($entry['module'] ?? '');
    if ($owner !== '') {
        $moduleOwners[] = $owner;
    }
}
attributionAssert(
    'DB accumulator declares no module (avoids discoverModules re-entry)',
    $moduleOwners === [],
    'listeners=' . count($dbEventListeners) . ' module-owners=' . json_encode($moduleOwners)
);

kernelPerfMarkRequestPhase('not-a-phase');
$afterUnknownPhase = kernelPerfProbeRequestAttribution();
attributionAssert('unknown phase is refused', $afterUnknownPhase['phases'] === $fresh['phases'], json_encode($afterUnknownPhase['phases']));

app()->events()->fire('kernel.database.query.after', ['sql' => 'SELECT refused', 'duration_ms' => 'not-a-duration']);
$afterMalformedEvent = kernelPerfProbeRequestAttribution();
attributionAssert('malformed query duration is refused', $afterMalformedEvent['db']['queries'] === 0, json_encode($afterMalformedEvent['db']));

kernelPerfMarkRequestPhase('dispatch');
$firstMark = kernelPerfProbeRequestAttribution()['phases']['dispatch'];
usleep(2_000);
kernelPerfMarkRequestPhase('dispatch');
$secondMark = kernelPerfProbeRequestAttribution()['phases']['dispatch'];
attributionAssert('repeated phase mark is idempotent', is_float($firstMark) && $firstMark === $secondMark, json_encode([$firstMark, $secondMark]));

$queryCount = 7;
for ($i = 0; $i < $queryCount; $i++) {
    app()->db()->query('SELECT ' . ($i + 1) . ' AS attribution_probe')->fetch();
}

$result = kernelPerfProbeRequestAttribution();
attributionAssert('known queries are counted exactly', $result['db']['queries'] === $queryCount, json_encode($result['db']));
attributionAssert('query total is measurable', $result['db']['total_ms'] > 0.0, json_encode($result['db']));
attributionAssert('slowest list is capped at five', count($result['db']['slowest']) === 5, json_encode($result['db']['slowest']));

$descending = true;
for ($i = 1; $i < count($result['db']['slowest']); $i++) {
    if ($result['db']['slowest'][$i - 1]['ms'] < $result['db']['slowest'][$i]['ms']) {
        $descending = false;
        break;
    }
}
attributionAssert('slowest list is descending', $descending, json_encode($result['db']['slowest']));
attributionAssert(
    'stored SQL is bounded',
    array_reduce($result['db']['slowest'], static fn(bool $ok, array $row): bool => $ok && strlen($row['sql']) <= 200, true)
);

echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
