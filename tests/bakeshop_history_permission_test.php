<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/history';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function btHistoryPermission(string $label, bool $ok, string $detail = ''): void
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

function bakeshopCapturePageHandler(callable $handler): array
{
    http_response_code(200);
    ob_start();
    $handler();
    $body = (string)ob_get_clean();
    $status = http_response_code();
    $decoded = json_decode($body, true);

    return [
        'status' => is_int($status) ? $status : 200,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== BAKESHOP HISTORY PERMISSION TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;
$previousUser = app()->user();
$fixtureUserIds = [];

try {
    $insertUser = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    foreach (['supervisor', 'admin'] as $fixtureRole) {
        $fixtureUsername = 'history_' . $fixtureRole . '_' . bin2hex(random_bytes(4));
        $insertUser->execute([
            $fixtureUsername,
            $fixtureUsername . '@example.test',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'History ' . ucfirst($fixtureRole),
            $fixtureRole,
        ]);
        $fixtureUserIds[$fixtureRole] = (int)bakeshopDb()->lastInsertId();
    }

    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read', 'bakeshop.manage'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    app()->setUser([
        'id' => $fixtureUserIds['supervisor'],
        'sub' => 'bakeshop:' . $fixtureUserIds['supervisor'],
        'username' => 'supervisor',
        'role' => 'supervisor',
        'source' => 'bakeshop',
        'email' => 'bakeshop-supervisor@example.test',
    ]);

    $supervisorResponse = bakeshopCapturePageHandler(static function (): void {
        bakeshopPageHistory();
    });
    btHistoryPermission('supervisor history page request is forbidden even with manage permission', ($supervisorResponse['status'] ?? 0) === 403, json_encode($supervisorResponse, JSON_UNESCAPED_SLASHES));
    btHistoryPermission('supervisor forbidden history response returns json error', (($supervisorResponse['json']['ok'] ?? true) === false) && (($supervisorResponse['json']['error'] ?? '') === 'Forbidden'), json_encode($supervisorResponse, JSON_UNESCAPED_SLASHES));

    app()->setUser([
        'id' => $fixtureUserIds['admin'],
        'sub' => 'bakeshop:' . $fixtureUserIds['admin'],
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
        'email' => 'bakeshop-admin@example.test',
    ]);

    $adminResponse = bakeshopCapturePageHandler(static function (): void {
        bakeshopPageHistory();
    });
    btHistoryPermission('admin history page request succeeds', ($adminResponse['status'] ?? 0) === 200, json_encode($adminResponse, JSON_UNESCAPED_SLASHES));
    btHistoryPermission('admin history page renders html heading', str_contains($adminResponse['body'] ?? '', 'Activity History'), $adminResponse['body'] ?? '');
} finally {
    if ($fixtureUserIds !== []) {
        $deleteUser = bakeshopDb()->prepare('DELETE FROM bakeshop_users WHERE id = ?');
        foreach ($fixtureUserIds as $fixtureUserId) {
            $deleteUser->execute([$fixtureUserId]);
        }
    }
    saveModuleSettings('bakeshop', [
        'role_permissions' => $originalRolePermissions,
    ]);
    app()->setUser(is_array($previousUser) ? $previousUser : []);
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
btHistoryPermission('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btHistoryPermission('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

echo (string)ob_get_clean();
exit($fail > 0 ? 1 : 0);
