#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Business Overview runtime harness.
 *
 * Boots the kernel, resolves tenant 207, mints a daily-ledger JWT for the
 * requested role, then invokes the real GET handler. Used by the focused
 * overview suite to prove role access at runtime (a denied role exits through
 * dlRedirect before "RENDERED" is printed).
 *
 * Usage: php daily_ledger_overview_runtime_harness.php <role> <user_id> [query]
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$role = (string)($argv[1] ?? 'viewer');
$userId = (int)($argv[2] ?? 0);
$query = (string)($argv[3] ?? '');

parse_str($query, $_GET);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview' . ($query !== '' ? '?' . $query : '');

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

$tokens = dl_generateAuthTokens([
    'sub' => $role . ':' . $userId,
    'id' => $userId,
    'username' => 'overview-' . $role,
    'name' => 'Overview ' . $role,
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

handleAdminOverview();
echo "\nRENDERED\n";
