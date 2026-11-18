<?php

declare(strict_types=1);

/**
 * Daily Ledger — OWN PRODUCTION on a commissary is ONE entry, like a branch
 *
 * Owner 2026-11-18: "add OWN PRODUCTION sa mga commissary pra usa ra daw ilang entry same sa mga
 * branch."
 *
 * What was actually missing was not the capability — the sheet's Record Addition already writes
 * BOTH dl_commissary_product_ledger.produced_qty and dl_production_movements (measured: RIZAL-COMMIS
 * 1,379 outputs, Pagadian 1,327, last 2026-11-16). What was missing was ONE entry: the modal took a
 * single product per submission, so a day's bake meant opening it once per product, while a branch
 * posts every line of its own production at once.
 *
 *   AC1  one batch records several products, and each lands in BOTH tables.
 *   AC2  the batch is atomic: one bad line records NOTHING.
 *   AC3  a replayed batch does not double-count (per-line identity).
 *   AC4  the same product twice in one entry is refused before anything is written.
 *   AC5  the single-product shape still works (the sheet's per-row trigger).
 *   AC6  the entry is audited as one batch.
 *
 * Fixtures: commissary 99897 with products 99898/99899, date 2035-09-05; removed in finally.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-commissary-own-production-batch', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99897;
$productA = 99898;
$productB = 99899;
$date = '2035-09-05';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$cleanup = static function () use ($db, $branchId, $productA, $productB): void {
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_production_runs WHERE destination_branch_id = :b')->execute([':b' => $branchId]);
    // Audit rows outlive the fixture: without this the next run counts the previous run's
    // batch entry and "audited once" fails against a leftover.
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = :b")->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (:a, :b)')->execute([':a' => $productA, ':b' => $productB]);
};

$produced = static function (int $productId) use ($db, $branchId, $date): int {
    $stmt = $db->prepare('SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger
                           WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d');
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    return (int)$stmt->fetchColumn();
};

$movements = static function (int $productId) use ($db, $branchId, $date): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM dl_production_movements
                           WHERE destination_branch_id = :b AND product_id = :p AND ledger_date = :d
                             AND movement_type = 'output'");
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    return (int)$stmt->fetchColumn();
};

$user = ['id' => 1, 'role' => 'admin', 'name' => 'Batch Admin', 'full_name' => 'Batch Admin'];

$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 1, 1)')
        ->execute([$branchId, 'OP-TEST', 'Own Production Commissary', 'Test']);
    foreach ([[$productA, 'OP-A', 'OWN PROD A @10'], [$productB, 'OP-B', 'OWN PROD B @20']] as [$id, $sku, $name]) {
        $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,10,0,1)')
            ->execute([$id, $sku, $name]);
        $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')
            ->execute([$branchId, $id]);
    }

    // ─── AC1 — one batch, many products, both tables ─────────────────────
    $h->section('AC1 — one entry records the whole bake');

    $result = dl_recordProductionAdditionBatch($user, [
        'date' => $date, 'commissary_branch_id' => $branchId, 'shift' => 'AM',
        'submission_id' => 'batch-ac1',
    ], [
        ['product_id' => $productA, 'quantity' => 7],
        ['product_id' => $productB, 'quantity' => 4],
    ]);
    $h->test('the batch reports both lines', ($result['line_count'] ?? 0) === 2, json_encode($result['line_count'] ?? null));
    $h->test('product A produced 7', $produced($productA) === 7, (string)$produced($productA));
    $h->test('product B produced 4', $produced($productB) === 4, (string)$produced($productB));
    $h->test('product A has ONE output movement', $movements($productA) === 1, (string)$movements($productA));
    $h->test('product B has ONE output movement', $movements($productB) === 1, (string)$movements($productB));

    // ─── AC6 — one audit entry for the batch ─────────────────────────────
    $auditStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger'
                                AND action = 'commissary_production_batch' AND entity_id = :e");
    $auditStmt->execute([':e' => "{$branchId}-{$date}"]);
    $h->test('the entry is audited once as a batch', (int)$auditStmt->fetchColumn() === 1, (string)(int)$auditStmt->fetchColumn());

    // ─── AC3 — a replay does not double-count ────────────────────────────
    $h->section('AC3 — a replayed entry does not add again');

    $replay = dl_recordProductionAdditionBatch($user, [
        'date' => $date, 'commissary_branch_id' => $branchId, 'shift' => 'AM',
        'submission_id' => 'batch-ac1',
    ], [
        ['product_id' => $productA, 'quantity' => 7],
        ['product_id' => $productB, 'quantity' => 4],
    ]);
    $h->test('the replay reports both lines as duplicates', ($replay['duplicate_count'] ?? 0) === 2, json_encode($replay['duplicate_count'] ?? null));
    $h->test('product A is still 7', $produced($productA) === 7, (string)$produced($productA));
    $h->test('no second movement was written', $movements($productA) === 1, (string)$movements($productA));

    // ─── AC4 — a repeated product in one entry is refused ────────────────
    $h->section('AC4 — the same product twice is refused, nothing written');

    $refused = null;
    try {
        dl_recordProductionAdditionBatch($user, [
            'date' => $date, 'commissary_branch_id' => $branchId, 'shift' => 'AM',
            'submission_id' => 'batch-ac4',
        ], [
            ['product_id' => $productA, 'quantity' => 2],
            ['product_id' => $productA, 'quantity' => 3],
        ]);
    } catch (\RuntimeException $e) {
        $refused = $e->getMessage();
    }
    $h->test('a repeated product is refused', $refused !== null && str_contains((string)$refused, 'twice'), (string)$refused);
    $h->test('nothing was added by the refused entry', $produced($productA) === 7, (string)$produced($productA));

    // ─── AC2 — atomicity: one bad line records nothing ───────────────────
    $h->section('AC2 — a bad line records NOTHING (one transaction)');

    $failed = null;
    try {
        dl_recordProductionAdditionBatch($user, [
            'date' => $date, 'commissary_branch_id' => $branchId, 'shift' => 'AM',
            'submission_id' => 'batch-ac2',
        ], [
            ['product_id' => $productA, 'quantity' => 5],
            // Product 1 is not assigned to this commissary, so the writer refuses this line.
            ['product_id' => 1, 'quantity' => 5],
        ]);
    } catch (\RuntimeException $e) {
        $failed = $e->getMessage();
    }
    $h->test('the entry is refused for the bad line', $failed !== null, (string)$failed);
    $h->test('the good line did NOT land (one transaction, rolled back)', $produced($productA) === 7, (string)$produced($productA));
    $h->test('no orphan movement survived the rollback', $movements($productA) === 1, (string)$movements($productA));

    // ─── AC5 — the single-product shape still works ──────────────────────
    $h->section('AC5 — the sheet\'s per-row trigger still posts one product');

    $single = dl_recordProductionAddition($user, [
        'date' => $date, 'commissary_branch_id' => $branchId, 'shift' => 'AM',
        'product_id' => $productB, 'quantity' => 1, 'submission_id' => 'single-ac5',
    ]);
    $h->test('the single-product call succeeds', !empty($single['row']));
    $h->test('product B is now 5', $produced($productB) === 5, (string)$produced($productB));

    // ─── Wiring — the endpoint accepts lines[] and the modal posts them ──
    $h->section('The endpoint and the entry');

    $handlers = php_strip_whitespace($base . '/modules/daily-ledger/handlers.php');
    $h->test('the run endpoint dispatches a lines[] entry to the batch',
        str_contains($handlers, 'dl_recordProductionAdditionBatch($user, $input, array_values($rawLines))'));
    $h->test('the batch writes through the one writer, not a copy',
        str_contains($handlers, 'dl_recordProductionAddition($user, $lineInput, false)'));
    $h->test('the writer can be told not to own the transaction',
        str_contains($handlers, 'function dl_recordProductionAddition(array $user, array $input, bool $ownTransaction = true)'));

    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $h->test('the modal is titled OWN PRODUCTION', str_contains($template, '>Own Production</span>'));
    $h->test('the modal can hold several product lines', str_contains($template, 'id="production-addition-lines"')
        && str_contains($template, 'production-addition-add-line'));
    $h->test('the entry posts lines[] in one submission', str_contains($template, 'lines: lines,'));
} finally {
    $cleanup();
}

$h->done();
