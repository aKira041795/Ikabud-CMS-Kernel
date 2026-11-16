#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runtime harness: run ONE daily-ledger API handler as a specific USER (role + id), exactly as the
 * web surface would — role cookie, GET query, JSON body.
 *
 *   php daily_ledger_cashier_api_harness.php <mode> <payload.json>
 *   modes: pos | field | withdraw | receive
 *   payload: { "user": {id, role, shift, source}, "get": {...}, "body": {...} }
 *
 * Prints whatever the handler emits (JSON), then exits.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
if (!in_array($mode, ['pos', 'field', 'withdraw', 'receive', 'whoami'], true) || !is_file($payloadFile)) {
    fwrite(STDERR, "usage: harness <pos|field|withdraw|receive> <payload.json>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers-pos.php';
modulePushContext('daily-ledger');

$payload = json_decode((string)file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    exit(3);
}

$user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'cashier');
$shift = (string)($user['shift'] ?? 'PM');

$_GET = is_array($payload['get'] ?? null) ? $payload['get'] : [];
$body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

// The module resolves the actor from its own cookie; minting it here is how the web surface does it
// after login (dlSetAuthCookie stores exactly this token).
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => $role . ':' . $userId,
    'id' => $userId,
    'user_id' => $userId,
    'username' => (string)($user['username'] ?? ($role . '-' . $userId)),
    'name' => (string)($user['name'] ?? 'Harness'),
    'role' => $role,
    'shift' => $shift,
    'source' => 'daily-ledger',
]);

/** JSON request body for $ctx->input(). */
final class DlCashierApiBody
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

DlCashierApiBody::$body = $body === [] ? '' : (string)json_encode($body);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlCashierApiBody::class);

if ($mode === 'whoami') {
    // Diagnostics: what does the module resolve for this actor + request?
    $me = dlCurrentUser();
    $auth = dl_authorizeBranch($me, $body);
    $resolved = dl_resolveLedgerShift($me, $body);
    $branch = (int)($auth['branch_id'] ?? 0);
    $date = (string)($body['date'] ?? dl_businessDate());
    echo json_encode([
        'ok' => true,
        'user_id' => (int)($me['id'] ?? 0),
        'role' => (string)($me['role'] ?? ''),
        'user_branch' => dl_getUserBranchId(),
        'authorized_branch' => $branch,
        'shift' => $resolved['shift'],
        'shift_bound' => $resolved['bound'],
        'date' => $date,
        'day_status' => $branch > 0 ? dl_getDayStatus($branch, $date) : null,
        'shift_status' => $branch > 0 ? (dl_shiftIsFinalized(module()->db(), $branch, $date, $resolved['shift']) ? 'finalized' : 'open') : null,
    ]);
    exit(0);
}

if ($mode === 'pos') {
    apiPosCheckout();
} elseif ($mode === 'field') {
    apiSaveLedgerField();
} elseif ($mode === 'withdraw') {
    apiSaveCashierWithdrawals();
} else {
    apiReceiveDelivery();
}
