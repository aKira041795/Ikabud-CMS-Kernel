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
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/helpers/admin-area-scope.php');
$h->fingerprint('modules/daily-ledger/database/migrations/090_correct_hybrid_supply_modes.sql');
$h->fingerprint('modules/daily-ledger/database/migrations/091_canonical_areas.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/branches.disyl');
$h->fingerprint('templates/modules/daily-ledger/layouts/app.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_area_rollout_harness.php');
$h->fingerprint('tests/daily-ledger/daily_ledger_admin_area_filters_harness.php');
$h->allowLogLines('disyl.compile.phases');
// The kernel rebuilds its module registry cache whenever module or template files change, and logs
// that fact. It is deliberate engine telemetry, not a defect, and admin_trace_test already allows the
// same line. Without this the app.log hygiene assertion here is FLAKY: it fails only on a run that
// happens to land right after an edit (measured: 22/23 then 22/22 on consecutive runs).
$h->allowLogLines('kernel_state_cache: module_registry rebuilt');
// Existing kernel catalog bootstrap probes DDL through ModuleDB on a fresh CLI
// request; ModuleDB correctly denies and logs it. Unrelated to this module data.
$h->allowLogLines("ModuleDB DENIED: DDL/DCL statement 'CREATE' is forbidden for modules");
$h->allowLogLines('disyl.interpreted_fallback');
// DiSyL engine timing telemetry from TemplateEngine, emitted on the render/compile paths under
// function_exists('log_timing'). Same class as disyl.compile.phases above: deliberate engine
// telemetry, asserted by disyl_compiled_render_instrumentation_test, not a defect. It appears
// transiently on the run that follows a template edit (compiled cache miss) - which is exactly
// the run that renders cashier/ledger.disyl after this suite's fixture edits.
$h->allowLogLines('disyl.render.breakdown');
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
// The canonical Area control lives in the shared partial, not in the layout banner. It moved there
// when the banner's own dropdown was removed as redundant: the banner now only reports the active
// scope, so the control is rendered once per branch-scoped view from this single source. The
// assertion keeps its original intent (ONE canonical control, "All areas" first) and now also
// proves the sharing it names - every probed branch-scoped view must include that one partial.
$scopeTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/partials/scope-filter.disyl');
$scopeShared = true;
foreach (['branches', 'dashboard', 'overview', 'sales'] as $scopeView) {
    $scopeShared = $scopeShared && str_contains((string)file_get_contents($base . '/templates/modules/daily-ledger/admin/' . $scopeView . '.disyl'), 'scope-filter.disyl');
}
$h->test('branch-scoped admin views share one canonical Area control with All areas first', str_contains($scopeTpl, 'id="admin-scope-filter"') && strpos($scopeTpl, '<label') !== false && strpos($scopeTpl, '<label') < strpos($scopeTpl, '<select') && strpos($scopeTpl, '>All areas</option>') < strpos($scopeTpl, 'value="AREA:{scope_area.id}"') && $scopeShared);

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
$allPersisted = AdminAreaScope::resolve([], $session, $admin);
// The persisted slot is keyed per ACTOR **and per VIEW**. Asserting the bare actor key here (as this
// oracle used to) is asserting the shape that made one selection global across every view, so its
// presence is treated as the regression. No CLI REQUEST_URI means every resolve above lands in the
// single `default` view slot. The actor id is derived, not hardcoded, so the assertion survives a
// change to how the actor is identified.
$scopeSlots = array_values(array_filter(array_keys($session), static fn ($k) => str_starts_with((string)$k, 'daily_ledger.admin_view_scope.')));
$defaultSlots = array_values(array_filter($scopeSlots, static fn ($k) => str_ends_with((string)$k, '.default')));
$actorOnlyKeys = array_values(array_filter(array_keys($session), static fn ($k) => preg_match('/^daily_ledger\.admin_view_scope\.\d+$/', (string)$k) === 1));
$defaultKey = count($defaultSlots) === 1 ? (string)$defaultSlots[0] : '';
$h->test(
    'ALL requires and accepts an explicit reset',
    $allScope['value'] === 'ALL'
        && $allPersisted['value'] === 'ALL'
        && count($scopeSlots) === 1
        && count($defaultSlots) === 1
        && $actorOnlyKeys === []
        && ($session[$defaultKey] ?? '') === 'ALL',
    json_encode(['explicit' => $allScope['value'], 'persisted' => $allPersisted['value'], 'slots' => $scopeSlots, 'actor_only_keys' => $actorOnlyKeys])
);

$h->section('Area scope belongs to the view, not the administrator');
$viewAdmin = ['id' => 4242, 'role' => 'admin'];
$viewSession = [];
$restoreUri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/daily-ledger/ledger/rows?scope=AREA:' . $areaId;
$ledgerSet = AdminAreaScope::resolve(['scope' => 'AREA:' . $areaId], $viewSession, $viewAdmin);
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview';
$overviewInherited = AdminAreaScope::resolve([], $viewSession, $viewAdmin);
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';
$commissaryInherited = AdminAreaScope::resolve([], $viewSession, $viewAdmin);
$_SERVER['REQUEST_URI'] = '/daily-ledger/ledger/rows';
$ledgerRemembered = AdminAreaScope::resolve([], $viewSession, $viewAdmin);
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview';
$overviewReset = AdminAreaScope::resolve(['scope' => 'ALL'], $viewSession, $viewAdmin);
$_SERVER['REQUEST_URI'] = '/daily-ledger/ledger/rows';
$ledgerAfterOverviewReset = AdminAreaScope::resolve([], $viewSession, $viewAdmin);
if ($restoreUri === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $restoreUri;
}
// The reported defect: an area picked on the Ledger narrowed Overview, Commissary and every other
// view, and resetting it in one view either followed the admin everywhere or was cleared by
// navigating away. Each half is asserted here so neither can regress alone.
$h->test(
    'a scope set on one view neither leaks into nor is cleared by another view',
    $ledgerSet['value'] === 'AREA:' . $areaId
        && $overviewInherited['type'] === 'ALL'
        && $commissaryInherited['type'] === 'ALL'
        && $ledgerRemembered['value'] === 'AREA:' . $areaId
        && $overviewReset['value'] === 'ALL'
        && $ledgerAfterOverviewReset['value'] === 'AREA:' . $areaId,
    json_encode([
        'ledger_set' => $ledgerSet['value'],
        'overview_inherited' => $overviewInherited['value'],
        'commissary_inherited' => $commissaryInherited['value'],
        'ledger_remembered' => $ledgerRemembered['value'],
        'overview_after_reset' => $overviewReset['value'],
        'ledger_after_overview_reset' => $ledgerAfterOverviewReset['value'],
    ])
);

$h->section('Area dropdown changes branch-scoped view rows');
$areaFixtures = $db->query('SELECT id, name FROM dl_areas WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$filterFixtureIds = ['branches' => [], 'products' => []];
try {
    if (count($areaFixtures) < 2) {
        throw new RuntimeException('Two canonical areas are required for the area-filter fixture.');
    }
    $suffix = (string)random_int(10000, 99999);
    $branchNames = ['Area filter A ' . $suffix, 'Area filter B ' . $suffix];
    $insertBranch = $db->prepare("INSERT INTO dl_branches (code, name, area_id, area, is_active, default_supply_mode) VALUES (?, ?, ?, ?, 1, 'self_managed')");
    foreach ([0, 1] as $index) {
        $insertBranch->execute([
            'AF-' . $suffix . '-' . ($index + 1),
            $branchNames[$index],
            (int)$areaFixtures[$index]['id'],
            (string)$areaFixtures[$index]['name'],
        ]);
        $filterFixtureIds['branches'][] = (int)$db->lastInsertId();
    }
    $insertProduct = $db->prepare("INSERT INTO dl_products (sku, name, product_category, current_price, sort_order, is_active) VALUES (?, ?, 'bread', 10, 0, 1)");
    $insertProduct->execute(['AF-P-' . $suffix, 'Area filter product ' . $suffix]);
    $productId = (int)$db->lastInsertId();
    $filterFixtureIds['products'][] = $productId;
    $insertAssignment = $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)');
    $insertLedger = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (?, ?, ?, \'AM\', 10, 10, 0, 0, 5, 5)');
    foreach ($filterFixtureIds['branches'] as $fixtureBranchId) {
        $insertAssignment->execute([$fixtureBranchId, $productId]);
        $insertLedger->execute([$fixtureBranchId, $productId, dl_businessDate()]);
    }

    $runView = static function (string $view, string $scope, string $markerA, string $markerB): array {
        $output = [];
        $exit = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_admin_area_filters_harness.php')
            . ' ' . escapeshellarg($view) . ' ' . escapeshellarg($scope)
            . ' ' . escapeshellarg($markerA) . ' ' . escapeshellarg($markerB) . ' 2>&1',
            $output,
            $exit
        );
        $decoded = json_decode(implode("\n", $output), true);
        return ['exit' => $exit, 'data' => is_array($decoded) ? $decoded : [], 'raw' => implode("\n", $output)];
    };

    foreach (['branches', 'dashboard', 'overview', 'sales'] as $view) {
        $areaAResult = $runView($view, 'AREA:' . (int)$areaFixtures[0]['id'], $branchNames[0], $branchNames[1]);
        $areaBResult = $runView($view, 'AREA:' . (int)$areaFixtures[1]['id'], $branchNames[0], $branchNames[1]);
        $a = $areaAResult['data'];
        $b = $areaBResult['data'];
        $h->test(
            $view . ' area selection returns a different branch row set',
            $areaAResult['exit'] === 0 && $areaBResult['exit'] === 0
                && ($a['status'] ?? 0) === 200 && ($b['status'] ?? 0) === 200
                && !empty($a['has_area_filter']) && !empty($b['has_area_filter'])
                && !empty($a['has_a']) && empty($a['has_b'])
                && empty($b['has_a']) && !empty($b['has_b']),
            json_encode([$areaAResult, $areaBResult])
        );
    }

    $settingsA = $runView('settings', 'AREA:' . (int)$areaFixtures[0]['id'], $branchNames[0], $branchNames[1]);
    $settingsB = $runView('settings', 'AREA:' . (int)$areaFixtures[1]['id'], $branchNames[0], $branchNames[1]);
    $h->test(
        'non-branch settings view is unchanged and has no decorative Area dropdown',
        $settingsA['exit'] === 0 && $settingsB['exit'] === 0
            && ($settingsA['data']['status'] ?? 0) === 200 && ($settingsB['data']['status'] ?? 0) === 200
            && empty($settingsA['data']['has_area_filter']) && empty($settingsB['data']['has_area_filter'])
            && (int)($settingsA['data']['row_count'] ?? 0) > 0
            && (int)($settingsA['data']['row_count'] ?? -1) === (int)($settingsB['data']['row_count'] ?? -2),
        json_encode([$settingsA, $settingsB])
    );
} finally {
    if ($filterFixtureIds['branches'] !== []) {
        $ids = implode(',', array_map('intval', $filterFixtureIds['branches']));
        $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id IN (' . $ids . ')');
        $db->execute('DELETE FROM dl_branch_products WHERE branch_id IN (' . $ids . ')');
        $db->execute('DELETE FROM dl_branches WHERE id IN (' . $ids . ')');
    }
    if ($filterFixtureIds['products'] !== []) {
        $db->execute('DELETE FROM dl_products WHERE id IN (' . implode(',', array_map('intval', $filterFixtureIds['products'])) . ')');
    }
}

$h->section('Operational presentation branch scope');
$operationalBindingViolations = $db->query(
    "SELECT u.id, u.role, COUNT(ub.branch_id) AS bindings
       FROM dl_users u
       LEFT JOIN dl_user_branches ub ON ub.user_id = u.id
      WHERE u.is_active = 1 AND u.deleted_at IS NULL
        AND u.role IN ('cashier', 'production_in_charge', 'supervisor')
      GROUP BY u.id, u.role
     HAVING COUNT(ub.branch_id) <> 1"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$h->test(
    'every active operational account has exactly one assigned branch',
    $operationalBindingViolations === [],
    json_encode($operationalBindingViolations)
);
$prodRizal = ['id' => 27, 'sub' => 'production_in_charge:27', 'role' => 'production_in_charge'];
$prodAssigned = array_map('intval', $db->query('SELECT branch_id FROM dl_user_branches WHERE user_id = 27 ORDER BY branch_id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
$rizalCommissaryForScope = (int)$db->query("SELECT id FROM dl_branches WHERE code = 'RIZAL-COMMIS1'")->fetchColumn();
$rizalNetworkForScope = array_map('intval', $db->query("SELECT id FROM dl_branches WHERE is_active = 1 AND assigned_commissary_id = {$rizalCommissaryForScope} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$pagadianNetworkForScope = array_map('intval', $db->query("SELECT id FROM dl_branches WHERE is_active = 1 AND assigned_commissary_id = (SELECT id FROM dl_branches WHERE code = 'PAG-COMMISARY1') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$prodExpectedScope = array_values(array_unique(array_merge($prodAssigned, $rizalNetworkForScope)));
$prodActualScope = array_values(array_unique(array_map('intval', dl_adminViewBranchIds($prodRizal, ['scope' => 'ALL']))));
sort($prodExpectedScope);
sort($prodActualScope);
// The presentation set is the assignment PLUS the network of an assigned commissary. Asserting the
// assignment alone (as this oracle first did) is asserting a set that renders ZERO Daily Sheet
// destination columns - measured: prod-rizal with RIZAL selected went columns=10 -> columns=0. A
// production user dispatches TO the commissary's branches, so those branches must be present while a
// sibling commissary's network must not.
$h->test(
    'production presentation scope is its assigned branch plus that commissary network',
    $prodActualScope === $prodExpectedScope
        && $rizalNetworkForScope !== []
        && array_intersect($prodActualScope, $pagadianNetworkForScope) === [],
    json_encode(['view' => $prodActualScope, 'expected' => $prodExpectedScope, 'assigned' => $prodAssigned])
);

$runCommissaryView = static function (string $role, int $userId, int $commissaryId = 0): array {
    $output = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_area_rollout_harness.php')
        . ' 0 ' . escapeshellarg($role) . ' ' . $userId . ' ' . $commissaryId . ' 2>&1',
        $output,
        $exit
    );
    $raw = implode("\n", $output);
    $decoded = json_decode($raw, true);
    return ['exit' => $exit, 'data' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
};
$productionView = $runCommissaryView('production_in_charge', 27);
$productionRendered = array_map('intval', $productionView['data']['rendered_branch_ids'] ?? []);
$h->test(
    'production Daily Sheet and branch picker render the assigned network, not a sibling network',
    $productionView['exit'] === 0
        && ($productionView['data']['status'] ?? 0) === 200
        && array_diff($productionRendered, $prodExpectedScope) === []
        && count(array_intersect($productionRendered, $rizalNetworkForScope)) === count($rizalNetworkForScope)
        && count(array_intersect($productionRendered, $pagadianNetworkForScope)) === 0
        && ($productionView['data']['pagadian_branch_ids_rendered'] ?? []) === [],
    json_encode(['rendered' => $productionRendered, 'expected' => $prodExpectedScope, 'columns' => $productionView['data']['columns'] ?? null])
);
$rizalCommissaryId = (int)$db->query("SELECT id FROM dl_branches WHERE code = 'RIZAL-COMMIS1'")->fetchColumn();
$rizalNetworkCount = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE is_active = 1 AND assigned_commissary_id = {$rizalCommissaryId}")->fetchColumn();
$adminRizalView = $runCommissaryView('admin', 1, $rizalCommissaryId);
$h->test(
    'RIZAL commissary selection still renders its complete Dapitan plus Dipolog network',
    $adminRizalView['exit'] === 0
        && $rizalNetworkCount === 11
        && count($adminRizalView['data']['rizal_network_ids_rendered'] ?? []) === $rizalNetworkCount
        && ($adminRizalView['data']['pagadian_branch_ids_rendered'] ?? []) === [],
    json_encode(['expected_network_count' => $rizalNetworkCount, 'view' => $adminRizalView])
);

// A presentation option-builder must never be callable without an explicit branch scope. This one
// defaulted to null, and null meant `SELECT id FROM dl_branches` - every branch in the tenant - so a
// future caller that forgot the argument would silently widen an operational user's options. The
// assertion is on the SIGNATURE because that is what makes the mistake impossible rather than merely
// documented, and it is falsifiable: restoring `= null` turns this red.
$optionsBuilderParam = (new ReflectionFunction('dl_consigneeDispatchFilterOptions'))->getParameters()[1] ?? null;
$h->test(
    'consignee dispatch filter options require an explicit branch scope',
    $optionsBuilderParam !== null
        && !$optionsBuilderParam->isDefaultValueAvailable()
        && !$optionsBuilderParam->allowsNull(),
    json_encode([
        'param' => $optionsBuilderParam ? $optionsBuilderParam->getName() : null,
        'has_default' => $optionsBuilderParam ? $optionsBuilderParam->isDefaultValueAvailable() : null,
        'allows_null' => $optionsBuilderParam ? $optionsBuilderParam->allowsNull() : null,
    ])
);
// And the empty scope must yield NOTHING rather than falling back to everything.
$emptyScopeOptions = dl_consigneeDispatchFilterOptions($db, []);
$h->test(
    'an empty branch scope yields no consignee dispatch options rather than all of them',
    ($emptyScopeOptions['consignees'] ?? []) === [] && ($emptyScopeOptions['products'] ?? []) === [],
    json_encode(['consignees' => count($emptyScopeOptions['consignees'] ?? []), 'products' => count($emptyScopeOptions['products'] ?? [])])
);

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
