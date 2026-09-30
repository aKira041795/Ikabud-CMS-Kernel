<?php

declare(strict_types=1);

/**
 * Daily Ledger — delayed producer entry (066).
 *
 * Paper reporting and digital encoding are not real time. Before 066 a paper DR
 * carried only the encoder (created_by/posted_by) and the encoding moment
 * (posted_at), so the Daily Sheet SENT row named the encoder as if they had
 * produced the goods. This suite drives the REAL `apiReceivePaperDelivery()`
 * endpoint in a subprocess (the endpoint reads php://input and exits through
 * $ctx->json()) and pins, by behaviour:
 *
 *   AC1  the migration adds produced_by/produced_at, guarded and re-runnable
 *   AC2  a capture with a producer + produced_at persists both; without them
 *        both stay NULL
 *   AC3  the SENT log row shows the producer name and the production time
 *   AC4  the encoder stays visible in the same row
 *   AC5  RECEIVED rows are unchanged; a NULL producer reproduces the old output
 *        except for the explicit single-paper-capture provenance marker (actor
 *        = encoder and when = posted_at remain unchanged, with no
 *        encoder suffix)
 *   AC6  an invalid/inactive produced_by or malformed produced_at is refused
 *        with 422 and nothing is written
 *   AC7  an idempotent replay neither duplicates the delivery nor erases a
 *        recorded producer; a replay that omits the fields does not null them
 *
 * Tenant 207 (baron-001). Every fixture row uses the 995xx id range and is
 * removed in a finally block; the suite never asserts whole-tenant emptiness.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delayed-producer', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/database/migrations/066_add_delivery_producer.sql');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_delayed_producer_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissaryId = 99501;
$branchId = 99502;
$productId = 99501;
$producerId = 99501;       // active production_in_charge
$inactiveProducerId = 99502; // is_active = 0
$deletedProducerId = 99504;  // deleted_at set
$actorId = 99503;          // active admin (the encoder)

$harnessPath = __DIR__ . '/daily_ledger_delayed_producer_harness.php';
// Idempotent responses are cached on disk for a day. A fresh run token keeps a
// previous run's cache entry from replaying a fixture row this run already
// removed, while both calls within this run still share the replay key.
$runToken = bin2hex(random_bytes(4));

$runHarness = static function (array $args) use ($harnessPath): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessPath);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>/dev/null', $output, $exitCode);
    $raw = implode("\n", $output);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $m)) {
        $status = (int)$m[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
        'exit' => $exitCode,
    ];
};

$capture = static function (array $payload, int $actor) use ($runHarness): array {
    $file = sys_get_temp_dir() . '/dl-producer-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode($payload));
    $result = $runHarness(['capture', $file, (string)$actor]);
    @unlink($file);
    return $result;
};

$basePayload = static function (string $dr, string $date) use ($branchId, $productId): array {
    return [
        'branch_id' => $branchId,
        'origin_type' => 'commissary',
        'origin_id' => null,
        'delivery_date' => $date,
        'receive_date' => $date,
        'dr_number' => $dr,
        'items' => [['product_id' => $productId, 'quantity' => 5, 'unit' => 'pcs']],
    ];
};

$cleanup = static function () use ($db, $commissaryId, $branchId, $productId, $producerId, $inactiveProducerId, $deletedProducerId, $actorId): void {
    $b = "{$commissaryId},{$branchId}";
    $u = "{$producerId},{$inactiveProducerId},{$deletedProducerId},{$actorId}";
    $branchFilter = "(SELECT id FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b}))";
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN {$branchFilter}");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$b}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$b}) OR delivery_id IN {$branchFilter}");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN {$branchFilter}");
    // The paper captures use a commissary origin, so their create_delivery / update_delivery
    // audit rows carry branch_id NULL and would survive the branch_id sweep below. Remove
    // them by the delivery ids they name, before the deliveries themselves are gone.
    $db->execute("DELETE FROM audit_logs WHERE entity_type = 'dl_deliveries' AND entity_id IN (SELECT CAST(id AS CHAR) FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b}))");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$b})");
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$b}) OR product_id = {$productId}");
    $db->execute("DELETE FROM dl_products WHERE id = {$productId}");
    $db->execute("DELETE FROM dl_user_branches WHERE user_id IN ({$u})");
    $db->execute("DELETE FROM dl_users WHERE id IN ({$u})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$b})");
};

$cleanup();

$findSent = static function (array $log, string $dr): ?array {
    foreach ($log as $entry) {
        if ((string)$entry['field'] === 'SENT' && str_contains((string)$entry['reason'], 'DR ' . $dr)) {
            return $entry;
        }
    }
    return null;
};
$findReceived = static function (array $log, string $dr): ?array {
    foreach ($log as $entry) {
        if ((string)$entry['field'] === 'RECEIVED' && str_contains((string)$entry['reason'], 'DR ' . $dr)) {
            return $entry;
        }
    }
    return null;
};
$countDeliveries = static function (string $dr) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM dl_deliveries WHERE dr_number = :dr');
    $stmt->execute([':dr' => $dr]);
    return (int)$stmt->fetchColumn();
};

// ─── Static: migration, handler and template wiring ────────────────
$h->section('Static: migration, handler and template wiring');

$migrationPath = $base . '/modules/daily-ledger/database/migrations/066_add_delivery_producer.sql';
$migrationSql = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
// Only the executable body matters for MySQL 8 features; the header deliberately
// names the constructs it must avoid.
$migrationBody = implode("\n", array_filter(
    explode("\n", $migrationSql),
    static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
));
$moduleJson = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$receiveModal = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
$ledgerTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');

$h->test(
    'AC1 migration 066 exists and is registered in module.json',
    $migrationSql !== ''
    && str_contains($moduleJson, 'database/migrations/066_add_delivery_producer.sql')
);
$h->test(
    'AC1 the migration is guarded per column via information_schema',
    str_contains($migrationSql, '@produced_by_exists')
    && str_contains($migrationSql, '@produced_at_exists')
    && str_contains($migrationSql, 'information_schema.columns')
    && str_contains($migrationSql, 'PREPARE stmt')
);
$h->test(
    'AC1 produced_by matches dl_users.id (INT UNSIGNED) and produced_at is DATETIME NULL',
    str_contains($migrationSql, 'ADD COLUMN produced_by INT UNSIGNED NULL')
    && str_contains($migrationSql, 'ADD COLUMN produced_at DATETIME NULL')
);
$h->test(
    'AC1 the migration adds no FOREIGN KEY and no MySQL 8-only construct',
    !str_contains($migrationBody, 'FOREIGN KEY')
    && !preg_match('/\b(WITH|OVER\s*\(|JSON_TABLE|CHECK\s*\()/i', $migrationBody)
);
$h->test(
    'AC2/AC6 the handler has a produced_at normalizer and producer validation',
    str_contains($handlers, 'function dl_normalizeProducedAt')
    && str_contains($handlers, 'Produced by must be an existing, active user.')
    && str_contains($handlers, 'Produced at must be a valid date or date-time.')
);
$h->test(
    'the SENT query falls back per field and never changes a NULL producer row',
    str_contains($handlers, '$sentWho = ($producedBy !== null && $producerName !== \'\') ? $producerName : $encoderName;')
    && str_contains($handlers, '$sentWhen = ($producedAt !== null && $producedAt !== \'\') ? $producedAt : $sentAt;')
    && str_contains($handlers, "if (\$producedBy !== null && \$encoderName !== '')")
);
$h->test(
    'the producer options list is server-side and passed to the cashier template',
    str_contains($handlers, 'function dl_productionProducerOptions')
    && str_contains($handlers, "'producer_options' => dl_productionProducerOptions(\$ctx->db())")
);
$h->test(
    'AC2/AC4 the receive modal posts produced_by/produced_at and renders the controls',
    str_contains($receiveModal, 'produced_by: this.paperForm.produced_by || null')
    && str_contains($receiveModal, 'produced_at: this.paperForm.produced_at || null')
    && str_contains($receiveModal, 'x-model="paperForm.produced_by"')
    && str_contains($receiveModal, 'x-model="paperForm.produced_at"')
    && str_contains($receiveModal, '{foreach producer_options as pu}')
);
$h->test(
    'the ledger replay forwards the producer fields on a drained paper DR',
    str_contains($ledgerTpl, "entry.op === 'receive_paper_dr'")
    && str_contains($ledgerTpl, 'payload.produced_by')
    && str_contains($ledgerTpl, 'payload.produced_at')
);

// ─── Runtime: fixtures ─────────────────────────────────────────────
$h->section('Runtime: capture persists the delayed producer');

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'DLP-COMM', 'DLP Commissary', 'self_managed', null, 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'DLP-BR', 'DLP Branch', 'commissary_supplied', $commissaryId, 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$productId, 'DLP-PROD', 'DLP Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$producerId, 'dlp-producer', 'x', 'Delayed Producer', 'production_in_charge', 1, null]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$inactiveProducerId, 'dlp-inactive', 'x', 'Inactive Producer', 'production_in_charge', 0, null]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$deletedProducerId, 'dlp-deleted', 'x', 'Deleted Producer', 'production_in_charge', 1, '2024-01-01 00:00:00']);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$actorId, 'dlp-encoder', 'x', 'Fixture Encoder', 'admin', 1, null]);

    // AC2 + AC3 + AC4: producer + produced_at persist and render.
    $withProducer = $basePayload('DLP-DR-0001', '2024-03-01');
    $withProducer['produced_by'] = $producerId;
    $withProducer['produced_at'] = '2024-02-28T05:30';
    $withProducer['idempotency_key'] = 'dlp-cap-0001-' . $runToken;
    $result = $capture($withProducer, $actorId);

    $h->test('AC2 capture with a producer returns 200/ok and a delivery id', ($result['status'] === 200) && (($result['body']['ok'] ?? false) === true) && (($result['body']['delivery_id'] ?? 0) > 0), $result['raw']);
    $deliveryId = (int)($result['body']['delivery_id'] ?? 0);

    $rowStmt = $db->prepare('SELECT produced_by, produced_at, created_by, posted_by, posted_at FROM dl_deliveries WHERE id = :id');
    $rowStmt->execute([':id' => $deliveryId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC2 the producer and production time are stored on the delivery',
        (int)($row['produced_by'] ?? 0) === $producerId
        && (string)($row['produced_at'] ?? '') === '2024-02-28 05:30:00'
    );

    $log = dl_fetchProductionLedgerLog($db, $commissaryId, '2024-03-01');
    $sent = $findSent($log, 'DLP-DR-0001');
    $received = $findReceived($log, 'DLP-DR-0001');
    $h->test(
        'AC3 the SENT row names the producer and the produced_at moment',
        $sent !== null
        && $sent['who'] === 'Delayed Producer'
        && $sent['when'] === '2024-02-28 05:30:00'
    );
    $h->test(
        'AC4 the encoder is still identifiable in the same SENT row',
        $sent !== null
        && str_contains((string)$sent['reason'], 'DR DLP-DR-0001')
        && str_contains((string)$sent['reason'], '· enc: Fixture Encoder')
    );

    $rcvStmt = $db->prepare('SELECT received_at FROM dl_branch_receivings WHERE delivery_id = :id LIMIT 1');
    $rcvStmt->execute([':id' => $deliveryId]);
    $receivedAt = (string)($rcvStmt->fetchColumn() ?: '');
    $h->test(
        'AC5 the RECEIVED row keeps the receiving user and received_at (producer does not leak into it)',
        $received !== null
        && $received['who'] === 'Fixture Encoder'
        && $received['when'] === $receivedAt
        && $received['source'] === 'receiving'
    );

    // A late producer on an already-created, not-yet-received capture: update
    // only when supplied, never null out what is already recorded.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (id, delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at, remarks, produced_by, produced_at)
         VALUES (?, ?, "commissary", NULL, "branch", ?, ?, "posted", ?, ?, ?, "[captured-from-paper-dr]", NULL, NULL)'
    )->execute([995001, '2024-03-06', $branchId, 'DLP-DR-0006', $actorId, $actorId, '2024-03-06 01:00:00']);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 5, "pcs", 0)')
        ->execute([995001, $productId]);
    $late = $basePayload('DLP-DR-0006', '2024-03-06');
    $late['produced_by'] = $producerId;
    $late['produced_at'] = '2024-03-05 22:10:00';
    $lateResult = $capture($late, $actorId);
    $lateRow = $db->prepare('SELECT produced_by, produced_at FROM dl_deliveries WHERE id = 995001');
    $lateRow->execute();
    $lateValues = $lateRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC7 a supplied producer is written onto an existing capture',
        ($lateResult['status'] === 200)
        && (int)($lateValues['produced_by'] ?? 0) === $producerId
        && (string)($lateValues['produced_at'] ?? '') === '2024-03-05 22:10:00'
    );
    $auditStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'update_delivery' AND entity_id = '995001'");
    $auditStmt->execute();
    $h->test('AC4 a producer set on an existing capture is logged as an audit event', (int)$auditStmt->fetchColumn() > 0);

    // A replay that omits the fields must not erase the recorded producer.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (id, delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at, remarks, produced_by, produced_at)
         VALUES (?, ?, "commissary", NULL, "branch", ?, ?, "posted", ?, ?, ?, "[captured-from-paper-dr]", ?, ?)'
    )->execute([995002, '2024-03-07', $branchId, 'DLP-DR-0007', $actorId, $actorId, '2024-03-07 01:00:00', $producerId, '2024-03-06 20:00:00']);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 5, "pcs", 0)')
        ->execute([995002, $productId]);
    $omitResult = $capture($basePayload('DLP-DR-0007', '2024-03-07'), $actorId);
    $omitRow = $db->prepare('SELECT produced_by, produced_at FROM dl_deliveries WHERE id = 995002');
    $omitRow->execute();
    $omitValues = $omitRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC7 a replay that omits the fields leaves the recorded producer intact',
        ($omitResult['status'] === 200)
        && (int)($omitValues['produced_by'] ?? 0) === $producerId
        && (string)($omitValues['produced_at'] ?? '') === '2024-03-06 20:00:00'
    );

    // AC2 + AC5: no producer at all — columns NULL and the old log output exactly.
    $plain = $basePayload('DLP-DR-0002', '2024-03-02');
    $plainResult = $capture($plain, $actorId);
    $plainId = (int)($plainResult['body']['delivery_id'] ?? 0);
    $plainStmt = $db->prepare('SELECT produced_by, produced_at, posted_at FROM dl_deliveries WHERE id = :id');
    $plainStmt->execute([':id' => $plainId]);
    $plainRow = $plainStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $plainLog = dl_fetchProductionLedgerLog($db, $commissaryId, '2024-03-02');
    $plainSent = $findSent($plainLog, 'DLP-DR-0002');
    $h->test(
        'AC2 a capture without the fields leaves both columns NULL',
        ($plainResult['status'] === 200)
        && array_key_exists('produced_by', $plainRow) && $plainRow['produced_by'] === null
        && array_key_exists('produced_at', $plainRow) && $plainRow['produced_at'] === null
    );
    $h->test(
        'AC5 the NULL-producer SENT row keeps encoder/time and exposes paper-capture provenance',
        $plainSent !== null
        && $plainSent['who'] === 'Fixture Encoder'
        && $plainSent['when'] === (string)$plainRow['posted_at']
        && str_contains($plainSent['reason'], 'DR DLP-DR-0002')
        && str_contains($plainSent['reason'], 'single paper capture')
    );

    // AC7: idempotent replay returns the same delivery and does not duplicate.
    $replayPayload = $basePayload('DLP-DR-0008', '2024-03-08');
    $replayPayload['produced_by'] = $producerId;
    $replayPayload['produced_at'] = '2024-03-08T06:00';
    $replayPayload['idempotency_key'] = 'dlp-idem-0008-' . $runToken;
    $first = $capture($replayPayload, $actorId);
    $second = $capture($replayPayload, $actorId);
    $replayId = (int)($first['body']['delivery_id'] ?? 0);
    $replayStmt = $db->prepare('SELECT produced_by FROM dl_deliveries WHERE id = :id');
    $replayStmt->execute([':id' => $replayId]);
    $h->test(
        'AC7 an idempotent replay returns the same delivery, does not duplicate and keeps the producer',
        $replayId > 0
        && (int)($second['body']['delivery_id'] ?? 0) === $replayId
        && $countDeliveries('DLP-DR-0008') === 1
        && (int)($replayStmt->fetchColumn() ?: 0) === $producerId
    );

    // AC6: invalid / inactive / deleted producer refused, nothing written.
    $badProducerPayloads = [
        'non-numeric' => 'DLP-DR-0003',
        'inactive' => 'DLP-DR-0009',
        'deleted' => 'DLP-DR-0010',
        'unknown' => 'DLP-DR-0011',
    ];
    $badProducerValues = [
        'non-numeric' => 'abc',
        'inactive' => $inactiveProducerId,
        'deleted' => $deletedProducerId,
        'unknown' => 99999999,
    ];
    foreach ($badProducerPayloads as $label => $dr) {
        $payload = $basePayload($dr, '2024-03-03');
        $payload['produced_by'] = $badProducerValues[$label];
        $res = $capture($payload, $actorId);
        $h->test(
            "AC6 a {$label} produced_by is refused with 422 and nothing is written",
            $res['status'] === 422
            && $countDeliveries($dr) === 0
        );
    }
    // Also the contract's inactive-user path must not silently ignore a supplied value.
    $inactivePayload = $basePayload('DLP-DR-0012', '2024-03-03');
    $inactivePayload['produced_by'] = $inactiveProducerId;
    $inactiveRes = $capture($inactivePayload, $actorId);
    $h->test(
        'AC6 the refused inactive producer reports a clear error, not a silent success',
        $inactiveRes['status'] === 422
        && is_string($inactiveRes['body']['error'] ?? null)
        && str_contains((string)$inactiveRes['body']['error'], 'active user')
    );

    // AC6: malformed produced_at refused, nothing written.
    foreach (['not-a-date' => 'DLP-DR-0004', '2024-13-45' => 'DLP-DR-0013'] as $bad => $dr) {
        $payload = $basePayload($dr, '2024-03-04');
        $payload['produced_at'] = $bad;
        $res = $capture($payload, $actorId);
        $h->test(
            "AC6 a malformed produced_at ('{$bad}') is refused with 422 and nothing is written",
            $res['status'] === 422
            && $countDeliveries($dr) === 0
        );
    }
} finally {
    $cleanup();
}

$h->done();
