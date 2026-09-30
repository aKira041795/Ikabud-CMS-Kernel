#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — offline replay runtime harness.
 *
 * Boots the kernel, resolves tenant 207, mints a daily-ledger admin JWT, serves
 * the supplied batch payload as php://input, and invokes the REAL
 * apiOfflineReconcile() entry point. stdout is the JSON the sync endpoint would
 * have returned, so a caller can assert on what actually happened to the
 * offline operation rather than on a helper's decision.
 *
 * Usage:
 *   php daily_ledger_offline_replay_harness.php <payload-file> [branch_id]
 *
 * The payload file is the reconcile request body:
 *   {"device_id":"...","enrollment_id":"...","operations":[...]}
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$payloadFile = (string)($argv[1] ?? '');
if ($payloadFile === '' || !is_file($payloadFile)) {
    fwrite(STDERR, "Usage: php daily_ledger_offline_replay_harness.php <payload-file> [branch_id]\n");
    exit(2);
}
$rawPayload = (string)file_get_contents($payloadFile);
$branchId = (int)($argv[2] ?? 0);

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/offline/reconcile';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
if ($branchId > 0) {
    $_GET['branch_id'] = (string)$branchId;
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/helpers/entity-views.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers-pos.php';
require_once $basePath . '/modules/daily-ledger/handlers-offline.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

// Mint a real access token directly and present it the way the browser does,
// so dlCurrentUser()/dlUserFromRequest() resolve the actor.
$token = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'prod-rizal',
    'name' => 'Noah Omamalin',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

// Serve the batch payload through the kernel's CLI-only raw-input hook, so
// $ctx->input() parses it exactly as it would a live JSON request body.
\Ikabud\Kernel\Http\Input::setRawInputForTesting($rawPayload);

apiOfflineReconcile();
