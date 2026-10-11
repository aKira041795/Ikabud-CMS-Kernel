<?php
declare(strict_types=1);

/**
 * PROBE: are the forecast's "measured zero-sales days" really measured?
 *
 * The forecast counts a day the BRANCH recorded but the product has a zero/absent sale as a
 * measured zero-demand day. That is only true when the product was AVAILABLE to sell. A day the
 * branch held nothing (beg 0, nothing received, nothing produced) cannot produce a sale at any
 * level of demand, so its zero is a CENSORED observation, not evidence of low demand - and
 * averaging it in drags every suggestion toward zero.
 *
 * Read-only. Prints the cell counts that decide whether this matters on tenant 207.
 *
 * Usage: php tools/probe-forecast-censored-demand.php [date_from] [date_to]
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/forecast';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$db = module()->db();
$from = $argv[1] ?? '2026-09-28';
$to = $argv[2] ?? '2026-10-11';

// Official-only, the same predicate the pages use.
$official = dl_officialOnlySqlPredicate('dl', 'ss');

// ModuleDB rejects derived tables ("accessed undeclared table 'cells'"), so the two case
// expressions are repeated inline in each aggregate rather than selected from a subquery.
$salesExpr = 'CASE WHEN dl.bal_end IS NULL THEN 0 ELSE GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) END';
$stockedExpr = 'CASE WHEN COALESCE(dl.beg_bal,0) > 0 OR COALESCE(dl.addtl,0) > 0 THEN 1 ELSE 0 END';

$sql = "SELECT
            COALESCE(SUM(CASE WHEN ({$salesExpr}) = 0 AND ({$stockedExpr}) = 0 THEN 1 ELSE 0 END), 0) AS zero_and_unstocked,
            COALESCE(SUM(CASE WHEN ({$salesExpr}) = 0 AND ({$stockedExpr}) = 1 THEN 1 ELSE 0 END), 0) AS zero_but_stocked,
            COALESCE(SUM(CASE WHEN ({$salesExpr}) > 0 THEN 1 ELSE 0 END), 0) AS sold
          FROM dl_daily_ledger dl
          LEFT JOIN dl_ledger_shift_status ss
                 ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
                AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
         WHERE dl.ledger_date BETWEEN ? AND ?
           {$official}";

$stmt = $db->prepare($sql);
$stmt->execute([$from, $to]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$unstocked = (int)($row['zero_and_unstocked'] ?? 0);
$stockedZero = (int)($row['zero_but_stocked'] ?? 0);
$sold = (int)($row['sold'] ?? 0);
$zeroTotal = $unstocked + $stockedZero;

printf("window %s..%s (official rows only)\n", $from, $to);
printf("  cells with a sale                      : %s\n", number_format($sold));
printf("  zero-sale cells, product WAS stocked   : %s   <- genuine measured zero demand\n", number_format($stockedZero));
printf("  zero-sale cells, product was NOT stocked: %s   <- CENSORED: unbuyable at any demand\n", number_format($unstocked));
printf("  zero-sale cells total                  : %s\n", number_format($zeroTotal));
printf("  censored share of all zero-sale cells  : %s\n", $zeroTotal > 0 ? number_format(100 * $unstocked / $zeroTotal, 1) . '%' : 'n/a');
printf("  censored share of ALL cells            : %s\n", ($sold + $zeroTotal) > 0 ? number_format(100 * $unstocked / ($sold + $zeroTotal), 1) . '%' : 'n/a');

// Consequence: what a stream's daily average looks like with and without the censored days.
$perStream = $db->prepare(
    "SELECT dl.branch_id, dl.product_id, dl.shift,
            COUNT(*) AS cells,
            SUM(CASE WHEN ({$salesExpr}) = 0 AND ({$stockedExpr}) = 0 THEN 1 ELSE 0 END) AS censored,
            SUM({$salesExpr}) AS units
       FROM dl_daily_ledger dl
       LEFT JOIN dl_ledger_shift_status ss
              ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
             AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
      WHERE dl.ledger_date BETWEEN ? AND ?
        {$official}
      GROUP BY dl.branch_id, dl.product_id, dl.shift
     HAVING censored > 0 AND units > 0
      ORDER BY censored DESC, units DESC
      LIMIT 8"
);
$perStream->execute([$from, $to]);
$rows = $perStream->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($rows !== []) {
    printf("\n  streams where censoring moves the average (capped at 8):\n");
    printf("  %-9s %-8s %-6s %-7s %-9s %-10s %-10s %s\n", 'branch', 'product', 'shift', 'cells', 'censored', 'units', 'avg_all', 'avg_available');
    foreach ($rows as $r) {
        $cells = (int)$r['cells'];
        $censored = (int)$r['censored'];
        $units = (int)$r['units'];
        $available = max(1, $cells - $censored);
        printf("  %-9d %-8d %-6s %-7d %-9d %-10d %-10s %s\n",
            (int)$r['branch_id'], (int)$r['product_id'], (string)$r['shift'], $cells, $censored, $units,
            number_format($units / max(1, $cells), 2),
            number_format($units / $available, 2));
    }
}
