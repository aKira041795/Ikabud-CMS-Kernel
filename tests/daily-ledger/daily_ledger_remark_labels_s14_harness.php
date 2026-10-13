<?php

declare(strict_types=1);

/**
 * S14 harness — serves the remark-label verification drives.
 *
 *   php daily_ledger_remark_labels_s14_harness.php sheet [YYYY-MM-DD] [commissary_id]
 *        Renders the REAL commissary admin page (handleAdminCommissary) with an
 *        admin JWT and reports: raw machine markers still visible in the HTML,
 *        counts of the expected human labels, and context for any marker found.
 *
 *   php daily_ledger_remark_labels_s14_harness.php adj <branch_id> <date> <shift>
 *        Calls the REAL apiTodayCashierWithdrawals() endpoint and reports each
 *        row's reason_code with the served reason_code_label.
 *
 * Output: one JSON object on stdout.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['sheet', 'adj'], true)) {
    fwrite(STDERR, "Usage: sheet [date] [commissary_id] | adj <branch> <date> <shift>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';

if ($mode === 'sheet') {
    $date = (string)($argv[2] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $_GET['date'] = $date;
    }
    $commissaryId = (int)($argv[3] ?? 0);
    if ($commissaryId > 0) {
        $_GET['commissary_id'] = (string)$commissaryId;
    }
    $_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';
} else {
    $branch = (int)($argv[2] ?? 0);
    $_GET = [
        'branch_id' => (string)$branch,
        'date' => (string)($argv[3] ?? ''),
        'shift' => (string)($argv[4] ?? 'AM'),
    ];
    $_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/cashier/ledger/withdrawals/today';
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

$token = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'prod-rizal',
    'name' => 'Noah Omamalin',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

if ($mode === 'sheet') {
    ob_start();
    handleAdminCommissary();
    $html = (string)ob_get_clean();

    $markers = [];
    if (preg_match_all(
        '/\[(daily-sheet-entry|daily-sheet-consignee-entry|captured-from-paper-dr|auto-dr-production|cashier-dispatch|commissary-dispatch|self-managed-no-delivery|cashier-pullout-return)[^\]]*\]/',
        $html,
        $m,
        PREG_OFFSET_CAPTURE
    )) {
        foreach ($m[0] as $hit) {
            $markers[] = [
                'marker' => $hit[0],
                'context' => substr($html, max(0, $hit[1] - 60), 140),
            ];
        }
    }

    $labels = [];
    foreach (['Daily sheet entry', 'Daily sheet correction', 'Captured from paper DR', 'Auto DR (production receive)', 'Cashier dispatch', 'Pullout return'] as $label) {
        $labels[$label] = substr_count($html, $label);
    }

    echo json_encode([
        'ok' => true,
        'html_len' => strlen($html),
        'markers' => array_slice($markers, 0, 8),
        'marker_count' => count($markers),
        'labels' => $labels,
    ]);
    exit(0);
}

// adj mode — the endpoint's $ctx->json() exits, so its JSON IS the process
// output; the test parses it (rows carry reason_code_label).
apiTodayCashierWithdrawals();
