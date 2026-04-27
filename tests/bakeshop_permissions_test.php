<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btPerm(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== BAKESHOP PERMISSIONS TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;

try {
    $defaults = bakeshopDefaultRolePermissions();
    btPerm('default admin can read', in_array('bakeshop.read', $defaults['admin'] ?? [], true), json_encode($defaults, JSON_UNESCAPED_SLASHES));
    btPerm('default admin can manage', in_array('bakeshop.manage', $defaults['admin'] ?? [], true), json_encode($defaults, JSON_UNESCAPED_SLASHES));
    btPerm('default supervisor can read', in_array('bakeshop.read', $defaults['supervisor'] ?? [], true), json_encode($defaults, JSON_UNESCAPED_SLASHES));
    btPerm('default supervisor can manage', in_array('bakeshop.manage', $defaults['supervisor'] ?? [], true), json_encode($defaults, JSON_UNESCAPED_SLASHES));

    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $permissions = bakeshopRolePermissions();
    btPerm('custom supervisor retains read', bakeshopRoleHasPermission('supervisor', 'bakeshop.read'), json_encode($permissions, JSON_UNESCAPED_SLASHES));
    btPerm('custom supervisor loses manage', !bakeshopRoleHasPermission('supervisor', 'bakeshop.manage'), json_encode($permissions, JSON_UNESCAPED_SLASHES));
    btPerm('custom admin keeps manage', bakeshopRoleHasPermission('admin', 'bakeshop.manage'), json_encode($permissions, JSON_UNESCAPED_SLASHES));

    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.unknown', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read', ''],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $sanitized = bakeshopRolePermissions();
    btPerm('unknown permission is ignored', !in_array('bakeshop.unknown', $sanitized['admin'] ?? [], true), json_encode($sanitized, JSON_UNESCAPED_SLASHES));
} finally {
    saveModuleSettings('bakeshop', [
        'role_permissions' => $originalRolePermissions,
    ]);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btPerm('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btPerm('no error.log errors', $errorLog === '', $errorLog);

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