<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'wms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms/users';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/handlers.php';

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

exit($fail > 0 ? 1 : 0);