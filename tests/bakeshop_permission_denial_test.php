<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function btDeny(string $label, bool $ok, string $detail = ''): void
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

function bakeshopCaptureHandler(callable $handler): array
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

echo "\n=== BAKESHOP PERMISSION DENIAL TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$originalSettings = getModuleSettings('bakeshop');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;
$previousUser = app()->user();
$previousCsrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

try {
    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read'],
        ], JSON_UNESCAPED_SLASHES),
    ]);

    app()->setUser([
        'id' => 1001,
        'sub' => 'bakeshop:1001',
        'role' => 'supervisor',
        'source' => 'bakeshop',
        'email' => 'bakeshop-supervisor@example.test',
    ]);

    $readResponse = bakeshopCaptureHandler(static function (): void {
        bakeshopApiUnitsIndex();
    });
    btDeny('read endpoint allowed for read-only supervisor', ($readResponse['status'] ?? 0) === 200 && (($readResponse['json']['ok'] ?? false) === true), json_encode($readResponse, JSON_UNESCAPED_SLASHES));

    $manageResponse = bakeshopCaptureHandler(static function (): void {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
        bakeshopApiProductsStore();
    });
    btDeny('manage endpoint forbidden for read-only supervisor', ($manageResponse['status'] ?? 0) === 403, json_encode($manageResponse, JSON_UNESCAPED_SLASHES));
    btDeny('forbidden response returns json error', (($manageResponse['json']['ok'] ?? true) === false) && (($manageResponse['json']['error'] ?? '') === 'Forbidden'), json_encode($manageResponse, JSON_UNESCAPED_SLASHES));

    app()->setUser([
        'id' => 1002,
        'sub' => 'bakeshop:1002',
        'role' => 'admin',
        'source' => 'bakeshop',
        'email' => 'bakeshop-admin@example.test',
    ]);
    $adminManageResponse = bakeshopCaptureHandler(static function (): void {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
        bakeshopApiProductsStore();
    });
    btDeny('admin manage request passes permission gate before validation', ($adminManageResponse['status'] ?? 0) === 422, json_encode($adminManageResponse, JSON_UNESCAPED_SLASHES));
} finally {
    saveModuleSettings('bakeshop', [
        'role_permissions' => $originalRolePermissions,
    ]);
    if ($previousCsrfHeader === null) {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    } else {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $previousCsrfHeader;
    }
    app()->setUser(is_array($previousUser) ? $previousUser : []);
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
btDeny('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btDeny('no error.log errors', $errorLog === '', $errorLog);

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