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

    // WHY THE PROOF IS NOT A CORRELATED EXISTS ANY MORE
    // --------------------------------------------------
    // It used to be `EXISTS (SELECT 1 FROM audit_logs ... entity_id LIKE CONCAT(...) ... new_data
    // LIKE '%repair_closed_day_sweep%')` inside the WHERE clause — evaluated once per candidate,
    // AGAIN as NOT EXISTS for the "unproven" count, and twice more for the shape and sample
    // queries. A leading-wildcard LIKE cannot use an index, so each of those evaluations was a
    // scan of the audit table. Measured 2026-11-16 against the imported live database (4,709
    // flags, 1,006 candidates): 497 SECONDS, and shared hosting would be worse while keeping
    // MySQL busy for that whole time.
    //
    // Identical semantics, set-based: read the candidates once, read the repair's audit keys
    // once, and match the `branch-date-` prefix in PHP. Two cheap queries, no per-row scan.
    $scan = static function () use ($db, $since, $humanUntouched): array {
        $stmt = $db->prepare(
            "SELECT vf.id, vf.branch_id, vf.ledger_date, vf.kind, DATE(vf.created_at) AS created_date
               FROM dl_variance_flags vf
              WHERE {$humanUntouched}
              ORDER BY vf.id ASC"
        );
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [[], [], 0];
        }

        $proof = $db->prepare(
            "SELECT entity_id, DATE(created_at) AS created_date
               FROM audit_logs
              WHERE module = 'daily-ledger'
                AND action = 'finalize_shift'
                AND new_data LIKE '%repair_closed_day_sweep%'
                AND created_at >= CURDATE()"
        );
        $proof->execute();
        $provenPrefixes = [];
        foreach ($proof->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            // The audit writes entity_id "<branch>-<date>-<shift>"; the old predicate matched
            // exactly that prefix, so collapse it to "<day>|<branch>-<date>-".
            if (preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})-[A-Za-z]+$/', (string)$p['entity_id'], $m)) {
                $provenPrefixes[$p['created_date'] . '|' . $m[1] . '-' . $m[2] . '-'] = true;
            }
        }

        $matched = [];
        $unprovenCount = 0;
        foreach ($rows as $r) {
            $key = $r['created_date'] . '|' . $r['branch_id'] . '-' . $r['ledger_date'] . '-';
            if (isset($provenPrefixes[$key])) {
                $matched[] = $r;
            } else {
                $unprovenCount++;
            }
        }
        return [$rows, $matched, $unprovenCount];
    };

    [, $provenRows, $unproven] = $scan();
    $proven = count($provenRows);
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
        $dates = array_column($provenRows, 'ledger_date');
        sort($dates);
        $days = [];
        foreach ($provenRows as $r) {
            $days[$r['branch_id'] . '-' . $r['ledger_date']] = true;
        }
        echo '  their dates           : ' . (string)$dates[0] . ' .. ' . (string)end($dates)
            . ' across ' . count($days) . " branch-day(s)\n";

        // Grouped in PHP from the rows already read — the old version re-ran the EXISTS proof
        // for this GROUP BY, which doubled an already expensive scan.
        $groups = [];
        foreach ($provenRows as $r) {
            $key = $r['ledger_date'] . '|' . $r['branch_id'] . '|' . $r['kind'];
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }
        krsort($groups);
        echo "\n  sample (date / branch / kind / rows):\n";
        $shown = 0;
        foreach ($groups as $key => $count) {
            [$gDate, $gBranch, $gKind] = explode('|', (string)$key);
            echo '    ' . $gDate . '  branch ' . $gBranch . '  ' . $gKind . '  x' . $count . "\n";
            if (++$shown >= 10) {
                break;
            }
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
        // The ids come from the proven rows already in memory (ordered by id ASC).
        $ids = array_slice(array_map(static fn(array $r): int => (int)$r['id'], $provenRows), 0, $limit);
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

    // Re-scan the same way, so "remaining" is measured by the same proof that chose the deletes.
    [, $remainingRows] = $scan();
    $remaining = count($remainingRows);
    echo "\n  deleted               : " . number_format($deleted) . "\n";
    echo '  remaining proven      : ' . number_format($remaining) . ($remaining > 0 && $deleted >= $limit ? " (limit reached — re-run)" : '') . "\n";
    echo '  variance flags now    : ' . number_format((int)$db->query('SELECT COUNT(*) FROM dl_variance_flags')->fetchColumn()) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Purge failed: ' . $e->getMessage() . "\n");
    exit(1);
}
