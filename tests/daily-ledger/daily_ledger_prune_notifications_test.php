<?php

declare(strict_types=1);

/**
 * Daily Ledger — integrity-notification prune: the safety invariants
 *
 * The prune is now reachable from the Settings panel as well as the CLI. The
 * thing that must never happen is the two acquiring different ideas of what
 * counts as orphaned, so both call one predicate: dlPruneWhere() in
 * modules/daily-ledger/helpers/maintenance.php.
 *
 * This suite proves the predicate's four safety properties directly, by
 * composing the PRODUCTION predicate into a query scoped to this test's own
 * seeded rows:
 *
 *   1. a notification whose variance flag is GONE and that every recipient has
 *      read IS eligible;
 *   2. a notification whose flag is gone but that ANY recipient has not read is
 *      NEVER eligible - an unread message must survive even when its finding
 *      has vanished;
 *   3. a notification whose flag still exists is never eligible;
 *   4. only finding_type='variance' rows are considered at all;
 *   5. the retention floor protects recent rows, and 0 disables the floor.
 *
 * It also proves the two callers cannot drift (neither defines a local copy of
 * the predicate), the shared lock refuses a second concurrent worker, and the
 * batch delete removes exactly the eligible seeded row.
 *
 * Integration mode - real tenant DB (207). Every row it creates carries a unique
 * aggregate_key prefix and is removed at the end. The batch-delete assertion
 * runs inside a transaction that is rolled back, so it cannot damage the data
 * even if the suite is run against a freshly imported live copy.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-prune-notifications', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/helpers/maintenance.php');
$h->fingerprint('modules/daily-ledger/cli/prune-integrity-notifications.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/admin/settings.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $ctx->db();

// ─── Fixture ─────────────────────────────────────────────────────────────
$marker = 'PRUNE-TEST-' . bin2hex(random_bytes(4)) . '-';
$oldDate = '2019-12-31';

$userId = (int)$db->query('SELECT id FROM dl_users ORDER BY id LIMIT 1')->fetchColumn();
$branchId = (int)$db->query('SELECT id FROM dl_branches ORDER BY id LIMIT 1')->fetchColumn();
$productId = (int)$db->query('SELECT id FROM dl_products ORDER BY id LIMIT 1')->fetchColumn();

if ($userId <= 0 || $branchId <= 0 || $productId <= 0) {
    fwrite(STDERR, "fixture prerequisites missing (user/branch/product)\n");
    exit(1);
}

/**
 * Seed one notification and return its id. Recipients are added separately.
 * The id is looked up by aggregate_key rather than lastInsertId(), which the
 * database contract does not expose.
 */
function dlPruneTestSeed($db, string $aggregateKey, string $findingType, ?int $entityId, string $raisedAt): int
{
    $db->execute(
        'INSERT INTO dl_integrity_notifications
            (aggregate_key, finding_type, branch_id, entity_type, entity_id, finding_count, title, raised_at)
         VALUES (:k, :ft, NULL, :et, :eid, 1, :title, :ra)',
        [
            ':k' => $aggregateKey,
            ':ft' => $findingType,
            ':et' => 'dl_variance_flags',
            ':eid' => $entityId,
            ':title' => 'prune test fixture',
            ':ra' => $raisedAt,
        ]
    );
    $st = $db->prepare('SELECT id FROM dl_integrity_notifications WHERE aggregate_key = :k');
    $st->execute([':k' => $aggregateKey]);

    return (int)$st->fetchColumn();
}

function dlPruneTestSeedRecipient($db, int $notificationId, int $userId, ?string $seenAt): void
{
    $db->execute(
        'INSERT INTO dl_integrity_notification_recipients (notification_id, user_id, notified_at, seen_at)
         VALUES (:n, :u, NOW(), :s)',
        [':n' => $notificationId, ':u' => $userId, ':s' => $seenAt]
    );
}

/**
 * Compose the PRODUCTION predicate into a query scoped to this suite's rows.
 * This is the point of the whole suite: it exercises dlPruneWhere() itself,
 * not a re-implementation of it.
 *
 * @return int[]
 */
function dlPruneTestCandidates($db, string $marker, int $retentionDays): array
{
    $sql = 'SELECT n.id AS id' . dlPruneWhere($retentionDays) . ' AND n.aggregate_key LIKE :m';
    $st = $db->prepare($sql);
    $st->execute([':m' => $marker . '%']);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// A real variance flag, so case 3 has an entity that genuinely exists.
$db->execute(
    'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance)
     VALUES (:b, :p, :d, :k, :s, 0)',
    [':b' => $branchId, ':p' => $productId, ':d' => $oldDate, ':k' => 'overnight', ':s' => 'AM']
);
$st = $db->prepare('SELECT id FROM dl_variance_flags WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND kind = :k ORDER BY id DESC LIMIT 1');
$st->execute([':b' => $branchId, ':p' => $productId, ':d' => $oldDate, ':k' => 'overnight']);
$liveFlagId = (int)$st->fetchColumn();

$missingFlagId = 999999999; // no such flag
$old = date('Y-m-d H:i:s', strtotime('-30 days'));
$now = date('Y-m-d H:i:s');

$ids = [];
$ids['orphan_seen'] = dlPruneTestSeed($db, $marker . 'orphan-seen', 'variance', $missingFlagId, $old);
dlPruneTestSeedRecipient($db, $ids['orphan_seen'], $userId, $now);

$ids['orphan_unseen'] = dlPruneTestSeed($db, $marker . 'orphan-unseen', 'variance', $missingFlagId, $old);
dlPruneTestSeedRecipient($db, $ids['orphan_unseen'], $userId, null);

$ids['flag_exists'] = dlPruneTestSeed($db, $marker . 'flag-exists', 'variance', $liveFlagId, $old);
dlPruneTestSeedRecipient($db, $ids['flag_exists'], $userId, $now);

$ids['other_type'] = dlPruneTestSeed($db, $marker . 'other-type', 'historical_digest', $missingFlagId, $old);
dlPruneTestSeedRecipient($db, $ids['other_type'], $userId, $now);

$ids['recent_orphan'] = dlPruneTestSeed($db, $marker . 'recent-orphan', 'variance', $missingFlagId, $now);
dlPruneTestSeedRecipient($db, $ids['recent_orphan'], $userId, $now);

$candidates7 = dlPruneTestCandidates($db, $marker, 7);
$candidates0 = dlPruneTestCandidates($db, $marker, 0);

// ─── The predicate ───────────────────────────────────────────────────────
$h->section('Predicate: what may be deleted');

$h->test(
    'a notification whose flag is gone and every recipient has read it IS eligible',
    in_array($ids['orphan_seen'], $candidates7, true),
    'id=' . $ids['orphan_seen']
);

$h->test(
    'a notification whose flag is gone but that a recipient has NOT read is NEVER eligible',
    !in_array($ids['orphan_unseen'], $candidates7, true),
    'id=' . $ids['orphan_unseen']
);

$h->test(
    'a notification whose variance flag still exists is never eligible',
    !in_array($ids['flag_exists'], $candidates7, true),
    'id=' . $ids['flag_exists'] . ' flag=' . $liveFlagId
);

$h->test(
    'only finding_type=variance is considered (historical_digest untouched)',
    !in_array($ids['other_type'], $candidates7, true),
    'id=' . $ids['other_type']
);

$h->test(
    'the 7-day retention floor protects a recently raised notification',
    !in_array($ids['recent_orphan'], $candidates7, true),
    'id=' . $ids['recent_orphan']
);

$h->test(
    'retention_days=0 disables the floor, so the same row becomes eligible',
    in_array($ids['recent_orphan'], $candidates0, true),
    'id=' . $ids['recent_orphan']
);

$h->test(
    'exactly one of the five seeded shapes is eligible at the default floor',
    count($candidates7) === 1 && (int)$candidates7[0] === $ids['orphan_seen'],
    'candidates=' . implode(',', $candidates7)
);

// ─── Batch delete ────────────────────────────────────────────────────────
$h->section('Batch delete removes the eligible row and nothing else');

$globalCandidates = dlPruneCandidateCount($db, 7);
if ($globalCandidates > 0 && $globalCandidates <= 500) {
    // Rolled back regardless of outcome: this proves the delete without
    // mutating the database, including a freshly imported live copy.
    $db->beginTransaction();
    try {
        $deleted = 0;
        for ($i = 0; $i < 3; $i++) {
            $n = dlPruneBatchDelete($db, 500, 7);
            if ($n <= 0) {
                break;
            }
            $deleted += $n;
        }

        $remaining = dlPruneTestCandidates($db, $marker, 7);

        // Survival must be checked by EXISTENCE. Checking membership of the candidate list
        // would pass for every row after the only eligible row is deleted, because that list
        // is empty - an assertion that cannot fail.
        $exists = static function (int $id) use ($db): bool {
            $st = $db->prepare('SELECT COUNT(*) AS c FROM dl_integrity_notifications WHERE id = :id');
            $st->execute([':id' => $id]);

            return (int)$st->fetchColumn() > 0;
        };

        $h->test(
            'the batch delete removed the eligible row',
            !$exists($ids['orphan_seen']),
            'deleted=' . $deleted . ' remaining_candidates=' . implode(',', $remaining)
        );
        $h->test(
            'the unread row survived the delete (the safety property)',
            $exists($ids['orphan_unseen']),
            'id=' . $ids['orphan_unseen']
        );
        $h->test(
            'the flag-backed row survived the delete',
            $exists($ids['flag_exists']),
            'id=' . $ids['flag_exists']
        );
        $h->test(
            'the non-variance row survived the delete',
            $exists($ids['other_type']),
            'id=' . $ids['other_type']
        );
        $h->test(
            'the retention-protected row survived the delete',
            $exists($ids['recent_orphan']),
            'id=' . $ids['recent_orphan']
        );
    } finally {
        $db->rollBack();
    }

    $afterRollback = dlPruneTestCandidates($db, $marker, 7);
    $h->test(
        'the rollback restored the eligible row (so the assertion above was real)',
        in_array($ids['orphan_seen'], $afterRollback, true)
    );
} else {
    $h->gap('batch delete end-to-end: skipped - ' . $globalCandidates
        . ' global candidates; run this suite after a prune for a deterministic one-batch test');
}

// ─── One predicate, two callers ──────────────────────────────────────────
$h->section('One predicate, two callers (drift guard)');

$cliSrc = (string)file_get_contents($base . '/modules/daily-ledger/cli/prune-integrity-notifications.php');
$handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$routeSrc = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');

$h->test('the CLI defines no local copy of the predicate', strpos($cliSrc, 'function dlPruneWhere') === false);
$h->test('the CLI deletes through the shared batch function', strpos($cliSrc, 'dlPruneBatchDelete(') !== false);
$h->test('the settings API defines no local copy of the predicate', strpos($handlerSrc, 'function dlPruneWhere') === false);
$h->test(
    'the settings API uses the shared summary and batch functions',
    strpos($handlerSrc, 'dlPruneSummary(') !== false && strpos($handlerSrc, 'dlPruneBatchDelete(') !== false
);
$h->test('the prune endpoint is routed', strpos($routeSrc, 'apiPruneIntegrityNotifications') !== false);
$h->test(
    'the settings template exposes the panel',
    strpos((string)file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl'), 'settings-maintenance') !== false
);

// ─── Concurrency guard ───────────────────────────────────────────────────
$h->section('Concurrency guard');

$lockA = dlPruneAcquireLock(999998);
$lockB = dlPruneAcquireLock(999998);
$h->test('a second worker cannot take the lock while one holds it', $lockA !== null && $lockB === null);
dlPruneReleaseLock($lockA);

$lockC = dlPruneAcquireLock(999998);
$h->test('the lock is available again after release', $lockC !== null);
dlPruneReleaseLock($lockC);

// ─── Cleanup ─────────────────────────────────────────────────────────────
$db->execute('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :m', [':m' => $marker . '%']);
$db->execute(
    'DELETE FROM dl_variance_flags WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND kind = :k',
    [':b' => $branchId, ':p' => $productId, ':d' => $oldDate, ':k' => 'overnight']
);

$st = $db->prepare('SELECT COUNT(*) AS c FROM dl_integrity_notifications WHERE aggregate_key LIKE :m');
$st->execute([':m' => $marker . '%']);
$h->test('cleanup removed every seeded row', (int)$st->fetchColumn() === 0);

$h->done();
