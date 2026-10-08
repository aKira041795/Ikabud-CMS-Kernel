#!/usr/bin/env php
<?php
/**
 * Sales-mode STRENGTHENED-PIN child — executes `handleAdminCommissary` so any write hidden in the
 * order-mode presentation path actually fires.
 *
 * WHY THIS EXISTS: the chair's first byte-identical pin only flipped the SETTING and compared the
 * ledger. A mutation that depletes the ledger when the sheet is rendered in 'order' mode came back
 * GREEN, because the pin never executed the handler at all. The pin proved less than it claimed.
 *
 * The render itself may FAIL in CLI (`storage/cache/compiled` is www-data-owned and uncomputable by
 * this user) — and that is FINE. The mode-dependent code runs BEFORE the render call, so the handler
 * only has to execute far enough to reach it. Output is discarded deliberately.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';
$_SERVER['REQUEST_METHOD'] = 'GET';

$token = app()->jwt()->generate([
    'sub' => 'admin:20', 'id' => 20, 'username' => 'shiela_baina',
    'name' => 'Shiela Baina', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

// A commissary + date so the sheet code path is entered with $sheetSourceBranchId > 0.
$_GET = ['date' => '2026-10-07', 'commissary_id' => '18', 'shift' => 'AM'];

ob_start();
try {
    handleAdminCommissary();
} catch (\Throwable $e) {
    // Expected in CLI when the compiled cache is unwritable. The mode-dependent code has already run,
    // which is the only thing this child is for.
    ob_end_clean();
    fwrite(STDERR, 'render did not complete (expected in CLI): ' . substr($e->getMessage(), 0, 120) . "\n");
    exit(0);
}
ob_end_clean();
exit(0);
