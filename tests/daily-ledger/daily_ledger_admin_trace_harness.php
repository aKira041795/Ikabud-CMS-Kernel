#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — admin evidence trace harness.
 *
 * Drives the REAL code in a subprocess so the suite can assert on behaviour
 * rather than on source text:
 *
 *   php daily_ledger_admin_trace_harness.php capture <payload-file> <actor-id>
 *   php daily_ledger_admin_trace_harness.php page <actor-id> <role> <query-string>
 *   php daily_ledger_admin_trace_harness.php build <filters-json>
 *
 * `capture` invokes apiReceivePaperDelivery() (it reads php://input and exits
 * through $ctx->json()). `page` invokes handleAdminTrace() with a minted token.
 * `build` calls dl_buildAdminTraceData() and prints a text rendering of the
 * grouped evidence. Tenant 207 (baron-001). LOCAL DEV ONLY.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\n__HTTP_STATUS__=" . ($status === false ? '0' : (string)$status);
});

$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['capture', 'page', 'build'], true)) {
    fwrite(STDERR, "Usage: capture <payload-file> <actor-id> | page <actor-id> <role> <query-string> | build <filters-json>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';

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

if ($mode === 'build') {
    $filters = json_decode((string)($argv[2] ?? '{}'), true);
    if (!is_array($filters)) {
        $filters = [];
    }
    $accessible = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $data = dl_buildAdminTraceData($db, $filters, $accessible);
    echo dl_traceTextSummary($data);
    exit;
}

$actorId = (int)($argv[2] ?? 1);
$role = (string)($argv[3] ?? 'admin');

if ($mode === 'page') {
    $query = (string)($argv[4] ?? '');
    parse_str($query, $_GET);
    $_SERVER['REQUEST_URI'] = '/daily-ledger/admin/trace';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $token = app()->jwt()->generate([
        'sub' => $role . ':' . $actorId,
        'id' => $actorId,
        'username' => 'trace-' . $role,
        'name' => 'Trace ' . ucfirst($role),
        'role' => $role,
        'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $token;
    handleAdminTrace();
    exit;
}

// capture mode — same wire shape as the cashier browser call.
$_SERVER['REQUEST_URI'] = '/daily-ledger/api/v1/cashier/ledger/receive-paper-dr';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$payloadFile = (string)($argv[2] ?? '');
if ($payloadFile === '' || !is_file($payloadFile)) {
    fwrite(STDERR, "capture mode requires a readable payload file\n");
    exit(2);
}

$token = app()->jwt()->generate([
    'sub' => 'admin:' . $actorId,
    'id' => $actorId,
    'username' => 'trace-encoder',
    'name' => 'Trace Encoder',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

final class DlAdminTraceInputWrapper
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

DlAdminTraceInputWrapper::$body = (string)file_get_contents($payloadFile);
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlAdminTraceInputWrapper::class);

apiReceivePaperDelivery();

/**
 * Human-readable rendering of the builder output, used only by this harness so
 * the lane can paste the live groups as text.
 */
function dl_traceTextSummary(array $data): string
{
    $out = [];
    $out[] = 'MODE ' . ($data['mode'] ?? '?') . ' count=' . (int)($data['count'] ?? 0) . ' truncated=' . (($data['truncated'] ?? false) ? 'yes' : 'no');
    foreach (($data['documents'] ?? []) as $doc) {
        $out[] = str_repeat('=', 72);
        $out[] = 'DR ' . ($doc['dr_display'] ?? '') . '  #' . ($doc['id'] ?? '') . '  ' . ($doc['delivery_date'] ?? '') . '  ' . ($doc['origin_label'] ?? '') . ' -> ' . ($doc['destination_label'] ?? '') . '  [' . ($doc['status_label'] ?? '') . ']';
        if (!isset($doc['production'])) {
            $out[] = '  (day list row) trace: ' . ($doc['trace_link'] ?? '');
            continue;
        }
        foreach (['production' => 'PRODUCTION', 'dispatch' => 'DISPATCH (SENT)', 'receiving' => 'RECEIVING (RECEIVED)', 'variance' => 'VARIANCE', 'cashier' => 'CASHIER / CORRECTIONS', 'ledger' => 'LEDGER'] as $key => $title) {
            $group = $doc[$key] ?? [];
            $out[] = '-- ' . $title . ' [' . ($group['authoritative'] ?? '') . ']';
            $out[] = '   link: ' . ($group['link_url'] ?? '');
            if ($key === 'production') {
                $out[] = '   producer: ' . (($group['producer_name'] ?? '') !== '' ? $group['producer_name'] : 'not recorded');
                $out[] = '   produced_at: ' . (($group['produced_at'] ?? '') !== '' ? $group['produced_at'] : 'not recorded');
                foreach (($group['runs'] ?? []) as $run) {
                    $out[] = '   run: ' . $run['product'] . ' baker=' . ($run['baker'] !== '' ? $run['baker'] : 'not recorded') . ' yield=' . $run['yield'];
                }
                foreach (($group['movements'] ?? []) as $mv) {
                    $out[] = '   movement: ' . $mv['movement_type'] . ' ' . $mv['product'] . ' qty=' . $mv['quantity'];
                }
            } elseif ($key === 'dispatch') {
                $sentTotal = 0;
                foreach (($group['items'] ?? []) as $item) {
                    $sentTotal += (int)$item['quantity'];
                    $out[] = '   item: ' . $item['product'] . ' sent=' . $item['quantity'] . ' ' . $item['unit'];
                }
                $out[] = '   sent_total=' . $sentTotal . ' pcs';
            } elseif ($key === 'receiving') {
                if (empty($group['recorded'])) {
                    $out[] = '   not recorded';
                }
                $receivedTotal = 0;
                foreach (($group['rows'] ?? []) as $rcv) {
                    $out[] = '   receiving #' . ($rcv['id'] ?? '') . ' received_by=' . ($rcv['received_by_label'] ?? '') . ' at=' . ($rcv['received_at'] ?? '');
                    foreach (($rcv['items'] ?? []) as $ritem) {
                        $receivedTotal += (int)$ritem['quantity'];
                        $out[] = '     ritem: ' . $ritem['product'] . ' qty=' . $ritem['quantity'];
                    }
                }
                if (!empty($group['recorded'])) {
                    $out[] = '   received_total=' . $receivedTotal . ' pcs';
                }
            } elseif ($key === 'variance') {
                if (($group['items'] ?? []) === []) {
                    $out[] = '   (no difference)';
                }
                foreach (($group['items'] ?? []) as $vi) {
                    $out[] = '   ' . $vi['product'] . ' sent=' . $vi['sent'] . ' received=' . $vi['received_display'] . ' delta=' . $vi['delta_display'] . ' state=' . $vi['state'];
                }
            } elseif ($key === 'cashier') {
                foreach (($group['rows'] ?? []) as $cw) {
                    $out[] = '   cashier: ' . $cw['type_label'] . ' ' . $cw['product'] . ' qty=' . $cw['quantity'] . ' by ' . $cw['encoded_by'];
                }
                foreach (($group['corrections'] ?? []) as $corr) {
                    $out[] = '   correction: ' . $corr['field'] . ' ' . $corr['from'] . ' -> ' . $corr['to'] . ' (' . $corr['shift'] . ')';
                }
                if (($group['rows'] ?? []) === [] && ($group['corrections'] ?? []) === []) {
                    $out[] = '   not recorded';
                }
            } elseif ($key === 'ledger') {
                if (($group['rows'] ?? []) === []) {
                    $out[] = '   not recorded';
                }
                foreach (($group['rows'] ?? []) as $led) {
                    $out[] = '   ' . $led['source'] . ' ' . $led['product'] . ' ' . $led['shift'] . ' ' . $led['figures'];
                }
            }
        }
    }
    return implode("\n", $out) . "\n";
}
