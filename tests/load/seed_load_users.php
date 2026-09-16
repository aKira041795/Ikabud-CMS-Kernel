<?php

declare(strict_types=1);

/**
 * Seeds deterministic load-test identities for tests/load/daily_ledger_load_test.php
 *
 * Concurrency cannot be measured from one login: PHP holds the session file lock for
 * the whole request (daily-ledger handlers never call releaseSessionAfterRender), so
 * simultaneous requests from a single session serialize. Every virtual user therefore
 * needs its own session.
 *
 * Creates:
 *   - per branch: a cashier pair  loadtest-b<branchId>-{AM,PM}   (read load + today's writes)
 *   - a pool:                     loadtest-admin<n>              (many distinct sessions
 *     aimed at ONE branch/date/shift, which is the only way to exercise the
 *     day-status row lock and the per-day variance recompute under contention)
 *
 * Idempotent: re-running resets the password/branch mapping rather than failing.
 *
 * Usage:
 *   php tests/load/seed_load_users.php [--db=baronledger] [--admins=10] [--clean]
 *
 * NOTE: dev/CI fixture only. Refuses to run unless the target DB is a known
 * non-production dev database, so it can never seed test logins into a live tenant.
 */

const LOADTEST_PASSWORD = 'loadtest123';
const LOADTEST_EMAIL_DOMAIN = 'loadtest.invalid';

$argvList = array_slice($argv, 1);
$dbName = 'baronledger';
$adminCount = 10;
$clean = false;
$force = false;
$host = strtolower((string)(getenv('LOADTEST_HOST') ?: 'localhost'));

foreach ($argvList as $arg) {
    if ($arg === '--clean') {
        $clean = true;
        continue;
    }
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if (str_starts_with($arg, '--db=')) {
        $dbName = substr($arg, strlen('--db='));
        continue;
    }
    if (str_starts_with($arg, '--admins=')) {
        $adminCount = max(1, (int)substr($arg, strlen('--admins=')));
        continue;
    }
    if (str_starts_with($arg, '--host=')) {
        $host = strtolower(substr($arg, strlen('--host=')));
        continue;
    }
    fwrite(STDERR, "ERROR unknown argument: {$arg}\n");
    exit(2);
}

// Guard: never seed predictable test logins into a live tenant by accident.
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !$force) {
    fwrite(STDERR, "ERROR refusing to seed load-test logins against a non-local host ({$host}).\n");
    fwrite(STDERR, "      Re-run with --force only if you are certain this is a disposable database.\n");
    exit(2);
}

// ── Connect to the tenant database ────────────────────────────────────────────
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

$host = $env['DB_HOST'] ?? 'localhost';
$port = (int)($env['DB_PORT'] ?? 3306);
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR cannot connect to {$dbName}: {$e->getMessage()}\n");
    exit(1);
}

echo "\n=== DAILY LEDGER LOAD-TEST FIXTURES ({$dbName}) ===\n\n";

// ── Cleanup ───────────────────────────────────────────────────────────────────
if ($clean) {
    $ids = $pdo->query("SELECT id FROM dl_users WHERE username LIKE 'loadtest-%'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM dl_user_branches WHERE user_id IN ({$in})");
        $pdo->exec("DELETE FROM dl_users WHERE id IN ({$in})");
        echo "  removed " . count($ids) . " load-test user(s) and their branch mappings\n";
    } else {
        echo "  no load-test users found\n";
    }
    echo "\nDone.\n";
    exit(0);
}

// ── Branch list ───────────────────────────────────────────────────────────────
$branches = $pdo->query('SELECT id, name FROM dl_branches ORDER BY id')->fetchAll() ?: [];
if ($branches === []) {
    fwrite(STDERR, "ERROR no branches in dl_branches — nothing to seed against.\n");
    exit(1);
}

$findUser = $pdo->prepare('SELECT id FROM dl_users WHERE username = :u LIMIT 1');
$insertUser = $pdo->prepare(
    'INSERT INTO dl_users (username, email, password_hash, full_name, role, shift, is_active)
     VALUES (:username, :email, :hash, :full_name, :role, :shift, 1)'
);
$updateUser = $pdo->prepare(
    'UPDATE dl_users
        SET password_hash = :hash, full_name = :full_name, role = :role, shift = :shift,
            is_active = 1, deleted_at = NULL
      WHERE id = :id'
);
$clearBranches = $pdo->prepare('DELETE FROM dl_user_branches WHERE user_id = :id');
$mapBranch = $pdo->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:id, :branch)');

/** @param array{username:string,full_name:string,role:string,shift:?string} $identity */
$upsertUser = function (array $identity) use ($pdo, $findUser, $insertUser, $updateUser): int {
    $hash = password_hash(LOADTEST_PASSWORD, PASSWORD_BCRYPT);

    $findUser->execute([':u' => $identity['username']]);
    $existing = $findUser->fetchColumn();

    if ($existing !== false) {
        $updateUser->execute([
            ':hash' => $hash,
            ':full_name' => $identity['full_name'],
            ':role' => $identity['role'],
            ':shift' => $identity['shift'],
            ':id' => (int)$existing,
        ]);
        return (int)$existing;
    }

    $insertUser->execute([
        ':username' => $identity['username'],
        ':email' => $identity['username'] . '@' . LOADTEST_EMAIL_DOMAIN,
        ':hash' => $hash,
        ':full_name' => $identity['full_name'],
        ':role' => $identity['role'],
        ':shift' => $identity['shift'],
    ]);
    return (int)$pdo->lastInsertId();
};

// ── Cashier pairs, one per branch ─────────────────────────────────────────────
$cashiers = [];
$created = 0;
foreach ($branches as $b) {
    $branchId = (int)$b['id'];
    $branchName = (string)$b['name'];
    foreach (['AM', 'PM'] as $shift) {
        $username = 'loadtest-b' . $branchId . '-' . $shift;
        $id = $upsertUser([
            'username' => $username,
            'full_name' => 'Load Test ' . $branchName . ' ' . $shift,
            'role' => 'cashier',
            'shift' => $shift,
        ]);
        $clearBranches->execute([':id' => $id]);
        $mapBranch->execute([':id' => $id, ':branch' => $branchId]);
        $cashiers[] = ['username' => $username, 'branch_id' => $branchId, 'shift' => $shift];
        $created++;
    }
}

// ── Admin pool (many distinct sessions on one branch/date/shift) ──────────────
$admins = [];
for ($i = 1; $i <= $adminCount; $i++) {
    $username = 'loadtest-admin' . $i;
    $upsertUser([
        'username' => $username,
        'full_name' => 'Load Test Admin ' . $i,
        'role' => 'admin',
        'shift' => null,
    ]);
    $admins[] = ['username' => $username];
    $created++;
}

echo '  branches:  ' . count($branches) . ' (' . implode(', ', array_map(static fn($b) => (string)$b['name'], $branches)) . ")\n";
echo '  cashiers:  ' . count($cashiers) . " (AM/PM per branch)\n";
echo '  admins:    ' . count($admins) . "\n";
echo '  total:     ' . $created . " load-test identities\n";
echo '  password:  ' . LOADTEST_PASSWORD . "\n\n";
echo "  Run:  php tests/load/daily_ledger_load_test.php --db={$dbName}\n";
echo "  Drop: php tests/load/seed_load_users.php --db={$dbName} --clean\n\n";
