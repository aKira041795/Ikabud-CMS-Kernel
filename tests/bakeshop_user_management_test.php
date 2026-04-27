<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/users';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btUser(string $label, bool $ok, string $detail = ''): void
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

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== BAKESHOP USER MANAGEMENT TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$cleanupStmt = $db->prepare("DELETE FROM bakeshop_users WHERE username IN ('test-bakeshop-admin', 'test-bakeshop-supervisor')");
$cleanupStmt->execute();

$bootstrapLookupStmt = $db->prepare("SELECT id, username, email, password_hash, full_name, role, is_active FROM bakeshop_users WHERE username = 'bakeshopadmin' LIMIT 1");
$bootstrapLookupStmt->execute();
$bootstrapOriginal = $bootstrapLookupStmt->fetch(PDO::FETCH_ASSOC);
$bootstrapCreatedId = null;
if (!is_array($bootstrapOriginal)) {
    $db->prepare(
        'INSERT INTO bakeshop_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())'
    )->execute([
        'bakeshopadmin',
        'admin@bakeshop.local',
        password_hash('admin123', PASSWORD_BCRYPT),
        'Bakeshop Admin',
        'admin',
    ]);
    $bootstrapCreatedId = (int)$db->lastInsertId();
} else {
    $db->prepare(
        'UPDATE bakeshop_users SET email = ?, full_name = ?, role = ?, is_active = 1, updated_at = NOW() WHERE id = ?'
    )->execute([
        'admin@bakeshop.local',
        'Bakeshop Admin',
        'admin',
        (int)$bootstrapOriginal['id'],
    ]);
}

$previousUser = app()->user();

try {
    $actor = [
        'id' => 5001,
        'username' => 'test-bakeshop-admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ];

    $initialOnboarding = bakeshopBootstrapOnboardingState();
    btUser('bootstrap onboarding detects default admin', ($initialOnboarding['required'] ?? false) === true && (($initialOnboarding['bootstrap_user']['username'] ?? '') === 'bakeshopadmin'), json_encode($initialOnboarding, JSON_UNESCAPED_SLASHES));

    $createdId = bakeshopUserCreateRecord([
        'username' => 'test-bakeshop-admin',
        'email' => 'test-bakeshop-admin@example.test',
        'phone' => '09171234567',
        'password' => 'admin1234',
        'full_name' => 'Test Bakeshop Admin',
        'role' => 'admin',
    ], $actor);
    btUser('creates module-local admin account', $createdId > 0, (string)$createdId);

    $created = bakeshopUserAccountRecord($createdId);
    btUser('created account can be loaded', is_array($created));
    btUser('created account role is admin', ($created['role'] ?? '') === 'admin', json_encode($created, JSON_UNESCAPED_SLASHES));

    $handoffOnboarding = bakeshopBootstrapOnboardingState();
    btUser('bootstrap onboarding sees successor admin after staff creation', ($handoffOnboarding['can_retire_bootstrap'] ?? false) === true, json_encode($handoffOnboarding, JSON_UNESCAPED_SLASHES));
    btUser('bootstrap onboarding still forces default admin through setup', bakeshopShouldForceBootstrapOnboarding([
        'username' => 'bakeshopadmin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], $handoffOnboarding), json_encode($handoffOnboarding, JSON_UNESCAPED_SLASHES));

    $supervisorId = bakeshopUserCreateRecord([
        'username' => 'test-bakeshop-supervisor',
        'email' => 'test-bakeshop-supervisor@example.test',
        'phone' => '',
        'password' => 'supervisor123',
        'full_name' => 'Test Bakeshop Supervisor',
        'role' => 'supervisor',
    ], $actor);
    btUser('creates supervisor account', $supervisorId > 0, (string)$supervisorId);

    bakeshopUserUpdateRecord($supervisorId, [
        'full_name' => 'Updated Bakeshop Supervisor',
        'email' => 'updated-bakeshop-supervisor@example.test',
        'phone' => '09998887777',
        'role' => 'supervisor',
        'is_active' => 1,
        'password' => 'supervisor5678',
    ], $actor);
    $updated = bakeshopUserAccountRecord($supervisorId);
    btUser('updates supervisor email', ($updated['email'] ?? '') === 'updated-bakeshop-supervisor@example.test', json_encode($updated, JSON_UNESCAPED_SLASHES));
    btUser('updates supervisor phone', ($updated['phone'] ?? '') === '09998887777', json_encode($updated, JSON_UNESCAPED_SLASHES));

    $auth = bakeshop_cap_kernel_auth_authenticate_1([
        'username' => '@bakeshop:test-bakeshop-supervisor',
        'password' => 'supervisor5678',
    ]);
    btUser('module auth provider authenticates bakeshop user', is_array($auth) && ($auth['source'] ?? '') === 'bakeshop', json_encode($auth, JSON_UNESCAPED_SLASHES));
    btUser('module auth provider exposes token version', is_array($auth) && (int)(($auth['user']['token_version'] ?? -1)) === 1, json_encode($auth, JSON_UNESCAPED_SLASHES));
    btUser('unsafe bootstrap helper flags reset marker', bakeshopUserHasUnsafeBootstrapPassword([
        'username' => 'bakeshopadmin',
        'password_hash' => bakeshopBootstrapPasswordResetMarker(),
    ]));
    $blockedBootstrapAuth = bakeshop_cap_kernel_auth_authenticate_1([
        'username' => '@bakeshop:bakeshopadmin',
        'password' => 'password',
    ]);
    btUser('module auth provider blocks legacy bootstrap default password', $blockedBootstrapAuth === null, json_encode($blockedBootstrapAuth, JSON_UNESCAPED_SLASHES));

    app()->setUser([
        'id' => $createdId,
        'sub' => 'bakeshop:' . $createdId,
        'username' => 'test-bakeshop-admin',
        'role' => 'admin',
        'source' => 'bakeshop',
        'email' => 'test-bakeshop-admin@example.test',
    ]);
    ob_start();
    bakeshopPageUsers();
    $usersHtml = (string)ob_get_clean();
    btUser('users page renders staff heading', str_contains($usersHtml, 'Active Staff'));
    btUser('users page renders created supervisor', str_contains($usersHtml, 'Updated Bakeshop Supervisor'));
    btUser('users page renders bootstrap onboarding', str_contains($usersHtml, 'Bootstrap Admin Onboarding'));
    btUser('users page explains bootstrap password setup requirement when needed', str_contains(bakeshopRender('pages/users.disyl', bakeshopPageContext($actor, 'users', [
        'page_title' => 'Bakeshop Staff',
        'current_user_id' => $createdId,
        'bootstrap_onboarding' => [
            'required' => true,
            'needs_successor_admin' => true,
            'can_retire_bootstrap' => false,
            'password_setup_required' => true,
            'other_admin_count' => 0,
            'bootstrap_user' => ['username' => 'bakeshopadmin'],
        ],
        'is_bootstrap_user' => false,
        'users' => [],
    ])), 'no longer ships with a shared default password'));

    app()->setUser([
        'id' => $supervisorId,
        'sub' => 'bakeshop:' . $supervisorId,
        'username' => 'test-bakeshop-supervisor',
        'role' => 'supervisor',
        'source' => 'bakeshop',
        'email' => 'updated-bakeshop-supervisor@example.test',
    ]);
    ob_start();
    bakeshopPageAccount();
    $accountHtml = (string)ob_get_clean();
    btUser('account page renders account details heading', str_contains($accountHtml, 'Account Details'));
    btUser('account page renders change password heading', str_contains($accountHtml, 'Change Password'));

    app()->setUser([
        'id' => $createdId,
        'sub' => 'bakeshop:' . $createdId,
        'username' => 'test-bakeshop-admin',
        'role' => 'admin',
        'source' => 'bakeshop',
        'email' => 'test-bakeshop-admin@example.test',
    ]);
    ob_start();
    bakeshopPageSettings();
    $settingsHtml = (string)ob_get_clean();
    btUser('settings page renders access settings heading', str_contains($settingsHtml, 'Access Settings'));
    btUser('settings page renders save access settings button', str_contains($settingsHtml, 'Save Access Settings'));
    btUser('settings page renders seeded units heading', str_contains($settingsHtml, 'Seeded Units'));

    $bootstrapStmt = $db->prepare("SELECT id, email FROM bakeshop_users WHERE username = 'bakeshopadmin' LIMIT 1");
    $bootstrapStmt->execute();
    $bootstrapUser = $bootstrapStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    app()->setUser([
        'id' => (int)($bootstrapUser['id'] ?? 0),
        'sub' => 'bakeshop:' . (int)($bootstrapUser['id'] ?? 0),
        'username' => 'bakeshopadmin',
        'role' => 'admin',
        'source' => 'bakeshop',
        'email' => (string)($bootstrapUser['email'] ?? 'admin@bakeshop.local'),
    ]);
    ob_start();
    bakeshopPageAccount();
    $bootstrapAccountHtml = (string)ob_get_clean();
    btUser('bootstrap account page renders onboarding note', str_contains($bootstrapAccountHtml, 'This is the bootstrap admin account.'));

    ob_start();
    bakeshopPageSupervisor();
    $bootstrapWorkspaceHtml = (string)ob_get_clean();
    btUser('bootstrap workspace renders onboarding notice', str_contains($bootstrapWorkspaceHtml, 'Bootstrap Admin Onboarding'));
    btUser('bootstrap workspace keeps handoff link', str_contains($bootstrapWorkspaceHtml, '/admin/bakeshop/users?onboarding=bootstrap'));
    btUser('bootstrap workspace stays on supervisor shell', str_contains($bootstrapWorkspaceHtml, 'Work Areas'));

    bakeshopChangeOwnPassword($supervisorId, 'supervisor5678', 'supervisor9012', 'supervisor9012');
    $tokenVersionAfterPasswordChange = bakeshopUserTokenVersion($supervisorId);
    btUser('own password change bumps token version', $tokenVersionAfterPasswordChange === 2, (string)$tokenVersionAfterPasswordChange);
    $reAuth = bakeshop_cap_kernel_auth_authenticate_1([
        'username' => '@bakeshop:test-bakeshop-supervisor',
        'password' => 'supervisor9012',
    ]);
    btUser('own password change updates module auth', is_array($reAuth) && ($reAuth['source'] ?? '') === 'bakeshop', json_encode($reAuth, JSON_UNESCAPED_SLASHES));
    btUser('re-auth payload includes bumped token version', is_array($reAuth) && (int)(($reAuth['user']['token_version'] ?? 0)) === 2, json_encode($reAuth, JSON_UNESCAPED_SLASHES));

    bakeshopUserDeactivate($supervisorId, $actor);
    $tokenVersionAfterDeactivate = bakeshopUserTokenVersion($supervisorId);
    btUser('deactivation bumps token version', $tokenVersionAfterDeactivate === 3, (string)$tokenVersionAfterDeactivate);

    $passwordRateLimit = bakeshopConsumePasswordChangeRateLimit($supervisorId);
    btUser('password change rate limit helper is available', is_array($passwordRateLimit) && array_key_exists('limited', $passwordRateLimit), json_encode($passwordRateLimit, JSON_UNESCAPED_SLASHES));
} finally {
    app()->setUser(is_array($previousUser) ? $previousUser : []);
    $cleanupStmt->execute();
    if ($bootstrapCreatedId !== null) {
        $db->prepare('DELETE FROM bakeshop_users WHERE id = ?')->execute([$bootstrapCreatedId]);
    } elseif (is_array($bootstrapOriginal)) {
        $db->prepare(
            'UPDATE bakeshop_users SET email = ?, password_hash = ?, full_name = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?'
        )->execute([
            (string)($bootstrapOriginal['email'] ?? 'admin@bakeshop.local'),
            (string)($bootstrapOriginal['password_hash'] ?? ''),
            (string)($bootstrapOriginal['full_name'] ?? 'Bakeshop Admin'),
            (string)($bootstrapOriginal['role'] ?? 'admin'),
            (int)($bootstrapOriginal['is_active'] ?? 1),
            (int)$bootstrapOriginal['id'],
        ]);
    }
}

$appLogRaw = (string)@file_get_contents($appLogPath);
$errorLogRaw = (string)@file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
btUser('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btUser('no error.log errors', $errorLog === '', $errorLog);

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