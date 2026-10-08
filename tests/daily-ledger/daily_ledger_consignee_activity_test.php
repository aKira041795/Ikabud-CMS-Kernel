<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-activity', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/activity.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_consignee_activity_render_harness.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$branch = 99841;
$consignee = 99842;
$product = 99843;
$admin = 99844;
$date = dl_businessDate();
$delivery = 0;
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-s4-activity-');
$reviewHarness = __DIR__ . '/daily_ledger_consignee_isolation_harness.php';
$renderHarness = __DIR__ . '/daily_ledger_consignee_activity_render_harness.php';

$render = static function (array $query) use ($renderHarness, $admin): array {
    $encoded = base64_encode((string)json_encode($query, JSON_THROW_ON_ERROR));
    $lines = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($renderHarness) . ' ' . $admin . ' ' . escapeshellarg($encoded) . ' 2>&1', $lines, $exit);
    return ['exit' => $exit, 'html' => implode("\n", $lines)];
};

$visibleMarkup = static function (string $html): string {
    $html = (string)preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = (string)preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$cleanup = static function () use ($db, $branch, $consignee, $product, $admin): void {
    $deliveryIds = $db->prepare('SELECT id FROM dl_deliveries WHERE origin_id = ? OR consignee_id = ?');
    $deliveryIds->execute([$branch, $consignee]);
    $ids = $deliveryIds->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND (branch_id = ? OR actor_module_user_id = ?)')->execute([$branch, $admin]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$consignee]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branch]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S4-ORIGIN", "Slice 4 Origin", 1, 1)')->execute([$branch]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S4-CONS", "Slice 4 Consignee", ?, 1)')->execute([$consignee, $branch]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S4-PROD", "Slice 4 Plain Product", 10, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, "s4-activity-admin", "fixture", "Slice 4 Activity Admin", "admin", 1)')->execute([$admin]);

    $deliveryInsert = $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, consignee_id, dr_number, delivery_date, production_shift, status, provenance_status, remarks, created_by, posted_by, posted_at) VALUES ("branch", :origin, "consignee", NULL, :consignee, "S4-ACTIVITY-DR", :date, "AM", "posted", "paper_dr_pending", "[cashier-dispatch]", :user, :user2, NOW())');
    $deliveryInsert->execute([':origin' => $branch, ':consignee' => $consignee, ':date' => $date, ':user' => $admin, ':user2' => $admin]);
    $delivery = (int)$db->lastInsertId();
    file_put_contents($payloadFile, json_encode(['delivery_id' => $delivery, 'action' => 'accepted', 'note' => 'slice 4 paper DR'], JSON_THROW_ON_ERROR));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($reviewHarness) . ' review ' . escapeshellarg($payloadFile) . ' ' . $admin . ' admin >/dev/null 2>&1');

    $audit = $db->prepare('INSERT INTO audit_logs (module, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data, created_at) VALUES ("daily-ledger", :actor, "daily-ledger", :branch, :action, :type, :entity, :old_data, :new_data, NOW())');
    $audit->execute([
        ':actor' => $admin, ':branch' => $branch, ':action' => 'delivery_voided', ':type' => 'dl_deliveries', ':entity' => (string)$delivery,
        ':old_data' => json_encode(['status' => 'posted']),
        ':new_data' => json_encode(['status' => 'voided', 'consignee_id' => $consignee, 'ledger_reversal' => ['status' => 'legacy_no_effect', 'reversed' => 0], 'consignee_reversal' => true]),
    ]);
    $audit->execute([
        ':actor' => $admin, ':branch' => $branch, ':action' => 'create_product', ':type' => 'product', ':entity' => (string)$product,
        ':old_data' => null,
        ':new_data' => json_encode(['name' => 'Slice 4 Plain Product', 'product_id' => $product, 'is_active' => true]),
    ]);

    $baseQuery = ['date_from' => $date, 'date_to' => $date, 'branch_id' => 'branch:' . $branch];
    $page = $render($baseQuery);
    $markup = $visibleMarkup($page['html']);
    $h->test('A discriminating: readable cells contain no decoded JSON object literal (guards recursive prose formatting)', $page['exit'] === 0 && !preg_match('/\{\s*"[A-Za-z_][A-Za-z0-9_]*"\s*:/', $markup));
    $h->test('A2 discriminating: booleans and absent values read as words (guards 1/0/null leakage)', str_contains($markup, 'Consignee Reversal: Yes') && str_contains($markup, 'Consignee Reversal: None -> Yes'));

    preg_match_all('/<tbody\b[^>]*>(.*?)<\/tbody>/is', $markup, $tbodyMatches);
    $duplicated = false;
    foreach ($tbodyMatches[1] ?? [] as $tbody) {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tbody, $rowMatches);
        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches);
            $actionText = trim((string)preg_replace('/\s+/', ' ', strip_tags((string)($cellMatches[1][4] ?? ''))));
            preg_match_all('/(?:[A-Za-z]+\s+)?#\d+/', $actionText, $labels);
            foreach (array_count_values($labels[0] ?? []) as $count) {
                if ($count > 1) { $duplicated = true; }
            }
        }
    }
    $h->test('B discriminating: no record label repeats inside an Action cell (guards the former delivery-label duplication)', !$duplicated);

    $h->test('C discriminating: consignee verification is visible under its origin branch (guards provenance audit scope)', str_contains($markup, 'Updated paper DR check') && str_contains($markup, 'Slice 4 Consignee'));
    $h->test('D discriminating: consignee ids render as the consignee name and code (guards bare numeric subjects)', str_contains($markup, 'Slice 4 Consignee') && str_contains($markup, 'S4-CONS') && !str_contains($markup, 'Consignee #' . $consignee));

    $consigneePage = $render(['date_from' => $date, 'date_to' => $date, 'branch_id' => 'consignee:' . $consignee, 'action_filter' => 'consignee']);
    $searchPage = $render(['date_from' => $date, 'date_to' => $date, 'q' => 'S4-CONS']);
    $h->test('E discriminating: consignee destination/action/search filters return and round-trip consignee events (guards first-class filtering)',
        $consigneePage['exit'] === 0
        && preg_match('/value="consignee:' . $consignee . '"\s+selected/', $consigneePage['html'])
        && preg_match('/value="consignee"\s+selected/', $consigneePage['html'])
        && str_contains($consigneePage['html'], 'Slice 4 Consignee')
        && $searchPage['exit'] === 0 && str_contains($searchPage['html'], 'Slice 4 Consignee'));

    $h->test('F pin: a plain branch/product activity still renders (defends existing non-consignee activity)', str_contains($markup, 'Slice 4 Origin') && str_contains($markup, 'Slice 4 Plain Product'));

    $rawReachable = false;
    if (preg_match('/href="data:application\/json;base64,([A-Za-z0-9+\/=]+)"[^>]*>Download raw payload/', $page['html'], $rawMatch)) {
        $rawReachable = str_contains((string)base64_decode($rawMatch[1], true), 'ledger_reversal');
    }
    // Find the matching payload if the first row is another event.
    if (!$rawReachable && preg_match_all('/href="data:application\/json;base64,([A-Za-z0-9+\/=]+)"[^>]*>Download raw payload/', $page['html'], $rawMatches)) {
        foreach ($rawMatches[1] as $encodedRaw) {
            if (str_contains((string)base64_decode($encodedRaw, true), 'legacy_no_effect')) { $rawReachable = true; break; }
        }
    }
    $h->test('G pin: the original machine-readable payload remains reachable (defends audit-data preservation)', $rawReachable);
} finally {
    $cleanup();
    @unlink($payloadFile);
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branch}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn();
$h->test('H pin: activity fixture cleanup removes every private row (defends test isolation)', $remaining === 0);
$h->done();
