<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-product-self-management', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/products.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_branch_product_visibility_harness.php');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$suffix = (string)random_int(100000, 999999);
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-self-products-');
$ownBranch = $foreignBranch = $product = $userId = 0;
$oldSetting = getModuleSettings('daily-ledger')['branch_product_self_management'] ?? '0';

$runToggle = static function (int $actorId, array $body) use ($payloadFile): array {
    file_put_contents($payloadFile, json_encode([
        'role' => 'cashier',
        'user_id' => $actorId,
        'body' => $body,
    ], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_branch_product_visibility_harness.php') . ' self_toggle ' . escapeshellarg($payloadFile) . ' 2>&1', $out, $code);
    return ['exit' => $code, 'raw' => implode("\n", $out), 'json' => json_decode(implode("\n", $out), true)];
};

try {
    $db->prepare('INSERT INTO dl_branches (code,name,is_commissary,is_active) VALUES (?,?,0,1)')->execute(['SELF-' . $suffix, 'Self Branch ' . $suffix]);
    $ownBranch = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branches (code,name,is_commissary,is_active) VALUES (?,?,0,1)')->execute(['FOREIGN-' . $suffix, 'Foreign Branch ' . $suffix]);
    $foreignBranch = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_products (sku,name,current_price,is_active) VALUES (?,?,10,1)')->execute(['SELF-P-' . $suffix, 'Self Product ' . $suffix]);
    $product = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_users (username,password_hash,full_name,role,is_active) VALUES (?,?,?,?,1)')->execute(['self-' . $suffix, password_hash('test-only', PASSWORD_BCRYPT), 'Self User', 'cashier']);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_user_branches (user_id,branch_id) VALUES (?,?)')->execute([$userId, $ownBranch]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$ownBranch, $product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$foreignBranch, $product]);
    saveModuleSettings('daily-ledger', ['branch_product_self_management' => '1']);

    $result = $runToggle($userId, [
        'product_id' => $product,
        'is_active' => false,
        'branch_id' => $foreignBranch,
    ]);
    $ownState = (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id={$ownBranch} AND product_id={$product}")->fetchColumn();
    $foreignState = (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id={$foreignBranch} AND product_id={$product}")->fetchColumn();

    $h->section('Branch-scoped toggle');
    $h->test('hide API returns the persisted hidden state', $result['exit'] === 0 && is_array($result['json']) && ($result['json']['ok'] ?? false) === true && (int)($result['json']['is_active'] ?? -1) === 0, $result['raw']);
    $h->test('hide persists for the acting user own branch', $ownState === 0, 'state=' . $ownState);
    $h->test('foreign branch_id in request is ignored', $foreignState === 1, 'foreign state=' . $foreignState);
} finally {
    saveModuleSettings('daily-ledger', ['branch_product_self_management' => $oldSetting]);
    if ($product > 0) {
        $db->execute('DELETE FROM audit_logs WHERE module = ? AND entity_type = ? AND entity_id = ?', ['daily-ledger', 'dl_branch_products', $ownBranch . '-' . $product]);
        $db->execute('DELETE FROM dl_branch_products WHERE product_id = ?', [$product]);
        $db->execute('DELETE FROM dl_products WHERE id = ?', [$product]);
    }
    if ($userId > 0) {
        $db->execute('DELETE FROM dl_user_branches WHERE user_id = ?', [$userId]);
        $db->execute('DELETE FROM dl_users WHERE id = ?', [$userId]);
    }
    if ($ownBranch > 0 || $foreignBranch > 0) {
        $db->execute('DELETE FROM dl_branches WHERE id IN (?, ?)', [$ownBranch, $foreignBranch]);
    }
    if (is_string($payloadFile) && is_file($payloadFile)) {
        unlink($payloadFile);
    }
}

$h->done();
