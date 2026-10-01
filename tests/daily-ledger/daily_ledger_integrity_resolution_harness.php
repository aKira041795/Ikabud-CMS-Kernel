#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
if (!in_array($mode, ['origin', 'count', 'variance', 'void', 'variances-page', 'trace-page'], true) || !is_file($payloadFile)) { exit(2); }

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = in_array($mode, ['variances-page', 'trace-page'], true) ? 'GET' : 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'integrity-harness',
    'name' => 'Integrity Harness', 'role' => 'admin', 'source' => 'daily-ledger',
]);

final class DlIntegrityInputWrapper
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
DlIntegrityInputWrapper::$body = (string)file_get_contents($payloadFile);
if ($mode === 'trace-page') {
    $_GET = (array)json_decode(DlIntegrityInputWrapper::$body, true);
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlIntegrityInputWrapper::class);
if ($mode === 'origin') {
    apiResolveDeliveryOrigin();
} elseif ($mode === 'count') {
    apiResolveReceivingCount();
} elseif ($mode === 'variance') {
    apiUpdateVarianceStatus();
} elseif ($mode === 'void') {
    apiVoidDelivery();
} elseif ($mode === 'variances-page') {
    handleAdminVariances();
} else {
    handleAdminTrace();
}
