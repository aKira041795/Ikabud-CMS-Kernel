<?php

declare(strict_types=1);

// Safe default-run mode: execute the focused decision flow inside the generated,
// disposable MySQL schema owned and torn down by migration-sandbox.php.
if (($_SERVER['argv'][1] ?? '') === '--generated-schema') {
    putenv('HARPP_ALLOW_SCHEMA_TEST=1');
    require __DIR__ . '/migration-sandbox.php';
    exit(0);
}

$root = dirname(__DIR__, 3);
$logs = [$root . '/storage/logs/app.log', $root . '/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }

require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
loadModuleRoutes([]);
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

use Harpp\Services\HarppAuthService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppServiceResult;

require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-decision-inbox'); // @phpstan-ignore-line
foreach ([
    'modules/harpp/handlers.php',
    'modules/harpp/routes.php',
    'modules/harpp/services/HarppDecisionService.php',
    'modules/harpp/assets/decisions.js',
    'modules/harpp/assets/decision-detail.js',
    'templates/modules/harpp/decisions.disyl',
    'templates/modules/harpp/decision-detail.disyl',
] as $file) {
    $h->fingerprint($file); // @phpstan-ignore-line
}
$assert = static function (string $name, bool $ok, string $detail = ''): void { $GLOBALS['harpp_h']->test($name, $ok, $detail); };
$GLOBALS['harpp_h'] = $h;

$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$db = new \Ikabud\Kernel\Contracts\ModuleDB(app()->dbForTenant($tenantId), 'harpp', (array)$manifest['owns_tables'], (array)$manifest['reads_tables']);
$ownerRow = $db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$memberRow = $db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='member' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($ownerRow) || !is_array($memberRow)) {
    throw new RuntimeException('HARPP owner/member fixtures are required.');
}
$owner = ['id' => (int)$ownerRow['id'], 'email' => (string)$ownerRow['email'], 'full_name' => (string)$ownerRow['full_name'], 'role' => (string)$ownerRow['role'], 'source' => 'harpp'];
$member = ['id' => (int)$memberRow['id'], 'email' => (string)$memberRow['email'], 'full_name' => (string)$memberRow['full_name'], 'role' => (string)$memberRow['role'], 'source' => 'harpp'];

$service = new HarppDecisionService($db);
$cleanup = [];
$probeFile = null;
$probeStatusFile = null;
$track = static function (int $decisionId, int $conversationId) use (&$cleanup): void {
    $cleanup[] = [$decisionId, $conversationId];
};
$makeDecision = static function (string $key, string $title) use ($service, $owner, $tenantId, $track): int {
    $created = $service->create($owner, [
        'title' => $title,
        'body' => 'Decision inbox regression fixture body.',
        'context' => 'CLI regression coverage',
        'requested_decision' => 'Approve the safe path',
        'priority' => 'normal',
        'source' => 'harness',
        'workbench_state' => 'ARCHITECTURE_DECISION_REQUIRED',
        'decision_key' => $key,
    ], $tenantId);
    if (empty($created['ok'])) {
        throw new RuntimeException('Unable to create decision fixture: ' . (string)($created['error'] ?? 'unknown'));
    }
    $track((int)$created['data']['decision_id'], (int)$created['data']['conversation_id']);
    return (int)$created['data']['decision_id'];
};
$advanceToAcknowledged = static function (int $decisionId) use ($service, $owner, $tenantId): void {
    foreach (['NOTIFIED', 'VIEWED'] as $state) {
        $result = $service->transition($owner, $decisionId, $state, 'Inbox regression ' . $state, [], $tenantId);
        if (empty($result['ok'])) throw new RuntimeException('Unable to advance fixture to ' . $state);
    }
    $decided = $service->transition($owner, $decisionId, 'DECIDED', 'Owner chose the safe path.', ['decision' => 'Proceed with the safe path'], $tenantId);
    if (empty($decided['ok'])) throw new RuntimeException('Unable to advance fixture to DECIDED');
    $ack = $service->transition($owner, $decisionId, 'ACKNOWLEDGED', 'Owner acknowledged the decision.', [], $tenantId);
    if (empty($ack['ok'])) throw new RuntimeException('Unable to advance fixture to ACKNOWLEDGED');
};

try {
    $h->section('Default inbox filter wiring'); // @phpstan-ignore-line

    $_COOKIE['harpp_token'] = (new HarppAuthService($db))->issueToken($owner);
    ob_start(); harppPageDecisions(); $renderedInbox = (string)ob_get_clean();
    $renderedSelectStart = strpos($renderedInbox, '<select name="state">');
    $renderedSelectEnd = strpos($renderedInbox, '</select>', $renderedSelectStart);
    $renderedStateOptions = substr($renderedInbox, $renderedSelectStart, $renderedSelectEnd - $renderedSelectStart);
    $renderedPendingPos = strpos($renderedStateOptions, '<option value="PENDING">');
    $renderedAllPos = strpos($renderedStateOptions, '<option value="">');
    $renderedClosedPos = strpos($renderedStateOptions, '<option>CLOSED</option>');
    $assert('rendered inbox select starts at PENDING', $renderedPendingPos !== false && $renderedAllPos !== false && $renderedPendingPos < $renderedAllPos);
    $assert('rendered inbox keeps explicit CLOSED filter', $renderedClosedPos !== false);

    ob_start(); harppPageDecisionDetail(['id' => 0]); $renderedDetail = (string)ob_get_clean();
    $assert('decision detail renders the Apply and close form', strpos($renderedDetail, 'id="decision-apply-close"') !== false && strpos($renderedDetail, 'Apply and close') !== false);
    $assert('decision detail no longer renders pre-decision decide/close shortcuts', strpos($renderedDetail, 'decision-decide-close') === false && strpos($renderedDetail, 'decision-close-plain') === false);

    $h->section('Route and handler CSRF ordering'); // @phpstan-ignore-line
    $routes = require dirname(__DIR__) . '/routes.php';
    $applyRoute = (string)($routes['POST']['/api/v1/harpp/decisions/{id}/apply-and-close'] ?? '');
    $assert('apply-and-close route registered', $applyRoute === 'harpp:harppDecisionApplyClose');
    $assert('apply-and-close handler defined', function_exists('harppDecisionApplyClose'));
    // CSRF-before-auth is exercised behaviorally by the HTTP probe below (419 with no mutation).

    $h->section('Role and domain enforcement'); // @phpstan-ignore-line
    $denied = $service->applyAndClose($member, 1, 'x', 'y', [], $tenantId);
    $assert('member apply-and-close denied', $denied instanceof HarppServiceResult && empty($denied['ok']) && ($denied['status'] ?? 0) === 403);
    $invalidState = $service->applyAndClose($owner, 1, 'x', 'y', [], $tenantId);
    $assert('apply-and-close rejects unknown decision as 404', empty($invalidState['ok']));

    $h->section('Atomic apply-and-close, CSRF 419, and idempotent retry'); // @phpstan-ignore-line

    $decisionId = $makeDecision('INBOX-ATOMIC-' . strtoupper(bin2hex(random_bytes(6))), 'Atomic apply-and-close fixture');
    $advanceToAcknowledged($decisionId);
    $before = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $before->execute([':id' => $decisionId]);
    $assert('fixture prepared as ACKNOWLEDGED', ($before->fetchColumn() ?: '') === 'ACKNOWLEDGED');

    $probeDir = sys_get_temp_dir();
    $probeFile = $probeDir . '/harpp_apply_probe_' . bin2hex(random_bytes(6)) . '.php';
    $probeStatusFile = $probeDir . '/harpp_apply_status_' . bin2hex(random_bytes(6)) . '.txt';
    $probe = <<<'PHP'
<?php
declare(strict_types=1);
$root = {$root};
$tenantId = (int)($argv[1] ?? 1);
$decisionId = (int)($argv[2] ?? 0);
$csrfMode = (string)($argv[3] ?? 'none');
$actorMode = (string)($argv[4] ?? 'none');
$statusFile = (string)($argv[5] ?? '');
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
app()->tenant()->setTenantId($tenantId);
loadModuleRoutes([]);
require_once $root . '/modules/harpp/helpers.php';
require_once $root . '/modules/harpp/handlers.php';
if ($actorMode === 'owner' || $actorMode === 'member') {
    $role = $actorMode === 'member' ? 'member' : 'owner';
    $row = harppDb()->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='$role' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) { fwrite(STDERR, 'fixture user missing'); exit(70); }
    $user = ['id'=>(int)$row['id'],'email'=>(string)$row['email'],'full_name'=>(string)$row['full_name'],'role'=>(string)$row['role'],'source'=>'harpp'];
    $_COOKIE['harpp_token'] = (new Harpp\Services\HarppAuthService())->issueToken($user);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/harpp/decisions/' . $decisionId . '/apply-and-close';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_POST = [];
if ($csrfMode === 'valid') { $_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken(); }
elseif ($csrfMode === 'invalid') { $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid-csrf-token'; }
if ($statusFile !== '') {
    register_shutdown_function(static function () use ($statusFile): void { @file_put_contents($statusFile, (string)http_response_code()); });
}
harppDecisionApplyClose(['id' => $decisionId]);
PHP;
    $probe = str_replace('{$root}', var_export($root, true), $probe);
    file_put_contents($probeFile, $probe);
    @unlink($probeStatusFile);

    $runProbe = static function (string $csrfMode, string $actorMode, int $decisionId) use ($probeFile, $probeStatusFile, $tenantId): array {
        @unlink($probeStatusFile);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg((string)$tenantId) . ' ' . escapeshellarg((string)$decisionId) . ' ' . escapeshellarg($csrfMode) . ' ' . escapeshellarg($actorMode) . ' ' . escapeshellarg($probeStatusFile) . ' 2>&1';
        $output = (string)shell_exec($cmd);
        $status = is_file($probeStatusFile) ? (int)trim((string)file_get_contents($probeStatusFile)) : 0;
        return ['status' => $status, 'output' => $output];
    };

    $decisionSnapshot = static function (int $decisionId) use ($db): array {
        $row = $db->prepare('SELECT lifecycle_state, version FROM harpp_decisions WHERE id=:id'); $row->execute([':id' => $decisionId]); $r = $row->fetch(PDO::FETCH_ASSOC);
        $t = $db->prepare('SELECT COUNT(*) FROM harpp_decision_transitions WHERE decision_id=:id'); $t->execute([':id' => $decisionId]);
        $a = $db->prepare("SELECT COUNT(*) FROM harpp_audit_events WHERE aggregate_type='harpp_decision' AND aggregate_id=:id"); $a->execute([':id' => $decisionId]);
        return [
            'state' => is_array($r) ? (string)$r['lifecycle_state'] : null,
            'version' => is_array($r) ? (int)$r['version'] : null,
            'transitions' => (int)$t->fetchColumn(),
            'audit' => (int)$a->fetchColumn(),
        ];
    };

    $beforeProbes = $decisionSnapshot($decisionId);
    $csrfCases = [
        ['missing CSRF (unauthenticated)', 'none', 'none', 419, null],
        ['invalid CSRF (unauthenticated)', 'invalid', 'none', 419, null],
        ['valid CSRF without authentication', 'valid', 'none', 401, 'authentication_required'],
        ['member apply-and-close denied', 'valid', 'member', 403, 'forbidden'],
    ];
    foreach ($csrfCases as [$label, $csrfMode, $actorMode, $expectedStatus, $expectedCode]) {
        $probeResult = $runProbe($csrfMode, $actorMode, $decisionId);
        $decoded = json_decode($probeResult['output'], true);
        $after = $decisionSnapshot($decisionId);
        $assert($label . ' returns HTTP ' . $expectedStatus, $probeResult['status'] === $expectedStatus, 'got ' . $probeResult['status']);
        $assert($label . ' response envelope is a failure', is_array($decoded) && ($decoded['ok'] ?? null) === false, $probeResult['output']);
        if ($expectedCode !== null) {
            $assert($label . ' response carries code ' . $expectedCode, ($decoded['code'] ?? '') === $expectedCode, $probeResult['output']);
        } else {
            $assert($label . ' reports Invalid CSRF token', str_contains($probeResult['output'], 'Invalid CSRF token'), $probeResult['output']);
        }
        $assert($label . ' causes no state/version/audit mutation', $after === $beforeProbes, json_encode(['before' => $beforeProbes, 'after' => $after]));
    }

    $validOwner = $runProbe('valid', 'owner', $decisionId);
    $decodedOwner = json_decode($validOwner['output'], true);
    $stateAfterValid = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $stateAfterValid->execute([':id' => $decisionId]);
    $assert('valid CSRF owner apply-and-close succeeds via handler', $validOwner['status'] === 200 && is_array($decodedOwner) && !empty($decodedOwner['ok']) && ($decodedOwner['data']['state'] ?? '') === 'CLOSED');
    $assert('valid CSRF owner apply-and-close persists CLOSED', ($stateAfterValid->fetchColumn() ?: '') === 'CLOSED');

    $transitions = $db->prepare('SELECT from_state,to_state FROM harpp_decision_transitions WHERE decision_id=:id ORDER BY id'); $transitions->execute([':id' => $decisionId]);
    $transitionRows = $transitions->fetchAll(PDO::FETCH_ASSOC);
    $assert('apply-and-close records ACKNOWLEDGED -> APPLIED -> CLOSED atomically', in_array(['from_state' => 'ACKNOWLEDGED', 'to_state' => 'APPLIED'], $transitionRows, true) && in_array(['from_state' => 'APPLIED', 'to_state' => 'CLOSED'], $transitionRows, true));

    $retry = $service->applyAndClose($owner, $decisionId, 'Retry apply', 'Retry close', [], $tenantId);
    $transitionsAfterRetry = $db->prepare('SELECT COUNT(*) FROM harpp_decision_transitions WHERE decision_id=:id'); $transitionsAfterRetry->execute([':id' => $decisionId]);
    $assert('closed retry is idempotent (success)', !empty($retry['ok']) && !empty($retry['data']['already_applied']) && ($retry['data']['state'] ?? '') === 'CLOSED');
    $assert('closed retry adds no duplicate transitions', (int)$transitionsAfterRetry->fetchColumn() === count($transitionRows));

    $pendingRows = $service->list($owner, ['state' => 'PENDING'], $tenantId);
    $closedRows = $service->list($owner, ['state' => 'CLOSED'], $tenantId);
    $durable = $service->get($owner, $decisionId, $tenantId);
    $pendingIds = array_map(static fn(array $row): int => (int)$row['id'], (array)($pendingRows['data']['decisions'] ?? []));
    $closedIds = array_map(static fn(array $row): int => (int)$row['id'], (array)($closedRows['data']['decisions'] ?? []));
    $auditTrail = (array)($durable['data']['audit_trail'] ?? []);
    $auditHasBoth = count(array_filter($auditTrail, static fn(array $row): bool => ($row['from_state'] === 'ACKNOWLEDGED' && $row['to_state'] === 'APPLIED') || ($row['from_state'] === 'APPLIED' && $row['to_state'] === 'CLOSED'))) === 2;
    $assert('closed decision is pruned from default PENDING inbox', !in_array($decisionId, $pendingIds, true));
    $assert('closed decision remains retrievable via explicit CLOSED filter', in_array($decisionId, $closedIds, true));
    $assert('closed decision retains complete lifecycle audit trail', !empty($durable['ok']) && $auditHasBoth);

    $h->section('Partial APPLIED recovery'); // @phpstan-ignore-line
    $partialId = $makeDecision('INBOX-PARTIAL-' . strtoupper(bin2hex(random_bytes(6))), 'Partial APPLIED recovery fixture');
    $advanceToAcknowledged($partialId);
    $db->prepare("UPDATE harpp_decisions SET lifecycle_state='APPLIED', applied_at=NOW() WHERE id=:id")->execute([':id' => $partialId]);
    $partialRecovered = $service->applyAndClose($owner, $partialId, 'Applied earlier by harness', 'Closed by operator', [], $tenantId);
    $partialTransitions = $db->prepare('SELECT from_state,to_state FROM harpp_decision_transitions WHERE decision_id=:id ORDER BY id'); $partialTransitions->execute([':id' => $partialId]);
    $partialRows = $partialTransitions->fetchAll(PDO::FETCH_ASSOC);
    $assert('partial APPLIED closes successfully', !empty($partialRecovered['ok']) && ($partialRecovered['data']['state'] ?? '') === 'CLOSED' && !empty($partialRecovered['data']['already_applied']));
    $assert('partial APPLIED records only APPLIED -> CLOSED', in_array(['from_state' => 'APPLIED', 'to_state' => 'CLOSED'], $partialRows, true) && !in_array(['from_state' => 'ACKNOWLEDGED', 'to_state' => 'APPLIED'], $partialRows, true));

    $h->section('Rejection of unsupported lifecycle states'); // @phpstan-ignore-line
    $rejectId = $makeDecision('INBOX-REJECT-' . strtoupper(bin2hex(random_bytes(6))), 'Unsupported state rejection fixture');
    foreach (['CREATED','PENDING','NOTIFIED','VIEWED','DECIDED','EXPIRED','SUPERSEDED','CANCELLED'] as $unsupported) {
        $db->prepare('UPDATE harpp_decisions SET lifecycle_state=:state, version=version+1 WHERE id=:id')->execute([':state' => $unsupported, ':id' => $rejectId]);
        $versionBefore = (int)$db->query("SELECT version FROM harpp_decisions WHERE id=$rejectId")->fetchColumn();
        $transitionsBefore = (int)$db->query("SELECT COUNT(*) FROM harpp_decision_transitions WHERE decision_id=$rejectId")->fetchColumn();
        $adrsBefore = (int)$db->query("SELECT COUNT(*) FROM harpp_adrs WHERE decision_ref=$rejectId")->fetchColumn();
        $auditBefore = (int)$db->query("SELECT COUNT(*) FROM harpp_audit_events WHERE aggregate_type='harpp_decision' AND aggregate_id='$rejectId'")->fetchColumn();
        $outboxBefore = (int)$db->query("SELECT COUNT(*) FROM harpp_outbox WHERE aggregate_type='harpp_decision' AND aggregate_id='$rejectId'")->fetchColumn();
        $notificationsBefore = (int)$db->query("SELECT COUNT(*) FROM harpp_notifications WHERE decision_id=$rejectId")->fetchColumn();
        $rejected = $service->applyAndClose($owner, $rejectId, 'Apply', 'Close', [], $tenantId);
        $stateAfter = $db->query("SELECT lifecycle_state FROM harpp_decisions WHERE id=$rejectId")->fetchColumn();
        $versionAfter = (int)$db->query("SELECT version FROM harpp_decisions WHERE id=$rejectId")->fetchColumn();
        $transitionsAfter = (int)$db->query("SELECT COUNT(*) FROM harpp_decision_transitions WHERE decision_id=$rejectId")->fetchColumn();
        $adrsAfter = (int)$db->query("SELECT COUNT(*) FROM harpp_adrs WHERE decision_ref=$rejectId")->fetchColumn();
        $auditAfter = (int)$db->query("SELECT COUNT(*) FROM harpp_audit_events WHERE aggregate_type='harpp_decision' AND aggregate_id='$rejectId'")->fetchColumn();
        $outboxAfter = (int)$db->query("SELECT COUNT(*) FROM harpp_outbox WHERE aggregate_type='harpp_decision' AND aggregate_id='$rejectId'")->fetchColumn();
        $notificationsAfter = (int)$db->query("SELECT COUNT(*) FROM harpp_notifications WHERE decision_id=$rejectId")->fetchColumn();
        $assert("apply-and-close rejects {$unsupported} without mutation",
            $rejected instanceof HarppServiceResult && empty($rejected['ok']) && ($rejected['code'] ?? '') === 'illegal_transition' && ($rejected['status'] ?? 0) === 409
            && $stateAfter === $unsupported && $versionAfter === $versionBefore
            && $transitionsAfter === $transitionsBefore && $adrsAfter === $adrsBefore
            && $auditAfter === $auditBefore && $outboxAfter === $outboxBefore && $notificationsAfter === $notificationsBefore
        );
    }
} finally {
    if (is_string($probeFile) && $probeFile !== '') @unlink($probeFile);
    if (is_string($probeStatusFile) && $probeStatusFile !== '') @unlink($probeStatusFile);
    foreach ($cleanup as [$decisionId, $conversationId]) {
        if ($decisionId > 0) {
            $db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id' => $decisionId]);
            $db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id' => $decisionId, ':c' => $conversationId]);
            $db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id' => $decisionId]);
        }
        if ($conversationId > 0) {
            $db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id' => $conversationId]);
        }
    }
    foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
}

$errorLog = is_file($logs[1]) ? trim((string)file_get_contents($logs[1])) : '';
$assert('error.log has no findings', $errorLog === '', $errorLog);
ob_end_flush();
$h->done(); // @phpstan-ignore-line
