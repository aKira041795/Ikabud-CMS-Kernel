<?php
declare(strict_types=1);

/**
 * Verdict classification for scripts/warm-opcache.php.
 *
 * Deliberately does NOT bootstrap the app: the script under test is a standalone
 * HTTP client with no kernel dependency, so booting the framework here would cost
 * seconds and prove nothing about it.
 *
 * The two outcomes are both asserted because both matter operationally. A warm-up
 * tool that cannot report "already warm" cries wolf on every healthy deploy, and a
 * tool that cannot report "warmed" is decoration. The threshold is then tested in
 * both directions, since a boundary that only ever returns one answer is not a
 * boundary — it is a constant with a comparison in front of it.
 */

$scriptPath = __DIR__ . '/../scripts/warm-opcache.php';
$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function warmAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

/** Framework samples shaped exactly as warmRequest() produces them. */
function warmSamples(array $milliseconds): array
{
    $samples = [];
    foreach ($milliseconds as $ms) {
        $samples[] = ['round' => 1, 'path' => '/', 'ms' => (float)$ms, 'status' => 200, 'bytes' => 1, 'error' => null];
    }

    return $samples;
}

// ── The guard that makes the rest of this file possible ─────────────────
ob_start();
require $scriptPath;
$incidentalOutput = (string)ob_get_clean();

warmAssert('requiring the script executes nothing', $incidentalOutput === '', 'output: ' . substr($incidentalOutput, 0, 100));
warmAssert('classification functions are available after include', function_exists('warmVerdict') && function_exists('warmMedian') && function_exists('warmNon2xx') && function_exists('warmModuleIds'));

// ── The outcome a deploy exists to produce ──────────────────────────────
$warmed = warmVerdict(warmSamples([227.64, 65.0, 62.0, 61.0]));
warmAssert('cold start then settled is reported as WARMED', $warmed['already_warm'] === false);
warmAssert('WARMED keeps the first request as the cold cost', abs((float)$warmed['first']['ms'] - 227.64) < 0.001);
warmAssert('WARMED quantifies what the pool no longer pays', abs($warmed['saved_ms'] - 166.14) < 0.01, 'saved=' . round($warmed['saved_ms'], 2) . ' ms');
warmAssert('WARMED ratio reflects the settled cost', abs($warmed['ratio'] - 0.270) < 0.001, 'ratio=' . round($warmed['ratio'], 3));

// ── The steady state must not read as a failure ─────────────────────────
$steady = warmVerdict(warmSamples([390.78, 377.38, 350.47]));
warmAssert('a cache that was already warm reports ALREADY_WARM', $steady['already_warm'] === true);

// ── One request cannot demonstrate that later ones are cheaper ──────────
$single = warmVerdict(warmSamples([300.0]));
warmAssert('a single sample claims nothing', $single['already_warm'] === true && $single['saved_ms'] === 0.0);

// ── Threshold, both directions ─────────────────────────────────────────
$justAbove = warmVerdict(warmSamples([100.0, 85.0001]));
warmAssert('a settlement just above the band is ALREADY_WARM', $justAbove['already_warm'] === true, 'ratio=' . round($justAbove['ratio'], 6));
$justBelow = warmVerdict(warmSamples([100.0, 84.9999]));
warmAssert('a settlement just below the band is WARMED', $justBelow['already_warm'] === false, 'ratio=' . round($justBelow['ratio'], 6));

// ── Degenerate input must not divide by zero or overclaim ───────────────
$noFramework = warmVerdict([['path' => '/api/v1/bakeshop/health', 'ms' => 80.0, 'status' => 200]]);
warmAssert('with no framework samples, nothing is claimed', $noFramework['first'] === null && $noFramework['already_warm'] === true);

$zero = warmVerdict(warmSamples([0.0, 0.0]));
warmAssert('a zero-ms first sample does not divide by zero', is_finite((float)$zero['ratio']) && $zero['already_warm'] === true);

$empty = warmVerdict([]);
warmAssert('empty input is not a crash', $empty['first'] === null && $empty['settled_ms'] === 0.0);

// ── Median helper ──────────────────────────────────────────────────────
warmAssert('median is order-independent for an odd count', warmMedian([30.0, 10.0, 20.0]) === 20.0);
warmAssert('median averages the middle pair for an even count', warmMedian([40.0, 10.0, 30.0, 20.0]) === 25.0);

// ── Path reporting: a 404 must be visible, an unreachable must not hide ─
$non2xx = warmNon2xx([
    ['path' => '/', 'status' => 200],
    ['path' => '/api/v1/x/health', 'status' => 404],
    ['path' => '/api/v1/y/health', 'status' => null],
]);
warmAssert(
    'non-2xx paths are surfaced; unreachable ones are not misfiled as 0',
    $non2xx === ['/api/v1/x/health' => 404],
    json_encode($non2xx)
);

// ── Module id discovery, run against the real repository ────────────────
$ids = warmModuleIds($root);
warmAssert('module ids are discovered from manifests', count($ids) > 50, count($ids) . ' ids found');
warmAssert('every discovered id is slug-shaped', $ids === array_values(array_filter(
    $ids,
    static fn(string $id): bool => preg_match('#^[a-z0-9][a-z0-9-]*$#', $id) === 1
)));
warmAssert('ids are unique and sorted', $ids === array_values(array_unique($ids)) && $root !== null && (function (array $list): bool {
    $sorted = $list;
    sort($sorted);

    return $list === $sorted;
})($ids));

// Grouped layouts must resolve to the manifest's own id, not the folder name.
warmAssert(
    'grouped module layouts are discovered too',
    in_array('cms-akira-core', $ids, true),
    'cms-akira-core present: ' . var_export(in_array('cms-akira-core', $ids, true), true)
);

// ── Deep paths must be declared, never assumed ──────────────────────────
$deepPaths = warmDeepPaths($root);
warmAssert(
    'a health route is probed only when a module names it in its own routes.php',
    $deepPaths !== [] && count($deepPaths) < count($ids),
    count($deepPaths) . ' probed of ' . count($ids) . ' modules'
);
warmAssert(
    'every deep path is a well-formed health route',
    $deepPaths === array_values(array_filter(
        $deepPaths,
        static fn(string $p): bool => preg_match('#^/api/v1/[a-z0-9-]+/health$#', $p) === 1
    )),
    json_encode(array_slice($deepPaths, 0, 3))
);
warmAssert(
    'deep paths are unique and sorted',
    $deepPaths === array_values(array_unique($deepPaths))
        && (function (array $list): bool {
            $sorted = $list;
            sort($sorted);

            return $list === $sorted;
        })($deepPaths)
);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
