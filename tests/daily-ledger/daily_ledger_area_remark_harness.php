#!/usr/bin/env php
<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
$mode = (string)($argv[1] ?? '');
$file = (string)($argv[2] ?? '');
if (!is_file($file)) { exit(2); }
$payload = json_decode((string)file_get_contents($file), true);
if (!is_array($payload)) { exit(3); }
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = $mode === 'variances-page' ? 'GET' : 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = is_array($payload['get'] ?? null) ? $payload['get'] : [];
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$role = (string)($payload['role'] ?? 'admin');
$userId = (int)($payload['user_id'] ?? 1);
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => $role . ':' . $userId, 'id' => $userId, 'username' => 'gap-test',
    'name' => 'Gap Test', 'role' => $role, 'source' => 'daily-ledger',
]);
final class DlAreaRemarkInput
{
    public static string $body = '';
    public $context;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$openedPath): bool { $this->position = 0; return true; }
    public function stream_read($count): string { $out = substr(self::$body, $this->position, $count); $this->position += strlen($out); return $out; }
    public function stream_eof(): bool { return $this->position >= strlen(self::$body); }
    public function stream_stat(): array { return []; }
    public function url_stat($path, $flags): array { return []; }
}
DlAreaRemarkInput::$body = json_encode($payload['body'] ?? [], JSON_THROW_ON_ERROR);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlAreaRemarkInput::class);
match ($mode) {
    'area-create' => apiCreateArea(),
    'area-update' => apiUpdateArea(),
    'consignee' => apiSaveConsignee(),
    'remark' => apiSaveDeliveryVarianceRemark(),
    'variances-page' => handleAdminVariances(),
    default => exit(4),
};
