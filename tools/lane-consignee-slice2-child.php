#!/usr/bin/env php
<?php
/**
 * Slice 2 acceptance CHILD — one handler call per process.
 *
 * WHY A CHILD PROCESS: the kernel's $ctx->json() writes the response and EXITS. Calling a handler
 * in-process therefore terminates the caller, which makes a gate report rc=0 without ever running its
 * assertions (the cli-fatal-exit-zero-false-pass class). Mirroring the existing isolation harness:
 * one child per call, and the shutdown function stamps the HTTP status so the parent can tell a real
 * response from a dead process.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
$actorId = (int)($argv[3] ?? 0);
$role = (string)($argv[4] ?? 'admin');
if (!in_array($mode, ['list', 'review'], true) || !is_file($payloadFile) || $actorId <= 0) { exit(2); }

$payload = json_decode((string)file_get_contents($payloadFile), true) ?: [];

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/s2gate/' . $mode;
$_SERVER['REQUEST_METHOD'] = $mode === 'review' ? 'POST' : 'GET';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = $mode === 'list' ? $payload : [];

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$token = app()->jwt()->generate([
    'sub' => $role . ':' . $actorId, 'id' => $actorId, 'username' => 's2gate-' . $actorId,
    'name' => 'S2 Gate Actor', 'role' => $role, 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class S2GateChildInput
{
    public static string $body = '';
    public $context;
    private int $position = 0;
    public function stream_open($p, $m, $o, &$op): bool { $this->position = 0; return true; }
    public function stream_read($c): string { $s = substr(self::$body, $this->position, $c); $this->position += strlen($s); return $s; }
    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_tell(): int { return $this->position; }
    public function stream_stat(): array { return []; }
    public function url_stat($p, $f): array { return []; }
}
S2GateChildInput::$body = $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
stream_wrapper_unregister('php');
stream_wrapper_register('php', S2GateChildInput::class);

if ($mode === 'review') { apiReviewDeliveryProvenance(); } else { apiListDeliveries(); }
