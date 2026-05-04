<?php

declare(strict_types=1);

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
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== AUTH_OWNED RESERVED ROLE VALIDATION ===\n\n";

$tmpDir = sys_get_temp_dir() . '/auth-owned-role-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);
$manifestPath = $tmpDir . '/module.json';

try {
    $manifest = [
        'id' => 'reserved-role-fixture',
        'name' => 'Reserved Role Fixture',
        'version' => '1.0.0',
        'description' => 'Fixture module for reserved auth_owned role validation.',
        'author' => 'Test',
        'owns_tables' => ['fixture_users'],
        'migrations' => [],
        'auth_owned' => [
            'users_table' => 'fixture_users',
            'admin_roles' => ['superadmin'],
            'default_admin_role' => 'superadmin',
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $runtimeValidation = validateAuthOwnedSpec($manifest['auth_owned']);
    t('legacy runtime validation stays backward compatible for existing manifests', !empty($runtimeValidation['ok']), json_encode($runtimeValidation, JSON_UNESCAPED_SLASHES));

    $strictValidation = validateAuthOwnedSpec($manifest['auth_owned'], true);
    t('strict auth_owned validation rejects reserved kernel roles', empty($strictValidation['ok']) && str_contains((string)($strictValidation['error'] ?? ''), 'reserved kernel roles'), json_encode($strictValidation, JSON_UNESCAPED_SLASHES));

    $manifestValidation = validateModuleManifest($manifestPath);
    t('strict module manifest validation rejects reserved auth_owned roles', empty($manifestValidation['ok']) && (string)($manifestValidation['error_code'] ?? '') === 'manifest_invalid_auth_owned', json_encode($manifestValidation, JSON_UNESCAPED_SLASHES));
} finally {
    @unlink($manifestPath);
    @rmdir($tmpDir);
}

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