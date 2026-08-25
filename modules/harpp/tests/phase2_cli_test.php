<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/bootstrap.php';

$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
if (!class_exists(\Harpp\Services\HarppAuthService::class)) {
    require dirname(__DIR__) . '/helpers.php';
}

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppUserService;
use Harpp\Services\HarppPasswordResetService;
use Harpp\Services\HarppSettingsService;

require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-phase2');
$h->fingerprint('modules/harpp/services/HarppAuthService.php');
$h->fingerprint('modules/harpp/services/HarppUserService.php');
$h->fingerprint('modules/harpp/services/HarppPasswordResetService.php');
$h->fingerprint('modules/harpp/services/HarppSettingsService.php');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };

$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$db = new \Ikabud\Kernel\Contracts\ModuleDB(
    app()->dbForTenant($tenantId),
    'harpp',
    (array)($manifest['owns_tables'] ?? []),
    (array)($manifest['reads_tables'] ?? [])
);
$stmt = $db->prepare('SELECT id, password_hash FROM harpp_users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => 'owner@harpp.local']);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($owner)) {
    throw new RuntimeException('HARPP bootstrap owner was not migrated.');
}
$ownerId = (int)$owner['id'];
$assert('bootstrap owner is deterministic user id 1', $ownerId === 1);
$originalHash = (string)$owner['password_hash'];
$temporaryPassword = 'HarppSecure42!';
$newPassword = 'HarppReset84!';
$managedUserId = 0;

try {
    $auth = new HarppAuthService($db);
    $users = new HarppUserService($db);
    $ownerActor = ['id'=>$ownerId, 'role'=>'owner', 'source'=>'harpp'];
    $memberActor = ['id'=>3, 'role'=>'member', 'source'=>'harpp'];
    $email = 'managed-' . bin2hex(random_bytes(4)) . '@harpp.local';
    $createdUser = $users->create($ownerActor, ['email'=>$email, 'full_name'=>'Managed User', 'role'=>'member', 'is_active'=>true, 'password'=>'ManagedUser42!']);
    $managedUserId = (int)($createdUser['data']['user']['id'] ?? 0);
    $assert('owner can create and list users', !empty($createdUser['ok']) && $managedUserId > 0 && !empty($users->list($ownerActor)['ok']));
    $assert('member cannot access user management', empty($users->list($memberActor)['ok']) && (int)($users->list($memberActor)['status'] ?? 0) === 403);
    $assert('owner cannot self-demote', empty($users->update($ownerActor, $ownerId, ['role'=>'admin'])['ok']));
    $assert('admin cannot self-demote', empty($users->update(['id'=>2,'role'=>'admin','source'=>'harpp'], 2, ['role'=>'member'])['ok']));
    $assert('last active owner cannot be deleted', empty($users->delete(['id'=>2,'role'=>'admin','source'=>'harpp'], $ownerId)['ok']));
    $deletedUser = $users->delete($ownerActor, $managedUserId);
    $listedUsers = (array)($users->list($ownerActor)['data']['users'] ?? []);
    $assert('delete is soft and default list excludes deleted users', !empty($deletedUser['ok']) && !in_array($managedUserId, array_column($listedUsers, 'id'), true));
    $assert('soft-deleted user cannot authenticate', empty($auth->authenticate($email, 'ManagedUser42!')['ok']));

    $blocked = $auth->authenticate('owner@harpp.local', 'anything');
    $assert('blocked bootstrap hash forces reset', empty($blocked['ok']) && ($blocked['code'] ?? '') === 'password_reset_required');

    $db->prepare('UPDATE harpp_users SET password_hash = :hash WHERE id = :id')->execute([
        ':hash' => password_hash($temporaryPassword, PASSWORD_BCRYPT), ':id' => $ownerId,
    ]);

    $wrong = $auth->login('owner@harpp.local', 'wrong-password');
    $assert('wrong password rejected', empty($wrong['ok']) && (int)($wrong['status'] ?? 0) === 401);

    $login = $auth->login('owner@harpp.local', $temporaryPassword);
    $token = (string)($login['data']['token'] ?? '');
    $claims = $token !== '' ? app()->jwt()->verify($token) : null;
    $assert('bootstrap user login succeeds', !empty($login['ok']));
    $assert('harpp_token JWT issued', is_array($claims) && (int)($claims['user_id'] ?? 0) === $ownerId && (int)($claims['store_id'] ?? 0) === $tenantId);
    $assert('harpp_token cookie set', !empty($login['data']['cookie_set']) && ($_COOKIE['harpp_token'] ?? '') === $token);

    $_SERVER['HTTP_HOST'] = 'harpp.test';
    // Deterministic token capture: clear prior reset-token log lines so this
    // run's forgotPassword token is the only one present in app.log.
    $appLog = dirname(__DIR__, 3) . '/storage/logs/app.log';
    if (is_file($appLog)) {
        file_put_contents($appLog, '');
    }
    $forgot = (new HarppPasswordResetService($db))->forgotPassword('owner@harpp.local');
    $log = is_file($appLog) ? (string)file_get_contents($appLog) : '';
    preg_match('/token=([a-f0-9]{64})/', $log, $tokenMatch);
    $resetToken = (string)($tokenMatch[1] ?? '');
    $assert('forgot-password creates reset', !empty($forgot['ok']) && $resetToken !== '');

    $reset = (new HarppPasswordResetService($db))->resetPassword($resetToken, $newPassword, $newPassword);
    $postResetLogin = $auth->authenticate('owner@harpp.local', $newPassword);
    $assert('reset-password flow completes', !empty($reset['ok']) && !empty($postResetLogin['ok']));

    $settings = new HarppSettingsService($db);
    $saved = $settings->save(['push_enabled' => '0', 'notification_channels' => ['push']], $tenantId);
    $loaded = $settings->get($tenantId);
    $persisted = (string)($loaded['data']['settings']['push_enabled'] ?? '') === '0'
        && (string)($loaded['data']['settings']['notification_channels'] ?? '') === 'push';
    $assert('settings get/save persists', !empty($saved['ok']) && !empty($loaded['ok']) && $persisted);
} finally {
    if ($managedUserId > 0) $db->prepare('DELETE FROM harpp_users WHERE id=:id')->execute([':id'=>$managedUserId]);
    $db->prepare('UPDATE harpp_users SET password_hash = :hash WHERE id = :id')->execute([':hash' => $originalHash, ':id' => $ownerId]);
    $db->prepare('DELETE FROM harpp_password_resets WHERE user_id = :id')->execute([':id' => $ownerId]);
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key IN ('push_enabled', 'notification_channels')")->execute();
}

ob_end_flush();
$h->done();
