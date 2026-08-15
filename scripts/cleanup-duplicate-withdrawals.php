<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "FATAL: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers-pos.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "FATAL: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);

// ── Target the baronledger DB (tenant 207) via raw PDO ──
$b = new PDO("mysql:host=127.0.0.1;dbname=baronledger;charset=utf8mb4", 'root', $_ENV['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Find duplicate withdrawal groups (same branch/product/date/type/reason/qty).
$groups = $b->query(
    "SELECT branch_id, product_id, ledger_date, withdrawal_type, reason_code, quantity, COUNT(*) c
       FROM dl_cashier_withdrawals
      GROUP BY branch_id, product_id, ledger_date, withdrawal_type, reason_code, quantity
     HAVING c > 1"
)->fetchAll();

if (!$groups) {
    echo "No duplicate withdrawal groups found.\n";
    exit(0);
}

$sel = $b->prepare(
    "SELECT id, created_at FROM dl_cashier_withdrawals
      WHERE branch_id=:b AND product_id=:p AND ledger_date=:d AND withdrawal_type=:t
        AND reason_code <=> :r AND quantity=:q ORDER BY created_at, id"
);
$del = $b->prepare("DELETE FROM dl_cashier_withdrawals WHERE id=:id");

$totalDeleted = 0;
$affected = []; // branch|date pairs needing variance recompute
foreach ($groups as $g) {
    $sel->execute([
        ':b' => $g['branch_id'], ':p' => $g['product_id'], ':d' => $g['ledger_date'],
        ':t' => $g['withdrawal_type'], ':r' => $g['reason_code'], ':q' => $g['quantity'],
    ]);
    $rows = $sel->fetchAll();
    $keepId = (int)$rows[0]['id'];
    foreach (array_slice($rows, 1) as $r) {
        printf("  %s DEL %s p=%s %s qty=%s id=%d (dup of id=%d, %s)\n",
            $apply ? 'APPLY' : 'DRY', $g['ledger_date'], $g['product_id'], $g['withdrawal_type'],
            $g['quantity'], $r['id'], $keepId, $r['created_at']);
        if ($apply) {
            $del->execute([':id' => (int)$r['id']]);
        }
        $totalDeleted++;
        $affected[$g['branch_id'] . '|' . $g['ledger_date']] = true;
    }
}

echo "\n" . ($apply ? "Deleted" : "Would delete") . " {$totalDeleted} duplicate row(s).\n";

// Rebuild ledger withdraw totals from the remaining records and recalc sales for
// the affected branch/dates, so the ledger stays consistent with the withdrawals.
if ($apply && $affected !== []) {
    $recalc = $b->prepare(
        "UPDATE dl_daily_ledger dl
            JOIN (
              SELECT branch_id, product_id, ledger_date, COALESCE(SUM(quantity),0) AS qty
                FROM dl_cashier_withdrawals
               WHERE withdrawal_type <> 'adjustment_add'
               GROUP BY branch_id, product_id, ledger_date
            ) s ON s.branch_id = dl.branch_id AND s.product_id = dl.product_id AND s.ledger_date = dl.ledger_date
            SET dl.withdraw = COALESCE(s.qty, dl.withdraw)
          WHERE dl.branch_id = :bid AND dl.ledger_date = :d AND dl.shift = 'AM'"
    );
    $recalcSales = $b->prepare(
        "UPDATE dl_daily_ledger
            SET sales = CASE WHEN bal_end IS NULL THEN NULL
                             ELSE GREATEST(0, COALESCE(beg_bal,0) + COALESCE(addtl,0) - COALESCE(withdraw,0) - COALESCE(bal_end,0)) END
          WHERE branch_id = :bid AND ledger_date = :d AND shift = 'AM'"
    );
    foreach (array_keys($affected) as $key) {
        [$bid, $d] = explode('|', $key);
        $n = $recalc->execute([':bid' => (int)$bid, ':d' => $d]);
        $n2 = $recalcSales->execute([':bid' => (int)$bid, ':d' => $d]);
        echo "  recalc ledger withdraw ({$n}) + sales ({$n2}) for branch={$bid} date={$d}\n";
    }
}

// Recompute variances for affected branch/dates.
if ($apply && $affected !== []) {
    app()->tenant()->setTenantId(207);
    modulePushContext('daily-ledger');
    foreach (array_keys($affected) as $key) {
        [$bid, $d] = explode('|', $key);
        dl_recomputeVariancesForDay((int)$bid, $d);
        echo "  recomputed variances for branch={$bid} date={$d}\n";
    }
    modulePopContext();
}

// Post-state summary
$after = $b->query("SELECT COUNT(*) FROM dl_cashier_withdrawals")->fetchColumn();
echo "dl_cashier_withdrawals rows now: {$after}\n";
