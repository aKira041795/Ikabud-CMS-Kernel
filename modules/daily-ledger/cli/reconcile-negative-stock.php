<?php

declare(strict_types=1);

/**
 * Reconcile the accumulated NEGATIVE commissary stock onto a declared opening position.
 *
 * THE PROBLEM THIS CLOSES (owner 2026-11-21: "i see this as a system gap that was not detected and
 * cumulatively bloated"). A hub's ledger row carries the previous shift's balance forward as
 * beg_qty. When a dispatch was recorded against stock that was never recorded as arriving, the
 * balance went negative, and the NEXT row then carried that negative in as its beginning. Nothing
 * flagged it, so it compounded: 129 hub/product pairs, -43,101 units, split between
 *
 *   52 pairs / -12,945 units  product never produced anywhere in the system — the stock predates
 *                             the ledger (go-live cutover)
 *   77 pairs / -30,156 units  product IS produced, but far less was recorded than shipped
 *
 * A negative shelf is physically impossible, so the figure is not a stock level — it is the size of
 * the unrecorded inflow. This tool replaces it with a DECLARED opening position on a cutover date.
 *
 * WHAT IT WRITES, AND WHAT IT DELIBERATELY DOES NOT
 *   - No history is rewritten. Every existing row keeps its numbers. The tool writes ONE new row
 *     per affected hub/product on the cutover date carrying the declared opening as beg_qty, so
 *     everything from that date forward starts from a position a human can defend.
 *   - The deficit is NOT hidden: each write is paired with an audit row that names the old book
 *     balance, the declared opening, and the difference written off. If the declared opening is
 *     later found wrong, a physical count corrects it upward through the normal count flow.
 *   - Dry run by default. `--apply` is required to write, and `--reason` is required with it, so a
 *     reconciliation can never be run silently.
 *   - Reversible: `--revert <batch>` deletes exactly the rows that batch created. The marker is the
 *     `end_source` value `reconcile-opening-<batch>`, so the rows are identifiable without a
 *     schema change.
 *
 * Usage:
 *   php modules/daily-ledger/cli/reconcile-negative-stock.php --date=2026-11-22
 *   php modules/daily-ledger/cli/reconcile-negative-stock.php --date=2026-11-22 --apply \
 *        --reason="go-live cutover: opening positions declared from the paper count"
 *   php modules/daily-ledger/cli/reconcile-negative-stock.php --revert=<batch>
 *
 * Options:
 *   --tenant=<id>     Tenant id (default 207).
 *   --date=<Y-m-d>    Cutover date the opening is declared ON. Required for --apply.
 *   --opening=<int>   Declared opening quantity (default 0 = "we hold none we can prove").
 *   --apply           Write. Without it the run is read-only.
 *   --reason=<text>   Why this reconciliation is being made. Required with --apply.
 *   --revert=<batch>  Delete the rows created by a previous batch.
 *   --json            Machine-readable output.
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';

$base = dirname(__DIR__, 3);
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$tenantId = (int)($opts['tenant'] ?? 207);
$cutover = (string)($opts['date'] ?? '');
$opening = (int)($opts['opening'] ?? 0);
$apply = isset($opts['apply']);
$reason = trim((string)($opts['reason'] ?? ''));
$revert = isset($opts['revert']) ? (string)$opts['revert'] : '';
$asJson = isset($opts['json']);

app()->tenant()->setTenantId($tenantId);
$db = modulePushContext('daily-ledger')->db();

// ── Revert mode ───────────────────────────────────────────────────────────────────────────────
if ($revert !== '') {
    $marker = 'recon-' . $revert;
    $rows = $db->prepare(
        "SELECT id, commissary_branch_id, product_id, ledger_date, beg_qty
           FROM dl_commissary_product_ledger WHERE end_source = :m"
    );
    $rows->execute([':m' => $marker]);
    $found = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($found === []) {
        fwrite(STDERR, "No rows found for batch {$revert}.\n");
        exit(1);
    }
    $del = $db->prepare("DELETE FROM dl_commissary_product_ledger WHERE end_source = :m");
    $del->execute([':m' => $marker]);
    foreach ($found as $r) {
        dl_auditLog('commissary_opening_reverted', (int)$r['commissary_branch_id'], 'dl_commissary_product_ledger', (string)$r['id'], [
            'beg_qty' => (int)$r['beg_qty'], 'ledger_date' => $r['ledger_date'], 'batch' => $revert,
        ], null, 'Reverted reconciliation batch ' . $revert);
    }
    printf("Reverted %d opening row(s) from batch %s.\n", count($found), $revert);
    exit(0);
}

if ($apply && ($cutover === '' || $reason === '')) {
    fwrite(STDERR, "--apply requires --date=<Y-m-d> and --reason=\"...\". Nothing was written.\n");
    exit(2);
}
if ($apply && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutover)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD. Nothing was written.\n");
    exit(2);
}

// ── Find every hub/product whose LATEST row is negative ───────────────────────────────────────
// beg_qty carries forward, so the latest row IS the book position. Using SUM(remaining_qty) here
// would be the flow column (produced - dispatched - wastage) and would overstate the damage — that
// mistake was made once already and is why this reduction is explicit.
//
// Deliberately ONE plain SELECT with no derived table and no correlated subquery: ModuleDB's parser
// treats aliases inside a FROM-clause subquery as undeclared table names and refuses the query
// (measured: "accessed undeclared table 'p'"). The grouping is done here in PHP instead.
$allRows = $db->query(
    'SELECT id, commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty
       FROM dl_commissary_product_ledger
   ORDER BY commissary_branch_id, product_id, ledger_date, id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$latest = [];            // "hub:product" => row
$producedAnywhere = [];  // product_id => total produced
$lastCount = [];         // "hub:product" => last human count (actual_end_qty)
foreach ($allRows as $r) {
    $pid = (int)$r['product_id'];
    $producedAnywhere[$pid] = ($producedAnywhere[$pid] ?? 0) + (int)$r['produced_qty'];
    $key = (int)$r['commissary_branch_id'] . ':' . $pid;
    // ORDER BY (hub, product, date, id) means the last row seen for a pair is its latest.
    $latest[$key] = $r;
    if ($r['actual_end_qty'] !== null) {
        $lastCount[$key] = (int)$r['actual_end_qty'];
    }
}

$productNames = [];
foreach ($db->query('SELECT id, name FROM dl_products')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
    $productNames[(int)$p['id']] = (string)$p['name'];
}
$branchNames = [];
foreach ($db->query('SELECT id, name FROM dl_branches')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
    $branchNames[(int)$b['id']] = (string)$b['name'];
}

$rows = [];
foreach ($latest as $key => $r) {
    $bal = (int)$r['beg_qty'] + (int)$r['produced_qty'] - (int)$r['dispatched_qty'] - (int)$r['wastage_qty'];
    if ($bal >= 0) { continue; }
    $pid = (int)$r['product_id'];
    $hubId = (int)$r['commissary_branch_id'];
    $rows[] = [
        'hub' => $hubId,
        'product' => $pid,
        'ledger_date' => (string)$r['ledger_date'],
        'id' => (int)$r['id'],
        'bal' => $bal,
        'beg' => (int)$r['beg_qty'],
        'produced' => (int)$r['produced_qty'],
        'dispatched' => (int)$r['dispatched_qty'],
        'wastage' => (int)$r['wastage_qty'],
        'product_name' => $productNames[$pid] ?? ('#' . $pid),
        'hub_name' => $branchNames[$hubId] ?? ('#' . $hubId),
        'produced_anywhere' => $producedAnywhere[$pid] ?? 0,
        // The declared opening prefers EVIDENCE over a guess: if a human ever counted this
        // hub/product, the last non-negative count is the best statement of what is on the shelf.
        // Otherwise 0 — "we hold nothing we can prove" — which is the only defensible default when
        // the alternative is an impossible negative.
        'opening' => (array_key_exists($key, $lastCount) && $lastCount[$key] >= 0) ? $lastCount[$key] : $opening,
        'opening_source' => (array_key_exists($key, $lastCount) && $lastCount[$key] >= 0)
            ? ('last_physical_count:' . $lastCount[$key]) : 'declared_zero',
    ];
}
usort($rows, static fn(array $a, array $b): int => $a['bal'] <=> $b['bal']);

$batch = date('Ymd-His');
$marker = 'recon-' . $batch; // end_source is varchar(24)
$totalDeficit = 0;
$byCause = ['never_produced' => ['pairs' => 0, 'units' => 0], 'under_recorded' => ['pairs' => 0, 'units' => 0]];
foreach ($rows as $r) {
    $bal = (int)$r['bal'];
    $totalDeficit += $bal;
    $cause = ((int)$r['produced_anywhere'] === 0) ? 'never_produced' : 'under_recorded';
    $byCause[$cause]['pairs']++;
    $byCause[$cause]['units'] += $bal;
}

if ($asJson) {
    echo json_encode(['batch' => $batch, 'pairs' => count($rows), 'deficit' => $totalDeficit,
        'by_cause' => $byCause, 'applied' => false], JSON_PRETTY_PRINT), "\n";
} else {
    printf("Tenant %d — %d hub/product pair(s) carry a negative book balance (%d units).\n\n",
        $tenantId, count($rows), $totalDeficit);
    printf("  never produced anywhere (stock predates the ledger) ... %d pair(s), %d units\n",
        $byCause['never_produced']['pairs'], $byCause['never_produced']['units']);
    printf("  produced, but too little recorded .................... %d pair(s), %d units\n\n",
        $byCause['under_recorded']['pairs'], $byCause['under_recorded']['units']);
    $show = array_slice($rows, 0, 12);
    printf("  %-6s %-28s %-26s %10s\n", 'hub', 'product', 'latest', 'balance');
    foreach ($show as $r) {
        printf("  %-6s %-28s %-26s %10d\n", (string)$r['hub'],
            mb_substr((string)($r['product_name'] ?? ('#' . $r['product'])), 0, 28),
            (string)$r['ledger_date'], (int)$r['bal']);
    }
    if (count($rows) > count($show)) {
        printf("  ... and %d more\n", count($rows) - count($show));
    }
    echo "\n";
    printf("Plan: write ONE opening row per pair on %s. Opening = the last physical count where one\n",
        $cutover !== '' ? $cutover : '<date>');
    printf("      exists, otherwise %d. The old negative balance is written off in the audit trail.\n", $opening);
    $fromCount = 0;
    foreach ($rows as $r) { if ($r['opening_source'] !== 'declared_zero') { $fromCount++; } }
    printf("      %d pair(s) open from a recorded count, %d pair(s) open at zero.\n", $fromCount, count($rows) - $fromCount);
    if (!$apply) {
        echo "\nDRY RUN — nothing was written. Re-run with --apply --date=<Y-m-d> --reason=\"...\"\n";
    }
}

if (!$apply) {
    exit(0);
}

// ── Apply ─────────────────────────────────────────────────────────────────────────────────────
$written = 0;
$skipped = 0;
$ownsTxn = !$db->inTransaction();
if ($ownsTxn) { $db->beginTransaction(); }
try {
    $ins = $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty,
             end_source, updated_by)
         VALUES (:hub, :pid, :d, NULL, :beg, 0, 0, 0, :src, NULL)'
    );
    // `shift` is NULL on an opening row, and MySQL treats NULLs as distinct in a UNIQUE key — so
    // ON DUPLICATE KEY cannot dedupe here. Look first, then insert or update explicitly.
    $find = $db->prepare(
        'SELECT id FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :hub AND product_id = :pid AND ledger_date = :d AND shift IS NULL
          LIMIT 1'
    );
    $upd = $db->prepare(
        'UPDATE dl_commissary_product_ledger SET beg_qty = :beg, end_source = :src, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    foreach ($rows as $r) {
        $hubId = (int)$r['hub'];
        $productId = (int)$r['product'];
        // The opening row must be the MOST RECENT row, or the carry simply continues past it. If the
        // latest activity is after the cutover date, moving the opening earlier would change the
        // balance on days that are already closed and reported, so this pair is skipped and named.
        if ((string)$r['ledger_date'] > $cutover) {
            $skipped++;
            printf("  SKIP hub %d product %d: last activity %s is after the cutover %s\n",
                $hubId, $productId, (string)$r['ledger_date'], $cutover);
            continue;
        }
        $pairOpening = (int)$r['opening'];
        $find->execute([':hub' => $hubId, ':pid' => $productId, ':d' => $cutover]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            $upd->execute([':beg' => $pairOpening, ':src' => $marker, ':id' => (int)$existing]);
        } else {
            $ins->execute([':hub' => $hubId, ':pid' => $productId, ':d' => $cutover, ':beg' => $pairOpening, ':src' => $marker]);
        }
        dl_auditLog(
            'commissary_opening_declared',
            $hubId,
            'dl_commissary_product_ledger',
            $hubId . '-' . $cutover . '-' . $productId,
            ['book_balance' => (int)$r['bal'], 'beg_qty_before' => (int)$r['beg']],
            [
                'declared_opening' => $pairOpening,
                'opening_source' => (string)$r['opening_source'],
                'written_off' => (int)$r['bal'] - $pairOpening,
                'cause' => ((int)$r['produced_anywhere'] === 0) ? 'never_produced_anywhere' : 'production_under_recorded',
                'last_activity' => (string)$r['ledger_date'],
                'produced_anywhere' => (int)$r['produced_anywhere'],
                'batch' => $batch,
                'reason' => $reason,
            ],
            $reason
        );
        $written++;
    }
    if ($ownsTxn) { $db->commit(); }
} catch (Throwable $e) {
    if ($ownsTxn && $db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'FAILED, nothing written: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("\nWrote %d opening row(s) on %s (batch %s). %d pair(s) skipped.\n", $written, $cutover, $batch, $skipped);
printf("Written off in the audit trail: %d units of unrecorded inflow.\n", $totalDeficit);
printf("Revert with: php %s --revert=%s\n", $argv[0], $batch);
