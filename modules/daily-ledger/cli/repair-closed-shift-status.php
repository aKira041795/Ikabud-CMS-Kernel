#!/usr/bin/env php
<?php

/**
 * Repair business days that were CLOSED while their day-ending shift was still open.
 *
 * WHY THIS EXISTS
 * ---------------
 * Until 2026-11-16 the close-of-day cutoff closed a fully-manual day even when the
 * shift that owns the day's sales was still open. The shift then had no way to be
 * finalized — every ledger control is gated on day_status == 'open' — so its sales
 * stayed provisional forever and the branch saw a reference-only page with no close
 * control ("PM shift wlay makita nga closed day, dili mocompute ang sales").
 *
 * Measured on tenant 207: 15 branches on 2026-11-15 alone, and earlier dates too.
 * The cutoff now finalizes the shift when its endings are complete and DEFERS the
 * close when they are not, so new days cannot reach this state. This worker repairs
 * the days that already did.
 *
 * WHAT IT WRITES — only what the recorded counts already imply
 *   1. the day-ending shift is marked finalized (audited, source repair_closed_day_sweep);
 *   2. that shift's sales are recomputed from the stored Beg/Addtl/Wdraw/End values;
 *   3. NOTHING ELSE. The day's variance state is left exactly as it is (see the note at the write
 *      site): re-deriving variances for months-old closed days opened 414 new investigations on the
 *      first run, which is the client's own complaint in reverse ("mawala na ang mga variance nga ok
 *      na"). A normal close still derives and freezes its OWN day.
 * It NEVER changes a count, NEVER reopens a day, NEVER closes an open day, and NEVER
 * touches a day an admin reopened (reopened_at IS NOT NULL).
 *
 * A day whose day-ending shift is MISSING endings is NOT repairable: it is reported
 * as "needs counts" and left exactly as it is, because force-finalizing would publish
 * sales derived from an incomplete sheet.
 *
 * STABILITY
 * ---------
 * - DRY RUN BY DEFAULT. Writing needs an explicit --apply.
 * - Idempotent: the criterion is state-based (closed day, shift not finalized), so a
 *   re-run finds nothing further.
 * - flock guard: two workers cannot fight over the same tenant.
 * - --limit bounds how many days one run may repair.
 *
 * MYSQL 5.7 COMPATIBILITY
 * -----------------------
 * No CTE, no window functions, no JSON_TABLE, plain derived/NOT EXISTS predicates only.
 *
 * USAGE
 *   php modules/daily-ledger/cli/repair-closed-shift-status.php --tenant=207            # report only
 *   php modules/daily-ledger/cli/repair-closed-shift-status.php --tenant=207 --apply
 *
 *   --since=YYYY-MM-DD   earliest business date to inspect (default: 120 days back)
 *   --limit=<n>          maximum days to repair per run       (default 200)
 *   --show=<n>           examples printed per bucket          (default 12)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'apply', 'since::', 'limit::', 'show::']);

// --tenant accepts a numeric id, a tenant_key, or a canonical domain — the live site's id is not
// necessarily 207, and asking an operator to look it up is how the wrong tenant gets repaired.
$tenantRef = trim((string)($options['tenant'] ?? ''));
$tenantId = 0;
if ($tenantRef !== '') {
    if (ctype_digit($tenantRef)) {
        $tenantId = (int)$tenantRef;
    } else {
        try {
            $boot = kernelCliBootstrap(dirname(__DIR__, 3));
            $lookup = $boot->db()->prepare('SELECT id FROM kernel_tenants WHERE tenant_key = :ref OR canonical_domain = :ref2 LIMIT 1');
            $lookup->execute([':ref' => $tenantRef, ':ref2' => $tenantRef]);
            $tenantId = (int)($lookup->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            $tenantId = 0;
        }
    }
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/repair-closed-shift-status.php --tenant=<id|key|domain> [--apply]\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$since = (string)($options['since'] ?? (new DateTimeImmutable('-120 days'))->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    fwrite(STDERR, "Invalid --since, expected YYYY-MM-DD.\n");
    exit(2);
}
$limit = max(1, min(5000, (int)($options['limit'] ?? 200)));
$show = max(0, min(100, (int)($options['show'] ?? 12)));

$lockPath = sys_get_temp_dir() . '/daily-ledger-repair-closed-shift-' . $tenantId . '.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A repair worker is already running for tenant {$tenantId}.\n");
    exit(3);
}

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Closed-Shift Repair',
        'username' => 'daily-ledger-closed-shift-repair',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    require_once __DIR__ . '/../handlers.php';

    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = $context->db();

    // Candidate days: CLOSED, not deliberately reopened, within the window. The
    // day-ending shift differs per branch (single-shift branches end on AM), so the
    // shift question is resolved in PHP rather than guessed in SQL.
    $stmt = $db->prepare(
        "SELECT ds.branch_id, ds.ledger_date, ds.closed_at, b.name AS branch_name
           FROM dl_ledger_day_status ds
           LEFT JOIN dl_branches b ON b.id = ds.branch_id
          WHERE ds.status = 'closed'
            AND ds.reopened_at IS NULL
            AND ds.ledger_date >= :since
          ORDER BY ds.ledger_date ASC, ds.branch_id ASC"
    );
    $stmt->execute([':since' => $since]);
    $days = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $openShiftDays = [];
    foreach ($days as $day) {
        $branchId = (int)($day['branch_id'] ?? 0);
        if ($branchId <= 0) {
            continue;
        }
        $date = (string)$day['ledger_date'];
        $endShift = dl_dayEndingShift($branchId);
        $statusRow = dl_getShiftStatus($db, $branchId, $date, $endShift);
        if ($statusRow !== null && (string)$statusRow['status'] === 'finalized') {
            continue;
        }
        $missing = dl_shiftMissingEndings($db, $branchId, $date, $endShift);
        $openShiftDays[] = [
            'branch_id' => $branchId,
            'branch_name' => (string)($day['branch_name'] ?? ''),
            'date' => $date,
            'end_shift' => $endShift,
            'missing' => count($missing),
            'status_row_exists' => $statusRow !== null,
        ];
    }

    $repairable = array_values(array_filter($openShiftDays, static fn(array $d): bool => $d['missing'] === 0));
    $needsCounts = array_values(array_filter($openShiftDays, static fn(array $d): bool => $d['missing'] > 0));

    echo "Daily Ledger closed-day shift repair — tenant {$tenantId}\n";
    echo '  mode                        : ' . ($apply ? 'APPLY (writing)' : 'DRY RUN (no writes)') . "\n";
    echo "  window                      : {$since} .. today\n";
    echo '  repaired per run limit      : ' . $limit . ($apply ? '' : ' (ignored in dry run)') . "\n";
    echo "\n";
    echo '  closed days inspected       : ' . number_format(count($days)) . "\n";
    echo '  closed, ending shift OPEN   : ' . number_format(count($openShiftDays)) . "\n";
    echo '    repairable (counts complete): ' . number_format(count($repairable)) . "\n";
    echo '    needs counts (left alone)  : ' . number_format(count($needsCounts)) . "\n";

    $printBucket = static function (string $label, array $rows) use ($show): void {
        if ($rows === []) {
            return;
        }
        echo "\n  " . $label . ":\n";
        foreach (array_slice($rows, 0, $show) as $row) {
            printf(
                "    %s  branch %-4d %-28s %s shift, %d missing ending(s)\n",
                $row['date'],
                $row['branch_id'],
                mb_substr($row['branch_name'], 0, 28),
                $row['end_shift'],
                $row['missing']
            );
        }
        if (count($rows) > $show) {
            echo '    … ' . (count($rows) - $show) . " more\n";
        }
    };
    $printBucket('REPAIRABLE (shift finalized + sales computed + variances frozen)', $repairable);
    $printBucket('NEEDS COUNTS (admin must reopen; this worker will not force a finalize)', $needsCounts);

    if (!$apply) {
        echo "\nDry run only. Re-run with --apply to repair the " . count($repairable) . " repairable day(s).\n";
        exit(0);
    }

    $finalized = 0;
    $skipped = 0;
    $failed = 0;
    foreach (array_slice($repairable, 0, $limit) as $row) {
        $branchId = (int)$row['branch_id'];
        $date = (string)$row['date'];
        $endShift = (string)$row['end_shift'];
        $ownsTxn = !$db->inTransaction();
        try {
            if ($ownsTxn) {
                $db->beginTransaction();
            }

            // Re-verify under lock: the day must still be closed and not reopened,
            // and the shift must still be open. Another actor may have fixed or
            // deliberately reopened it between selection and this write.
            if (dl_lockDayStatusRow($db, $branchId, $date) !== 'closed') {
                $db->rollBack();
                $skipped++;
                continue;
            }
            $reopenStmt = $db->prepare('SELECT reopened_at FROM dl_ledger_day_status WHERE branch_id = :bid AND ledger_date = :d LIMIT 1');
            $reopenStmt->execute([':bid' => $branchId, ':d' => $date]);
            $reopenedAt = $reopenStmt->fetchColumn();
            if ($reopenedAt !== false && $reopenedAt !== null && (string)$reopenedAt !== '') {
                $db->rollBack();
                $skipped++;
                continue;
            }
            $shiftRow = dl_lockShiftStatusRow($db, $branchId, $date, $endShift);
            if ((string)($shiftRow['status'] ?? 'open') === 'finalized') {
                $db->rollBack();
                $skipped++;
                continue;
            }

            $result = dl_finalizeDayEndingShiftCore($db, $branchId, $date, $endShift, null, 'repair_closed_day_sweep');
            if (!$result['finalized']) {
                $db->rollBack();
                $skipped++;
                continue;
            }

            // A closed day's variances are the frozen record of that day.
            // Deliberately NO variance recompute / freeze here.
            //
            // Measured 2026-11-16 on tenant 207: this worker's first run derived 414 NEW unreviewed
            // flags across 32 branch-days from 2026-08-15 .. 2026-11-15 — days that had been closed
            // for months and had simply never been evaluated (the old cutoff only recomputed for
            // finalized days). That is the exact symptom the client asked to stop: "mawala na ang
            // mga variance nga ok na". A repair whose job is to make SALES official must not open a
            // retroactive investigation queue; the day's variance state is left exactly as it was.
            // Normal closes still derive and freeze their own day — that is a live operator's record,
            // not history being re-judged.

            if ($ownsTxn) {
                $db->commit();
            }
            $finalized++;
            echo "  repaired: {$date} branch {$branchId} {$endShift}\n";
        } catch (\Throwable $e) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (\Throwable $ignored) {
            }
            $failed++;
            write_log('daily-ledger closed-shift repair failed', 'error', [
                'branch_id' => $branchId,
                'ledger_date' => $date,
                'shift' => $endShift,
                'error' => $e->getMessage(),
            ]);
            echo "  FAILED  : {$date} branch {$branchId} {$endShift} — " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
    echo '  finalized : ' . $finalized . "\n";
    echo '  skipped   : ' . $skipped . "\n";
    echo '  failed    : ' . $failed . "\n";
    echo '  still needing counts : ' . count($needsCounts) . " (admin reopen required)\n";
    exit($failed > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Repair failed: ' . $e->getMessage() . "\n");
    exit(1);
}
