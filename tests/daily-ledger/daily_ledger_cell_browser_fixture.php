<?php
declare(strict_types=1);

// Isolated ledger fixtures; no operational branch or settings are changed.
$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/cell-browser-fixture';
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$id = 99471;
$mode = $argv[1] ?? '';
if (!in_array($mode, ['setup', 'cleanup', 'read', 'stale-sales'], true)) exit(2);
if ($mode === 'stale-sales') {
    $db->execute('UPDATE dl_daily_ledger SET sales = NULL WHERE branch_id = ? AND shift = ?', [$id, 'AM']);
    $db->execute('UPDATE dl_daily_ledger SET bal_end = NULL, sales = 999 WHERE branch_id = ? AND shift = ?', [$id, 'PM']);
    exit;
}
if ($mode === 'read') {
    echo json_encode($db->query('SELECT ledger_date, shift, beg_bal, addtl, withdraw, bal_end, sales FROM dl_daily_ledger WHERE branch_id = 99471 ORDER BY ledger_date, shift')->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
\Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
try {
    $app->db()->prepare('DELETE FROM audit_logs WHERE module = ? AND branch_id = ?')->execute(['daily-ledger', $id]);
} finally {
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
}
foreach (['dl_variance_flags', 'dl_cashier_withdrawals', 'dl_ledger_shift_status', 'dl_ledger_day_status', 'dl_daily_ledger', 'dl_user_branches', 'dl_branch_products'] as $table) {
    $db->execute('DELETE FROM ' . $table . ' WHERE branch_id = ?', [$id]);
}
$db->execute('DELETE FROM dl_users WHERE id = ?', [$id]);
$db->execute('DELETE FROM dl_branches WHERE id = ?', [$id]);
$db->execute('DELETE FROM dl_products WHERE id = ?', [$id]);
if ($mode === 'cleanup') exit;
$date = dl_businessDate();
$db->execute('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_active) VALUES (?, ?, ?, ?, 1)', [$id, 'CELL-TEST', 'Ledger Cell Test', 'self_managed']);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, pcs_per_pack, is_active) VALUES (?, ?, ?, 10, 12, 1)', [$id, 'CELL-TEST', 'Ledger Cell Product']);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)', [$id, $id]);
$db->execute('INSERT INTO dl_users (id, username, full_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)', [$id, 'browser-ledger-cell', 'Browser Ledger Cell', password_hash('BrowserCell!2031', PASSWORD_BCRYPT), 'admin']);
foreach (['AM', 'PM'] as $shift) {
    $db->execute('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by) VALUES (?, ?, ?, ?, 10, 20, 100, 5, 10, 105, ?, ?)', [$id, $id, $date, $shift, $id, $id]);
}
echo json_encode(['date' => $date, 'branch_id' => $id, 'product_id' => $id]);
