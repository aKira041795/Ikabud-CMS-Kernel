<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-area-and-delivery-remarks', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/database/migrations/099_delivery_variance_branch_remarks.sql');
$h->fingerprint('tests/daily-ledger/daily_ledger_area_remark_harness.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$db = modulePushContext('daily-ledger')->db();
$suffix = (string)random_int(100000, 999999);
$areaCode = 'SINDANGAN_' . $suffix;
$commissary = 997100 + random_int(1, 99);
$sender = $commissary;
$receiver = $commissary + 100;
$product = $commissary + 200;
$delivery = $commissary + 300;
$date = '2035-01-17';
$ids = ['area' => 0, 'consignee' => 0, 'variance' => 0, 'receiving' => 0, 'users' => []];
$file = tempnam(sys_get_temp_dir(), 'dl-gap-');
$run = static function (string $mode, string $role, int $userId, array $body, array $get = []) use ($file): array {
    file_put_contents($file, json_encode(compact('role', 'userId', 'body', 'get') + ['user_id' => $userId], JSON_THROW_ON_ERROR));
    $out = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_area_remark_harness.php') . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $out, $exit);
    $raw = implode("\n", $out);
    return ['exit' => $exit, 'body' => json_decode($raw, true), 'raw' => $raw];
};
$cleanup = static function () use ($db, &$ids, $sender, $receiver, $product, $delivery): void {
    if ($ids['variance']) $db->execute('DELETE FROM dl_variance_flags WHERE id = ?', [$ids['variance']]);
    $db->execute('DELETE FROM audit_logs WHERE module = ? AND (entity_id = ? OR branch_id IN (?,?))', ['daily-ledger', (string)$ids['area'], $sender, $receiver]);
    if ($ids['receiving']) $db->execute('DELETE FROM dl_branch_receivings WHERE id = ?', [$ids['receiving']]);
    $db->execute('DELETE FROM dl_delivery_items WHERE delivery_id = ?', [$delivery]);
    $db->execute('DELETE FROM dl_deliveries WHERE id = ?', [$delivery]);
    if ($ids['consignee']) {
        $db->execute('DELETE FROM dl_consignee_products WHERE consignee_id = ?', [$ids['consignee']]);
        $db->execute('DELETE FROM dl_consignees WHERE id = ?', [$ids['consignee']]);
    }
    foreach ($ids['users'] as $uid) $db->execute('DELETE FROM dl_users WHERE id = ?', [$uid]);
    $db->execute('DELETE FROM dl_branch_products WHERE product_id = ?', [$product]);
    $db->execute('DELETE FROM dl_products WHERE id = ?', [$product]);
    $db->execute('DELETE FROM dl_branches WHERE id IN (?,?)', [$sender, $receiver]);
    if ($ids['area']) $db->execute('DELETE FROM dl_areas WHERE id = ?', [$ids['area']]);
};

try {
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')->execute([$sender, 'GAP-S-' . $suffix, 'Gap Sender']);

    $h->section('Concern 7: admin creates Sindangan then its consignee');
    $created = $run('area-create', 'admin', 1, ['code' => $areaCode, 'name' => '  Sindangan  ', 'is_active' => true, 'sort_order' => 7]);
    $ids['area'] = (int)($created['body']['area_id'] ?? 0);
    $row = $ids['area'] ? $db->query('SELECT * FROM dl_areas WHERE id = ' . $ids['area'])->fetch(PDO::FETCH_ASSOC) : false;
    $h->test('admin create stores trimmed Sindangan with explicit active state', !empty($created['body']['ok']) && ($row['name'] ?? '') === 'Sindangan' && ($row['code'] ?? '') === $areaCode && (int)($row['is_active'] ?? -1) === 1, json_encode([$created, $row]));
    $duplicate = $run('area-create', 'admin', 1, ['code' => $areaCode, 'name' => 'Duplicate', 'is_active' => true, 'sort_order' => 0]);
    $h->test('duplicate area code is a clear refusal, not a server error', ($duplicate['body']['ok'] ?? null) === false && str_contains((string)($duplicate['body']['error'] ?? ''), 'already exists'), $duplicate['raw']);
    $consignee = $run('consignee', 'admin', 1, ['code' => 'PURE-' . $suffix, 'name' => 'PUREGOLD Sindangan', 'area_id' => $ids['area'], 'assigned_commissary_id' => $sender, 'is_active' => 1, 'sort_order' => 1]);
    $ids['consignee'] = (int)($consignee['body']['consignee_id'] ?? 0);
    $consigneeArea = $ids['consignee'] ? (int)$db->query('SELECT area_id FROM dl_consignees WHERE id = ' . $ids['consignee'])->fetchColumn() : 0;
    $h->test('existing apiSaveConsignee journey creates PUREGOLD in the new Sindangan area', !empty($consignee['body']['ok']) && $consigneeArea === $ids['area'], json_encode([$consignee, 'area_id' => $consigneeArea]));

    $h->section('Concern 3: only the branch on each delivery side can remark');
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,assigned_commissary_id,default_supply_mode,is_active) VALUES (?,?,?,0,?,"commissary_supplied",1)')->execute([$receiver, 'GAP-R-' . $suffix, 'Gap Receiver', $sender]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')->execute([$product, 'GAP-P-' . $suffix, 'Gap Product']);
    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status,receipt_required) VALUES (?,"commissary",?,"branch",?,?,?,"posted",1)')->execute([$delivery, $sender, $receiver, 'GAP-DR-' . $suffix, $date]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,12,"pcs",10)')->execute([$delivery, $product]);
    $db->prepare('INSERT INTO dl_branch_receivings (branch_id,origin_type,origin_id,delivery_id,dr_number,received_ledger_date,status,received_by) VALUES (?,"commissary",?,?,?,?,"posted",1)')->execute([$receiver, $sender, $delivery, 'GAP-DR-' . $suffix, $date]);
    $ids['receiving'] = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id,product_id,quantity_received) VALUES (?,?,9)')->execute([$ids['receiving'], $product]);
    $db->prepare('INSERT INTO dl_variance_flags (branch_id,product_id,ledger_date,kind,delivery_id,receiving_id,sent_qty,received_qty,original_counted_qty,variance) VALUES (?,?,?,"delivery",?,?,12,9,9,-3)')->execute([$receiver, $product, $date, $delivery, $ids['receiving']]);
    $ids['variance'] = (int)$db->lastInsertId();
    foreach ([[$sender, 'sender'], [$receiver, 'receiver']] as [$branchId, $label]) {
        $db->prepare('INSERT INTO dl_users (username,password_hash,full_name,role,is_active) VALUES (?,"x",?,"cashier",1)')->execute(['gap-' . $label . '-' . $suffix, ucfirst($label) . ' User']);
        $uid = (int)$db->lastInsertId(); $ids['users'][] = $uid;
        $db->prepare('INSERT INTO dl_user_branches (user_id,branch_id) VALUES (?,?)')->execute([$uid, $branchId]);
    }
    [$senderUser, $receiverUser] = $ids['users'];
    $before = $db->query('SELECT sent_qty,received_qty,original_counted_qty,variance,resolution_status FROM dl_variance_flags WHERE id=' . $ids['variance'])->fetch(PDO::FETCH_ASSOC);
    $senderOk = $run('remark', 'cashier', $senderUser, ['variance_id' => $ids['variance'], 'side' => 'sender', 'remark' => 'Loaded twelve pieces.']);
    $senderWrong = $run('remark', 'cashier', $senderUser, ['variance_id' => $ids['variance'], 'side' => 'receiver', 'remark' => 'forged receiver note']);
    $receiverOk = $run('remark', 'cashier', $receiverUser, ['variance_id' => $ids['variance'], 'side' => 'receiver', 'remark' => 'Counted nine pieces.']);
    $receiverWrong = $run('remark', 'cashier', $receiverUser, ['variance_id' => $ids['variance'], 'side' => 'sender', 'remark' => 'forged sender note']);
    $after = $db->query('SELECT * FROM dl_variance_flags WHERE id=' . $ids['variance'])->fetch(PDO::FETCH_ASSOC);
    $page = $run('variances-page', 'admin', 1, [], ['branch_id' => $receiver, 'date_from' => $date, 'date_to' => $date]);
    $h->test('sending branch may write only the sender remark', !empty($senderOk['body']['ok']) && ($senderWrong['body']['ok'] ?? null) === false && str_contains((string)($senderWrong['body']['error'] ?? ''), 'Only the branch'), json_encode([$senderOk, $senderWrong]));
    $h->test('receiving branch may write only the receiver remark', !empty($receiverOk['body']['ok']) && ($receiverWrong['body']['ok'] ?? null) === false && str_contains((string)($receiverWrong['body']['error'] ?? ''), 'Only the branch'), json_encode([$receiverOk, $receiverWrong]));
    $h->test('both remarks retain author, author branch, and timestamp', ($after['sender_remark'] ?? '') === 'Loaded twelve pieces.' && (int)$after['sender_remark_by'] === $senderUser && (int)$after['sender_remark_branch_id'] === $sender && !empty($after['sender_remarked_at']) && ($after['receiver_remark'] ?? '') === 'Counted nine pieces.' && (int)$after['receiver_remark_by'] === $receiverUser && (int)$after['receiver_remark_branch_id'] === $receiver && !empty($after['receiver_remarked_at']), json_encode($after));
    $h->test('office variance surface shows both branch remarks alongside the figures', str_contains($page['raw'], 'Sender remark') && str_contains($page['raw'], 'Loaded twelve pieces.') && str_contains($page['raw'], 'Receiver remark') && str_contains($page['raw'], 'Counted nine pieces.'), 'page bytes=' . strlen($page['raw']));
    $afterQuantities = array_intersect_key($after, $before);
    $h->test('remarks change no quantity and no resolution status', $afterQuantities === $before, json_encode(['before' => $before, 'after' => $afterQuantities]));
} finally {
    $cleanup();
    if (is_file($file)) unlink($file);
}
$h->done();
