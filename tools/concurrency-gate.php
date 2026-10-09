<?php

declare(strict_types=1);

$url = null;
$path = '/login';
$concurrency = 10;
$requests = 40;
$failOnRatio = 4.0;
$json = false;
$hostHeader = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (str_starts_with($arg, '--url=')) {
        $url = trim(substr($arg, 6));
    } elseif (str_starts_with($arg, '--path=')) {
        $path = substr($arg, 7);
    } elseif (str_starts_with($arg, '--concurrency=')) {
        $concurrency = (int)substr($arg, 14);
    } elseif (str_starts_with($arg, '--requests=')) {
        $requests = (int)substr($arg, 11);
    } elseif (str_starts_with($arg, '--fail-on-ratio=')) {
        $value = substr($arg, 16);
        $failOnRatio = is_numeric($value) ? (float)$value : -1.0;
    } elseif (str_starts_with($arg, '--host-header=')) {
        $hostHeader = trim(substr($arg, 14));
    } else {
        fwrite(STDERR, "ERROR unknown argument: {$arg}\n");
        exit(2);
    }
}

if (!extension_loaded('curl') || !function_exists('curl_multi_init')) {
    fwrite(STDERR, "NOT MEASURED curl_multi is unavailable\n");
    exit(2);
}
if (!is_string($url) || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "ERROR --url=<base> is required and must be an absolute URL\n");
    exit(2);
}
if ($requests < 1 || $concurrency < 1 || $concurrency > $requests || $failOnRatio <= 0.0 || !is_finite($failOnRatio)) {
    fwrite(STDERR, "ERROR requests/concurrency/ratio must be positive, and concurrency cannot exceed requests\n");
    exit(2);
}
if ($hostHeader !== null && ($hostHeader === '' || str_contains($hostHeader, "\r") || str_contains($hostHeader, "\n"))) {
    fwrite(STDERR, "ERROR invalid --host-header value\n");
    exit(2);
}

$target = rtrim($url, '/') . '/' . ltrim($path, '/');
$headers = $hostHeader !== null ? ['Host: ' . $hostHeader] : [];

/** @return CurlHandle */
function concurrencyGateHandle(string $target, array $headers)
{
    $handle = curl_init($target);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Ikabud-Concurrency-Gate/1.0',
    ]);
    return $handle;
}

/** @return array{samples: array<int,float>, errors: int, wall_s: float, throughput_rps: float} */
function concurrencyGateSequential(string $target, array $headers, int $requests): array
{
    $samples = [];
    $errors = 0;
    $started = hrtime(true);
    for ($i = 0; $i < $requests; $i++) {
        $handle = concurrencyGateHandle($target, $headers);
        curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $duration = (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME);
        if (curl_errno($handle) !== 0 || $status < 200 || $status >= 400 || $duration <= 0.0) {
            $errors++;
        } else {
            $samples[] = $duration * 1000;
        }
        curl_close($handle);
    }
    $wall = max((hrtime(true) - $started) / 1_000_000_000, 0.000001);
    return ['samples' => $samples, 'errors' => $errors, 'wall_s' => $wall, 'throughput_rps' => $requests / $wall];
}

/** @return array{samples: array<int,float>, errors: int, wall_s: float, throughput_rps: float} */
function concurrencyGateConcurrent(string $target, array $headers, int $requests, int $concurrency): array
{
    $multi = curl_multi_init();
    $activeHandles = [];
    $samples = [];
    $errors = 0;
    $startedCount = 0;
    $started = hrtime(true);

    $add = static function () use ($multi, $target, $headers, &$activeHandles, &$startedCount): void {
        $handle = concurrencyGateHandle($target, $headers);
        curl_multi_add_handle($multi, $handle);
        $activeHandles[spl_object_id($handle)] = $handle;
        $startedCount++;
    };
    while ($startedCount < min($concurrency, $requests)) {
        $add();
    }

    do {
        do {
            $multiStatus = curl_multi_exec($multi, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $duration = (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME);
            if (($info['result'] ?? CURLE_OK) !== CURLE_OK || $status < 200 || $status >= 400 || $duration <= 0.0) {
                $errors++;
            } else {
                $samples[] = $duration * 1000;
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($activeHandles[spl_object_id($handle)]);
            if ($startedCount < $requests) {
                $add();
            }
        }

        if ($running > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(1_000);
            }
        }
    } while ($running > 0 || $activeHandles !== []);

    curl_multi_close($multi);
    $wall = max((hrtime(true) - $started) / 1_000_000_000, 0.000001);
    return ['samples' => $samples, 'errors' => $errors, 'wall_s' => $wall, 'throughput_rps' => $requests / $wall];
}

function concurrencyGatePercentile(array $samples, float $percentile): ?float
{
    if ($samples === []) {
        return null;
    }
    sort($samples, SORT_NUMERIC);
    $index = max(0, min(count($samples) - 1, (int)ceil(($percentile / 100) * count($samples)) - 1));
    return (float)$samples[$index];
}

$sequential = concurrencyGateSequential($target, $headers, $requests);
if ($sequential['samples'] === []) {
    $message = ['status' => 'NOT_MEASURED', 'url' => $target, 'reason' => 'sequential phase produced no successful samples', 'errors' => $sequential['errors']];
    echo $json ? json_encode($message, JSON_UNESCAPED_SLASHES) . "\n" : "NOT MEASURED url={$target} reason=no_sequential_samples errors={$sequential['errors']}\n";
    exit(2);
}

$concurrent = concurrencyGateConcurrent($target, $headers, $requests, $concurrency);
if ($concurrent['samples'] === []) {
    $message = ['status' => 'NOT_MEASURED', 'url' => $target, 'reason' => 'concurrent phase produced no successful samples', 'errors' => $concurrent['errors']];
    echo $json ? json_encode($message, JSON_UNESCAPED_SLASHES) . "\n" : "NOT MEASURED url={$target} reason=no_concurrent_samples errors={$concurrent['errors']}\n";
    exit(2);
}

$p50Seq = concurrencyGatePercentile($sequential['samples'], 50);
$p50 = concurrencyGatePercentile($concurrent['samples'], 50);
$p95 = concurrencyGatePercentile($concurrent['samples'], 95);
$p99 = concurrencyGatePercentile($concurrent['samples'], 99);
$max = max($concurrent['samples']);
$ratio = $p50Seq !== null && $p50Seq > 0.0 && $p95 !== null ? $p95 / $p50Seq : null;
$totalErrors = $sequential['errors'] + $concurrent['errors'];
$errorRate = ($concurrent['errors'] / $requests) * 100;
$failed = $totalErrors > 0 || $ratio === null || $ratio > $failOnRatio;
$status = $failed ? 'FAIL' : 'PASS';

$report = [
    'status' => $status,
    'url' => $target,
    'requests_per_phase' => $requests,
    'concurrency' => $concurrency,
    'p50_seq_ms' => $p50Seq,
    'p50_concurrent_ms' => $p50,
    'p95_concurrent_ms' => $p95,
    'p99_concurrent_ms' => $p99,
    'max_concurrent_ms' => $max,
    'sequential_rps' => $sequential['throughput_rps'],
    'concurrent_rps' => $concurrent['throughput_rps'],
    'sequential_errors' => $sequential['errors'],
    'concurrent_errors' => $concurrent['errors'],
    'error_rate_pct' => $errorRate,
    'p95_ratio' => $ratio,
    'fail_on_ratio' => $failOnRatio,
];

if ($json) {
    echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo sprintf(
        "%s p50_seq=%.2fms seq_rps=%.2f concurrent[p50=%.2fms p95=%.2fms p99=%.2fms max=%.2fms rps=%.2f] errors=%d(%.2f%%) ratio=%.2f limit=%.2f\n",
        $status,
        $p50Seq,
        $sequential['throughput_rps'],
        $p50,
        $p95,
        $p99,
        $max,
        $concurrent['throughput_rps'],
        $totalErrors,
        $errorRate,
        $ratio,
        $failOnRatio
    );
}
exit($failed ? 1 : 0);
