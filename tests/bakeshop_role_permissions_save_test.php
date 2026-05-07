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

function btRoleSave(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== BAKESHOP ROLE PERMISSIONS SAVE TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;

try {
    $saved = bakeshopSaveRolePermissions([
        'admin' => [],
        'supervisor' => ['bakeshop.read'],
    ]);

    btRoleSave('admin read remains fixed', in_array('bakeshop.read', $saved['admin'] ?? [], true), json_encode($saved, JSON_UNESCAPED_SLASHES));
    btRoleSave('admin manage remains fixed', in_array('bakeshop.manage', $saved['admin'] ?? [], true), json_encode($saved, JSON_UNESCAPED_SLASHES));
    btRoleSave('supervisor read can stay enabled', in_array('bakeshop.read', $saved['supervisor'] ?? [], true), json_encode($saved, JSON_UNESCAPED_SLASHES));
    btRoleSave('supervisor manage can be removed', !in_array('bakeshop.manage', $saved['supervisor'] ?? [], true), json_encode($saved, JSON_UNESCAPED_SLASHES));

    $stored = getModuleSettings('bakeshop');
    $decoded = json_decode((string)($stored['role_permissions'] ?? ''), true);
    btRoleSave('saved permissions persist as json', is_array($decoded), (string)($stored['role_permissions'] ?? 'null'));
    btRoleSave('stored supervisor permissions match normalized result', ($decoded['supervisor'] ?? null) === ($saved['supervisor'] ?? null), json_encode($decoded, JSON_UNESCAPED_SLASHES));
    $effective = bakeshopRolePermissions();
    btRoleSave('cached role permissions reflect saved supervisor removal', !bakeshopRoleHasPermission('supervisor', 'bakeshop.manage'), json_encode($effective, JSON_UNESCAPED_SLASHES));

    $savedUnknown = bakeshopSaveRolePermissions([
        'supervisor' => ['bakeshop.read', 'bakeshop.unknown', ''],
    ]);
    btRoleSave('unknown permissions are removed on save', !in_array('bakeshop.unknown', $savedUnknown['supervisor'] ?? [], true), json_encode($savedUnknown, JSON_UNESCAPED_SLASHES));
    $effectiveUnknown = bakeshopRolePermissions();
    btRoleSave('role permission cache invalidates after second save', ($effectiveUnknown['supervisor'] ?? []) === ($savedUnknown['supervisor'] ?? []), json_encode($effectiveUnknown, JSON_UNESCAPED_SLASHES));
} finally {
    saveModuleSettings('bakeshop', [
        'role_permissions' => $originalRolePermissions,
    ]);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btRoleSave('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btRoleSave('no error.log errors', $errorLog === '', $errorLog);

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