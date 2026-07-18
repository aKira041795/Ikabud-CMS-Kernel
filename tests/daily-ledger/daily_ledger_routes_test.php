<?php

declare(strict_types=1);

/**
 * Daily Ledger — Routes Test
 *
 * Validates route file structure, handler function mappings,
 * route count integrity, and URL pattern conventions.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-routes');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');

$base = $h->basePath();

// ─── Route File Structure ───────────────────────────────────────
$h->section('Route File Structure');

$routesPath = $base . '/modules/daily-ledger/routes.php';
$h->test('routes.php exists', is_file($routesPath));

$routes = include $routesPath;
$h->test('routes.php returns array', is_array($routes));
$h->test('GET key exists', array_key_exists('GET', $routes));
$h->test('POST key exists', array_key_exists('POST', $routes));

$getRoutes = $routes['GET'] ?? [];
$postRoutes = $routes['POST'] ?? [];

$h->test('GET routes is array', is_array($getRoutes));
$h->test('POST routes is array', is_array($postRoutes));
$h->test('GET routes not empty', !empty($getRoutes));
$h->test('POST routes not empty', !empty($postRoutes));

// ─── Route Count Integrity ──────────────────────────────────────
$h->section('Route Count Integrity');

$getCount = count($getRoutes);
$postCount = count($postRoutes);
$totalRoutes = $getCount + $postCount;

$h->test("GET routes: {$getCount}", $getCount >= 35);
$h->test("POST routes: {$postCount}", $postCount >= 40);
$h->test("Total routes >= 75", $totalRoutes >= 75);

// ─── GET Route Categories ───────────────────────────────────────
$h->section('GET Route Categories');

$getPaths = array_keys($getRoutes);

$hasAuthPages = false;
$hasAdminPages = false;
$hasApiGet = false;
$hasCashierPages = false;

foreach ($getPaths as $path) {
    if (str_starts_with($path, '/daily-ledger/login')) $hasAuthPages = true;
    if (str_starts_with($path, '/daily-ledger/admin/')) $hasAdminPages = true;
    if (str_starts_with($path, '/daily-ledger/api/')) $hasApiGet = true;
    if (str_starts_with($path, '/daily-ledger/ledger')) $hasCashierPages = true;
}

$h->test('Auth pages (login, forgot-password, reset-password)', $hasAuthPages);
$h->test('Admin pages (/admin/*)', $hasAdminPages);
$h->test('API GET endpoints (/api/*)', $hasApiGet);
$h->test('Cashier pages (/ledger)', $hasCashierPages);

// ─── POST Route Categories ──────────────────────────────────────
$h->section('POST Route Categories');

$postPaths = array_keys($postRoutes);

$hasAuthPost = false;
$hasAdminApiPost = false;
$hasCashierApiPost = false;
$hasProductionApiPost = false;
$hasDeliveryApiPost = false;
$hasReceivingApiPost = false;
$hasCommissaryApiPost = false;

foreach ($postPaths as $path) {
    if (str_starts_with($path, '/daily-ledger/auth/')) $hasAuthPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/admin/')) $hasAdminApiPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/cashier/')) $hasCashierApiPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/production/')) $hasProductionApiPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/deliveries')) $hasDeliveryApiPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/receivings')) $hasReceivingApiPost = true;
    if (str_starts_with($path, '/daily-ledger/api/v1/commissary')) $hasCommissaryApiPost = true;
}

$h->test('Auth POST (login, refresh)', $hasAuthPost);
$h->test('Admin API POST', $hasAdminApiPost);
$h->test('Cashier API POST', $hasCashierApiPost);
$h->test('Production API POST', $hasProductionApiPost);
$h->test('Delivery API POST', $hasDeliveryApiPost);
$h->test('Receiving API POST', $hasReceivingApiPost);
$h->test('Commissary API POST', $hasCommissaryApiPost);

// ─── Handler Function Mapping ───────────────────────────────────
$h->section('Handler Function Mapping');

$allHandlers = array_merge(array_values($getRoutes), array_values($postRoutes));
$h->test('All route values are strings', array_reduce($allHandlers, fn($c, $v) => $c && is_string($v), true));
$h->test('All route values use module:function format', array_reduce($allHandlers, fn($c, $v) => $c && str_contains($v, ':'), true));
$h->test('All handlers prefixed with daily-ledger:', array_reduce($allHandlers, fn($c, $v) => $c && str_starts_with($v, 'daily-ledger:'), true));

// ─── Key Handler Functions Exist ────────────────────────────────
$h->section('Key Handler Functions');

// Load handler source files for inspection (helpers.php requires bootstrap,
// so we inspect source text rather than loading)
$handlersSource = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveriesSource = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$helpersSource = (string) file_get_contents($base . '/modules/daily-ledger/helpers.php');
$allSource = $handlersSource . $deliveriesSource . $helpersSource;

$requiredHandlers = [
    'dailyLedgerAuthLogin',
    'dailyLedgerForgotPassword',
    'dailyLedgerResetPassword',
    'dailyLedgerAuthRefresh',
    'dailyLedgerLogout',
    'handleCashierLedger',
    'handleCashierRows',
    'handleAdminDashboard',
    'handleAdminSales',
    'handleAdminProductionOutput',
    'handleAdminVariances',
    'handleAdminProducts',
    'handleAdminBranches',
    'handleAdminUsers',
    'handleAdminSettings',
    'handleAdminActivity',
    'handleAdminUsage',
    'handleAdminCommissary',
    'handleAdminDeliveries',
    'handleAdminPriceGroups',
    'handleAdminWithdrawals',
    'apiGetLedgerRows',
    'apiSaveLedgerField',
    'apiSaveLedgerBatch',
    'apiCloseDay',
    'apiReopenDay',
    'apiProductionOutput',
    'apiProductionWithdrawal',
    'apiProductionReverse',
    'apiProductionSyncBatch',
    'apiCreateDelivery',
    'apiPostDelivery',
    'apiVoidDelivery',
    'apiListDeliveries',
    'apiCreateReceiving',
    'apiPostReceiving',
    'apiVoidReceiving',
    'apiSaveProductionRun',
    'apiCommissaryDispatch',
    'apiSaveCommissaryMaterial',
];

foreach ($requiredHandlers as $fn) {
    $h->test("function {$fn}() defined in source", str_contains($allSource, "function {$fn}("));
}

// ─── URL Pattern Conventions ────────────────────────────────────
$h->section('URL Pattern Conventions');

$allPaths = array_merge($getPaths, $postPaths);
$allGood = true;

foreach ($allPaths as $path) {
    // No trailing slashes
    if (str_ends_with($path, '/') && $path !== '/') {
        $h->fail("No trailing slash: {$path}");
        $allGood = false;
    }
}
if ($allGood) {
    $h->pass('No routes have trailing slashes');
}

// All routes start with /daily-ledger/
$allPrefixed = true;
foreach ($allPaths as $path) {
    if (!str_starts_with($path, '/daily-ledger/')) {
        $h->fail("Route prefix: {$path}", "does not start with /daily-ledger/");
        $allPrefixed = false;
    }
}
if ($allPrefixed) {
    $h->pass('All routes start with /daily-ledger/');
}

// API routes use /api/v1/
$apiRoutes = array_filter($allPaths, fn($p) => str_starts_with($p, '/daily-ledger/api/'));
$apiVersioned = true;
foreach ($apiRoutes as $path) {
    if (!str_contains($path, '/api/v1/')) {
        $h->fail("API versioning: {$path}", "should use /api/v1/");
        $apiVersioned = false;
    }
}
if ($apiVersioned) {
    $h->pass('All API routes use /api/v1/ version prefix');
}

// ─── No Duplicate Routes ────────────────────────────────────────
$h->section('No Duplicate Routes');

$getDupes = array_diff_assoc($getPaths, array_unique($getPaths));
$postDupes = array_diff_assoc($postPaths, array_unique($postPaths));
$h->test('No duplicate GET routes', empty($getDupes));
$h->test('No duplicate POST routes', empty($postDupes));

// ─── Nav ↔ GET Route Consistency ───────────────────────────────
$h->section('Nav ↔ GET Route Consistency');

$manifestPath = $base . '/modules/daily-ledger/module.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$navUrls = [];
$collectNav = static function (array $items) use (&$collectNav, &$navUrls): void {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!empty($item['url'])) $navUrls[] = (string)$item['url'];
        if (is_array($item['children'] ?? null)) $collectNav($item['children']);
    }
};
$collectNav($manifest['nav'] ?? []);

$navMatches = true;
foreach ($navUrls as $navUrl) {
    if (!in_array($navUrl, $getPaths, true)) {
        $h->fail("Nav → GET: {$navUrl}", 'declared in module.json nav but not in routes.php GET');
        $navMatches = false;
    }
}
if ($navMatches) {
    $h->pass('All ' . count($navUrls) . ' nav URLs present in GET routes');
}

// ─── Contract ↔ Route Consistency ──────────────────────────────
$h->section('Contract ↔ Route Consistency');

$contractPath = $base . '/modules/daily-ledger/workbench-contract.json';
$contract = json_decode((string) file_get_contents($contractPath), true);
$contractGet = $contract['ownership']['routes']['GET'] ?? [];
$contractPost = $contract['ownership']['routes']['POST'] ?? [];

// Every contract GET must exist in routes.php
$contractGetOk = true;
foreach ($contractGet as $cr) {
    if (!in_array($cr, $getPaths, true)) {
        $h->fail("Contract GET → routes: {$cr}", 'in contract but not in routes.php');
        $contractGetOk = false;
    }
}
if ($contractGetOk) {
    $h->pass('All ' . count($contractGet) . ' contract GET routes exist in routes.php');
}

// Every routes GET should be in contract (bidirectional)
$getInContractOk = true;
foreach ($getPaths as $gr) {
    if (!in_array($gr, $contractGet, true)) {
        $h->fail("Routes GET → contract: {$gr}", 'in routes.php but not in workbench contract');
        $getInContractOk = false;
    }
}
if ($getInContractOk) {
    $h->pass('All ' . count($getPaths) . ' routes.php GET routes present in contract');
}

// POST route parity
$h->test('Contract POST count matches routes POST count', count($contractPost) === count($postRoutes));

// ─── Route naming conventions ───────────────────────────────────
$h->section('Handler Naming Conventions');

// API handlers should start with 'api' or 'dailyLedger'
$apiPostPaths = array_filter($postPaths, fn($p) => str_starts_with($p, '/daily-ledger/api/'));
$apiNamingOk = true;
foreach ($apiPostPaths as $path) {
    $handler = $postRoutes[$path];
    $fnName = explode(':', $handler)[1] ?? '';
    if (!str_starts_with($fnName, 'api') && !str_starts_with($fnName, 'dailyLedger')) {
        $h->fail("API handler naming: {$handler}", "should start with 'api' prefix");
        $apiNamingOk = false;
    }
}
if ($apiNamingOk) {
    $h->pass('All API POST handlers follow naming convention');
}

// Page handlers should start with 'handle' or 'page'
$pageGetPaths = array_filter($getPaths, fn($p) => !str_starts_with($p, '/daily-ledger/api/') && !str_starts_with($p, '/daily-ledger/auth/'));
$pageNamingOk = true;
foreach ($pageGetPaths as $path) {
    $handler = $getRoutes[$path];
    $fnName = explode(':', $handler)[1] ?? '';
    if (!str_starts_with($fnName, 'handle') && !str_starts_with($fnName, 'page') && !str_starts_with($fnName, 'dailyLedger')) {
        $h->fail("Page handler naming: {$handler}", "should start with 'handle' or 'page' prefix");
        $pageNamingOk = false;
    }
}
if ($pageNamingOk) {
    $h->pass('All page GET handlers follow naming convention');
}

$h->done();
