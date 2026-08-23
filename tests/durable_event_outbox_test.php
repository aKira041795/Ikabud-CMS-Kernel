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

$outboxTable = 'kernel_durable_event_outbox';
$scratchTable = 'kernel_durable_event_outbox_test_markers';

$cleanup = static function () use ($pdo, $scratchTable, $outboxTable): void {
    try {
        $pdo->exec('DROP TABLE IF EXISTS `' . $scratchTable . '`');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('DROP TABLE IF EXISTS `' . $outboxTable . '`');
    } catch (Throwable $e) {
    }
};

$cleanup();

try {
    $ddl = durableEventOutboxDdl();
    $pdo->exec($ddl);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `' . $scratchTable . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`marker` VARCHAR(64) NOT NULL,'
        . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uq_marker` (`marker`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $uuid = durableEventOutboxUuidV4();
    t('UUID v4 format is valid', preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid) === 1, $uuid);

    t('DDL contains ENGINE=InnoDB', str_contains($ddl, 'ENGINE=InnoDB'));
    t('DDL contains utf8mb4', str_contains($ddl, 'utf8mb4'));
    t('DDL omits OVER (', stripos($ddl, 'OVER (') === false);
    t('DDL omits WITH ', stripos($ddl, 'WITH ') === false);
    t('DDL omits JSON_TABLE', stripos($ddl, 'JSON_TABLE') === false);
    t('DDL omits ADD COLUMN IF NOT EXISTS', stripos($ddl, 'ADD COLUMN IF NOT EXISTS') === false);
    t('DDL omits CREATE INDEX IF NOT EXISTS', stripos($ddl, 'CREATE INDEX IF NOT EXISTS') === false);
    t('DDL omits functional index syntax', preg_match('/KEY\s+`[^`]+`\s*\(\s*\(/i', $ddl) !== 1);

    $committedEvent = [
        'tenant_id' => 'tenant-poc',
        'event_id' => durableEventOutboxUuidV4(),
        'event_name' => 'manifesto.mutation.committed',
        'payload' => ['record' => 'alpha', 'count' => 1],
        'source' => 'manifesto-poc',
        'actor_id' => 'user-123',
        'actor_role' => 'admin',
        'request_id' => 'req-commit-1',
        'idempotency_key' => 'idem-commit-1',
    ];

    $pdo->beginTransaction();
    $markerStmt = $pdo->prepare('INSERT INTO `' . $scratchTable . '` (`marker`) VALUES (?)');
    $markerStmt->execute(['commit-marker']);
    $insertId = writeDurableEventOutbox($pdo, $committedEvent);
    $pdo->commit();

    t('writeDurableEventOutbox returns insert id', $insertId > 0, (string)$insertId);

    $foundCommitted = durableEventOutboxFind($pdo, $committedEvent['tenant_id'], $committedEvent['event_id']);
    t('committed event row is found', is_array($foundCommitted));
    t('commit marker row is found', (int)$pdo->query('SELECT COUNT(*) FROM `' . $scratchTable . '` WHERE `marker` = "commit-marker"')->fetchColumn() === 1);

    $expectedHash = hash('sha256', (string) json_encode($committedEvent['payload']));
    t('provenance tenant_id persisted', ($foundCommitted['tenant_id'] ?? null) === $committedEvent['tenant_id']);
    t('provenance source persisted', ($foundCommitted['source'] ?? null) === $committedEvent['source']);
    t('provenance actor_id persisted', ($foundCommitted['actor_id'] ?? null) === $committedEvent['actor_id']);
    t('provenance request_id persisted', ($foundCommitted['request_id'] ?? null) === $committedEvent['request_id']);
    t('payload_hash persisted', ($foundCommitted['payload_hash'] ?? null) === $expectedHash, (string)($foundCommitted['payload_hash'] ?? ''));

    $rolledBackEvent = [
        'tenant_id' => 'tenant-poc',
        'event_id' => durableEventOutboxUuidV4(),
        'event_name' => 'manifesto.mutation.rolled_back',
        'payload' => ['record' => 'beta', 'count' => 2],
        'source' => 'manifesto-poc',
        'actor_id' => 'user-999',
        'request_id' => 'req-rollback-1',
    ];

    $pdo->beginTransaction();
    $markerStmt->execute(['rollback-marker']);
    writeDurableEventOutbox($pdo, $rolledBackEvent);
    $pdo->rollBack();

    t('rolled back event row is absent', durableEventOutboxFind($pdo, $rolledBackEvent['tenant_id'], $rolledBackEvent['event_id']) === null);
    t('rollback marker row is absent', (int)$pdo->query('SELECT COUNT(*) FROM `' . $scratchTable . '` WHERE `marker` = "rollback-marker"')->fetchColumn() === 0);

    $duplicateObserved = false;
    try {
        writeDurableEventOutbox($pdo, [
            'tenant_id' => $committedEvent['tenant_id'],
            'event_id' => $committedEvent['event_id'],
            'event_name' => 'manifesto.mutation.duplicate',
            'payload' => ['duplicate' => true],
            'source' => 'manifesto-poc',
            'request_id' => 'req-dup-1',
        ]);
    } catch (Throwable $e) {
        $duplicateObserved = str_contains(strtolower($e->getMessage()), 'duplicate')
            || ((string)$e->getCode() === '23000');
    }
    t('duplicate (tenant_id, event_id) is rejected', $duplicateObserved);
} catch (Throwable $e) {
    t('test harness completed without exception', false, $e->getMessage());
} finally {
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
