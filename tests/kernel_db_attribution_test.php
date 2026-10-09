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
