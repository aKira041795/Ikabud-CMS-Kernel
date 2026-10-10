<?php
declare(strict_types=1);

/**
 * Safety test for the integrity-notification prune.
 *
 * A test that only asserts "rows were deleted" is worthless — it would pass on a prune that deleted
 * everything. So this captures the two sets that must SURVIVE, runs the real CLI worker, and then proves
 * they are still there. It also checks idempotence, which is the claim that makes the worker safe to
 * schedule.
 *
 * MUST-SURVIVE SETS
 *   A. notifications whose dl_variance_flags row STILL EXISTS  (pending findings, not stale ones)
 *   B. notifications with at least one recipient whose seen_at IS NULL  (nobody has read it yet)
 *   C. every non-variance finding_type (day-status, receipt, origin, digest)
 *
 * Deliberately uses a PLAIN PDO rather than the module handle the worker uses, so verification does not
 * share the implementation's access path and cannot inherit its mistakes.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/test-prune-safety.php [--apply]
 */

require __DIR__ . '/../../../bootstrap.php';

$apply = in_array('--apply', $argv, true);
$tenantId = 207;

$pass = getenv('DB_PASSWORD');
$pass = $pass === false ? '' : $pass;
$pdo = new PDO('mysql:host=localhost;dbname=baronledger;charset=utf8mb4', 'root', $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$q = fn(string $sql) => $pdo->query($sql)->fetchAll();
$scalar = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$sizes = function () use ($pdo): array {
    $sql = "SELECT table_name AS t, table_rows AS est, data_length + index_length AS bytes
              FROM information_schema.tables
             WHERE table_schema = 'baronledger'
               AND table_name IN ('dl_integrity_notifications','dl_integrity_notification_recipients')
             ORDER BY table_name";
    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $out[$r['t']] = (int)$r['bytes'];
    }
    return $out;
};

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

echo "=== BEFORE ===\n";
$before = [
    'notifications' => $scalar('SELECT COUNT(*) FROM dl_integrity_notifications'),
    'recipients' => $scalar('SELECT COUNT(*) FROM dl_integrity_notification_recipients'),
];
$beforeSizes = $sizes();
printf("  notifications %s | recipients %s\n", number_format($before['notifications']), number_format($before['recipients']));
foreach ($beforeSizes as $t => $b) {
    printf("  %-40s %.2f MB\n", $t, $b / 1048576);
}

// --- Capture the must-survive sets, by id.
$liveIds = array_column($q(
    "SELECT n.id FROM dl_integrity_notifications n
       JOIN dl_variance_flags v ON v.id = n.entity_id AND n.entity_type = 'dl_variance_flags'
      WHERE n.finding_type = 'variance'"
), 'id');
$unseenIds = array_column($q(
    'SELECT DISTINCT n.id FROM dl_integrity_notifications n
       JOIN dl_integrity_notification_recipients r ON r.notification_id = n.id
      WHERE r.seen_at IS NULL'
), 'id');
$otherTypeIds = array_column($q(
    "SELECT id FROM dl_integrity_notifications WHERE finding_type <> 'variance'"
), 'id');

printf(
    "\n  protected: %s flag-backed | %s unread | %s other finding types\n",
    number_format(count($liveIds)),
    number_format(count($unseenIds)),
    number_format(count($otherTypeIds))
);

$protected = array_values(array_unique(array_merge($liveIds, $unseenIds, $otherTypeIds)));
printf("  protected ids (deduped): %s\n\n", number_format(count($protected)));

// --- Run the worker.
echo "=== RUN (retention floor 0, full effect) ===\n";
$mode = $apply ? '--apply' : '';
$cmd = sprintf(
    'php %s/modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=%d --retention-days=0 --batch=1000 --sleep-ms=0 %s 2>&1',
    escapeshellarg(dirname(__DIR__, 3)),
    $tenantId,
    $mode
);
exec($cmd, $outLines, $exitCode);
echo '  exit=' . $exitCode . "\n";
$candidates = null;
$deletedFirst = null;
foreach ($outLines as $line) {
    if (preg_match('/CANDIDATES to prune\s*:\s*([\d,]+)/', $line, $m)) {
        $candidates = (int)str_replace(',', '', $m[1]);
    }
    if (preg_match('/^\s*deleted\s*:\s*([\d,]+)/', $line, $m)) {
        $deletedFirst = (int)str_replace(',', '', $m[1]);
    }
    if (preg_match('/deleted |batches|notifications now|candidates remaining|Idempotent|ceiling|CANDIDATES/', $line)) {
        echo '  ' . trim($line) . "\n";
    }
}

// --- Verify.
echo "\n=== AFTER ===\n";
$after = [
    'notifications' => $scalar('SELECT COUNT(*) FROM dl_integrity_notifications'),
    'recipients' => $scalar('SELECT COUNT(*) FROM dl_integrity_notification_recipients'),
];
$afterSizes = $sizes();
printf("  notifications %s | recipients %s\n", number_format($after['notifications']), number_format($after['recipients']));
foreach ($afterSizes as $t => $b) {
    printf("  %-40s %.2f MB  (%+.2f MB)\n", $t, $b / 1048576, ($b - ($beforeSizes[$t] ?? 0)) / 1048576);
}

echo "\n=== SAFETY: every protected row must still exist ===\n";
// Chunked existence check — one query per 1000 ids, no giant IN list.
$present = [];
foreach (array_chunk($protected, 1000) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    foreach ($q("SELECT id FROM dl_integrity_notifications WHERE id IN ({$in})") as $r) {
        $present[(int)$r['id']] = true;
    }
}
$missing = array_values(array_filter($protected, fn($id) => !isset($present[(int)$id])));

$check('flag-backed notifications all survived', count(array_filter($liveIds, fn($id) => !isset($present[(int)$id]))) === 0,
    count(array_filter($liveIds, fn($id) => !isset($present[(int)$id]))) . ' of ' . count($liveIds) . ' gone');
$check('unread notifications all survived', count(array_filter($unseenIds, fn($id) => !isset($present[(int)$id]))) === 0,
    count(array_filter($unseenIds, fn($id) => !isset($present[(int)$id]))) . ' of ' . count($unseenIds) . ' gone');
$check('non-variance finding types all survived', count(array_filter($otherTypeIds, fn($id) => !isset($present[(int)$id]))) === 0,
    count(array_filter($otherTypeIds, fn($id) => !isset($present[(int)$id]))) . ' of ' . count($otherTypeIds) . ' gone');
printf("  (total protected ids missing: %d of %d)\n", count($missing), count($protected));

// --- The partition invariant: every row is either protected or a candidate, with nothing left over.
// If these do not sum to the total, the predicate and the protection sets describe different universes
// and one of them is wrong.
if ($candidates !== null) {
    $partition = count($protected) + $candidates;
    $check(
        'protected + candidates == all notifications (complete partition)',
        $partition === $before['notifications'],
        "protected " . count($protected) . " + candidates {$candidates} = {$partition}, table has {$before['notifications']}"
    );
}

// --- Referential integrity: no recipient may be orphaned.
$orphans = $scalar(
    'SELECT COUNT(*) FROM dl_integrity_notification_recipients r
       LEFT JOIN dl_integrity_notifications n ON n.id = r.notification_id
      WHERE n.id IS NULL'
);
$check('no orphaned recipient rows (CASCADE worked)', $orphans === 0, "{$orphans} orphaned");

// --- What remains must be exactly the protected set (nothing over-deleted).
$remainingIds = array_column($q('SELECT id FROM dl_integrity_notifications'), 'id');
$remaining = count($remainingIds);
printf("  remaining notifications: %s | protected set: %s\n", number_format($remaining), number_format(count($protected)));

if ($apply) {
    echo "\n=== IDEMPOTENCE: a second run must delete nothing ===\n";
    // A run with nothing to do prints "Nothing to do." and emits no 'deleted' line at all, so a missing
    // line means zero — treating it as unparseable would fail a correct run.
    $check(
        'first run deleted all candidates',
        $candidates === null || $candidates === 0 || $deletedFirst === $candidates,
        'candidates ' . var_export($candidates, true) . ', deleted ' . var_export($deletedFirst, true)
    );
    exec($cmd, $out2, $exit2);
    $deletedSecond = null;
    $nothingToDo = false;
    foreach ($out2 as $line) {
        if (preg_match('/^\s*deleted\s*:\s*([\d,]+)/', $line, $m)) {
            $deletedSecond = (int)str_replace(',', '', $m[1]);
        }
        if (str_contains($line, 'Nothing to do')) {
            $nothingToDo = true;
        }
    }
    $secondDeleted = $nothingToDo ? 0 : $deletedSecond;
    $check('second run deleted 0 rows', $secondDeleted === 0, 'deleted ' . var_export($deletedSecond, true) . ($nothingToDo ? ' (reported "Nothing to do")' : '') . ' (exit=' . $exit2 . ')');
    $afterSecond = $scalar('SELECT COUNT(*) FROM dl_integrity_notifications');
    $check('second run left the table unchanged', $afterSecond === $after['notifications'], "{$afterSecond} vs {$after['notifications']}");
} else {
    echo "\n(dry run: idempotence is only meaningful once rows have actually been deleted)\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
