<?php
/**
 * PAL Project Completion Flow — Integration Regression Tests
 *
 * Tests the completeProject() method for idempotency, invoice auto-creation,
 * client snapshot behavior, precondition enforcement, and duplicate prevention.
 *
 * Usage: php tests/PalProjectCompletionTest.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────
require_once __DIR__ . '/../bootstrap.php';

// Load PAL module files
require_once __DIR__ . '/../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../modules/project-audit-ledger/handlers.php';

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$passed = 0;
$failed = 0;
$errors = [];

function btAdmin(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
        return;
    }
    $failed++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== PAL PROJECT COMPLETION TEST ===\n\n";

$db = app()->db();

// ── Run PAL migrations against the test DB ────────────────────────
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('project-audit-ledger');

// ── Create ModuleDB for PAL so the service can run ────────────────
$ownsTables = [
    'pal_users', 'pal_password_resets', 'pal_projects', 'pal_project_items',
    'pal_project_types', 'pal_clients', 'pal_suppliers', 'pal_materials',
    'pal_material_categories', 'pal_units', 'pal_inventory_locations',
    'pal_inventory_movements', 'pal_inventory_balances', 'pal_purchases',
    'pal_purchase_items', 'pal_material_issuances', 'pal_material_issuance_items',
    'pal_material_returns', 'pal_expenses', 'pal_expense_categories',
    'pal_sales', 'pal_collections', 'pal_fabrication_allocations',
    'pal_fabrication_weekly_dues', 'pal_fabrication_payments', 'pal_team_leads',
    'pal_approvals', 'pal_attachments', 'pal_audit_logs', 'pal_report_exports',
    'pal_settings', 'pal_quotations', 'pal_quotation_items', 'pal_sale_items',
    'pal_cash_advances', 'pal_otp_codes', 'pal_mobilization_requests',
];
$readsTables = [
    'audit_logs', 'attendance_groups', 'attendance_group_members',
    'attendance_wage_users', 'employee_profiles', 'attendance_records',
];
$palModuleDb = new \Ikabud\Kernel\Contracts\ModuleDB($db, 'project-audit-ledger', $ownsTables, $readsTables);

// ── Test tenant ID ────────────────────────────────────────────────
// Use a high tenant ID to avoid conflicts with existing data.
// Insert a test tenant + DB connection record so dbForTenant() can
// resolve it. We point it at the same DB as app()->db().
$testTenantId = 999901;

// ── Clean up any leftover data from a prior interrupted run ────────
$cleanupTables = [
    'pal_sale_items', 'pal_collections', 'pal_sales', 'pal_project_items',
    'pal_projects', 'pal_clients', 'pal_project_types',
];
foreach ($cleanupTables as $tbl) {
    $db->exec("DELETE FROM {$tbl} WHERE tenant_id = {$testTenantId}");
}

// ── Helper: create a test client ──────────────────────────────────
function createTestClient(PDO $db, int $tenantId, string $suffix): int
{
    $stmt = $db->prepare(
        "INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address)
         VALUES (:tid, :name, :contact, :email, :phone, :address)"
    );
    $stmt->execute([
        ':tid' => $tenantId,
        ':name' => "Test Client {$suffix}",
        ':contact' => "Contact {$suffix}",
        ':email' => "client{$suffix}@example.com",
        ':phone' => "+63-900-{$suffix}",
        ':address' => "{$suffix} Test Street, Manila",
    ]);
    return (int)$db->lastInsertId();
}

// ── Helper: create a test project ─────────────────────────────────
function createTestProject(PDO $db, int $tenantId, string $suffix, ?int $clientId = null, float $contractAmount = 50000.00): int
{
    $projectId = 'PJ-' . date('Ymd') . '-' . $suffix;
    $joNum = 'JO-' . date('Ymd') . '-' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO pal_projects
            (tenant_id, project_id, job_order_number, jo_type, title, client_id,
             contract_amount, estimated_cost, status, created_by)
         VALUES
            (:tid, :pid, :jo, 'contract', :title, :cid,
             :ca, :est, 'draft', :cb)"
    );
    $stmt->execute([
        ':tid' => $tenantId,
        ':pid' => $projectId,
        ':jo' => $joNum,
        ':title' => "Test Project {$suffix}",
        ':cid' => $clientId,
        ':ca' => $contractAmount,
        ':est' => 30000.00,
        ':cb' => 1,
    ]);
    return (int)$db->lastInsertId();
}

// ── Helper: get sale count for a project ──────────────────────────
function saleCountForProject(PDO $db, int $tenantId, int $projectId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid");
    $stmt->execute([':pid' => $projectId, ':tid' => $tenantId]);
    return (int)$stmt->fetchColumn();
}

// ── Test Data ─────────────────────────────────────────────────────
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

$projectIds = [];
$clientIds = [];
$saleIds = [];

try {
    // ── 1. Idempotency: Complete a project twice ─────────────────
    echo "─── 1. Idempotency (complete twice) ───\n";

    $clientIds['idem'] = createTestClient($db, $testTenantId, "IDEM-{$suffix}");
    $projectIds['idem'] = createTestProject($db, $testTenantId, "IDEM-{$suffix}", $clientIds['idem'], 75000.00);

    $svc = new palProjectService($palModuleDb, $testTenantId, 1);

    $firstResult = $svc->completeProject($projectIds['idem']);
    btAdmin('first completeProject() returns true', $firstResult === true);

    // Verify project is completed
    $projStmt = $db->prepare("SELECT status, actual_completion_date FROM pal_projects WHERE id = :id");
    $projStmt->execute([':id' => $projectIds['idem']]);
    $proj = $projStmt->fetch(PDO::FETCH_ASSOC);
    btAdmin('project status = completed after first call', ($proj['status'] ?? '') === 'completed', $proj['status'] ?? 'null');
    btAdmin('actual_completion_date is set', !empty($proj['actual_completion_date']), $proj['actual_completion_date'] ?? 'null');

    $secondResult = $svc->completeProject($projectIds['idem']);
    btAdmin('second completeProject() returns true (idempotent)', $secondResult === true);

    // ── 2. Invoice creation on completion ─────────────────────────
    echo "\n─── 2. Invoice creation on completion ───\n";

    $clientIds['inv'] = createTestClient($db, $testTenantId, "INV-{$suffix}");
    $projectIds['inv'] = createTestProject($db, $testTenantId, "INV-{$suffix}", $clientIds['inv'], 120000.00);

    $svc2 = new palProjectService($palModuleDb, $testTenantId, 1);
    $result2 = $svc2->completeProject($projectIds['inv']);
    btAdmin('completeProject() returns true', $result2 === true);

    $saleStmt = $db->prepare("SELECT * FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid");
    $saleStmt->execute([':pid' => $projectIds['inv'], ':tid' => $testTenantId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    btAdmin('sale record exists for completed project', is_array($sale) && !empty($sale), json_encode($sale, JSON_UNESCAPED_SLASHES));
    if (is_array($sale)) {
        btAdmin('sale gross_amount matches contract_amount', (float)($sale['gross_amount'] ?? 0) === 120000.00, (string)($sale['gross_amount'] ?? 0));
        btAdmin('sale status is "issued"', ($sale['status'] ?? '') === 'issued', $sale['status'] ?? 'null');
        $saleIds['inv'] = (int)$sale['id'];
    }

    // ── 3. Client snapshot on completion ──────────────────────────
    echo "\n─── 3. Client snapshot on completion ───\n";

    $clientIds['snap'] = createTestClient($db, $testTenantId, "SNAP-{$suffix}");
    // Update client with distinctive snapshot values
    $db->prepare("UPDATE pal_clients SET name = :n, contact_person = :cp, email = :e, phone = :ph, address = :a WHERE id = :id")
        ->execute([
            ':n' => 'Snapshot Client Co.',
            ':cp' => 'Juan dela Cruz',
            ':e' => 'juan@snapshot.com',
            ':ph' => '+63-917-888-9999',
            ':a' => '456 Snapshot Ave, Makati',
            ':id' => $clientIds['snap'],
        ]);

    $projectIds['snap'] = createTestProject($db, $testTenantId, "SNAP-{$suffix}", $clientIds['snap'], 95000.00);

    $svc3 = new palProjectService($palModuleDb, $testTenantId, 1);
    $result3 = $svc3->completeProject($projectIds['snap']);
    btAdmin('completeProject() returns true', $result3 === true);

    $snapSaleStmt = $db->prepare("SELECT * FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid");
    $snapSaleStmt->execute([':pid' => $projectIds['snap'], ':tid' => $testTenantId]);
    $snapSale = $snapSaleStmt->fetch(PDO::FETCH_ASSOC);
    btAdmin('sale record exists for snapshot project', is_array($snapSale) && !empty($snapSale));
    if (is_array($snapSale)) {
        btAdmin('client_name is snapshotted', ($snapSale['client_name'] ?? '') === 'Snapshot Client Co.', $snapSale['client_name'] ?? 'null');
        btAdmin('client_contact is snapshotted', ($snapSale['client_contact'] ?? '') === 'Juan dela Cruz', $snapSale['client_contact'] ?? 'null');
        btAdmin('client_email is snapshotted', ($snapSale['client_email'] ?? '') === 'juan@snapshot.com', $snapSale['client_email'] ?? 'null');
        btAdmin('client_phone is snapshotted', ($snapSale['client_phone'] ?? '') === '+63-917-888-9999', $snapSale['client_phone'] ?? 'null');
        btAdmin('client_address is snapshotted', ($snapSale['client_address'] ?? '') === '456 Snapshot Ave, Makati', $snapSale['client_address'] ?? 'null');

        // Modify client record after completion
        $db->prepare("UPDATE pal_clients SET name = 'Modified Name' WHERE id = :id")
            ->execute([':id' => $clientIds['snap']]);

        // Re-read sale to verify snapshot is unchanged
        $snapSaleStmt->execute([':pid' => $projectIds['snap'], ':tid' => $testTenantId]);
        $snapSale2 = $snapSaleStmt->fetch(PDO::FETCH_ASSOC);
        btAdmin('client snapshot is immutable after completion',
            ($snapSale2['client_name'] ?? '') === 'Snapshot Client Co.',
            $snapSale2['client_name'] ?? 'null');

        $saleIds['snap'] = (int)$snapSale['id'];
    }

    // ── 4. Complete project without client ────────────────────────
    echo "\n─── 4. Complete project without client ───\n";

    $projectIds['noclient'] = createTestProject($db, $testTenantId, "NOCLIENT-{$suffix}", null, 30000.00);

    $svc4 = new palProjectService($palModuleDb, $testTenantId, 1);
    $threwException = false;
    try {
        $svc4->completeProject($projectIds['noclient']);
    } catch (InvalidArgumentException $e) {
        $threwException = true;
        btAdmin('InvalidArgumentException thrown: ' . $e->getMessage(), true);
    } catch (Throwable $e) {
        btAdmin('wrong exception type thrown', false, get_class($e) . ': ' . $e->getMessage());
        $threwException = true;
    }
    if (!$threwException) {
        btAdmin('InvalidArgumentException thrown', false, 'No exception thrown');
    }

    // Verify project was NOT completed
    $ncStmt = $db->prepare("SELECT status FROM pal_projects WHERE id = :id");
    $ncStmt->execute([':id' => $projectIds['noclient']]);
    $ncStatus = $ncStmt->fetchColumn();
    btAdmin('project status remains unchanged', ($ncStatus ?: '') !== 'completed', $ncStatus ?: 'null');

    // ── 5. Duplicate invoice prevention ───────────────────────────
    echo "\n─── 5. Duplicate invoice prevention ───\n";

    $clientIds['dup'] = createTestClient($db, $testTenantId, "DUP-{$suffix}");
    $projectIds['dup'] = createTestProject($db, $testTenantId, "DUP-{$suffix}", $clientIds['dup'], 60000.00);

    $svc5 = new palProjectService($palModuleDb, $testTenantId, 1);

    // First completion
    $result5a = $svc5->completeProject($projectIds['dup']);
    btAdmin('first completeProject() returns true', $result5a === true);

    $saleCount1 = saleCountForProject($db, $testTenantId, $projectIds['dup']);
    btAdmin('one sale exists after first completion', $saleCount1 === 1, "count={$saleCount1}");

    // Reset project status to 'draft' directly (bypassing service guards)
    // to simulate a scenario where the app-level duplicate check must prevent
    // a second invoice
    $db->prepare("UPDATE pal_projects SET status = 'draft' WHERE id = :id")
        ->execute([':id' => $projectIds['dup']]);

    // Second completion attempt — the FOR UPDATE lock + COUNT(*) check
    // inside the transaction should prevent creating a second sale
    $result5b = $svc5->completeProject($projectIds['dup']);
    btAdmin('second completeProject() returns true (sale check prevented duplicate)', $result5b === true);

    $saleCount2 = saleCountForProject($db, $testTenantId, $projectIds['dup']);
    btAdmin('no duplicate sale created after reset + re-completion', $saleCount2 === 1, "count={$saleCount2}");

    // Also verify the unique constraints on sales_number and invoice_number
    // (migration 016) would catch any attempted duplicate at the DB level
    $saleStmtDup = $db->prepare("SELECT sales_number, invoice_number FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid");
    $saleStmtDup->execute([':pid' => $projectIds['dup'], ':tid' => $testTenantId]);
    $existingSale = $saleStmtDup->fetch(PDO::FETCH_ASSOC);
    if (is_array($existingSale)) {
        // Verify unique constraint exists
        $constraintStmt = $db->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'pal_sales'
              AND CONSTRAINT_TYPE = 'UNIQUE'
        ");
        $constraints = $constraintStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasSalesNumUq = in_array('uq_pal_sales_number', $constraints, true);
        $hasInvNumUq = in_array('uq_pal_invoice_number', $constraints, true);
        btAdmin('uq_pal_sales_number unique constraint exists', $hasSalesNumUq);
        btAdmin('uq_pal_invoice_number unique constraint exists', $hasInvNumUq);
    }

} finally {
    // ── Cleanup ───────────────────────────────────────────────────
    $allIds = array_unique(array_merge($saleIds, $projectIds, $clientIds));

    // Delete in FK-safe order (children first)
    foreach ($saleIds as $sid) {
        $db->prepare('DELETE FROM pal_sale_items WHERE sale_id = ?')->execute([$sid]);
        $db->prepare('DELETE FROM pal_collections WHERE sales_id = ?')->execute([$sid]);
        $db->prepare('DELETE FROM pal_sales WHERE id = ?')->execute([$sid]);
    }
    foreach ($projectIds as $pid) {
        $db->prepare('DELETE FROM pal_project_items WHERE project_id = ?')->execute([$pid]);
        $db->prepare('DELETE FROM pal_projects WHERE id = ?')->execute([$pid]);
    }
    foreach ($clientIds as $cid) {
        $db->prepare('DELETE FROM pal_clients WHERE id = ?')->execute([$cid]);
    }
}

// ── Log checks (filter known palAudit noise in test context) ─────
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
// palAudit depends on module() which isn't available in test bootstrap — ignore those lines
$appLogErrors = array_filter(explode("\n", $appLog), static fn(string $line): bool => $line !== '' && !str_contains($line, 'palAudit failed'));
btAdmin('no app.log errors', $appLogErrors === [], implode('; ', array_slice($appLogErrors, 0, 3)));
btAdmin('no error.log errors', $errorLog === '', substr($errorLog, 0, 500));

// ── Summary ───────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$passed} passed, {$failed} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($failed > 0 ? 1 : 0);
