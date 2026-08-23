<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/durable-event-outbox.php';

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

$pdo = app()->controlDb();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$appEvents = app()->events();
if (method_exists($appEvents, 'reset')) {
    $appEvents->reset();
}

$outboxTable = 'kernel_durable_event_outbox';
$cleanup = static function () use ($pdo, $outboxTable): void {
    try {
        $pdo->exec('DROP TABLE IF EXISTS `' . $outboxTable . '`');
    } catch (Throwable $e) {
    }
};

$cleanup();

try {
    $pdo->exec(durableEventOutboxDdl());

    $commitEventId = durableEventOutboxUuidV4();
    $pdo->beginTransaction();
    $commitRowId = $appEvents->fireDurable('order.placed', ['id' => 1], $pdo, [
        'tenant_id' => 't1',
        'source' => 'ecommerce',
        'event_id' => $commitEventId,
    ]);
    $pdo->commit();

    $committedRow = durableEventOutboxFind($pdo, 't1', $commitEventId);
    t('commit coupling persists row after commit', is_array($committedRow));

    $rollbackEventId = durableEventOutboxUuidV4();
    $pdo->beginTransaction();
    $rollbackRowId = $appEvents->fireDurable('order.placed', ['id' => 2], $pdo, [
        'tenant_id' => 't1',
        'source' => 'ecommerce',
        'event_id' => $rollbackEventId,
    ]);
    $pdo->rollBack();

    t('rollback coupling omits row after rollback', durableEventOutboxFind($pdo, 't1', $rollbackEventId) === null);
    t('fireDurable returns committed outbox row id', is_int($commitRowId) && $commitRowId > 0, (string) $commitRowId);
    t('fireDurable returns rollback-time insert id before rollback', is_int($rollbackRowId) && $rollbackRowId > 0, (string) $rollbackRowId);
    t('committed row id matches returned id', (int) ($committedRow['id'] ?? 0) === $commitRowId, 'expected=' . (string) $commitRowId . ' actual=' . (string) ($committedRow['id'] ?? '0'));
    t('provenance source recorded', ($committedRow['source'] ?? '') === 'ecommerce');
    t('provenance tenant_id recorded', ($committedRow['tenant_id'] ?? '') === 't1');
    t('payload_hash recorded correctly', ($committedRow['payload_hash'] ?? '') === hash('sha256', (string) json_encode(['id' => 1])), (string) ($committedRow['payload_hash'] ?? ''));

    $missingTenantThrown = false;
    try {
        $appEvents->fireDurable('order.placed', ['id' => 3], $pdo, ['source' => 'ecommerce']);
    } catch (InvalidArgumentException $e) {
        $missingTenantThrown = true;
    }
    t('missing tenant_id throws InvalidArgumentException', $missingTenantThrown);

    $listenerCalls = 0;
    $appEvents->listen('smoke.event', static function () use (&$listenerCalls): void {
        $listenerCalls++;
    }, 10, 'test');
    $fired = $appEvents->fire('smoke.event', [], 'test');
    t('fire regression still invokes listener', $listenerCalls === 1, (string) $listenerCalls);
    t('fire regression still returns listener count', $fired === 1, (string) $fired);
} catch (Throwable $e) {
    t('eventbus durable harness completed without exception', false, $e->getMessage());
} finally {
    if (method_exists($appEvents, 'reset')) {
        $appEvents->reset();
    }
    $cleanup();
}

$appLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/error.log'));

t('app.log remains empty', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
t('error.log remains empty', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\nPass: {$pass}\nFail: {$fail}\n";
if ($fail > 0) {
    echo "\nFailures:\n- " . implode("\n- ", $errors) . "\n";
    exit(1);
}
