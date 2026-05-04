<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== CMS LEGACY SUPERADMIN COMPAT ===\n\n";

$normalizedRole = cmsNormalizeRole('superadmin');
t('legacy superadmin role normalizes to administrator', $normalizedRole === 'administrator', $normalizedRole);

$normalizedUser = cmsNormalizeUserContext([
    'id' => 1,
    'role' => 'superadmin',
    'source' => 'cms',
]);
t(
    'legacy cms user context carries administrator role with legacy marker',
    is_array($normalizedUser)
        && ($normalizedUser['role'] ?? '') === 'administrator'
        && ($normalizedUser['legacy_role'] ?? '') === 'superadmin'
);

t('legacy superadmin still satisfies administrator gates', cmsRoleAtLeast('superadmin', 'administrator'));

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/cms/module.json'), true);
$adminRoles = is_array($manifest) ? ($manifest['auth_owned']['admin_roles'] ?? []) : [];
t(
    'cms manifest no longer declares reserved superadmin admin role',
    is_array($adminRoles)
        && !in_array('superadmin', $adminRoles, true)
        && in_array('administrator', $adminRoles, true),
    is_array($adminRoles) ? implode(', ', $adminRoles) : 'invalid manifest'
);

$foundationMigration = (string)file_get_contents(__DIR__ . '/../modules/cms/database/migrations/001_cms_foundation.sql');
t(
    'cms foundation migration seeds administrator instead of superadmin',
    str_contains($foundationMigration, "'CMS Admin', 'administrator', 1")
        && !str_contains($foundationMigration, "'CMS Admin', 'superadmin', 1")
);

$usersTemplate = (string)file_get_contents(__DIR__ . '/../templates/modules/cms/admin/users.disyl');
t(
    'cms users UI no longer offers superadmin as an assignable role',
    !str_contains($usersTemplate, 'option value="superadmin"')
);
t(
    'cms users UI marks legacy superadmin rows explicitly',
    str_contains($usersTemplate, 'Legacy Super Admin')
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);