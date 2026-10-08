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
if (!in_array($mode, ['save', 'bulk', 'branches', 'products', 'commissary', 'delta'], true) || !is_file($payloadFile) || $actorId <= 0) exit(2);
$payload = json_decode((string)file_get_contents($payloadFile), true) ?: [];

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/daily-ledger/consignee-admin-fixture/' . $mode;
$_SERVER['REQUEST_METHOD'] = in_array($mode, ['save', 'bulk'], true) ? 'POST' : 'GET';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET = in_array($mode, ['branches', 'products', 'commissary'], true) ? $payload : [];

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$_COOKIE[dlCookieName()] = app()->jwt()->generate(['sub' => 'admin:' . $actorId, 'id' => $actorId, 'username' => 'consignee-admin-fixture', 'name' => 'Consignee Admin Fixture', 'role' => 'admin', 'source' => 'daily-ledger']);

final class DlConsigneeAdminInputWrapper
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
DlConsigneeAdminInputWrapper::$body = json_encode($payload, JSON_THROW_ON_ERROR);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlConsigneeAdminInputWrapper::class);

if ($mode === 'save') apiSaveConsignee();
elseif ($mode === 'bulk') apiBulkAssignConsigneeProducts();
elseif ($mode === 'branches') handleAdminBranches();
elseif ($mode === 'products') handleAdminProducts();
elseif ($mode === 'commissary') handleAdminCommissary();
else {
    $result = dl_applyConsigneeLedgerDelta(module()->db(), (int)$payload['branch_id'], (int)$payload['consignee_id'], (int)$payload['product_id'], (string)$payload['date'], (int)$payload['quantity'], $actorId, (string)($payload['shift'] ?? 'AM'));
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
}
