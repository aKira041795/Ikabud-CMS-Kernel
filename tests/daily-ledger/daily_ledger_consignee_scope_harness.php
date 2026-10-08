#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Slice 7 consignee product-scope runtime harness.
 *
 * Runs one daily-ledger API handler in a clean subprocess so the product
 * create/update payloads (and a consignee create) can be exercised exactly as
 * the web surface would. The payload file is JSON:
 *
 *   { "role": "admin", "get": {...}, "body": {...} }
 *
 * Modes: update_product | create_product | save_consignee
 *
 * stdout is the JSON the endpoint would have returned, so the caller asserts
 * on what actually happened rather than on a helper's decision. This exists
 * because $ctx->json() EXITS the process: an in-process handler call would
 * silently false-pass.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
$modes = ['update_product', 'create_product', 'save_consignee'];
if (!in_array($mode, $modes, true) || !is_file($payloadFile)) {
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$payload = json_decode((string)file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    exit(3);
}
$role = in_array((string)($payload['role'] ?? 'admin'), ['admin', 'supervisor', 'cashier', 'auditor', 'production_in_charge'], true)
    ? (string)$payload['role']
    : 'admin';
$_GET = is_array($payload['get'] ?? null) ? $payload['get'] : [];
$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => $role . ':1',
    'id' => 1,
    'username' => $role . '-harness',
    'name' => ucfirst($role) . ' Harness',
    'role' => $role,
    'source' => 'daily-ledger',
]);

final class DlConsigneeScopeInputWrapper
{
    public static string $body = '';
    public $context;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$openedPath): bool { $this->position = 0; return true; }
    public function stream_read($count): string { $out = substr(self::$body, $this->position, $count); $this->position += strlen($out); return $out; }
    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function url_stat($path, $flags): array { return []; }
}

DlConsigneeScopeInputWrapper::$body = $body === [] ? '' : (string)json_encode($body);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlConsigneeScopeInputWrapper::class);

if ($mode === 'create_product') {
    apiCreateProduct();
} elseif ($mode === 'save_consignee') {
    apiSaveConsignee();
} else {
    apiUpdateProduct();
}
