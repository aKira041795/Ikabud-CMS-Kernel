<?php

declare(strict_types=1);

/**
 * Print the Daily Ledger operating clock for tenant 207 as a single line, for use in an
 * acceptance gate that must prove the browser seed did NOT move it.
 *
 * Usage:  php .ai/read-clock.php     -> e.g. "Asia/Manila|23:59|2026-10-05"
 */

ob_start();
require_once __DIR__ . '/../tests/harness/TestHarness.php';
$h = new TestHarness('read-clock', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);

$s = dlModuleSettings(true);

echo implode('|', [
    (string)($s['operating_timezone'] ?? '<unset>'),
    (string)($s['close_of_day_time'] ?? '<unset>'),
    function_exists('dl_businessDate') ? (string)dl_businessDate() : '<unset>',
]) . "\n";
