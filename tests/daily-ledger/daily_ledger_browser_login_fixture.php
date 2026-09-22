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
 *   php tests/daily-ledger/daily_ledger_browser_login_fixture.php <username> [tenant_id]
 *
 * Output:
 *   the account's full_name on stdout, exit 0
 *   nothing, exit 1 when the account does not exist / is inactive / has no name
 *   exit 2 on bad usage
 *
 * This script never writes: it is a lookup, not a seed. Seeding fixture accounts
 * is database/seeds/browser_environment.php and is a separate, destructive step.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/browser-login-fixture';

$app = kernelCliBootstrap($basePath);

$username = trim((string)($argv[1] ?? ''));
$tenantId = (int)($argv[2] ?? 207);
if ($username === '') {
    fwrite(STDERR, "Usage: php daily_ledger_browser_login_fixture.php <username> [tenant_id]\n");
    exit(2);
}

try {
    $db = $app->dbForTenant($tenantId);
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
