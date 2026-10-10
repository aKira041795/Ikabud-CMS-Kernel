<?php

declare(strict_types=1);

/**
 * Route precedence order cache — correctness before speed.
 *
 * The cache exists to skip a sort measured at 2.97 ms on the live host (route_match_sort), replaced
 * by an APCu fetch measured at 0.027 ms for a 51.7 KB entry on that same host.
 *
 * A speed test would be the wrong test. The thing that can go wrong here is a MISROUTE: a cached
 * order that is not the order the comparator would have produced. So this asserts order equivalence
 * and refuses-to-accept, not milliseconds.
 *
 * HONEST SCOPE — the CLI cannot reach the cache path. apc.enable_cli=0, so apcu_store/apcu_fetch are
 * no-ops here and routePatternsInMatchOrder() always takes the fallback. Rather than let that make
 * the test vacuous, the cache-hit path is proven by composition:
 *
 *   1. the order the fallback produces IS the comparator's order (asserted on the real corpus), and
 *   2. that exact order passes routeOrderCachedIsValid() (asserted), so a cache holding it is
 *      accepted and returned verbatim.
 *
 * Together those mean a hit returns the comparator's order. What remains unproven here is only that
 * APCu round-trips — which is what kernelPerfProbeApcu() measures on the live host, and what the
 * `APCu round trip` row on /superadmin/perf reported as working.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-routes.php';
require_once __DIR__ . '/../src/http/core-routes.php';

$pass = 0;
$fail = 0;

function orderCacheAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

$routes = loadModuleRoutes(kernelCoreRoutes());

// ── 1. Order equivalence on the real corpus ─────────────────────────────────
// The refactor must not change a single position. This is the whole contract.

$methodsChecked = 0;
$patternsChecked = 0;
$orderMismatches = [];
$hitPathOrderMismatches = 0;

foreach ($routes as $method => $methodRoutes) {
    if ($methodRoutes === []) {
        continue;
    }
    $methodsChecked++;

    // What the untouched comparator produces — the reference order.
    $reference = array_keys($methodRoutes);
    usort($reference, 'compareRoutePatternsForMatching');

    // What the production path produces (fallback here, since APCu is off in CLI).
    $actual = routePatternsInMatchOrder($methodRoutes, (string)$method);

    $patternsChecked += count($reference);

    if ($actual !== $reference) {
        $orderMismatches[] = (string)$method;
    }

    // ── 2. The hit path, by composition ─────────────────────────────────────
    // A cache holding the comparator's order must be ACCEPTED and returned unchanged. If this ever
    // returns false, the cache can never hit — the must-allow direction of the same guard.
    if (!routeOrderCachedIsValid($reference, $methodRoutes)) {
        $hitPathOrderMismatches++;
    }
}

orderCacheAssert(
    'production path returns the comparator order for every method',
    $orderMismatches === [],
    $orderMismatches === [] ? "{$methodsChecked} methods, {$patternsChecked} patterns" : 'differed: ' . implode(', ', $orderMismatches)
);
orderCacheAssert(
    'the comparator order is accepted by the cache guard (must-allow)',
    $hitPathOrderMismatches === 0,
    'so a hit returns that order verbatim'
);

// ── 3. The guard must REFUSE ────────────────────────────────────────────────
// Every one of these, if accepted, is a misroute rather than a slowdown.
// Derived from the real corpus so the fixtures are not invented.

$getRoutes = $routes['GET'] ?? [];
$getPatterns = array_keys($getRoutes);

if (count($getPatterns) >= 2) {
    $a = $getPatterns[0];
    $b = $getPatterns[1];

    // The specific misroute the duplicate check exists for: [A, A] has the right length for a map of
    // {A, B} and every element is a member, so a length+membership-only check would accept it and B
    // would never be scanned.
    $twoRouteMap = [$a => 'handlerA', $b => 'handlerB'];
    orderCacheAssert(
        'refuses a duplicate entry that matches the length (would drop a route)',
        routeOrderCachedIsValid([$a, $a], $twoRouteMap) === false,
        '[A,A] against a map of {A,B}'
    );

    orderCacheAssert(
        'refuses a shorter list than the route map (would drop routes)',
        routeOrderCachedIsValid([$a], $getRoutes) === false
    );

    orderCacheAssert(
        'refuses a longer list than the route map (would scan unknown routes)',
        routeOrderCachedIsValid(array_merge($getPatterns, ['/__not_a_route__']), $getRoutes) === false
    );

    orderCacheAssert(
        'refuses a list containing a pattern the map does not have',
        routeOrderCachedIsValid(array_merge(array_slice($getPatterns, 1), ['/__not_a_route__']), $getRoutes) === false
    );

    orderCacheAssert(
        'refuses a non-string element',
        routeOrderCachedIsValid(array_merge(array_slice($getPatterns, 1), [12345]), $getRoutes) === false
    );

    orderCacheAssert(
        'refuses a non-array entry',
        routeOrderCachedIsValid('not-an-array', $getRoutes) === false
    );

    orderCacheAssert(
        'refuses null (the shape of an apcu miss)',
        routeOrderCachedIsValid(null, $getRoutes) === false
    );

    // ── 4. The key must both discriminate and be stable ─────────────────────
    // Discriminate: a changed input must be a different key, or a stale order is served.
    // Stable: the same input must be the same key, or the cache never hits.

    $stamp = 1700000000;
    $baseKey = routeOrderCacheKey($getRoutes, 'GET', $stamp);

    orderCacheAssert(
        'key is stable for the same input (must-allow)',
        routeOrderCacheKey($getRoutes, 'GET', $stamp) === $baseKey
    );

    orderCacheAssert(
        'key changes when the comparator source changes',
        routeOrderCacheKey($getRoutes, 'GET', $stamp + 1) !== $baseKey
    );

    orderCacheAssert(
        'key changes when the route map changes',
        routeOrderCacheKey(array_merge($getRoutes, ['/__extra__' => 'h']), 'GET', $stamp) !== $baseKey
    );

    orderCacheAssert(
        'key changes when the method changes',
        routeOrderCacheKey($getRoutes, 'POST', $stamp) !== $baseKey
    );

    // Input ORDER is part of the key, because array_keys() order is what the comparator is handed.
    // Reversing it is a different input even though the set is equal, so it must not share a key.
    $reversed = [];
    foreach (array_reverse($getRoutes) as $pattern => $handler) {
        $reversed[$pattern] = $handler;
    }
    orderCacheAssert(
        'key distinguishes a different input order',
        routeOrderCacheKey($reversed, 'GET', $stamp) !== $baseKey
    );
}

// A single-route method must bypass the cache entirely rather than store something meaningless.
$solo = routePatternsInMatchOrder(['/only' => 'h'], 'GET');
orderCacheAssert('a single route needs no sort and no cache entry', $solo === ['/only']);

// ── 5. State the scope limit rather than implying coverage ──────────────────
$apcuInCli = function_exists('apcu_enabled') && apcu_enabled();
echo PHP_EOL . 'NOTE: apcu_enabled() in this SAPI = ' . ($apcuInCli ? 'true' : 'false') . '. '
    . ($apcuInCli
        ? "The cache path was exercised directly by this run."
        : "The cache path was NOT exercised here (apc.enable_cli=0); the hit path is proven above by\n"
            . "      composition, and APCu round-tripping is measured by kernelPerfProbeApcu() on the host.")
    . PHP_EOL;

echo 'Total: ' . ($pass + $fail) . ' PASS: ' . $pass . ' FAIL: ' . $fail . PHP_EOL;
exit($fail === 0 ? 0 : 1);
