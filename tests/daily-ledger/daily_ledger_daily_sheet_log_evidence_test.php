<?php

declare(strict_types=1);

/**
 * Daily Ledger — S15: the day's evidence in the Daily Sheet log.
 *
 * The bottom log merges production movements and `production_ledger_change`
 * audits. Neither carries the send or the receipt: audit_logs has create_delivery
 * / create_receiving rows for only a handful of the real events, so the history
 * has to come from dl_deliveries + dl_delivery_items and dl_branch_receivings.
 * This suite pins:
 *
 *   AC1  a date with deliveries renders SENT rows with branch, quantity and DR
 *   AC2  it renders RECEIVED rows with the receiving user and timestamp
 *   AC3  the paper-captured day (2026-09-28, delivery 129 / receiving 123) appears
 *        through the real render, and is NOT re-derived from audit_logs
 *   AC4  the source-of-truth rule line is on the page
 *   AC5  existing movement/correction rows are unchanged in content and order
 *   AC6  a date with no deliveries/receivings renders no phantom rows
 *   AC7  voided deliveries/receivings are excluded
 *   AC8  a Daily Sheet S10 entry is not double-listed as a SENT row
 *   AC9  no column was added to the log table; the row shape is reused
 *   AC10 draft receivings are not physical RECEIVED evidence
 *
 * Tenant 207 (baron-001). All fixture rows use high ids and are removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-daily-sheet-log-evidence', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

// Rendering compiles the DiSyL template, which emits a named info line.
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissaryId = 99301;
$branchId = 99302;
$productId = 99301;
$date = '2020-07-01';
$voidDate = '2020-07-02';
$coveredDate = '2020-07-03';
$user = ['id' => 27, 'sub' => 'admin:27', 'role' => 'admin', 'source' => 'daily-ledger', 'full_name' => 'Noah Omamalin'];

$cleanup = static function () use ($db, $commissaryId, $branchId, $productId): void {
    $branchIds = "{$commissaryId},{$branchId}";
    $productIds = "{$productId}";
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE product_id IN ({$productIds}) OR delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE product_id IN ({$productIds})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branchIds}) OR product_id IN ({$productIds})");
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_production_runs WHERE product_id IN ({$productIds}) OR destination_branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branchIds}) OR product_id IN ({$productIds})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$productIds})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branchIds})");
};
$cleanup();

$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

// ─── Static: the sources, the rule line and the row shape ──────────
$h->section('Static: sources and rule line');

$logFnStart = strpos($handlers, 'function dl_fetchProductionLedgerLog');
$logFn = '';
if ($logFnStart !== false) {
    $rest = substr($handlers, $logFnStart);
    $nextFn = strpos($rest, "\nfunction ", 1);
    $logFn = $nextFn === false ? $rest : substr($rest, 0, $nextFn);
}

$h->test(
    'AC1/AC2 the log builder reads dl_deliveries + items and dl_branch_receivings',
    $logFn !== ''
    && str_contains($logFn, 'FROM dl_deliveries d')
    && str_contains($logFn, 'dl_delivery_items di')
    && str_contains($logFn, 'FROM dl_branch_receivings br')
    && str_contains($logFn, 'dl_branch_receiving_items bri')
);

$h->test(
    'the send/receive rows do not come from audit_logs',
    $logFn !== ''
    && !str_contains($logFn, "action = 'create_delivery'")
    && !str_contains($logFn, "action = 'create_receiving'")
);

$h->test(
    'AC7/AC10 sends exclude voided documents and receivings require posted status',
    $logFn !== ''
    && str_contains($logFn, "d.status = 'posted'")
    && str_contains($logFn, "d2.status = 'posted'")
    && str_contains($logFn, "br.status = 'posted'")
    && !str_contains($logFn, "br.status <> 'voided'")
);

$h->test(
    'AC8 a S10 branch entry owns its delivery and is not listed again',
    $logFn !== ''
    && str_contains($logFn, '$coveredDeliveryIds')
    && str_contains($logFn, "if (isset(\$coveredDeliveryIds[\$deliveryId]))")
    && str_contains($logFn, "\$new['delivery_id']")
);

$h->test(
    'AC4 the source-of-truth rule line is present with its full content',
    str_contains($template, 'id="production-ledger-log-rule"')
    && str_contains($template, 'DR = what was sent (production)')
    && str_contains($template, 'Received = what the branch counted')
    && str_contains($template, 'the difference is an open item until one side is corrected')
    && str_contains($template, 'every correction is logged')
);

$h->test(
    'AC9 the log table header is still the original nine columns',
    substr_count(
        substr($template, strpos($template, 'id="production-ledger-log-table"'), 1400),
        '</th>'
    ) === 9
);

// ─── Runtime: crafted fixture ──────────────────────────────────────
$h->section('Runtime: crafted send and receive rows');

$tokens = dl_generateAuthTokens([
    'sub' => 'production_in_charge:27',
    'id' => 27,
    'username' => 'prod-rizal',
    'name' => 'Noah Omamalin',
    'role' => 'production_in_charge',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$renderSheet = static function (string $date, int $commissaryId): string {
    $_GET['date'] = $date;
    $_GET['commissary_id'] = (string)$commissaryId;
    ob_start();
    handleAdminCommissary();
    return (string)ob_get_clean();
};

$logRows = static function (string $html): array {
    $start = strpos($html, 'id="production-ledger-log-table"');
    if ($start === false) {
        return [];
    }
    $tbody = strpos($html, '<tbody>', $start);
    $end = strpos($html, '</tbody>', $tbody ?: $start);
    if ($tbody === false || $end === false) {
        return [];
    }
    $body = substr($html, $tbody, $end - $tbody);
    $rows = [];
    if (preg_match_all('/<tr[^>]*class="production-log-row"[^>]*>(.*?)<\/tr>/s', $body, $matches)) {
        foreach ($matches[1] as $tr) {
            $cells = [];
            if (preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $cellMatches)) {
                foreach ($cellMatches[1] as $cell) {
                    $cells[] = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
            $rows[] = $cells;
        }
    }
    return $rows;
};

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'S15-COMM', 'S15 Commissary', 'self_managed', null, 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'S15-BR', 'S15 Branch', 'commissary_supplied', $commissaryId, 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productId, 'S15-PROD', 'S15 Product', 'cake', 1]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$commissaryId, $productId]);

    // A movement so AC5 can prove an existing family row is untouched.
    $db->prepare(
        'INSERT INTO dl_production_movements
            (movement_uuid, movement_type, flow_mode, destination_branch_id, product_id, ledger_date, quantity, override_reason, source_payload, created_by_id, created_by_role, created_at)
         VALUES (?, "output", "production", ?, ?, ?, 7, "s15 fixture", ?, 27, "production_in_charge", ?)'
    )->execute([
        's15-move-0001-0000-0000-000000000001',
        $commissaryId,
        $productId,
        $date,
        json_encode(['before_produced' => 10, 'after_produced' => 17]),
        $date . ' 08:00:00',
    ]);

    $logBefore = dl_fetchProductionLedgerLog($db, $commissaryId, $date);
    $movementBefore = null;
    foreach ($logBefore as $entry) {
        if ($entry['source'] === 'movement') {
            $movementBefore = $entry;
        }
    }
    $h->test('AC5 the fixture movement is present before the delivery is added', $movementBefore !== null);

    // A legacy paper-captured send with no audit row at all.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at, remarks)
         VALUES (?, "commissary", NULL, "branch", ?, "DR-S15-0001", "posted", 27, 27, ?, "[captured-from-paper-dr]")'
    )->execute([$date, $branchId, $date . ' 09:15:00']);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 10, "pcs", 0)')
        ->execute([$deliveryId, $productId]);
    $deliveryItemId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status, posted_by, posted_at)
         VALUES (?, "commissary", ?, ?, "DR-S15-0001", 27, ?, ?, "posted", 27, ?)'
    )->execute([$branchId, $commissaryId, $deliveryId, $date . ' 09:40:00', $date, $date . ' 09:40:00']);
    $receivingId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (?, ?, ?, 10, "pcs")')
        ->execute([$receivingId, $deliveryItemId, $productId]);
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status)
         VALUES (?, "commissary", ?, ?, "DR-S15-0001", 27, ?, ?, "draft")'
    )->execute([$branchId, $commissaryId, $deliveryId, $date . ' 09:50:00', $date]);
    $draftReceivingId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (?, ?, ?, 100, "pcs")')
        ->execute([$draftReceivingId, $deliveryItemId, $productId]);

    $log = dl_fetchProductionLedgerLog($db, $commissaryId, $date);
    $sent = null;
    $received = null;
    $movementAfter = null;
    foreach ($log as $entry) {
        if ($entry['field'] === 'SENT') {
            $sent = $entry;
        } elseif ($entry['field'] === 'RECEIVED') {
            $received = $entry;
        } elseif ($entry['source'] === 'movement') {
            $movementAfter = $entry;
        }
    }

    $h->test(
        'AC1 the SENT row carries branch, quantity and the DR',
        $sent !== null
        && $sent['branch_name'] === 'S15 Branch'
        && (int)$sent['quantity'] === 10
        && $sent['after_display'] === '10'
        && str_contains($sent['reason'], 'DR DR-S15-0001')
        && str_contains($sent['reason'], 'single paper capture')
        && $sent['source'] === 'send'
    );
    $h->test(
        'AC2/AC10 only the posted RECEIVED row carries physical evidence; draft 100 is excluded',
        $received !== null
        && $received['who'] === 'Noah Omamalin'
        && $received['when'] === $date . ' 09:40:00'
        && (int)$received['quantity'] === 10
        && str_contains($received['reason'], 'DR DR-S15-0001')
        && str_contains($received['reason'], 'single paper capture · same encoder')
        && $received['source'] === 'receiving'
        && count(array_filter($log, static fn(array $e): bool => $e['field'] === 'RECEIVED')) === 1
    );
    $h->test(
        'AC5 the existing movement row is unchanged in content and order',
        $movementBefore !== null
        && $movementAfter !== null
        && $movementBefore === $movementAfter
    );

    // AC3: the real paper-captured day, through the full handler render.
    $realHtml = $renderSheet('2026-09-28', 0);
    $realRows = $logRows($realHtml);
    $realSent = null;
    $realReceived = null;
    foreach ($realRows as $cells) {
        $field = $cells[4] ?? '';
        if ($field === 'SENT' && str_contains($cells[7] ?? '', 'SEPT 28 2026')) {
            $realSent = $cells;
        }
        if ($field === 'RECEIVED' && str_contains($cells[7] ?? '', 'DR SEPT 28 2026')) {
            $realReceived = $cells;
        }
    }
    $h->test(
        'AC3 the real render for 2026-09-28 includes the paper SENT row',
        $realSent !== null
        && $realSent[3] === 'Miputak'
        && $realSent[6] === '— → 1594'
        && str_contains($realSent[7], 'DR SEPT 28 2026')
        && str_contains($realSent[7], 'single paper capture'),
        json_encode($realSent)
    );
    $h->test(
        'AC3 the real render includes the paper RECEIVED row with user 22 and timestamp',
        $realReceived !== null
        && $realReceived[0] === '2026-09-27 22:43:57'
        && $realReceived[1] === 'Bernalisa Dywatco'
        && $realReceived[3] === 'Miputak'
        && $realReceived[6] === '— → 1594',
        json_encode($realReceived)
    );
    $h->test(
        'AC4 the real render shows the source-of-truth rule and single-witness provenance',
        str_contains($realHtml, 'DR = what was sent (production)')
        && str_contains($realHtml, 'Received = what the branch counted')
        && str_contains($realHtml, 'every correction is logged')
        && str_contains($realHtml, 'single paper capture')
        && str_contains($realHtml, 'same encoder')
    );

    // AC6: a date with no deliveries and no receivings has no send/receive rows.
    $emptyLog = dl_fetchProductionLedgerLog($db, $commissaryId, '2020-08-08');
    $emptyEvents = array_filter($emptyLog, static fn(array $e): bool => in_array($e['field'], ['SENT', 'RECEIVED'], true));
    $h->test('AC6 a clean date renders no phantom send/receive rows', $emptyEvents === []);

    // AC7: voided rows are excluded.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, remarks)
         VALUES (?, "commissary", NULL, "branch", ?, "DR-S15-VOID", "voided", 27, "[captured-from-paper-dr]")'
    )->execute([$voidDate, $branchId]);
    $voidDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 4, "pcs", 0)')
        ->execute([$voidDeliveryId, $productId]);
    $voidItemId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status)
         VALUES (?, "commissary", ?, ?, "DR-S15-VOID", 27, ?, ?, "voided")'
    )->execute([$branchId, $commissaryId, $voidDeliveryId, $voidDate . ' 10:00:00', $voidDate]);
    $voidReceivingId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (?, ?, ?, 4, "pcs")')
        ->execute([$voidReceivingId, $voidItemId, $productId]);
    $db->prepare('INSERT INTO dl_deliveries (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by) VALUES (?, "commissary", ?, "branch", ?, "DR-S15-DRAFT", "draft", 27)')
        ->execute([$voidDate, $commissaryId, $branchId]);
    $draftDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 100, "pcs", 0)')
        ->execute([$draftDeliveryId, $productId]);
    $voidLog = dl_fetchProductionLedgerLog($db, $commissaryId, $voidDate);
    $voidEvents = array_filter($voidLog, static fn(array $e): bool => in_array($e['field'], ['SENT', 'RECEIVED'], true));
    $h->test('AC7/AC10 voided and draft deliveries/receivings are excluded', $voidEvents === []);

    // AC8: a S10 entry's delivery is represented by its BRANCH row, not a SENT row.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at, remarks)
         VALUES (?, "commissary", ?, "branch", ?, NULL, "posted", 27, 27, ?, "[daily-sheet-entry] new")'
    )->execute([$coveredDate, $commissaryId, $branchId, $coveredDate . ' 11:00:00']);
    $coveredDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot, remarks) VALUES (?, ?, 3, "pcs", 0, "daily_sheet_entry")')
        ->execute([$coveredDeliveryId, $productId]);
    $coveredItemId = (int)$db->lastInsertId();
    dl_auditLog(
        'production_ledger_change',
        $commissaryId,
        'dl_deliveries',
        $commissaryId . '-' . $productId . '-' . $coveredDate,
        ['field' => 'branch_qty', 'value' => 0],
        [
            'field' => 'branch_qty',
            'value' => 3,
            'quantity' => 3,
            'type' => 'correction',
            'reason_code' => 'manual_adjustment',
            'reason' => 's15 covered',
            'branch_id' => $branchId,
            'branch_name' => 'S15 Branch',
            'product_id' => $productId,
            'product_name' => 'S15 Product',
            'ledger_date' => $coveredDate,
            'delivery_id' => $coveredDeliveryId,
            'item_id' => $coveredItemId,
            'actor_name' => 'Noah Omamalin',
        ],
        's15 covered'
    );
    $coveredLog = dl_fetchProductionLedgerLog($db, $commissaryId, $coveredDate);
    $coveredSent = array_filter($coveredLog, static fn(array $e): bool => $e['field'] === 'SENT');
    $coveredBranch = array_filter($coveredLog, static fn(array $e): bool => $e['source'] === 'branch_entry');
    $h->test(
        'AC8 the S10 delivery is not double-listed as a SENT row',
        $coveredSent === [] && count($coveredBranch) === 1,
        'sent=' . count($coveredSent) . ' branch=' . count($coveredBranch)
    );
} finally {
    $cleanup();
}

$h->done();
