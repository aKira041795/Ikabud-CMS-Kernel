#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — admin variances render harness.
 *
 * Renders the REAL admin variances page (templates/modules/daily-ledger/admin/variances.disyl)
 * through handleAdminVariances() with a minted admin token, so a test can assert
 * that the integrity-notification card actually renders a notification whose
 * finding_type is the new 'closed_without_pm_finalize' enum value.
 *
 * Usage: php daily_ledger_variances_render_harness.php [actor-id] [role]
 *
 * Tenant 207 (baron-001). LOCAL DEV ONLY.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/variances';
$_SERVER['REQUEST_METHOD'] = 'GET';
// A wide (non-single-day) range makes handleAdminVariances() skip
// dl_refreshVariancesForDateView(), which otherwise recomputes variances for
// the whole current day and raises a notification per variance flag. This
// harness must render the page, NOT manufacture variance notifications.
$_GET = ['date_from' => '2000-01-01', 'date_to' => '2000-01-02'];

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
    'username' => 'variances-' . $role,
    'name' => 'Variances ' . ucfirst($role),
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

handleAdminVariances();
