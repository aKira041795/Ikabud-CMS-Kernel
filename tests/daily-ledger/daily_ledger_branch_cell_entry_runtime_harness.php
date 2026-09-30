#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — S10 Daily Sheet branch-cell runtime harness.
 *
 * Boots the kernel, resolves tenant 207, mints a daily-ledger token for the
 * production-in-charge account, invokes the real Commissary handler and prints
 * the rendered row/column counts as JSON so the sheet's shape can be asserted
 * without trusting a source grep. Column order is covered by the S9 harness.
 *
 * Usage: php daily_ledger_branch_cell_entry_runtime_harness.php [YYYY-MM-DD]
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

echo json_encode([
    'rows' => preg_match_all('/<tr class="daily-sheet-product-row"/', $html),
    'branch_headers' => preg_match_all('/<th class="text-right daily-sheet-branch-column"/', $html),
    'branch_cells' => preg_match_all('/<td class="text-right production-branch-cell"/', $html),
    'branch_triggers' => preg_match_all('/class="ledger-trigger ledger-trigger-add production-branch-trigger"/', $html),
]);
