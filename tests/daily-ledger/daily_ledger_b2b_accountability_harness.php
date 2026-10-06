#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
$actorId = (int)($argv[3] ?? 0);
if (!in_array($mode, ['receive', 'dispatch'], true) || !is_file($payloadFile) || $actorId <= 0) {
    fwrite(STDERR, "Usage: php daily_ledger_b2b_accountability_harness.php receive|dispatch <payload> <actor-id>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = $mode === 'dispatch'
    ? '/daily-ledger/api/v1/cashier/ledger/dispatch'
    : '/daily-ledger/api/v1/cashier/ledger/receive-delivery';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

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
    'username' => 'b2b-accountability-' . $actorId,
    'name' => 'B2B Accountability Fixture',
    'role' => 'cashier',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlB2bAccountabilityInputWrapper
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

DlB2bAccountabilityInputWrapper::$body = (string)file_get_contents($payloadFile);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlB2bAccountabilityInputWrapper::class);

if ($mode === 'dispatch') {
    apiCreateCashierDispatch();
}
apiReceiveDelivery();
