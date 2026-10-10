#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * tools/perf-sample.php — take N readings of the perf API and report median + range, not one sample.
 *
 * WHY THIS EXISTS
 * /superadmin/perf renders a SINGLE request. This host drifts +/-10-30% between readings, and
 * route_match alone was recorded moving 7.93 -> 12.24 ms with no explanation, so one sample cannot
 * tell a real change from noise. Every claim made about these metrics is backed by a median with a
 * range; this is the tool that produces one for the live host.
 *
 * It does NOT add an API route. GET /api/v1/superadmin/perf already exists and returns the same
 * payload the page renders. What it does is take several readings and reduce them, because that is
 * the form in which the numbers are comparable.
 *
 * It needs a superadmin session on the target host, so run it where that session can be created.
 * Credentials come from the environment and are never echoed, logged, or written to disk.
 *
 * Usage — PREFER THE `read -s` FORM. Putting the password on the command line puts it in your shell
 * history and in the process list, and a single line of terminal output can carry it into a
 * transcript where it cannot be un-sent. That happened on 2026-10-10 with this very script.
 *
 *   read -rs -p "perf password: " PERF_PASS && export PERF_PASS
 *   PERF_USER=<user> php tools/perf-sample.php \
 *     --base=https://kernelappos.ikabudkernel.com --samples=8 --out=/tmp/perf.txt
 *   unset PERF_PASS
 *
 * --out writes the same table to a file, so a run does not have to be repeated (nor the password
 * re-entered) just to be read. The file contains phase timings only - never a credential.
 *
 * Local:
 *   PERF_USER=superadmin PERF_PASS='...' \
 *     php tools/perf-sample.php --base=http://127.0.0.1 --host-header=applicationos.test
 *
 * Exit codes: 0 = sampled, 1 = could not authenticate, 2 = endpoint did not answer.
 */

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

$base = rtrim((string)argValue($argv, '--base', ''), '/');
$samples = (int)argValue($argv, '--samples', '8');
$hostHeader = argValue($argv, '--host-header');
$out = argValue($argv, '--out');
$user = (string)(getenv('PERF_USER') ?: '');
$pass = (string)(getenv('PERF_PASS') ?: '');

if ($base === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "usage: PERF_USER=... PERF_PASS=... php tools/perf-sample.php --base=URL [--samples=N] [--host-header=H]\n");
    exit(1);
}
if ($samples < 1) {
    $samples = 1;
}

/** One HTTP request. Returns [status, body, cookieValues[]]. */
function request(string $url, ?string $body, array $headers): array
{
    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => $headers,
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $cookies = [];
    foreach ($http_response_header ?? [] as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            // Keep EVERY cookie, not just the first: login sets the JWT cookie AND a PHPSESSID, and
            // sending only the first one is why an earlier version of this tool saw 403 from the
            // perf endpoint while curl (which stores the whole jar) worked.
            $cookies[] = trim(explode(';', substr($line, strlen('Set-Cookie:')))[0]);
        }
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return [$status, (string)$response, $cookies];
}

$common = ['Accept: application/json'];
if ($hostHeader !== null && $hostHeader !== '') {
    $common[] = 'Host: ' . $hostHeader;
}

// ── authenticate ────────────────────────────────────────────────────────────────────────────────
[$status, $body, $setCookies] = request(
    $base . '/api/v1/auth/login',
    json_encode(['username' => $user, 'password' => $pass], JSON_UNESCAPED_SLASHES),
    array_merge($common, ['Content-Type: application/json'])
);

if ($status === 0) {
    fwrite(STDERR, "perf-sample: no response from {$base}/api/v1/auth/login\n");
    exit(2);
}
$auth = json_decode($body, true);
if (!is_array($auth) || empty($auth['ok'])) {
    // Deliberately does not echo the body: a failed login response can carry the username back.
    fwrite(STDERR, "perf-sample: authentication failed (HTTP {$status}). Check PERF_USER / PERF_PASS.\n");
    exit(1);
}
if ($setCookies === []) {
    fwrite(STDERR, "perf-sample: logged in but no session cookie was returned - cannot sample.\n");
    exit(2);
}

// Send BOTH credentials the login handed back: every session cookie, and the JWT from the body.
// A real browser presents the cookies; the API issues the token. Sending both removes the whole
// class of "works in curl, 403 here" differences and costs nothing.
$authHeaders = $common;
$authHeaders[] = 'Cookie: ' . implode('; ', $setCookies);
if (isset($auth['token']) && is_string($auth['token']) && $auth['token'] !== '') {
    $authHeaders[] = 'Authorization: Bearer ' . $auth['token'];
}

// ── sample ──────────────────────────────────────────────────────────────────────────────────────
$metrics = [
    'boot'                  => ['phases', 'boot'],
    'boot_fastpath'         => ['phases', 'boot_fastpath'],
    'boot_bootstrap'        => ['phases', 'boot_bootstrap'],
    'boot_requires'         => ['phases', 'boot_requires'],
    'dispatch'              => ['phases', 'dispatch'],
    'route_match'           => ['phase_deltas', 'route_match'],
    'route_match_sort'      => ['phase_deltas', 'route_match_sort'],
    'route_match_scan'      => ['phase_deltas', 'route_match_scan'],
    'route_merge'           => ['registration_deltas', 'route_merge'],
    'helpers_load'          => ['registration_deltas', 'helpers_load'],
    'discovery'             => ['module_route_deltas', 'discovery'],
];
$values = array_fill_keys(array_keys($metrics), []);
$missing = [];
$readings = 0;
$apcuReadings = [];

for ($i = 0; $i < $samples; $i++) {
    [$status, $body] = request($base . '/api/v1/superadmin/perf', null, $authHeaders);
    if ($status !== 200) {
        continue;
    }
    $payload = json_decode($body, true);
    $attribution = $payload['perf']['attribution'] ?? null;
    if (!is_array($attribution)) {
        continue;
    }
    $readings++;
    if (is_array($payload['perf']['apcu'] ?? null)) {
        $apcuReadings[] = $payload['perf']['apcu'];
    }
    foreach ($metrics as $name => [$group, $key]) {
        $value = $attribution[$group][$key] ?? null;
        if (is_numeric($value)) {
            $values[$name][] = (float)$value;
        } else {
            $missing[$name] = true;
        }
    }
}

if ($readings === 0) {
    fwrite(STDERR, "perf-sample: no usable readings (endpoint returned no attribution payload).\n");
    exit(2);
}

// ── report ──────────────────────────────────────────────────────────────────────────────────────
$report = sprintf("%s  —  %d reading(s)\n\n", $base, $readings);
$report .= sprintf("%-20s%12s%12s%12s%10s\n", 'metric', 'median', 'min', 'max', 'n');
foreach ($metrics as $name => $_) {
    $series = $values[$name];
    if ($series === []) {
        $report .= sprintf("%-20s%12s%12s%12s%10s\n", $name, '-', '-', '-', '0');
        continue;
    }
    sort($series);
    $count = count($series);
    $median = $count % 2
        ? $series[intdiv($count, 2)]
        : ($series[$count / 2 - 1] + $series[$count / 2]) / 2;
    $report .= sprintf("%-20s%12.3f%12.3f%12.3f%10d\n", $name, $median, $series[0],
        $series[$count - 1], $count);
}

if ($missing !== []) {
    $report .= "\nnot reported by this host (instrumentation absent or phase never marked):\n";
    foreach (array_keys($missing) as $name) {
        $report .= "  - {$name}\n";
    }
    $report .= "\nIf boot_fastpath / boot_bootstrap / boot_requires / route_match_sort are listed\n";
    $report .= "here, the deployment does not include the 2026-10-10 instrumentation\n";
    $report .= "(commits c93446db, aef943d0).\n";
}

if ($apcuReadings !== []) {
    $last = $apcuReadings[count($apcuReadings) - 1];
    $fmt = static function ($v): string {
        if ($v === null) {
            return '-';
        }
        return is_float($v) ? sprintf('%.3f', $v) : (string)$v;
    };

    $report .= "\nAPCu — is there somewhere for a cross-request cache to live, and what does a round trip cost?\n";
    $report .= sprintf("  usable=%s  roundtrip_ok=%s  entry_kb=%s  shm_size=%s\n",
        $fmt($last['usable'] ?? null),
        $fmt($last['roundtrip_ok'] ?? null),
        $fmt($last['entry_kb'] ?? null),
        $fmt($last['shm_size'] ?? null));
    $report .= sprintf("  store_ms=%s  fetch_ms=%s  cache_hits=%s  cache_misses=%s  cache_mem_mb=%s\n",
        $fmt($last['store_ms'] ?? null),
        $fmt($last['fetch_ms'] ?? null),
        $fmt($last['cache_hits'] ?? null),
        $fmt($last['cache_misses'] ?? null),
        $fmt($last['cache_mem_mb'] ?? null));

    if (!empty($last['reason'])) {
        $report .= "  reason: {$last['reason']}\n";
    }

    // A failed round trip DISCARDS the timings rather than reporting the cost of a failure as the
    // cost of a hit. Seeing '-' here is the design working, not missing data.
    if (($last['roundtrip_ok'] ?? null) === false) {
        $report .= "  NOTE: store_ms/fetch_ms are '-' because the round trip failed - a fetch that\n";
        $report .= "        did not happen is not a cheap fetch. Do not read this as zero cost.\n";
    }

    $differing = array_unique(array_map(
        static fn(array $r): string => var_export($r['usable'] ?? null, true),
        $apcuReadings
    ));
    if (count($differing) > 1) {
        $report .= '  WARNING: usable differed across readings (' . implode(', ', $differing)
            . ") - explain that before trusting it.\n";
    }
} else {
    $report .= "\nAPCu — not reported by this host: the deployment predates kernelPerfProbeApcu(),\n";
    $report .= "so whether a cross-request cache can live here is still unmeasured.\n";
}

$report .= "\nA median without its range is not evidence. Compare median AND range between two runs;\n";
$report .= "if the ranges overlap, the difference is not established.\n";

echo $report;

// --out exists so a run does not have to be repeated to be read: the numbers land in a file the
// same way they land on the terminal. Contains no credentials - only phase timings.
if ($out !== null && $out !== '') {
    if (@file_put_contents($out, $report) === false) {
        fwrite(STDERR, "perf-sample: could not write {$out}\n");
        exit(2);
    }
    fwrite(STDERR, "written: {$out}\n");
}
