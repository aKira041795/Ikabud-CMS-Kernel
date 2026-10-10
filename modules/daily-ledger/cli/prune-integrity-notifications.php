#!/usr/bin/env php
<?php

/**
 * Prune orphaned Daily Ledger integrity notifications.
 *
 * WHY THIS EXISTS
 * ---------------
 * A ledger recompute deliberately does this (handlers.php ~6911):
 *
 *     DELETE FROM dl_variance_flags
 *      WHERE branch_id = :bid AND ledger_date = :d
 *        AND resolution_status = 'unreviewed' AND kind <> 'delivery'
 *
 * ...and then re-derives and re-inserts the flags. That is correct — the recompute owns the derived day
 * kinds. But the notification raised for a flag was keyed on the flag's SURROGATE id
 * ('variance-' . $flagId), so every recompute minted a NEW flag id and therefore a NEW notification plus its
 * recipient rows. Measured on tenant 207: 118,985 notifications in 9 days, of which 118,491 point at a
 * variance flag that no longer exists, while only 982 flags survive. ~13,000 rows/day, ~3.6 MB/day.
 *
 * The capture side is fixed separately (the key now uses the flag's natural identity, matching
 * uq_dl_variance, and repeats increment finding_count). This worker clears the rows that fix cannot reach:
 * the ones already created.
 *
 * WHAT IT DELETES — all four must hold, so it cannot touch anything live or unread:
 *   1. finding_type = 'variance' AND entity_type = 'dl_variance_flags'
 *   2. the referenced dl_variance_flags row NO LONGER EXISTS   (its subject is gone — stale, not pending)
 *   3. at least one recipient exists                            (someone was actually told)
 *   4. NO recipient still has seen_at IS NULL                   (everyone has read it)
 * Notifications whose flag still exists, and anything unseen, are never candidates. Recipient rows are
 * removed by the existing ON DELETE CASCADE (fk_dl_inr_notification), not by a second statement.
 *
 * STABILITY
 * ---------
 * - DRY RUN BY DEFAULT. Deletion needs an explicit --apply.
 * - Batched: bounded rows per statement and a configurable ceiling on batches, so runtime is known.
 * - Resumable and idempotent: the criterion is state-based, each batch is autonomous, and a re-run finds
 *   nothing further. A crash leaves a consistent tree.
 * - flock guard: two workers cannot fight over the same tenant.
 * - A retention floor (--retention-days, default 7) keeps recently-raised rows out of reach entirely.
 *
 * MYSQL 5.7 COMPATIBILITY (live runs an older server than this dev box)
 * --------------------------------------------------------------------
 * Uses only 5.7-safe syntax, deliberately:
 *   - no CTE (WITH), no window functions, no JSON_TABLE, no EXCEPT/INTERSECT
 *   - no multi-table DELETE with LIMIT/ORDER BY (5.7 permits those in the single-table form only)
 *   - the batched id set is a MATERIALISED DERIVED TABLE, which is also what avoids MySQL error 1093
 *     ("can't specify target table for update in FROM clause"); no scratch table is created, so nothing
 *     is added to the module schema.
 *   - LIMIT takes a bound parameter, supported by 5.7 prepared statements.
 *
 * USAGE
 *   php modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=207              # report only
 *   php modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=207 --apply
 *
 *   --batch=<n>            rows per statement            (default 500)
 *   --max-batches=<n>      safety ceiling per run        (default 400, i.e. up to 200k rows)
 *   --retention-days=<n>   never touch rows newer than n (default 7; 0 disables the floor)
 *   --sleep-ms=<n>         pause between batches         (default 50)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'apply', 'batch::', 'max-batches::', 'retention-days::', 'sleep-ms::']);

$tenantId = max(0, (int)($options['tenant'] ?? 0));
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/prune-integrity-notifications.php --tenant=<id> [--apply]\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$batch = max(1, min(5000, (int)($options['batch'] ?? 500)));
$maxBatches = max(1, (int)($options['max-batches'] ?? 400));
$retentionDays = max(0, (int)($options['retention-days'] ?? 7));
$sleepMs = max(0, min(5000, (int)($options['sleep-ms'] ?? 50)));

$lockPath = sys_get_temp_dir() . '/daily-ledger-notification-prune-' . $tenantId . '.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A prune worker is already running for tenant {$tenantId}.\n");
    exit(3);
}

/**
 * The candidate predicate, shared by the count and the delete so they can never drift apart.
 * $retentionDays > 0 adds a floor; 0 removes the clause entirely rather than relying on INTERVAL 0.
 */
function dlPruneWhere(int $retentionDays): string
{
    $sql = " FROM dl_integrity_notifications n
             LEFT JOIN dl_variance_flags v
                    ON v.id = n.entity_id AND n.entity_type = 'dl_variance_flags'
            WHERE n.finding_type = 'variance'
              AND n.entity_type = 'dl_variance_flags'
              AND v.id IS NULL
              AND EXISTS (SELECT 1 FROM dl_integrity_notification_recipients r
                           WHERE r.notification_id = n.id)
              AND NOT EXISTS (SELECT 1 FROM dl_integrity_notification_recipients r2
                               WHERE r2.notification_id = n.id AND r2.seen_at IS NULL)";
    if ($retentionDays > 0) {
        $sql .= ' AND n.raised_at < DATE_SUB(NOW(), INTERVAL ' . $retentionDays . ' DAY)';
    }
    return $sql;
}

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Notification Pruner',
        'username' => 'daily-ledger-notification-pruner',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = $context->db();

    $where = dlPruneWhere($retentionDays);

    $total = (int)$db->query('SELECT COUNT(*) AS c' . $where)->fetchColumn();

    $recipientShare = 0;
    if ($total > 0) {
        $recipientShare = (int)$db->query(
            'SELECT COUNT(*) AS c FROM dl_integrity_notification_recipients r
              WHERE r.notification_id IN (SELECT n.id' . $where . ')'
        )->fetchColumn();
    }

    $surviving = (int)$db->query('SELECT COUNT(*) AS c FROM dl_integrity_notifications')->fetchColumn();
    $liveFlags = (int)$db->query('SELECT COUNT(*) AS c FROM dl_variance_flags')->fetchColumn();

    echo "Daily Ledger integrity-notification prune — tenant {$tenantId}\n";
    echo '  mode                 : ' . ($apply ? "APPLY (deleting)" : 'DRY RUN (no writes)') . "\n";
    echo "  retention floor      : " . ($retentionDays > 0 ? "{$retentionDays} days" : 'none') . "\n";
    echo "  batch / max-batches  : {$batch} / {$maxBatches}" . ($apply ? '' : ' (ignored in dry run)') . "\n";
    echo "\n";
    echo '  notifications now    : ' . number_format($surviving) . "\n";
    echo '  variance flags now   : ' . number_format($liveFlags) . "\n";
    echo '  CANDIDATES to prune  : ' . number_format($total) . "\n";
    echo '  their recipient rows : ' . number_format($recipientShare) . " (removed by CASCADE)\n";
    echo "\n";

    if (!$apply) {
        echo "Dry run. Re-run with --apply to delete these rows.\n";
        exit(0);
    }

    if ($total === 0) {
        echo "Nothing to do.\n";
        exit(0);
    }

    $deleted = 0;
    $batches = 0;
    $started = microtime(true);

    while ($batches < $maxBatches) {
        // Materialised derived table: satisfies 5.7 (no CTE, single-table DELETE) AND avoids error 1093.
        $sql = 'DELETE FROM dl_integrity_notifications
                 WHERE id IN (
                   SELECT id FROM (
                     SELECT n.id AS id' . $where . '
                     ORDER BY n.id
                     LIMIT ' . $batch . '
                   ) AS prune_batch
                 )';
        $affected = $db->prepare($sql);
        $affected->execute();
        $rows = $affected->rowCount();

        if ($rows === 0) {
            break;
        }
        $deleted += $rows;
        $batches++;

        printf("  batch %-4d deleted %-6d (running total %s)\n", $batches, $rows, number_format($deleted));

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    $elapsed = microtime(true) - $started;
    $remaining = (int)$db->query('SELECT COUNT(*) AS c' . $where)->fetchColumn();
    $after = (int)$db->query('SELECT COUNT(*) AS c FROM dl_integrity_notifications')->fetchColumn();

    echo "\n";
    echo '  deleted              : ' . number_format($deleted) . "\n";
    echo '  batches              : ' . $batches . sprintf(' in %.1f s', $elapsed) . "\n";
    echo '  notifications now    : ' . number_format($after) . "\n";
    echo '  candidates remaining : ' . number_format($remaining) . "\n";

    if ($batches >= $maxBatches && $remaining > 0) {
        echo "\nHit the --max-batches ceiling with rows still eligible. Re-run to continue.\n";
        exit(0);
    }
    echo "\nIdempotent: re-running now finds nothing to delete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Notification prune failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
