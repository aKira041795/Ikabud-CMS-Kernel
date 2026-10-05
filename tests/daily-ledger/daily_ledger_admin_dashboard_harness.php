#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — admin dashboard render harness.
 *
 * Renders the REAL admin dashboard (templates/modules/daily-ledger/admin/dashboard.disyl)
 * through handleAdminDashboard() with a minted admin token, so a test can assert
 * that the admin device list actually SHOWS a stranded device's enrollment state
 * and refusal reason — not merely that the query returns the columns.
 *
 * Usage: php daily_ledger_admin_dashboard_harness.php [actor-id] [role]
 *
 * Tenant 207 (baron-001). LOCAL DEV ONLY.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/dashboard';
$_SERVER['REQUEST_METHOD'] = 'GET';

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

$actorId = (int)($argv[1] ?? 1);
$role = (string)($argv[2] ?? 'admin');

$token = app()->jwt()->generate([
    'sub' => $role . ':' . $actorId,
    'id' => $actorId,
    'username' => 'dashboard-' . $role,
    'name' => 'Dashboard ' . ucfirst($role),
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

handleAdminDashboard();
