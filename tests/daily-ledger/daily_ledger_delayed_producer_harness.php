#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — delayed producer entry (066) runtime harness.
 *
 * Drives the REAL `apiReceivePaperDelivery()` endpoint in a subprocess, because
 * the endpoint reads `php://input` directly and exits through `$ctx->json()`.
 * The payload file is served as the raw request body; stdout is the JSON the
 * endpoint would have returned, so the caller asserts on what actually happened
 * (status code, body, DB writes) rather than on a source grep.
 *
 * Usage:
 *   php daily_ledger_delayed_producer_harness.php capture <payload-file>
 *   php daily_ledger_delayed_producer_harness.php log <commissary-id> <date>
 *
 * `capture` mints a daily-ledger admin token and invokes the capture endpoint.
 * `log` calls dl_fetchProductionLedgerLog() and prints the rows as JSON so the
 * rendered SENT/RECEIVED shape can be inspected without a browser.
 *
 * Tenant 207 (baron-001). LOCAL DEV ONLY.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

// The endpoint exits from inside $ctx->json(), so the HTTP status has to be
// captured from a shutdown hook and appended after the JSON body.
register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['capture', 'log'], true)) {
    fwrite(STDERR, "Usage: php daily_ledger_delayed_producer_harness.php capture <payload-file> | log <commissary-id> <date>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';

$payloadFile = '';
$actorId = 1;
if ($mode === 'capture') {
    $payloadFile = (string)($argv[2] ?? '');
    if ($payloadFile === '' || !is_file($payloadFile)) {
        fwrite(STDERR, "capture mode requires a readable payload file\n");
        exit(2);
    }
    $actorId = (int)($argv[3] ?? 1);
    $_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/cashier/ledger/receive-paper-dr';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
} else {
    $_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}
$db = $context->db();

if ($mode === 'log') {
    $commissaryId = (int)($argv[2] ?? 0);
    $date = (string)($argv[3] ?? '');
    $rows = dl_fetchProductionLedgerLog($db, $commissaryId, $date);
    $events = array_values(array_filter($rows, static function (array $row): bool {
        return in_array((string)$row['field'], ['SENT', 'RECEIVED'], true);
    }));
    echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Mint a real access token and present it the way the browser does so
// dlCurrentUser()/dlUserFromRequest() resolve the actor.
$token = app()->jwt()->generate([
    'sub' => 'admin:' . $actorId,
    'id' => $actorId,
    'username' => 'prod-rizal',
    'name' => 'Noah Omamalin',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlDelayedProducerInputWrapper
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

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        if ($whence === SEEK_SET) {
            $this->position = (int)$offset;
        } elseif ($whence === SEEK_CUR) {
            $this->position += (int)$offset;
        } elseif ($whence === SEEK_END) {
            $this->position = strlen(self::$body) + (int)$offset;
        }
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat($path, $flags): array
    {
        return [];
    }
}

DlDelayedProducerInputWrapper::$body = (string)file_get_contents($payloadFile);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlDelayedProducerInputWrapper::class);

// $ctx->json() exits, so the JSON response is the whole process output.
apiReceivePaperDelivery();
