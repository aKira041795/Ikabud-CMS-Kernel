<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';

function smokeFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit($exitCode);
}

function smokeStatusCode(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string)$header, $matches) === 1) {
            return (int)$matches[1];
        }
    }

    return 0;
}

/**
 * @return array{status:int,headers:array<int,string>,body:string,url:string}
 */
function smokeHttpRequest(string $method, string $url, ?string $body = null, array $headers = []): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        smokeFail("Invalid URL: {$url}");
    }

    if (!ini_get('allow_url_fopen')) {
        smokeFail('allow_url_fopen is disabled; PHP stream HTTP smoke tests cannot run.');
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) && is_array($http_response_header)
        ? $http_response_header
        : [];

    if ($responseBody === false && $responseHeaders === []) {
        $error = error_get_last();
        smokeFail('HTTP request failed: ' . (string)($error['message'] ?? 'unknown error'));
    }

    return [
        'status' => smokeStatusCode($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($responseBody) ? $responseBody : '',
        'url' => $url,
    ];
}

function smokeAssertContains(string $haystack, string $needle, string $context): void
{
    if (!str_contains($haystack, $needle)) {
        smokeFail("Expected '{$needle}' in {$context}.");
    }
}

$appUrl = rtrim((string)config('app.url', ''), '/');
if ($appUrl === '') {
    smokeFail('APP_URL is not configured.');
}

$installMarkerPath = BASE_PATH . '/storage/.installed';
$installed = is_file($installMarkerPath);

$lockUrl = $appUrl . '/lock.php';

echo "=== HTTP Installer Smoke Test ===\n";
echo 'Base URL: ' . $appUrl . "\n";
echo 'Installed marker: ' . ($installed ? 'present' : 'missing') . "\n\n";

echo "[1/2] GET {$lockUrl}\n";
$locked = smokeHttpRequest('GET', $lockUrl);
echo 'Status: ' . $locked['status'] . "\n";

if ($installed) {
    if ($locked['status'] !== 403) {
        smokeFail('Expected 403 from lock.php while installed marker exists.');
    }
    smokeAssertContains($locked['body'], 'System already installed', 'installed lock response body');
    echo "PASS: installed lock is enforced\n\n";
} else {
    if ($locked['status'] !== 200) {
        smokeFail('Expected 200 from lock.php before installation.');
    }
    smokeAssertContains($locked['body'], 'Install', 'installer page body');
    echo "PASS: installer page is reachable before installation\n\n";
}


echo "[2/2] GET {$appUrl}/lock.php?force=1\n";
$forced = smokeHttpRequest('GET', "{$appUrl}/lock.php?force=1");
echo 'Status: ' . $forced['status'] . "\n";
if ($installed) {
    if ($forced['status'] !== 403) {
        smokeFail('Expected 403 from lock.php?force=1 while installed marker exists.');
    }
    smokeAssertContains($forced['body'], 'System already installed', 'forced installer lock response body');
    echo "PASS: force query does not bypass installed lock\n\n";
} else {
    if ($forced['status'] !== 200) {
        smokeFail('Expected 200 from lock.php?force=1 before installation.');
    }
    smokeAssertContains($forced['body'], 'Database Host', 'forced installer response body');
    smokeAssertContains($forced['body'], 'Admin Username', 'forced installer response body');
    echo "PASS: force query does not change pre-install accessibility\n\n";
}


echo "[3/3] GET {$appUrl}/\n";
$home = smokeHttpRequest('GET', $appUrl . '/');
echo 'Status: ' . $home['status'] . "\n";
if (!in_array($home['status'], [200, 301, 302, 303, 307, 308], true)) {
    smokeFail('Expected a normal application response or redirect from the home page.');
}
if (str_contains($home['body'], 'Application Error') || str_contains($home['body'], 'An unexpected error occurred')) {
    smokeFail('Application home page returned the generic error document.');
}
echo "PASS: application home page does not return the generic error page\n\n";

echo "All installer smoke checks passed.\n";