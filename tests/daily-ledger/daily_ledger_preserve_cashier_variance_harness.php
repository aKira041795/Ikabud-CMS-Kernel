#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runtime harness for the preserve-cashier-variance contract.
 *
 * Runs one daily-ledger API handler in a clean subprocess so an HTTP-style
 * request (role cookie, GET query, JSON body) can be exercised exactly as the
 * web surface would. The payload is JSON:
 *
 *   { "role": "admin", "get": {...}, "body": {...} }
 *
 * Modes: decide | variance | incoming | variances-page
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
if (!in_array($mode, ['decide', 'variance', 'incoming', 'variances-page'], true) || !is_file($payloadFile)) {
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = $mode === 'incoming' ? 'GET' : 'POST';
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
$role = in_array((string)($payload['role'] ?? 'admin'), ['admin', 'supervisor', 'cashier', 'auditor'], true)
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

final class DlPreserveVarianceInputWrapper
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

DlPreserveVarianceInputWrapper::$body = $body === [] ? '' : (string)json_encode($body);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlPreserveVarianceInputWrapper::class);

if ($mode === 'decide') {
    apiResolveDeliveryVariance();
} elseif ($mode === 'variance') {
    apiUpdateVarianceStatus();
} elseif ($mode === 'incoming') {
    apiGetIncomingDeliveries();
} else {
    handleAdminVariances();
}
