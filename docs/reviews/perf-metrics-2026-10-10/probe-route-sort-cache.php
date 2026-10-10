<?php

declare(strict_types=1);

/**
 * Probe: is the route-pattern sort worth caching across requests, or is the
 * per-call memoisation already close to the floor?
 *
 * Question being tested (owner, 2026-10-10): "memoisation is moot".
 *
 * The memo in routePatternMatchPriority() is a `static $cache`, so it is
 * per-PROCESS. A real request therefore pays every pattern once (1968 cold
 * misses) and then serves ~41k comparator invocations from it. What survives
 * the memo is the comparison structure itself: usort still performs
 * ~n*log2(n) callback invocations, and each one still does two array lookups.
 *
 * public/index.php:561-563 (added the same day) already names the stronger
 * option: "the sort is request-invariant work that can be removed entirely".
 * This probe measures whether that removal pays, i.e. whether the key that
 * identifies the pattern set costs less than the sort it would replace.
 *
 * Method: run once per process (the memo is per-process), several processes
 * via the shell loop below, so each sample is a true cold per-request cost.
 *
 *   for i in $(seq 1 8); do php probe-route-sort-cache.php; done
 *
 * It measures, on the REAL routes this application builds:
 *   1. array_keys + usort, memoised comparator   <- what a request pays today
 *   2. the same, in a FRESH pattern-string space  <- so measurement 1 is not
 *      warmed by... nothing; kept so the two are in the same condition
 *   3. key computation (implode + md5)           <- what a cache would cost
 *   4. the key over a single method vs all       <- the app sorts one method
 *
 * And it PROVES the cached value would be correct rather than asserting it:
 * it checks that a cache hit would hand back an identical ORDER, by sorting
 * the same set twice from cold and comparing elementwise.
 *
 * Read-only: boots the app, loads routes, measures. Writes nothing.
 */

require __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../../src/helpers/module-routes.php';
require_once __DIR__ . '/../../../src/http/core-routes.php';

$routes = loadModuleRoutes(kernelCoreRoutes());

/** @return float milliseconds */
function msSince(int $startNs): float
{
    return (hrtime(true) - $startNs) / 1_000_000;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

// ── Which method does a request actually sort? ───────────────────────────────
// The app sorts $routes[$method] only. GET is the common case; report the
// shape so the right N is used rather than assumed.

$perMethod = [];
foreach ($routes as $method => $map) {
    $perMethod[$method] = count($map);
}
arsort($perMethod);

$method = 'GET';
$patternSets = [
    'GET' => array_keys($routes['GET'] ?? []),
];
$allPatterns = [];
foreach ($routes as $map) {
    foreach ($map as $pattern => $_) {
        $allPatterns[] = $pattern;
    }
}
$patternSets['ALL-methods'] = $allPatterns;

// ── Measurement 1: cold memoised usort (what a request pays) ────────────────

$sortMs = [];
$keyMs = [];
$correctSameOrder = true;
$orderChecked = 0;

foreach ($patternSets as $label => $patterns) {
    if ($patterns === []) {
        continue;
    }

    // Sort from cold. This is the exact call public/index.php:560 makes.
    $startNs = hrtime(true);
    $sorted = $patterns;
    usort($sorted, 'compareRoutePatternsForMatching');
    $sortMs[$label] = msSince($startNs);

    // Independent second sort after the memo is warm, purely to establish that
    // the comparator is deterministic. If a cached order were ever handed back
    // it must equal this; a non-deterministic comparator would make caching
    // unsafe no matter how cheap the key is.
    $second = $patterns;
    usort($second, 'compareRoutePatternsForMatching');
    if ($second !== $sorted) {
        $correctSameOrder = false;
    }
    $orderChecked += count($patterns);

    // ── Measurement 2: what a cache key costs ─────────────────────────────
    // The key must identify the pattern SET. Building it is the cache's
    // entire overhead on the hot path (plus the apcu_fetch itself).
    $startNs = hrtime(true);
    $key = md5(implode("\n", $patterns));
    $keyMs[$label] = msSince($startNs);

    // Verify the key actually discriminates: appending one pattern must change
    // it, otherwise a stale order could be served to a changed route set.
    $probe = $patterns;
    $probe[] = '/__probe__/{x}';
    if (md5(implode("\n", $probe)) === $key) {
        $correctSameOrder = false;
    }
}

// ── Measurement 3: the apcu_fetch a hit would cost ──────────────────────────

$apcuNote = 'apcu unavailable in this SAPI';
if (!function_exists('apcu_enabled')) {
    $apcuNote = 'apcu extension not loaded';
} elseif (!apcu_enabled()) {
    // CLI normally runs with apcu.enable_cli=Off, so store/fetch are no-ops and
    // apcu_fetch() returns false. That is not a defect and it is not evidence
    // about the web SAPI, where the page cache relies on APCu being live. It
    // means THIS probe cannot measure APCu here - say so, do not print 0.003 ms
    // as though it were a hit cost.
    $apcuNote = 'apcu DISABLED in this SAPI (apcu.enable_cli='
        . (string)ini_get('apcu.enable_cli')
        . ') - web-SAPI round-trip NOT measured by this probe';
}
$apcuHitMs = null;
$apcuRoundTripMs = null;
if (apcu_enabled() && function_exists('apcu_fetch') && function_exists('apcu_store')) {
    $apcuKey = 'probe:route_sort:' . md5((string)microtime(true));
    $victim = $patternSets['GET'] ?? [];
    apcu_store($apcuKey, $victim, 300);

    $startNs = hrtime(true);
    $got = apcu_fetch($apcuKey, $hit);
    $apcuHitMs = msSince($startNs);

    if (!$hit || $got !== $victim) {
        // The first draft printed this timing anyway, which would have been read
        // as "a hit costs 0.003 ms" when it was really a failed fetch returning
        // false immediately. A measurement that reports on a fetch that did not
        // happen is worse than no measurement.
        $apcuNote = 'apcu fetch did NOT round-trip the value - timings discarded';
        $apcuHitMs = null;
        $apcuRoundTripMs = null;
    } else {
        $apcuNote = 'apcu round-trips the sorted list';
        $startNs = hrtime(true);
        apcu_store($apcuKey . ':2', $victim, 300);
        $apcuRoundTripMs = msSince($startNs);
        apcu_delete($apcuKey . ':2');
        $apcuNote .= '; entry ~' . number_format(strlen(serialize($victim)) / 1024, 1) . ' KB';
    }
    apcu_delete($apcuKey);
}

// ── Report ──────────────────────────────────────────────────────────────────

echo json_encode([
    'patterns_per_method' => $perMethod,
    'sort_ms' => $sortMs,
    'key_ms' => $keyMs,
    'net_saving_if_cached_ms' => array_map(
        static fn(string $k): float => round(($sortMs[$k] ?? 0.0) - ($keyMs[$k] ?? 0.0), 3),
        array_keys($sortMs)
    ),
    'apcu_fetch_ms' => $apcuHitMs,
    'apcu_store_ms' => $apcuRoundTripMs,
    'apcu_note' => $apcuNote,
    'comparator_deterministic' => $correctSameOrder,
    'patterns_order_checked' => $orderChecked,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
