#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$payloadFile = (string)($argv[1] ?? '');
$actorId = (int)($argv[2] ?? 0);
$mode = (string)($argv[3] ?? 'dispatch');
$role = (string)($argv[4] ?? 'cashier');
if (!in_array($mode, ['dispatch', 'deliveries'], true) || !is_file($payloadFile) || $actorId <= 0) {
    fwrite(STDERR, "Usage: php daily_ledger_consignee_toggle_harness.php <payload> <actor-id> [dispatch|deliveries] [role]\n");
    exit(2);
}
$payload = json_decode((string)file_get_contents($payloadFile), true) ?: [];

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = $mode === 'deliveries'
    ? '/daily-ledger/api/v1/deliveries'
    : '/daily-ledger/api/v1/cashier/ledger/dispatch';
$_SERVER['REQUEST_METHOD'] = $mode === 'deliveries' ? 'GET' : 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = $mode === 'deliveries' ? $payload : [];

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$token = app()->jwt()->generate([
    'sub' => 'cashier:' . $actorId,
    'id' => $actorId,
    'username' => 'consignee-toggle-' . $actorId,
    'name' => 'Consignee Toggle Fixture',
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlConsigneeToggleInputWrapper
{
    public static string $body = '';
    public $context;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$openedPath): bool { $this->position = 0; return true; }
    public function stream_read($count): string { $chunk = substr(self::$body, $this->position, $count); $this->position += strlen($chunk); return $chunk; }
    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function url_stat($path, $flags): array { return []; }
}

DlConsigneeToggleInputWrapper::$body = (string)file_get_contents($payloadFile);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlConsigneeToggleInputWrapper::class);
if ($mode === 'deliveries') {
    apiListDeliveries();
} else {
    apiCreateCashierDispatch();
}
