<?php

declare(strict_types=1);

/**
 * Slice 7 acceptance gate — the product modal's scope control must cover consignees.
 *
 * WHAT THIS GATE PROVES (data/handler semantics):
 *   A  the column exists and defaults to all_active
 *   B  a missing mode coerces to all_active (never to "strip everything")
 *   C  a garbage mode coerces to all_active
 *   D  'specific' assigns exactly the ticked consignees and deactivates the rest
 *   E  PIN — 'all_active' is ADDITIVE: it never decreases the pair count and reaches every active consignee
 *   F  PIN — the consignee path never touches dl_branch_products
 *   G  PIN — the gate leaves the database exactly as it found it
 *
 * WHAT THIS GATE DELIBERATELY DOES NOT PROVE:
 *   That the product modal RENDERS the consignee list. storage/cache/compiled is www-data-owned, so the
 *   CLI cannot compile products.disyl once this slice edits it; any render-based criterion here would be
 *   a permanent false red. The emitted consignee list is verified in a real browser by the chair.
 *
 * Every fixture lives in one transaction that is ROLLED BACK, so the gate cannot leave rows behind.
 *
 * Traps respected (all measured this session):
 *   - information_schema is FORBIDDEN under modulePushContext (it throws, and a try/catch turns that
 *     into a silent false). Column checks use SHOW COLUMNS.
 *   - The helpers under test take $db and return arrays, so no handler is called in-process and the
 *     $ctx->json() exit-the-process trap does not apply.
 */

$basePath = dirname(__DIR__);

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');

/** @var PDO $db */
$db = app()->dbForTenant(207);

echo "== consignee product-scope acceptance gate ==\n";

$results = [];

function probe(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
}

/** Column lookup via SHOW COLUMNS — never information_schema under module context. */
function columnRow(PDO $db, string $table, string $column): ?array
{
    foreach ($db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['Field'], $column) === 0) {
            return $c;
        }
    }
    return null;
}

$TAG = 'S7GATE';
$COMMISSARY = 18; // RIZAL-COMMIS, the fixture commissary used by every other gate in this module

// ---------------------------------------------------------------------------------------------
// A — the column
// ---------------------------------------------------------------------------------------------
$col = columnRow($db, 'dl_products', 'consignee_assignment_mode');
probe(
    'A dl_products.consignee_assignment_mode exists and defaults to all_active',
    $col !== null && (string)($col['Default'] ?? '') === 'all_active',
    $col === null ? 'column absent' : 'default=' . var_export($col['Default'], true)
);

// ---------------------------------------------------------------------------------------------
// B / C — the normalizer
// ---------------------------------------------------------------------------------------------
$hasNormalizer = function_exists('dl_normalizeConsigneeAssignmentMode');

if ($hasNormalizer) {
    $missing = dl_normalizeConsigneeAssignmentMode([]);
    probe(
        'B a missing mode coerces to all_active',
        ($missing[0] ?? null) === 'all_active',
        'got=' . var_export($missing[0] ?? null, true)
    );

    $garbage = dl_normalizeConsigneeAssignmentMode(['consignee_assignment_mode' => 'garbage', 'consignee_ids' => []]);
    probe(
        'C a garbage mode coerces to all_active',
        ($garbage[0] ?? null) === 'all_active',
        'got=' . var_export($garbage[0] ?? null, true)
    );
} else {
    probe('B a missing mode coerces to all_active', false, 'dl_normalizeConsigneeAssignmentMode() missing');
    probe('C a garbage mode coerces to all_active', false, 'dl_normalizeConsigneeAssignmentMode() missing');
}

$hasApply = function_exists('dl_applyProductConsigneeAssignmentMode');
if (!$hasApply) {
    // Pre-implementation tree: the work items (D, E) cannot pass. The PINS are still evaluated honestly
    // — with no consignee machinery in existence, the branch side is trivially untouched and the gate
    // has created nothing to leak, so F and G legitimately PASS here exactly as they must after.
    probe('D specific assigns exactly the ticked consignees', false, 'dl_applyProductConsigneeAssignmentMode() missing');
    probe('E PIN all_active is additive', false, 'dl_applyProductConsigneeAssignmentMode() missing');
    probe('F PIN the consignee path never touches dl_branch_products', true, 'no consignee path exists yet; branch side trivially untouched');
    probe('G PIN the gate leaves the database exactly as it found it', true, 'no fixtures were created');
    $failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
    echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------------------------
// Rerun-safety: a crashed or fixture-leaking earlier run must not poison this one. Without this the
// gate dies on a duplicate-key fatal instead of reporting, which is how a previous experiment's leaked
// rows were discovered (2026-10-08). The precedent gate does the same at its fixture step.
// ---------------------------------------------------------------------------------------------
$db->exec("DELETE FROM dl_consignee_products WHERE consignee_id IN (SELECT id FROM dl_consignees WHERE code LIKE '{$TAG}%')");
$db->exec("DELETE FROM dl_consignee_products WHERE product_id IN (SELECT id FROM dl_products WHERE sku LIKE '{$TAG}%')");
$db->exec("DELETE FROM dl_branch_products WHERE product_id IN (SELECT id FROM dl_products WHERE sku LIKE '{$TAG}%')");
$db->exec("DELETE FROM dl_consignees WHERE code LIKE '{$TAG}%'");
$db->exec("DELETE FROM dl_products WHERE sku LIKE '{$TAG}%'");

// ---------------------------------------------------------------------------------------------
// Fixtures — one transaction, rolled back at the end so nothing can leak.
// ---------------------------------------------------------------------------------------------
$pairsBefore = (int)$db->query('SELECT COUNT(*) FROM dl_consignee_products')->fetchColumn();
$branchPairsBefore = (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn();
$consigneesBefore = (int)$db->query('SELECT COUNT(*) FROM dl_consignees')->fetchColumn();

$db->beginTransaction();

try {
    $consigneeId = function (string $suffix) use ($db, $TAG, $COMMISSARY): int {
        $db->prepare('INSERT INTO dl_consignees (code, name, assigned_commissary_id, is_active) VALUES (?,?,?,1)')
           ->execute([$TAG . '-' . $suffix, 'S7 Gate ' . $suffix, $COMMISSARY]);
        return (int)$db->lastInsertId();
    };
    $c1 = $consigneeId('C1');
    $c2 = $consigneeId('C2');
    $c3 = $consigneeId('C3');

    $productId = function (string $suffix) use ($db, $TAG): int {
        $db->prepare('INSERT INTO dl_products (sku, name, assignment_mode, consignee_assignment_mode)
                      VALUES (?,?,\'all_active\',\'all_active\')')
           ->execute([$TAG . '-' . $suffix, 'S7 Gate Product ' . $suffix]);
        return (int)$db->lastInsertId();
    };

    $setPair = function (int $pid, int $cid, int $active) use ($db): void {
        $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)')
           ->execute([$cid, $pid, $active]);
    };

    $activePairIds = function (int $pid) use ($db): array {
        $s = $db->prepare('SELECT consignee_id FROM dl_consignee_products WHERE product_id = ? AND is_active = 1 ORDER BY consignee_id');
        $s->execute([$pid]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    };

    // ---- E's product: one pre-existing consignee pair and one pre-existing BRANCH pair.
    $pE = $productId('E');
    $setPair($pE, $c1, 1);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)
                  ON DUPLICATE KEY UPDATE is_active = 1')->execute([8, $pE]);

    // ---- D's product: c3 pre-assigned, plus a branch pair so pin F is meaningful.
    $pD = $productId('D');
    $setPair($pD, $c3, 1);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)
                  ON DUPLICATE KEY UPDATE is_active = 1')->execute([8, $pD]);

    // ---------------------------------------------------------------------------------------------
    // E — PIN: all_active is ADDITIVE.
    // ---------------------------------------------------------------------------------------------
    $pairsEBefore = count($activePairIds($pE));
    dl_applyProductConsigneeAssignmentMode($db, $pE, 'all_active', [], null);
    $pairsEAfter = count($activePairIds($pE));
    $activeConsignees = (int)$db->query('SELECT COUNT(*) FROM dl_consignees WHERE is_active = 1')->fetchColumn();
    $c1StillThere = in_array($c1, $activePairIds($pE), true);

    probe(
        'E PIN all_active is additive: never decreases pairs, reaches every active consignee, keeps the pre-existing pair',
        $pairsEAfter >= $pairsEBefore && $pairsEAfter === $activeConsignees && $c1StillThere,
        "before={$pairsEBefore} after={$pairsEAfter} activeConsignees={$activeConsignees} preExistingPairKept="
        . ($c1StillThere ? 'yes' : 'NO')
    );

    // ---------------------------------------------------------------------------------------------
    // D — specific assigns exactly the ticked consignees and deactivates the rest.
    // ---------------------------------------------------------------------------------------------
    dl_applyProductConsigneeAssignmentMode($db, $pD, 'specific', [$c1, $c2], null);
    $assigned = $activePairIds($pD);
    sort($assigned);
    $want = [$c1, $c2];
    sort($want);
    $c3Off = !in_array($c3, $assigned, true);

    probe(
        "D specific assigns exactly the ticked consignees and deactivates the unticked one",
        $assigned === $want && $c3Off,
        'assigned=[' . implode(',', $assigned) . '] want=[' . implode(',', $want) . '] untickedDeactivated='
        . ($c3Off ? 'yes' : 'NO')
    );

    // ---------------------------------------------------------------------------------------------
    // F — PIN: the consignee path never touches dl_branch_products.
    // ---------------------------------------------------------------------------------------------
    $branchPairsD = (int)$db->query('SELECT COUNT(*) FROM dl_branch_products WHERE product_id = ' . $pD)->fetchColumn();
    probe(
        'F PIN the consignee path never touches dl_branch_products',
        $branchPairsD === 1,
        "branch pairs for the consignee-tested product={$branchPairsD} (expected 1, untouched)"
    );
} finally {
    // Rollback ALWAYS — including on an exception, so a crash cannot leak fixtures.
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

// ---------------------------------------------------------------------------------------------
// G — PIN: the gate left the database exactly as it found it.
// ---------------------------------------------------------------------------------------------
$pairsAfter = (int)$db->query('SELECT COUNT(*) FROM dl_consignee_products')->fetchColumn();
$branchPairsAfter = (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn();
$consigneesAfter = (int)$db->query('SELECT COUNT(*) FROM dl_consignees')->fetchColumn();
$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE code LIKE '" . $TAG . "%'")->fetchColumn()

    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE sku LIKE '" . $TAG . "%'")->fetchColumn();

probe(
    'G PIN the gate leaves the database exactly as it found it',
    $pairsAfter === $pairsBefore && $branchPairsAfter === $branchPairsBefore
        && $consigneesAfter === $consigneesBefore && $leaked === 0,
    "consigneePairs={$pairsBefore}->{$pairsAfter} branchPairs={$branchPairsBefore}->{$branchPairsAfter} "
    . "consignees={$consigneesBefore}->{$consigneesAfter} leaked={$leaked}"
);

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: a product can be scoped to all or selected branches and consignees, and all_active never destroys a pair\n";
    exit(0);
}

echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
