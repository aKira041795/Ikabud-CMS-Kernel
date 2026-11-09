<?php

declare(strict_types=1);

/** Formal-delivery production outputs must not bypass dispatch and receipt. */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-output-dr-guard', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$fixtureBase = random_int(1500000000, 1900000000);
$branch = $fixtureBase;
$commissary = $fixtureBase + 1;
$product = $fixtureBase;
$date = '2098-11-09';
$previousFormal = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';
$user = [
    'id' => 1,
    'sub' => 'admin:1',
    'role' => 'admin',
    'source' => 'daily-ledger',
    'name' => 'Output DR Guard Test',
];

$branchSnapshotStmt = $db->prepare('SELECT * FROM dl_branches WHERE id IN (?, ?) ORDER BY id');
$branchSnapshotStmt->execute([$branch, $commissary]);
$branchSnapshots = $branchSnapshotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($branchSnapshots !== []) {
    throw new RuntimeException('Random fixture branch IDs unexpectedly exist; refusing to touch them.');
}

$cleanup = static function () use ($db, $branch, $commissary, $product): void {
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id IN (?, ?)')->execute([$branch, $commissary]);
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id IN (?, ?) OR product_id = ?')->execute([$branch, $commissary, $product]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$branch, $commissary, $product]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN (?, ?) OR product_id = ?')->execute([$branch, $commissary, $product]);
    $db->prepare('DELETE FROM dl_production_runs WHERE destination_branch_id IN (?, ?) OR product_id = ?')->execute([$branch, $commissary, $product]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$branch, $commissary]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$branch, $commissary]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$branch, $commissary, $product]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$branch, $commissary]);
};
$ledgerAddtl = static function (int $branchId) use ($db, $product, $date): int {
    $stmt = $db->prepare('SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ? AND ledger_date = ?');
    $stmt->execute([$branchId, $product, $date]);
    return (int)$stmt->fetchColumn();
};
$movementCount = static function (int $branchId) use ($db, $product, $date): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id = ? AND product_id = ? AND ledger_date = ?');
    $stmt->execute([$branchId, $product, $date]);
    return (int)$stmt->fetchColumn();
};

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, "self_managed", 0, 1), (?, ?, ?, "self_managed", 1, 1)')
        ->execute([$branch, 'ODRG-B-' . $branch, 'Output DR Guard Branch', $commissary, 'ODRG-C-' . $commissary, 'Output DR Guard Commissary']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 10, 1)')
        ->execute([$product, 'ODRG-P-' . $product, 'Output DR Guard Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1)')
        ->execute([$branch, $product, $commissary, $product]);

    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);

    $h->section('T1 formal ON, branch destination, no DR');
    $before = $ledgerAddtl($branch);
    $message = '';
    try {
        dl_processProductionMovement($user, 'output', [
            'destination_branch_id' => $branch,
            'product_id' => $product,
            'quantity' => 5,
            'ledger_date' => $date,
            'shift' => 'AM',
            'flow_mode' => 'production',
        ]);
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
    $after = $ledgerAddtl($branch);
    $h->test('output is refused with an instruction to enter the DR number',
        str_contains($message, 'Enter a Delivery Receipt number') && str_contains($message, 'output to a branch'),
        $message);
    $h->test('refusal writes no branch addtl and no movement',
        $before === 0 && $after === $before && $movementCount($branch) === 0,
        "addtl {$before} -> {$after}; movements=" . $movementCount($branch));

    // Keep T3 independent on the unfixed tree: remove only the writes that T1
    // detected, so the formal-OFF legacy assertion starts from its own baseline.
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id = ?')->execute([$branch]);
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id = ? AND product_id = ?')->execute([$branch, $product]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ?')->execute([$branch, $product]);

    $h->section('T2 formal ON, commissary destination, no DR');
    $commissaryResult = dl_processProductionMovement($user, 'output', [
        'destination_branch_id' => $commissary,
        'product_id' => $product,
        'quantity' => 5,
        'ledger_date' => $date,
        'shift' => 'AM',
        'flow_mode' => 'production',
    ]);
    $producedStmt = $db->prepare('SELECT COALESCE(SUM(produced_qty), 0) FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ?');
    $producedStmt->execute([$commissary, $product, $date]);
    $produced = (int)$producedStmt->fetchColumn();
    $h->test('Record Addition shape remains allowed without a DR',
        !empty($commissaryResult['movement_id']) && $produced === 5 && $movementCount($commissary) === 1,
        json_encode(['result' => $commissaryResult, 'produced' => $produced]));
    $h->test('commissary output does not write branch addtl', $ledgerAddtl($commissary) === 0);

    $h->section('T3 formal OFF, branch destination, no DR');
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '0']);
    $legacyBefore = $ledgerAddtl($branch);
    $legacyResult = dl_processProductionMovement($user, 'output', [
        'destination_branch_id' => $branch,
        'product_id' => $product,
        'quantity' => 7,
        'ledger_date' => $date,
        'shift' => 'AM',
        'flow_mode' => 'production',
    ]);
    $legacyAfter = $ledgerAddtl($branch);
    $h->test('formal-OFF legacy path still credits branch addtl',
        !empty($legacyResult['movement_id']) && $legacyAfter === $legacyBefore + 7 && $movementCount($branch) === 1,
        "addtl {$legacyBefore} -> {$legacyAfter}");
} finally {
    $cleanup();
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $previousFormal]);
}

$branchSnapshotStmt->execute([$branch, $commissary]);
$restoredBranches = $branchSnapshotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$h->test('formal setting and branch snapshots are restored in finally',
    (string)(dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '') === (string)$previousFormal
    && $restoredBranches === $branchSnapshots,
    json_encode(['branches' => $restoredBranches]));

$h->done();
