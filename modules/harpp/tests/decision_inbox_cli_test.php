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

    $inboxTemplate = (string)file_get_contents($root . '/templates/modules/harpp/decisions.disyl');
    $stateSelect = strpos($inboxTemplate, '<select name="state">');
    $stateSelectEnd = strpos($inboxTemplate, '</select>', $stateSelect);
    $stateOptions = substr($inboxTemplate, $stateSelect, $stateSelectEnd - $stateSelect);
    $pendingPos = strpos($stateOptions, '<option value="PENDING">');
    $allPos = strpos($stateOptions, '<option value="">');
    $closedPos = strpos($stateOptions, '<option>CLOSED</option>');
    $assert('inbox state select visibly starts at PENDING', $pendingPos !== false && $allPos !== false && $pendingPos < $allPos);
    $assert('explicit CLOSED filter remains selectable', $closedPos !== false);

    $inboxJs = (string)file_get_contents($root . '/modules/harpp/assets/decisions.js');
    $assert('inbox first load builds query from default form', strpos($inboxJs, 'new URLSearchParams(new FormData(form))') !== false);
    $assert('inbox load targets decisions list endpoint', strpos($inboxJs, "/api/v1/harpp/decisions?'") !== false || strpos($inboxJs, "/api/v1/harpp/decisions?\"") !== false);

    $_COOKIE['harpp_token'] = (new HarppAuthService($db))->issueToken($owner);
    ob_start(); harppPageDecisions(); $renderedInbox = (string)ob_get_clean();
    $renderedSelectStart = strpos($renderedInbox, '<select name="state">');
    $renderedSelectEnd = strpos($renderedInbox, '</select>', $renderedSelectStart);
    $renderedStateOptions = substr($renderedInbox, $renderedSelectStart, $renderedSelectEnd - $renderedSelectStart);
    $renderedPendingPos = strpos($renderedStateOptions, '<option value="PENDING">');
    $renderedAllPos = strpos($renderedStateOptions, '<option value="">');
    $assert('rendered inbox select starts at PENDING', $renderedPendingPos !== false && $renderedAllPos !== false && $renderedPendingPos < $renderedAllPos);

    $h->section('Route and handler CSRF ordering'); // @phpstan-ignore-line
    $routes = require dirname(__DIR__) . '/routes.php';
    $applyRoute = (string)($routes['POST']['/api/v1/harpp/decisions/{id}/apply-and-close'] ?? '');
    $assert('apply-and-close route registered', $applyRoute === 'harpp:harppDecisionApplyClose');
    $assert('apply-and-close handler defined', function_exists('harppDecisionApplyClose'));

    $handlersSrc = (string)file_get_contents($root . '/modules/harpp/handlers.php');
    $fnPos = strpos($handlersSrc, 'function harppDecisionApplyClose');
    $fnLineStart = strrpos(substr($handlersSrc, 0, $fnPos), "\n") + 1;
    $fnLineEnd = strpos($handlersSrc, "\n", $fnPos);
    $fnLine = substr($handlersSrc, $fnLineStart, $fnLineEnd - $fnLineStart);
    $csrfPos = strpos($fnLine, 'harppRequireCsrf()');
    $authPos = strpos($fnLine, 'harppAuthenticated(');
    $assert('apply handler enforces CSRF', $csrfPos !== false);
    $assert('apply handler enforces CSRF before authentication', $csrfPos !== false && $authPos !== false && $csrfPos < $authPos);

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
$mode = (string)($argv[3] ?? 'invalid');
$statusFile = (string)($argv[4] ?? '');
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
app()->tenant()->setTenantId($tenantId);
loadModuleRoutes([]);
require_once $root . '/modules/harpp/helpers.php';
require_once $root . '/modules/harpp/handlers.php';
$ownerRow = harppDb()->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$owner = ['id'=>(int)$ownerRow['id'],'email'=>(string)$ownerRow['email'],'full_name'=>(string)$ownerRow['full_name'],'role'=>(string)$ownerRow['role'],'source'=>'harpp'];
$_COOKIE['harpp_token'] = (new Harpp\Services\HarppAuthService())->issueToken($owner);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/harpp/decisions/' . $decisionId . '/apply-and-close';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_POST = [];
if ($mode === 'valid') { $_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken(); }
if ($statusFile !== '') {
    register_shutdown_function(static function () use ($statusFile): void { @file_put_contents($statusFile, (string)http_response_code()); });
}
harppDecisionApplyClose(['id' => $decisionId]);
PHP;
    $probe = str_replace('{$root}', var_export($root, true), $probe);
    file_put_contents($probeFile, $probe);
    @unlink($probeStatusFile);

    $runProbe = static function (string $mode, int $decisionId) use ($probeFile, $probeStatusFile, $tenantId): array {
        @unlink($probeStatusFile);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg((string)$tenantId) . ' ' . escapeshellarg((string)$decisionId) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($probeStatusFile) . ' 2>&1';
        $output = (string)shell_exec($cmd);
        $status = is_file($probeStatusFile) ? (int)trim((string)file_get_contents($probeStatusFile)) : 0;
        return ['status' => $status, 'output' => $output];
    };

    $invalid = $runProbe('invalid', $decisionId);
    $stateAfterInvalid = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $stateAfterInvalid->execute([':id' => $decisionId]);
    $assert('missing CSRF on apply mutation fails with HTTP 419', $invalid['status'] === 419 && str_contains($invalid['output'], 'Invalid CSRF token'));
    $assert('missing CSRF causes no state change', ($stateAfterInvalid->fetchColumn() ?: '') === 'ACKNOWLEDGED');

    $valid = $runProbe('valid', $decisionId);
    $decoded = json_decode($valid['output'], true);
    $stateAfterValid = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $stateAfterValid->execute([':id' => $decisionId]);
    $assert('valid CSRF apply-and-close succeeds', $valid['status'] === 200 && is_array($decoded) && !empty($decoded['ok']) && ($decoded['data']['state'] ?? '') === 'CLOSED');
    $assert('valid CSRF apply-and-close persists CLOSED', ($stateAfterValid->fetchColumn() ?: '') === 'CLOSED');

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

    $h->section('Direct close from PENDING'); // @phpstan-ignore-line
    $pendingId = $makeDecision('INBOX-PENDING-' . strtoupper(bin2hex(random_bytes(6))), 'Direct close fixture');
    $pendingBefore = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $pendingBefore->execute([':id' => $pendingId]);
    $assert('direct-close fixture starts at PENDING', ($pendingBefore->fetchColumn() ?: '') === 'PENDING');
    $pendingClosed = $service->applyAndClose($owner, $pendingId, 'Applied directly.', 'Closed directly.', [], $tenantId);
    $pendingState = $db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id'); $pendingState->execute([':id' => $pendingId]);
    $pendingAdr = $db->prepare('SELECT COUNT(*) FROM harpp_adrs WHERE decision_ref=:id'); $pendingAdr->execute([':id' => $pendingId]);
    $pendingChain = $db->prepare('SELECT from_state,to_state FROM harpp_decision_transitions WHERE decision_id=:id ORDER BY id'); $pendingChain->execute([':id' => $pendingId]);
    $pendingLinks = array_map(static fn(array $r): string => $r['from_state'] . '->' . $r['to_state'], $pendingChain->fetchAll(PDO::FETCH_ASSOC));
    $assert('apply-and-close closes a PENDING decision directly', !empty($pendingClosed['ok']) && ($pendingClosed['data']['state'] ?? '') === 'CLOSED');
    $assert('direct close persists CLOSED', ($pendingState->fetchColumn() ?: '') === 'CLOSED');
    $assert('direct close creates the immutable ADR', (int)$pendingAdr->fetchColumn() === 1);
    $assert('direct close records the full legal chain', in_array('PENDING->DECIDED', $pendingLinks, true) && in_array('APPLIED->CLOSED', $pendingLinks, true));
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
