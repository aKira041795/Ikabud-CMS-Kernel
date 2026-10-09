<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-routes.php';
require_once __DIR__ . '/../src/http/core-routes.php';

$pass = 0;
$fail = 0;

function routeConflictAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$routes = loadModuleRoutes(kernelCoreRoutes());
$routeCount = array_sum(array_map('count', $routes));
$sameMethodPairs = 0;
$cheapAdmitted = 0;
$trueConflicts = 0;
$falseNegatives = [];

$cheapStarted = hrtime(true);
foreach ($routes as $method => $methodRoutes) {
    $patterns = array_keys($methodRoutes);
    $count = count($patterns);
    $sameMethodPairs += intdiv($count * ($count - 1), 2);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (routePatternsCouldConflictCheap($patterns[$i], $patterns[$j])) {
                $cheapAdmitted++;
            }
        }
    }
}
$cheapElapsedMs = (hrtime(true) - $cheapStarted) / 1_000_000;

$fullStarted = hrtime(true);
foreach ($routes as $method => $methodRoutes) {
    $patterns = array_keys($methodRoutes);
    $count = count($patterns);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $fullConflict = routePatternsMayConflict($patterns[$i], $patterns[$j]);
            if (!$fullConflict) {
                continue;
            }

            $trueConflicts++;
            if (!routePatternsCouldConflictCheap($patterns[$i], $patterns[$j])) {
                $falseNegatives[] = [$method, $patterns[$i], $patterns[$j]];
            }
        }
    }
}
$fullElapsedMs = (hrtime(true) - $fullStarted) / 1_000_000;

$syntheticConflictLeft = '/probe/{id}';
$syntheticConflictRight = '/probe/bar';
$syntheticFullConflict = routePatternsMayConflict($syntheticConflictLeft, $syntheticConflictRight);
$syntheticCheapConflict = routePatternsCouldConflictCheap($syntheticConflictLeft, $syntheticConflictRight);
$differentCountsRejected = !routePatternsCouldConflictCheap('/probe/{id}', '/probe/bar/detail');
$firstLiteralMismatchRejected = !routePatternsCouldConflictCheap('/alpha/x', '/beta/x');

routeConflictAssert('real route corpus contains 1,968 patterns', $routeCount === 1968, "routes={$routeCount}");
routeConflictAssert('all same-method pairs were enumerated', $sameMethodPairs === 927972, "pairs={$sameMethodPairs}");
routeConflictAssert('cheap predicate has no false negatives', $falseNegatives === [], 'false_negatives=' . count($falseNegatives));
routeConflictAssert('real corpus conflict count is non-vacuous', $trueConflicts > 0, "true_conflicts={$trueConflicts}");
routeConflictAssert('cheap predicate admits at most ten percent of baseline pairs', $cheapAdmitted <= 92797, "admitted={$cheapAdmitted}");
routeConflictAssert('genuine synthetic conflict is genuine and admitted', $syntheticFullConflict && $syntheticCheapConflict);
routeConflictAssert('different segment counts are rejected cheaply', $differentCountsRejected);
routeConflictAssert('first-position literal mismatch is rejected cheaply', $firstLiteralMismatchRejected);

echo sprintf(
    "PAIR_COUNTS baseline=%d admitted=%d reduction=%.2fx true_conflicts=%d false_negatives=%d\n",
    $sameMethodPairs,
    $cheapAdmitted,
    $cheapAdmitted > 0 ? $sameMethodPairs / $cheapAdmitted : INF,
    $trueConflicts,
    count($falseNegatives)
);
echo sprintf("TIMING cheap_ms=%.3f full_ms=%.3f\n", $cheapElapsedMs, $fullElapsedMs);
echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
