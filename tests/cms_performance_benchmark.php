<?php
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://cmsnew.test', '/');
$iterations = max(1, (int)($argv[2] ?? 10));

$endpoints = [
    ['path' => '/',                    'label' => 'Homepage (public)'],
    ['path' => '/cms/login',           'label' => 'CMS Login page'],
    ['path' => '/api/v1/health',       'label' => 'Health check (no auth)'],
    ['path' => '/cms/admin',           'label' => 'CMS Admin (auth redirect)'],
    ['path' => '/api/v1/me',           'label' => 'API /me (auth)'],
];

function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HEADER => false,
    ]);
    $t0 = microtime(true) * 1000;
    $body = curl_exec($ch);
    $ms = round(microtime(true) * 1000 - $t0, 2);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = strlen((string)$body);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ms' => $ms, 'code' => $code, 'bytes' => $size, 'ok' => $code > 0 && !$err, 'err' => $err ?: null, 'body' => substr((string)$body, 0, 120)];
}

function p95(array $v): float { sort($v); return $v[(int)ceil(count($v)*0.95)-1] ?? end($v); }
function p50(array $v): float { sort($v); return $v[(int)floor(count($v)*0.5)] ?? 0; }

echo "=== CMS HTTP Benchmark ===\n";
printf("Base: %s  |  Runs: %d\n\n", $baseUrl, $iterations);

// Warmup
for ($i=0;$i<3;$i++) httpGet("{$baseUrl}/api/v1/health");

$all = [];
foreach ($endpoints as $ep) {
    $url = "{$baseUrl}{$ep['path']}";
    $times = []; $codes = []; $sizes = []; $first = '';
    for ($i=0;$i<$iterations;$i++) {
        $r = httpGet($url);
        $times[] = $r['ms']; $codes[] = $r['code']; $sizes[] = $r['bytes'];
        if ($i===0) $first = $r['body'];
    }
    $ok = count(array_filter($codes, fn($c)=>$c>0&&$c<500));
    printf("%-30s %d/%d ok  min=%5.1f max=%5.1f avg=%5.1f p50=%5.1f p95=%5.1f  %5.1f KB  [%d]\n",
        $ep['label'], $ok, $iterations,
        min($times), max($times), array_sum($times)/count($times),
        p50($times), p95($times), $sizes[0]/1024, $codes[0]);
    if ($first && strlen($first)<150) echo "    Preview: {$first}\n";
    $all[$ep['label']] = $times;
}

echo "\n=== Choke Points ===\n";
$avgs = []; foreach ($all as $l => $t) $avgs[$l] = array_sum($t)/count($t);
arsort($avgs);
foreach ($avgs as $l => $a) {
    $icon = $a>200?'\033[31m':($a>100?'\033[33m':'\033[32m');
    printf("  %s %-30s %6.1f ms  %s\033[0m\n", $icon, $l, $a, str_repeat('#', (int)($a/5)));
}

echo "\nDone.\n";
