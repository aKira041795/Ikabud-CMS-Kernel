<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/tenants';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/Services/TenantProvisioner.php';

$pass = 0;
$fail = 0;
$errors = [];

function btProvision(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP TENANT PROVISION CONTRACT TEST ===\n\n";

$cliFile = (string)file_get_contents(BASE_PATH . '/ikabud');
$provisionerFile = (string)file_get_contents(BASE_PATH . '/kernel/Services/TenantProvisioner.php');
$adminHandlersFile = (string)file_get_contents(BASE_PATH . '/src/http/admin-handlers.php');

btProvision('CLI help documents bakeshop admin requirement', str_contains($cliFile, 'bakeshop entry tenants require --admin-user and --admin-pass'));
btProvision('CLI tenant:provision blocks missing bakeshop admin credentials', str_contains($cliFile, 'Bakeshop entry tenants require a named admin during provisioning.'));
btProvision('CLI tenant:provision seeds bakeshop_users', str_contains($cliFile, 'bakeshop_users'));
btProvision('CLI tenant:provision inserts admin into bakeshop_users', str_contains($cliFile, 'INSERT INTO `bakeshop_users`'));
btProvision('TenantProvisioner enforces seeded admin credentials for bakeshop', str_contains($provisionerFile, "requiresSeededAdminCredentials") && str_contains($provisionerFile, "['bakeshop']"));
btProvision('TenantProvisioner seeds bakeshop_users directly', str_contains($provisionerFile, "'bakeshop' => 'bakeshop_users'") || str_contains($provisionerFile, "INSERT INTO `bakeshop_users`"));
btProvision('tenant admin password push updates bakeshop_users', str_contains($adminHandlersFile, 'UPDATE bakeshop_users SET password_hash = :p, updated_at = NOW() WHERE role = :r AND is_active = 1'));
btProvision('tenant admin password push reports bakeshop_users results', str_contains($adminHandlersFile, "'bakeshop_users'"));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btProvision('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btProvision('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);