<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;

function tpam(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== TENANT PROVISION AUTH COLUMN MAPPING ===\n";

if (!function_exists('kernelBuildAuthOwnedAdminSeedPlan')) {
    tpam('auth-owned admin seed SQL planner exists', false, 'kernelBuildAuthOwnedAdminSeedPlan is unavailable');
} else {
    $emailOnly = kernelNormalizeAuthOwnedSpec('email-only-fixture', [
        'users_table' => 'fixture_accounts',
        'username_column' => null,
        'email_column' => 'login_email',
        'password_column' => 'secret_hash',
        'name_column' => 'display_label',
        'active_column' => 'enabled_flag',
        'role_column' => 'access_level',
        'admin_roles' => ['owner'],
        'default_admin_role' => 'owner',
    ]);
    $mapped = kernelBuildAuthOwnedAdminSeedPlan($emailOnly, 'owner@example.test', 'HASH', 'Owner', 42);
    $mappedSql = (string)($mapped['insert_sql'] ?? '');
    tpam('email-only lookup uses the mapped email column', str_contains((string)($mapped['lookup_sql'] ?? ''), '`login_email` = :identity'), (string)($mapped['lookup_sql'] ?? ''));
    tpam('email-only insert names every mapped real column', $mappedSql === 'INSERT INTO `fixture_accounts` (`login_email`, `secret_hash`, `display_label`, `access_level`, `enabled_flag`) VALUES (:identity, :password, :name, :role, 1)', $mappedSql);
    tpam('email-only insert does not invent a username column', !str_contains($mappedSql, '`username`'), $mappedSql);

    $defaults = kernelNormalizeAuthOwnedSpec('default-fixture', [
        'users_table' => 'users',
        'admin_roles' => ['admin'],
    ]);
    $defaultPlan = kernelBuildAuthOwnedAdminSeedPlan($defaults, 'admin', 'HASH', 'Admin', 42);
    $defaultSql = (string)($defaultPlan['insert_sql'] ?? '');
    tpam('default mapping remains username/email/password_hash/full_name/role/is_active', $defaultSql === 'INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `is_active`) VALUES (:identity, :email, :password, :name, :role, 1)', $defaultSql);

    try {
        kernelBuildAuthOwnedAdminSeedPlan([
            'module_id' => 'broken-fixture',
            'users_table' => 'broken_users',
            'username_column' => null,
            'email_column' => null,
            'password_column' => 'password_hash',
            'name_column' => 'full_name',
            'role_column' => 'role',
            'active_column' => 'is_active',
            'default_admin_role' => 'admin',
        ], 'admin', 'HASH', 'Admin', 42);
        tpam('unresolved required identity fails loudly with module and fields', false, 'no exception');
    } catch (Throwable $e) {
        tpam('unresolved required identity fails loudly with module and fields', str_contains($e->getMessage(), 'broken-fixture') && str_contains($e->getMessage(), 'username_column') && str_contains($e->getMessage(), 'email_column'), $e->getMessage());
    }
}

echo "\nPASS: {$pass}  FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
