<?php

declare(strict_types=1);

/**
 * Daily Ledger — a shared branch account tracks its CURRENT holder (owner Option B).
 *
 * Locks in the contract .ai/shared-account-latest-holder-contract.md:
 *   AC1  a shared branch account (cashier) lets the typed name update the profile on
 *        every login, so it reflects the latest person who used it (real HTTP logins)
 *   AC2  a personal account (admin) keeps the empty-only guard: a different name typed
 *        at login leaves the stored name unchanged, with the refusal warning
 *   AC3  the per-event {actor_name, actor_username} stamp is frozen: renaming the
 *        shared profile does NOT rewrite an earlier audit row
 *   AC4  the role rule is an explicit, commented list; production_in_charge is NOT in
 *        it (unconfirmed), and the empty-capture rule still exists for personal accounts
 *
 * Tenant 207 (baronledger). Every probe account is high-id and removed in finally.
 * The suite never touches the live accounts shiela_baina / admin_view.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-shared-account-latest-holder', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// These are the log lines the live login endpoint is EXPECTED to write for this
// suite. Anything else added to app.log during the run is an unexpected offender
// and fails the harness log check.
$h->allowLogLines(
    'daily-ledger auth full_name updated for shared account',
    'daily-ledger auth full_name overwrite refused',
    'daily-ledger auth full_name persisted',
    'capability.call',
    'slow_request'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers.php');

$h->section('AC4: the role rule is an explicit, commented list');

$roleSource = (string)file_get_contents($base . '/modules/daily-ledger/helpers.php');
$sharedRoles = dl_sharedBranchAccountRoles();

echo 'ACCEPTANCE_SHARED_ROLES=' . json_encode($sharedRoles, JSON_UNESCAPED_SLASHES) . "\n";

$h->test('dl_sharedBranchAccountRoles() exists and returns exactly cashier',
    $sharedRoles === ['cashier'],
    json_encode($sharedRoles, JSON_UNESCAPED_SLASHES));
$h->test('admin is NOT treated as a shared account',
    !in_array('admin', $sharedRoles, true));
$h->test('viewer is NOT treated as a shared account',
    !in_array('viewer', $sharedRoles, true));
$h->test('production_in_charge is NOT treated as a shared account (unconfirmed, ask)',
    !in_array('production_in_charge', $sharedRoles, true));
$h->test('the role rule is commented as unconfirmed for production_in_charge',
    str_contains($roleSource, 'production_in_charge')
    && str_contains($roleSource, 'not been confirmed'));

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$sharedId = 997801;
$personalId = 997802;
$sharedUsername = 'Cashier-ProbeSharedAM-' . $sharedId;
$personalUsername = 'probe-shared-admin-' . $personalId;
$password = 'Probe!Shared2031';
$personalStored = 'Admin Probe Stored';

$cleanup = static function () use ($db, $sharedId, $personalId, $sharedUsername, $personalUsername): void {
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$sharedId, $personalId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?) OR username IN (?, ?)')
        ->execute([$sharedId, $personalId, $sharedUsername, $personalUsername]);
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id IN (?, ?)')->execute([$sharedId, $personalId]);
    $db->prepare("DELETE FROM audit_logs WHERE entity_id IN ('EVT-SHARED-1')")->execute();
};
$cleanup();

// Seed: the shared cashier starts with an EMPTY name (a brand-new branch account);
// the personal admin already has an established name that must be protected.
$db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "cashier", 1)')
    ->execute([$sharedId, $sharedUsername, password_hash($password, PASSWORD_BCRYPT), '']);
$db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, "admin", 1)')
    ->execute([$personalId, $personalUsername, password_hash($password, PASSWORD_BCRYPT), $personalStored]);

$storedSharedName = static function () use ($db, $sharedId): string {
    $stmt = $db->prepare('SELECT full_name FROM dl_users WHERE id = ? LIMIT 1');
    $stmt->execute([$sharedId]);
    return (string)$stmt->fetchColumn();
};
$storedPersonalName = static function () use ($db, $personalId): string {
    $stmt = $db->prepare('SELECT full_name FROM dl_users WHERE id = ? LIMIT 1');
    $stmt->execute([$personalId]);
    return (string)$stmt->fetchColumn();
};
$logLines = static function (string $needle) use ($base): array {
    $path = $base . '/storage/logs/app.log';
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, $needle)));
};

$httpBase = rtrim((string)(getenv('TEST_BASE_URL') ?: 'http://baronledger.test'), '/');
$httpLogin = static function (string $url, string $username, string $fullName, string $password): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'username' => $username,
            'full_name' => $fullName,
            'password' => $password,
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    $body = is_string($raw) ? substr($raw, $headerSize) : '';
    return ['status' => $status, 'body' => $body, 'error' => $error];
};

$httpGet = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    return ['status' => $status, 'error' => $error, 'raw' => is_string($raw) ? $raw : ''];
};

$reachable = $httpGet($httpBase . '/daily-ledger/login');
if (($reachable['status'] ?? 0) === 0) {
    $h->skip('real HTTP login proof', 'no live web server reachable at ' . $httpBase . ' (' . $reachable['error'] . ').');
} else {
    $loginUrl = $httpBase . '/daily-ledger/auth/login';

    // ─── AC1: shared branch account tracks the latest holder ────────────
    $h->section('AC1: shared cashier account tracks the latest holder (real HTTP)');

    $sharedLogins = [
        ['Maria Santos', 'Maria Santos', 'capture'],
        ['Jose Reyes', 'Jose Reyes', 'latest wins'],
        ['Ana Cruz', 'Ana Cruz', 'latest wins'],
    ];
    foreach ($sharedLogins as $index => [$entered, $expectedStored, $why]) {
        $response = $httpLogin($loginUrl, $sharedUsername, $entered, $password);
        usleep(150000); // let the web process flush the appended log line
        $body = json_decode($response['body'], true);
        $stored = $storedSharedName();
        $infoLines = $logLines('daily-ledger auth full_name updated for shared account');
        $lastInfo = $infoLines !== [] ? $infoLines[count($infoLines) - 1] : '';
        echo 'ACCEPTANCE_SHARED_LOGIN_' . ($index + 1) . '=' . json_encode([
            'status' => $response['status'],
            'ok' => is_array($body) ? ($body['ok'] ?? null) : null,
            'entered' => $entered,
            'stored' => $stored,
            'expected' => $expectedStored,
            'why' => $why,
        ], JSON_UNESCAPED_SLASHES) . "\n";
        echo 'ACCEPTANCE_SHARED_LOGIN_' . ($index + 1) . '_LOG=' . $lastInfo . "\n";
        $h->test(
            sprintf('shared login %d ("%s") returns HTTP 200 ok=true', $index + 1, $entered),
            $response['status'] === 200 && is_array($body) && ($body['ok'] ?? false) === true,
            json_encode(['status' => $response['status'], 'body' => $response['body']], JSON_UNESCAPED_SLASHES)
        );
        $h->test(
            sprintf('stored name after shared login %d is "%s" (%s)', $index + 1, $expectedStored, $why),
            $stored === $expectedStored,
            json_encode(['stored' => $stored, 'expected' => $expectedStored], JSON_UNESCAPED_SLASHES)
        );
        $h->test(
            sprintf('shared login %d logs an info line with previous and new name', $index + 1),
            $lastInfo !== ''
            && str_contains($lastInfo, $sharedUsername)
            && str_contains($lastInfo, '"new_full_name":"' . $expectedStored . '"'),
            $lastInfo
        );
    }

    // ─── AC2: personal account is still protected ───────────────────────
    $h->section('AC2: personal admin account refuses a different name (real HTTP)');

    $intruder = 'Intruder Rewrite';
    $response = $httpLogin($loginUrl, $personalUsername, $intruder, $password);
    usleep(150000);
    $body = json_decode($response['body'], true);
    $stored = $storedPersonalName();
    $refusals = $logLines('daily-ledger auth full_name overwrite refused');
    $lastRefusal = $refusals !== [] ? $refusals[count($refusals) - 1] : '';
    echo 'ACCEPTANCE_PERSONAL_LOGIN=' . json_encode([
        'status' => $response['status'],
        'ok' => is_array($body) ? ($body['ok'] ?? null) : null,
        'entered' => $intruder,
        'stored' => $stored,
        'expected' => $personalStored,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_PERSONAL_LOGIN_LOG=' . $lastRefusal . "\n";

    $h->test('personal admin login still succeeds (HTTP 200 ok=true)',
        $response['status'] === 200 && is_array($body) && ($body['ok'] ?? false) === true,
        json_encode(['status' => $response['status'], 'body' => $response['body']], JSON_UNESCAPED_SLASHES));
    $h->test('personal admin stored name is unchanged by the typed name',
        $stored === $personalStored,
        json_encode(['stored' => $stored, 'expected' => $personalStored], JSON_UNESCAPED_SLASHES));
    $h->test('personal admin refusal is logged with stored and entered values',
        $lastRefusal !== ''
        && str_contains($lastRefusal, '"stored_full_name":"' . $personalStored . '"')
        && str_contains($lastRefusal, '"entered_full_name":"' . $intruder . '"'),
        $lastRefusal);

    // ─── AC3: history is immune to the shared rename ────────────────────
    $h->section('AC3: an earlier audit row keeps its per-event name across a rename');

    // The profile currently holds "Ana Cruz" (from AC1). Stamp an event with it.
    $db->prepare("UPDATE dl_users SET full_name = 'Ana Cruz' WHERE id = ?")->execute([$sharedId]);
    app()->setUser([
        'sub' => 'cashier:' . $sharedId,
        'id' => $sharedId,
        'username' => $sharedUsername,
        'name' => 'Ana Cruz',
        'full_name' => 'Ana Cruz',
        'role' => 'cashier',
        'source' => 'daily-ledger',
    ]);
    $ctx->audit('field_update', null, 'probe-shared', 'EVT-SHARED-1', null, ['note' => 'holder at event time']);

    // A later login through the shared account changes the profile to a new holder.
    $response = $httpLogin($loginUrl, $sharedUsername, 'New Holder After Event', $password);
    usleep(150000);
    $storedAfterRename = $storedSharedName();

    $frozenRow = $db->query("SELECT metadata_json FROM audit_logs WHERE entity_id = 'EVT-SHARED-1' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $frozenMeta = json_decode((string)$frozenRow, true);

    echo 'ACCEPTANCE_HISTORY=' . json_encode([
        'profile_now' => $storedAfterRename,
        'event_metadata' => $frozenRow,
    ], JSON_UNESCAPED_SLASHES) . "\n";

    $h->test('the later shared login changed the profile to the new holder',
        $storedAfterRename === 'New Holder After Event',
        json_encode(['stored' => $storedAfterRename], JSON_UNESCAPED_SLASHES));
    $h->test('the earlier audit row still reports the name used at event time',
        is_array($frozenMeta) && ($frozenMeta['actor_name'] ?? null) === 'Ana Cruz',
        (string)$frozenRow);
    $h->test('the earlier audit row does NOT report the new holder name',
        is_array($frozenMeta) && ($frozenMeta['actor_name'] ?? null) !== 'New Holder After Event',
        (string)$frozenRow);
}

// Always run cleanup, even if HTTP was skipped.
$cleanup();

// Prove the probes are gone (the suite never touches shiela_baina / admin_view).
$h->section('Fixtures cleaned up');
$remaining = (int)$db->query('SELECT COUNT(*) FROM dl_users WHERE id IN (' . $sharedId . ', ' . $personalId . ')')->fetchColumn();
$h->test('probe accounts are removed', $remaining === 0, (string)$remaining);

$h->done();
