<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$logs = [$root . '/storage/logs/app.log', $root . '/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
loadModuleRoutes([]);
require_once dirname(__DIR__) . '/handlers.php';

require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-phase4');
$h->fingerprint('modules/harpp/handlers.php');
$h->fingerprint('modules/harpp/assets/sw.js');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$capture = static function (callable $handler): array {
    http_response_code(200);
    ob_start();
    $handler();
    return [http_response_code(), (string)ob_get_clean()];
};

[$status, $html] = $capture(static fn() => harppPageLogin());
$assert('login shell is unauthenticated HTTP 200', $status === 200 && str_contains($html, 'login-form'));

unset($_COOKIE['harpp_token']);
[$status] = $capture(static fn() => harppPageMessenger());
$assert('protected shell rejects unauthenticated request', $status === 302);

$owner = harppDb()->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($owner)) throw new RuntimeException('HARPP owner missing.');
$owner['source'] = 'harpp';
$_COOKIE['harpp_token'] = (new Harpp\Services\HarppAuthService())->issueToken($owner);
$pages = [
    'messenger' => static fn() => harppPageMessenger(),
    'decisions' => static fn() => harppPageDecisions(),
    'settings' => static fn() => harppPageSettings(),
    'notifications' => static fn() => harppPageNotifications(),
];
foreach ($pages as $name => $handler) {
    [$status, $html] = $capture($handler);
    $assert($name . ' shell authenticated HTTP 200', $status === 200 && str_contains($html, 'HARPP'), 'status=' . $status);
}
[$status, $html] = $capture(static fn() => harppPageDecisionDetail(['id' => 1]));
$assert('decision detail shell authenticated HTTP 200', $status === 200 && str_contains($html, 'decision-detail'));

$assets = [
    'service worker' => [static fn() => harppServiceWorker(), 'push'],
    'manifest' => [static fn() => harppManifest(), 'HARPP'],
    'icon' => [static fn() => harppIcon(), '<svg'],
];
foreach ($assets as $name => [$handler, $needle]) {
    [$status, $body] = $capture($handler);
    $assert($name . ' asset reachable', $status === 200 && str_contains($body, $needle));
    if ($name === 'service worker') $assert('service worker has push handler', str_contains($body, "addEventListener('push'"));
    if ($name === 'manifest') $assert('manifest parses as JSON', is_array(json_decode($body, true)) && json_last_error() === JSON_ERROR_NONE);
}

[$status, $body] = $capture(static fn() => harppPushPublicKey());
$keyResponse = json_decode($body, true);
$publicKey = (string)($keyResponse['data']['public_key'] ?? '');
$assert('VAPID public key endpoint returns key', $status === 200 && !empty($keyResponse['ok']) && preg_match('/^[A-Za-z0-9_-]{32,}$/', $publicKey) === 1);

$routes = require dirname(__DIR__) . '/routes.php';
foreach (['/harpp/login', '/harpp', '/harpp/decisions', '/harpp/settings', '/harpp/notifications', '/harpp/sw.js', '/harpp/manifest.webmanifest', '/harpp/icon.svg'] as $route) {
    $assert('route map ' . $route, isset($routes['GET'][$route]));
}

$errorLog = is_file($logs[1]) ? trim((string)file_get_contents($logs[1])) : '';
$appLog = is_file($logs[0]) ? (string)file_get_contents($logs[0]) : '';
$assert('error.log has no findings', $errorLog === '', $errorLog);
$assert('app.log has no error findings', !str_contains(strtolower($appLog), '[error]') && !str_contains(strtolower($appLog), '[critical]'));

ob_end_flush();
$h->done();
