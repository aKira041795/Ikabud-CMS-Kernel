<?php

declare(strict_types=1);

/**
 * Print the Daily Ledger operating clock for tenant 207 as a single line, for use in an
 * acceptance gate that must prove the browser seed did NOT move it.
 *
 * Usage:  php tools/read-dl-clock.php     -> e.g. "Asia/Manila|23:59|2026-10-05"
 *
 * This is a probe, not a test suite. It deliberately bootstraps the kernel directly instead of
 * going through TestHarness: the harness registers a shutdown guard that prints "SUITE ABORTED"
 * and exits 1 unless done() is called, which made this tool fail the moment it was chained with
 * `&&` even though it printed the correct line.
 */

$base = dirname(__DIR__);

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'baronledger.test';

global $config;
$returned = require $base . '/bootstrap.php';
if ($config === null || !isset($config['database'])) {
    $config = $returned;
}

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
