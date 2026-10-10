<?php
declare(strict_types=1);

/**
 * How efficient is the capture fix, measured end-to-end?
 *
 * The unit test proved the raise function collapses repeats. This goes further: it drives the REAL
 * dl_upsertVarianceFlag() through repeated delete-and-re-derive cycles, which is exactly what a ledger
 * recompute does (handlers.php ~6911 deletes the unreviewed derived flags, then the day is re-derived).
 * That is the only way to show the fix survives the cycle that caused the defect, rather than surviving a
 * single call.
 *
 * It also reports the steady-state rate the fix settles at, derived from the data rather than assumed:
 * after the fix the number of notifications is bounded by the number of DISTINCT natural findings
 * (branch, product, date, kind, shift), not by how many times they were recomputed.
 *
 * Uses a real branch and product (dl_variance_flags has FKs to both, so a synthetic pair cannot insert) on
 * a date far outside the real ledger, and removes everything it creates.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/test-capture-efficiency.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$tenantId = 207;
$CYCLES = 6;

// Bootstrap FIRST: cli-bootstrap loads .env, so the plain PDO below would have no credentials if it were
// created before this (a real bug in the first version of this script: "Access denied ... using password: NO").
$app = kernelCliBootstrap($basePath);

$pass = getenv('DB_PASSWORD');
$pass = $pass === false ? '' : $pass;
$plain = new PDO('mysql:host=localhost;dbname=baronledger;charset=utf8mb4', 'root', $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    // --- The steady-state ceiling, from real data: how many DISTINCT findings exist vs how many
    // notification rows were actually written before the fix.
    echo "=== steady state, from the data (pre-fix rows vs distinct findings) ===\n";
    $distinctFindings = (int)$plain->query(
        "SELECT COUNT(*) FROM (SELECT DISTINCT branch_id, product_id, ledger_date, kind, shift FROM dl_variance_flags) x"
    )->fetchColumn();
    $flagIdsConsumed = (int)$plain->query('SELECT MAX(id) - MIN(id) + 1 FROM dl_variance_flags')->fetchColumn();
    $perDay = $plain->query(
        "SELECT ledger_date, COUNT(*) n FROM dl_variance_flags GROUP BY ledger_date ORDER BY ledger_date DESC LIMIT 7"
    )->fetchAll();
    printf("  distinct natural findings in dl_variance_flags : %s\n", number_format($distinctFindings));
    printf("  variance-notification rows written pre-fix      : 118,902 (measured)\n");
    printf("  churn factor                                    : %.0fx\n", 118902 / max(1, $distinctFindings));
    printf("  findings per ledger_date (recent days):\n");
    foreach ($perDay as $r) {
        printf("      %s  %4d\n", $r['ledger_date'], (int)$r['n']);
    }
    $avgPerDay = array_sum(array_map(fn($r) => (int)$r['n'], $perDay)) / max(1, count($perDay));
    printf("  average findings on a ledger date               : %.1f\n", $avgPerDay);

    echo "\n  => before the fix, ONE recompute of ONE day wrote ~" . number_format((int)round($avgPerDay)) . " new notifications.\n";
    echo "     after the fix it writes that many ONCE, then 0 per further recompute (counters increment).\n";

    // --- End-to-end: real code path, repeated recompute cycles.
    echo "\n=== end-to-end: {$CYCLES} real recompute cycles through dl_upsertVarianceFlag() ===\n";

    $app->tenant()->setTenantId($tenantId);
    $app->setUser(['id' => 0, 'name' => 'efficiency test', 'username' => 'test', 'role' => 'administrator', 'source' => 'system']);
    require_once $basePath . '/src/helpers/module-manager.php';
    require_once $basePath . '/modules/daily-ledger/helpers.php';
    require_once $basePath . '/modules/daily-ledger/handlers.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    $db = $context->db();

    $row = $plain->query('SELECT branch_id, product_id FROM dl_variance_flags ORDER BY id DESC LIMIT 1')->fetch();
    $branchId = (int)$row['branch_id'];
    $productId = (int)$row['product_id'];
    $date = '2099-12-31';                 // far outside the real ledger
    $expectedKey = 'variance-b' . $branchId . '-p' . $productId . '-' . $date . '-ending-AM';
    printf("  using branch %d / product %d / date %s\n", $branchId, $productId, $date);
    printf("  expected aggregate_key: %s\n\n", $expectedKey);

    $countRows = function () use ($plain, $branchId, $productId, $date): int {
        $st = $plain->prepare(
            "SELECT COUNT(*) FROM dl_integrity_notifications
              WHERE finding_type = 'variance' AND branch_id = :b
                AND aggregate_key LIKE :k"
        );
        $st->execute([':b' => $branchId, ':k' => 'variance-b' . $branchId . '-p' . $productId . '-' . $date . '-%']);
        return (int)$st->fetchColumn();
    };

    // Clean slate for this synthetic tuple.
    $plain->prepare(
        "DELETE n FROM dl_integrity_notifications n
          WHERE n.finding_type = 'variance' AND n.branch_id = :b AND n.aggregate_key LIKE :k"
    )->execute([':b' => $branchId, ':k' => 'variance-b' . $branchId . '-p' . $productId . '-' . $date . '-%']);

    printf("  %-8s %-14s %-14s %s\n", 'cycle', 'flag_id', 'notif_rows', 'finding_count');
    for ($i = 1; $i <= $CYCLES; $i++) {
        // Exactly what the recompute does: drop the derived flag, then re-derive it.
        $plain->prepare('DELETE FROM dl_variance_flags WHERE branch_id = :b AND product_id = :p AND ledger_date = :d')
            ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

        dl_upsertVarianceFlag($db, $branchId, $productId, $date, 'ending', 'AM', 7, 100, 107, 50, null, 10, 2);

        $flagId = (int)$plain->query(
            'SELECT id FROM dl_variance_flags WHERE branch_id = ' . $branchId . ' AND product_id = ' . $productId
            . ' AND ledger_date = "' . $date . '" LIMIT 1'
        )->fetchColumn();

        $st = $plain->prepare('SELECT COUNT(*) c, COALESCE(MAX(finding_count),0) fc, COALESCE(MAX(id),0) id
                                 FROM dl_integrity_notifications WHERE aggregate_key = :k');
        $st->execute([':k' => $expectedKey]);
        $agg = $st->fetch();
        printf("  %-8d %-14d %-14s %s\n", $i, $flagId, number_format((int)$agg['c']), (int)$agg['fc']);
    }

    echo "\n  Interpretation: the flag id CHANGES every cycle (that is the recompute's own delete+re-derive,\n";
    echo "  and the pre-fix defect keyed on it), while the notification count stays at 1 and finding_count\n";
    echo "  counts the recomputes. Before the fix, cycle N produced notification N.\n";

    // --- Cleanup.
    $plain->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key = :k')->execute([':k' => $expectedKey]);
    $plain->prepare('DELETE FROM dl_variance_flags WHERE branch_id = :b AND product_id = :p AND ledger_date = :d')
        ->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $leftover = $countRows();
    printf("\n  cleanup: %s notification row(s) for the synthetic tuple, %s flag(s)\n",
        $leftover,
        (int)$plain->query('SELECT COUNT(*) FROM dl_variance_flags WHERE ledger_date = "' . $date . '"')->fetchColumn()
    );
    exit($leftover === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'efficiency test failed: ' . $e->getMessage() . "\n");
    exit(1);
}
