<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('pal_print_jobs_integration', TestHarness::MODE_INTEGRATION, 'palsystem.test');

foreach ([
    'modules/project-audit-ledger/module.json',
    'modules/project-audit-ledger/routes.php',
    'modules/project-audit-ledger/helpers.php',
    'modules/project-audit-ledger/handlers.php',
    'modules/project-audit-ledger/handlers/00-bootstrap.php',
    'modules/project-audit-ledger/handlers/05-auth.php',
    'modules/project-audit-ledger/handlers/58-printing.php',
    'modules/project-audit-ledger/handlers/75-users.php',
    'modules/project-audit-ledger/templates/project-audit-ledger/printing-shell.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/printing-jobs-printer.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/printing-jobs-admin.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/printing-job-form.disyl',
    'modules/project-audit-ledger/database/migrations/023_pal_print_jobs.sql',
] as $fp) {
    $h->fingerprint($fp);
}
$h->gap('Coverage', 'Browser-level redirect/header assertions remain in Playwright journey; this suite verifies handler outcomes directly.');

require_once __DIR__ . '/../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../modules/project-audit-ledger/handlers.php';

$db = palDb();
$rawDb = app()->dbForTenant((int)(app()->tenant()->current() ?? palTenantId()));
$tenantId = (int)(app()->tenant()->current() ?? palTenantId());
$token = app()->csrfRotate();
$_SESSION['_csrf_token'] = $token;

$run = (function_exists('runkit_function_redefine')) ? null : null;

function palPrintTestUser(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'tenant_id' => (int)$row['tenant_id'],
        'username' => (string)$row['username'],
        'email' => (string)($row['email'] ?? ''),
        'full_name' => (string)$row['full_name'],
        'name' => (string)$row['full_name'],
        'role' => (string)$row['role'],
        'token_version' => (int)($row['token_version'] ?? 0),
        'source' => 'module',
    ];
}

function palPrintTestCapture(callable $fn): string
{
    ob_start();
    $fn();
    return (string)ob_get_clean();
}

function palPrintTestJson(callable $fn): array
{
    $raw = palPrintTestCapture($fn);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok' => false, 'raw' => $raw];
}

$prefix = 'itest_print_' . substr((string)time(), -6);
$db->prepare("DELETE FROM pal_audit_logs WHERE tenant_id = :tid AND action LIKE 'pal.print_job.%'")->execute([':tid' => $tenantId]);
$db->prepare('DELETE FROM pal_print_jobs WHERE tenant_id = :tid AND job_number LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => strtoupper($prefix) . '%']);
$db->prepare('DELETE FROM pal_sale_items WHERE tenant_id = :tid AND particulars LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => $prefix . '%']);
$db->prepare('DELETE FROM pal_sales WHERE tenant_id = :tid AND sales_number LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => strtoupper($prefix) . '%']);
$db->prepare('DELETE FROM pal_materials WHERE tenant_id = :tid AND material_code LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => strtoupper($prefix) . '%']);
$db->prepare('DELETE FROM pal_clients WHERE tenant_id = :tid AND name LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => $prefix . '%']);
$db->prepare('DELETE FROM pal_users WHERE tenant_id = :tid AND username LIKE :prefix')->execute([':tid' => $tenantId, ':prefix' => $prefix . '%']);

$userStmt = $db->prepare('INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active, created_by) VALUES (:tid, :username, :email, :hash, :name, :role, 1, NULL)');
$users = [];
foreach ([
    'admin' => 'Admin',
    'supervisor' => 'Supervisor',
    'printer' => 'Printer',
    'encoder' => 'Encoder',
] as $role => $label) {
    $userStmt->execute([
        ':tid' => $tenantId,
        ':username' => $prefix . '_' . $role,
        ':email' => $prefix . '_' . $role . '@example.test',
        ':hash' => password_hash('password1234', PASSWORD_BCRYPT, ['cost' => 12]),
        ':name' => $label . ' ' . $prefix,
        ':role' => $role,
    ]);
    $users[$role] = [
        'id' => (int)$db->lastInsertId(),
        'tenant_id' => $tenantId,
        'username' => $prefix . '_' . $role,
        'email' => $prefix . '_' . $role . '@example.test',
        'full_name' => $label . ' ' . $prefix,
        'role' => $role,
        'token_version' => 0,
    ];
}

$db->prepare('INSERT INTO pal_clients (tenant_id, name, created_by) VALUES (:tid, :name, :created_by)')->execute([
    ':tid' => $tenantId,
    ':name' => $prefix . ' client',
    ':created_by' => $users['admin']['id'],
]);
$clientId = (int)$db->lastInsertId();

$db->prepare('INSERT INTO pal_materials (tenant_id, material_code, name, current_avg_cost, is_active, created_by) VALUES (:tid, :code, :name, 10.00, 1, :created_by)')->execute([
    ':tid' => $tenantId,
    ':code' => strtoupper($prefix) . '-MAT',
    ':name' => $prefix . ' vinyl',
    ':created_by' => $users['admin']['id'],
]);
$materialId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO pal_sales (tenant_id, sales_number, client_id, client_name, sales_date, gross_amount, discount_amount, tax_amount, invoice_number, status, created_by)
              VALUES (:tid, :sales_number, :client_id, :client_name, CURDATE(), 360.00, 0.00, 0.00, :invoice_number, 'issued', :created_by)")->execute([
    ':tid' => $tenantId,
    ':sales_number' => strtoupper($prefix) . '-SALE',
    ':client_id' => $clientId,
    ':client_name' => $prefix . ' client',
    ':invoice_number' => strtoupper($prefix) . '-INV',
    ':created_by' => $users['admin']['id'],
]);
$saleId = (int)$db->lastInsertId();

$db->prepare('INSERT INTO pal_sale_items (tenant_id, sale_id, material_id, particulars, width, height, uom, quantity, price_per_unit, price_per_sqft, line_total, sort_order) VALUES (:tid, :sale_id, :material_id, :particulars, 24.00, 36.00, :uom, 2.00, 0.00, 5.00, 360.00, 1)')->execute([
    ':tid' => $tenantId,
    ':sale_id' => $saleId,
    ':material_id' => $materialId,
    ':particulars' => $prefix . ' sourced sticker',
    ':uom' => 'in',
]);
$saleItemId = (int)$db->lastInsertId();

$h->section('Schema and capability wiring');
$roleColumn = $rawDb->query("SHOW COLUMNS FROM pal_users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
$roleEnum = (string)($roleColumn['Type'] ?? '');
$h->test('pal_users.role includes printer', str_contains($roleEnum, "'printer'"), $roleEnum);
$printTable = $rawDb->query("SHOW TABLES LIKE 'pal_print_jobs'")->fetchColumn();
$h->test('pal_print_jobs table exists', $printTable === 'pal_print_jobs');
$manifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/project-audit-ledger/module.json'), true);
$capIds = array_map(static fn(array $cap): string => (string)($cap['id'] ?? ''), $manifest['capabilities']['exposes'] ?? []);
$h->test('module.json exposes pal.print.read@1', in_array('pal.print.read@1', $capIds, true));
$h->test('module.json exposes pal.print.write@1', in_array('pal.print.write@1', $capIds, true));
$h->test('module.json owns pal_print_jobs', in_array('pal_print_jobs', $manifest['owns_tables'] ?? [], true));
$h->test('login redirect helper routes printer to printing', palLoginRedirectPath(['role' => 'printer']) === '/admin/project-audit-ledger/printing');

$h->section('Admin create print jobs');
app()->setUser(palPrintTestUser($users['admin']));
$_POST = [
    '_token' => $token,
    'job_number' => strtoupper($prefix) . '-MANUAL',
    'client_name' => $prefix . ' manual client',
    'material_id' => (string)$materialId,
    'material_label' => $prefix . ' manual vinyl',
    'width' => '24',
    'height' => '36',
    'size_unit' => 'in',
    'quantity' => '2',
    'cost' => '250.50',
];
$manualCreate = palPrintTestJson(static fn() => palApiPrintJobStore());
$manualId = (int)($manualCreate['id'] ?? 0);
$h->test('admin can create manual print job', ($manualCreate['ok'] ?? false) === true && $manualId > 0, json_encode($manualCreate));
$manualJob = palLoadPrintJob($tenantId, $manualId);
$h->test('manual print job stores client snapshot', ($manualJob['client_name'] ?? '') === $prefix . ' manual client');
$h->test('manual print job stores cost', (float)($manualJob['cost'] ?? 0) === 250.50, json_encode($manualJob));

$_POST = [
    '_token' => $token,
    'job_number' => strtoupper($prefix) . '-SOURCE',
    'sale_item_id' => (string)$saleItemId,
];
$sourceCreate = palPrintTestJson(static fn() => palApiPrintJobStore());
$sourceId = (int)($sourceCreate['id'] ?? 0);
$sourceJob = palLoadPrintJob($tenantId, $sourceId);
$h->test('admin can create print job from sale item snapshot', ($sourceCreate['ok'] ?? false) === true && $sourceId > 0, json_encode($sourceCreate));
$h->test('sourced print job snapshots sale item quantity', (float)($sourceJob['quantity'] ?? 0) === 2.0, json_encode($sourceJob));
$h->test('sourced print job snapshots sale item cost', (float)($sourceJob['cost'] ?? 0) === 360.0, json_encode($sourceJob));
$h->test('sourced print job snapshots size', ($sourceJob['display_size'] ?? '') === '24 x 36 in', json_encode($sourceJob));

$h->section('Printer completion flow');
app()->setUser(palPrintTestUser($users['printer']));
$_POST = [
    '_token' => $token,
    'comment_option_key' => 'needs_revision',
    'comment_text' => 'Trim left edge',
];
$complete = palPrintTestJson(static fn() => palApiPrintJobComplete(['id' => (string)$manualId]));
$completedJob = palLoadPrintJob($tenantId, $manualId);
$h->test('printer complete action returns ok', ($complete['ok'] ?? false) === true, json_encode($complete));
$h->test('complete action sets status done', ($completedJob['status'] ?? '') === 'done');
$h->test('complete action sets completed_by', (int)($completedJob['completed_by'] ?? 0) === (int)$users['printer']['id']);
$h->test('complete action sets completed_at', !empty($completedJob['completed_at']));
$h->test('complete action stores comment option', ($completedJob['comment_option_key'] ?? '') === 'needs_revision');
$h->test('complete action stores comment text', ($completedJob['comment_text'] ?? '') === 'Trim left edge');

$_POST = [
    '_token' => $token,
    'comment_option_key' => 'waiting_material',
    'comment_text' => 'Hold until laminate arrives',
];
$commentUpdate = palPrintTestJson(static fn() => palApiPrintJobComment(['id' => (string)$sourceId]));
$commentedJob = palLoadPrintJob($tenantId, $sourceId);
$h->test('printer can save comment on pending job', ($commentUpdate['ok'] ?? false) === true, json_encode($commentUpdate));
$h->test('comment action updates preset', ($commentedJob['comment_option_key'] ?? '') === 'waiting_material');
$h->test('comment action updates free text', ($commentedJob['comment_text'] ?? '') === 'Hold until laminate arrives');

$printerPage = palPrintTestCapture(static fn() => palPagePrintJobList());
$h->test('printer list excludes done jobs', !str_contains($printerPage, strtoupper($prefix) . '-MANUAL'), $printerPage);
$h->test('printer list shows pending jobs', str_contains($printerPage, strtoupper($prefix) . '-SOURCE'), $printerPage);

$h->section('Admin and supervisor visibility');
app()->setUser(palPrintTestUser($users['supervisor']));
$supervisorPage = palPrintTestCapture(static fn() => palPagePrintJobList());
$h->test('supervisor list includes done job', str_contains($supervisorPage, strtoupper($prefix) . '-MANUAL'));
$h->test('supervisor list includes pending job', str_contains($supervisorPage, strtoupper($prefix) . '-SOURCE'));
$h->test('supervisor list shows client name', str_contains($supervisorPage, $prefix . ' manual client'));
$h->test('supervisor list shows size', str_contains($supervisorPage, '24 x 36 in'));
$h->test('supervisor list shows cost', str_contains($supervisorPage, '₱250.50') || str_contains($supervisorPage, '&#8369;250.50'), $supervisorPage);

app()->setUser(palPrintTestUser($users['admin']));
$adminPage = palPrintTestCapture(static fn() => palPagePrintJobList());
$h->test('admin list includes comment preset label', str_contains($adminPage, 'Needs revision') && str_contains($adminPage, 'Waiting for material'), $adminPage);

$h->section('Unauthorized encoder access');
app()->setUser(palPrintTestUser($users['encoder']));
$encoderRead = pal_cap_print_read_1([]);
$encoderWrite = pal_cap_print_write_1([]);
$h->test('encoder lacks print read capability', ($encoderRead['ok'] ?? true) === false, json_encode($encoderRead));
$h->test('encoder lacks print write capability', ($encoderWrite['ok'] ?? true) === false, json_encode($encoderWrite));
$forbiddenPage = false;
try {
    palPagePrintingHome();
} catch (DomainException $e) {
    $forbiddenPage = $e->getMessage() === 'Forbidden';
}
$h->test('encoder access to /printing is forbidden', $forbiddenPage);

$auditCountStmt = $db->prepare("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = :tid AND action IN ('pal.print_job.created','pal.print_job.completed','pal.print_job.comment_updated')");
$auditCountStmt->execute([':tid' => $tenantId]);
$h->section('Audit trail');
$h->test('print mutations write audit logs', (int)$auditCountStmt->fetchColumn() >= 4);

$h->done();

echo (string)ob_get_clean();
