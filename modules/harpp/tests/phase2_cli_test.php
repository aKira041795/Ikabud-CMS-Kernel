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
use Harpp\Services\HarppPasswordResetService;
use Harpp\Services\HarppSettingsService;

require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-phase2');
$h->fingerprint('modules/harpp/services/HarppAuthService.php');
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
$originalHash = (string)$owner['password_hash'];
$temporaryPassword = 'HarppSecure42!';
$newPassword = 'HarppReset84!';

try {
    $auth = new HarppAuthService($db);
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
    $db->prepare('UPDATE harpp_users SET password_hash = :hash WHERE id = :id')->execute([':hash' => $originalHash, ':id' => $ownerId]);
    $db->prepare('DELETE FROM harpp_password_resets WHERE user_id = :id')->execute([':id' => $ownerId]);
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key IN ('push_enabled', 'notification_channels')")->execute();
}

ob_end_flush();
$h->done();
