<?php

declare(strict_types=1);

/**
 * Daily Ledger — S12 received-vs-sent on the Daily Sheet.
 *
 * The branch cell is what production SENT. The cashier separately records what
 * ARRIVED. This suite pins the one additive display change plus the correctness
 * rule that decides whether it is useful or harmful:
 *
 *   - AC1  each branch cell shows the sent quantity (unchanged) plus the received
 *   - AC2  a received != sent difference is signed and visually distinguishable
 *   - AC3  a delivery with NO receiving row renders as pending, NEVER a zero
 *          difference. Proven with a crafted fixture, not by inspection.
 *   - AC4  the DR for that product+branch+date is visible on the sheet
 *   - AC5  the sheet's VARIANCE column (physical count) is untouched
 *   - AC6  book_balance is unchanged: beg + addtl - sum(branch cells)
 *
 * Sent comes from dl_delivery_items.quantity; received comes from
 * dl_branch_receiving_items.quantity_received joined on delivery_item_id. The
 * suite asserts the exception-only dl_delivery_variance_flags table is NOT
 * consulted and the COALESCE(quantity_received, di.quantity) shortcut is NOT
 * reused. Tenant 207 (baron-001).
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-received-vs-sent', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

// Rendering the sheet compiles the DiSyL template, which emits one info line
// (disyl.compile.phases). It is not a defect, but it must be named explicitly
// rather than tolerated as an unexplained log write.
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

$commissaryId = 98701;
$branchId = 98702;
$productId = 98701;
$date = '2020-06-06';

$cleanup = static function () use ($db, $commissaryId, $branchId, $productId): void {
    $branchIds = "{$commissaryId},{$branchId}";
    $productIds = "{$productId}";
    // Receivings first: deleting a delivery item sets the receiving item's FK to
    // NULL instead of cascading it, so the receiving header must go first.
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE product_id IN ({$productIds}) OR delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branchIds})");
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
$sheetTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

// ─── Source: the read is from the items, not the flags table ──────
$h->section('Source: sent and received come from the item rows');

$receivingFnStart = strpos($handlers, 'function dl_fetchProductionSheetReceivingMatrix');
$receivingFn = $receivingFnStart === false
    ? ''
    : substr($handlers, $receivingFnStart, 3200);

$h->test(
    'AC1 a receiving-matrix reader exists and joins receiving items on delivery_item_id',
    $receivingFn !== ''
    && str_contains($receivingFn, 'dl_branch_receiving_items bri')
    && str_contains($receivingFn, 'rcv.delivery_item_id = di.id')
    && str_contains($receivingFn, 'di.quantity AS sent')
    && str_contains($receivingFn, 'quantity_received')
);

$h->test(
    'the reader does NOT use the exception-only dl_delivery_variance_flags table',
    $receivingFn !== '' && !str_contains($receivingFn, 'dl_delivery_variance_flags')
);

$h->test(
    'the reader does NOT reuse COALESCE(quantity_received, di.quantity)',
    $receivingFn !== ''
    && !preg_match('/COALESCE\s*\(\s*(?:ri|rcv)\.quantity_received\s*,\s*di\.quantity/i', $receivingFn)
);

$h->test(
    'the reader only counts posted receivings (draft is not physical evidence)',
    $receivingFn !== ''
    && str_contains($receivingFn, "br.status = 'posted'")
    && !str_contains($receivingFn, "br.status <> 'voided'")
);

$h->test(
    'the reader exposes a pending flag and a DR list',
    $receivingFn !== ''
    && str_contains($receivingFn, "'pending' => false")
    && str_contains($receivingFn, "'dr_numbers'")
);

// ─── Source: the handler keeps the three states apart ─────────────
$h->section('Source: pending, full and variance stay distinct');

$h->test(
    'AC3 the handler makes an unreceived delivery null (pending), not a zero difference',
    str_contains($handlers, '$receivedPending = $receiving !== null && !empty($receiving[\'pending\']);')
    && str_contains($handlers, '$deliveryDiff = (!$receivedPending && $receivedQty !== null)')
    && str_contains($handlers, '? (($notIndependentlyCounted || $countBasisUnresolved) ? null : ($receivedQty - $quantity))')
);

$h->test(
    'AC2 the handler only shows a difference when it is non-zero, and signs it',
    str_contains($handlers, "\$deliveryDiff === null || \$deliveryDiff === 0")
    && str_contains($handlers, "(\$deliveryDiff > 0 ? '+' : '') . \$deliveryDiff")
    && str_contains($handlers, "'delivery_short' => \$deliveryDiff !== null && \$deliveryDiff < 0")
    && str_contains($handlers, "'delivery_over' => \$deliveryDiff !== null && \$deliveryDiff > 0")
);

// ─── Source: the template renders sent + received + diff + DR ─────
$h->section('Source: the branch cell renders the reconciliation');

$h->test(
    'AC1 the branch cell shows the received quantity next to the sent value',
    str_contains($sheetTpl, 'rcv {cell.received_qty}')
    && str_contains($sheetTpl, 'class="production-branch-receipt"')
    && str_contains($sheetTpl, 'class="production-branch-value"')
);

$h->test(
    'a cell with no delivery at all shows no reconciliation',
    str_contains($sheetTpl, '{if cell.has_delivery}')
);

$h->test(
    'AC3 a pending delivery renders its own state and never a zero difference',
    str_contains($sheetTpl, 'data-receipt-state="pending"')
    && str_contains($sheetTpl, 'production-branch-receipt-pending')
    && str_contains($sheetTpl, 'Cashier has not received this delivery yet')
);

$h->test(
    'AC2 the signed difference is visually distinct for short and over',
    str_contains($sheetTpl, 'production-branch-diff-short')
    && str_contains($sheetTpl, 'production-branch-diff-over')
    && str_contains($sheetTpl, 'data-delivery-diff="{cell.delivery_diff}"')
);

$h->test(
    'AC4 the delivery receipt number is rendered for the cell',
    str_contains($sheetTpl, 'class="production-branch-dr"')
    && str_contains($sheetTpl, 'DR {cell.dr_display}')
);

// ─── Source: untouched neighbours ─────────────────────────────────
$h->section('Source: the physical VARIANCE and book_balance are untouched');

$h->test(
    'AC5 the sheet still renders the physical VARIANCE cell unchanged',
    str_contains($sheetTpl, 'class="text-right production-variance"')
    && str_contains($sheetTpl, '{row.calc_variance}')
);

$h->test(
    'AC6 book_balance is still beg + addtl - sum(branch cells) - wastage',
    str_contains($handlers, "'book_balance' => \$begQty + \$addtlQty - \$total - \$wastageQty,")
);

// ─── Runtime: craft an unreceived delivery, then a short one ──────
$h->section('Runtime: crafted delivery fixtures');

$tokens = dl_generateAuthTokens([
    'sub' => 'production_in_charge:27',
    'id' => 27,
    'username' => 'prod-rizal',
    'name' => 'Prod Rizal',
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

$fixtureCell = static function (string $html, int $branchId): string {
    if (!preg_match(
        '/<td class="text-right production-branch-cell" data-branch-id="' . preg_quote((string)$branchId, '/') . '">(.*?)<\/td>/s',
        $html,
        $match
    )) {
        return '';
    }
    return (string)$match[1];
};

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'S12-COMM', 'S12 Commissary', 'self_managed', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'S12-BR', 'S12 Branch', 'commissary_supplied', 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productId, 'S12-PROD', 'S12 Received Product', 'cake', 1]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$commissaryId, $productId]);

    // A posted commissary -> branch delivery of 10 with NO receiving row.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$date, 'commissary', $commissaryId, 'branch', $branchId, 'DR-S12-0001', 'posted', 1]);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, ?, ?, 0)')
        ->execute([$deliveryId, $productId, 10, 'pcs']);
    $deliveryItemId = (int)$db->lastInsertId();

    // 1) No receiving row: the reader must flag pending, and the rendered cell
    //    must carry the pending marker instead of a zero difference.
    $matrix = dl_fetchProductionSheetReceivingMatrix($db, $date, $commissaryId);
    $cell = $matrix[$productId][$branchId] ?? [];
    $h->test(
        'AC3 an unreceived delivery is pending with sent=10 and no numeric receipt',
        (int)($cell['sent'] ?? -1) === 10
        && !empty($cell['pending'])
        && (int)($cell['received'] ?? -1) === 0
        && !array_key_exists('variance', $cell),
        json_encode($cell)
    );

    $html = $renderSheet($date, $commissaryId);
    $cellHtml = $fixtureCell($html, $branchId);
    $h->test(
        'AC3 the rendered cell shows pending, not a zero difference',
        $cellHtml !== ''
        && str_contains($cellHtml, 'data-receipt-state="pending"')
        && str_contains($cellHtml, 'pending')
        && !str_contains($cellHtml, 'data-delivery-diff='),
        $cellHtml
    );
    $h->test(
        'AC4 the rendered cell shows the DR for the product+branch+date',
        $cellHtml !== '' && str_contains($cellHtml, 'DR-S12-0001'),
        $cellHtml
    );

    // 2) A short receipt of 8 against 10: signed -2, visually distinct.
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_ledger_date, status, posted_by, posted_at, count_basis)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), "independently_counted")'
    )->execute([$branchId, 'commissary', $commissaryId, $deliveryId, 'DR-S12-0001', $date, 'posted', 1]);
    $receivingId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (?, ?, ?, ?, ?)')
        ->execute([$receivingId, $deliveryItemId, $productId, 8, 'pcs']);
    $receivingItemId = (int)$db->lastInsertId();

    $matrix = dl_fetchProductionSheetReceivingMatrix($db, $date, $commissaryId);
    $cell = $matrix[$productId][$branchId] ?? [];
    $h->test(
        'AC2 a short receipt records received=8 against sent=10 and stops being pending',
        (int)($cell['received'] ?? -1) === 8
        && empty($cell['pending'])
        && (int)($cell['sent'] ?? -1) === 10,
        json_encode($cell)
    );

    $html = $renderSheet($date, $commissaryId);
    $cellHtml = $fixtureCell($html, $branchId);
    $h->test(
        'AC2 the rendered short receipt shows rcv 8 and a signed -2 difference',
        $cellHtml !== ''
        && str_contains($cellHtml, 'rcv 8')
        && str_contains($cellHtml, 'data-delivery-diff="-2"')
        && str_contains($cellHtml, 'production-branch-diff-short'),
        $cellHtml
    );

    // 3) Received in full: no difference is shown.
    $db->prepare('UPDATE dl_branch_receiving_items SET quantity_received = 10 WHERE id = :id')
        ->execute([':id' => $receivingItemId]);
    $matrix = dl_fetchProductionSheetReceivingMatrix($db, $date, $commissaryId);
    $cell = $matrix[$productId][$branchId] ?? [];
    $h->test(
        'a full receipt records received=10 and is not pending',
        (int)($cell['received'] ?? -1) === 10 && empty($cell['pending']),
        json_encode($cell)
    );

    $html = $renderSheet($date, $commissaryId);
    $cellHtml = $fixtureCell($html, $branchId);
    $h->test(
        'AC2 a full receipt shows the sent and received figures with no difference',
        $cellHtml !== ''
        && str_contains($cellHtml, 'rcv 10')
        && !str_contains($cellHtml, 'data-delivery-diff='),
        $cellHtml
    );

    // 4) Over receipt of 12 against a sent 10: the positive signed difference.
    $db->prepare('UPDATE dl_branch_receiving_items SET quantity_received = 12 WHERE id = :id')
        ->execute([':id' => $receivingItemId]);
    $matrix = dl_fetchProductionSheetReceivingMatrix($db, $date, $commissaryId);
    $cell = $matrix[$productId][$branchId] ?? [];
    $h->test(
        'an over receipt records received=12 against sent=10 and is not pending',
        (int)($cell['received'] ?? -1) === 12
        && empty($cell['pending'])
        && (int)($cell['sent'] ?? -1) === 10,
        json_encode($cell)
    );
    $html = $renderSheet($date, $commissaryId);
    $cellHtml = $fixtureCell($html, $branchId);
    $h->test(
        'the rendered over receipt shows rcv 12 and a signed +2 difference',
        $cellHtml !== ''
        && str_contains($cellHtml, 'rcv 12')
        && str_contains($cellHtml, 'data-delivery-diff="2"')
        && str_contains($cellHtml, '>+2</div>')
        && str_contains($cellHtml, 'production-branch-diff-over'),
        $cellHtml
    );

    // A voided receiving must read as pending again, never as a clean zero.
    $db->execute("UPDATE dl_branch_receivings SET status = 'voided' WHERE id = {$receivingId}");
    $matrix = dl_fetchProductionSheetReceivingMatrix($db, $date, $commissaryId);
    $cell = $matrix[$productId][$branchId] ?? [];
    $h->test(
        'AC3 a voided receiving is pending again and never reported as a zero difference',
        !empty($cell['pending']) && (int)($cell['received'] ?? -1) === 0,
        json_encode($cell)
    );
} finally {
    $cleanup();
}

$h->test(
    'AC3 fixture cleanup leaves only the suite\'s own ledger and movement rows absent',
    (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$commissaryId},{$branchId}) OR product_id IN ({$productId})")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM dl_production_runs WHERE destination_branch_id IN ({$commissaryId},{$branchId}) OR product_id IN ({$productId})")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id IN ({$commissaryId},{$branchId}) OR product_id IN ({$productId})")->fetchColumn() === 0
);

$h->done();
