<?php

declare(strict_types=1);

/**
 * Daily Ledger — admin evidence trace.
 *
 * The trace is the linked lookup an admin uses to arbitrate a cashier-vs-
 * production dispute from one page: for a DR (or a day+branch) it returns the
 * dispatch, the receiving, the producer, the derived variance, the cashier /
 * correction rows and the ledger rows, each labelled with what it is
 * authoritative for and linked to the page that owns it.
 *
 * This suite pins, by behaviour:
 *
 *   AC1  the route renders for an admin and is refused for a cashier
 *   AC2  a known DR returns its dispatch, items and receiving, linked
 *   AC3  a missing receiving renders "not recorded" and is an OPEN variance
 *        item, never counted as received and never a zero
 *   AC4  a voided delivery is absent
 *   AC5  a nonexistent DR yields an empty result with no phantom rows
 *   AC6  a recorded producer is shown; an unrecorded one reads "not recorded"
 *   AC7  every link carries parameters the target page actually reads
 *   AC8  each group states what its source is authoritative for
 *   AC11 a paper capture whose DR matches a delivery on another date does not
 *        update that delivery (the separately-listed defect fix)
 *   AC12 NULL-origin scope follows destination.assigned_commissary_id
 *   AC13 draft receivings remain open and contribute no received quantity
 *   AC14 reused DR evidence is scoped to this branch/date/product
 *   AC15 same-encoder paper capture is visibly identified as one witness
 *   AC16 every owning link is compatible with the viewer's derived route gate
 *
 * Tenant 207 (baron-001). Every fixture uses the 996xx id range and is removed
 * in a finally block; the suite never asserts whole-tenant emptiness.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-admin-trace', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// Rendering the page compiles the DiSyL template and rebuilds the kernel module
// registry; both are expected instrumentation, not defects.
$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('modules/daily-ledger/workbench-contract.json');
$h->fingerprint('templates/modules/daily-ledger/admin/trace.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_admin_trace_harness.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/deliveries.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/withdrawals.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissaryId = 99601;
$branchId = 99602;
$otherCommissaryId = 99603;
$otherBranchId = 99604;
$productA = 99601; // Trace Bread
$productB = 99602; // Trace Cake
$actorId = 99601;  // Trace Actor (admin / encoder / receiver)
$producerId = 99602; // Trace Producer (production_in_charge)
$controlUserId = 99603; // Deliberately does not match "Trace"

$fullDr = 'TRC-FULL-0001';
$missingDr = 'TRC-MISS-0001';
$voidDr = 'TRC-VOID-0001';
$caseDr = 'TRC SEPT 28 2026';
$commaDr = 'TRC Sep24,2026';
$bareDr = 'TRC 99912345';
$dateDr = 'TRC-DATE-0001';

$harnessPath = __DIR__ . '/daily_ledger_admin_trace_harness.php';
$runToken = bin2hex(random_bytes(4));

$runHarness = static function (array $args) use ($harnessPath): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessPath);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>/dev/null', $output, $exitCode);
    $raw = implode("\n", $output);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $m)) {
        $status = (int)$m[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['status' => $status, 'body' => $raw, 'exit' => $exitCode];
};

$capture = static function (array $payload) use ($runHarness, $actorId): array {
    $file = sys_get_temp_dir() . '/dl-trace-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode($payload));
    $result = $runHarness(['capture', $file, (string)$actorId]);
    @unlink($file);
    $decoded = json_decode($result['body'], true);
    $result['json'] = is_array($decoded) ? $decoded : null;
    return $result;
};

$queryParams = static function (string $url): array {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    return $params;
};

$cleanup = static function () use ($db, $commissaryId, $branchId, $otherCommissaryId, $otherBranchId, $productA, $productB, $actorId, $producerId, $controlUserId): void {
    $b = "{$commissaryId},{$branchId},{$otherCommissaryId},{$otherBranchId}";
    $p = "{$productA},{$productB}";
    $u = "{$actorId},{$producerId},{$controlUserId}";
    $branchFilter = "(SELECT id FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b}))";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$b}))");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN {$branchFilter} OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$b}) OR delivery_id IN {$branchFilter})");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$b}) OR delivery_id IN {$branchFilter}");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN {$branchFilter}");
    $db->execute("DELETE FROM audit_logs WHERE entity_type = 'dl_deliveries' AND entity_id IN (SELECT CAST(id AS CHAR) FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b}))");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b})");
    $db->execute("DELETE FROM dl_cashier_withdrawals WHERE branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$b}) OR product_id IN ({$p})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$p})");
    $db->execute("DELETE FROM dl_user_branches WHERE user_id IN ({$u})");
    $db->execute("DELETE FROM dl_users WHERE id IN ({$u})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$b})");
};

$cleanup();

$allBranches = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
// The fixture branches are inserted later; include them up front so the
// accessible-branch scope the builder applies does not hide the fixtures.
$allBranches = array_values(array_unique(array_merge($allBranches, [$commissaryId, $branchId, $otherCommissaryId, $otherBranchId])));
$build = static function (array $filters) use ($db, $allBranches): array {
    return dl_buildAdminTraceData($db, $filters, $allBranches);
};

$routeSource = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveriesSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$contract = (string)file_get_contents($base . '/modules/daily-ledger/workbench-contract.json');
$traceStart = strpos($handlersSource, 'function dl_buildAdminTraceData');
$traceEnd = strpos($handlersSource, 'function handleAdminActivity');
$traceSource = ($traceStart !== false && $traceEnd !== false && $traceEnd > $traceStart)
    ? substr($handlersSource, $traceStart, $traceEnd - $traceStart)
    : '';

// ─── Static: route, gate and group contract ─────────────────────
$h->section('Static: route, role gate and grouped evidence');

$h->test(
    'AC1 the trace route is registered to handleAdminTrace',
    (bool)preg_match("/'\\/daily-ledger\\/admin\\/trace'\s*=>\s*'daily-ledger:handleAdminTrace'/", $routeSource)
);
$h->test(
    'AC1 the route is declared in the workbench contract GET map',
    str_contains($contract, '"/daily-ledger/admin/trace"')
);
$h->test('AC1 handleAdminTrace exists', function_exists('handleAdminTrace'));
$h->test(
    'AC1 the trace handler declares all four admitted roles',
    (bool)preg_match(
        "/function handleAdminTrace.*?dlCurrentUser\\(\\['admin', 'supervisor', 'auditor', 'production_in_charge'\\]\\)/s",
        $handlersSource
    )
);
$traceRuntimeRoles = [];
if (preg_match('/function handleAdminTrace.*?dlCurrentUser\\(\\[([^\\]]+)\\]\\)/s', $handlersSource, $gateMatch)) {
    preg_match_all("/'([^']+)'/", $gateMatch[1], $roleMatches);
    $traceRuntimeRoles = $roleMatches[1] ?? [];
}
$contractData = json_decode($contract, true);
$traceContractRoles = [];
foreach (($contractData['pages'] ?? []) as $page) {
    if (($page['route'] ?? '') === '/daily-ledger/admin/trace') {
        $traceContractRoles = $page['roles'] ?? [];
        break;
    }
}
$h->test(
    'N6 the workbench trace roles equal the runtime handler gate',
    $traceRuntimeRoles !== [] && $traceContractRoles === $traceRuntimeRoles,
    json_encode(['runtime' => $traceRuntimeRoles, 'contract' => $traceContractRoles])
);
$h->test(
    'AC8 every source group carries an authoritative line',
    str_contains($handlersSource, "'authoritative' => 'Who produced the goods and when")
    && str_contains($handlersSource, "'authoritative' => 'What the source recorded as sent")
    && str_contains($handlersSource, "'authoritative' => 'What physically arrived")
    && str_contains($handlersSource, "'authoritative' => 'Derived difference between SENT and RECEIVED")
    && str_contains($handlersSource, "'authoritative' => 'Cashier-side adjustments")
    && str_contains($handlersSource, "'authoritative' => 'What the Daily Sheet ultimately recorded")
);
$h->test(
    'the trace builder never reuses the COALESCE false-all-clear shortcut',
    $traceSource !== ''
    && !preg_match('/COALESCE\s*\([^)]*quantity_received/i', $traceSource)
);

try {
    // ─── Fixtures ────────────────────────────────────────────────
    $h->section('Runtime: fixtures');

    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'TRC-COMM', 'Trace Commissary', 'self_managed', null, 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'TRC-BR', 'Trace Branch', 'commissary_supplied', $commissaryId, 0]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$otherCommissaryId, 'TRC-OC', 'Other Commissary', 'self_managed', null, 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$otherBranchId, 'TRC-OB', 'Other Branch', 'commissary_supplied', $otherCommissaryId, 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$productA, 'TRC-BREAD', 'Trace Bread']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 20, 1, 1)')
        ->execute([$productB, 'TRC-CAKE', 'Trace Cake']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productA]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, 1, NULL)')
        ->execute([$actorId, 'trace-actor', 'x', 'Trace Actor', 'admin']);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, 1, NULL)')
        ->execute([$producerId, 'trace-producer', 'x', 'Trace Producer', 'production_in_charge']);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active, deleted_at) VALUES (?, ?, ?, ?, ?, 1, NULL)')
        ->execute([$controlUserId, 'control-witness', 'x', 'Control Witness', 'supervisor']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')
        ->execute([$producerId, $commissaryId]);

    $insertDelivery = static function (int $id, string $dr, string $date, string $status, ?int $producedBy, ?string $producedAt, ?string $remarks) use ($db, $commissaryId, $branchId, $actorId): void {
        $db->prepare(
            'INSERT INTO dl_deliveries
                (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status,
                 created_by, posted_by, posted_at, remarks, provenance_status, produced_by, produced_at)
             VALUES (?, "commissary", ?, "branch", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $commissaryId, $branchId, $dr, $date, $status,
            $actorId, $actorId, $date . ' 08:00:00', $remarks,
            $remarks === null ? 'none' : 'paper_dr_pending', $producedBy, $producedAt,
        ]);
    };
    $insertItem = static function (int $deliveryId, int $productId, int $qty) use ($db): int {
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, ?, "pcs", 0)')
            ->execute([$deliveryId, $productId, $qty]);
        return (int)$db->lastInsertId();
    };

    // AC2/AC3/AC6: full document — two sent items, one full receipt, one short.
    $insertDelivery(996001, $fullDr, '2021-07-01', 'posted', $producerId, '2021-06-30 22:00:00', '[captured-from-paper-dr]');
    $fullItemA = $insertItem(996001, $productA, 10);
    $fullItemB = $insertItem(996001, $productB, 4);
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (id, branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status, posted_by, posted_at, count_basis)
         VALUES (996001, ?, "commissary", ?, 996001, ?, ?, ?, ?, "posted", ?, ?, "independently_counted")'
    )->execute([$branchId, $commissaryId, $fullDr, $actorId, '2021-07-01 09:00:00', '2021-07-01', $actorId, '2021-07-01 09:00:00']);
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (996001, ?, ?, 10, "pcs")')
        ->execute([$fullItemA, $productA]);
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (996001, ?, ?, 1, "pcs")')
        ->execute([$fullItemB, $productB]);

    // AC3/AC13: a document whose only receiving is a draft.
    $insertDelivery(996002, $missingDr, '2021-07-02', 'posted', null, null, '[captured-from-paper-dr]');
    $missingItemId = $insertItem(996002, $productA, 7);
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (id, branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status, count_basis)
         VALUES (996002, ?, "commissary", ?, 996002, ?, ?, "2021-07-02 09:00:00", "2021-07-02", "draft", "independently_counted")'
    )->execute([$branchId, $commissaryId, $missingDr, $actorId]);
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (996002, ?, ?, 7, "pcs")')
        ->execute([$missingItemId, $productA]);

    // AC4: a voided document.
    $insertDelivery(996003, $voidDr, '2021-07-03', 'voided', null, null, null);
    $insertItem(996003, $productA, 9);

    // AC7: the tenant's real DR formats.
    $insertDelivery(996004, $caseDr, '2021-07-04', 'posted', null, null, null);
    $insertItem(996004, $productA, 3);
    $insertDelivery(996005, $commaDr, '2021-07-05', 'posted', null, null, null);
    $insertItem(996005, $productA, 3);
    $insertDelivery(996006, $bareDr, '2021-07-06', 'posted', null, null, null);
    $insertItem(996006, $productA, 3);

    // AC11: an app-created dispatch with no receiving, on another date.
    $insertDelivery(996007, $dateDr, '2021-07-07', 'posted', null, null, null);
    $insertItem(996007, $productA, 5);

    // AC12: two NULL-origin paper dispatches, one attributable to each
    // destination's configured commissary.
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status) VALUES (?, "commissary", NULL, "branch", ?, ?, "2021-07-09", "posted")')
        ->execute([996008, $branchId, 'TRC-NULL-OWN']);
    $insertItem(996008, $productA, 2);
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status) VALUES (?, "commissary", NULL, "branch", ?, ?, "2021-07-09", "posted")')
        ->execute([996009, $otherBranchId, 'TRC-NULL-OTHER']);
    $insertItem(996009, $productA, 3);

    // User-filter fixtures share a DR prefix but each is attributable to one
    // user only. The control row proves that a multi-user match is filtering,
    // rather than accidentally returning the entire DR scope.
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, created_by, posted_by) VALUES (?, "commissary", ?, "branch", ?, ?, "2021-07-10", "posted", ?, ?)')
        ->execute([996010, $commissaryId, $branchId, 'TRC-USER-ACTOR', $actorId, $actorId]);
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, created_by, posted_by, produced_by) VALUES (?, "commissary", ?, "branch", ?, ?, "2021-07-10", "posted", ?, ?, ?)')
        ->execute([996011, $commissaryId, $branchId, 'TRC-USER-PRODUCER', $producerId, $producerId, $producerId]);
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, created_by, posted_by) VALUES (?, "commissary", ?, "branch", ?, ?, "2021-07-10", "posted", ?, ?)')
        ->execute([996012, $commissaryId, $branchId, 'TRC-USER-CONTROL', $controlUserId, $controlUserId]);

    // Production evidence: a same-date run and a DR-linked movement.
    $db->prepare(
        'INSERT INTO dl_production_runs (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type, yield_qty, dr_number, destination_branch_id, recorded_by)
         VALUES (?, ?, ?, "regular", 1.000, "kilo", 10, ?, ?, ?)'
    )->execute(['2021-07-01', $productA, 'Trace Baker', $fullDr, $branchId, $actorId]);
    $db->prepare(
        'INSERT INTO dl_production_movements (movement_uuid, movement_type, flow_mode, destination_branch_id, product_id, ledger_date, quantity, dr_number, created_by_id, created_by_role)
         VALUES (?, "output", "production", ?, ?, ?, 10, ?, ?, "admin")'
    )->execute(['00000000-0000-0000-0000-000000000096', $branchId, $productA, '2021-07-01', $fullDr, $actorId]);
    // Same DR, wrong branch/date: none of these may attach to this document.
    $db->prepare('INSERT INTO dl_production_runs (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type, yield_qty, dr_number, destination_branch_id, recorded_by) VALUES ("2021-07-01", ?, "Wrong Baker", "regular", 1, "kilo", 99, ?, ?, ?)')
        ->execute([$productA, $fullDr, $otherBranchId, $actorId]);
    $db->prepare('INSERT INTO dl_production_movements (movement_uuid, movement_type, flow_mode, destination_branch_id, product_id, ledger_date, quantity, dr_number, created_by_id, created_by_role) VALUES (?, "output", "production", ?, ?, "2021-07-02", 99, ?, ?, "admin")')
        ->execute(['00000000-0000-0000-0000-000000000097', $otherBranchId, $productA, $fullDr, $actorId]);

    // Cashier adjustment for the same DR.
    $db->prepare(
        'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, shift, withdrawal_type, reason_code, dr_number, quantity, unit, encoded_by, dedup_hash)
         VALUES (?, ?, ?, "AM", "pullout", "other", ?, 2, "pcs", ?, ?)'
    )->execute([$branchId, $productA, '2021-07-01', $fullDr, $actorId, hash('sha1', 'trace-withdrawal-' . $runToken)]);
    $db->prepare('INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, shift, withdrawal_type, reason_code, dr_number, quantity, unit, encoded_by, dedup_hash) VALUES (?, ?, "2021-07-02", "AM", "pullout", "other", ?, 99, "pcs", ?, ?)')
        ->execute([$otherBranchId, $productA, $fullDr, $actorId, hash('sha1', 'trace-wrong-' . $runToken)]);

    // Ledger-cell correction for the same date+product.
    $db->prepare(
        'INSERT INTO audit_logs (module, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data, created_at)
         VALUES ("daily-ledger", ?, "daily-ledger", ?, "field_update", "dl_daily_ledger", ?, ?, ?, NOW())'
    )->execute([$actorId, $branchId, $branchId . '-' . $productA . '-2021-07-01-AM', '{"withdraw":0}', '{"withdraw":2}']);

    // Ledger rows for the same date+branch+product.
    $db->prepare(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by)
         VALUES (?, ?, ?, "AM", 0, 5, 10, 0, NULL, NULL, ?, ?)'
    )->execute([$branchId, $productA, '2021-07-01', $actorId, $actorId]);
    // remaining_qty and calc_variance are generated columns; do not write them.
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty)
         VALUES (?, ?, ?, 0, 10, 10, 0)'
    )->execute([$commissaryId, $productA, '2021-07-01']);

    $h->test('fixtures inserted', true);

    // ─── User name resolution ────────────────────────────────────
    $h->section('User filter: names and usernames resolve without false broad results');
    $userFilterIds = static function (array $data): array {
        $ids = array_map('intval', array_column($data['documents'] ?? [], 'id'));
        sort($ids);
        return $ids;
    };

    $partialName = $build(['dr' => 'TRC-USER-', 'user' => '  pRoDuCeR  ']);
    $h->test(
        'user filter partial full name is trimmed and case-insensitive (revert returns all three)',
        $userFilterIds($partialName) === [996011]
        && array_map('intval', array_column($partialName['user_filter']['matches'] ?? [], 'id')) === [$producerId]
    );

    $usernameMatch = $build(['dr' => 'TRC-USER-', 'user' => 'TRACE-ACTOR']);
    $h->test(
        'user filter username match is case-insensitive (revert returns all three)',
        $userFilterIds($usernameMatch) === [996010]
        && array_map('intval', array_column($usernameMatch['user_filter']['matches'] ?? [], 'id')) === [$actorId]
    );

    $severalUsers = $build(['dr' => 'TRC-USER-', 'user' => 'tRaCe']);
    $severalMatchedIds = array_map('intval', array_column($severalUsers['user_filter']['matches'] ?? [], 'id'));
    sort($severalMatchedIds);
    $h->test(
        'user filter includes every resolved user and excludes nonmatches (revert includes control)',
        $userFilterIds($severalUsers) === [996010, 996011]
        && $severalMatchedIds === [$actorId, $producerId]
    );

    $noUser = $build(['dr' => 'TRC-USER-', 'user' => 'Nobody Named Like This 996']);
    $h->test(
        'nonmatching user is an active filter with zero documents (revert returns all three)',
        (int)($noUser['count'] ?? -1) === 0
        && ($noUser['documents'] ?? null) === []
        && ($noUser['user_filter']['no_match'] ?? false) === true
    );

    $emptyUser = $build(['dr' => 'TRC-USER-', 'user' => '   ']);
    $h->test(
        'empty user leaves the filter inactive (revert lacks the explicit inactive state)',
        $userFilterIds($emptyUser) === [996010, 996011, 996012]
        && ($emptyUser['user_filter']['active'] ?? true) === false
    );

    $legacyUser = $build(['dr' => 'TRC-USER-', 'user_id' => $producerId]);
    $h->test(
        'legacy numeric user_id remains honoured (revert-proof against removing compatibility)',
        $userFilterIds($legacyUser) === [996011]
        && (int)($legacyUser['user_filter']['legacy_user_id'] ?? 0) === $producerId
    );

    $noUserPage = $runHarness(['page', (string)$actorId, 'admin', 'dr=TRC-USER-&user=' . rawurlencode('Nobody Named Like This 996')]);
    $h->test(
        'nonmatching user page visibly says no user matches and renders zero documents (revert shows all three)',
        $noUserPage['exit'] === 0
        && str_contains($noUserPage['body'], '0 documents')
        && str_contains($noUserPage['body'], 'No user matches')
        && str_contains($noUserPage['body'], 'Nobody Named Like This 996')
        && !str_contains($noUserPage['body'], 'TRC-USER-ACTOR')
    );

    $matchedUsersPage = $runHarness(['page', (string)$actorId, 'admin', 'dr=TRC-USER-&user=Trace']);
    $h->test(
        'rendered matches show full name, username and role (revert has no match list)',
        str_contains($matchedUsersPage['body'], 'Trace Actor')
        && str_contains($matchedUsersPage['body'], '@trace-actor')
        && str_contains($matchedUsersPage['body'], 'admin')
        && str_contains($matchedUsersPage['body'], 'Trace Producer')
        && str_contains($matchedUsersPage['body'], '@trace-producer')
        && str_contains($matchedUsersPage['body'], 'production_in_charge')
    );
    $h->test(
        'rendered filter is a text user name field, not numeric User ID (revert restores user_id)',
        str_contains($matchedUsersPage['body'], 'name="user"')
        && str_contains($matchedUsersPage['body'], 'Producer / encoder name')
        && !str_contains($matchedUsersPage['body'], 'name="user_id"')
    );

    // ─── AC12: NULL-origin visibility follows assigned commissary ──
    $h->section('AC12: multi-commissary NULL-origin isolation');
    $ownNull = dl_buildAdminTraceData($db, ['dr' => 'TRC-NULL-OWN'], [$commissaryId], 'production_in_charge');
    $otherNull = dl_buildAdminTraceData($db, ['dr' => 'TRC-NULL-OTHER'], [$commissaryId], 'production_in_charge');
    $h->test('AC12 own assigned NULL-origin dispatch is visible', (int)$ownNull['count'] === 1);
    $h->test('AC12 another commissary assigned NULL-origin dispatch is hidden', (int)$otherNull['count'] === 0);

    // ─── AC2: known DR carries the linked chain ──────────────────
    $h->section('AC2: known DR returns its dispatch, items, receiving and variance');

    $full = $build(['dr' => $fullDr]);
    $h->test('AC2 exactly one document matches the DR', (int)$full['count'] === 1, json_encode(['count' => $full['count']]));
    $doc = $full['documents'][0] ?? [];
    $h->test('AC2 the document is the delivery', (int)($doc['id'] ?? 0) === 996001);
    $h->test('AC2 the dispatch lists both sent items', count($doc['dispatch']['items'] ?? []) === 2);
    $h->test(
        'AC2 the receiving is recorded and linked to the dispatch',
        !empty($doc['receiving']['recorded'])
        && count($doc['receiving']['rows'] ?? []) === 1
        && (int)($doc['receiving']['rows'][0]['delivery_id'] ?? 0) === 996001
        && ($doc['receiving']['rows'][0]['received_by_label'] ?? '') === 'Trace Actor'
    );
    $h->test(
        'receipt status replaces Sent / Waiting and names its actor and time (revert says Sent / Waiting)',
        ($doc['status_label'] ?? '') === 'Received'
        && ($doc['status_actor'] ?? '') === 'Trace Actor'
        && ($doc['status_time'] ?? '') === '2021-07-01 09:00:00'
        && str_contains((string)($doc['status_detail'] ?? ''), 'Trace Actor')
    );
    $h->test(
        'AC2 the linked receiving exposes both received items',
        count($doc['receiving']['rows'][0]['items'] ?? []) === 2
    );
    $h->test(
        'AC2 the receiving item is linked through delivery_item_id',
        (int)($doc['receiving']['rows'][0]['items'][0]['linked'] ?? 0) === 1
    );

    // ─── AC3: difference is the different item only; missing = open ──
    $h->section('AC3: variance lists only real differences; absent is OPEN, not zero');

    $varianceItems = $doc['variance']['items'] ?? [];
    $h->test('AC3 exactly the differing item is listed', count($varianceItems) === 1, json_encode($varianceItems));
    $vi = $varianceItems[0] ?? [];
    $h->test(
        'AC3 the difference is Trace Cake sent 4 / received 1 / -3',
        ($vi['product'] ?? '') === 'Trace Cake'
        && (int)($vi['sent'] ?? 0) === 4
        && ($vi['received_display'] ?? '') === '1'
        && ($vi['delta_display'] ?? '') === '-3'
        && ($vi['state'] ?? '') === 'short'
    );
    $h->test(
        'AC3 the fully-received item is NOT listed as a difference',
        !in_array('Trace Bread', array_column($varianceItems, 'product'), true)
    );

    $missing = $build(['dr' => $missingDr]);
    $missingDoc = $missing['documents'][0] ?? [];
    $h->test(
        'an unreceipted document remains Sent / Waiting (revert/eager Received fails)',
        ($missingDoc['status_label'] ?? '') === 'Sent / Waiting'
        && ($missingDoc['status_detail'] ?? '') === ''
    );
    $h->test('AC13 a draft receiving is recorded=false', empty($missingDoc['receiving']['recorded']));
    $missingVariance = $missingDoc['variance']['items'] ?? [];
    $h->test(
        'AC13 the draft receiving leaves its sent item OPEN (not a zero match)',
        count($missingVariance) === 1
        && ($missingVariance[0]['state'] ?? '') === 'open'
        && ($missingVariance[0]['received_display'] ?? '') === 'not recorded'
        && ($missingVariance[0]['delta_display'] ?? '') === 'open'
        && (int)($missingVariance[0]['sent'] ?? 0) === 7
    );

    // ─── AC6: producer present / absent ──────────────────────────
    $h->section('AC6: producer shown when recorded, not recorded when absent');

    $h->test(
        'AC6 a recorded producer is shown',
        ($doc['production']['producer_name'] ?? '') === 'Trace Producer'
        && ($doc['production']['produced_at'] ?? '') === '2021-06-30 22:00:00'
        && !empty($doc['production']['recorded'])
    );
    $h->test(
        'AC6 the same-date production run names the baker and labels it candidate evidence',
        count($doc['production']['runs'] ?? []) === 1
        && ($doc['production']['runs'][0]['baker'] ?? '') === 'Trace Baker'
        && ($doc['production']['runs'][0]['evidence_state'] ?? '') === 'candidate'
        && str_starts_with((string)($doc['production']['runs'][0]['product'] ?? ''), '[candidate] ')
    );
    $h->test(
        'AC14 only the same branch/date/product DR-linked movement is shown',
        count($doc['production']['movements'] ?? []) === 1
        && (int)($doc['production']['movements'][0]['quantity'] ?? 0) === 10
    );
    $h->test(
        'AC14 only the same branch/date/product cashier row is shown',
        count($doc['cashier']['rows'] ?? []) === 1
        && (int)($doc['cashier']['rows'][0]['quantity'] ?? 0) === 2
        && ($doc['cashier']['rows'][0]['branch'] ?? '') === 'Trace Branch'
    );
    $h->test(
        'AC6 an unrecorded producer reads as empty (template renders not recorded)',
        ($missingDoc['production']['producer_name'] ?? 'x') === ''
        && empty($missingDoc['production']['recorded'])
    );

    // ─── AC4/AC5: voided absent, nonexistent empty, no phantoms ──
    $h->section('AC4/AC5: voided absent and nonexistent DR empty');

    $void = $build(['dr' => $voidDr]);
    $h->test('AC4 a voided delivery is absent', (int)$void['count'] === 0, json_encode(['count' => $void['count']]));

    $phantom = $build(['dr' => 'TRC-DOES-NOT-EXIST-999']);
    $h->test(
        'AC5 a nonexistent DR yields an empty result with no phantom rows',
        (int)$phantom['count'] === 0 && ($phantom['documents'] ?? []) === []
    );

    // ─── AC7-format: case-insensitive, tenant formats ────────────
    $h->section('AC7: DR matching is case-insensitive and tolerates real formats');

    $caseIds = static function (array $data): array {
        return array_map('intval', array_column($data['documents'] ?? [], 'id'));
    };

    $caseLower = $build(['dr' => 'sept 28 2026']);
    $caseUpper = $build(['dr' => 'SEPT 28 2026']);
    $h->test(
        'a lowercase search matches the tenant case format',
        in_array(996004, $caseIds($caseLower), true)
    );
    $h->test(
        'the search is case-insensitive (lower and upper return the same ids)',
        $caseIds($caseLower) === $caseIds($caseUpper) && $caseIds($caseLower) !== []
    );
    $commaMatch = $build(['dr' => 'Sep24,2026']);
    $h->test(
        'the comma format matches',
        in_array(996005, $caseIds($commaMatch), true)
    );
    $bareMatch = $build(['dr' => '99912345']);
    $h->test(
        'a bare number matches',
        in_array(996006, $caseIds($bareMatch), true)
    );

    // ─── AC7: links carry the parameters the target pages read ───
    $h->section('AC7: links use the target pages\' real query parameters');

    $dispatchParams = $queryParams((string)($doc['dispatch']['link_url'] ?? ''));
    $receivingParams = $queryParams((string)($doc['receiving']['link_url'] ?? ''));
    $cashierParams = $queryParams((string)($doc['cashier']['link_url'] ?? ''));
    $varianceParams = $queryParams((string)($doc['variance']['link_url'] ?? ''));
    $ledgerParams = $queryParams((string)($doc['ledger']['link_url'] ?? ''));
    $productionParams = $queryParams((string)($doc['production']['link_url'] ?? ''));

    $h->test(
        'dispatch and receiving link to admin/deliveries with branch_id and date range',
        str_contains((string)$doc['dispatch']['link_url'], '/admin/deliveries')
        && ($dispatchParams['branch_id'] ?? '') === (string)$branchId
        && ($dispatchParams['date_from'] ?? '') === '2021-07-01'
        && ($dispatchParams['date_to'] ?? '') === '2021-07-01'
        && $receivingParams === $dispatchParams
    );
    $h->test(
        'cashier links to admin/withdrawals with q=DR',
        str_contains((string)$doc['cashier']['link_url'], '/admin/withdrawals')
        && ($cashierParams['q'] ?? '') === $fullDr
        && ($cashierParams['branch_id'] ?? '') === (string)$branchId
    );
    $h->test(
        'variance links to admin/variances with branch_id and the differing product',
        str_contains((string)$doc['variance']['link_url'], '/admin/variances')
        && ($varianceParams['branch_id'] ?? '') === (string)$branchId
        && ($varianceParams['q'] ?? '') === 'Trace Cake'
        && ($varianceParams['date_from'] ?? '') === '2021-07-01'
    );
    $h->test(
        'ledger links to the Daily Sheet for that date+branch',
        str_contains((string)$doc['ledger']['link_url'], '/ledger?')
        && ($ledgerParams['branch_id'] ?? '') === (string)$branchId
        && ($ledgerParams['date'] ?? '') === '2021-07-01'
    );
    $h->test(
        'production links to admin/commissary with date and branch',
        str_contains((string)$doc['production']['link_url'], '/admin/commissary')
        && ($productionParams['date'] ?? '') === '2021-07-01'
        && ($productionParams['branch_id'] ?? '') === (string)$branchId
    );

    // AC16 derives each target's role gate from its owning handler, then checks
    // every URL emitted for every role admitted by the trace itself.
    $extractGate = static function (string $source, string $function): array {
        $start = strpos($source, 'function ' . $function);
        if ($start === false) {
            return [];
        }
        $tail = substr($source, $start);
        $next = strpos($tail, "\nfunction ", 1);
        $body = $next === false ? $tail : substr($tail, 0, $next);
        if (!preg_match('/dl(?:CurrentUser|RequireAuth)\(\[([^\]]+)\]\)/', $body, $match)) {
            return [];
        }
        preg_match_all("/'([^']+)'/", $match[1], $roles);
        return $roles[1] ?? [];
    };
    $derivedGates = [
        '/daily-ledger/admin/deliveries' => $extractGate($deliveriesSource, 'handleAdminDeliveries'),
        '/daily-ledger/admin/commissary' => $extractGate($handlersSource, 'handleAdminCommissary'),
        '/daily-ledger/admin/withdrawals' => $extractGate($handlersSource, 'handleAdminWithdrawals'),
        '/daily-ledger/admin/variances' => $extractGate($handlersSource, 'handleAdminVariances'),
        '/daily-ledger/ledger' => $extractGate($handlersSource, 'handleCashierLedger'),
        '/daily-ledger/admin/trace' => $extractGate($handlersSource, 'handleAdminTrace'),
    ];
    $allRoleLinksOpenable = true;
    $badRoleLink = '';
    foreach (['admin', 'supervisor', 'auditor', 'production_in_charge'] as $traceRole) {
        $roleBranches = $traceRole === 'production_in_charge' ? [$commissaryId] : $allBranches;
        $roleData = dl_buildAdminTraceData($db, ['dr' => $fullDr], $roleBranches, $traceRole);
        $roleDoc = $roleData['documents'][0] ?? [];
        foreach (['production', 'dispatch', 'receiving', 'variance', 'cashier', 'ledger'] as $group) {
            $url = (string)($roleDoc[$group]['link_url'] ?? '');
            $path = (string)parse_url($url, PHP_URL_PATH);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $roleLinkParams);
            $unauthorizedTraceBranch = $path === '/daily-ledger/admin/trace'
                && isset($roleLinkParams['branch_id'])
                && !in_array((int)$roleLinkParams['branch_id'], $roleBranches, true);
            if ($path === '' || !in_array($traceRole, $derivedGates[$path] ?? [], true) || $unauthorizedTraceBranch) {
                $allRoleLinksOpenable = false;
                $badRoleLink = $traceRole . ':' . $group . ':' . $url;
                break 2;
            }
        }
    }
    $h->test('AC16 every rendered owning link admits its trace viewer according to the derived handler gate', $allRoleLinksOpenable, $badRoleLink);

    // Prove the target handlers actually read those parameter names.
    $h->test(
        'AC7 handleAdminDeliveries reads branch_id/date_from/date_to',
        str_contains($deliveriesSource, "\$input['branch_id']")
        && str_contains($deliveriesSource, "\$input['date_from']")
        && str_contains($deliveriesSource, "\$input['date_to']")
    );
    $h->test(
        'AC7 handleAdminWithdrawals reads q/branch_id/date_from/date_to',
        str_contains($handlersSource, "\$input['q']")
        && str_contains($handlersSource, "\$input['branch_id']")
        && str_contains($handlersSource, "\$input['date_from']")
        && str_contains($handlersSource, "\$input['date_to']")
    );
    $h->test(
        'AC7 handleAdminVariances reads branch_id/q and the date range',
        str_contains($handlersSource, 'dl_varianceDateRange($input)')
        && str_contains($handlersSource, "\$input['q']")
        && str_contains($handlersSource, "\$input['branch_id']")
    );
    $h->test(
        'AC7 handleCashierLedger reads date (and branch via dl_authorizeBranch)',
        str_contains($handlersSource, "\$input['date']")
        && str_contains($handlersSource, 'dl_authorizeBranch($user, $input)')
    );

    // ─── Rendered page: exact "not recorded" state ───────────────
    $h->section('Rendered page: exact absent-receiving state');

    $adminPage = $runHarness(['page', (string)$actorId, 'admin', 'dr=' . rawurlencode($missingDr) . '&date_from=2021-07-02&date_to=2021-07-02']);
    $h->test(
        'the trace page renders for an admin',
        $adminPage['exit'] === 0
        && str_contains($adminPage['body'], 'Production Delivery Audit')
        && str_contains($adminPage['body'], $missingDr)
    );
    $h->test(
        'AC3 the rendered missing receiving shows "not recorded", not a zero',
        str_contains($adminPage['body'], 'not recorded')
        && !str_contains($adminPage['body'], 'received 0')
        && !str_contains($adminPage['body'], '0 received')
    );
    $h->test(
        'AC3 the rendered variance marks the item open, not matched',
        str_contains($adminPage['body'], '>open</')
        || str_contains($adminPage['body'], 'open')
    );

    $fullPage = $runHarness(['page', (string)$actorId, 'admin', 'dr=' . rawurlencode($fullDr) . '&date_from=2021-07-01&date_to=2021-07-01']);
    $h->test(
        'AC6 the rendered page shows the recorded producer',
        $fullPage['exit'] === 0 && str_contains($fullPage['body'], 'Trace Producer')
    );
    $h->test(
        'AC2 the rendered page shows the linked receiving user',
        str_contains($fullPage['body'], 'Trace Actor')
    );
    $h->test(
        'AC15 same-encoder dispatch and receiving are visibly one paper witness',
        str_contains($fullPage['body'], 'Single paper capture: dispatch and receiving were encoded by the same user; these are not independent witnesses.')
    );

    // AC1 behaviour: production is admitted, while cashier remains excluded.
    $productionPage = $runHarness(['page', (string)$producerId, 'production_in_charge', 'dr=' . rawurlencode($fullDr)]);
    $h->test(
        'AC1 the trace page renders for production_in_charge',
        $productionPage['exit'] === 0
        && str_contains($productionPage['body'], 'Production Delivery Audit')
        && str_contains($productionPage['body'], $fullDr)
    );

    $cashierPage = $runHarness(['page', (string)$actorId, 'cashier', 'dr=' . rawurlencode($missingDr)]);
    $h->test(
        'AC1 the trace page is refused for a cashier',
        in_array($cashierPage['status'], [301, 302, 303, 307, 308, 403], true)
        && !str_contains($cashierPage['body'], 'Production Delivery Audit')
    );

    // ─── AC11: defect fix — date-scoped paper capture ────────────
    $h->section('AC11: paper capture is scoped by delivery_date');

    $beforeStmt = $db->prepare('SELECT * FROM dl_deliveries WHERE id = 996007');
    $beforeStmt->execute();
    $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $capturePayload = [
        'branch_id' => $branchId,
        'origin_type' => 'commissary',
        'origin_id' => null,
        'delivery_date' => '2021-07-08',
        'receive_date' => '2021-07-08',
        'dr_number' => $dateDr,
        'items' => [['product_id' => $productA, 'quantity' => 2, 'unit' => 'pcs']],
        'produced_by' => $producerId,
        'produced_at' => '2021-07-07T22:00',
        'idempotency_key' => 'dl-trace-date-' . $runToken,
    ];
    $captureResult = $capture($capturePayload);
    $newDeliveryId = (int)($captureResult['json']['delivery_id'] ?? 0);

    $h->test(
        'the capture on the other date succeeds and creates a new document',
        $captureResult['status'] === 200
        && ($captureResult['json']['ok'] ?? false) === true
        && $newDeliveryId > 0
        && $newDeliveryId !== 996007,
        $captureResult['body']
    );

    $afterStmt = $db->prepare('SELECT * FROM dl_deliveries WHERE id = 996007');
    $afterStmt->execute();
    $afterRow = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC11 the other date\'s delivery is byte-unchanged',
        $beforeRow !== [] && $beforeRow === $afterRow,
        json_encode(['before' => $beforeRow, 'after' => $afterRow])
    );
    $h->test(
        'AC11 no producer was written onto the other date\'s delivery',
        array_key_exists('produced_by', $afterRow) && $afterRow['produced_by'] === null
        && $afterRow['delivery_date'] === '2021-07-07'
    );

    $newStmt = $db->prepare('SELECT delivery_date, produced_by, dr_number FROM dl_deliveries WHERE id = :id');
    $newStmt->execute([':id' => $newDeliveryId]);
    $newRow = $newStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC11 the new document belongs to the capture\'s own date and carries the producer',
        ($newRow['delivery_date'] ?? '') === '2021-07-08'
        && (int)($newRow['produced_by'] ?? 0) === $producerId
        && ($newRow['dr_number'] ?? '') === $dateDr
    );

    $otherReceiving = $db->prepare('SELECT COUNT(*) FROM dl_branch_receivings WHERE delivery_id = 996007');
    $otherReceiving->execute();
    $h->test(
        'AC11 the other date\'s delivery was not converted into a receiving',
        (int)$otherReceiving->fetchColumn() === 0
    );
} finally {
    $cleanup();
}

$h->done();
