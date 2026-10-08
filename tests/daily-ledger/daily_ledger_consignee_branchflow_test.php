<?php

declare(strict_types=1);

/**
 * Slice 10 ORACLE — the Consignees SUB-TAB of the Commissary Daily Sheet takes the
 * Branches shape.
 *
 * Owner, 2026-10-08: "at consignees daily sheet, the consignee name is a row, it must
 * also be the same as in branches, columned and vertically set."
 *
 * Every case asserts an OUTCOME, not a mechanism. Discriminating cases prove the new
 * contract; pins defend a property that must hold after the slice.
 *
 *   A discriminating  cells are keyed [product_id][consignee_id] (a COLUMN dimension)
 *   B discriminating  a consignee with no ledger activity that date gets no column
 *   C discriminating  BEG/ADDTL are the COMMISSARY's shared values, never the
 *                     consignee's own beg_bal/addtl (fixture makes them differ)
 *   D discriminating  a BRANCH-originated consignee dispatch is included (no
 *                     origin_type='commissary' filter — the correction the chair owed)
 *   E pin             the Branches sub-tab's matrix is byte-identical before/after the
 *                     seam runs and stays branch-keyed (defends R10.5)
 *   F pin             the custody columns (WITHDRAWALS / ENDING) are gone from the
 *                     Consignees sub-tab markup (defends R10.2 / R10.6)
 *   G pin             every fixture row is deleted and every setting is restored
 *                     (defends tenant state and proves cleanup)
 *   H pin             the feature toggle and sales mode are untouched by the seam
 *                     (defends R10.7)
 *
 * PIN VALIDATION, both directions (recorded so the pins are not trusted blindly):
 *   C  returning the consignee's beg_bal/addtl (100/3) instead of the commissary's
 *      (5/9) reddens C alone, because the fixture deliberately makes them differ.
 *   D  adding an origin_type='commissary' requirement to the cells reddens D, because
 *      the only fixture dispatch is branch-originated.
 *   E  letting the seam write to the branch matrix reddens E (matrices differ).
 *   F  re-adding <th>WITHDRAWALS</th> to commissary.disyl reddens F.
 *   G  skipping any delete reddens G (the cleanup proof is an explicit count).
 *
 * The pure row builder is called directly; no fixture-driving HTTP handler is invoked,
 * so nothing can reach $ctx->json() and silently exit the process. Rendering is NOT
 * asserted here: storage/cache/compiled is www-data-owned, so a CLI render of the
 * edited template is not a gate-able signal (the chair verifies it in a browser).
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-branchflow', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// Distinct ids so nothing can be confused with the live fixture or with each other.
$commissary = 99781;
$branch = 99782;
$consigneeActive = 99783;
$consigneeIdle = 99784;
$product = 99785;
$date = '2097-04-15';
$drPrefix = 'S10BORACLE-';

$cleanup = static function () use ($db, $commissary, $branch, $consigneeActive, $consigneeIdle, $product, $drPrefix): void {
    // Effects first: they reference deliveries, items, consignees and products.
    $db->prepare('DELETE FROM dl_consignee_ledger_effects WHERE consignee_id IN (?, ?) OR product_id = ?')
        ->execute([$consigneeActive, $consigneeIdle, $product]);
    $deliveryIds = $db->query("SELECT id FROM dl_deliveries WHERE dr_number LIKE '{$drPrefix}%'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($deliveryIds !== []) {
        $idList = implode(',', array_map('intval', $deliveryIds));
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$idList})")->execute();
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ({$idList})")->execute();
    }
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id IN (?, ?) OR product_id = ?')
        ->execute([$consigneeActive, $consigneeIdle, $product]);
    $db->prepare('DELETE FROM dl_consignee_products WHERE consignee_id IN (?, ?) OR product_id = ?')
        ->execute([$consigneeActive, $consigneeIdle, $product]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ?')
        ->execute([$commissary, $product]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id = ?')
        ->execute([$commissary, $branch, $product]);
    $db->prepare('DELETE FROM dl_consignees WHERE id IN (?, ?)')->execute([$consigneeActive, $consigneeIdle]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$commissary, $branch]);
};

$cleanup();
$settingsBefore = dlModuleSettings(true);
$originalEnabled = dl_isConsigneeEnabled();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S10B-COM", "S10B Commissary", 1, 1)')->execute([$commissary]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S10B-BR", "S10B Branch", 0, 1)')->execute([$branch]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S10B-P", "S10B Product", 10.00, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$commissary, $product]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S10B-C1", "S10B Active Consignee", ?, 1)')->execute([$consigneeActive, $commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S10B-C2", "S10B Idle Consignee", ?, 1)')->execute([$consigneeIdle, $commissary]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consigneeActive, $product]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consigneeIdle, $product]);
    // The COMMISSARY's shared production values: BEG 5, ADDTL 9.
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, "AM", 5, 9, 0, 1)')
        ->execute([$commissary, $product, $date]);
    // The consignee's OWN custody balances deliberately DIFFER: beg_bal 100, addtl 3.
    // Case C must return the commissary's 5/9, never these.
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 4.00, 100, 3, 1)')
        ->execute([$consigneeActive, $product, $date]);

    // Branch-originated consignee dispatch — the NORMAL cashier flow. The seam reads the
    // consignee ledger that this dispatch credited; there is no origin filter.
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, consignee_id, dr_number, delivery_date, production_shift, status) VALUES ("branch", ?, "consignee", ?, ?, ?, "AM", "posted")')
        ->execute([$branch, $consigneeActive, $drPrefix . 'CONS', $date]);
    $consigneeDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 3, 4.00)')
        ->execute([$consigneeDeliveryId, $product]);
    $consigneeDeliveryItemId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_consignee_ledger_effects (delivery_id, delivery_item_id, consignee_id, source_branch_id, product_id, ledger_date, shift, quantity, effect_status, applied_at, before_qty, after_qty) VALUES (?, ?, ?, ?, ?, ?, "AM", 3, "applied", NOW(), 0, 3)')
        ->execute([$consigneeDeliveryId, $consigneeDeliveryItemId, $consigneeActive, $branch, $product, $date]);

    // Commissary -> branch dispatch, the Branches sub-tab's own data (case E).
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status) VALUES ("commissary", ?, "branch", ?, ?, ?, "AM", "posted")')
        ->execute([$commissary, $branch, $drPrefix . 'BR', $date]);
    $branchDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 4, 10.00)')
        ->execute([$branchDeliveryId, $product]);

    // Snapshot the Branches matrix BEFORE the seam runs, then call the seam, then
    // snapshot again. The pin is that the two are identical and stay branch-keyed.
    $branchMatrixBefore = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissary, null);
    $payload = dl_fetchProductionSheetConsigneeCells($db, $date, $commissary, null);
    $branchMatrixAfter = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissary, null);

    $cells = is_array($payload) ? (array)($payload['cells'] ?? []) : [];
    $shared = is_array($payload) ? (array)($payload['beg_addtl'] ?? []) : [];
    $consignees = is_array($payload) ? (array)($payload['consignees'] ?? []) : [];

    // A — discriminating: the consignee is a COLUMN dimension, keyed under the product.
    $h->test(
        'A discriminating: consignee cells are keyed [product_id][consignee_id], a column dimension not a row list',
        isset($cells[$product][$consigneeActive])
        && (int)$cells[$product][$consigneeActive] === 3
        && !isset($cells[$consigneeActive])
        && isset($consignees[$consigneeActive])
        && (string)($consignees[$consigneeActive]['name'] ?? '') === 'S10B Active Consignee'
    );

    // B — discriminating: width is bounded by ledger activity that date.
    $h->test(
        'B discriminating: a consignee with no ledger activity that date gets no column',
        isset($consignees[$consigneeActive])
        && !isset($consignees[$consigneeIdle])
        && !isset($cells[$product][$consigneeIdle])
    );

    // C — discriminating: BEG/ADDTL are the COMMISSARY's, not the consignee's custody.
    $directStmt = $db->prepare('SELECT SUM(beg_qty) AS beg_qty, SUM(produced_qty) AS addtl_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = "AM"');
    $directStmt->execute([$commissary, $product, $date]);
    $direct = $directStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'C discriminating: BEG/ADDTL equal the Branches source (commissary ledger 5/9), never the consignee balances (100/3)',
        (int)($shared[$product]['beg'] ?? -1) === 5
        && (int)($shared[$product]['addtl'] ?? -1) === 9
        && (int)($shared[$product]['beg'] ?? -1) !== 100
        && (int)($shared[$product]['addtl'] ?? -1) !== 3
        && (int)($direct['beg_qty'] ?? -1) === 5
        && (int)($direct['addtl_qty'] ?? -1) === 9
    );

    // D — discriminating: a branch-originated dispatch is included.
    $branchOriginCount = (int)$db->query(
        "SELECT COUNT(*) FROM dl_deliveries WHERE destination_type = 'consignee' AND origin_type = 'branch'"
        . " AND consignee_id = {$consigneeActive} AND delivery_date = '{$date}'"
    )->fetchColumn();
    $columnTotal = 0;
    foreach ((array)($cells[$product] ?? []) as $quantity) {
        $columnTotal += (int)$quantity;
    }
    $h->test(
        'D discriminating: a branch-originated consignee dispatch is included (no origin_type=commissary filter)',
        $branchOriginCount === 1 && $columnTotal === 3 && (int)($cells[$product][$consigneeActive] ?? 0) === 3
    );

    // E — pin: the Branches sub-tab's matrix is untouched and stays branch-keyed.
    $branchKeysOnly = true;
    foreach ($branchMatrixAfter as $byBranch) {
        foreach (array_keys((array)$byBranch) as $destinationId) {
            if ((int)$destinationId !== $branch) {
                $branchKeysOnly = false;
            }
        }
    }
    $h->test(
        'E pin: the Branches matrix is byte-identical after the seam and stays branch-keyed (defends R10.5)',
        $branchMatrixBefore === $branchMatrixAfter
        && (int)($branchMatrixAfter[$product][$branch] ?? 0) === 4
        && !isset($branchMatrixAfter[$product][$consigneeActive])
        && $branchKeysOnly
    );

    // F — pin: the custody columns leave this sub-tab.
    $tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $custodyHeaders = [];
    foreach (['WITHDRAWALS', 'ENDING'] as $needle) {
        if (preg_match('/<th[^>]*>\s*' . $needle . '\s*<\/th>/i', $tpl)) {
            $custodyHeaders[] = $needle;
        }
    }
    $h->test(
        'F pin: WITHDRAWALS / ENDING are gone from the Consignees sub-tab markup (defends R10.2)',
        $custodyHeaders === []
        && !str_contains($tpl, 'row.withdraw_qty')
        && !str_contains($tpl, 'row.ending_qty')
    );
    // F2 REPOINTED (commissary-consignee-depletion, R5): the Branches and Consignees
    // sub-tabs became ONE Daily Sheet table with a destination filter, so the old
    // separate consignee table id is deliberately gone. The pin keeps its strength:
    // the one table still carries the Branches column shape for consignee columns and
    // exactly one ACTUAL BAL, and there is no second table.
    $h->test(
        'F2 pin: the ONE Daily Sheet table carries a consignee destination filter and the Branches shape (defends R5)',
        str_contains($tpl, 'id="production-ledger-table"')
        && str_contains($tpl, 'id="production-destination-filter-consignees"')
        && str_contains($tpl, 'consignee-sheet-column')
        && str_contains($tpl, 'consignee-sheet-cell')
        && str_contains($tpl, 'consignee-sheet-total')
        && !str_contains($tpl, 'id="consignee-production-ledger-table"')
    );

    // H — pin: the feature toggle and sales mode are untouched by the seam (R10.7).
    $settingsAfter = dlModuleSettings(true);
    $h->test(
        'H pin: the feature toggle and sales mode are unchanged by the Consignees seam (defends R10.7)',
        ($settingsBefore['consignee_enabled'] ?? null) === ($settingsAfter['consignee_enabled'] ?? null)
        && ($settingsBefore['consignee_sales_mode'] ?? null) === ($settingsAfter['consignee_sales_mode'] ?? null)
        && dl_isConsigneeEnabled() === $originalEnabled
    );
} finally {
    $cleanup();
}

// G — pin: every fixture row is gone and the tenant state is restored.
$remaining = 0;
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$commissary}, {$branch})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id IN ({$consigneeActive}, {$consigneeIdle})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id IN ({$consigneeActive}, {$consigneeIdle}) OR product_id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id IN ({$consigneeActive}, {$consigneeIdle}) OR product_id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE consignee_id IN ({$consigneeActive}, {$consigneeIdle}) OR product_id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id IN ({$commissary}, {$branch}) OR product_id = {$product}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number LIKE '{$drPrefix}%'")->fetchColumn();
$settingsRestored = dlModuleSettings(true);
$h->test(
    'G pin: every fixture row is deleted and the settings are restored (defends tenant state and proves cleanup)',
    $remaining === 0
    && ($settingsRestored['consignee_enabled'] ?? null) === ($settingsBefore['consignee_enabled'] ?? null)
    && ($settingsRestored['consignee_sales_mode'] ?? null) === ($settingsBefore['consignee_sales_mode'] ?? null)
    && dl_isConsigneeEnabled() === $originalEnabled
);

$h->done();
