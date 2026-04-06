<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/email-templates';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';

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
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($users && $users->fetchColumn()) && (bool)($settings && $settings->fetchColumn());
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

$tenantDb = app()->db();
$controlDb = app()->controlDb();
$stamp = (string)time() . bin2hex(random_bytes(3));
$adminEmail = 'guidance-email-admin-' . $stamp . '@example.test';
$adminId = 0;

try {
    $tenantDb->beginTransaction();
    $controlDb->beginTransaction();

    $userStmt = $tenantDb->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$adminEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Email', 'Admin', 'admin']);
    $adminId = (int)$tenantDb->lastInsertId();

    $token = app()->jwt()->generate([
        'sub' => 'admin:' . $adminId,
        'id' => $adminId,
        'username' => $adminEmail,
        'name' => 'Email Admin',
        'role' => 'admin',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;

    $freeGranted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status' => 'active',
        'tier' => 'free',
        'source' => 'guidance_admin_email_templates_test',
        'metadata' => ['via' => 'guidance_admin_email_templates_test', 'tier' => 'free'],
    ]);
    invalidateModuleCatalogCache();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceEmailTemplates();
    });
    $freeEmailPage = (string)ob_get_clean();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceFormSettings();
    });
    $freeFormPage = (string)ob_get_clean();

    t('guidance free entitlement can be forced for admin page checks', $freeGranted);
    t('sidebar shows form settings link on free tier', str_contains($freeEmailPage, 'Form Settings'), 'Form Settings nav item missing');
    t('sidebar shows email templates link on free tier', str_contains($freeEmailPage, 'Email Templates'), 'Email Templates nav item missing');
    t('email templates page renders upgrade state on free tier', str_contains($freeEmailPage, 'Guidance Pro required'), 'Upgrade state missing from email templates page');
    t('form settings page renders upgrade state on free tier', str_contains($freeFormPage, 'Guidance Pro required'), 'Upgrade state missing from form settings page');

    $proGranted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status' => 'active',
        'tier' => 'pro',
        'source' => 'guidance_admin_email_templates_test',
        'metadata' => ['via' => 'guidance_admin_email_templates_test', 'tier' => 'pro'],
    ]);
    invalidateModuleCatalogCache();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceEmailTemplates();
    });
    $proEmailPage = (string)ob_get_clean();

    $savedSubject = 'Request Received for ' . $stamp;
    $savedBody = "Hello {student_name},\n\nYour request is queued.";
    moduleWithContext('guidance', static function () use ($adminId, $savedSubject, $savedBody): void {
        guidancePersistEmailTemplates([
            'email_tpl_booking_received_subject' => $savedSubject,
            'email_tpl_booking_received_body' => $savedBody,
            'email_tpl_booking_confirmed_subject' => 'Approved appointment',
            'email_tpl_booking_confirmed_body' => "Approved for {date} at {time}.",
            'email_tpl_booking_rejected_subject' => 'Request update',
            'email_tpl_booking_rejected_body' => "We cannot accommodate {date} at {time}.\n{reason}",
        ], $adminId);
    });

    $templates = moduleWithContext('guidance', static function (): array {
        return guidanceEmailTemplates();
    });

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceEmailTemplates();
    });
    $savedEmailPage = (string)ob_get_clean();

    t('guidance pro entitlement can be forced for admin page checks', $proGranted);
    t('email templates page renders editable defaults on pro tier', str_contains($proEmailPage, 'Appointment Request Received'), 'Default subject missing from pro page');
    t('email template settings persist updated subject', (($templates['booking_received']['subject'] ?? '') === $savedSubject), json_encode($templates, JSON_UNESCAPED_SLASHES));
    t('email template settings persist updated body', (($templates['booking_received']['body'] ?? '') === $savedBody), json_encode($templates, JSON_UNESCAPED_SLASHES));
    t('email templates page renders saved values after persistence', str_contains($savedEmailPage, $savedSubject), 'Saved email template subject not rendered');

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('guidance admin email template checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('guidance admin email template checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE']);

    if ($controlDb->inTransaction()) {
        $controlDb->rollBack();
    }
    if ($tenantDb->inTransaction()) {
        $tenantDb->rollBack();
    }

    invalidateModuleCatalogCache();
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