<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'wms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms/users';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/handlers.php';

$routes = require __DIR__ . '/../modules/wms/routes.php';

ob_start();

$pass = 0;
$fail = 0;
$skip = 0;
$errors = [];
$cleanupUserIds = [];

function userTestPass(string $label): void
{
    global $pass;

    $pass++;
    echo "PASS {$label}\n";
}

function userTestFail(string $label, string $detail = ''): void
{
    global $fail, $errors;

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
}

function userTestSkip(string $label, string $detail = ''): void
{
    global $skip;

    $skip++;
    echo "SKIP {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
}

function userTestCheck(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        userTestPass($label);
        return;
    }

    userTestFail($label, $detail);
}

function userTestColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function userTestCleanup(PDO $db): void
{
    global $cleanupUserIds;

    if ($cleanupUserIds === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($cleanupUserIds), '?'));
    $db->prepare("DELETE FROM wms_users WHERE id IN ({$placeholders})")->execute($cleanupUserIds);
}

function userTestRunRequestThroughEntrypoint(array $server, ?array $user = null, ?string $rawBody = null): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-wms-users-account-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypointPath = __DIR__ . '/../public/index.php';
    $serverExport = var_export($server, true);
    $userExport = var_export($user, true);
    $patchedAppPath = null;

    if ($rawBody !== null) {
        $patchedAppPath = sys_get_temp_dir() . '/ikabud-wms-users-account-app-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
        $appSource = (string)file_get_contents(__DIR__ . '/../kernel/App.php');
        $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
        $appSource = str_replace("file_get_contents('php://input')", $replacement, $appSource);
        file_put_contents($patchedAppPath, $appSource);
    }

    $entrypoint = var_export($entrypointPath, true);
    $patchedApp = var_export($patchedAppPath, true);

    $script = "<?php\n"
        . "foreach ({$serverExport} as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "if (!isset(\$_SERVER['REQUEST_METHOD'])) { \$_SERVER['REQUEST_METHOD'] = 'GET'; }\n"
        . "if (!isset(\$_SERVER['REQUEST_URI'])) { \$_SERVER['REQUEST_URI'] = '/'; }\n"
        . "if (!isset(\$_SERVER['HTTP_HOST'])) { \$_SERVER['HTTP_HOST'] = 'wms.test'; }\n"
        . "\$_GET = [];\n"
        . "\$__ik_query = parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_QUERY);\n"
        . "if (is_string(\$__ik_query) && \$__ik_query !== '') { parse_str(\$__ik_query, \$_GET); }\n"
        . "\$_REQUEST = array_merge(\$_REQUEST ?? [], \$_GET);\n"
        . "\$_SERVER['SCRIPT_NAME'] = '/public/index.php';\n"
        . "\$_SERVER['PHP_SELF'] = '/public/index.php';\n"
        . "if (is_string({$patchedApp}) && {$patchedApp} !== '') { require {$patchedApp}; }\n"
        . "require {$bootstrap};\n"
        . "\$user = {$userExport};\n"
        . "if (is_array(\$user)) { app()->setUser(\$user); }\n"
        . "register_shutdown_function(static function (): void { echo \"\\n__HEADERS__\\n\"; echo json_encode(headers_list(), JSON_UNESCAPED_SLASHES); });\n"
        . "require {$entrypoint};\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);
    if (is_string($patchedAppPath) && $patchedAppPath !== '') {
        @unlink($patchedAppPath);
    }

    $stdout = implode("\n", $output);
    $parts = explode("\n__HEADERS__\n", $stdout, 2);
    $headers = isset($parts[1]) ? json_decode($parts[1], true) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    return [
        'exit_code' => $exitCode,
        'body' => $parts[0] ?? '',
        'headers' => $headers,
        'json' => json_decode((string)($parts[0] ?? ''), true),
        'raw' => $stdout,
    ];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== WMS USERS ACCOUNT ===\n";

if (!function_exists('module') || !module('wms')) {
    userTestSkip('WMS module context unavailable', 'Skipping WMS users account regression');
    exit(0);
}

$db = app()->db();

if (!userTestColumnExists($db, 'wms_users', 'phone')) {
    userTestSkip('wms_users.phone missing', 'Apply WMS migration 021 before running this test');
    exit(0);
}

try {
    userTestCheck(
        'account update API route declared',
        ($routes['POST']['/api/v1/wms/account'] ?? '') === 'wms:wmsApiAccountUpdate',
        (string)($routes['POST']['/api/v1/wms/account'] ?? 'missing')
    );

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $actorPassword = 'Admin!' . $suffix . '123';

    $db->prepare(
        'INSERT INTO wms_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
    )->execute([
        'acct-admin-' . $suffix,
        'acct-admin-' . $suffix . '@example.test',
        '+63917000' . substr($suffix, 0, 4),
        password_hash($actorPassword, PASSWORD_BCRYPT),
        'Account Admin ' . $suffix,
        'admin',
    ]);

    $actorId = (int)$db->lastInsertId();
    $cleanupUserIds[] = $actorId;
    $actor = [
        'id' => $actorId,
        'username' => 'acct-admin-' . $suffix,
        'name' => 'Account Admin ' . $suffix,
        'email' => 'acct-admin-' . $suffix . '@example.test',
        'role' => 'admin',
        'source' => 'wms',
    ];

    $createdUserId = wmsUserCreateRecord([
        'username' => 'acct-user-' . $suffix,
        'email' => 'acct-user-' . $suffix . '@example.test',
        'phone' => '+63918000' . substr($suffix, 0, 4),
        'password' => 'Created!' . $suffix . '456',
        'full_name' => 'Created User ' . $suffix,
        'role' => 'viewer',
    ], $actor);
    $cleanupUserIds[] = $createdUserId;

    $created = wmsUserAccountRecord($createdUserId);
    userTestCheck(
        'phone persists on user create',
        is_array($created) && ($created['phone'] ?? null) === '+63918000' . substr($suffix, 0, 4),
        'created phone did not match'
    );

    $usersPageHtml = wmsRender('admin/users.disyl', wmsAdminContext($actor, 'users', [
        'page_title' => 'Users',
        'current_user_id' => $actorId,
        'users' => wmsUsersListData(),
    ]));
    userTestCheck(
        'users page render uses create payload helper',
        str_contains($usersPageHtml, 'wmsBuildUserCreatePayload(this)'),
        'create payload helper missing from rendered users page'
    );
    userTestCheck(
        'users page render uses update payload helper',
        str_contains($usersPageHtml, 'wmsBuildUserUpdatePayload(this)'),
        'update payload helper missing from rendered users page'
    );

    wmsUserUpdateRecord($createdUserId, [
        'full_name' => 'Updated User ' . $suffix,
        'email' => 'acct-user-' . $suffix . '@example.test',
        'phone' => '+63919999' . substr($suffix, 0, 4),
        'role' => 'supervisor',
        'is_active' => 1,
    ], $actor);

    $updated = wmsUserAccountRecord($createdUserId);
    userTestCheck(
        'phone persists on user update',
        is_array($updated) && ($updated['phone'] ?? null) === '+63919999' . substr($suffix, 0, 4),
        'updated phone did not match'
    );

    $apiEmail = 'acct-user-api-' . $suffix . '@example.test';
    $apiPhone = '+63917777' . substr($suffix, 0, 4);
    $apiResponse = userTestRunRequestThroughEntrypoint([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v1/wms/users/' . $createdUserId,
        'HTTP_HOST' => 'wms.test',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $actor, json_encode([
        'full_name' => 'API Updated User ' . $suffix,
        'email' => $apiEmail,
        'phone' => $apiPhone,
        'role' => 'supervisor',
        'is_active' => 1,
        'password' => '',
    ], JSON_UNESCAPED_SLASHES));
    $apiUpdated = wmsUserAccountRecord($createdUserId);
    userTestCheck(
        'API user update returns success',
        ($apiResponse['exit_code'] ?? 1) === 0 && is_array($apiResponse['json']) && (($apiResponse['json']['ok'] ?? false) === true),
        (string)($apiResponse['raw'] ?? '')
    );
    userTestCheck(
        'API user update persists email',
        is_array($apiUpdated) && (($apiUpdated['email'] ?? null) === $apiEmail),
        is_array($apiUpdated) ? (string)($apiUpdated['email'] ?? 'missing') : 'no user row'
    );
    userTestCheck(
        'API user update persists phone',
        is_array($apiUpdated) && (($apiUpdated['phone'] ?? null) === $apiPhone),
        is_array($apiUpdated) ? (string)($apiUpdated['phone'] ?? 'missing') : 'no user row'
    );
    userTestCheck(
        'API user update persists full name',
        is_array($apiUpdated) && (($apiUpdated['full_name'] ?? null) === 'API Updated User ' . $suffix),
        is_array($apiUpdated) ? (string)($apiUpdated['full_name'] ?? 'missing') : 'no user row'
    );

    $updatedOwnAccount = wmsUpdateOwnAccount($actorId, [
        'full_name' => 'Account Admin Updated ' . $suffix,
        'email' => 'acct-admin-updated-' . $suffix . '@example.test',
        'phone' => '+63918888' . substr($suffix, 0, 4),
    ]);
    userTestCheck(
        'own account update persists email',
        is_array($updatedOwnAccount) && (($updatedOwnAccount['email'] ?? null) === 'acct-admin-updated-' . $suffix . '@example.test'),
        is_array($updatedOwnAccount) ? (string)($updatedOwnAccount['email'] ?? 'missing') : 'no account returned'
    );
    userTestCheck(
        'own account update persists phone',
        is_array($updatedOwnAccount) && (($updatedOwnAccount['phone'] ?? null) === '+63918888' . substr($suffix, 0, 4)),
        is_array($updatedOwnAccount) ? (string)($updatedOwnAccount['phone'] ?? 'missing') : 'no account returned'
    );

    $selfDeactivateError = '';
    try {
        wmsUserDeactivate($actorId, $actor);
    } catch (Throwable $e) {
        $selfDeactivateError = $e->getMessage();
    }
    userTestCheck(
        'self delete is blocked',
        str_contains($selfDeactivateError, 'Cannot delete your own account.'),
        $selfDeactivateError
    );

    $selfInactiveError = '';
    $actorRow = wmsUserAccountRecord($actorId);
    try {
        wmsUserUpdateRecord($actorId, [
            'full_name' => (string)($actorRow['full_name'] ?? ''),
            'email' => (string)($actorRow['email'] ?? ''),
            'phone' => (string)($actorRow['phone'] ?? ''),
            'role' => (string)($actorRow['role'] ?? 'admin'),
            'is_active' => 0,
        ], $actor);
    } catch (Throwable $e) {
        $selfInactiveError = $e->getMessage();
    }
    userTestCheck(
        'self deactivate via update is blocked',
        str_contains($selfInactiveError, 'Cannot deactivate your own account.'),
        $selfInactiveError
    );

    $newPassword = 'Changed!' . $suffix . '789';
    wmsChangeOwnPassword($actorId, $actorPassword, $newPassword, $newPassword);
    $passwordRow = wmsUserFindOrFail($actorId);
    userTestCheck(
        'account password change updates the stored hash',
        password_verify($newPassword, (string)($passwordRow['password_hash'] ?? '')),
        'new password did not verify against stored hash'
    );
} catch (Throwable $e) {
    userTestFail('unexpected exception', $e->getMessage());
} finally {
    userTestCleanup($db);
}

echo "\nSummary: {$pass} passed, {$fail} failed, {$skip} skipped\n";

if ($errors !== []) {
    echo implode("\n", $errors) . "\n";
}

ob_end_flush();

exit($fail > 0 ? 1 : 0);