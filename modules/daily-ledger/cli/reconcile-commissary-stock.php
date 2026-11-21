#!/usr/bin/env php
<?php

/**
 * Reconcile a commissary's stock from THREE INDEPENDENT SOURCES instead of trusting one column.
 *
 * WHY THIS EXISTS
 * ---------------
 * Owner, 2026-11-21: "it's simply not possible for a commissary to have negative inventory …
 * how was shipped computed? from where?" That question cannot be answered from the ledger alone,
 * because `dl_commissary_product_ledger` is ONE opinion. This tool rebuilds the movement for each
 * item from three sources that do not read each other:
 *
 *   1. OPENING      - the `beg_qty` of the item's FIRST row in the window (what the hub said it
 *                     already held when it started encoding).
 *   2. ADDITIONALS  - `SUM(produced_qty)`: what the production lane recorded as made (returns the
 *                     cashier sent back are credited here too, by design).
 *   3. DELIVERED    - the POSTED delivery lines from this commissary (`dl_deliveries` +
 *                     `dl_delivery_items`), NOT `dispatched_qty`. This is the point: the dispatch
 *                     column is what is in doubt, so it must not be the thing being checked.
 *
 *   expected = opening + additionals - delivered - wastage
 *   recorded = the ledger's own closing balance for that item (the physical count when one exists)
 *   delta    = recorded - expected
 *
 * A delta is ledger drift: either the same load was booked twice, or a beginning was carried wrong,
 * or a count overwrote the chain. Zero on every line means the ledger is honest for that item.
 *
 * SAFE BY CONSTRUCTION
 * --------------------
 * SELECT-only. There is no --apply and no write path in this file: reconciliation is an act of
 * looking, and a repair is a separate, owner-approved action. Nothing is created, updated or
 * deleted, so it can be run on production at any time, including under load.
 *
 * MYSQL 5.7 COMPATIBILITY (live runs an older server than this dev box)
 * --------------------------------------------------------------------
 * No CTEs and no window functions: "latest row per product" is resolved in PHP from an ordered
 * result set, and "first row per product" from a MAX(ledger_date) derived table.
 *
 * USAGE
 *   php modules/daily-ledger/cli/reconcile-commissary-stock.php --tenant=207 --commissary=18
 *   php modules/daily-ledger/cli/reconcile-commissary-stock.php --tenant=207 --commissary=18 \
 *       --until=2026-11-20 --branch=9 --product=mamon --csv=/tmp/rizal-recon.csv
 *
 *   --tenant=<id>        tenant to read (required)
 *   --commissary=<id>    commissary branch id (required)
 *   --until=<date>       last day included, inclusive (default: today)
 *   --from=<date>        first day included (default: the first day this hub ever encoded)
 *   --branch=<id>        only count deliveries to this destination branch
 *   --product=<text>     only items whose name/SKU contains this text
 *   --limit=<n>          rows printed to the console (default 40, 0 = all)
 *   --csv=<path>         also write the full per-item table to a CSV
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'commissary:', 'until::', 'from::', 'branch::', 'product::', 'limit::', 'csv::']);

$tenantId = max(0, (int)($options['tenant'] ?? 0));
$commissaryId = max(0, (int)($options['commissary'] ?? 0));
if ($tenantId <= 0 || $commissaryId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/reconcile-commissary-stock.php --tenant=<id> --commissary=<id>"
        . " [--until=YYYY-MM-DD] [--from=YYYY-MM-DD] [--branch=<id>] [--product=<text>] [--limit=<n>] [--csv=<path>]\n");
    exit(2);
}

$isDate = static fn($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
$until = $isDate($options['until'] ?? null) ? (string)$options['until'] : date('Y-m-d');
$from = $isDate($options['from'] ?? null) ? (string)$options['from'] : null;
$branchFilter = max(0, (int)($options['branch'] ?? 0));
$productFilter = trim((string)($options['product'] ?? ''));
$printLimit = isset($options['limit']) ? max(0, (int)$options['limit']) : 40;
$csvPath = isset($options['csv']) ? (string)$options['csv'] : '';

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Commissary Stock Reconciler',
        'username' => 'daily-ledger-reconciler',
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

    $stmt = $db->prepare('SELECT name FROM dl_branches WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $commissaryId]);
    $branchName = (string)($stmt->fetchColumn() ?: ('#' . $commissaryId));

    // The first day this hub encoded anything, so "opening" means the same thing in every run.
    $stmt = $db->prepare('SELECT MIN(ledger_date) FROM dl_commissary_product_ledger WHERE commissary_branch_id = :c');
    $stmt->execute([':c' => $commissaryId]);
    $firstEver = (string)($stmt->fetchColumn() ?: '');
    $windowStart = $from ?? ($firstEver !== '' ? $firstEver : $until);

    $dateSql = 'AND l.ledger_date BETWEEN :d1 AND :d2';
    // The derived tables below have no `l` alias, so they need the same window unprefixed.
    $dateSqlPlain = 'AND ledger_date BETWEEN :d1 AND :d2';
    $dateBind = [':d1' => $windowStart, ':d2' => $until];

    // ── 1. OPENING: the beginning written on each item's FIRST row inside the window ──────────
    // MAX(ledger_date) finds that day; the shift/id ordering then picks the day's FIRST bucket,
    // which is the only bucket whose `beg_qty` is an opening position rather than a carried one.
    $openingSql = "SELECT l.product_id, l.ledger_date, l.shift, l.beg_qty
                     FROM dl_commissary_product_ledger l
                    WHERE l.commissary_branch_id = :c {$dateSql}
                    ORDER BY l.product_id, l.ledger_date ASC,
                             CASE l.shift WHEN 'AM' THEN 1 WHEN 'PM' THEN 2 ELSE 0 END ASC, l.id ASC";
    $stmt = $db->prepare($openingSql);
    $stmt->execute([':c' => $commissaryId] + $dateBind);
    $opening = [];
    $openingZeroOnly = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int)$row['product_id'];
        // "Starting stock on the first day of entries" is the first beginning this hub EVER
        // declared for the item — not necessarily its first row. A row whose beg is 0 is an
        // empty ledger, not a declared opening: on RIZAL-COMMIS the first day (2026-09-30) was
        // written with beg 0 while dispatch was already live, and TOASTED MAMON's real opening
        // (16,350) only appears on 2026-10-01. Taking row #1 would report that item as having
        // had no stock at all, which is the very arithmetic being reconciled.
        if (isset($opening[$pid])) {
            continue;
        }
        if ((int)$row['beg_qty'] > 0) {
            $opening[$pid] = ['qty' => (int)$row['beg_qty'], 'date' => (string)$row['ledger_date']];
            continue;
        }
        $openingZeroOnly[$pid] = true; // remember it had rows; it just never declared stock
    }
    foreach ($openingZeroOnly as $pid => $_) {
        if (!isset($opening[$pid])) {
            $opening[$pid] = ['qty' => 0, 'date' => ''];
        }
    }

    // ── 2. ADDITIONALS + WASTAGE, from the ledger's own flow columns ─────────────────────────
    $stmt = $db->prepare("SELECT product_id, SUM(produced_qty) AS addtl, SUM(wastage_qty) AS waste
                            FROM dl_commissary_product_ledger l
                           WHERE l.commissary_branch_id = :c {$dateSql}
                           GROUP BY product_id");
    $stmt->execute([':c' => $commissaryId] + $dateBind);
    $addtl = [];
    $waste = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $addtl[(int)$row['product_id']] = (int)$row['addtl'];
        $waste[(int)$row['product_id']] = (int)$row['waste'];
    }

    // ── 3. DELIVERED: posted delivery lines, an INDEPENDENT record of what left the hub ──────
    // Origin is resolved (`resolved_origin_id` wins) exactly as the ledger effect resolves it, and
    // returns are excluded by construction: a pullout return has a BRANCH origin, not this hub.
    $deliveredSql = "SELECT i.product_id, SUM(i.quantity) AS qty, COUNT(DISTINCT d.id) AS dcs
                       FROM dl_deliveries d
                       INNER JOIN dl_delivery_items i ON i.delivery_id = d.id
                      WHERE d.status = 'posted'
                        AND COALESCE(d.resolved_origin_id, d.origin_id) = :c
                        AND d.delivery_date BETWEEN :d1 AND :d2";
    $deliverBind = [':c' => $commissaryId] + $dateBind;
    if ($branchFilter > 0) {
        $deliveredSql .= ' AND d.destination_id = :dest';
        $deliverBind[':dest'] = $branchFilter;
    }
    $deliveredSql .= ' GROUP BY i.product_id';
    $stmt = $db->prepare($deliveredSql);
    $stmt->execute($deliverBind);
    $delivered = [];
    $deliveryCount = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $delivered[(int)$row['product_id']] = (int)$row['qty'];
        $deliveryCount[(int)$row['product_id']] = (int)$row['dcs'];
    }
    $countSql = "SELECT COUNT(DISTINCT d.id)
                   FROM dl_deliveries d
                  WHERE d.status = 'posted'
                    AND COALESCE(d.resolved_origin_id, d.origin_id) = :c
                    AND d.delivery_date BETWEEN :d1 AND :d2"
        . ($branchFilter > 0 ? ' AND d.destination_id = :dest' : '');
    $stmt = $db->prepare($countSql);
    $stmt->execute($deliverBind);
    $deliveryRows = (int)$stmt->fetchColumn();

    // ── 4. RECORDED: the ledger's own closing balance (count wins when one exists) ───────────
    $stmt = $db->prepare("SELECT l.product_id, l.ledger_date, l.shift, l.actual_end_qty,
                                 (l.beg_qty + l.produced_qty - l.dispatched_qty - l.wastage_qty) AS book,
                                 l.dispatched_qty
                            FROM dl_commissary_product_ledger l
                           WHERE l.commissary_branch_id = :c {$dateSql}
                           ORDER BY l.product_id, l.ledger_date DESC,
                                    CASE l.shift WHEN 'PM' THEN 2 WHEN 'AM' THEN 1 ELSE 0 END DESC, l.id DESC");
    $stmt->execute([':c' => $commissaryId] + $dateBind);
    $recorded = [];
    $ledgerDispatch = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int)$row['product_id'];
        // Sum the whole window, not one bucket: `dispatched_qty` is per shift, and comparing a
        // single bucket against all deliveries would look like a 10x discrepancy that is not real.
        $ledgerDispatch[$pid] = ($ledgerDispatch[$pid] ?? 0) + (int)$row['dispatched_qty'];
        if (isset($recorded[$pid])) {
            continue; // only the latest bucket decides the closing balance
        }
        $recorded[$pid] = $row['actual_end_qty'] !== null ? (int)$row['actual_end_qty'] : (int)$row['book'];
    }

    // ── 5. Item names, and the shape of the result ──────────────────────────────────────────
    $productIds = array_values(array_unique(array_merge(
        array_keys($opening),
        array_keys($addtl),
        array_keys($delivered),
        array_keys($recorded)
    )));
    $names = [];
    $skus = [];
    if ($productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        foreach ($db->query("SELECT id, name, sku FROM dl_products WHERE id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $names[(int)$p['id']] = (string)$p['name'];
            $skus[(int)$p['id']] = (string)($p['sku'] ?? '');
        }
    }

    $rows = [];
    $tot = ['opening' => 0, 'addtl' => 0, 'delivered' => 0, 'waste' => 0, 'expected' => 0, 'recorded' => 0, 'delta' => 0];
    foreach ($productIds as $pid) {
        $name = $names[$pid] ?? ('#' . $pid);
        if ($productFilter !== '' && stripos($name, $productFilter) === false && stripos($skus[$pid] ?? '', $productFilter) === false) {
            continue;
        }
        // Opening is deliberately counted ONLY when this hub actually recorded one. Counting a
        // zero-beginning as if it were a measured opening would hide the very case being hunted.
        $openQty = (int)($opening[$pid]['qty'] ?? 0);
        $add = (int)($addtl[$pid] ?? 0);
        $out = (int)($delivered[$pid] ?? 0);
        $wst = (int)($waste[$pid] ?? 0);
        $expected = $openQty + $add - $out - $wst;
        $rec = (int)($recorded[$pid] ?? 0);
        // `dispatched_qty` is shown beside the delivery figure on purpose: when they disagree, the
        // ledger double-booked the load and the DELIVERY figure is the one to trust.
        $ledgerOut = (int)($ledgerDispatch[$pid] ?? 0);
        $flags = [];
        if ($add === 0 && $out > 0) {
            $flags[] = 'no-production-ever';
        }
        if ($out > 0 && $ledgerOut > $out) {
            $flags[] = 'ledger-out-exceeds-drs';
        }
        if ($openQty === 0 && $out > 0) {
            $flags[] = 'opening-zero';
        }
        $row = [
            'product_id' => $pid,
            'item' => $name,
            'opening' => $openQty,
            'opening_date' => (string)($opening[$pid]['date'] ?? ''),
            'additionals' => $add,
            'delivered_drs' => $out,
            'ledger_dispatched' => $ledgerOut,
            'wastage' => $wst,
            'expected' => $expected,
            'recorded' => $rec,
            'delta' => $rec - $expected,
            'dr_count' => (int)($deliveryCount[$pid] ?? 0),
            'flags' => implode(' ', $flags),
        ];
        $rows[] = $row;
        foreach (['opening', 'addtl', 'delivered', 'waste', 'expected', 'recorded', 'delta'] as $k) {
            $map = ['addtl' => 'additionals', 'delivered' => 'delivered_drs', 'waste' => 'wastage'];
            $tot[$k] += $row[$map[$k] ?? $k];
        }
    }

    usort($rows, static fn(array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));

    // ── Report ──────────────────────────────────────────────────────────────────────────────
    $line = str_repeat('-', 118);
    echo "\nCOMMISSARY STOCK RECONCILIATION (read-only)\n";
    echo "  hub      : {$branchName} (#{$commissaryId})\n";
    echo "  window   : {$windowStart} .. {$until}" . ($branchFilter > 0 ? "   delivery branch filter: #{$branchFilter}" : '   (all destinations)') . "\n";
    echo "  source 1 : opening = the first NON-ZERO beg_qty this hub declared for the item (0 = never declared)\n";
    echo "  source 2 : additionals = SUM(produced_qty)   (returns credited by design)\n";
    echo "  source 3 : delivered = POSTED delivery lines (independent of dispatched_qty)\n";
    echo "  recorded : the ledger's own closing balance (physical count wins when set)\n";
    echo "  expected = opening + additionals - delivered - wastage ;  delta = recorded - expected\n";
    echo "  posted deliveries in window: {$deliveryRows}\n\n";

    printf("%28s %9s %10s %10s %10s %9s %9s %10s\n",
        'TOTAL', 'opening', 'additionals', 'delivered', 'wastage', 'expected', 'recorded', 'delta');
    echo $line . "\n";
    printf("%28s %9d %10d %10d %10d %9d %9d %10d\n", 'ALL ITEMS',
        $tot['opening'], $tot['addtl'], $tot['delivered'], $tot['waste'], $tot['expected'], $tot['recorded'], $tot['delta']);
    echo $line . "\n\n";

    $printed = 0;
    printf("%-34s %8s %9s %9s %9s %9s %9s %8s %5s %s\n",
        'ITEM', 'opening', 'addition', 'delivered', 'ledgerOut', 'expected', 'recorded', 'delta', 'DRs', 'flags');
    echo $line . "\n";
    foreach ($rows as $row) {
        if ($printLimit > 0 && $printed >= $printLimit) {
            break;
        }
        printf("%-34s %8d %9d %9d %9d %9d %9d %8d %5d %s\n",
            mb_substr($row['item'], 0, 34), $row['opening'], $row['additionals'], $row['delivered_drs'],
            $row['ledger_dispatched'], $row['expected'], $row['recorded'], $row['delta'], $row['dr_count'], $row['flags']);
        $printed++;
    }
    echo $line . "\n";
    echo '  items: ' . count($rows) . '   shown: ' . $printed
        . '   (use --limit=0 for all, --product= / --branch= / --until= to narrow)'
        . ($csvPath !== '' ? "   csv: {$csvPath}" : '') . "\n";
    echo "  A non-zero delta means the ledger and its own delivery records disagree for that item.\n";
    echo "  'ledger-out-exceeds-drs' = dispatched_qty carries more than the posted DRs: a load was booked more than once.\n\n";

    if ($csvPath !== '') {
        $fh = fopen($csvPath, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot write CSV to {$csvPath}");
        }
        fputcsv($fh, ['product_id', 'item', 'opening', 'opening_date', 'additionals', 'delivered_drs',
            'ledger_dispatched', 'wastage', 'expected', 'recorded', 'delta', 'dr_count', 'flags']);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
    }

    modulePopContext();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
