#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';

$view = (string)($argv[1] ?? 'branches');
$scope = (string)($argv[2] ?? 'ALL');
$markerA = (string)($argv[3] ?? '');
$markerB = (string)($argv[4] ?? '');
$role = (string)($argv[5] ?? 'admin');
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
$actorId = $role === 'viewer' ? 21 : 1;
$tokens = dl_generateAuthTokens([
    'sub' => $role . ':' . $actorId, 'id' => $actorId, 'username' => 'area-filter-' . $role,
    'name' => 'Area Filter ' . ucfirst($role), 'role' => $role, 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$handlers = [
    'branches' => 'handleAdminBranches',
    'dashboard' => 'handleAdminDashboard',
    'overview' => 'handleAdminOverview',
    'sales' => 'handleAdminSales',
    'settings' => 'handleAdminSettings',
    'commissary' => 'handleAdminCommissary',
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
$formEnd = strpos($html, '</form>');
$analytics = $formEnd === false ? $html : substr($html, $formEnd + 7);
// The commissary selector is rendered inside the filter form, so parse the OPTIONS of the
// commissary_id select. Other views return an empty map. This is the rendered output, not a
// re-run of the handler's data query, so a narrowing mutation cannot hide behind a green probe.
$commissaryOptions = [];
if (preg_match('/<select[^>]*name="commissary_id"[^>]*>(.*?)<\/select>/s', $html, $selectMatch)) {
    preg_match_all('/<option value="(\d+)"[^>]*>([^<]*)<\/option>/', $selectMatch[1], $optionMatches, PREG_SET_ORDER);
    foreach ($optionMatches as $optionMatch) {
        $commissaryOptions[(int)$optionMatch[1]] = trim((string)$optionMatch[2]);
    }
}
echo json_encode([
    'status' => is_int($status) ? $status : 200,
    // The canonical Area control moved from the layout banner into the shared partial
    // (templates/modules/daily-ledger/admin/partials/scope-filter.disyl) when the banner
    // control was removed as redundant. Probe the control where it now lives.
    'has_area_filter' => str_contains($html, 'id="admin-scope-filter"'),
    'has_a' => $markerA !== '' && str_contains($html, $markerA),
    'has_b' => $markerB !== '' && str_contains($html, $markerB),
    'analytics_has_a' => $markerA !== '' && str_contains($analytics, $markerA),
    'analytics_has_b' => $markerB !== '' && str_contains($analytics, $markerB),
    'commissary_options' => $commissaryOptions,
    'row_count' => substr_count($html, '<tr'),
], JSON_THROW_ON_ERROR);
