<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the PRODUCTS OVERVIEW (the second of the owner's three views).
 *
 * Owner direction (2026-10-11):
 *   "we can separate the product level in its own view. don't integrate with reports. this will give
 *    the business the focus needed. - a buisiness health overview - products overview - reports
 *    generation which can summarize the first two"
 *
 * WHAT ALREADY EXISTS, measured rather than assumed:
 *   /daily-ledger/admin/products -> handleAdminProducts. READ IT: 1081-line template doing catalogue
 *   maintenance (product list, Add Product, Edit Product, Import CSV, consignee assignment). That is
 *   product CONFIGURATION. It is NOT the analytical products overview the owner asked for, so this
 *   criterion does NOT accept it as satisfying the requirement.
 *
 * The analytical content EXISTS and is ORPHANED - 0 call sites each:
 *   dl_overviewTopProducts, dl_overviewPareto, dl_overviewBranchProductTotals, dl_overviewProductTotals
 * They were removed from the Health view (its acceptance A8 asserts "no product blocks" and must stay
 * green). So this is a THIN build: wire the existing tested queries to a page. Not new analytics.
 *
 * Chair-written, measured RED against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-products-overview.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/products/performance';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// --- R1: the analytical queries are no longer orphaned -------------------------------------------
$root = $base . '/modules/daily-ledger';
$callSites = 0;
foreach (['dl_overviewTopProducts', 'dl_overviewPareto', 'dl_overviewBranchProductTotals', 'dl_overviewProductTotals'] as $fn) {
    $hits = shell_exec(sprintf(
        'grep -rn %s %s --include=*.php --include=*.disyl 2>/dev/null | grep -v "function %s" | wc -l',
        escapeshellarg($fn . '('), escapeshellarg($root), $fn
    ));
    $callSites += (int)trim((string)$hits);
}
$check('R1', 'the orphaned product analytics are wired to a page', $callSites > 0,
    sprintf('%d call site(s) across the four product query functions (0 today = nothing renders them)', $callSites));

// --- R2: a products performance route exists and resolves ----------------------------------------
$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$hasRoute = (bool)preg_match("#/daily-ledger/admin/products/performance#", $routes);
$check('R2', 'a products-performance route is registered', $hasRoute,
    $hasRoute ? 'route present in modules/daily-ledger/routes.php' : 'no such route registered');

// --- R3: a VIEWER can open it, and it is area-scoped ---------------------------------------------
// The owner has been reviewing as a viewer throughout, so "viewer can reach it" is the real bar.
$viewerOk = false;
$scopedOk = false;
$rendered = '';
if ($hasRoute) {
    require_once $base . '/src/helpers/module-manager.php';
    modulePushContext('daily-ledger');
    $tokens = dl_generateAuthTokens([
        'sub' => 'viewer:21', 'id' => 21, 'username' => 'products-acc',
        'name' => 'Products Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $fn = null;
    if (preg_match("#/daily-ledger/admin/products/performance'\s*=>\s*'daily-ledger:([A-Za-z0-9_]+)#", $routes, $rm)) {
        $fn = $rm[1];
    }
    if ($fn !== null && function_exists($fn)) {
        $_GET = ['scope' => 'ALL'];
        $_REQUEST = $_GET;
        ob_start();
        try {
            $fn();
        } catch (Throwable $e) {
            echo 'THREW: ' . $e->getMessage();
        }
        $rendered = (string)ob_get_clean();
        $viewerOk = $rendered !== '' && !preg_match('/not authorised|forbidden|access denied|THREW/i', $rendered);
        $scopedOk = (bool)preg_match('/dlAdminViewBranchIds\(|dl_reportFilters\(/', (string)file_get_contents($base . '/modules/daily-ledger/handlers.php'));
    }
    if ($fn !== null && !function_exists($fn)) {
        $rendered = 'handler ' . $fn . ' does not exist';
    }
}
$check('R3', 'a viewer can open it (the owner reviews as a viewer)', $viewerOk,
    $viewerOk ? 'rendered non-empty without an authorisation refusal' : 'not reachable by a viewer: ' . substr(strip_tags($rendered), 0, 110));

// --- R4: it is reachable from the nav ------------------------------------------------------------
$nav = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
$navHas = (bool)preg_match('#href="\{base_url\}/admin/products/performance"#', $nav);
$check('R4', 'the nav links to it', $navHas,
    $navHas ? 'nav entry present' : 'no nav entry - the page would be unreachable by clicking');

// --- R5: the product analytics are not duplicated back into Health -------------------------------
$health = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/overview.disyl');
$healthClean = !preg_match('/top_products|pareto|branch_product_totals/i', $health);
$check('R5', 'Health still has no product blocks (its A8 must stay green)', $healthClean,
    $healthClean ? 'Health contains no product blocks' : 'product blocks reappeared in Health - the views are supposed to be separate');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
