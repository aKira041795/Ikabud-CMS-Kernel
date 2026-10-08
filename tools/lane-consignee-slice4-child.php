#!/usr/bin/env php
<?php
/**
 * Slice 4 acceptance CHILD — renders the Activity page as an admin and prints the HTML.
 *
 * WHY A CHILD PROCESS: rendering needs a request context, and a handler may call $ctx->json() which
 * EXITS the process. In-process calls silently false-pass a gate (measured on the slice-2 gate, which
 * returned rc=0 having run none of its assertions). One render per process.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$actorId = (int)($argv[1] ?? 0);
$role = (string)($argv[2] ?? 'admin');
if ($actorId <= 0) { exit(2); }

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/activity';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['CONTENT_TYPE'] = 'text/html';
$_GET = [];

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$token = app()->jwt()->generate([
    'sub' => $role . ':' . $actorId, 'id' => $actorId, 'username' => 's4gate-' . $actorId,
    'name' => 'S4 Gate Actor', 'role' => $role, 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

ob_start();
try {
    handleAdminActivity();
} catch (\Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, 'render failed: ' . $e->getMessage() . "\n");
    exit(3);
}
echo (string)ob_get_clean();
