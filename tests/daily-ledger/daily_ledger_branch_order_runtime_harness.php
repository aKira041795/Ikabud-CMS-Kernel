#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Daily Sheet branch-column order runtime harness.
 *
 * Boots the kernel, resolves tenant 207, mints a daily-ledger token for the
 * production-in-charge account (allowed on /daily-ledger/admin/commissary),
 * invokes the real handler, and prints ONLY the ordered branch <th> names as
 * JSON. A source grep cannot prove the rendered column order; this can.
 *
 * Usage: php daily_ledger_branch_order_runtime_harness.php [YYYY-MM-DD]
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$date = (string)($argv[1] ?? '');
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $_GET['date'] = $date;
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

$tokens = dl_generateAuthTokens([
    'sub' => 'production_in_charge:27',
    'id' => 27,
    'username' => 'prod-rizal',
    'name' => 'Prod Rizal',
    'role' => 'production_in_charge',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

ob_start();
handleAdminCommissary();
$html = (string)ob_get_clean();

$names = [];
if (preg_match_all('/<th class="text-right daily-sheet-branch-column"[^>]*>([^<]*)<\/th>/', $html, $matches)) {
    $names = array_map('trim', $matches[1]);
}
echo json_encode($names);
