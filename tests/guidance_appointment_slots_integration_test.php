<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/api/appointments/slots';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function unexpectedAppLogLines(string $content): array
{
    return array_values(array_filter(explode("\n", $content), static function (string $line): bool {
        if (trim($line) === '') {
            return false;
        }

        return str_contains($line, '[error]') || str_contains($line, '[critical]');
    }));
}

function hasGuidanceTenantSchema(PDO $db): bool
{
    try {
        $users = $db->query("SHOW TABLES LIKE 'gm_users'");
        $availability = $db->query("SHOW TABLES LIKE 'gm_counselor_availability'");
        return (bool)($users && $users->fetchColumn()) && (bool)($availability && $availability->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function resolveGuidanceTenant(): array
{
    $controlDb = app()->controlDb();
    $stmt = $controlDb->query(
        "SELECT t.id, COALESCE(d.domain, '') AS domain\n"
        . "FROM kernel_tenants t\n"
        . "LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id\n"
        . "WHERE t.status = 'active' AND t.entry_module_id = 'guidance'\n"
        . "ORDER BY t.id ASC"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $tenantId = (int)($row['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }

        $tenantDb = app()->dbForTenant($tenantId);
        if (!$tenantDb instanceof PDO || !hasGuidanceTenantSchema($tenantDb)) {
            continue;
        }

        return [
            'tenant_id' => $tenantId,
            'domain' => trim((string)($row['domain'] ?? '')),
        ];
    }

    return ['tenant_id' => 0, 'domain' => ''];
}

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
    exit(1);
}

loadModuleHelpers($guidance);
moduleWithContext('guidance', static function () use ($guidance): void {
    require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
});

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenant = resolveGuidanceTenant();
$tenantId = (int)($tenant['tenant_id'] ?? 0);
$tenantDomain = trim((string)($tenant['domain'] ?? ''));

if ($tenantId <= 0) {
    fwrite(STDERR, "No active Guidance tenant database with the required schema is available.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();
invalidateModuleContextCache('guidance');

if ($tenantDomain !== '') {
    $_SERVER['HTTP_HOST'] = $tenantDomain;
}

$db = app()->db();
$stamp = (string)time() . bin2hex(random_bytes(3));
$adminEmail = 'guidance-slots-admin-' . $stamp . '@example.test';
$counselorEmail = 'guidance-slots-counselor-' . $stamp . '@example.test';
$adminId = 0;
$counselorId = 0;

try {
    $db->beginTransaction();

    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );

    $userStmt->execute([$adminEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Slots', 'Admin', 'admin']);
    $adminId = (int)$db->lastInsertId();

    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Slots', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();

    $availabilityStmt = $db->prepare(
        'INSERT INTO gm_counselor_availability '
        . '(counselor_id, day_of_week, slot_index, is_available, start_time, end_time, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $availabilityStmt->execute([$counselorId, 'monday', 1, 1, '13:00:00', '14:00:00']);

    $token = app()->jwt()->generate([
        'sub' => 'admin:' . $adminId,
        'id' => $adminId,
        'username' => $adminEmail,
        'name' => 'Slots Admin',
        'role' => 'admin',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;
    $_GET = [
        'date' => '2026-04-06',
        'duration' => '30',
        'counselor_id' => (string)$counselorId,
    ];
    $_REQUEST = $_GET;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function (): void {
        apiGuidanceAppointmentSlots();
    });
    $output = (string)ob_get_clean();
    $status = http_response_code();

    $decoded = json_decode($output, true);
    $slotTimes = [];
    foreach (($decoded['data'] ?? []) as $slot) {
        if (is_array($slot) && isset($slot['time'])) {
            $slotTimes[] = (string)$slot['time'];
        }
    }

    t('appointment slots handler returns HTTP 200', $status === 200, 'status=' . (string)$status . ' body=' . $output);
    t('appointment slots handler returns success payload', is_array($decoded) && !empty($decoded['success']), $output);
    t('appointment slots handler uses custom counselor availability', $slotTimes === ['13:00:00', '13:30:00'], json_encode($slotTimes, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('appointment slots handler does not log ModuleDB exec errors', !str_contains($appLog, 'ModuleDB::exec'), $appLog);
    t('appointment slots handler leaves app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('appointment slots handler leaves error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_GET, $_REQUEST, $_SERVER['HTTP_COOKIE']);

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
    invalidateModuleContextCache('guidance');
}

echo "\n==========================================\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "==========================================\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);