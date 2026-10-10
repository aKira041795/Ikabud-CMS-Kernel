#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary?scope=ALL';
$selectedBranchId = max(0, (int)($argv[1] ?? 0));
$role = (string)($argv[2] ?? 'admin');
$userId = max(1, (int)($argv[3] ?? 1));
$selectedCommissaryId = max(0, (int)($argv[4] ?? 0));
$_GET = ['scope' => 'ALL'];
if ($selectedCommissaryId > 0) {
    $_GET['commissary_id'] = $selectedCommissaryId;
}
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
$tokens = dl_generateAuthTokens(['sub' => $role . ':' . $userId, 'id' => $userId, 'username' => 'area-test-user', 'name' => 'Area Test User', 'role' => $role, 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $tokens['token'];
http_response_code(200);
ob_start();
handleAdminCommissary();
$html = (string)ob_get_clean();
$status = http_response_code();
$columns = preg_match_all('/<th class="text-right daily-sheet-branch-column"[^>]*>/', $html);
$db = module()?->db();
$pagadianBranchIds = $db ? array_map('intval', $db->query("SELECT id FROM dl_branches WHERE assigned_commissary_id = (SELECT id FROM dl_branches WHERE code = 'PAG-COMMISARY1' LIMIT 1)")->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$rizalNetworkIds = $db ? array_map('intval', $db->query("SELECT id FROM dl_branches WHERE assigned_commissary_id = (SELECT id FROM dl_branches WHERE code = 'RIZAL-COMMIS1' LIMIT 1)")->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
$renderedIds = [];
preg_match_all('/data-branch-id="(\d+)"/', $html, $renderedMatches);
foreach ($renderedMatches[1] ?? [] as $renderedId) {
    $renderedIds[(int)$renderedId] = true;
}
echo json_encode([
    'status' => is_int($status) ? $status : 200,
    'bytes' => strlen($html),
    'notice' => str_contains($html, 'daily-sheet-branch-limit-notice') && str_contains($html, 'branch column(s) omitted'),
    'columns' => $columns,
    'selected_included' => $selectedBranchId <= 0 || str_contains($html, 'data-branch-id="' . $selectedBranchId . '"'),
    'pagadian_branch_ids_rendered' => array_values(array_filter($pagadianBranchIds, static fn(int $id): bool => isset($renderedIds[$id]))),
    'rizal_network_ids_rendered' => array_values(array_filter($rizalNetworkIds, static fn(int $id): bool => isset($renderedIds[$id]))),
    'rendered_branch_ids' => array_map('intval', array_keys($renderedIds)),
    'has_rizal_commissary' => str_contains($html, 'RIZAL-COMMIS'),
    'has_pagadian_commissary' => str_contains($html, 'PAG-COMMISARY1') || str_contains($html, 'Pagadian Commisary'),
], JSON_THROW_ON_ERROR);
