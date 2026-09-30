#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$mode = (string)($argv[1] ?? 'receive');
if (!in_array($mode, ['receive', 'list'], true)) {
    fwrite(STDERR, "mode must be receive or list\n");
    exit(2);
}
$argument = (string)($argv[2] ?? '');
if ($mode === 'receive' && ($argument === '' || !is_file($argument))) {
    fwrite(STDERR, "receive mode requires a payload file\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = $mode === 'list'
    ? '/daily-ledger/api/v1/deliveries'
    : '/daily-ledger/api/v1/cashier/ledger/receive-delivery';
$_SERVER['REQUEST_METHOD'] = $mode === 'list' ? 'GET' : 'POST';
if ($mode === 'list') {
    parse_str($argument, $_GET);
} else {
    $_SERVER['CONTENT_TYPE'] = 'application/json';
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$token = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'receipt-count-harness',
    'name' => 'Receipt Count Harness',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlReceiptCountInputWrapper
{
    public static string $body = '';
    public $context;
    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read($count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function url_stat($path, $flags): array { return []; }
}

if ($mode === 'list') {
    apiListDeliveries();
    exit;
}

DlReceiptCountInputWrapper::$body = (string)file_get_contents($argument);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlReceiptCountInputWrapper::class);
apiReceiveDelivery();
