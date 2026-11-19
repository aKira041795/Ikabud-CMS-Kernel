<?php

declare(strict_types=1);

/**
 * Reconcile missing commissary opening positions from the origin of each ledger.
 *
 * Run: php tools/reconcile-opening-positions.php [--apply]
 * Default is a read-only dry run against tenant baronledger.test.
 */

$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(2);
}

$tenantHost = 'baronledger.test';
ob_start();
require_once __DIR__ . '/../tests/harness/TestHarness.php';
$harness = new TestHarness('reconcile-opening-positions', TestHarness::MODE_INTEGRATION, $tenantHost);
ob_end_clean();
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$moduleContext = modulePushContext('daily-ledger');
if (!$moduleContext) {
    throw new RuntimeException('Daily Ledger module context is unavailable.');
}
$db = $moduleContext->db();

/** @return array<int,array<string,mixed>> */
function rop_query($db, string $sql, array $bind = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{count:int,units:int,matrix:array<int,array<int,array<string,mixed>>>} */
function rop_negative_snapshot($db): array
{
    $hubRows = rop_query($db, 'SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id');
    $hubIds = array_map(static fn(array $row): int => (int)$row['id'], $hubRows);
    $matrix = dl_commissaryOnHandByProduct($db, $hubIds, null);
    $count = 0;
    $units = 0;
    foreach ($matrix as $products) {
        foreach ($products as $state) {
            $onHand = (int)$state['on_hand'];
            if ($onHand < 0) {
                $count++;
                $units += $onHand;
            }
        }
    }
    return ['count' => $count, 'units' => $units, 'matrix' => $matrix];
}

function rop_cell($value): string
{
    return str_replace('|', '\\|', (string)$value);
}

$beforeSnapshot = rop_negative_snapshot($db);
$ledgerRows = rop_query(
    $db,
    'SELECT l.id, l.commissary_branch_id, b.name AS hub_name, l.product_id, p.name AS product_name,
            l.ledger_date, l.shift, l.beg_qty, l.produced_qty, l.dispatched_qty,
            l.wastage_qty, l.actual_end_qty
       FROM dl_commissary_product_ledger l
       INNER JOIN dl_branches b ON b.id = l.commissary_branch_id
       INNER JOIN dl_products p ON p.id = l.product_id
      ORDER BY l.commissary_branch_id, l.product_id, l.ledger_date,
               CASE WHEN l.shift IS NULL THEN 0 WHEN l.shift = "AM" THEN 1 WHEN l.shift = "PM" THEN 2 ELSE 3 END,
               l.id'
);

$groups = [];
foreach ($ledgerRows as $row) {
    $key = (int)$row['commissary_branch_id'] . ':' . (int)$row['product_id'];
    $groups[$key][] = $row;
}

$results = [];
$correctable = [];
foreach ($groups as $key => $rows) {
    $running = 0;
    $minimum = 0;
    foreach ($rows as $row) {
        $running += (int)$row['produced_qty'] - (int)$row['dispatched_qty'] - (int)$row['wastage_qty'];
        $minimum = min($minimum, $running);
    }
    if ($minimum >= 0) {
        continue;
    }

    $first = $rows[0];
    $last = $rows[count($rows) - 1];
    $hubId = (int)$first['commissary_branch_id'];
    $productId = (int)$first['product_id'];
    $firstDate = (string)$first['ledger_date'];
    $latestDate = (string)$last['ledger_date'];
    $deficit = -$minimum;

    // If several shifts were counted on the latest date, the last shift in ledger order is authoritative.
    $physicalTarget = null;
    foreach ($rows as $row) {
        if ((string)$row['ledger_date'] === $latestDate && $row['actual_end_qty'] !== null) {
            $physicalTarget = (int)$row['actual_end_qty'];
        }
    }
    $opening = $physicalTarget !== null ? $physicalTarget - $running : $deficit;
    $currentState = $beforeSnapshot['matrix'][$hubId][$productId] ?? ['on_hand' => 0];
    $currentOnHand = (int)$currentState['on_hand'];
    $entityId = "{$hubId}-{$productId}-{$firstDate}";

    $audit = rop_query(
        $db,
        'SELECT new_data FROM audit_logs
          WHERE module = "daily-ledger" AND action = "opening_position_reconciled"
            AND branch_id = :hub AND entity_type = "dl_commissary_product_ledger"
            AND entity_id LIKE :pair
          ORDER BY id DESC LIMIT 1',
        [':hub' => $hubId, ':pair' => $hubId . '-' . $productId . '-%']
    );
    if ($audit !== []) {
        $prior = json_decode((string)($audit[0]['new_data'] ?? ''), true);
        $priorOpening = is_array($prior) && isset($prior['opening_added']) ? (int)$prior['opening_added'] : $opening;
        $results[$key] = [
            'hub' => (string)$first['hub_name'], 'product' => (string)$first['product_name'],
            'current' => $currentOnHand, 'deficit' => $deficit, 'opening' => $priorOpening,
            'resulting' => $currentOnHand, 'status' => 'SKIPPED-ALREADY', 'physical' => $physicalTarget,
        ];
        continue;
    }

    if ($opening < $deficit) {
        $results[$key] = [
            'hub' => (string)$first['hub_name'], 'product' => (string)$first['product_name'],
            'current' => $currentOnHand, 'deficit' => $deficit, 'opening' => $opening,
            'resulting' => $currentOnHand, 'status' => 'UNRESOLVED', 'physical' => $physicalTarget,
            'why' => "physical target {$physicalTarget} - net {$running} = opening {$opening}, below deficit {$deficit}",
        ];
        continue;
    }

    $netAfter = $running + $opening;
    $residualNegativeRows = 0;
    foreach ($rows as $row) {
        $resultingBookBalance = (int)$row['beg_qty'] + $opening
            + (int)$row['produced_qty'] - (int)$row['dispatched_qty'] - (int)$row['wastage_qty'];
        if ($resultingBookBalance < 0) {
            $residualNegativeRows++;
        }
    }
    $reason = "opening stock was never recorded; dispatches from {$firstDate} were applied against an empty ledger";
    // A physical count only controls displayed on-hand when it belongs to the final ordered row.
    $resultingOnHand = $last['actual_end_qty'] !== null ? (int)$last['actual_end_qty'] : $currentOnHand + $opening;
    $results[$key] = [
        'hub' => (string)$first['hub_name'], 'product' => (string)$first['product_name'],
        'current' => $currentOnHand, 'deficit' => $deficit, 'opening' => $opening,
        'resulting' => $resultingOnHand, 'status' => 'RECONCILED', 'physical' => $physicalTarget,
    ];
    $correctable[$key] = [
        'rows' => $rows, 'hub_id' => $hubId, 'product_id' => $productId, 'first_date' => $firstDate,
        'entity_id' => $entityId, 'opening' => $opening, 'deficit' => $deficit,
        'physical' => $physicalTarget, 'net_before' => $running, 'net_after' => $netAfter,
        'residual' => $residualNegativeRows, 'reason' => $reason,
    ];
}

if ($apply) {
    $update = $db->prepare('UPDATE dl_commissary_product_ledger SET beg_qty = beg_qty + :opening WHERE id = :id');
    foreach ($correctable as $key => $item) {
        $db->beginTransaction();
        try {
            $lock = $db->prepare(
                'SELECT id FROM dl_commissary_product_ledger
                  WHERE commissary_branch_id = :hub AND product_id = :product
                  ORDER BY ledger_date,
                           CASE WHEN shift IS NULL THEN 0 WHEN shift = "AM" THEN 1 WHEN shift = "PM" THEN 2 ELSE 3 END,
                           id FOR UPDATE'
            );
            $lock->execute([':hub' => $item['hub_id'], ':product' => $item['product_id']]);
            $lockedIds = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $expectedIds = array_map(static fn(array $row): int => (int)$row['id'], $item['rows']);
            if ($lockedIds !== $expectedIds) {
                throw new RuntimeException('Ledger rows changed after planning; rerun the tool.');
            }

            $duplicate = $db->prepare(
                'SELECT 1 FROM audit_logs
                  WHERE module = "daily-ledger" AND action = "opening_position_reconciled"
                    AND branch_id = :hub AND entity_type = "dl_commissary_product_ledger"
                    AND entity_id LIKE :pair LIMIT 1 FOR UPDATE'
            );
            $duplicate->execute([
                ':hub' => $item['hub_id'],
                ':pair' => $item['hub_id'] . '-' . $item['product_id'] . '-%',
            ]);
            if ($duplicate->fetchColumn()) {
                $db->rollBack();
                $results[$key]['status'] = 'SKIPPED-ALREADY';
                $results[$key]['resulting'] = $results[$key]['current'];
                continue;
            }

            $auditBefore = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
            $auditData = [
                'opening_added' => $item['opening'],
                'deficit' => $item['deficit'],
                'physical_target' => $item['physical'],
                'net_before' => $item['net_before'],
                'net_after' => $item['net_after'],
                'residual_negative_rows' => $item['residual'],
                'reason' => $item['reason'],
            ];
            // This audit is intentionally emitted and verified before the first stock write.
            dl_auditLog(
                'opening_position_reconciled',
                $item['hub_id'],
                'dl_commissary_product_ledger',
                $item['entity_id'],
                null,
                $auditData,
                $item['reason']
            );
            $auditCheck = $db->prepare(
                'SELECT id FROM audit_logs
                  WHERE id > :before AND module = "daily-ledger" AND action = "opening_position_reconciled"
                    AND branch_id = :hub AND entity_type = "dl_commissary_product_ledger" AND entity_id = :entity
                  ORDER BY id DESC LIMIT 1'
            );
            $auditCheck->execute([
                ':before' => $auditBefore, ':hub' => $item['hub_id'], ':entity' => $item['entity_id'],
            ]);
            if (!$auditCheck->fetchColumn()) {
                throw new RuntimeException('Required audit row was not written; no ledger row was changed.');
            }

            // There is no safe historical-chain builder in handlers.php: its carry helper only fills
            // current zero beginnings and enforces open-day/operator rules. The prescribed fallback is
            // therefore used: shift every stored beginning by the same injected opening, in ledger order.
            foreach ($lockedIds as $id) {
                $update->execute([':opening' => $item['opening'], ':id' => $id]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException("Beginning update failed for ledger row {$id}.");
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fwrite(STDERR, "FAILED hub {$item['hub_id']} product {$item['product_id']}: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

$afterSnapshot = $apply ? rop_negative_snapshot($db) : $beforeSnapshot;
if (!$apply) {
    // Predict the same latest-row result the updates would create. A latest physical count remains truth.
    foreach ($results as $key => $result) {
        if ($result['status'] !== 'RECONCILED') {
            continue;
        }
        [$hubId, $productId] = array_map('intval', explode(':', $key));
        $old = (int)($afterSnapshot['matrix'][$hubId][$productId]['on_hand'] ?? 0);
        $new = (int)$result['resulting'];
        if ($old < 0) {
            $afterSnapshot['count']--;
            $afterSnapshot['units'] -= $old;
        }
        if ($new < 0) {
            $afterSnapshot['count']++;
            $afterSnapshot['units'] += $new;
        }
    }
}

ksort($results);
echo ($apply ? "APPLY" : "DRY RUN") . " — tenant {$tenantHost}\n";
echo "Write strategy: audit first, then add the opening to beg_qty on every existing row; no dispatch, production, delivery, date, shift, or generated remaining_qty is edited.\n\n";
echo "| hub | product name | current on-hand | deficit | opening added | resulting on-hand | status |\n";
echo "|---|---|---:|---:|---:|---:|---|\n";
foreach ($results as $result) {
    echo sprintf(
        "| %s | %s | %s | %s | %s | %s | %s |\n",
        rop_cell($result['hub']), rop_cell($result['product']), number_format((int)$result['current']),
        number_format((int)$result['deficit']), number_format((int)$result['opening']),
        number_format((int)$result['resulting']), $result['status']
    );
    if (isset($result['why'])) {
        echo '  UNRESOLVED: ' . $result['hub'] . ' / ' . $result['product'] . ' — ' . $result['why'] . "\n";
    }
}

$counts = ['RECONCILED' => 0, 'UNRESOLVED' => 0, 'SKIPPED-ALREADY' => 0];
foreach ($results as $result) {
    $counts[$result['status']]++;
}
$arithmeticMismatches = [];
foreach ($correctable as $key => $item) {
    if ((int)$item['residual'] > 0) {
        $arithmeticMismatches[] = $results[$key]['hub'] . ' / ' . $results[$key]['product']
            . ': ' . $item['residual'] . ' stored row(s) remain book-negative after adding the computed opening';
    }
    if ($item['physical'] !== null && (int)$item['net_after'] !== (int)$item['physical']) {
        $arithmeticMismatches[] = $results[$key]['hub'] . ' / ' . $results[$key]['product']
            . ': net_after ' . $item['net_after'] . ' != physical target ' . $item['physical'];
    }
}
echo "\nSummary: {$counts['RECONCILED']} reconciled, {$counts['UNRESOLVED']} unresolved, {$counts['SKIPPED-ALREADY']} skipped-already.\n";
echo sprintf(
    "Negative products/units: before %d / %s; %s %d / %s.\n",
    $beforeSnapshot['count'], number_format($beforeSnapshot['units']), $apply ? 'after' : 'predicted after',
    $afterSnapshot['count'], number_format($afterSnapshot['units'])
);
if ($arithmeticMismatches === []) {
    echo "Arithmetic reconciliation exceptions: none.\n";
} else {
    echo "Arithmetic reconciliation exceptions (reported, never clamped):\n- "
        . implode("\n- ", $arithmeticMismatches) . "\n";
}

modulePopContext();
$harness->done();
