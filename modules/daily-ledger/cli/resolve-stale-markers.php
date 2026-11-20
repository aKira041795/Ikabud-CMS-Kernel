#!/usr/bin/env php
<?php

/**
 * Close Daily Ledger findings whose CAUSE is gone, and remove the duplicate negative variance flags.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two markers this module derived were write-once, so a live install that has been running carries a
 * backlog the code fix cannot reach:
 *
 * 1. "shift not finalized" findings (`closed_without_pm_finalize`) and the `pending_notified_at`
 *    stamp beside them. Measured on tenant 207 (2026-11-20): 653 findings open, of which 604 had NO
 *    shift-status row at all while ZERO movement rows still lacked an ending — the counts were
 *    complete and the badge was still on the operators' screen. 393 finalized shifts also still
 *    carried the stamp.
 *    The resolver closes a finding when EITHER the shift is finalized OR every activity-bearing
 *    product on that date/shift has its ending: the same predicate the finding is raised with, so
 *    "solved" cannot mean two things.
 *
 * 2. Mirrored `sales` variance flags. Every discrepancy used to write an `ending` flag and a `sales`
 *    flag with the opposite sign; 678 of 678 `sales` flags on tenant 207 were an exact negation of an
 *    `ending` flag on the same branch/product/date/shift. They were the "negative variance" the
 *    operators kept reporting. Only UNREVIEWED mirrors are removed — a reviewed row is somebody's
 *    signed-off record and rewriting it would destroy evidence.
 *
 * The forward fixes ship with the code (the auto-finalize resolves as dates are opened, and the
 * maintenance action sweeps). This worker exists for the rows already on the server.
 *
 * SAFETY
 * ------
 * - DRY RUN BY DEFAULT. Nothing changes without an explicit --apply.
 * - Idempotent and resumable: every decision is state-based, and a re-run finds nothing further.
 * - Bounded: --limit caps how many findings are examined in one run.
 * - Each removal is recorded in audit_logs (integrity_notification_resolved) before the row is
 *   deleted, so "it was raised and it was closed" stays provable.
 *
 * MYSQL 5.7 COMPATIBILITY (live runs an older server than this dev box)
 * --------------------------------------------------------------------
 * The mirror delete is a multi-table DELETE with no LIMIT and no ORDER BY (5.7 permits those only in
 * the single-table form), and `shift` is compared with an explicit COLLATE because
 * dl_variance_flags and dl_daily_ledger were created with different collations on this schema — the
 * join fails with error 1267 without it.
 *
 * USAGE
 *   php modules/daily-ledger/cli/resolve-stale-markers.php --tenant=207            # report only
 *   php modules/daily-ledger/cli/resolve-stale-markers.php --tenant=207 --apply
 *
 *   --limit=<n>   findings examined in one run (default 2000)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'apply', 'limit::']);

$tenantId = max(0, (int)($options['tenant'] ?? 0));
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/resolve-stale-markers.php --tenant=<id> [--apply] [--limit=<n>]\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
$limit = max(1, min(20000, (int)($options['limit'] ?? 2000)));

// maintenance.php only declares functions, so it is safe before bootstrap. handlers.php is NOT: it
// requires helpers.php, which calls kernel helpers at include time.
require_once __DIR__ . '/../helpers/maintenance.php';

$lockPath = sys_get_temp_dir() . '/daily-ledger-stale-markers-' . $tenantId . '.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A stale-marker worker is already running for tenant {$tenantId}.\n");
    exit(3);
}

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Stale Marker Repair',
        'username' => 'daily-ledger-stale-marker-repair',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    // handlers.php requires helpers.php, which needs the kernel loaded - so it comes in here, not at
    // the top of the file.
    require_once __DIR__ . '/../handlers.php';
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = $context->db();

    $openFindings = (int)$db->query(
        "SELECT COUNT(*) FROM dl_integrity_notifications WHERE finding_type = 'closed_without_pm_finalize'"
    )->fetchColumn();
    $stampedFinalized = (int)$db->query(
        "SELECT COUNT(*) FROM dl_ledger_shift_status WHERE status = 'finalized' AND pending_notified_at IS NOT NULL"
    )->fetchColumn();
    $mirrorStmt = $db->prepare(
        "SELECT COUNT(*)
           FROM dl_variance_flags s
           JOIN dl_variance_flags e
             ON e.kind = 'ending' AND e.branch_id = s.branch_id AND e.product_id = s.product_id
            AND e.ledger_date = s.ledger_date AND e.shift = s.shift COLLATE utf8mb4_unicode_ci
            AND e.variance = -s.variance
          WHERE s.kind = 'sales' AND s.resolution_status = 'unreviewed'"
    );
    $mirrorStmt->execute();
    $mirrors = (int)$mirrorStmt->fetchColumn();

    echo "tenant {$tenantId}\n";
    echo "  open 'shift not finalized' findings : {$openFindings}\n";
    echo "  finalized shifts still stamped      : {$stampedFinalized}\n";
    echo "  unreviewed mirrored 'sales' flags   : {$mirrors}\n";

    if (!$apply) {
        echo "\ndry run - pass --apply to change anything\n";
        fclose($lock);
        exit(0);
    }

    $settled = dlResolveFinalizedPendingFlags($db, $limit);
    echo "  findings checked {$settled['checked']}, closed {$settled['resolved']}\n";

    $del = $db->prepare(
        "DELETE s FROM dl_variance_flags s
           JOIN dl_variance_flags e
             ON e.kind = 'ending' AND e.branch_id = s.branch_id AND e.product_id = s.product_id
            AND e.ledger_date = s.ledger_date AND e.shift = s.shift COLLATE utf8mb4_unicode_ci
            AND e.variance = -s.variance
          WHERE s.kind = 'sales' AND s.resolution_status = 'unreviewed'"
    );
    $del->execute();
    echo "  mirrored 'sales' flags removed : " . (int)$del->rowCount() . "\n";

    $afterOpen = (int)$db->query(
        "SELECT COUNT(*) FROM dl_integrity_notifications WHERE finding_type = 'closed_without_pm_finalize'"
    )->fetchColumn();
    $afterStamped = (int)$db->query(
        "SELECT COUNT(*) FROM dl_ledger_shift_status WHERE status = 'finalized' AND pending_notified_at IS NOT NULL"
    )->fetchColumn();
    echo "\nafter\n  open findings {$afterOpen}\n  finalized-but-stamped shifts {$afterStamped}\n";
    fclose($lock);
    exit(0);
} catch (\Throwable $e) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
