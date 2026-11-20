#!/usr/bin/env php
<?php

/**
 * Reopen the shifts that were marked FINALIZED while they still owed an ending count.
 *
 * WHY THIS EXISTS
 * ---------------
 * A finalized shift is immutable: dl_assertShiftMutable() refuses every write, so the ending cells
 * on the sheet are locked. If a shift was stamped `finalized` while activity-bearing rows still had
 * bal_end NULL, those endings can never be entered — the row is neither open work nor recorded
 * truth, and the Business Health view keeps reporting the day as Incomplete no matter what the
 * branch does.
 *
 * Measured on tenant 203 (2026-11-20, live dump), period 2026-10-01..2026-11-20:
 *   2,804 ledger rows were pending an ending.
 *     896 sat on OPEN shifts                     -> ordinary open work, clears on its own.
 *   1,318 had NO shift-status row at all         -> 33 shift-days, dominated by that same day: a
 *                                                   business date in progress, also normal.
 *     631 sat on shifts already FINALIZED        -> 56 shift-days; 608 of the 631 were
 *                                                   activity-bearing, i.e. a real ending was owed.
 * On branch 9 the AM shift for 2026-11-16 held 113 owed endings; the rows were written on
 * 2026-11-15 between 17:15 and 23:20 and the shift was stamped finalized the next afternoon.
 * AM and PM carried the SAME finalized_at to the second, which no in-app path produces — the three
 * code paths that finalize all check the endings and all write an audit row, and there is no audit
 * row for those shift-days at all. So the stamp came from outside the application (a manual SQL
 * repair or an import), and the application simply has no way back: there is no endpoint that
 * reopens a shift on a day that is already closed.
 *
 * This worker is that way back. It is deliberately narrow: it only touches a shift that is BOTH
 * finalized AND still owing an ending — never a complete shift, never a ledger value, never money.
 *
 * THE PREDICATE IS SHARED, NOT REIMPLEMENTED
 * ------------------------------------------
 * "Owes an ending" is answered by dl_shiftMissingEndings(), the same function the finalize gate
 * uses. A repair tool that computed completeness its own way could reopen shifts the application
 * considers finished, or leave shut the ones it considers unfinished.
 *
 * SAFETY
 * ------
 * - DRY RUN BY DEFAULT. Nothing changes without an explicit --apply.
 * - Reversible and auditable: each shift writes `reopen_shift` to audit_logs with the owed count
 *   and the reason BEFORE the status changes, so "this was locked with 113 endings missing" stays
 *   provable. Reopening is itself reversible (the shift can be finalized again once complete).
 * - Idempotent: a reopened shift is no longer `finalized`, so a re-run finds nothing further.
 * - It also reopens the DAY when the day is closed, because a reopened shift on a closed day is
 *   still read-only (dl_cashierMayEdit + the day-status guard). reopened_at is what authorises a
 *   cashier to correct a past day (dl_deliberateReopenUnlocksEntryEdit).
 * - Bounded: --limit caps how many shift-days are handled in one run.
 * - Never touches dl_daily_ledger, dl_variance_flags, products, branches, or any financial record.
 *
 * USAGE
 *   php modules/daily-ledger/cli/reopen-incomplete-shifts.php --tenant=203            # report only
 *   php modules/daily-ledger/cli/reopen-incomplete-shifts.php --tenant=203 --apply
 *
 *   --limit=<n>        shift-days handled in one run (default 200)
 *   --date-from=<ymd>  only consider dates from this one (inclusive)
 *   --date-to=<ymd>    only consider dates up to this one (inclusive)
 *   --show=<n>         rows listed in the report (default 20)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'apply', 'limit::', 'date-from::', 'date-to::', 'show::']);

$tenantId = max(0, (int)($options['tenant'] ?? 0));
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/reopen-incomplete-shifts.php --tenant=<id> [--apply] [--limit=<n>] [--date-from=<ymd>] [--date-to=<ymd>]\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$limit = max(1, min(20000, (int)($options['limit'] ?? 200)));
$show = max(1, min(500, (int)($options['show'] ?? 20)));
$dateFrom = trim((string)($options['date-from'] ?? ''));
$dateTo = trim((string)($options['date-to'] ?? ''));
foreach ([$dateFrom, $dateTo] as $bound) {
    if ($bound !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bound)) {
        fwrite(STDERR, "Dates must be YYYY-MM-DD.\n");
        exit(2);
    }
}

$lockPath = sys_get_temp_dir() . '/daily-ledger-reopen-incomplete-' . $tenantId . '.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A reopen-incomplete-shifts worker is already running for tenant {$tenantId}.\n");
    exit(3);
}

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Incomplete Shift Repair',
        'username' => 'daily-ledger-incomplete-shift-repair',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }

    // handlers.php requires helpers.php, which calls kernel helpers at include time, so it is
    // required only after the kernel and the module context exist.
    require_once __DIR__ . '/../handlers.php';
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = $context->db();

    // Cheap candidate scan: a finalized shift that still has a movement-bearing row without an
    // ending. This is a superset of the authoritative answer and only saves round trips.
    $candidateSql =
        "SELECT st.branch_id, st.ledger_date, st.shift, COUNT(*) AS pending_rows
           FROM dl_ledger_shift_status st
           JOIN dl_daily_ledger dl
             ON dl.branch_id = st.branch_id AND dl.ledger_date = st.ledger_date
            AND dl.shift = st.shift COLLATE utf8mb4_unicode_ci
          WHERE st.status = 'finalized'
            AND dl.bal_end IS NULL
            AND (dl.beg_bal <> 0 OR dl.addtl <> 0 OR dl.withdraw <> 0)";
    $candidateBind = [];
    if ($dateFrom !== '') {
        $candidateSql .= ' AND st.ledger_date >= :from';
        $candidateBind[':from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $candidateSql .= ' AND st.ledger_date <= :to';
        $candidateBind[':to'] = $dateTo;
    }
    $candidateSql .= ' GROUP BY st.branch_id, st.ledger_date, st.shift ORDER BY st.ledger_date, st.branch_id';
    $candidateStmt = $db->prepare($candidateSql);
    $candidateStmt->execute($candidateBind);
    $candidates = $candidateStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Authoritative filter: the same predicate the finalize gate uses, so "complete" cannot mean
    // two different things in the gate and in the repair.
    $work = [];
    foreach ($candidates as $row) {
        $branchId = (int)$row['branch_id'];
        $date = (string)$row['ledger_date'];
        $shift = dl_normalizeShift((string)$row['shift']);
        $missing = dl_shiftMissingEndings($db, $branchId, $date, $shift);
        if ($missing === []) {
            continue;
        }
        $work[] = [
            'branch_id' => $branchId,
            'ledger_date' => $date,
            'shift' => $shift,
            'owed' => count($missing),
            'candidate_rows' => (int)$row['pending_rows'],
            'day_status' => (string)(dl_getDayStatus($branchId, $date) ?: 'open'),
        ];
    }

    $owedRows = 0;
    $closedDays = 0;
    foreach ($work as $w) {
        $owedRows += $w['owed'];
        if ($w['day_status'] === 'closed') {
            $closedDays++;
        }
    }

    echo "tenant {$tenantId}\n";
    echo '  finalized shifts still owing an ending : ' . count($work) . " shift-day(s)\n";
    echo "  endings actually owed                  : {$owedRows}\n";
    echo "  of those shift-days on a CLOSED day    : {$closedDays} (the day is reopened too)\n";
    if ($work !== []) {
        @usort($work, static function (array $a, array $b): int {
            return $b['owed'] <=> $a['owed'];
        });
        echo "\n  largest first (branch / date / shift · endings owed):\n";
        foreach (array_slice($work, 0, $show) as $w) {
            printf(
                "    #%-4d %s %s · %3d owed%s\n",
                $w['branch_id'],
                $w['ledger_date'],
                $w['shift'],
                $w['owed'],
                $w['day_status'] === 'closed' ? ' · day closed' : ''
            );
        }
    }

    if (!$apply) {
        echo "\ndry run - nothing changed. Pass --apply to reopen these shifts.\n";
        fclose($lock);
        exit(0);
    }

    $handled = 0;
    $reopenedShifts = 0;
    $reopenedDays = 0;
    foreach ($work as $w) {
        if ($handled >= $limit) {
            break;
        }
        $handled++;
        $branchId = $w['branch_id'];
        $date = $w['ledger_date'];
        $shift = $w['shift'];

        $db->beginTransaction();
        try {
            // Record WHY before the status changes: the audit row is the evidence that this shift
            // was locked while it still owed counts, which is the fact no other surface keeps.
            dl_auditLog(
                'reopen_shift',
                $branchId,
                'dl_ledger_shift_status',
                "{$branchId}-{$date}-{$shift}",
                ['status' => 'finalized'],
                [
                    'status' => 'open',
                    'shift' => $shift,
                    'source' => 'reopen_incomplete_shifts_worker',
                    'reason' => 'finalized while activity-bearing rows had no ending count',
                    'endings_owed' => $w['owed'],
                    'day_was_closed' => $w['day_status'] === 'closed',
                ]
            );

            $db->prepare(
                'UPDATE dl_ledger_shift_status
                    SET status = \'open\', finalized_by = NULL, finalized_at = NULL,
                        pending_notified_at = CURRENT_TIMESTAMP
                  WHERE branch_id = :bid AND ledger_date = :d AND shift = :shift
                    AND status = \'finalized\''
            )->execute([':bid' => $branchId, ':d' => $date, ':shift' => $shift]);
            $reopenedShifts++;

            // A reopened shift on a closed day is still read-only. reopened_at is the authorisation
            // a cashier needs (dl_deliberateReopenUnlocksEntryEdit), so the day is reopened with it.
            if ($w['day_status'] === 'closed') {
                $db->prepare(
                    'UPDATE dl_ledger_day_status
                        SET status = \'open\', reopened_at = CURRENT_TIMESTAMP
                      WHERE branch_id = :bid AND ledger_date = :d'
                )->execute([':bid' => $branchId, ':d' => $date]);
                dl_auditLog(
                    'reopen_day',
                    $branchId,
                    'dl_ledger_day_status',
                    "{$branchId}-{$date}",
                    ['status' => 'closed'],
                    [
                        'status' => 'open',
                        'source' => 'reopen_incomplete_shifts_worker',
                        'reason' => 'the shift was reopened because it was finalized with endings outstanding',
                    ]
                );
                $reopenedDays++;
            }

            $db->commit();
            // Never let a memo answer with the value these writes just replaced.
            dl_forgetDayStatus($branchId, $date);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fwrite(STDERR, "  failed for #{$branchId} {$date} {$shift}: " . $e->getMessage() . "\n");
        }
    }

    // Recount from the database rather than from the snapshot above: the point of the "after" block
    // is to prove the state changed, and a count derived from the plan would say so either way.
    // It carries the SAME date bounds as the run — without them it reports the shift-days the
    // operator deliberately excluded and the exit code says "work remains" when there is none.
    $remaining = 0;
    $recountSql =
        "SELECT st.branch_id, st.ledger_date, st.shift
           FROM dl_ledger_shift_status st
           JOIN dl_daily_ledger dl
             ON dl.branch_id = st.branch_id AND dl.ledger_date = st.ledger_date
            AND dl.shift = st.shift COLLATE utf8mb4_unicode_ci
          WHERE st.status = 'finalized'
            AND dl.bal_end IS NULL
            AND (dl.beg_bal <> 0 OR dl.addtl <> 0 OR dl.withdraw <> 0)";
    $recountBind = [];
    if ($dateFrom !== '') {
        $recountSql .= ' AND st.ledger_date >= :from';
        $recountBind[':from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $recountSql .= ' AND st.ledger_date <= :to';
        $recountBind[':to'] = $dateTo;
    }
    $recountSql .= ' GROUP BY st.branch_id, st.ledger_date, st.shift';
    $recountStmt = $db->prepare($recountSql);
    $recountStmt->execute($recountBind);
    foreach ($recountStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        if (dl_shiftMissingEndings($db, (int)$row['branch_id'], (string)$row['ledger_date'], dl_normalizeShift((string)$row['shift'])) !== []) {
            $remaining++;
        }
    }

    echo "\nafter\n";
    echo "  shifts reopened                  {$reopenedShifts}\n";
    echo "  days reopened                    {$reopenedDays}\n";
    echo "  shift-days still owing an ending {$remaining}\n";
    echo "\nThe reopened days now appear to the branch as editable, and every reopened shift is recorded\n";
    echo "in audit_logs as reopen_shift with the number of endings it was missing.\n";
    fclose($lock);
    exit($remaining === 0 ? 0 : 4);
} catch (\Throwable $e) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
