<?php
declare(strict_types=1);

/**
 * Does the integrity-notification capture fix actually collapse repeats?
 *
 * The defect: dl_upsertVarianceFlag() keyed its notification on 'variance-' . $flagId, and the ledger
 * recompute deletes and re-derives variance flags, so the same logical variance got a new flag id — and a
 * new notification row plus recipients — on every recompute.
 *
 * This test does not just assert the new form works. It runs the OLD key form alongside it, in the same
 * process against the same database, so the test is falsifiable: if the old form did NOT produce two rows
 * here, the test is measuring nothing and says so.
 *
 * It also asserts the second half of the fix — that a repeat increments finding_count rather than being
 * discarded, which is what INSERT IGNORE used to do.
 *
 * Run against the local tenant DB. Cleans up every synthetic row it creates.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/test-capture-fix.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$tenantId = 207;
$passed = 0;
$failed = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
        return;
    }
    $failed++;
    echo "  ✗ {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
};

// Fake tuple, chosen so it cannot collide with real data and is easy to clean up.
$BRANCH = 999999;
$PRODUCT = 999999;
$DATE = '2099-01-01';
$NEW_KEY = 'variance-b' . $BRANCH . '-p' . $PRODUCT . '-' . $DATE . '-ending-any';
$OLD_KEY_A = 'variance-8888881';   // the pre-fix form: keys on the flag's surrogate id
$OLD_KEY_B = 'variance-8888882';   // ...and a recompute mints a NEW id, hence a new key
$cleanupLike = 'variance-b999999%';

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser(['id' => 0, 'name' => 'capture-fix test', 'username' => 'test', 'role' => 'administrator', 'source' => 'system']);
    require_once $basePath . '/src/helpers/module-manager.php';
    require_once $basePath . '/modules/daily-ledger/helpers.php';
    require_once $basePath . '/modules/daily-ledger/handlers.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    $db = $context->db();

    $rowsFor = function (string $key) use ($db): array {
        $st = $db->prepare('SELECT id, finding_count, entity_id FROM dl_integrity_notifications WHERE aggregate_key = :k');
        $st->execute([':k' => $key]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $recipientsFor = function (int $id) use ($db): int {
        $st = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notification_recipients WHERE notification_id = :i');
        $st->execute([':i' => $id]);
        return (int)$st->fetchColumn();
    };

    // --- Clean slate for the synthetic keys.
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :p OR aggregate_key IN (:a, :b)')
        ->execute([':p' => $cleanupLike, ':a' => $OLD_KEY_A, ':b' => $OLD_KEY_B]);

    echo "=== A. the FIXED key: a recompute must reuse one row and count the repeat ===\n";
    $id1 = dl_raiseIntegrityNotification($db, $NEW_KEY, 'variance', $BRANCH, 'dl_variance_flags', 770001, 'TEST variance surfaced', 'TEST detail one');
    $rows = $rowsFor($NEW_KEY);
    $check('first raise created exactly one row', count($rows) === 1, 'rows=' . count($rows));
    $check('finding_count starts at 1', (int)($rows[0]['finding_count'] ?? 0) === 1, 'count=' . ($rows[0]['finding_count'] ?? '?'));
    $recips = $id1 ? $recipientsFor($id1) : 0;
    printf("      (row id %s, %d recipient(s))\n", var_export($id1, true), $recips);

    // Simulate the recompute: the flag is deleted and re-derived with a NEW id, same natural identity.
    $id2 = dl_raiseIntegrityNotification($db, $NEW_KEY, 'variance', $BRANCH, 'dl_variance_flags', 770002, 'TEST variance surfaced', 'TEST detail two');
    $rows = $rowsFor($NEW_KEY);
    $check('a repeat did NOT create a second row', count($rows) === 1, 'rows=' . count($rows));
    $check('the repeat returned the SAME notification id', $id1 === $id2, var_export($id1, true) . ' vs ' . var_export($id2, true));
    $check('finding_count incremented to 2', (int)($rows[0]['finding_count'] ?? 0) === 2, 'count=' . ($rows[0]['finding_count'] ?? '?'));
    $check('entity_id now points at the CURRENT flag', (int)($rows[0]['entity_id'] ?? 0) === 770002, 'entity_id=' . ($rows[0]['entity_id'] ?? '?'));

    // A third recompute, to show it keeps collapsing.
    dl_raiseIntegrityNotification($db, $NEW_KEY, 'variance', $BRANCH, 'dl_variance_flags', 770003, 'TEST variance surfaced', 'TEST detail three');
    $rows = $rowsFor($NEW_KEY);
    $check('a third recompute still holds one row', count($rows) === 1, 'rows=' . count($rows));
    $check('finding_count reached 3', (int)($rows[0]['finding_count'] ?? 0) === 3, 'count=' . ($rows[0]['finding_count'] ?? '?'));

    // --- B. the OLD key form, as the control. If this does not produce two rows, the test proves nothing.
    echo "\n=== B. the OLD key form (control): the same recompute must leak a row ===\n";
    dl_raiseIntegrityNotification($db, $OLD_KEY_A, 'variance', $BRANCH, 'dl_variance_flags', 8888881, 'TEST variance surfaced', 'TEST detail old');
    dl_raiseIntegrityNotification($db, $OLD_KEY_B, 'variance', $BRANCH, 'dl_variance_flags', 8888882, 'TEST variance surfaced', 'TEST detail old');
    $oldRows = count($rowsFor($OLD_KEY_A)) + count($rowsFor($OLD_KEY_B));
    $check(
        'control: the surrogate-id key still produces one row PER recompute',
        $oldRows === 2,
        "rows={$oldRows} — if this is not 2 the test is not discriminating the fix"
    );
    printf("      fixed key total rows: %d | old key total rows: %d\n", count($rowsFor($NEW_KEY)), $oldRows);

    // --- C. recipients stay idempotent and cascade.
    echo "\n=== C. recipients ===\n";
    $recipientsAfterThree = $id1 ? $recipientsFor($id1) : 0;
    $check('re-addressing on repeat did not duplicate recipient rows', $recipientsAfterThree === $recips,
        "{$recipientsAfterThree} vs {$recips} after three raises");

    if ($id1) {
        $db->prepare('DELETE FROM dl_integrity_notifications WHERE id = :i')->execute([':i' => $id1]);
        $check('deleting the notification cascaded its recipients away', $recipientsFor($id1) === 0, 'recipients remain');
    }

    // --- Cleanup.
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :p OR aggregate_key IN (:a, :b)')
        ->execute([':p' => $cleanupLike, ':a' => $OLD_KEY_A, ':b' => $OLD_KEY_B]);
    $left = $rowsFor($NEW_KEY);
    $check('cleanup removed every synthetic row', $left === [], 'rows left=' . count($left));

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'capture-fix test failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
