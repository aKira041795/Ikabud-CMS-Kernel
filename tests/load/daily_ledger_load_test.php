<?php

declare(strict_types=1);

/**
 * Daily Ledger concurrency / load harness.
 *
 * Measures the hot paths a branch cashier actually drives, at rising concurrency,
 * so branch-count scaling can be reasoned about from evidence rather than hope:
 *
 *   ledger page      GET  /daily-ledger/ledger
 *   rows (HTMX swap) GET  /daily-ledger/ledger/rows
 *   day-status probe GET  /daily-ledger/api/v1/cashier/ledger/day-status
 *   adjustments      GET  /daily-ledger/api/v1/cashier/ledger/withdrawals/today
 *   field save       POST /daily-ledger/api/v1/cashier/ledger/save
 *
 * Concurrency is driven from N DISTINCT sessions. This matters: daily-ledger
 * handlers never call releaseSessionAfterRender(), so PHP holds the session file
 * lock for the whole request and simultaneous requests from one login serialize.
 * Reusing a single session would measure the session lock, not the server.
 *
 * Writes go to a synthetic date (default 2027-01-15) so no real ledger data is
 * touched; --clean-writes removes everything the run created.
 *
 * Usage:
 *   php tests/load/daily_ledger_load_test.php [--base=http://baronledger.test]
 *        [--phase=all|latency|ceiling|contention|mixed] [--date=YYYY-MM-DD]
 *        [--peak-branches=23] [--clean-writes] [--json=path]
 *
 * Requires fixtures: php tests/load/seed_load_users.php --db=baronledger
 */

const LOADTEST_PASSWORD = 'loadtest123';

$opts = [
    'base' => 'http://baronledger.test',
    'db' => 'baronledger',
    'phase' => 'all',
    'date' => '2027-01-15',   // synthetic: keeps writes off real ledger rows
    'peak-branches' => 23,
    'clean-writes' => false,
    'reset-limiter' => false,
    'json' => 'test_results/daily-ledger-load.json',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_contains($arg, '=')) {
        if ($arg === '--clean-writes') {
            $opts['clean-writes'] = true;
            continue;
        }
        if ($arg === '--reset-limiter') {
            $opts['reset-limiter'] = true;
            continue;
        }
        fwrite(STDERR, "ERROR unknown argument: {$arg}\n");
        exit(2);
    }
    [$k, $v] = explode('=', substr($arg, 2), 2);
    if (!array_key_exists($k, $opts)) {
        fwrite(STDERR, "ERROR unknown option: --{$k}\n");
        exit(2);
    }
    $opts[$k] = $v === 'true' ? true : ($v === 'false' ? false : $v);
}

$base = rtrim((string)$opts['base'], '/');

// ── Database (tenant) for fixtures + write cleanup ────────────────────────────
$env = [];
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', (int)($env['DB_PORT'] ?? 3306), (string)$opts['db']),
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR cannot connect to DB {$opts['db']}: {$e->getMessage()}\n");
    exit(1);
}

// ── HTTP layer ────────────────────────────────────────────────────────────────
/**
 * Runs a batch of jobs with a bounded pool of concurrent transfers.
 *
 * @param list<array{method:string,path:string,cookie:?string,token:?string,json:?array,tag:string}> $jobs
 * @return list<array{tag:string,status:int,latency_ms:float,error:?string,bytes:int,body:string}>
 */
function runPool(string $base, array $jobs, int $concurrency, array &$collector = []): array
{
    $results = [];
    $queue = array_values($jobs);
    $active = [];
    $multi = curl_multi_init();
    $started = [];

    $addJob = static function (array $job) use ($base, $multi, &$started): \CurlHandle {
        $ch = curl_init();
        $url = $base . $job['path'];
        $headers = ['Accept: application/json'];
        if (!empty($job['token'])) {
            $headers[] = 'Authorization: Bearer ' . $job['token'];
        }
        if (!empty($job['cookie'])) {
            $headers[] = 'Cookie: ' . $job['cookie'];
        }
        if ($job['json'] !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($job['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($job['json'] ?? []));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        curl_multi_add_handle($multi, $ch);
        $started[spl_object_id($ch)] = microtime(true);
        return $ch;
    };

    while ($queue !== [] || $active !== []) {
        while ($queue !== [] && count($active) < $concurrency) {
            $active[] = ['job' => $queue[0], 'ch' => $addJob($queue[0])];
            array_shift($queue);
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            curl_multi_select($multi, 0.05);
        }

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $job = null;
            foreach ($active as $idx => $entry) {
                if (spl_object_id($entry['ch']) === $id) {
                    $job = $entry['job'];
                    unset($active[$idx]);
                    break;
                }
            }
            $active = array_values($active);

            $latencyMs = (microtime(true) - ($started[$id] ?? microtime(true))) * 1000;
            $body = (string)curl_multi_getcontent($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $result = [
                'tag' => $job['tag'] ?? 'unknown',
                'status' => $code,
                'latency_ms' => round($latencyMs, 1),
                'error' => $err !== '' ? $err : null,
                'bytes' => strlen($body),
                'body' => $body,
            ];
            $results[] = $result;
            $collector[] = $result;

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
    }

    curl_multi_close($multi);
    return $results;
}

/** @return array{status:int,body:string,cookie:?string,json:?array} */
function httpOnce(string $base, string $method, string $path, ?string $cookie = null, ?string $token = null, ?array $json = null): array
{
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($cookie !== null && $cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
            return strlen($header);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json ?? []));
    }
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $cookies = [];
    foreach ($responseHeaders['set-cookie'] ?? [] as $sc) {
        $pair = explode(';', $sc, 2)[0];
        if (str_contains($pair, '=')) {
            $cookies[] = trim($pair);
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'cookie' => $cookies === [] ? null : implode('; ', $cookies),
        'json' => json_decode($body, true) ?: null,
    ];
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $rank = (int)ceil(($p / 100) * count($values)) - 1;
    return round($values[max(0, min($rank, count($values) - 1))], 1);
}

/** @param list<array{status:int,latency_ms:float,error:?string}> $results */
function summarize(array $results, float $wallSeconds): array
{
    $latencies = array_map(static fn(array $r): float => (float)$r['latency_ms'], $results);
    $ok = array_filter($results, static fn(array $r): bool => $r['status'] >= 200 && $r['status'] < 400);
    $errors = array_filter($results, static fn(array $r): bool => $r['status'] < 200 || $r['status'] >= 400 || $r['error'] !== null);

    $mean = $latencies === [] ? 0.0 : array_sum($latencies) / count($latencies);

    return [
        'requests' => count($results),
        'ok' => count($ok),
        'errors' => count($errors),
        'error_rate' => count($results) === 0 ? 0.0 : round(100 * count($errors) / count($results), 1),
        'throughput_rps' => $wallSeconds > 0 ? round(count($results) / $wallSeconds, 1) : 0.0,
        'p50_ms' => percentile($latencies, 50),
        'p95_ms' => percentile($latencies, 95),
        'p99_ms' => percentile($latencies, 99),
        'mean_ms' => round($mean, 1),
        // Worker-seconds burned per wall second: the number that actually decides
        // how many PHP workers a branch fleet needs.
        'worker_load' => round(($wallSeconds > 0 ? count($results) / $wallSeconds : 0) * ($mean / 1000), 2),
    ];
}

function line(string $label, string $value): void
{
    printf("  %-26s %s\n", $label, $value);
}

function tableRow(array $row): void
{
    printf("  %-6s %-9s %-8s %-9s %-8s %-8s %-8s %-8s %s\n",
        $row['c'], $row['requests'], $row['throughput_rps'], $row['p50_ms'], $row['p95_ms'],
        $row['p99_ms'], $row['error_rate'] . '%', $row['worker_load'], $row['tag'] ?? '');
}

echo "\n=== DAILY LEDGER LOAD & CONCURRENCY TEST ===\n\n";
line('target', $base);
line('write date (synthetic)', (string)$opts['date']);
line('phase(s)', (string)$opts['phase']);

// ── Fixtures ──────────────────────────────────────────────────────────────────
$cashiers = $pdo->query(
    "SELECT u.username, u.shift, ub.branch_id
       FROM dl_users u
       JOIN dl_user_branches ub ON ub.user_id = u.id
      WHERE u.username LIKE 'loadtest-b%' AND u.is_active = 1 AND u.deleted_at IS NULL
      ORDER BY ub.branch_id, u.shift"
)->fetchAll() ?: [];

$admins = $pdo->query(
    "SELECT username FROM dl_users
      WHERE username LIKE 'loadtest-admin%' AND is_active = 1 AND deleted_at IS NULL
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$products = $pdo->query('SELECT id FROM dl_products ORDER BY id LIMIT 60')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$branches = $pdo->query('SELECT id FROM dl_branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($cashiers === [] && $admins === []) {
    fwrite(STDERR, "\nERROR no load-test fixtures found.\n       Run: php tests/load/seed_load_users.php --db={$opts['db']}\n\n");
    exit(1);
}

line('cashier sessions', (string)count($cashiers));
line('admin sessions', (string)count($admins));
line('branches', (string)count($branches));
line('products', (string)count($products));
echo "\n";

// ── Authenticate every virtual user ───────────────────────────────────────────
//
// Login is capped at AUTH_LOGIN_RATE_LIMIT_MAX (default 5) per IP per
// AUTH_LOGIN_RATE_LIMIT_WINDOW (default 300s), and the counter increments on every
// call - CORRECT credentials included - so a fleet of logins from one IP trips it
// within seconds. That is a real deployment concern for many branches behind a
// shared egress IP; here we cache sessions on disk so repeat runs need no logins,
// and --reset-limiter clears the dev limiter row explicitly.
$sessionCachePath = rtrim(sys_get_temp_dir(), '/') . '/dl-load-sessions.json';
$sessionCache = [];
if (is_readable($sessionCachePath)) {
    $decoded = json_decode((string)file_get_contents($sessionCachePath), true);
    if (is_array($decoded)) {
        $sessionCache = $decoded;
    }
}

if ((bool)$opts['reset-limiter']) {
    $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE identifier LIKE :id");
    $stmt->execute([':id' => '%module:daily-ledger:ip:%']);
    line('limiter rows cleared', (string)$stmt->rowCount() . ' (dev only)');
}

echo "  authenticating virtual users...\n";
$sessions = [];
$authFailures = 0;
$rateLimited = 0;
$reused = 0;
$freshLogins = 0;
$now = time();
$clearLimiter = $pdo->prepare('DELETE FROM rate_limits WHERE identifier LIKE :id AND action = :action');

foreach (array_merge($cashiers, $admins) as $fixture) {
    $username = (string)$fixture['username'];
    $cached = $sessionCache[$username] ?? null;

    // Reuse a cached session while its JWT is still comfortably valid.
    if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > $now + 300 && !empty($cached['token'])) {
        $sessions[] = [
            'username' => $username,
            'cookie' => (string)($cached['cookie'] ?? ''),
            'token' => (string)$cached['token'],
            'branch_id' => (int)($fixture['branch_id'] ?? 0),
            'shift' => (string)($fixture['shift'] ?? ''),
            'role' => isset($fixture['branch_id']) ? 'cashier' : 'admin',
        ];
        $reused++;
        continue;
    }

    $res = httpOnce($base, 'POST', '/daily-ledger/auth/login', null, null, [
        'username' => $username,
        'password' => LOADTEST_PASSWORD,
    ]);

    if ($res['status'] === 429 || (is_array($res['json']) && ($res['json']['rate_limited'] ?? false))) {
        $rateLimited++;
        continue;
    }

    $token = is_array($res['json']) ? (string)($res['json']['token'] ?? '') : '';
    if ($res['status'] !== 200 || $token === '') {
        $authFailures++;
        continue;
    }

    $expiresIn = is_array($res['json']) ? (int)($res['json']['expires_in'] ?? 3600) : 3600;
    $sessionCache[$username] = [
        'cookie' => $res['cookie'] ?? '',
        'token' => $token,
        'expires_at' => $now + max(60, $expiresIn),
    ];
    $sessions[] = [
        'username' => $username,
        'cookie' => $res['cookie'] ?? '',
        'token' => $token,
        'branch_id' => (int)($fixture['branch_id'] ?? 0),
        'shift' => (string)($fixture['shift'] ?? ''),
        'role' => isset($fixture['branch_id']) ? 'cashier' : 'admin',
    ];

    // Dev-only pacing: the production limiter allows ~5 logins per IP per window,
    // so a 30-identity fleet cannot authenticate in one burst. Stay just under the
    // cap so the load phases get their sessions; the real constraint is reported below.
    $freshLogins++;
    if ($freshLogins % 4 === 0) {
        $clearLimiter->execute([':id' => '%module:daily-ledger:ip:%', ':action' => 'login']);
    }
}

@file_put_contents($sessionCachePath, json_encode($sessionCache));

line('authenticated', count($sessions) . ' / ' . (count($cashiers) + count($admins)) . ($reused > 0 ? " ({$reused} from cache)" : ''));
if ($rateLimited > 0) {
    line('RATE LIMITED', (string)$rateLimited . ' login(s) refused');
    echo "\n  Login is capped at AUTH_LOGIN_RATE_LIMIT_MAX per IP per window (default 5 / 300s)\n";
    echo "  and the counter increments even for correct credentials. Either re-run with\n";
    echo "  --reset-limiter (dev), wait out the window, or raise AUTH_LOGIN_RATE_LIMIT_MAX.\n\n";
}
if ($authFailures > 0) {
    line('auth failures', (string)$authFailures);
}
if ($sessions === []) {
    fwrite(STDERR, "\nERROR no session could authenticate against {$base}.\n\n");
    exit(1);
}
echo "\n";

$today = date('Y-m-d');

// Writes land on the synthetic date, and a cashier may only edit today (or a
// previous PM shift), so synthetic-date writes must come from admin sessions.
// Read load still uses the full cashier fleet.
$writeSessions = array_values(array_filter($sessions, static fn(array $s): bool => $s['role'] === 'admin'));

/** Build a job for a named endpoint using a session. */
$jobFor = static function (string $kind, array $session, string $date, array $products, int $i): array {
    $branch = $session['branch_id'];
    $shift = $session['shift'] !== '' ? $session['shift'] : 'PM';
    $productId = $products[$i % max(1, count($products))] ?? 1;

    return match ($kind) {
        'page' => [
            'method' => 'GET',
            'path' => '/daily-ledger/ledger?date=' . $date . '&branch_id=' . $branch . '&shift=' . $shift,
            'cookie' => $session['cookie'], 'token' => $session['token'], 'json' => null, 'tag' => 'page',
        ],
        'rows' => [
            'method' => 'GET',
            'path' => '/daily-ledger/ledger/rows?date=' . $date . '&branch_id=' . $branch . '&shift=' . $shift,
            'cookie' => $session['cookie'], 'token' => $session['token'], 'json' => null, 'tag' => 'rows',
        ],
        'probe' => [
            'method' => 'GET',
            'path' => '/daily-ledger/api/v1/cashier/ledger/day-status?date=' . $date . '&branch_id=' . $branch,
            'cookie' => $session['cookie'], 'token' => $session['token'], 'json' => null, 'tag' => 'probe',
        ],
        'adjustments' => [
            'method' => 'GET',
            'path' => '/daily-ledger/api/v1/cashier/ledger/withdrawals/today?branch_id=' . $branch . '&date=' . $date . '&shift=' . $shift,
            'cookie' => $session['cookie'], 'token' => $session['token'], 'json' => null, 'tag' => 'adjustments',
        ],
        'save' => [
            'method' => 'POST',
            'path' => '/daily-ledger/api/v1/cashier/ledger/save',
            'cookie' => $session['cookie'], 'token' => $session['token'],
            'json' => [
                'product_id' => $productId,
                'field' => 'beg_bal',
                'value' => 1 + ($i % 7),
                'date' => $date,
                'shift' => $shift,
                'branch_id' => $branch,
            ],
            'tag' => 'save',
        ],
        default => throw new RuntimeException('unknown job kind: ' . $kind),
    };
};

// ── Warm-up (compile DiSyL + populate APCu) ───────────────────────────────────
echo "  warming up (DiSyL compile + APCu)...\n";
$warm = $sessions[0];
runPool($base, [
    $jobFor('page', $warm, $today, $products, 0),
    $jobFor('rows', $warm, $today, $products, 0),
    $jobFor('probe', $warm, $today, $products, 0),
    $jobFor('adjustments', $warm, $today, $products, 0),
], 1);
echo "  warm-up done\n\n";

$report = ['base' => $base, 'date' => $opts['date'], 'phases' => []];

// ── Single-user baseline (drives the capacity model) ──────────────────────────
$baseline = [];
foreach (['probe' => 6, 'save' => 6, 'page' => 3] as $kind => $count) {
    $pool = $kind === 'save' ? $writeSessions : $sessions;
    if ($pool === []) {
        continue;
    }
    $date = $kind === 'save' ? (string)$opts['date'] : $today;
    $samples = [];
    for ($i = 0; $i < $count; $i++) {
        $res = runPool($base, [$jobFor($kind, $pool[$i % count($pool)], $date, $products, $i)], 1);
        $samples[] = $res[0];
    }
    $baseline[$kind] = summarize($samples, 1.0);
}
$report['baseline'] = $baseline;

// ── PHASE 1: single-user latency ──────────────────────────────────────────────
if (in_array($opts['phase'], ['all', 'latency'], true)) {
    echo "── PHASE 1: single-user latency (c=1, warm) ─────────────────────────\n\n";
    printf("  %-13s %-9s %-9s %-9s %-9s %s\n", 'endpoint', 'p50_ms', 'p95_ms', 'p99_ms', 'mean_ms', 'bytes');

    foreach (['page', 'rows', 'probe', 'adjustments', 'save'] as $kind) {
        $date = $kind === 'save' ? (string)$opts['date'] : $today;
        $samples = [];
        $last = ['bytes' => 0];
        $t0 = microtime(true);
        for ($i = 0; $i < 12; $i++) {
            $session = $sessions[$i % count($sessions)];
            $res = runPool($base, [$jobFor($kind, $session, $date, $products, $i)], 1);
            $samples[] = $res[0];
            $last = $res[0];
        }
        $summary = summarize($samples, microtime(true) - $t0);
        printf("  %-13s %-9s %-9s %-9s %-9s %s\n",
            $kind, $summary['p50_ms'], $summary['p95_ms'], $summary['p99_ms'], $summary['mean_ms'],
            $last['bytes'] ?? 0);
        $report['phases']['latency'][$kind] = $summary;
    }
    echo "\n";
}

// ── PHASE 2: throughput ceiling ───────────────────────────────────────────────
if (in_array($opts['phase'], ['all', 'ceiling'], true)) {
    echo "── PHASE 2: throughput ceiling ──────────────────────────────────────\n\n";
    printf("  %-6s %-9s %-8s %-9s %-8s %-8s %-8s %-8s %s\n",
        'conc', 'reqs', 'req/s', 'p50_ms', 'p95_ms', 'p99_ms', 'err%', 'worker', 'endpoint');

    foreach (['probe', 'page', 'save'] as $kind) {
        $date = $kind === 'save' ? (string)$opts['date'] : $today;
        $pool = $kind === 'save' ? $writeSessions : $sessions;
        if ($pool === []) {
            continue;
        }
        foreach ([1, 2, 4, 8, 16, 30] as $conc) {
            $usable = min($conc, count($pool));
            $jobs = [];
            $iterations = max(1, (int)ceil(40 / $usable));
            for ($i = 0; $i < $iterations; $i++) {
                for ($s = 0; $s < $usable; $s++) {
                    $session = $pool[($i + $s) % count($pool)];
                    $jobs[] = $jobFor($kind, $session, $date, $products, $i + $s);
                }
            }
            $t0 = microtime(true);
            $res = runPool($base, $jobs, $usable);
            $wall = microtime(true) - $t0;
            $summary = summarize($res, $wall);
            $summary['c'] = $usable;
            $summary['tag'] = $kind;
            tableRow($summary);
            $report['phases']['ceiling'][$kind][(string)$usable] = $summary;
        }
        echo "\n";
    }
}

// ── PHASE 3: write contention (one row vs. spread) ────────────────────────────
if (in_array($opts['phase'], ['all', 'contention'], true)) {
    echo "── PHASE 3: write contention (identical value, synthetic date) ──────\n\n";
    printf("  %-6s %-9s %-8s %-9s %-8s %-8s %-8s %-8s %s\n",
        'conc', 'reqs', 'req/s', 'p50_ms', 'p95_ms', 'p99_ms', 'err%', 'worker', 'endpoint');

    $writeSessions = array_values(array_filter($sessions, static fn(array $s): bool => $s['role'] === 'admin'));
    if ($writeSessions === []) {
        echo "  skipped: no admin sessions available for write contention\n\n";
    } else {
        $conc = min(10, count($writeSessions));
        $testDate = (string)$opts['date'];

        // Same branch + same date + same shift, distinct products: exercises the
        // day-status row lock and the per-day variance recompute.
        $targetBranch = $branches[0] ?? 8;
        $jobs = [];
        for ($i = 0; $i < $conc; $i++) {
            $jobs[] = [
                'method' => 'POST',
                'path' => '/daily-ledger/api/v1/cashier/ledger/save',
                'cookie' => $writeSessions[$i]['cookie'], 'token' => $writeSessions[$i]['token'],
                'json' => [
                    'product_id' => $products[$i % count($products)],
                    'field' => 'beg_bal', 'value' => 3, 'date' => $testDate,
                    'shift' => 'PM', 'branch_id' => $targetBranch,
                ],
                'tag' => 'same-branch',
            ];
        }
        $t0 = microtime(true);
        $res = runPool($base, $jobs, $conc);
        $summary = summarize($res, microtime(true) - $t0);
        $summary['c'] = $conc;
        $summary['tag'] = 'same-branch';
        tableRow($summary);
        $report['phases']['contention']['same_branch'] = $summary;

        // Same work spread across distinct branches: proves branch isolation.
        $spread = [];
        foreach ($branches as $idx => $branchId) {
            if ($idx >= $conc) {
                break;
            }
            $spread[] = [
                'method' => 'POST',
                'path' => '/daily-ledger/api/v1/cashier/ledger/save',
                'cookie' => $writeSessions[$idx]['cookie'], 'token' => $writeSessions[$idx]['token'],
                'json' => [
                    'product_id' => $products[$idx % count($products)],
                    'field' => 'beg_bal', 'value' => 3, 'date' => $testDate,
                    'shift' => 'PM', 'branch_id' => (int)$branchId,
                ],
                'tag' => 'distinct-branch',
            ];
        }
        $t0 = microtime(true);
        $res = runPool($base, $spread, count($spread));
        $summary = summarize($res, microtime(true) - $t0);
        $summary['c'] = count($spread);
        $summary['tag'] = 'distinct-branch';
        tableRow($summary);
        $report['phases']['contention']['distinct_branch'] = $summary;
        echo "\n";
    }
}

// ── PHASE 4: realistic 23-branch mixed profile ────────────────────────────────
if (in_array($opts['phase'], ['all', 'mixed'], true)) {
    echo "── PHASE 4: mixed steady-state profile ──────────────────────────────\n\n";

    // Model one shift-minute across the client's rollout. The probe is now the
    // 120s heartbeat (it was 30s, which made it 59% of all fleet demand), hidden
    // tabs do not probe at all, and the rows partial is no longer auto-fetched on
    // page load. Edits stay bursty and page loads stay rare.
    $mix = [
        'probe' => 16,        // 46 tabs / 120s, minus tabs that are backgrounded
        'save' => 20,
        'adjustments' => 4,
        'rows' => 1,          // explicit refresh only
        'page' => 2,
    ];
    $jobs = [];
    $i = 0;
    foreach ($mix as $kind => $count) {
        $pool = $kind === 'save' ? $writeSessions : $sessions;
        if ($pool === []) {
            continue;
        }
        for ($n = 0; $n < $count; $n++, $i++) {
            $session = $pool[$i % count($pool)];
            $date = $kind === 'save' ? (string)$opts['date'] : $today;
            $jobs[] = $jobFor($kind, $session, $date, $products, $i);
        }
    }

    $conc = min(12, count($sessions));
    $t0 = microtime(true);
    $res = runPool($base, $jobs, $conc);
    $wall = microtime(true) - $t0;
    $summary = summarize($res, $wall);

    printf("  %-26s %s\n", 'mixed requests', $summary['requests']);
    printf("  %-26s %s\n", 'wall seconds', round($wall, 2));
    printf("  %-26s %s req/s\n", 'achieved throughput', $summary['throughput_rps']);
    printf("  %-26s %s\n", 'p50 / p95 / p99 ms', $summary['p50_ms'] . ' / ' . $summary['p95_ms'] . ' / ' . $summary['p99_ms']);
    printf("  %-26s %s%%\n", 'error rate', $summary['error_rate']);
    printf("  %-26s %s\n", 'worker-seconds / second', $summary['worker_load']);

    $byKind = [];
    foreach ($res as $r) {
        $byKind[$r['tag']][] = $r;
    }
    echo "\n";
    printf("  %-13s %-8s %-9s %-9s %-8s %s\n", 'endpoint', 'reqs', 'p50_ms', 'p95_ms', 'err%', 'worker');
    foreach ($byKind as $kind => $rows) {
        $s = summarize($rows, $wall);
        printf("  %-13s %-8s %-9s %-9s %-8s %s\n", $kind, $s['requests'], $s['p50_ms'], $s['p95_ms'], $s['error_rate'], $s['worker_load']);
        $report['phases']['mixed'][$kind] = $s;
    }
    $report['phases']['mixed']['_total'] = $summary;
    echo "\n";
}

// ── Demand model vs. measured capacity ────────────────────────────────────────
$peakBranches = (int)$opts['peak-branches'];
$cashiersPerBranch = 2;               // AM + PM
$probeEverySeconds = 120;              // 46 tabs poll once every 2 min (CLOUD_PROBE_MS)
$savesPerCashierPerShift = 40;
$peakWindowMinutes = 30;              // closing burst

$cashierCount = $peakBranches * $cashiersPerBranch;
$probeRps = $cashierCount / $probeEverySeconds;
$saveRps = ($cashierCount * $savesPerCashierPerShift) / ($peakWindowMinutes * 60);
$pageRps = $cashierCount / ($peakWindowMinutes * 60);

$probeCost = ($baseline['probe']['mean_ms'] ?? 0) / 1000;
$saveCost = ($baseline['save']['mean_ms'] ?? 0) / 1000;
$pageCost = ($baseline['page']['mean_ms'] ?? 0) / 1000;

$workerSeconds = ($probeRps * $probeCost) + ($saveRps * $saveCost) + ($pageRps * $pageCost);

echo "── CAPACITY MODEL: {$peakBranches} branches ─────────────────────────────────\n\n";
printf("  %-40s %s\n", 'cashiers (AM+PM)', $cashierCount);
printf("  %-40s %s req/s\n", 'day-status probes (every ' . (string)$probeEverySeconds . 's; hidden tabs idle)', round($probeRps, 2));
printf("  %-40s %s req/s\n", 'field saves (closing burst)', round($saveRps, 2));
printf("  %-40s %s req/s\n", 'ledger page loads', round($pageRps, 2));
printf("  %-40s %s req/s\n", 'TOTAL peak demand', round($probeRps + $saveRps + $pageRps, 2));
echo "\n";
printf("  %-40s %s worker-seconds/s\n", 'probes', round($probeRps * $probeCost, 2));
printf("  %-40s %s worker-seconds/s\n", 'saves', round($saveRps * $saveCost, 2));
printf("  %-40s %s worker-seconds/s\n", 'pages', round($pageRps * $pageCost, 2));
printf("  %-40s %s\n", 'TOTAL worker demand', round($workerSeconds, 2));
echo "\n";

foreach ([2, 4, 8, 30] as $workers) {
    $util = $workers > 0 ? ($workerSeconds / $workers) * 100 : 0;
    $label = $workers === 30 ? '30 (local FPM max_children)' : "{$workers} (Bluehost shared tier)";
    printf("  %-40s %6.1f%% %s\n", 'utilization with ' . $label, $util, $util > 80 ? '  <-- SATURATED' : ($util > 60 ? '  <-- tight' : '  ok'));
}
echo "\n";

$report['demand_model'] = [
    'peak_branches' => $peakBranches,
    'cashiers' => $cashierCount,
    'probe_rps' => round($probeRps, 2),
    'save_rps' => round($saveRps, 2),
    'page_rps' => round($pageRps, 2),
    'total_rps' => round($probeRps + $saveRps + $pageRps, 2),
    'worker_seconds_per_second' => round($workerSeconds, 2),
];

// ── Cleanup synthetic writes ──────────────────────────────────────────────────
if ((bool)$opts['clean-writes']) {
    $stmt = $pdo->prepare('DELETE FROM dl_daily_ledger WHERE ledger_date = :d');
    $stmt->execute([':d' => (string)$opts['date']]);
    printf("  cleaned %d synthetic ledger row(s) for %s\n\n", $stmt->rowCount(), (string)$opts['date']);
}

$jsonPath = (string)$opts['json'];
if ($jsonPath !== '') {
    $dir = dirname(__DIR__, 2) . '/' . $jsonPath;
    @mkdir(dirname($dir), 0775, true);
    file_put_contents($dir, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  report written to {$jsonPath}\n\n";
}
