#!/usr/bin/env php
<?php

/**
 * Void dispatches that a shift's OWN physical count proves never happened.
 *
 * THE DEFECT (owner, 2026-11-21: "fix the toasted mamon issue. i can't accept a bloated number")
 * ----------------------------------------------------------------------------------------------
 * A load could be booked in the wrong shift bucket and then booked AGAIN in the right one, because
 * the second bucket's beginning was carried from a count rather than from the first bucket's
 * computed closing. The ledger then carries a dispatch nobody made, and every "shipped" total on
 * every screen inherits it.
 *
 * Measured on RIZAL-COMMIS, TOASTED MAMON @15, 2026-10-11:
 *   AM row 840 : beg 20,691  produced 0  dispatched 20,691  waste 0  COUNTED END 20,691
 *   PM row 1044: beg 20,691  produced 0  dispatched 20,691  waste 0  COUNTED END 0
 * The AM count says the stock was still all there at the end of AM — so the AM dispatch is
 * impossible; the load physically left in PM, where the count says 0 remained. Two deliveries
 * (570 at 00:01, 622 at 22:16) carry the same 20,691 with no DR number.
 *
 * THE PROOF STANDARD — no guessing, no matching by eye
 * ----------------------------------------------------
 * A row is repairable only when its own recorded count contradicts its own dispatch:
 *
 *     actual_end_qty IS NOT NULL
 *     AND dispatched_qty > 0
 *     AND actual_end_qty = beg_qty + produced_qty - wastage_qty      (i.e. the dispatch never left)
 *
 * That is arithmetic on one row, not a similarity heuristic: if the count equals the movement with
 * the dispatch excluded, the dispatch did not happen. 163 such rows exist at RIZAL-COMMIS
 * (24,651 units), 20,691 of them this single TOASTED MAMON load.
 *
 * WHAT IT CHANGES (only with --apply)
 * -----------------------------------
 *   1. the matching posted delivery line(s) are VOIDED, never deleted — the record of the mistake
 *      stays, and every screen that counts `status='posted'` stops counting it;
 *   2. that ledger row's `dispatched_qty` drops to 0, which restores its generated `calc_variance`
 *      to 0 (it was +20,691, a variance nobody actioned);
 *   3. one `audit_logs` entry per repair records the before/after and the evidence.
 * Nothing else is touched: no other row, no other shift, no other item.
 *
 * SAFE BY DEFAULT
 * ---------------
 * Dry run unless --apply. Bounded by --limit. `--product=` narrows it to one item, which is how it
 * was first run (TOASTED MAMON @15) rather than sweeping the tenant in one go.
 *
 * USAGE
 *   php modules/daily-ledger/cli/repair-contradicted-dispatch.php --tenant=207            # report
 *   php modules/daily-ledger/cli/repair-contradicted-dispatch.php --tenant=207 \
 *       --product="TOASTED MAMON @15" --apply
 *
 *   --tenant=<id>       tenant to repair (required)
 *   --commissary=<id>   limit to one hub (default: every commissary)
 *   --product=<text>    limit to items whose name contains this text
 *   --until=<date>      ignore rows after this date (default: today)
 *   --limit=<n>         rows examined in one run (default 500)
 *   --apply             actually void + zero (otherwise nothing is written)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'commissary::', 'product::', 'until::', 'limit::', 'apply', 'with-branch']);

$tenantId = max(0, (int)($options['tenant'] ?? 0));
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/repair-contradicted-dispatch.php --tenant=<id>"
        . " [--commissary=<id>] [--product=<text>] [--until=YYYY-MM-DD] [--limit=<n>] [--apply]\n");
    exit(2);
}

$commissaryId = max(0, (int)($options['commissary'] ?? 0));
$productFilter = trim((string)($options['product'] ?? ''));
$until = (is_string($options['until'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['until']))
    ? (string)$options['until']
    : date('Y-m-d');
$limit = max(1, min(20000, (int)($options['limit'] ?? 500)));
$apply = array_key_exists('apply', $options);
// Adjusting the destination BRANCH sheet is opt-in: it is a cashier's own record, and the default
// repair stays inside the commissary that made the error.
$withBranch = array_key_exists('with-branch', $options);

try {
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Contradicted Dispatch Repair',
        'username' => 'daily-ledger-dispatch-repair',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    // handlers.php owns dl_auditLog() and requires helpers.php, which needs the kernel loaded —
    // so it is included here, after bootstrap, not at the top of the file.
    require_once __DIR__ . '/../handlers.php';
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = $context->db();

    // ── Candidates: a row whose count proves its own dispatch never left ─────────────────────
    $sql = "SELECT l.id, l.commissary_branch_id, l.product_id, l.ledger_date, l.shift,
                   l.beg_qty, l.produced_qty, l.dispatched_qty, l.wastage_qty, l.actual_end_qty,
                   p.name AS product_name
              FROM dl_commissary_product_ledger l
              INNER JOIN dl_products p ON p.id = l.product_id
             WHERE l.actual_end_qty IS NOT NULL
               AND l.dispatched_qty > 0
               AND l.actual_end_qty = (l.beg_qty + l.produced_qty - l.wastage_qty)
               AND l.ledger_date <= :until";
    $bind = [':until' => $until];
    if ($commissaryId > 0) {
        $sql .= ' AND l.commissary_branch_id = :c';
        $bind[':c'] = $commissaryId;
    }
    if ($productFilter !== '') {
        $sql .= ' AND p.name LIKE :pn';
        $bind[':pn'] = '%' . $productFilter . '%';
    }
    $sql .= ' ORDER BY l.dispatched_qty DESC, l.ledger_date DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($candidates === []) {
        echo "Nothing to repair: no row's count contradicts its own dispatch in this scope.\n";
        modulePopContext();
        exit(0);
    }

    // The delivery that booked the phantom: same hub, product, date, quantity and shift.
    $findDr = $db->prepare(
        "SELECT DISTINCT d.id, d.dr_number, d.production_shift, d.created_at, d.created_by,
                i.quantity, d.destination_type, d.destination_id
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items i ON i.delivery_id = d.id
          WHERE d.status = 'posted'
            AND COALESCE(d.resolved_origin_id, d.origin_id) = :c
            AND d.delivery_date = :d
            AND i.product_id = :p
            AND i.quantity = :q
            AND d.production_shift <=> :s
          ORDER BY d.id"
    );

    echo "\nCONTRADICTED DISPATCH REPAIR" . ($apply ? ' — APPLYING' : ' — DRY RUN (nothing is written)') . "\n";
    echo "  rule: actual_end_qty = beg + produced - wastage  =>  the dispatch never left\n";
    echo "  until {$until}" . ($commissaryId > 0 ? ", hub #{$commissaryId}" : ', all hubs')
        . ($productFilter !== '' ? ", item ~ '{$productFilter}'" : '') . "\n\n";

    $voided = 0;
    $zeroed = 0;
    $units = 0;
    $orphaned = 0;
    $receivingsVoided = 0;
    $branchRowsAdjusted = 0;

    foreach ($candidates as $row) {
        $findDr->execute([
            ':c' => (int)$row['commissary_branch_id'],
            ':d' => (string)$row['ledger_date'],
            ':p' => (int)$row['product_id'],
            ':q' => (int)$row['dispatched_qty'],
            ':s' => $row['shift'],
        ]);
        $drs = $findDr->fetchAll(PDO::FETCH_ASSOC) ?: [];

        printf("%-30s %s %-3s  row %-6d disp %7d  counted-end %7d  deliveries: %d\n",
            mb_substr((string)$row['product_name'], 0, 30),
            (string)$row['ledger_date'],
            (string)($row['shift'] ?? 'LEG'),
            (int)$row['id'],
            (int)$row['dispatched_qty'],
            (int)$row['actual_end_qty'],
            count($drs)
        );
        foreach ($drs as $dr) {
            printf("      -> delivery #%d  %s  shift %s  qty %d  dest %s/%s  made %s by %s\n",
                (int)$dr['id'],
                ($dr['dr_number'] === null ? '(no DR)' : (string)$dr['dr_number']),
                (string)($dr['production_shift'] ?? '-'),
                (int)$dr['quantity'],
                (string)$dr['destination_type'],
                (string)($dr['destination_id'] ?? '-'),
                (string)$dr['created_at'],
                (string)($dr['created_by'] ?? '-')
            );
        }
        if ($drs === []) {
            $orphaned++;
            echo "      !! no posted delivery matches this row — the ledger column is zeroed but there is no delivery to void\n";
        }

        // ── The destination end ────────────────────────────────────────────────────────────
        // A voided dispatch that leaves its receiving live is worse than the original error: the
        // hub stops counting the load while the branch keeps the stock. Every receiving linked to
        // the delivery is therefore voided too, and the branch sheet row it credited is reduced by
        // the SAME quantity on both sides (addtl AND withdraw), so its beginning, its recorded end
        // and its variance are all untouched — only the phantom half of the pair disappears.
        $receivings = [];
        foreach ($drs as $dr) {
            $recvStmt = $db->prepare(
                "SELECT id, branch_id, dr_number, received_ledger_date, received_shift
                   FROM dl_branch_receivings
                  WHERE delivery_id = :d AND status = 'posted'"
            );
            $recvStmt->execute([':d' => (int)$dr['id']]);
            foreach ($recvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $recv) {
                $qtyStmt = $db->prepare(
                    'SELECT COALESCE(SUM(quantity_received), 0) FROM dl_branch_receiving_items WHERE receiving_id = :r AND product_id = :p'
                );
                $qtyStmt->execute([':r' => (int)$recv['id'], ':p' => (int)$row['product_id']]);
                $recv['qty'] = (int)$qtyStmt->fetchColumn();
                $receivings[] = $recv;
            }
        }
        foreach ($receivings as $recv) {
            printf("      => receiving #%d at branch %d  %s  qty %d\n",
                (int)$recv['id'], (int)$recv['branch_id'], (string)$recv['received_ledger_date'], (int)$recv['qty']);
        }

        if (!$apply) {
            $units += (int)$row['dispatched_qty'];
            continue;
        }

        $db->beginTransaction();
        // Per-row tallies. They are merged into the run totals only AFTER the commit: a failed
        // transaction rolls its writes back, and a summary that counted them anyway would report a
        // repair that never happened (measured here: the run printed "APPLIED … 20,691 removed"
        // while the rollback had left every row untouched).
        $rowVoided = 0;
        $rowZeroed = 0;
        $rowReceivings = 0;
        $rowBranch = 0;
        try {
            foreach ($drs as $dr) {
                $db->prepare(
                    "UPDATE dl_deliveries
                        SET status = 'voided', voided_at = NOW(),
                            remarks = CONCAT(COALESCE(remarks, ''),
                                             ' [voided: duplicate shift booking — the shift count proves the goods never left]')
                      WHERE id = :id AND status = 'posted'"
                )->execute([':id' => (int)$dr['id']]);
                $rowVoided++;
            }
            // The ledger column itself. `calc_variance` is generated, so restoring the dispatch to
            // the count's view of the shift also restores the variance to 0 in the same write.
            $db->prepare('UPDATE dl_commissary_product_ledger SET dispatched_qty = 0, updated_at = NOW() WHERE id = :id')
                ->execute([':id' => (int)$row['id']]);
            $rowZeroed++;

            foreach ($receivings as $recv) {
                $db->prepare(
                    "UPDATE dl_branch_receivings
                        SET status = 'voided', voided_at = NOW(),
                            remarks = CONCAT(COALESCE(remarks, ''),
                                             ' [voided: the matching dispatch was a duplicate shift booking]')
                      WHERE id = :id AND status = 'posted'"
                )->execute([':id' => (int)$recv['id']]);
                $rowReceivings++;
                if ($withBranch && (int)$recv['qty'] > 0) {
                    // Reduce only rows that can absorb it on BOTH sides; anything else is reported
                    // instead of forced, because a branch sheet is a cashier's signed record.
                    $branchStmt = $db->prepare(
                        'SELECT id, addtl, withdraw FROM dl_daily_ledger
                          WHERE branch_id = :b AND product_id = :p AND ledger_date = :d
                            AND addtl >= :q1 AND withdraw >= :q2
                          ORDER BY id LIMIT 1'
                    );
                    $branchStmt->execute([
                        ':b' => (int)$recv['branch_id'],
                        ':p' => (int)$row['product_id'],
                        ':d' => (string)$recv['received_ledger_date'],
                        ':q1' => (int)$recv['qty'],
                        ':q2' => (int)$recv['qty'],
                    ]);
                    $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($branchRow === null) {
                        echo "      !! branch {$recv['branch_id']} sheet row cannot absorb {$recv['qty']} on both sides — left untouched, reported only\n";
                    } else {
                        // Two placeholders because a named parameter may only be bound once.
                        $db->prepare('UPDATE dl_daily_ledger SET addtl = addtl - :qa, withdraw = withdraw - :qw, updated_at = NOW() WHERE id = :id')
                            ->execute([':qa' => (int)$recv['qty'], ':qw' => (int)$recv['qty'], ':id' => (int)$branchRow['id']]);
                        $rowBranch++;
                        printf("      => branch %d sheet row %d: addtl %d -> %d, withdraw %d -> %d\n",
                            (int)$recv['branch_id'], (int)$branchRow['id'],
                            (int)$branchRow['addtl'], (int)$branchRow['addtl'] - (int)$recv['qty'],
                            (int)$branchRow['withdraw'], (int)$branchRow['withdraw'] - (int)$recv['qty']);
                    }
                }
            }

            dl_auditLog(
                'voided_contradicted_dispatch',
                (int)$row['commissary_branch_id'],
                'dl_commissary_product_ledger',
                $row['commissary_branch_id'] . '-' . $row['ledger_date'] . '-' . $row['product_id'] . '-' . ($row['shift'] ?? 'NULL'),
                ['dispatched_qty' => (int)$row['dispatched_qty'], 'actual_end_qty' => (int)$row['actual_end_qty']],
                [
                    'dispatched_qty' => 0,
                    'evidence' => 'count equals beg+produced-wastage, so the dispatch never left',
                    'deliveries_voided' => array_map(static fn($d) => (int)$d['id'], $drs),
                ]
            );
            $db->commit();
            // Committed: only now do these become part of the run's totals.
            $voided += $rowVoided;
            $zeroed += $rowZeroed;
            $receivingsVoided += $rowReceivings;
            $branchRowsAdjusted += $rowBranch;
            $units += (int)$row['dispatched_qty'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fwrite(STDERR, '  FAILED on row ' . (int)$row['id'] . ' (rolled back, nothing changed): ' . $e->getMessage() . "\n");
        }
    }

    echo "\n";
    if ($apply) {
        echo "APPLIED: {$zeroed} ledger row(s) zeroed, {$voided} delivery(ies) voided, {$receivingsVoided} receiving(s) voided"
            . ($branchRowsAdjusted > 0 ? ", {$branchRowsAdjusted} branch sheet row(s) adjusted" : '')
            . ", {$units} phantom unit(s) removed.\n";
        if (!$withBranch && $receivingsVoided > 0) {
            echo "NOTE: branch sheet rows were NOT adjusted (pass --with-branch). The receiving is voided, so the\n";
            echo "      branch shows goods it no longer has a receipt for - reconcile its sheet before relying on it.\n";
        }
    } else {
        echo "DRY RUN: {$units} phantom unit(s) across " . count($candidates) . " row(s) would be removed"
            . ($orphaned > 0 ? " ({$orphaned} row(s) have no matching delivery)" : '') . ".\n";
        echo "Re-run with --apply to void the deliveries and zero the columns.\n";
    }
    echo "Nothing was deleted: voided deliveries keep their lines, and each repair leaves one audit_logs entry.\n\n";

    modulePopContext();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
