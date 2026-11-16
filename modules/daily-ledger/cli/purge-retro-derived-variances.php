#!/usr/bin/env php
<?php

/**
 * Purge variance findings that a REPAIR derived retroactively for closed days.
 *
 * WHY THIS EXISTS
 * ---------------
 * `repair-closed-shift-status.php` used to finalize the day-ending shift of days that had been closed
 * months earlier AND re-derive that day's variances. Measured on tenant 207 on 2026-11-16: 414 NEW
 * unreviewed flags across 32 branch-days (2026-08-15 .. 2026-11-15), 408 of them frozen — settled
 * history re-opened, which is exactly the client's complaint: "mawala na ang mga variance nga ok na".
 *
 * The repair no longer derives variances (commit 0c2d03ad). This worker removes what an EARLIER run
 * already created, on any site where it ran.
 *
 * HOW A ROW IS PROVEN TO BE FROM THAT RUN — not guessed
 *   1. created_at >= today's midnight       (created during the repair's own day)
 *   2. ledger_date < today                  (a past day: the retroactive case)
 *   3. resolution_status = 'unreviewed', is_reviewed = 0, reviewed_by/at NULL,
 *      review_note NULL, auto_clear_note NULL     (no human opened, settled or annotated it)
 *   4. the day carries a `finalize_shift` audit row written by the repair
 *      (new_data LIKE '%repair_closed_day_sweep%') ON THE SAME CALENDAR DAY as the flag.
 *
 * (4) is what separates junk from legitimate work: closing YESTERDAY's sheet this morning also
 * derives unreviewed flags on a past, closed day — and those are real, so they are never touched.
 * Rows that match (1)-(3) but not (4) are REPORTED as "unproven" and left alone.
 *
 * STABILITY
 * ---------
 * - DRY RUN BY DEFAULT. Deleting needs an explicit --apply.
 * - Idempotent: a second run finds nothing.
 * - flock guard: two workers cannot fight over the same site.
 * - --limit bounds how many rows one run may delete; a capped run says so and can be re-run.
 *
 * USAGE
 *   php modules/daily-ledger/cli/purge-retro-derived-variances.php --tenant=207
 *   php modules/daily-ledger/cli/purge-retro-derived-variances.php --tenant=baron-001 --apply
 *
 *   --tenant=<id|key|domain>   REQUIRED (numeric id, tenant_key, or canonical domain)
 *   --since=YYYY-MM-DD         earliest ledger_date to consider   (default: 120 days ago)
 *   --limit=<n>                maximum rows this run may delete   (default 5000)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'apply', 'since::', 'limit::']);

$tenantRef = trim((string)($options['tenant'] ?? ''));
if ($tenantRef === '') {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/purge-retro-derived-variances.php --tenant=<id|key|domain> [--apply]\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$since = (string)($options['since'] ?? (new DateTimeImmutable('-120 days'))->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    fwrite(STDERR, "Invalid --since, expected YYYY-MM-DD.\n");
    exit(2);
}
$limit = max(1, min(100000, (int)($options['limit'] ?? 5000)));

try {
    $app = kernelCliBootstrap($basePath);

    $tenantId = 0;
    if (ctype_digit($tenantRef)) {
        $tenantId = (int)$tenantRef;
    } else {
        $lookup = $app->db()->prepare('SELECT id FROM kernel_tenants WHERE tenant_key = :ref OR canonical_domain = :ref2 LIMIT 1');
        $lookup->execute([':ref' => $tenantRef, ':ref2' => $tenantRef]);
        $tenantId = (int)($lookup->fetchColumn() ?: 0);
    }
    if ($tenantId <= 0) {
        fwrite(STDERR, "Tenant not found for '{$tenantRef}'. Pass the numeric id, tenant_key or canonical domain.\n");
        exit(2);
    }

    $lockPath = sys_get_temp_dir() . '/daily-ledger-purge-retro-variances-' . $tenantId . '.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another purge is already running for tenant {$tenantId}.\n");
        exit(3);
    }

    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Retro Variance Purge',
        'username' => 'daily-ledger-retro-variance-purge',
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

    $humanUntouched = "vf.created_at >= CURDATE()
                  AND vf.ledger_date >= :since
                  AND vf.ledger_date < CURDATE()
                  AND vf.resolution_status = 'unreviewed'
                  AND vf.is_reviewed = 0
                  AND vf.reviewed_by IS NULL AND vf.reviewed_at IS NULL
                  AND vf.review_note IS NULL AND vf.auto_clear_note IS NULL";
    $repairProof = "EXISTS (
                    SELECT 1 FROM audit_logs a
                     WHERE a.module = 'daily-ledger'
                       AND a.action = 'finalize_shift'
                       AND a.entity_id LIKE CONCAT(vf.branch_id, '-', vf.ledger_date, '-%')
                       AND a.new_data LIKE '%repair_closed_day_sweep%'
                       AND DATE(a.created_at) = DATE(vf.created_at)
                  )";
    $provenWhere = $humanUntouched . ' AND ' . $repairProof;
    $unprovenWhere = $humanUntouched . ' AND NOT ' . $repairProof;

    $countWhere = static function (string $where) use ($db, $since): int {
        $stmt = $db->prepare("SELECT COUNT(*) FROM dl_variance_flags vf WHERE {$where}");
        $stmt->execute([':since' => $since]);
        return (int)$stmt->fetchColumn();
    };

    $proven = $countWhere($provenWhere);
    $unproven = $countWhere($unprovenWhere);
    $totalFlags = (int)$db->query('SELECT COUNT(*) FROM dl_variance_flags')->fetchColumn();

    echo "Daily Ledger retro-derived variance purge — tenant {$tenantId}\n";
    echo '  mode                  : ' . ($apply ? 'APPLY (deleting)' : 'DRY RUN (no writes)') . "\n";
    echo "  window                : {$since} .. yesterday\n";
    echo '  row limit             : ' . $limit . "\n";
    echo "\n";
    echo '  variance flags now    : ' . number_format($totalFlags) . "\n";
    echo '  PROVEN from the repair: ' . number_format($proven) . "\n";
    echo '  unproven look-alikes  : ' . number_format($unproven) . " (left alone: real work on a past day)\n";

    if ($proven > 0) {
        $shape = $db->prepare(
            "SELECT MIN(vf.ledger_date) AS mn, MAX(vf.ledger_date) AS mx,
                    COUNT(DISTINCT CONCAT(vf.branch_id, '-', vf.ledger_date)) AS days
               FROM dl_variance_flags vf WHERE {$provenWhere}"
        );
        $shape->execute([':since' => $since]);
        $info = $shape->fetch(PDO::FETCH_ASSOC) ?: [];
        echo '  their dates           : ' . (string)($info['mn'] ?? '?') . ' .. ' . (string)($info['mx'] ?? '?')
            . ' across ' . (int)($info['days'] ?? 0) . " branch-day(s)\n";

        $sample = $db->prepare(
            "SELECT vf.branch_id, vf.ledger_date, vf.kind, COUNT(*) AS c
               FROM dl_variance_flags vf WHERE {$provenWhere}
              GROUP BY vf.branch_id, vf.ledger_date, vf.kind
              ORDER BY vf.ledger_date DESC LIMIT 10"
        );
        $sample->execute([':since' => $since]);
        echo "\n  sample (date / branch / kind / rows):\n";
        foreach ($sample->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            echo '    ' . $row['ledger_date'] . '  branch ' . $row['branch_id'] . '  ' . $row['kind'] . '  x' . $row['c'] . "\n";
        }
    }

    if (!$apply) {
        echo "\nDry run only. Re-run with --apply to delete the " . number_format($proven) . " proven row(s).\n";
        exit(0);
    }

    if ($proven === 0) {
        echo "\nNothing to purge — this site is already clean.\n";
        exit(0);
    }

    $db->beginTransaction();
    try {
        // MySQL 5.7 (@mysql57-compat): a MULTI-table DELETE accepts neither LIMIT nor ORDER BY, so
        // the bounded id set is materialised first and removed by primary key in one statement.
        $sel = $db->prepare("SELECT vf.id FROM dl_variance_flags vf WHERE {$provenWhere} ORDER BY vf.id ASC LIMIT {$limit}");
        $sel->execute([':since' => $since]);
        $ids = array_values(array_unique(array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $deleted = 0;
        if ($ids !== []) {
            $db->prepare('DELETE FROM dl_variance_flags WHERE id IN (' . implode(',', $ids) . ')')->execute();
            $deleted = count($ids);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, 'Purge failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $remaining = $countWhere($provenWhere);
    echo "\n  deleted               : " . number_format($deleted) . "\n";
    echo '  remaining proven      : ' . number_format($remaining) . ($remaining > 0 && $deleted >= $limit ? " (limit reached — re-run)" : '') . "\n";
    echo '  variance flags now    : ' . number_format((int)$db->query('SELECT COUNT(*) FROM dl_variance_flags')->fetchColumn()) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Purge failed: ' . $e->getMessage() . "\n");
    exit(1);
}
