#!/usr/bin/env php
<?php
/**
 * Slice 3 acceptance CHILD — drives apiSaveConsignee in its own process.
 *
 * WHY A CHILD: $ctx->json() writes the response and EXITS, so an in-process call terminates the caller
 * and a gate silently false-passes having run none of its assertions (measured on the slice-2 gate,
 * rc=0 with no assertions evaluated). One handler call per process.
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$payloadFile = (string)($argv[1] ?? '');
if (!is_file($payloadFile)) { exit(2); }
$payload = json_decode((string)file_get_contents($payloadFile), true) ?: [];

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/admin/consignees';
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
    'sub' => 'admin:20', 'id' => 20, 'username' => 'shiela_baina',
    'name' => 'Shiela Baina', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class S3GateInput
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
S3GateInput::$body = json_encode($payload, JSON_THROW_ON_ERROR);
stream_wrapper_unregister('php');
stream_wrapper_register('php', S3GateInput::class);

apiSaveConsignee();
