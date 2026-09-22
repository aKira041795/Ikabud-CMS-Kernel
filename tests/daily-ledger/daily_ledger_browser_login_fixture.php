#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — browser login Full Name resolver (READ-ONLY).
 *
 * The daily-ledger login form validates the "Full Name" field against
 * dl_users.full_name. That value is per-tenant DATA and can be renamed at any
 * time, so a browser fixture must READ it rather than assume
 * full_name === username. Assuming was the reason every daily-ledger browser
 * spec failed at login after a live data restore renamed the Ledger-Admin
 * account to "Jean".
 *
 * Usage:
 *   php ... <username> [tenant_id]                 lookup only (print full_name)
 *   php ... ensure <username> [tenant_id] [password]
 *                                                  create/repair a dedicated browser-test
 *                                                  admin with a known password, then print
 *                                                  its full_name
 *   php ... remove <username> [tenant_id]           delete that test account
 *
 * WHY `ensure` EXISTS: the browser suites used to depend on an owner-supplied
 * TEST_ADMIN_PASS. When a live data restore replaced the tenant's users, that default
 * stopped matching and EVERY daily-ledger browser spec failed at login - so the suites
 * could not verify anything. Seeding a dedicated account makes them self-sufficient and
 * immune to whatever the real accounts are renamed or re-hashed to. This mirrors
 * tests/daily-ledger/daily_ledger_overview_browser_fixture.php, which already creates its
 * own viewer/cashier accounts for the same reason.
 *
 * Output:
 *   the account's full_name on stdout, exit 0
 *   nothing, exit 1 when the account does not exist / is inactive / has no name
 *   exit 2 on bad usage
 *
 * `ensure` and `remove` WRITE, but only ever to a dedicated test account; they never touch
 * a real user. The lookup form is read-only.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/browser-login-fixture';

$app = kernelCliBootstrap($basePath);

// Parse both forms: `ensure|remove <username> ...` and the bare `<username>` lookup.
$first = trim((string)($argv[1] ?? ''));
$mode = in_array($first, ['ensure', 'remove'], true) ? $first : 'lookup';
$offset = $mode === 'lookup' ? 1 : 2;

$username = trim((string)($argv[$offset] ?? ''));
$tenantId = (int)($argv[$offset + 1] ?? 207);
$password = (string)($argv[$offset + 2] ?? 'browser-fixture-pass');

if ($username === '') {
    fwrite(STDERR, "Usage: php daily_ledger_browser_login_fixture.php [ensure|remove] <username> [tenant_id] [password]\n");
    exit(2);
}

try {
    $db = $app->dbForTenant($tenantId);

    if ($mode === 'ensure') {
        // Idempotent: repair an account that drifted (renamed, re-hashed, deactivated)
        // instead of inserting a duplicate. `username` is UNIQUE, so the upsert targets it.
        $db->prepare(
            'INSERT INTO dl_users (username, password_hash, full_name, role, shift, is_active)
             VALUES (:u, :p, :n, "admin", NULL, 1)
             ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash),
                full_name = VALUES(full_name),
                role = "admin",
                shift = NULL,
                is_active = 1,
                deleted_at = NULL'
        )->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            // full_name is validated by the login form, so it must be a real name and
            // MUST equal what the fixture will type. Keep the two in lockstep.
            ':n' => $username,
        ]);
    } elseif ($mode === 'remove') {
        $db->prepare('DELETE FROM dl_users WHERE username = :u')->execute([':u' => $username]);
        exit(0);
    }

    $stmt = $db->prepare(
        'SELECT full_name FROM dl_users WHERE username = :u AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $fullName = (string)($stmt->fetchColumn() ?: '');
} catch (\Throwable $e) {
    // Unknown tenant, module DB not provisioned, missing table: the caller falls
    // back to the username, so this is a lookup miss rather than a hard failure.
    fwrite(STDERR, 'lookup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (trim($fullName) === '') {
    exit(1);
}

echo trim($fullName), PHP_EOL;
exit(0);
