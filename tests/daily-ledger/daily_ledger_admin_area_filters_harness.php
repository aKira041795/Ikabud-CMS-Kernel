#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';

$view = (string)($argv[1] ?? 'branches');
$scope = (string)($argv[2] ?? 'ALL');
$markerA = (string)($argv[3] ?? '');
$markerB = (string)($argv[4] ?? '');
$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/' . $view . '?scope=' . rawurlencode($scope);
$_GET = ['scope' => $scope];
$_REQUEST = $_GET;

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'area-filter-admin',
    'name' => 'Area Filter Admin', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$handlers = [
    'branches' => 'handleAdminBranches',
    'dashboard' => 'handleAdminDashboard',
    'overview' => 'handleAdminOverview',
    'sales' => 'handleAdminSales',
    'settings' => 'handleAdminSettings',
];
if (!isset($handlers[$view])) {
    fwrite(STDERR, "Unknown view\n");
    exit(2);
}

http_response_code(200);
ob_start();
$handlers[$view]();
$html = (string)ob_get_clean();
$status = http_response_code();
echo json_encode([
    'status' => is_int($status) ? $status : 200,
    // The canonical Area control moved from the layout banner into the shared partial
    // (templates/modules/daily-ledger/admin/partials/scope-filter.disyl) when the banner
    // control was removed as redundant. Probe the control where it now lives.
    'has_area_filter' => str_contains($html, 'id="admin-scope-filter"'),
    'has_a' => $markerA !== '' && str_contains($html, $markerA),
    'has_b' => $markerB !== '' && str_contains($html, $markerB),
    'row_count' => substr_count($html, '<tr'),
], JSON_THROW_ON_ERROR);
