<?php

declare(strict_types=1);

/**
 * ACCEPTANCE GATE — the Health view must render inside a wall-clock budget.
 *
 * This is the oracle for the overview latency work. It asserts an OUTCOME (the page a user opens
 * comes back in time), never a mechanism: it does not care whether the win comes from an index, a
 * cheaper aggregate, a cached projection or removing a redundant query. Any fix that makes the real
 * handler render fast passes; anything that only moves the cost around does not.
 *
 * It renders the REAL handler in-process, exactly as the page does, so the number it reports is the
 * server-side work the browser waits for. Bootstrap noise is excluded by timing only the handler.
 *
 * Usage:  php tools/checks/dl-overview-latency-acceptance.php [--budget=SECONDS]
 * Prints: RESULT: PASS  or  RESULT: FAIL
 */

$root = dirname(__DIR__, 2);

// Budget for the DEFAULT view (no filters supplied - what a user gets when they open Health).
$budget = 3.0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--budget=([0-9.]+)$/', $arg, $m) === 1) {
        $budget = (float)$m[1];
    }
}

require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$tenantId = (int)(getenv('DL_ACCEPTANCE_TENANT') ?: 207);
app()->tenant()->setTenantId($tenantId);

$ctx = modulePushContext('daily-ledger');
require_once $root . '/modules/daily-ledger/helpers.php';
require_once $root . '/modules/daily-ledger/helpers/reporting.php';
require_once $root . '/modules/daily-ledger/handlers.php';

// An admin is the role that browses every branch, i.e. the widest and slowest legitimate scope.
$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'overview-acceptance',
    'name' => 'Overview Acceptance',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

// The DEFAULT view: no date_from/date_to, exactly like a bare /daily-ledger/admin/overview click.
$_GET = [];

$started = microtime(true);
ob_start();
try {
    handleAdminOverview();
    $html = (string)ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, 'RESULT: FAIL — the handler threw: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$elapsed = microtime(true) - $started;

// A render that produced no page is not a fast page. Guard against "fast because it broke".
if (strlen($html) < 5000) {
    fwrite(STDERR, 'RESULT: FAIL — the handler returned ' . strlen($html) . " bytes; a real page is far larger.\n");
    exit(1);
}

printf("  default Health view rendered in %.2fs (%d KB), budget %.2fs\n", $elapsed, (int)(strlen($html) / 1024), $budget);
echo($elapsed <= $budget ? 'RESULT: PASS' : 'RESULT: FAIL') . PHP_EOL;
exit($elapsed <= $budget ? 0 : 1);
