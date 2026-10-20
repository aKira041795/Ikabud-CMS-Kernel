<?php

declare(strict_types=1);

/**
 * Daily Ledger — database maintenance helpers.
 *
 * The integrity-notification prune previously existed only as a CLI worker, with
 * its predicate defined inside that script. The Settings panel now offers the
 * same operation, and the one outcome that must not happen is the two acquiring
 * different ideas of what counts as orphaned: a UI predicate that drifts from the
 * cron predicate silently deletes a different set of rows, and nobody finds out
 * until a cashier's notification disappears.
 *
 * So the predicate and the batch delete live here, once. Both callers use them.
 * The CLI keeps its own loop, output format and exit codes.
 *
 * The safety property, stated once: a notification is only eligible when its
 * variance flag is GONE and EVERY recipient has already read it. A notification
 * a cashier has not seen is never deleted, even when its flag no longer exists.
 *
 * MySQL 5.7 compatibility (Bluehost) is a hard requirement here:
 *   - no CTE, no window functions, no JSON_TABLE, no EXCEPT/INTERSECT
 *   - no multi-table DELETE with LIMIT/ORDER BY (5.7 allows those in the
 *     single-table form only), so batching uses a materialised derived table,
 *     which also avoids error 1093
 *   - LIMIT takes a bound integer, supported by 5.7 prepared statements
 */

use Ikabud\Kernel\Contracts\DatabaseContract;

/**
 * The candidate predicate, shared by every count and every delete so they cannot drift.
 *
 * $retentionDays > 0 adds a floor; 0 removes the clause entirely rather than
 * relying on INTERVAL 0.
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

/**
 * Cheap counts for a page load: two COUNT(*)s, no correlated subqueries.
 *
 * The ratio of notifications to live flags is the diagnostic that matters: a
 * high ratio means orphan accumulation is outpacing cleanup, which is what a
 * failure to collapse on aggregate_key looks like.
 *
 * @return array{notifications: int, flags: int, ratio: ?float}
 */
function dlPruneCheapCounts(DatabaseContract $db): array
{
    $notifications = (int)$db->query('SELECT COUNT(*) AS c FROM dl_integrity_notifications')->fetchColumn();
    $flags = (int)$db->query('SELECT COUNT(*) AS c FROM dl_variance_flags')->fetchColumn();

    return [
        'notifications' => $notifications,
        'flags' => $flags,
        'ratio' => $flags > 0 ? round($notifications / $flags, 1) : null,
    ];
}

/** How many notifications are eligible for pruning right now. */
function dlPruneCandidateCount(DatabaseContract $db, int $retentionDays): int
{
    return (int)$db->query('SELECT COUNT(*) AS c' . dlPruneWhere($retentionDays))->fetchColumn();
}

/** How many recipient rows would go with them (removed by the FK cascade). */
function dlPruneRecipientShare(DatabaseContract $db, int $retentionDays): int
{
    return (int)$db->query(
        'SELECT COUNT(*) AS c FROM dl_integrity_notification_recipients r
          WHERE r.notification_id IN (SELECT n.id' . dlPruneWhere($retentionDays) . ')'
    )->fetchColumn();
}

/**
 * Full summary. Runs the correlated-subquery count, so this is for an explicit
 * user action rather than a page load.
 *
 * @return array{notifications: int, flags: int, ratio: ?float, candidates: int, recipients: int}
 */
function dlPruneSummary(DatabaseContract $db, int $retentionDays): array
{
    $counts = dlPruneCheapCounts($db);
    $counts['candidates'] = dlPruneCandidateCount($db, $retentionDays);
    $counts['recipients'] = dlPruneRecipientShare($db, $retentionDays);

    return $counts;
}

/**
 * Delete one bounded batch. Returns the number of notifications removed.
 *
 * A single statement per call, so the lock it holds is short. Callers loop.
 */
function dlPruneBatchDelete(DatabaseContract $db, int $batch, int $retentionDays): int
{
    $batch = max(1, min(5000, $batch));

    // Materialised derived table: satisfies 5.7 (no CTE, single-table DELETE) AND
    // avoids error 1093 (target table not selectable from a subquery directly).
    $sql = 'DELETE FROM dl_integrity_notifications
             WHERE id IN (
               SELECT id FROM (
                 SELECT n.id AS id' . dlPruneWhere($retentionDays) . '
                 ORDER BY n.id
                 LIMIT ' . $batch . '
               ) AS prune_batch
             )';

    $stmt = $db->prepare($sql);
    $stmt->execute();

    return (int)$stmt->rowCount();
}

/**
 * Acquire the per-tenant prune lock.
 *
 * The SAME lock file the CLI worker uses, so a UI run and a cron run cannot
 * overlap. Returns null when someone else holds it - callers report that rather
 * than blocking a web request on it.
 *
 * @return resource|null
 */
function dlPruneAcquireLock(int $tenantId)
{
    $path = sys_get_temp_dir() . '/daily-ledger-notification-prune-' . $tenantId . '.lock';
    $handle = @fopen($path, 'c');
    if (!$handle) {
        return null;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return null;
    }

    return $handle;
}

/** @param resource|null $handle */
function dlPruneReleaseLock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
