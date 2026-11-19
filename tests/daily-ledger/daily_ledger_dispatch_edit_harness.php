#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Real-handler runner for the sender-side dispatch correction test.
 *
 * One POST to /daily-ledger/api/v1/commissary/dispatch-edit per process, so the JSON response, the
 * HTTP status AND the production-in-charge route allowlist are exactly what the browser gets. The
 * allowlist matters: dlCurrentUser() refuses any /daily-ledger path not named there, which is why a
 * handler can look correct and still answer 403 to the only role that uses the surface (measured
 * 2026-11-18 on the commissary withdrawals modal).
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$payloadFile = (string)($argv[1] ?? '');
$actorId = (int)($argv[2] ?? 0);
$role = (string)($argv[3] ?? 'production_in_charge');
if (!is_file($payloadFile) || $actorId <= 0) {
    exit(2);
}

$payload = json_decode((string)file_get_contents($payloadFile), true) ?: [];
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/commissary/dispatch-edit';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = [];

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$token = app()->jwt()->generate([
    'sub' => $role . ':' . $actorId,
    'id' => $actorId,
    'username' => 'edit-fixture-' . $actorId,
    'name' => 'Edit Fixture Actor',
    'role' => $role,
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlDispatchEditInputWrapper
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

DlDispatchEditInputWrapper::$body = json_encode($payload, JSON_THROW_ON_ERROR);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlDispatchEditInputWrapper::class);

apiEditCommissaryDispatch();
