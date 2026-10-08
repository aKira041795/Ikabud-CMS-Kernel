<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-admin', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
foreach (['modules/daily-ledger/handlers.php', 'modules/daily-ledger/routes.php', 'templates/modules/daily-ledger/admin/products.disyl', 'templates/modules/daily-ledger/admin/branches.disyl', 'templates/modules/daily-ledger/admin/commissary.disyl', 'tests/daily-ledger/daily_ledger_consignee_admin_harness.php'] as $file) $h->fingerprint($file);
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$branch = 99581;
$consigneeCustom = 99582;
$consigneeDefault = 99583;
$product = 99581;
$priceGroup = 99581;
$fixtureDefaultGroup = 99580;
$admin = 99581;
$date = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-consignee-admin-');
$harness = __DIR__ . '/daily_ledger_consignee_admin_harness.php';

$run = static function (string $mode, array $payload) use ($payloadFile, $harness, $admin): array {
    file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));
    $lines = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' ' . $admin . ' 2>&1', $lines, $exit);
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $m)) {
        $status = (int)$m[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $branch, $consigneeCustom, $consigneeDefault, $product, $priceGroup, $fixtureDefaultGroup, $admin): void {
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND (branch_id = ? OR entity_id IN (?, ?))')->execute([$branch, (string)$consigneeCustom, (string)$consigneeDefault]);
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id IN (?, ?) OR product_id = ?')->execute([$consigneeCustom, $consigneeDefault, $product]);
    $db->prepare('DELETE FROM dl_consignee_products WHERE consignee_id IN (?, ?) OR product_id = ?')->execute([$consigneeCustom, $consigneeDefault, $product]);
    $db->prepare('DELETE FROM dl_product_prices WHERE product_id = ? OR price_group_id = ?')->execute([$product, $priceGroup]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?')->execute([$branch, $product]);
    $db->prepare('DELETE FROM dl_consignees WHERE id IN (?, ?)')->execute([$consigneeCustom, $consigneeDefault]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_price_groups WHERE id IN (?, ?)')->execute([$priceGroup, $fixtureDefaultGroup]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branch]);
};

$cleanup();
try {
    $defaultGroup = (int)$db->query('SELECT id FROM dl_price_groups WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($defaultGroup <= 0) {
        $db->prepare('INSERT INTO dl_price_groups (id, name, type, is_default, is_active) VALUES (?, "Slice 3 Default Pricing", "branch", 1, 1)')->execute([$fixtureDefaultGroup]);
        $defaultGroup = $fixtureDefaultGroup;
    }
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S3-COM", "Slice 3 Commissary", 1, 1)')->execute([$branch]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, "s3-consignee-admin", "fixture", "Slice 3 Admin", "admin", 1)')->execute([$admin]);
    $db->prepare('INSERT INTO dl_price_groups (id, name, type, is_default, is_active) VALUES (?, "Slice 3 Price Group", "other", 0, 1)')->execute([$priceGroup]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S3-PRODUCT", "Slice 3 Product", 5.00, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$branch, $product]);
    $price = $db->prepare('INSERT INTO dl_product_prices (product_id, price_group_id, selling_price, effective_from, is_active) VALUES (?, ?, ?, "1970-01-01", 1) ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), is_active = 1');
    $price->execute([$product, $defaultGroup, 12.50]);
    $price->execute([$product, $priceGroup, 27.75]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, price_group_id, is_active) VALUES (?, "S3-CUSTOM", "Slice 3 Custom", ?, ?, 1), (?, "S3-DEFAULT", "Slice 3 Default", ?, NULL, 1)')->execute([$consigneeCustom, $branch, $priceGroup, $consigneeDefault, $branch]);

    $saved = $run('save', ['consignee_id' => $consigneeCustom, 'code' => 'S3-CUSTOM', 'name' => 'Slice 3 Custom', 'area' => 'Rizal Area', 'address' => '123 Test Street', 'assigned_commissary_id' => $branch, 'price_group_id' => $priceGroup, 'is_active' => 1]);
    $row = $db->query("SELECT area, address, price_group_id FROM dl_consignees WHERE id = {$consigneeCustom}")->fetch(PDO::FETCH_ASSOC);
    $branchesPage = $run('branches', []);
    $h->test('A discriminating: saving persists area/address/price group and Edit preloads all three', ($saved['body']['ok'] ?? false) && $row === ['area' => 'Rizal Area', 'address' => '123 Test Street', 'price_group_id' => $priceGroup] && str_contains($branchesPage['raw'], 'Rizal Area') && str_contains($branchesPage['raw'], '123 Test Street') && str_contains($branchesPage['raw'], 'Slice 3 Price Group') && str_contains($branchesPage['raw'], 'openConsignee(' . $consigneeCustom));

    $refused = $run('save', ['consignee_id' => $consigneeCustom, 'code' => 'S3-CUSTOM', 'name' => 'Must Not Save', 'area' => 'Bad', 'address' => 'Bad', 'assigned_commissary_id' => $branch, 'price_group_id' => 4294967, 'is_active' => 1]);
    $unchanged = (string)$db->query("SELECT name FROM dl_consignees WHERE id = {$consigneeCustom}")->fetchColumn();
    $h->test('B discriminating: nonexistent price group is refused without mutating the consignee', $refused['status'] === 422 && ($refused['body']['ok'] ?? true) === false && str_contains((string)($refused['body']['error'] ?? ''), 'does not exist') && $unchanged === 'Slice 3 Custom');

    $customDelta = $run('delta', ['branch_id' => $branch, 'consignee_id' => $consigneeCustom, 'product_id' => $product, 'date' => $date, 'quantity' => 2, 'shift' => 'AM']);
    $defaultDelta = $run('delta', ['branch_id' => $branch, 'consignee_id' => $consigneeDefault, 'product_id' => $product, 'date' => $date, 'quantity' => 3, 'shift' => 'AM']);
    $prices = $db->query("SELECT consignee_id, price_snapshot FROM dl_consignee_ledger WHERE consignee_id IN ({$consigneeCustom},{$consigneeDefault}) ORDER BY consignee_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $h->test('C discriminating: R8 custom price group credits at that group price', ($customDelta['body']['ok'] ?? false) && (float)($prices[$consigneeCustom] ?? 0) === 27.75);
    $h->test('D discriminating: R8 NULL price group falls back to default group price', ($defaultDelta['body']['ok'] ?? false) && (float)($prices[$consigneeDefault] ?? 0) === 12.50);

    $assigned = $run('bulk', ['consignee_id' => $consigneeCustom, 'product_ids' => [$product]]);
    $activePair = (int)$db->query("SELECT is_active FROM dl_consignee_products WHERE consignee_id = {$consigneeCustom} AND product_id = {$product}")->fetchColumn();
    $assignedAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'consignee_product_assigned' AND entity_id = '{$consigneeCustom}-{$product}'")->fetchColumn();
    $h->test('E discriminating: Show in Consignees assignment persists and is audited', ($assigned['body']['ok'] ?? false) && (int)($assigned['body']['added'] ?? 0) === 1 && $activePair === 1 && $assignedAudit === 1);

    $unassigned = $run('bulk', ['consignee_id' => $consigneeCustom, 'product_ids' => []]);
    $inactivePair = (int)$db->query("SELECT is_active FROM dl_consignee_products WHERE consignee_id = {$consigneeCustom} AND product_id = {$product}")->fetchColumn();
    $unassignedAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'consignee_product_unassigned' AND entity_id = '{$consigneeCustom}-{$product}'")->fetchColumn();
    $h->test('F discriminating: unassignment is persisted and distinguishably audited', ($unassigned['body']['ok'] ?? false) && (int)($unassigned['body']['removed'] ?? 0) === 1 && $inactivePair === 0 && $unassignedAudit === 1);

    $assignmentCount = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id = {$consigneeCustom} AND is_active = 1")->fetchColumn();
    $ledgerCount = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id = {$consigneeCustom}")->fetchColumn();
    $commissaryTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $handlerSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test('G discriminating: assignment gates the sheet and an empty assignment is explicitly explained', $assignmentCount === 0 && $ledgerCount > 0 && str_contains($handlerSource, 'INNER JOIN dl_consignee_products cp ON cp.consignee_id = c.id AND cp.product_id = p.id AND cp.is_active = 1') && str_contains($commissaryTemplate, 'No products are assigned to active consignees') && str_contains($commissaryTemplate, 'Show in Consignees'));

    $products = $run('products', []);
    $branchTab = $run('products', ['tab' => 'assignment', 'branch_id' => $branch]);
    $consigneeTab = $run('products', ['tab' => 'consignee_assignment', 'consignee_id' => $consigneeCustom]);
    $h->test('H pin: Products tab still renders catalog content (defends the preexisting default tab)', str_contains($products['raw'], 'Slice 3 Product') && str_contains($products['raw'], 'data-tab-products'));
    $h->test('I pin: Show in Branches still renders its branch picker and endpoint (defends existing assignment behavior)', str_contains($branchTab['raw'], 'Branch being edited') && str_contains($branchTab['raw'], '/api/v1/admin/branches/products') && str_contains($branchTab['raw'], 'S3-COM'));
    $h->test('J discriminating: Show in Consignees renders active picker/product and consignee endpoint', str_contains($consigneeTab['raw'], 'Consignee being edited') && str_contains($consigneeTab['raw'], '/api/v1/admin/consignees/products') && str_contains($consigneeTab['raw'], 'Slice 3 Custom') && str_contains($consigneeTab['raw'], 'Slice 3 Product'));
} finally {
    $cleanup();
    @unlink($payloadFile);
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id IN ({$consigneeCustom},{$consigneeDefault})")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branch}")->fetchColumn();
$h->test('K pin: every slice-3 fixture row is cleaned up', $remaining === 0);
$h->done();
