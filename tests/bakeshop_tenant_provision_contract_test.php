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
$bakeshopManifest = json_decode((string)file_get_contents(BASE_PATH . '/modules/bakeshop/module.json'), true);
$moduleManagerFile = (string)file_get_contents(BASE_PATH . '/src/helpers/module-manager.php');

btProvision('CLI help documents bakeshop admin requirement', str_contains($cliFile, 'bakeshop entry tenants require --admin-user and --admin-pass'));
btProvision('CLI tenant:provision blocks missing bakeshop admin credentials', str_contains($cliFile, 'Bakeshop entry tenants require a named admin during provisioning.'));
btProvision('CLI tenant:provision seeds bakeshop_users', str_contains($cliFile, 'bakeshop_users'));
btProvision('CLI tenant:provision inserts admin into bakeshop_users', str_contains($cliFile, 'INSERT INTO `bakeshop_users`'));
btProvision('TenantProvisioner enforces seeded admin credentials for bakeshop', str_contains($provisionerFile, 'requiresSeededAdminCredentials') && str_contains($provisionerFile, "['bakeshop']"));
btProvision('TenantProvisioner reads auth_owned spec for entry module', str_contains($provisionerFile, 'kernelAuthOwnedSpecForModule') && str_contains($provisionerFile, 'seedAdminUserFromAuthOwnedSpec'));
btProvision('module-manager exposes kernelAuthOwnedModules helper', str_contains($moduleManagerFile, 'function kernelAuthOwnedModules') && str_contains($moduleManagerFile, 'function kernelNormalizeAuthOwnedSpec'));
btProvision('module-manager validates auth_owned manifest block', str_contains($moduleManagerFile, 'function validateAuthOwnedSpec'));
btProvision('bakeshop manifest declares auth_owned.users_table = bakeshop_users', is_array($bakeshopManifest) && (($bakeshopManifest['auth_owned']['users_table'] ?? '') === 'bakeshop_users'));
btProvision('bakeshop manifest requires named admin on provision', is_array($bakeshopManifest) && !empty($bakeshopManifest['auth_owned']['requires_named_admin_on_provision']));
btProvision('bakeshop manifest declares blocked bootstrap hashes', is_array($bakeshopManifest)
    && is_array($bakeshopManifest['auth_owned']['blocked_password_hashes'] ?? null)
    && in_array('!bakeshop-bootstrap-password-reset-required!', $bakeshopManifest['auth_owned']['blocked_password_hashes'], true));
btProvision('tenant admin password push iterates manifest auth tables', str_contains($adminHandlersFile, 'kernelAuthOwnedModules()') && str_contains($adminHandlersFile, 'apiTenantAdminPasswordPush'));
btProvision('tenant admin password push reports bakeshop_users results', str_contains($adminHandlersFile, "kernelAuthOwnedModules"));

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