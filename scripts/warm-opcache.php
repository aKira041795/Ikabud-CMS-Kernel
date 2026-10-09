<?php
declare(strict_types=1);

/**
 * Ikabud — OPcache warm-up for a deployed install.
 *
 * WHY THIS EXISTS
 * ---------------
 * Measured on the live host 2026-10-09 (PHP 8.5.11, identical code either side):
 *
 *     cold opcache   request phase: dispatch   227.64 ms   (102 scripts compiled)
 *     warm opcache   request phase: dispatch    61.68 ms
 *
 * 3.7x — and it is not a PHP-version effect. In the same cold reading, every phase
 * that must compile or stat files inflated 4-9x (helpers load 8.4x, event flush
 * 9.1x, module discovery 6.7x) while every phase that runs on already-loaded data
 * was flat or faster (route match 0.89x, contract drift 0.69x, entity context
 * 0.75x). The upgrade to 8.5 restarted PHP, which empties OPcache, and the first
 * real visitor then waited for the framework to be recompiled from source.
 *
 * That is what every deploy costs, and on shared hosting the restart time is the
 * host's decision, not yours. Cache warmth is therefore the one large performance
 * input nobody schedules. This script schedules it: run it once after uploading,
 * and the restart is paid by the deploy instead of by a customer.
 *
 * WHY HTTP AND NOT CLI
 * --------------------
 * OPcache is per-SAPI. A CLI warm-up builds a second, separate opcache that the web
 * pool never reads — it compiles a great deal of code and warms nobody. These
 * requests are real HTTP so that the web pool does the compiling itself. OPcache
 * shared memory is shared by every worker in the pool, so a handful of requests
 * warms all of them, not just the worker that answered.
 *
 * WHY ONE PATH IS ALMOST ENOUGH
 * -----------------------------
 * boot + module discovery + registration is route-independent and is the whole of
 * the 227.64 ms above, so a single path warms it. Only module handler files are
 * per-route, so --deep additionally probes /api/v1/<module>/health for the modules
 * that name that route in their own routes.php — one framework boot each.
 *
 * RUN IT ON THE SERVER IF YOU CAN
 * -------------------------------
 * The signal is a latency difference of ~165 ms. Run from a laptop over a WAN and
 * every sample carries network RTT on top, diluting it. From the server itself
 * (cPanel Terminal or SSH) RTT is ~0 and the trend is unambiguous.
 *
 * USAGE
 *   php scripts/warm-opcache.php --base=https://tenant.example.com
 *   php scripts/warm-opcache.php --base=https://tenant.example.com --deep
 *   php scripts/warm-opcache.php --base=... --rounds=5 --timeout=60 --json
 *
 * EXIT CODES
 *   0  ran and the server answered. A cache that was ALREADY warm is a PASS, not a
 *      failure: "nothing to warm" is the steady state we want, and a tool that
 *      reports failure on a healthy deploy is a tool everyone learns to ignore.
 *   1  every request failed (DNS, TLS, refused) — the deploy is not reachable
 *   2  bad usage
 */

const WARM_UA = 'ikabud-warm-opcache/1.0 (+deploy warm-up)';

// Only execute when invoked directly. A test can `require` this file to get the
// classification functions without deploying anything or warming anything, which
// is the only way to exercise the "already warm" and "warmed" branches on a host
// whose cache is already in one of those states.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string)$argv[0]) !== realpath(__FILE__)) {
    return;
}

$options = [
    'base' => '',
    'deep' => false,
    'rounds' => 3,
    'timeout' => 30.0,
    'insecure' => false,
    'json' => false,
];

$cliArgs = array_slice($argv, 1);
for ($i = 0; $i < count($cliArgs); $i++) {
    $arg = $cliArgs[$i];
    $next = static fn(string $name): ?string => ($cliArgs[$i + 1] ?? null) !== null && !str_starts_with((string)$cliArgs[$i + 1], '--')
        ? (string)$cliArgs[++$i]
        : null;

    if ($arg === '--help' || $arg === '-h') {
        warmUsage();
        exit(0);
    }
    if ($arg === '--deep') {
        $options['deep'] = true;
        continue;
    }
    if ($arg === '--json') {
        $options['json'] = true;
        continue;
    }
    if ($arg === '--insecure') {
        $options['insecure'] = true;
        continue;
    }
    if (str_starts_with($arg, '--base=')) {
        $options['base'] = substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, '--rounds=')) {
        $options['rounds'] = max(1, (int)substr($arg, 9));
        continue;
    }
    if (str_starts_with($arg, '--timeout=')) {
        $options['timeout'] = max(1.0, (float)substr($arg, 10));
        continue;
    }
    if ($arg === '--base') {
        $options['base'] = (string)($next('base') ?? '');
        continue;
    }
    if ($arg === '--rounds') {
        $options['rounds'] = max(1, (int)($next('rounds') ?? '1'));
        continue;
    }
    if ($arg === '--timeout') {
        $options['timeout'] = max(1.0, (float)($next('timeout') ?? '30'));
        continue;
    }
    if ($arg !== '' && $arg[0] !== '-') {
        $options['base'] = $arg;
        continue;
    }

    fwrite(STDERR, "unknown option: {$arg}\n\n");
    warmUsage();
    exit(2);
}

$base = rtrim(trim($options['base']), '/');
if ($base === '' || !preg_match('#^https?://#i', $base)) {
    fwrite(STDERR, "A base URL is required, e.g. --base=https://tenant.example.com\n\n");
    warmUsage();
    exit(2);
}

$root = dirname(__DIR__);
$paths = ['/'];

if ($options['deep']) {
    $deepPaths = warmDeepPaths($root);
    printf(
        "  deep: probing %d health route(s) that a module declares in its own routes.php\n",
        count($deepPaths)
    );
    foreach ($deepPaths as $path) {
        $paths[] = $path;
    }
}

// ── Warm the framework path, then (if asked) each module handler ────────
$samples = [];

for ($round = 1; $round <= $options['rounds']; $round++) {
    foreach ($paths as $index => $path) {
        // Deep paths are per-route one-offs; only re-request them on round 1.
        if ($index > 0 && $round > 1) {
            continue;
        }
        $samples[] = warmRequest($base . $path, $path, $round, (float)$options['timeout'], (bool)$options['insecure']);
    }
}

$reached = 0;
foreach ($samples as $sample) {
    if ($sample['status'] !== null) {
        $reached++;
    }
}

if ($reached === 0) {
    warmReportFailure($base, $samples, (bool)$options['json']);
    exit(1);
}

// ── Verdict: compare the cold first hit with the settled tail ───────────
$verdict = warmVerdict($samples);
$first = $verdict['first'];
$settled = $verdict['settled_ms'];
$delta = $verdict['saved_ms'];
$ratio = $verdict['ratio'];
$alreadyWarm = $verdict['already_warm'];

if ($options['json']) {
    echo json_encode([
        'base' => $base,
        'verdict' => $alreadyWarm ? 'ALREADY_WARM' : 'WARMED',
        'first_ms' => $first !== null ? round((float)$first['ms'], 2) : null,
        'settled_ms' => round($settled, 2),
        'saved_ms' => round($delta, 2),
        'ratio' => round($ratio, 3),
        'requests' => count($samples),
        'unreachable' => count($samples) - $reached,
        'non_2xx' => warmNon2xx($samples),
        'samples' => $samples,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

warmReport($base, $samples, $first, $settled, $delta, $ratio, $alreadyWarm);
exit(0);

// ───────────────────────────────────────────────────────────────────────

/**
 * One HTTP GET, timed end to end.
 *
 * Returns status null when the request never produced a response, so "the server
 * said 404" stays distinguishable from "the server said nothing" — collapsing
 * those into one failure is how a dead deploy looks healthy.
 */
function warmRequest(string $url, string $path, int $round, float $timeout, bool $insecure): array
{
    $sample = [
        'round' => $round,
        'path' => $path,
        'url' => $url,
        'ms' => 0.0,
        'status' => null,
        'bytes' => 0,
        'error' => null,
    ];

    $startedAt = hrtime(true);

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => (int)ceil(min($timeout, 10.0)),
            CURLOPT_FOLLOWLOCATION => false, // a redirect target is not what we measured
            CURLOPT_USERAGENT => WARM_UA,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        if ($errno !== 0) {
            $sample['error'] = curl_error($handle) ?: ('curl error ' . $errno);
        }
        curl_close($handle);
        $sample['ms'] = (hrtime(true) - $startedAt) / 1_000_000;
        if (is_string($body)) {
            $sample['bytes'] = strlen($body);
        }
        if ($status !== null && (int)$status > 0) {
            $sample['status'] = (int)$status;
            $sample['error'] = null;
        }
        return $sample;
    }

    // Some shared hosts ship PHP without curl; allow_url_fopen is the other path to
    // the same result, and a warm-up that cannot run on the host it was written for
    // is worth very little.
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true, // 404 must still return headers + body
            'follow_location' => 0,
            'header' => 'User-Agent: ' . WARM_UA . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => !$insecure,
            'verify_peer_name' => !$insecure,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $sample['ms'] = (hrtime(true) - $startedAt) / 1_000_000;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            $sample['status'] = (int)$matches[1];
        }
    }
    if (is_string($body)) {
        $sample['bytes'] = strlen($body);
    }
    if ($sample['status'] === null) {
        $sample['error'] = 'no HTTP response';
    }

    return $sample;
}

/**
 * Module id => module directory, read from module.json rather than folder names.
 *
 * Two layouts exist (modules/<id>/ and grouped modules/<group>/<id>/), and the
 * manifest's own id is authoritative in both. No application bootstrap is needed:
 * this only decides which paths to probe.
 */
function warmModuleManifests(string $root): array
{
    $manifests = [];
    $patterns = [
        $root . '/modules/*/module.json',
        $root . '/modules/*/*/module.json',
    ];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $manifest) {
            $decoded = json_decode((string)@file_get_contents($manifest), true);
            $id = is_array($decoded) ? trim((string)($decoded['id'] ?? '')) : '';
            if ($id === '') {
                $id = basename(dirname($manifest));
            }
            if ($id !== '' && preg_match('#^[a-z0-9][a-z0-9-]*$#', $id) === 1) {
                $manifests[$id] = dirname($manifest);
            }
        }
    }

    ksort($manifests);

    return $manifests;
}

function warmModuleIds(string $root): array
{
    return array_keys(warmModuleManifests($root));
}

/**
 * The paths worth probing beyond the framework path.
 *
 * Probing /api/v1/<id>/health for every module issued 69 404s against a real
 * install, because a health route is a per-module convention and not a kernel
 * route. The fix is a single accurate check: the module must name that exact path
 * in its own routes.php. That is proof the route exists, so the request is never a
 * guess.
 *
 * An earlier version of this also filtered on "enabled" by reading
 * storage/modules.json. That file is NOT an enablement registry — on this install
 * it is 545 bytes of settings overrides with no "enabled": true entry anywhere — so
 * the filter silently classified every module as disabled and probed nothing. A
 * filter that cannot be verified from the file it reads is worse than no filter;
 * the remaining 404s below are reported instead of guessed at.
 */
function warmDeepPaths(string $root): array
{
    $paths = [];

    foreach (warmModuleManifests($root) as $id => $dir) {
        $healthPath = '/api/v1/' . $id . '/health';
        $routesFile = $dir . '/routes.php';

        if (!is_file($routesFile) || !str_contains((string)@file_get_contents($routesFile), $healthPath)) {
            continue;
        }
        $paths[] = $healthPath;
    }

    sort($paths);

    return $paths;
}

/**
 * Classify a run from its framework samples.
 *
 * Kept free of I/O so both the "warmed" and the "already warm" branches are
 * testable without a cold server — a classifier that can only ever report one of
 * its two outcomes is not evidence of anything.
 *
 * The 15% band is the load-bearing part. Below it, the difference between the
 * first request and the settled ones is request-to-request noise on a quiet host,
 * and reporting "WARMED" would be presenting noise as a result. A single sample
 * therefore reports ALREADY_WARM, which is the honest reading: one request cannot
 * demonstrate that later ones are cheaper.
 */
function warmVerdict(array $samples): array
{
    $framework = array_values(array_filter(
        $samples,
        static fn(array $s): bool => ($s['path'] ?? '') === '/'
    ));

    if ($framework === []) {
        // No framework samples: nothing to compare, so nothing is claimed.
        return ['first' => null, 'settled_ms' => 0.0, 'saved_ms' => 0.0, 'ratio' => 1.0, 'already_warm' => true];
    }

    $first = $framework[0];
    $tail = array_slice($framework, max(1, intdiv(count($framework), 2)));
    $settled = warmMedian(array_map(
        static fn(array $s): float => (float)($s['ms'] ?? 0.0),
        $tail !== [] ? $tail : $framework
    ));
    $firstMs = (float)($first['ms'] ?? 0.0);
    $ratio = $firstMs > 0.0 ? $settled / $firstMs : 1.0;

    return [
        'first' => $first,
        'settled_ms' => $settled,
        'saved_ms' => $firstMs - $settled,
        'ratio' => $ratio,
        'already_warm' => $ratio >= 0.85,
    ];
}

function warmMedian(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? (float)$values[$middle]
        : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
}

/** Paths that did not answer 2xx — a 404 still warms the framework, but should be seen. */
function warmNon2xx(array $samples): array
{
    $out = [];
    foreach ($samples as $sample) {
        $status = $sample['status'];
        if ($status !== null && ($status < 200 || $status >= 300)) {
            $out[$sample['path']] = $status;
        }
    }

    return $out;
}

function warmReport(
    string $base,
    array $samples,
    ?array $first,
    float $settled,
    float $delta,
    float $ratio,
    bool $alreadyWarm
): void {
    echo "OPcache warm-up — {$base}\n";
    echo str_repeat('─', 66) . "\n";

    $rows = 0;
    foreach ($samples as $sample) {
        if ($sample['path'] !== '/') {
            continue;
        }
        $rows++;
        printf(
            "  round %-2d  %8.2f ms  HTTP %-4s  %s\n",
            $sample['round'],
            $sample['ms'],
            $sample['status'] ?? '---',
            $sample['error'] ?? ''
        );
    }
    if ($rows === 0) {
        foreach ($samples as $sample) {
            printf("  %-42s %8.2f ms  HTTP %s\n", $sample['path'], $sample['ms'], $sample['status'] ?? '---');
        }
    }

    echo str_repeat('─', 66) . "\n";

    if ($first !== null) {
        printf("  first request (cold) : %8.2f ms\n", (float)$first['ms']);
        printf("  settled              : %8.2f ms\n", $settled);
        printf("  saved                : %8.2f ms  (%.2fx of cold)\n", $delta, $ratio);
    }

    if ($alreadyWarm) {
        echo "\n  VERDICT: ALREADY WARM — nothing to do. This is the healthy steady\n";
        echo "           state, not a failure: the cache was warm before this ran.\n";
    } else {
        printf("\n  VERDICT: WARMED — the framework is now compiled in OPcache for the\n");
        printf("           whole worker pool; users no longer pay the %.0f ms cold start.\n", $delta);
    }

    $non2xx = warmNon2xx($samples);
    if ($non2xx !== []) {
        echo "\n  NOTE: these paths did not answer 2xx:\n";
        foreach ($non2xx as $path => $status) {
            printf("        HTTP %-4s %s\n", $status, $path);
        }
        echo "        A 404 still boots and warms the framework, so the warm-up is not\n";
        echo "        wasted — but the path itself was not the one you expected.\n";
    }

    echo "\n  Reminder: this measurement ran from here. Over a WAN, network RTT is\n";
    echo "  included in every figure above; run it on the server for the clean signal.\n";
}

function warmReportFailure(string $base, array $samples, bool $json): void
{
    if ($json) {
        echo json_encode([
            'base' => $base,
            'verdict' => 'UNREACHABLE',
            'samples' => $samples,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo "OPcache warm-up — {$base}\n";
    echo str_repeat('─', 66) . "\n";
    echo "  No request produced an HTTP response. Nothing was warmed.\n\n";
    foreach ($samples as $sample) {
        printf("  %-42s %s\n", $sample['path'], $sample['error'] ?? 'no response');
    }
    echo "\n  Check the URL, DNS and TLS before re-running. The site is not reachable\n";
    echo "  from here, which is a deploy problem, not a cache problem.\n";
}

function warmUsage(): void
{
    echo <<<TXT
    Ikabud — OPcache warm-up after deploy

      php scripts/warm-opcache.php --base=https://tenant.example.com [options]

      --base=URL      Site to warm (required). Warmed per site: OPcache belongs to
                      the PHP pool, so a differently-pooled vhost is a separate run.
      --rounds=N      Framework rounds. Default 3. Only round 1 compiles; the rest
                      show the settled cost, which is how "already warm" is told
                      apart from "warming did nothing". Max 1 is valid but then the
                      verdict cannot be measured.
      --deep          Also probe /api/v1/<module>/health, but only for modules that
                      name that exact path in their own routes.php. Warms module
                      handler files, at one framework boot each. Most modules have
                      no health route (16 of 70 on one real install), so this is a
                      short pass, not a sweep. A module that is installed but
                      disabled still answers 404, and the run reports that.
      --timeout=SEC   Per-request timeout. Default 30.
      --insecure      Skip TLS verification (staging with a self-signed cert only).
      --json          Machine-readable output.
      -h, --help      This text.

    Run it as the last deploy step, after the files are in place. Restarting PHP
    empties OPcache, so a deploy that regenerates a pool (or an upgrade, or a host
    restart) is exactly when this earns its place.

    TXT;
}
