<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-area-rollout', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers/admin-area-scope.php');
$h->fingerprint('modules/daily-ledger/database/migrations/090_correct_hybrid_supply_modes.sql');
$h->fingerprint('modules/daily-ledger/database/migrations/091_canonical_areas.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/branches.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_area_rollout_harness.php');
$h->allowLogLines('disyl.compile.phases');
// Existing kernel catalog bootstrap probes DDL through ModuleDB on a fresh CLI
// request; ModuleDB correctly denies and logs it. Unrelated to this module data.
$h->allowLogLines("ModuleDB DENIED: DDL/DCL statement 'CREATE' is forbidden for modules");
$h->allowLogLines('disyl.interpreted_fallback');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('Supply correction');
$eleven = ['DAP-POLO1','DAP-PRINCE1','DAP-BAGTING1','DPL-MP1','DPL-RIZAL1','DPL-GENLUNA1','DPL-OBAY1','DPL-KATIPUNAN1','DPL-FISHPORT1','DPL-MINAOG1','DPL-HERITG1'];
$marks = implode(',', array_fill(0, count($eleven), '?'));
$stmt = $db->prepare("SELECT code, default_supply_mode FROM dl_branches WHERE code IN ({$marks}) ORDER BY code");
$stmt->execute($eleven);
$modes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$h->test('all eleven supplied-producing branches are hybrid', count($modes) === 11 && count(array_filter($modes, static fn($mode): bool => $mode !== 'hybrid')) === 0, json_encode($modes));
$counter = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE assigned_commissary_id IS NOT NULL AND default_supply_mode = 'self_managed'")->fetchColumn();
$h->test('assigned commissary but self_managed snapshot counter is zero', $counter === 0, 'counter=' . $counter);
$branchId = (int)$db->query("SELECT id FROM dl_branches WHERE code = 'DAP-POLO1'")->fetchColumn();
$productStmt = $db->prepare('SELECT p.id FROM dl_products p WHERE NOT EXISTS (SELECT 1 FROM dl_branch_product_supply_rules r WHERE r.branch_id = ? AND r.product_id = p.id AND r.is_active = 1) LIMIT 1');
$productStmt->execute([$branchId]);
$supply = dl_resolveProductSupplySource($branchId, (int)$productStmt->fetchColumn());
$h->test('delivery resolver returns commissary for corrected branch', ($supply['source'] ?? '') === 'commissary' && ($supply['mode'] ?? '') === 'hybrid', json_encode($supply));

$h->section('Canonical areas');
$areas = $db->query('SELECT code, name FROM dl_areas ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$h->test('stored areas have canonical codes without changing display spelling', ($areas['PAGADIAN'] ?? '') === 'Pagadian City' && ($areas['MOLAVE'] ?? '') === 'MOLAVE');
$unmapped = (int)$db->query("SELECT (SELECT COUNT(*) FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL) + (SELECT COUNT(*) FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL)")->fetchColumn();
$h->test('every nonempty historical area maps exactly once', $unmapped === 0, 'unmapped=' . $unmapped);
$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/branches.disyl');
$h->test('all three free-text area controls are canonical pickers', str_contains($tpl, 'id="add-area-id"') && str_contains($tpl, 'id="edit-area-id"') && str_contains($tpl, 'id="consignee-area-id"') && !str_contains($tpl, 'id="add-area"') && !str_contains($tpl, 'id="edit-area"') && !str_contains($tpl, 'id="consignee-area"'));

$h->section('Admin view scope is not authorization');
$admin = ['id' => 1, 'role' => 'admin'];
$areaId = (int)$db->query("SELECT id FROM dl_areas WHERE code = 'DAPITAN'")->fetchColumn();
$session = [];
$areaScope = AdminAreaScope::resolve(['scope' => 'AREA:' . $areaId], $session, $admin);
$expectedArea = array_map('intval', $db->query("SELECT id FROM dl_branches WHERE is_active = 1 AND area_id = {$areaId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$persistedScope = AdminAreaScope::resolve([], $session, $admin);
$h->test('validated explicit AREA filters and persists when parameter is absent', $areaScope['branch_ids'] === $expectedArea && $persistedScope['value'] === $areaScope['value'], json_encode($areaScope));
$reportFilters = dl_reportFilters(['scope' => 'AREA:' . $areaId], $admin);
$h->test('displayed reports and their exports share the area branch criteria', $reportFilters['accessible_branch_ids'] === $expectedArea && ($reportFilters['view_scope'] ?? '') === 'AREA:' . $areaId, json_encode($reportFilters));
$commissaryId = (int)$db->query("SELECT id FROM dl_branches WHERE code = 'RIZAL-COMMIS1'")->fetchColumn();
$networkScope = AdminAreaScope::resolve(['scope' => 'COMMISSARY:' . $commissaryId], $session, $admin);
$expectedNetwork = array_map('intval', $db->query("SELECT id FROM dl_branches WHERE is_active = 1 AND (id = {$commissaryId} OR assigned_commissary_id = {$commissaryId}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$h->test('COMMISSARY is the supply network, not the geographic area', $networkScope['branch_ids'] === $expectedNetwork && $networkScope['branch_ids'] !== $expectedArea, json_encode($networkScope));
$cashierRow = $db->query("SELECT u.id FROM dl_users u WHERE u.role = 'cashier' AND u.is_active = 1 AND u.deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashier = ['id' => (int)($cashierRow['id'] ?? 0), 'role' => 'cashier'];
$cashierToken = dl_generateAuthTokens(['sub' => 'cashier:' . $cashier['id'], 'id' => $cashier['id'], 'username' => 'scope-cashier', 'name' => 'Scope Cashier', 'role' => 'cashier', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $cashierToken['token'];
$authBefore = dl_accessibleBranchIds($cashier);
$ignored = AdminAreaScope::resolve(['scope' => 'AREA:' . $areaId], $session, $cashier);
$authAfter = dl_accessibleBranchIds($cashier);
$h->test('cashier authorized branch set is unchanged while admin area scope is active', $authBefore !== [] && $authBefore === $authAfter && $ignored['type'] === 'ALL', json_encode([$authBefore, $authAfter, $ignored]));
$allScope = AdminAreaScope::resolve(['scope' => 'ALL'], $session, $admin);
$h->test('ALL requires and accepts an explicit reset', $allScope['value'] === 'ALL' && ($session['daily_ledger.admin_view_scope.1'] ?? '') === 'ALL');

$h->section('Commissary output bound');
$temporaryIds = [];
$beforeCount = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE code LIKE 'BOUND-%'")->fetchColumn();
try {
    $insert = $db->prepare("INSERT INTO dl_branches (code, name, is_active, is_commissary, default_supply_mode) VALUES (?, ?, 1, 0, 'self_managed')");
    $suffix = random_int(10000, 99999);
    for ($i = 1; $i <= 45; $i++) {
        $insert->execute(['BOUND-' . $suffix . '-' . $i, 'Bound branch ' . $suffix . ' ' . $i]);
        $temporaryIds[] = (int)$db->lastInsertId();
    }
    $output = [];
    $exit = 0;
    $selectedTemporaryId = (int)end($temporaryIds);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_area_rollout_harness.php') . ' ' . $selectedTemporaryId . ' 2>&1', $output, $exit);
    $probe = json_decode(implode("\n", $output), true);
    $h->test('page remains HTTP 200 after branches exceed old output ceiling', $exit === 0 && is_array($probe) && ($probe['status'] ?? 0) === 200 && ($probe['bytes'] ?? 0) > 0, implode("\n", $output));
    $h->test('bounded page visibly reports omitted branch columns', !empty($probe['notice']), json_encode($probe));
    $h->test('rendered destination columns have a fixed construction bound', (int)($probe['columns'] ?? 999) <= 10, json_encode($probe));
    $h->test('an explicitly selected branch is retained beyond the normal first page', !empty($probe['selected_included']), json_encode($probe));
} finally {
    if ($temporaryIds !== []) {
        $db->execute('DELETE FROM dl_branches WHERE id IN (' . implode(',', array_map('intval', $temporaryIds)) . ')');
    }
}
$afterCount = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE code LIKE 'BOUND-%'")->fetchColumn();
$h->test('temporary ceiling-proof branches are removed', $afterCount === $beforeCount, "{$beforeCount}->{$afterCount}");

$h->done();
