#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — cashier PM-finalize runtime harness.
 *
 * Boots the kernel, resolves tenant 207, mints a daily-ledger JWT for the
 * supplied actor, and invokes the REAL apiFinalizePmShift() entry point.
 * stdout is the JSON the endpoint would have returned (apiFinalizePmShift
 * calls $ctx->json(), which is exit-terminating), so a caller can assert on
 * the real handler outcome rather than a reimplementation.
 *
 * Usage:
 *   php daily_ledger_finalize_pm_harness.php <branch_id> <date> <user_id> [role]
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$branchId = (int)($argv[1] ?? 0);
$date     = (string)($argv[2] ?? '');
$userId   = (int)($argv[3] ?? 0);
$role     = (string)($argv[4] ?? 'cashier');

if ($branchId <= 0 || $date === '' || $userId <= 0) {
    fwrite(STDERR, "Usage: php daily_ledger_finalize_pm_harness.php <branch_id> <date> <user_id> [role]\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/cashier/ledger/finalize-pm';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/helpers/entity-views.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers-offline.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

// Mint a real access token directly and present it the way the browser does,
// so dlCurrentUser()/dlUserFromRequest() resolve the actor.
$subPrefix = $role === 'cashier' ? 'cashier:' : 'admin:';
$token = app()->jwt()->generate([
    'sub' => $subPrefix . $userId,
    'id' => $userId,
    'username' => 'fixture-finalize-' . $role,
    'name' => 'Fixture Finalize ' . ucfirst($role),
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

\Ikabud\Kernel\Http\Input::setRawInputForTesting(json_encode([
    'branch_id' => $branchId,
    'date' => $date,
]));

apiFinalizePmShift();
