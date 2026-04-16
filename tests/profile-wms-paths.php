<?php

declare(strict_types=1);

/**
 * Profile individual WMS request paths to identify bottleneck.
 * Measures per-request latency for each candidate path hit by load test.
 * 
 * Usage:
 *   php tests/profile-wms-paths.php              # default 20 samples per path
 *   php tests/profile-wms-paths.php 50           # 50 samples per path
 */

$samples = max(5, min(200, (int)($argv[1] ?? 20)));
$baseUrl = 'http://wms.test';
$tenantHost = 'wms.test';

// Paths hit by load test for WMS entry
$paths = [
    '/' => 'Root (entry landing)',
    '/login' => 'WMS login page',
    '/wms' => 'WMS dashboard',
    '/api/v1/wms/health' => 'WMS health API',
];

function buildHeaders($tenantHost = 'wms.test'): array
{
    return [
        'Host: ' . $tenantHost,
        'User-Agent: LoadTest/1.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive',
    ];
}

function timeRequest($url, $tenantHost): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => buildHeaders($tenantHost),
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $elapsed = (microtime(true) - $start) * 1000;
    
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'time_ms' => round($elapsed, 2),
        'status' => $status,
        'error' => $error,
        'ok' => $status >= 200 && $status < 400 && !$error,
    ];
}

echo "\n════════════════════════════════════════════════════════════════════════════\n";
echo "WMS Path Latency Profiling — {$samples} samples per path\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

$allTimes = [];

foreach ($paths as $path => $label) {
    $url = $baseUrl . $path;
    $times = [];
    $okCount = 0;
    $errorCount = 0;

    echo "Profiling: {$label}\n";
    echo "  URL: {$url}\n";
    echo "  Samples: ";

    for ($i = 0; $i < $samples; $i++) {
        $result = timeRequest($url, $tenantHost);
        if ($result['ok']) {
            $times[] = $result['time_ms'];
            $okCount++;
            echo '.';
        } else {
            $errorCount++;
            echo 'E';
        }
        if (($i + 1) % 10 === 0) {
            echo " ";
        }
    }

    echo "\n";

    if (empty($times)) {
        echo "  ❌ All requests failed or returned non-2xx status\n\n";
        continue;
    }

    sort($times);
    $count = count($times);
    $min = $times[0];
    $max = $times[$count - 1];
    $avg = array_sum($times) / $count;
    $p50 = $times[(int)(0.50 * $count)];
    $p95 = $times[(int)(0.95 * $count)];
    $p99 = $times[(int)(0.99 * $count)] ?? $max;

    printf("  ✓ Success: %d/%d (%.1f%% error)\n", $okCount, $samples, ($errorCount / $samples) * 100);
    printf("  Latency: min=%.0fms, avg=%.0fms, p50=%.0fms, p95=%.0fms, p99=%.0fms, max=%.0fms\n",
        $min, $avg, $p50, $p95, $p99, $max);

    $allTimes[$path] = [
        'label' => $label,
        'avg' => $avg,
        'p95' => $p95,
        'times' => $times,
    ];

    echo "\n";
}

if (empty($allTimes)) {
    echo "No successful requests. Exiting.\n";
    exit(1);
}

// Summary
echo "════════════════════════════════════════════════════════════════════════════\n";
echo "Summary (sorted by p95 latency)\n";
echo "════════════════════════════════════════════════════════════════════════════\n\n";

usort($allTimes, fn($a, $b) => $b['p95'] <=> $a['p95']);

foreach ($allTimes as $path => $data) {
    printf("%-40s p95=%-6.0fms avg=%-6.0fms\n", 
        $data['label'], $data['p95'], $data['avg']);
}

// Identify slowest path
if (!empty($allTimes)) {
    $slowest = array_key_first($allTimes);
    $slowestData = $allTimes[$slowest];
    echo "\n🐢 Bottleneck: {$slowestData['label']} (p95={$slowestData['p95']}ms)\n";
}

echo "\n";
