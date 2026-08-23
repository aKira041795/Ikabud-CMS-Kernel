<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/durable-event-outbox.php';

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$pass = 0;
$fail = 0;
$errors = [];
$raceEvidence = '';
$idempotencyEvidence = '';

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
$effectsTable = 'kernel_durable_event_worker_effects';
$idemTable = 'kernel_durable_event_worker_idempotency';

$cleanup = static function () use ($pdo, $outboxTable, $effectsTable, $idemTable): void {
    foreach ([$effectsTable, $idemTable, $outboxTable] as $table) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        } catch (Throwable $e) {
        }
    }
};

$cleanup();

$resetOutbox = static function () use ($pdo, $outboxTable): void {
    $pdo->exec('DELETE FROM `' . $outboxTable . '`');
    $pdo->exec('ALTER TABLE `' . $outboxTable . '` AUTO_INCREMENT = 1');
};

$insertEvent = static function (string $tenantId, string $eventId, string $idempotencyKey, string $eventName = 'manifesto.worker.event', array $payload = []) use ($pdo): int {
    return writeDurableEventOutbox($pdo, [
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'event_name' => $eventName,
        'idempotency_key' => $idempotencyKey,
        'payload' => $payload,
        'source' => 'manifesto-worker-test',
        'request_id' => 'req-' . substr($eventId, 0, 8),
    ]);
};

try {
    $pdo->exec(durableEventOutboxDdl());
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `' . $effectsTable . '` ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`event_id` CHAR(36) NOT NULL,'
        . '`idempotency_key` VARCHAR(190) NOT NULL,'
        . '`note` VARCHAR(190) NOT NULL,'
        . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (`id`),'
        . 'UNIQUE KEY `uq_event` (`event_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `' . $idemTable . '` ('
        . '`idempotency_key` VARCHAR(190) NOT NULL,'
        . '`effect_count` INT UNSIGNED NOT NULL DEFAULT 0,'
        . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (`idempotency_key`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // (a) Atomic claim
    $resetOutbox();
    $firstId = $insertEvent('tenant-a', durableEventOutboxUuidV4(), 'idem-a-1', 'event.a', ['n' => 1]);
    $secondId = $insertEvent('tenant-a', durableEventOutboxUuidV4(), 'idem-a-2', 'event.a', ['n' => 2]);
    $thirdId = $insertEvent('tenant-a', durableEventOutboxUuidV4(), 'idem-a-3', 'event.a', ['n' => 3]);
    $claimed = durableOutboxClaim($pdo, 'tenant-a', 'worker-A');
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$outboxTable}` WHERE `tenant_id` = 'tenant-a' AND `status` = 'pending'")->fetchColumn();
    $claimedRow = $claimed[0] ?? null;
    t('atomic claim returns one row', count($claimed) === 1, json_encode($claimed));
    t('atomic claim returns lowest id row', (int) ($claimedRow['id'] ?? 0) === $firstId, 'expected=' . $firstId . ' actual=' . (string) ($claimedRow['id'] ?? '0'));
    t('atomic claim sets claimed status', ($claimedRow['status'] ?? '') === 'claimed');
    t('atomic claim sets lease owner', ($claimedRow['lease_owner'] ?? '') === 'worker-A');
    t('atomic claim sets 64-hex lease token', preg_match('/^[a-f0-9]{64}$/', (string) ($claimedRow['lease_token'] ?? '')) === 1, (string) ($claimedRow['lease_token'] ?? ''));
    t('atomic claim increments attempt_count to 1', (int) ($claimedRow['attempt_count'] ?? 0) === 1, (string) ($claimedRow['attempt_count'] ?? ''));
    t('atomic claim leaves other rows pending', $pendingCount === 2, (string) $pendingCount);
    t('atomic claim seeded three ids in order', $firstId < $secondId && $secondId < $thirdId, "{$firstId},{$secondId},{$thirdId}");

    // (b) Two-worker race
    $resetOutbox();
    $raceEventId = durableEventOutboxUuidV4();
    $insertEvent('tenant-race', $raceEventId, 'idem-race-1', 'event.race');
    $workerAClaim = durableOutboxClaim($pdo, 'tenant-race', 'worker-A');
    $workerBClaim = durableOutboxClaim($pdo, 'tenant-race', 'worker-B');
    $raceEvidence = 'A=' . count($workerAClaim) . ',B=' . count($workerBClaim) . ',winner_id=' . (string) (($workerAClaim[0]['id'] ?? 'none'));
    t('two-worker race only worker A wins', count($workerAClaim) === 1 && $workerBClaim === [], $raceEvidence);

    // (c) Process success
    $resetOutbox();
    $processedIds = [];
    $successEventId = durableEventOutboxUuidV4();
    $insertEvent('tenant-process', $successEventId, 'idem-process-1', 'event.process');
    $summary = durableOutboxProcess(
        $pdo,
        'tenant-process',
        'worker-process',
        static function (array $row, PDO $pdo) use (&$processedIds, $effectsTable): void {
            $processedIds[] = $row['event_id'];
            $stmt = $pdo->prepare('INSERT INTO `' . $effectsTable . '` (`event_id`, `idempotency_key`, `note`) VALUES (:event_id, :idempotency_key, :note)');
            $stmt->execute([
                ':event_id' => $row['event_id'],
                ':idempotency_key' => $row['idempotency_key'],
                ':note' => 'processed',
            ]);
        }
    );
    $processedRow = durableEventOutboxFind($pdo, 'tenant-process', $successEventId);
    $effectCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$effectsTable}` WHERE `event_id` = '" . $successEventId . "'")->fetchColumn();
    t('process success summary claimed=1', (int) $summary['claimed'] === 1, json_encode($summary));
    t('process success summary processed=1', (int) $summary['processed'] === 1, json_encode($summary));
    t('process success row becomes processed', ($processedRow['status'] ?? '') === 'processed');
    t('process success clears lease owner', ($processedRow['lease_owner'] ?? null) === null);
    t('process success clears lease token', ($processedRow['lease_token'] ?? null) === null);
    t('process success effect ran once', count($processedIds) === 1 && $effectCount === 1, 'array=' . count($processedIds) . ',db=' . $effectCount);

    // (d) Idempotent consumption
    $resetOutbox();
    $pdo->exec('DELETE FROM `' . $idemTable . '`');
    $duplicateKey = 'idem-once-1';
    $firstDupEvent = durableEventOutboxUuidV4();
    $secondDupEvent = durableEventOutboxUuidV4();
    $insertEvent('tenant-idem', $firstDupEvent, $duplicateKey, 'event.idem', ['copy' => 1]);
    $insertEvent('tenant-idem', $secondDupEvent, $duplicateKey . '-redelivery', 'event.idem', ['copy' => 2]);
    $idemHandler = static function (array $row, PDO $pdo) use ($idemTable, $duplicateKey): void {
        $businessKey = $duplicateKey;
        $insert = $pdo->prepare('INSERT IGNORE INTO `' . $idemTable . '` (`idempotency_key`, `effect_count`) VALUES (:idempotency_key, 1)');
        $insert->execute([':idempotency_key' => $businessKey]);
    };
    durableOutboxProcess($pdo, 'tenant-idem', 'worker-idem', $idemHandler);
    durableOutboxProcess($pdo, 'tenant-idem', 'worker-idem', $idemHandler);
    $idemCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$idemTable}` WHERE `idempotency_key` = '" . $duplicateKey . "'")->fetchColumn();
    $idemEffectCount = (int) $pdo->query("SELECT COALESCE(MAX(`effect_count`), 0) FROM `{$idemTable}` WHERE `idempotency_key` = '" . $duplicateKey . "'")->fetchColumn();
    $processedDupRows = (int) $pdo->query("SELECT COUNT(*) FROM `{$outboxTable}` WHERE `tenant_id` = 'tenant-idem' AND `status` = 'processed'")->fetchColumn();
    $idempotencyEvidence = 'rows=' . $processedDupRows . ',guard_rows=' . $idemCount . ',effect_count=' . $idemEffectCount;
    t('idempotent consumption applies business effect once', $idemCount === 1 && $idemEffectCount === 1, $idempotencyEvidence);
    t('idempotent consumption still processes both deliveries', $processedDupRows === 2, (string) $processedDupRows);

    // (e) Retry + backoff
    $resetOutbox();
    $retryEventId = durableEventOutboxUuidV4();
    $insertEvent('tenant-retry', $retryEventId, 'idem-retry-1', 'event.retry');
    $retrySummary = durableOutboxProcess(
        $pdo,
        'tenant-retry',
        'worker-retry',
        static function (): void {
            throw new RuntimeException('retry me');
        },
        ['backoffSeconds' => 30, 'maxAttempts' => 5]
    );
    $retryRow = durableEventOutboxFind($pdo, 'tenant-retry', $retryEventId);
    $retryBlockedClaim = durableOutboxClaim($pdo, 'tenant-retry', 'worker-retry-2', 60, 5);
    $pdo->prepare("UPDATE `{$outboxTable}` SET `available_at` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `tenant_id` = :tenant AND `event_id` = :event_id")
        ->execute([':tenant' => 'tenant-retry', ':event_id' => $retryEventId]);
    $retryAllowedClaim = durableOutboxClaim($pdo, 'tenant-retry', 'worker-retry-3', 60, 5);
    t('retry summary marks one failed attempt', (int) $retrySummary['failed'] === 1, json_encode($retrySummary));
    t('retry puts row back to pending', ($retryRow['status'] ?? '') === 'pending');
    t('retry leaves attempt_count at 1 after first failure', (int) ($retryRow['attempt_count'] ?? 0) === 1, (string) ($retryRow['attempt_count'] ?? ''));
    t('retry sets available_at in the future', strtotime((string) $retryRow['available_at']) > time(), (string) ($retryRow['available_at'] ?? ''));
    t('retry blocks second claim before available_at', $retryBlockedClaim === []);
    t('retry row becomes claimable again after backoff window', count($retryAllowedClaim) === 1);
    t('retry second claim increments attempt_count to 2', (int) (($retryAllowedClaim[0]['attempt_count'] ?? 0)) === 2, (string) (($retryAllowedClaim[0]['attempt_count'] ?? '')));

    // (f) Dead-letter
    $resetOutbox();
    $deadEventId = durableEventOutboxUuidV4();
    $insertEvent('tenant-dead', $deadEventId, 'idem-dead-1', 'event.dead');
    $deadSummary1 = durableOutboxProcess(
        $pdo,
        'tenant-dead',
        'worker-dead',
        static function (): void {
            throw new RuntimeException('still failing');
        },
        ['backoffSeconds' => 1, 'maxAttempts' => 2]
    );
    $pdo->prepare("UPDATE `{$outboxTable}` SET `available_at` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `tenant_id` = :tenant AND `event_id` = :event_id")
        ->execute([':tenant' => 'tenant-dead', ':event_id' => $deadEventId]);
    $deadSummary2 = durableOutboxProcess(
        $pdo,
        'tenant-dead',
        'worker-dead',
        static function (): void {
            throw new RuntimeException('still failing');
        },
        ['backoffSeconds' => 1, 'maxAttempts' => 2]
    );
    $deadRow = durableEventOutboxFind($pdo, 'tenant-dead', $deadEventId);
    t('dead-letter first failure is retried not dead-lettered', (int) $deadSummary1['dead_letter'] === 0, json_encode($deadSummary1));
    t('dead-letter second failure is dead-lettered', (int) $deadSummary2['dead_letter'] === 1, json_encode($deadSummary2));
    t('dead-letter row ends dead_letter', ($deadRow['status'] ?? '') === 'dead_letter');

    // (g) Crash recovery
    $resetOutbox();
    $crashEventId = durableEventOutboxUuidV4();
    $insertEvent('tenant-crash', $crashEventId, 'idem-crash-1', 'event.crash');
    $crashClaim = durableOutboxClaim($pdo, 'tenant-crash', 'worker-crash');
    $pdo->prepare("UPDATE `{$outboxTable}` SET `lease_expires_at` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `tenant_id` = :tenant AND `event_id` = :event_id")
        ->execute([':tenant' => 'tenant-crash', ':event_id' => $crashEventId]);
    $swept = durableOutboxSweepExpired($pdo, 'tenant-crash');
    $crashRow = durableEventOutboxFind($pdo, 'tenant-crash', $crashEventId);
    $reclaimed = durableOutboxClaim($pdo, 'tenant-crash', 'worker-crash-2');
    t('crash recovery initial claim succeeds', count($crashClaim) === 1);
    t('crash recovery sweep requeues one row', $swept === 1, (string) $swept);
    t('crash recovery row back to pending', ($crashRow['status'] ?? '') === 'pending');
    t('crash recovery row claimable again', count($reclaimed) === 1);

    // (h) MySQL 5.7 safety
    $helperSource = (string) file_get_contents(__DIR__ . '/../src/helpers/durable-event-outbox.php');
    t('claim SQL contains ORDER BY `id` LIMIT 1', str_contains($helperSource, 'ORDER BY `id` LIMIT 1'));
    t('helper omits SKIP LOCKED', stripos($helperSource, 'SKIP LOCKED') === false);
    t('helper omits NOWAIT', stripos($helperSource, 'NOWAIT') === false);
    t('helper omits window syntax OVER (', stripos($helperSource, 'OVER (') === false);
    t('helper omits CTE WITH ', stripos($helperSource, 'WITH ') === false);
} catch (Throwable $e) {
    t('worker test harness completed without exception', false, $e->getMessage());
} finally {
    $cleanup();
}

$appLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string) @file_get_contents(STORAGE_PATH . '/logs/error.log'));

t('app.log remains empty', $appLog === '', $appLog !== '' ? substr($appLog, 0, 200) : '');
t('error.log remains empty', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "RACE {$raceEvidence}\n";
echo "IDEMPOTENCY {$idempotencyEvidence}\n";
echo "\nPass: {$pass}\nFail: {$fail}\n";
if ($fail > 0) {
    echo "\nFailures:\n- " . implode("\n- ", $errors) . "\n";
    exit(1);
}
