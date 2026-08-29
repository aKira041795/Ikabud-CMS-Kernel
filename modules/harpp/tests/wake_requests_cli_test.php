<?php

declare(strict_types=1);

use Harpp\Services\HarppBridgeService;
use Harpp\Services\HarppRunService;
$root = dirname(__DIR__, 3);
$logs = [$root.'/storage/logs/app.log', $root.'/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root.'/bootstrap.php';
$tenantId = max(1, (int)($_SERVER['argv'][1] ?? 1));
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__).'/helpers.php';
require_once $root . '/tests/harness/TestHarness.php';
$manifest = json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'), true, 512, JSON_THROW_ON_ERROR);
$pdo = app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/019_harpp_runner_wake_requests.sql'));
$db = new \Ikabud\Kernel\Contracts\ModuleDB($pdo, 'harpp', (array)$manifest['owns_tables'], (array)$manifest['reads_tables']);
$owner = $db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($owner)) throw new RuntimeException('HARPP owner missing.');
$owner['id'] = (int)$owner['id'];
$owner['source'] = 'harpp';
$bridgeActor = $owner;
$bridgeActor['source'] = 'harpp_bridge';
$memberActor = ['id' => $owner['id'], 'role' => 'member', 'source' => 'harpp'];
$runnerKey = 'wake-test-' . bin2hex(random_bytes(4));
$requestIds = [];
$notificationIds = [];

$h = new TestHarness('harpp-wake-requests');
$assert = static function (string $name, bool $condition) use ($h): void { $h->test($name, $condition); };

try {
    $runService = new HarppRunService($db);
    $bridge = new HarppBridgeService($db);
    $registered = $bridge->registerRunner($bridgeActor, ['runner_key' => $runnerKey, 'display_name' => 'Wake Test Runner', 'capabilities' => ['desktop']], $tenantId);
    $assert('runner registered', !empty($registered['ok']));

    $requested = $runService->requestWake($owner, $runnerKey, []);
    $requestId = (int)($requested['data']['request']['id'] ?? 0);
    $requestIds[] = $requestId;
    $assert('owner requests pending wake', !empty($requested['ok']) && $requestId > 0 && ($requested['data']['request']['status'] ?? '') === 'pending');
    $s = $db->prepare("SELECT id FROM harpp_notifications WHERE user_id=:user_id AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.event'))='runner.wake_requested' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.request_id'))=:request_id ORDER BY id DESC LIMIT 1");
    $s->execute([':user_id' => $owner['id'], ':request_id' => (string)$requestId]);
    $notificationId = (int)$s->fetchColumn();
    if ($notificationId > 0) $notificationIds[] = $notificationId;
    $assert('wake request creates owner notification', $notificationId > 0);

    $duplicate = $runService->requestWake($owner, $runnerKey, []);
    $assert('wake request coalesces within five minutes', !empty($duplicate['ok']) && (int)($duplicate['data']['request']['id'] ?? 0) === $requestId && !empty($duplicate['data']['request']['duplicate']));
    $unknown = $runService->requestWake($owner, $runnerKey . '-missing', []);
    $assert('unknown runner is rejected', empty($unknown['ok']) && (int)($unknown['status'] ?? 0) === 404);
    $forbidden = $runService->requestWake($memberActor, $runnerKey, []);
    $assert('member cannot request wake', empty($forbidden['ok']) && (int)($forbidden['status'] ?? 0) === 403);

    $claimed = $bridge->claimWake($bridgeActor, ['runner_key' => $runnerKey], $tenantId);
    $claimToken = (string)($claimed['data']['claim_token'] ?? '');
    $assert('bridge claims pending wake', !empty($claimed['ok']) && $claimToken !== '' && ($claimed['data']['request']['status'] ?? '') === 'claimed' && ($claimed['data']['request']['runner_key'] ?? '') === $runnerKey);
    $emptyClaim = $bridge->claimWake($bridgeActor, ['runner_key' => $runnerKey], $tenantId);
    $assert('claim returns null without pending wake', !empty($emptyClaim['ok']) && array_key_exists('request', $emptyClaim['data']) && $emptyClaim['data']['request'] === null);
    $delivered = $bridge->deliverWake($bridgeActor, $requestId, ['claim_token' => $claimToken], $tenantId);
    $assert('matching token delivers wake', !empty($delivered['ok']) && ($delivered['data']['request']['status'] ?? '') === 'delivered');
    $secondDeliver = $bridge->deliverWake($bridgeActor, $requestId, ['claim_token' => $claimToken], $tenantId);
    $assert('second delivery rejects consumed claim', empty($secondDeliver['ok']) && (int)($secondDeliver['status'] ?? 0) === 409 && ($secondDeliver['code'] ?? '') === 'claim_invalid');

    // A wake claim that was never acknowledged (relay died) is reaped to failed on
    // the next claim pass so it cannot accumulate as a stuck 'claimed' row.
    $staleRequest = $runService->requestWake($owner, $runnerKey, []);
    $staleRequestId = (int)($staleRequest['data']['request']['id'] ?? 0);
    $requestIds[] = $staleRequestId;
    $staleClaim = $bridge->claimWake($bridgeActor, ['runner_key' => $runnerKey], $tenantId);
    $staleToken = (string)($staleClaim['data']['claim_token'] ?? '');
    $db->prepare("UPDATE harpp_runner_wake_requests SET claimed_at=DATE_SUB(NOW(6),INTERVAL 15 MINUTE) WHERE id=:id")->execute([':id' => $staleRequestId]);
    $bridge->claimWake($bridgeActor, ['runner_key' => $runnerKey], $tenantId); // runs the reaper
    $staleAfter = $db->query("SELECT status FROM harpp_runner_wake_requests WHERE id=" . (int)$staleRequestId)->fetchColumn();
    $assert('stale unacknowledged claim is reaped to failed', (string)$staleAfter === 'failed');


    $failedRequest = $runService->requestWake($owner, $runnerKey, []);
    $failedRequestId = (int)($failedRequest['data']['request']['id'] ?? 0);
    $requestIds[] = $failedRequestId;
    $failedClaim = $bridge->claimWake($bridgeActor, ['runner_key' => $runnerKey], $tenantId);
    $failedToken = (string)($failedClaim['data']['claim_token'] ?? '');
    $failed = $bridge->failWake($bridgeActor, $failedRequestId, ['claim_token' => $failedToken, 'error' => 'Magic packet unavailable'], $tenantId);
    $assert('matching token records failed wake', !empty($failed['ok']) && ($failed['data']['request']['status'] ?? '') === 'failed' && ($failed['data']['request']['last_error'] ?? '') === 'Magic packet unavailable');

    $listed = $runService->listWakeRequests($owner, $runnerKey);
    $statuses = array_column((array)($listed['data']['requests'] ?? []), 'status');
    $assert('owner lists wake history', !empty($listed['ok']) && in_array('delivered', $statuses, true) && in_array('failed', $statuses, true));
} finally {
    foreach (array_unique(array_filter($requestIds)) as $id) $db->prepare('DELETE FROM harpp_runner_wake_requests WHERE id=:id')->execute([':id' => $id]);
    foreach (array_unique(array_filter($notificationIds)) as $id) $db->prepare('DELETE FROM harpp_notifications WHERE id=:id')->execute([':id' => $id]);
    $db->prepare('DELETE FROM harpp_runners WHERE runner_key=:key')->execute([':key' => $runnerKey]);
}

$h->done();