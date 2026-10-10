#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary?scope=ALL';
$selectedBranchId = max(0, (int)($argv[1] ?? 0));
$_GET = ['scope' => 'ALL'];
if ($selectedBranchId > 0) {
    $_GET['branch_id'] = $selectedBranchId;
}
$_REQUEST = $_GET;
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$tokens = dl_generateAuthTokens(['sub' => 'admin:1', 'id' => 1, 'username' => 'area-test-admin', 'name' => 'Area Test Admin', 'role' => 'admin', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $tokens['token'];
http_response_code(200);
ob_start();
handleAdminCommissary();
$html = (string)ob_get_clean();
$status = http_response_code();
$columns = preg_match_all('/<th class="text-right daily-sheet-branch-column"[^>]*>/', $html);
echo json_encode([
    'status' => is_int($status) ? $status : 200,
    'bytes' => strlen($html),
    'notice' => str_contains($html, 'daily-sheet-branch-limit-notice') && str_contains($html, 'branch column(s) omitted'),
    'columns' => $columns,
    'selected_included' => $selectedBranchId <= 0 || str_contains($html, 'data-branch-id="' . $selectedBranchId . '"'),
], JSON_THROW_ON_ERROR);
