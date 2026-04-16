<?php

declare(strict_types=1);

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  HTTP Load Test — Concurrent Users Simulation
 * ──────────────────────────────────────────────────────────────────────────
 *
 *  Uses curl_multi to fire real HTTP requests in parallel, measuring:
 *    - Throughput (requests/sec)
 *    - Latency (p50, p95, p99, max)
 *    - Error rate
 *    - Concurrent connection handling
 *
 *  Profiles:
 *    storefront  — public shop/product/blog pages  (GET)
 *    api         — REST API product list + detail   (GET)
 *    mixed       — interleaved storefront + API     (GET)
 *    checkout    — shopping journey: shop → product → blog → cart (GET, sequential)
 *
 *  Usage:
 *    php tests/load_test.php                  # all profiles, default concurrency
 *    php tests/load_test.php storefront 20    # storefront only, 20 concurrent
 *    php tests/load_test.php api 50           # API only, 50 concurrent
 *
 * ══════════════════════════════════════════════════════════════════════════
 */

$BASE_URL = getenv('LOAD_TEST_BASE_URL') ?: 'http://cmsnew.test';

/**
 * Comma-separated tenant hostnames to simulate in round-robin mode.
 * Example: LOAD_TEST_TENANT_HOSTS="tenant-a.test,tenant-b.test,tenant-c.test"
 */
$rawTenantHosts = trim((string)(getenv('LOAD_TEST_TENANT_HOSTS') ?: ''));
$tenantHosts = [];
if ($rawTenantHosts !== '') {
    foreach (explode(',', $rawTenantHosts) as $tenantHost) {
        $tenantHost = trim($tenantHost);
        if ($tenantHost !== '') {
            $tenantHosts[] = $tenantHost;
        }
    }
}
if (empty($tenantHosts)) {
    $baseHost = (string)(parse_url($BASE_URL, PHP_URL_HOST) ?? '');
    if ($baseHost !== '') {
        $tenantHosts[] = $baseHost;
    }
}

$selectedProfile  = $argv[1] ?? 'all';
$concurrency      = max(1, min(200, (int)($argv[2] ?? 10)));
$requestsPerBatch = max($concurrency, (int)($argv[3] ?? 100));

echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║            HTTP LOAD TEST                            ║\n";
echo "╠══════════════════════════════════════════════════════╣\n";
echo "║  Base URL:     {$BASE_URL}\n";
echo "║  Concurrency:  {$concurrency}\n";
echo "║  Requests:     {$requestsPerBatch} per profile\n";
echo "║  Tenants:      " . count($tenantHosts) . " (" . implode(', ', $tenantHosts) . ")\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ── Connectivity check ───────────────────────────────────────────────────

$ch = curl_init("{$BASE_URL}/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
$ok = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 400) {
    echo "  ✗ Cannot reach {$BASE_URL}/ (HTTP {$httpCode}). Set LOAD_TEST_BASE_URL if needed.\n\n";
    exit(1);
}
echo "  ✓ Connected to {$BASE_URL} (HTTP {$httpCode})\n\n";


// ═════════════════════════════════════════════════════════════════════════
//  CORE: curl_multi batch executor
// ═════════════════════════════════════════════════════════════════════════

/**
 * Fire $requests in parallel with $concurrency slots.
 *
 * Each request: ['method'=>'GET'|'POST', 'url'=>string, 'body'=>string|null, 'headers'=>[]]
 * Returns:      ['results'=>[...], 'wall_time'=>float]
 *   each result: ['url'=>, 'status'=>int, 'time'=>float(sec), 'size'=>int, 'error'=>string|null]
 */
function loadTestBatch(array $requests, int $concurrency): array
{
    $mh = curl_multi_init();
    $results    = [];
    $handles    = [];
    $queue      = $requests;
    $running    = 0;
    $wallStart  = microtime(true);

    // Seed initial batch
    while (count($handles) < $concurrency && $queue) {
        $req = array_shift($queue);
        $ch  = loadTestCreateHandle($req);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = $req;
    }

    do {
        curl_multi_exec($mh, $running);

        // Collect completed
        while ($info = curl_multi_info_read($mh)) {
            $ch  = $info['handle'];
            $key = (int)$ch;
            $req = $handles[$key] ?? ['url' => '?'];

            $results[] = [
                'url'    => $req['url'],
                'tenant' => (string)($req['tenant'] ?? ''),
                'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'time'   => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                'size'   => (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
                'error'  => $info['result'] !== CURLE_OK ? curl_error($ch) : null,
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);

            // Enqueue next
            if ($queue) {
                $nextReq = array_shift($queue);
                $nextCh  = loadTestCreateHandle($nextReq);
                curl_multi_add_handle($mh, $nextCh);
                $handles[(int)$nextCh] = $nextReq;
            }
        }

        if ($running > 0) {
            curl_multi_select($mh, 0.05);
        }
    } while ($running > 0 || $handles);

    curl_multi_close($mh);

    return [
        'results'   => $results,
        'wall_time' => microtime(true) - $wallStart,
    ];
}

function loadTestCreateHandle(array $req): CurlHandle
{
    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => '',  // accept gzip
        CURLOPT_USERAGENT      => 'LoadTest/1.0',
    ]);

    if (($req['method'] ?? 'GET') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($req['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
        }
    }
    if (!empty($req['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $req['headers']);
    }

    return $ch;
}

function loadTestBuildHeaders(array $headers, ?string $tenantHost): array
{
    $out = $headers;
    if (is_string($tenantHost) && $tenantHost !== '') {
        $out[] = 'Host: ' . $tenantHost;
        $out[] = 'X-Tenant-Host: ' . $tenantHost;
    }
    return $out;
}


// ═════════════════════════════════════════════════════════════════════════
//  REPORTING
// ═════════════════════════════════════════════════════════════════════════

function loadTestReport(string $profile, array $batch): void
{
    $results   = $batch['results'];
    $wallTime  = $batch['wall_time'];
    $total     = count($results);

    if ($total === 0) {
        echo "  No results.\n";
        return;
    }

    $times   = array_column($results, 'time');
    $statuses = array_count_values(array_column($results, 'status'));
    $errors  = array_filter($results, fn($r) => $r['error'] !== null || $r['status'] >= 400);
    $success = array_filter($results, fn($r) => $r['error'] === null && $r['status'] < 400);

    sort($times);
    $p50  = $times[(int)floor($total * 0.50)] ?? 0;
    $p95  = $times[(int)floor($total * 0.95)] ?? 0;
    $p99  = $times[(int)floor($total * 0.99)] ?? 0;
    $max  = end($times) ?: 0;
    $avg  = array_sum($times) / $total;
    $rps  = $wallTime > 0 ? $total / $wallTime : 0;
    $totalBytes = array_sum(array_column($results, 'size'));

    echo "  ┌─────────────────────────────────────────────────\n";
    echo "  │ Profile:    {$profile}\n";
    echo "  │ Requests:   {$total} in " . round($wallTime, 2) . "s\n";
    echo "  │ Throughput:  " . round($rps, 1) . " req/s\n";
    echo "  │ Data:       " . round($totalBytes / 1024, 0) . " KB transferred\n";
    echo "  │\n";
    echo "  │ Latency:\n";
    echo "  │   avg   " . round($avg * 1000) . "ms\n";
    echo "  │   p50   " . round($p50 * 1000) . "ms\n";
    echo "  │   p95   " . round($p95 * 1000) . "ms\n";
    echo "  │   p99   " . round($p99 * 1000) . "ms\n";
    echo "  │   max   " . round($max * 1000) . "ms\n";
    echo "  │\n";
    echo "  │ Status codes:\n";
    ksort($statuses);
    foreach ($statuses as $code => $count) {
        $pct = round($count / $total * 100, 1);
        echo "  │   {$code}: {$count} ({$pct}%)\n";
    }
    echo "  │\n";
    $errCount = count($errors);
    $successCount = count($success);
    $errRate = round($errCount / $total * 100, 1);
    $tag = $errRate > 5 ? '✗' : ($errRate > 0 ? '⚠' : '✓');
    echo "  │ Success: {$successCount}/{$total}  Errors: {$errCount}/{$total} ({$errRate}%)\n";
    echo "  │ Verdict: {$tag} " . ($errRate === 0.0 ? 'CLEAN' : ($errRate <= 5 ? 'ACCEPTABLE' : 'DEGRADED')) . "\n";

    $tenantBuckets = [];
    foreach ($results as $row) {
        $tenant = (string)($row['tenant'] ?? '');
        if ($tenant === '') {
            $tenant = (string)(parse_url((string)($row['url'] ?? ''), PHP_URL_HOST) ?? 'unknown');
        }
        if (!isset($tenantBuckets[$tenant])) {
            $tenantBuckets[$tenant] = [
                'count' => 0,
                'errors' => 0,
                'times' => [],
            ];
        }
        $tenantBuckets[$tenant]['count']++;
        $tenantBuckets[$tenant]['times'][] = (float)($row['time'] ?? 0);
        if (($row['error'] ?? null) !== null || (int)($row['status'] ?? 0) >= 400) {
            $tenantBuckets[$tenant]['errors']++;
        }
    }

    if (count($tenantBuckets) > 1) {
        echo "  │\n";
        echo "  │ Per-tenant breakdown:\n";
        foreach ($tenantBuckets as $tenant => $bucket) {
            $tCount = max(1, (int)$bucket['count']);
            sort($bucket['times']);
            $tP95 = $bucket['times'][(int)floor($tCount * 0.95)] ?? 0;
            $tErrPct = round(((int)$bucket['errors'] / $tCount) * 100, 1);
            echo "  │   {$tenant}: " . $bucket['count'] . " req, p95=" . round($tP95 * 1000) . "ms, err=" . $tErrPct . "%\n";
        }
    }
    echo "  └─────────────────────────────────────────────────\n\n";
}


// ═════════════════════════════════════════════════════════════════════════
//  PROFILE: Storefront (public GET pages)
// ═════════════════════════════════════════════════════════════════════════

function buildStorefrontRequests(string $baseUrl, int $count, array $tenantHosts = []): array
{
    $pages = [
        '/',
        '/ecommerce/shop',
        '/cms/blog',
        '/ecommerce/cart',
    ];

    // Discover a few product slugs for detail page hits
    $ch = curl_init("{$baseUrl}/api/v1/ecommerce/products?limit=5");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = @json_decode((string)$body, true);
    $items = $json['items'] ?? $json['data'] ?? [];

    if (!empty($items)) {
        foreach (array_slice($items, 0, 5) as $p) {
            if (!empty($p['slug'])) {
                $pages[] = '/ecommerce/shop/' . $p['slug'];
            }
        }
    }

    // Discover blog post slugs
    $ch = curl_init("{$baseUrl}/api/v1/cms/content?type=post&status=published&limit=5");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch);
    curl_close($ch);
    // Blog posts may not be available via API — just use /cms/blog

    $requests = [];
    $tenantCount = max(1, count($tenantHosts));
    for ($i = 0; $i < $count; $i++) {
        $page = $pages[$i % count($pages)];
        $tenant = $tenantHosts[$i % $tenantCount] ?? '';
        $requests[] = [
            'method' => 'GET',
            'url' => $baseUrl . $page,
            'headers' => loadTestBuildHeaders([], $tenant),
            'tenant' => $tenant,
        ];
    }
    return $requests;
}


// ═════════════════════════════════════════════════════════════════════════
//  PROFILE: API (REST JSON endpoints)
// ═════════════════════════════════════════════════════════════════════════

function buildApiRequests(string $baseUrl, int $count, array $tenantHosts = []): array
{
    $endpoints = [
        '/api/v1/ecommerce/products',
        '/api/v1/ecommerce/products?limit=50',
        '/api/v1/ecommerce/categories',
    ];

    // Discover product IDs for detail hits
    $ch = curl_init("{$baseUrl}/api/v1/ecommerce/products?limit=10");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = @json_decode((string)$body, true);
    $items = $json['items'] ?? $json['data'] ?? [];

    if (!empty($items)) {
        foreach (array_slice($items, 0, 10) as $p) {
            if (!empty($p['id'])) {
                $endpoints[] = '/api/v1/ecommerce/products/' . (int)$p['id'];
            }
        }
    }

    $requests = [];
    $tenantCount = max(1, count($tenantHosts));
    for ($i = 0; $i < $count; $i++) {
        $ep = $endpoints[$i % count($endpoints)];
        $tenant = $tenantHosts[$i % $tenantCount] ?? '';
        $requests[] = [
            'method'  => 'GET',
            'url'     => $baseUrl . $ep,
            'headers' => loadTestBuildHeaders(['Accept: application/json'], $tenant),
            'tenant' => $tenant,
        ];
    }
    return $requests;
}


// ═════════════════════════════════════════════════════════════════════════
//  PROFILE: Mixed (storefront + API interleaved)
// ═════════════════════════════════════════════════════════════════════════

function buildMixedRequests(string $baseUrl, int $count, array $tenantHosts = []): array
{
    $half = (int)ceil($count / 2);
    $sf   = buildStorefrontRequests($baseUrl, $half, $tenantHosts);
    $api  = buildApiRequests($baseUrl, $count - $half, $tenantHosts);

    // Interleave
    $mixed = [];
    $si = 0;
    $ai = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($i % 2 === 0 && $si < count($sf)) {
            $mixed[] = $sf[$si++];
        } elseif ($ai < count($api)) {
            $mixed[] = $api[$ai++];
        } elseif ($si < count($sf)) {
            $mixed[] = $sf[$si++];
        }
    }
    return $mixed;
}


// ═════════════════════════════════════════════════════════════════════════
//  PROFILE: Shopping Journey (sequential multi-page session navigation)
// ═════════════════════════════════════════════════════════════════════════

function runCheckoutProfile(string $baseUrl, int $iterations, array $tenantHosts = []): array
{
    // Discover product slugs for detail page visits
    $ch = curl_init("{$baseUrl}/api/v1/ecommerce/products?limit=5");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = @json_decode((string)$body, true);

    $items = $json['items'] ?? $json['data'] ?? [];
    $productSlugs = [];
    foreach ($items as $p) {
        if (!empty($p['slug'])) $productSlugs[] = $p['slug'];
    }

    if (empty($productSlugs)) {
        echo "  ⚠ No products found for shopping journey — skipping.\n\n";
        return ['results' => [], 'wall_time' => 0];
    }

    // Each iteration simulates a user browsing session:
    //   shop listing → product detail → blog → another product → cart page
    // All GETs — POST endpoints (cart/add, checkout) require CSRF tokens
    // which is correct security behavior, not testable via anonymous load test.

    $results   = [];
    $wallStart = microtime(true);
    $tenantCount = max(1, count($tenantHosts));

    for ($i = 0; $i < $iterations; $i++) {
        $cookieFile = tempnam(sys_get_temp_dir(), 'lt_cookie_');
        $slug = $productSlugs[$i % count($productSlugs)];
        $tenant = $tenantHosts[$i % $tenantCount] ?? '';

        $journey = [
            '/ecommerce/shop',
            '/ecommerce/shop/' . $slug,
            '/cms/blog',
            '/ecommerce/shop/' . $productSlugs[($i + 1) % count($productSlugs)],
            '/ecommerce/cart',
        ];

        foreach ($journey as $path) {
            $ch = curl_init("{$baseUrl}{$path}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'LoadTest/1.0',
                CURLOPT_HTTPHEADER     => loadTestBuildHeaders([], $tenant),
            ]);
            curl_exec($ch);
            $results[] = [
                'url'    => "{$baseUrl}{$path}",
                'tenant' => $tenant,
                'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'time'   => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                'size'   => (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
                'error'  => curl_errno($ch) ? curl_error($ch) : null,
            ];
            curl_close($ch);
        }

        @unlink($cookieFile);
    }

    return [
        'results'   => $results,
        'wall_time' => microtime(true) - $wallStart,
    ];
}


// ═════════════════════════════════════════════════════════════════════════
//  CONCURRENCY RAMP — find breaking point
// ═════════════════════════════════════════════════════════════════════════

function runConcurrencyRamp(string $baseUrl, array $tenantHosts = []): void
{
    echo "══ Concurrency Ramp (finding limits) ══\n\n";

    $levels  = [1, 5, 10, 25, 50];
    $perLevel = 50;  // requests per level

    $endpoint = "{$baseUrl}/api/v1/ecommerce/products?limit=5";

    echo "  " . str_pad('Conc', 6) . str_pad('Reqs', 6) . str_pad('RPS', 10)
       . str_pad('p50', 8) . str_pad('p95', 8) . str_pad('p99', 8)
       . str_pad('Err%', 8) . "Verdict\n";
    echo "  " . str_repeat('─', 62) . "\n";

    foreach ($levels as $conc) {
        $requests = [];
        $tenantCount = max(1, count($tenantHosts));
        for ($i = 0; $i < $perLevel; $i++) {
            $tenant = $tenantHosts[$i % $tenantCount] ?? '';
            $requests[] = ['method' => 'GET', 'url' => $endpoint, 'headers' => ['Accept: application/json']];
            $requests[$i]['headers'] = loadTestBuildHeaders($requests[$i]['headers'], $tenant);
            $requests[$i]['tenant'] = $tenant;
        }

        $batch  = loadTestBatch($requests, $conc);
        $res    = $batch['results'];
        $wall   = $batch['wall_time'];
        $total  = count($res);
        $errors = count(array_filter($res, fn($r) => $r['error'] !== null || $r['status'] >= 400));

        $times = array_column($res, 'time');
        sort($times);
        $p50 = round(($times[(int)floor($total * 0.50)] ?? 0) * 1000);
        $p95 = round(($times[(int)floor($total * 0.95)] ?? 0) * 1000);
        $p99 = round(($times[(int)floor($total * 0.99)] ?? 0) * 1000);
        $rps = $wall > 0 ? round($total / $wall, 1) : 0;
        $errPct = round($errors / $total * 100, 1);

        $verdict = $errPct > 10 ? '✗ DEGRADED' : ($errPct > 0 ? '⚠ PARTIAL' : ($p95 > 3000 ? '⚠ SLOW' : '✓ OK'));

        echo "  " . str_pad((string)$conc, 6)
           . str_pad((string)$total, 6)
           . str_pad("{$rps}/s", 10)
           . str_pad("{$p50}ms", 8)
           . str_pad("{$p95}ms", 8)
           . str_pad("{$p99}ms", 8)
           . str_pad("{$errPct}%", 8)
           . $verdict . "\n";
    }
    echo "\n";
}


// ═════════════════════════════════════════════════════════════════════════
//  EXECUTION
// ═════════════════════════════════════════════════════════════════════════

$profiles = [];

if ($selectedProfile === 'all' || $selectedProfile === 'storefront') {
    $profiles['Storefront (HTML pages)'] = fn() => loadTestBatch(
        buildStorefrontRequests($BASE_URL, $requestsPerBatch, $tenantHosts), $concurrency
    );
}

if ($selectedProfile === 'all' || $selectedProfile === 'api') {
    $profiles['API (JSON endpoints)'] = fn() => loadTestBatch(
        buildApiRequests($BASE_URL, $requestsPerBatch, $tenantHosts), $concurrency
    );
}

if ($selectedProfile === 'all' || $selectedProfile === 'mixed') {
    $profiles['Mixed (storefront + API)'] = fn() => loadTestBatch(
        buildMixedRequests($BASE_URL, $requestsPerBatch, $tenantHosts), $concurrency
    );
}

if ($selectedProfile === 'all' || $selectedProfile === 'multitenant') {
    if (count($tenantHosts) > 1) {
        $profiles['Multi-Tenant Mixed (tenant round-robin)'] = fn() => loadTestBatch(
            buildMixedRequests($BASE_URL, $requestsPerBatch, $tenantHosts), $concurrency
        );
    } else {
        echo "  ⚠ Multi-tenant profile requested but only one tenant host is configured.\n";
        echo "    Set LOAD_TEST_TENANT_HOSTS to a comma-separated list to enable true multi-tenant simulation.\n\n";
    }
}

if ($selectedProfile === 'all' || $selectedProfile === 'checkout') {
    $checkoutIter = min(20, (int)ceil($requestsPerBatch / 4));
    $profiles['Shopping Journey (sequential sessions)'] = fn() => runCheckoutProfile($BASE_URL, $checkoutIter, $tenantHosts);
}

foreach ($profiles as $name => $runner) {
    echo "══ {$name} ══\n\n";
    $batch = $runner();
    loadTestReport($name, $batch);
}

// Concurrency ramp always runs in 'all' mode
if ($selectedProfile === 'all' || $selectedProfile === 'ramp') {
    runConcurrencyRamp($BASE_URL, $tenantHosts);
}


// ═════════════════════════════════════════════════════════════════════════
//  SUMMARY
// ═════════════════════════════════════════════════════════════════════════

echo "══ Load Test Complete ══\n\n";
