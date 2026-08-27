<?php

declare(strict_types=1);

/**
 * HARPP password management integration test.
 *
 * Covers both new password capabilities:
 *   1. Self-service change password (current password verified, session kept).
 *   2. Admin reset password (role-scoped, self-reset rejected).
 * Runs against the migrated live tenant and cleans up throwaway users.
 */

$root = dirname(__DIR__, 3);
require $root . '/bootstrap.php';

$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
if (!class_exists(\Harpp\Services\HarppAuthService::class)) {
    require dirname(__DIR__) . '/helpers.php';
}
require_once dirname(__DIR__) . '/handlers.php';

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppUserService;
use Harpp\Services\HarppServiceResult;

require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-password-management'); // @phpstan-ignore-line
$h->fingerprint('modules/harpp/services/HarppAuthService.php');
$h->fingerprint('modules/harpp/services/HarppUserService.php');
$h->fingerprint('modules/harpp/handlers.php');
$h->fingerprint('modules/harpp/routes.php');
$assert = static function (string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };

$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$db = new \Ikabud\Kernel\Contracts\ModuleDB(
    app()->dbForTenant($tenantId),
    'harpp',
    (array)($manifest['owns_tables'] ?? []),
    (array)($manifest['reads_tables'] ?? [])
);
$ownerRow = $db->query("SELECT id, role FROM harpp_users WHERE role='owner' AND is_active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$memberRow = $db->query("SELECT id, role FROM harpp_users WHERE role='member' AND is_active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($ownerRow) || !is_array($memberRow)) {
    throw new RuntimeException('HARPP owner/member fixtures are required.');
}
$owner = ['id' => (int)$ownerRow['id'], 'role' => 'owner', 'source' => 'harpp'];
$member = ['id' => (int)$memberRow['id'], 'role' => 'member', 'source' => 'harpp'];

$auth = new HarppAuthService($db);
$users = new HarppUserService($db);
$createdId = 0;

try {
    $h->section('Route and handler wiring'); // @phpstan-ignore-line
    $routes = require dirname(__DIR__) . '/routes.php';
    $changeRoute = (string)($routes['POST']['/api/v1/harpp/auth/change-password'] ?? '');
    $resetRoute = (string)($routes['POST']['/api/v1/harpp/users/{id}/reset-password'] ?? '');
    $assert('change-password route registered', $changeRoute === 'harpp:harppAuthChangePassword');
    $assert('user reset-password route registered', $resetRoute === 'harpp:harppUserResetPassword');
    $assert('change-password handler defined', function_exists('harppAuthChangePassword'));
    $assert('user reset-password handler defined', function_exists('harppUserResetPassword'));
    $handlersSrc = (string)file_get_contents($root . '/modules/harpp/handlers.php');
    foreach (['harppAuthChangePassword', 'harppUserResetPassword'] as $fn) {
        $fnPos = strpos($handlersSrc, 'function ' . $fn);
        $fnLineStart = strrpos(substr($handlersSrc, 0, $fnPos), "\n") + 1;
        $fnLineEnd = strpos($handlersSrc, "\n", $fnPos);
        $fnLine = substr($handlersSrc, $fnLineStart, $fnLineEnd - $fnLineStart);
        $assert($fn . ' enforces CSRF before auth', strpos($fnLine, 'harppRequireCsrf()') !== false && strpos($fnLine, 'harppAuthenticated(') !== false && strpos($fnLine, 'harppRequireCsrf()') < strpos($fnLine, 'harppAuthenticated('));
    }

    $h->section('Self-service change password'); // @phpstan-ignore-line
    $email = 'pw-' . bin2hex(random_bytes(4)) . '@harpp.local';
    $original = 'OriginalPass42!';
    $created = $users->create($owner, ['email' => $email, 'full_name' => 'Password Fixture', 'role' => 'member', 'is_active' => true, 'password' => $original]);
    $createdId = (int)($created['data']['user']['id'] ?? 0);
    $assert('fixture user created', !empty($created['ok']) && $createdId > 0);

    $missing = $auth->changePassword($createdId, []);
    $assert('change requires all fields', empty($missing['ok']));
    $wrong = $auth->changePassword($createdId, ['current_password' => 'WrongPassword42!', 'new_password' => 'NextPassword42!', 'confirm_password' => 'NextPassword42!']);
    $assert('wrong current password rejected', empty($wrong['ok']) && ($wrong['code'] ?? '') === 'invalid_current_password' && ($wrong['status'] ?? 0) === 403);
    $mismatch = $auth->changePassword($createdId, ['current_password' => $original, 'new_password' => 'NextPassword42!', 'confirm_password' => 'DifferentPass42!']);
    $assert('confirmation mismatch rejected', empty($mismatch['ok']));
    $weak = $auth->changePassword($createdId, ['current_password' => $original, 'new_password' => 'weak', 'confirm_password' => 'weak']);
    $assert('weak new password rejected', empty($weak['ok']));
    $same = $auth->changePassword($createdId, ['current_password' => $original, 'new_password' => $original, 'confirm_password' => $original]);
    $assert('same-as-current new password rejected', empty($same['ok']));
    $authResult = $auth->authenticate($email, $original);
    $assert('original password authenticates before change', !empty($authResult['ok']));

    $next = 'NextPassword84!';
    $changed = $auth->changePassword($createdId, ['current_password' => $original, 'new_password' => $next, 'confirm_password' => $next]);
    $newToken = (string)($changed['data']['token'] ?? '');
    $newClaims = $newToken !== '' ? app()->jwt()->verify($newToken) : null;
    $assert('self-service change succeeds and re-issues session token', !empty($changed['ok']) && $newToken !== '' && is_array($newClaims) && (int)($newClaims['user_id'] ?? 0) === $createdId && !empty($changed['data']['cookie_set']));
    $oldLogin = $auth->authenticate($email, $original);
    $newLogin = $auth->authenticate($email, $next);
    $assert('old password stops working after change', empty($oldLogin['ok']));
    $assert('new password authenticates after change', !empty($newLogin['ok']));
    $freshUser = $auth->findActiveUser($createdId);
    $assert('changed user still active and returned', is_array($freshUser) && (int)($freshUser['id'] ?? 0) === $createdId);

    $h->section('Admin reset password'); // @phpstan-ignore-line
    $memberDenied = $users->resetPassword($member, $createdId, 'ResetPass42!');
    $assert('member cannot reset passwords', empty($memberDenied['ok']) && ($memberDenied['status'] ?? 0) === 403);
    $missingUser = $users->resetPassword($owner, 999999, 'ResetPass42!');
    $assert('reset of unknown user returns 404', empty($missingUser['ok']) && ($missingUser['status'] ?? 0) === 404);
    $selfReset = $users->resetPassword($owner, $owner['id'], 'ResetPass42!');
    $assert('self-reset is rejected (use Change password)', empty($selfReset['ok']) && ($selfReset['code'] ?? '') === 'self_protected');
    $adminResetOwner = $users->resetPassword(['id' => 2, 'role' => 'admin', 'source' => 'harpp'], $owner['id'], 'ResetPass42!');
    $assert('admin cannot reset an owner', empty($adminResetOwner['ok']) && ($adminResetOwner['code'] ?? '') === 'owner_required');
    $weakReset = $users->resetPassword($owner, $createdId, 'weak');
    $assert('weak reset password rejected', empty($weakReset['ok']));

    $resetPassword = 'ResetPass84!';
    $reset = $users->resetPassword($owner, $createdId, $resetPassword);
    $assert('owner resets member password', !empty($reset['ok']) && !empty($reset['data']['reset']));
    $afterResetOld = $auth->authenticate($email, $next);
    $afterResetNew = $auth->authenticate($email, $resetPassword);
    $assert('old password fails after admin reset', empty($afterResetOld['ok']));
    $assert('new reset password authenticates', !empty($afterResetNew['ok']));

    $adminEmail = 'pw-admin-' . bin2hex(random_bytes(4)) . '@harpp.local';
    $adminCreated = $users->create($owner, ['email' => $adminEmail, 'full_name' => 'Reset Admin', 'role' => 'admin', 'is_active' => true, 'password' => 'AdminStart42!']);
    $adminId = (int)($adminCreated['data']['user']['id'] ?? 0);
    $adminActor = ['id' => $adminId, 'role' => 'admin', 'source' => 'harpp'];
    $adminReset = $users->resetPassword($adminActor, $createdId, 'MemberReset42!');
    $memberAfterAdmin = $auth->authenticate($email, 'MemberReset42!');
    $assert('admin resets a member password', !empty($adminReset['ok']) && !empty($memberAfterAdmin['ok']));

    $h->section('Audit trail'); // @phpstan-ignore-line
    $appLog = $root . '/storage/logs/app.log';
    $log = is_file($appLog) ? (string)file_get_contents($appLog) : '';
    $assert('self-service change is audit-logged', str_contains($log, 'password.changed') && str_contains($log, 'channel'));
    $assert('admin reset is audit-logged', str_contains($log, 'password.reset'));
} finally {
    if ($createdId > 0) $db->prepare('DELETE FROM harpp_users WHERE id=:id')->execute([':id' => $createdId]);
    if (isset($adminId) && $adminId > 0) $db->prepare('DELETE FROM harpp_users WHERE id=:id')->execute([':id' => $adminId]);
}
$h->done();
