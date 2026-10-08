<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$actorId = (int)($argv[1] ?? 0);
$query = json_decode((string)base64_decode((string)($argv[2] ?? ''), true), true);
if ($actorId <= 0 || !is_array($query)) {
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/activity?' . http_build_query($query);
$_GET = $query;
$_REQUEST = $query;

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:' . $actorId,
    'id' => $actorId,
    'username' => 's4-activity-admin',
    'name' => 'Slice 4 Activity Admin',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);

handleAdminActivity();
