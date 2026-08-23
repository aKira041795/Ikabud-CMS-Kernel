<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/workflow-retention.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== WORKFLOW RETENTION / PROVENANCE ===\n";

$db = app()->db();
$engine = app()->workflowEngine();
$seed = 'wf_ret_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$module = '_test_retention';

$db->prepare('DELETE FROM workflow_transition_logs WHERE instance_id IN (SELECT id FROM workflow_instances WHERE module = :module)')->execute([':module' => $module]);
$db->prepare('DELETE FROM workflow_instances WHERE module = :module')->execute([':module' => $module]);
$db->prepare('DELETE FROM workflow_run_steps WHERE run_id IN (SELECT id FROM workflow_runs WHERE module = :module)')->execute([':module' => $module]);
$db->prepare('DELETE FROM workflow_runs WHERE module = :module')->execute([':module' => $module]);

$payload = ['z' => 2, 'a' => ['b' => 1, 'a' => 0], 'list' => [['d' => 4, 'c' => 3]]];
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$expectedHash = workflowPayloadHash(workflowCanonicalJson((string)$payloadJson));

$start = $engine->start($seed . '.hash', $module, $payload);
t('engine start returns ok', ($start['ok'] ?? false) === true, json_encode($start));
$hashRunId = (int)($start['run_id'] ?? 0);
t('engine start returns run id', $hashRunId > 0);

$hashRunStmt = $db->prepare('SELECT payload_json, payload_hash FROM workflow_runs WHERE id = :id LIMIT 1');
$hashRunStmt->execute([':id' => $hashRunId]);
$hashRun = $hashRunStmt->fetch(PDO::FETCH_ASSOC) ?: [];
t('payload hash recorded immutably on new run', ($hashRun['payload_hash'] ?? '') === $expectedHash, json_encode($hashRun));

$hashAgain = workflowRecordRunPayloadHash($db, $hashRunId, (string)$payloadJson);
$hashRunStmt->execute([':id' => $hashRunId]);
$hashRunAgain = $hashRunStmt->fetch(PDO::FETCH_ASSOC) ?: [];
t('idempotent hash returns same hash', $hashAgain === $expectedHash && ($hashRunAgain['payload_hash'] ?? '') === $expectedHash, json_encode($hashRunAgain));

$runInsert = $db->prepare('INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, definition_id, status, payload_json, context_json, started_at, created_at) VALUES (:wk, :mod, :et, :eid, NULL, :status, :payload, :context, NOW(), NOW())');
$runInsert->execute([
    ':wk' => $seed . '.redact',
    ':mod' => $module,
    ':et' => 'test_entity',
    ':eid' => 'redact-1',
    ':status' => 'running',
    ':payload' => json_encode(['secret' => 'payload', 'alpha' => 1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':context' => json_encode(['ctx' => 'remove'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$redactRunId = (int)$db->lastInsertId();
workflowRecordRunPayloadHash($db, $redactRunId, json_encode(['secret' => 'payload', 'alpha' => 1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$db->prepare('INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, result_json, created_at) VALUES (:rid, 1, :sk, :lab, :cap, :args, :status, 1, 3, :ikey, :result, NOW())')->execute([
    ':rid' => $redactRunId,
    ':sk' => 'step1',
    ':lab' => 'Step 1',
    ':cap' => 'test.capability',
    ':args' => json_encode(['token' => 'abc123'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':status' => 'completed',
    ':ikey' => 'idem-' . $seed,
    ':result' => json_encode(['secret_result' => 'xyz'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$redactStepId = (int)$db->lastInsertId();

$purgePayloadResult = workflowPurgeRunPayload($db, $redactRunId);
$redactedRunStmt = $db->prepare('SELECT payload_json, context_json, payload_hash, payload_redacted_at, status, workflow_key FROM workflow_runs WHERE id = :id LIMIT 1');
$redactedRunStmt->execute([':id' => $redactRunId]);
$redactedRun = $redactedRunStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$redactedStepStmt = $db->prepare('SELECT args_json, result_json, payload_hash, status, attempt, idempotency_key FROM workflow_run_steps WHERE id = :id LIMIT 1');
$redactedStepStmt->execute([':id' => $redactStepId]);
$redactedStep = $redactedStepStmt->fetch(PDO::FETCH_ASSOC) ?: [];

t('retain-provenance redaction returns mode', ($purgePayloadResult['mode'] ?? '') === 'retain_provenance', json_encode($purgePayloadResult));
t('run payload/context removed but hash kept', array_key_exists('payload_json', $redactedRun) && $redactedRun['payload_json'] === null && array_key_exists('context_json', $redactedRun) && $redactedRun['context_json'] === null && (($redactedRun['payload_hash'] ?? '') !== '') && (($redactedRun['payload_redacted_at'] ?? null) !== null), json_encode($redactedRun));
t('step payload removed but metadata rows remain', array_key_exists('args_json', $redactedStep) && $redactedStep['args_json'] === null && array_key_exists('result_json', $redactedStep) && $redactedStep['result_json'] === null && (($redactedStep['payload_hash'] ?? '') !== '') && ($redactedStep['status'] ?? '') === 'completed' && (int)($redactedStep['attempt'] ?? 0) === 1 && ($redactedStep['idempotency_key'] ?? '') !== '', json_encode($redactedStep));

$runInsert->execute([
    ':wk' => $seed . '.purge',
    ':mod' => $module,
    ':et' => 'test_entity',
    ':eid' => 'purge-1',
    ':status' => 'running',
    ':payload' => json_encode(['full' => 'purge'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':context' => json_encode(['full' => 'purge'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$purgeRunId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, result_json, created_at) VALUES (:rid, 1, :sk, :lab, :cap, :args, :status, 0, 1, :ikey, :result, NOW())')->execute([
    ':rid' => $purgeRunId,
    ':sk' => 'step1',
    ':lab' => 'Step 1',
    ':cap' => 'test.capability',
    ':args' => json_encode(['purge' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':status' => 'pending',
    ':ikey' => 'idem-purge-' . $seed,
    ':result' => json_encode(['purge' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$purgeResult = workflowPurgeRun($db, $purgeRunId);
t('full purge returns purge_all mode', ($purgeResult['mode'] ?? '') === 'purge_all', json_encode($purgeResult));
t('full purge removes run data', (int)$db->query('SELECT COUNT(*) FROM workflow_runs WHERE id = ' . $purgeRunId)->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM workflow_run_steps WHERE run_id = ' . $purgeRunId)->fetchColumn() === 0);

$baselineOld = (int)$db->query("SELECT COUNT(*) FROM workflow_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY) AND payload_json IS NOT NULL")->fetchColumn();
$runInsert->execute([
    ':wk' => $seed . '.old',
    ':mod' => $module,
    ':et' => 'test_entity',
    ':eid' => 'old-1',
    ':status' => 'completed',
    ':payload' => json_encode(['old' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':context' => json_encode(['old' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$oldRunId = (int)$db->lastInsertId();
workflowRecordRunPayloadHash($db, $oldRunId, json_encode(['old' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$db->exec('UPDATE workflow_runs SET created_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = ' . $oldRunId);

$runInsert->execute([
    ':wk' => $seed . '.fresh',
    ':mod' => $module,
    ':et' => 'test_entity',
    ':eid' => 'fresh-1',
    ':status' => 'completed',
    ':payload' => json_encode(['fresh' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':context' => json_encode(['fresh' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$freshRunId = (int)$db->lastInsertId();
workflowRecordRunPayloadHash($db, $freshRunId, json_encode(['fresh' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$redactedCount = workflowRedactPayloadOlderThan($db, '2 DAY');
$oldFreshStmt = $db->prepare('SELECT payload_json, payload_redacted_at, payload_hash FROM workflow_runs WHERE id = :id LIMIT 1');
$oldFreshStmt->execute([':id' => $oldRunId]);
$oldRun = $oldFreshStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$oldFreshStmt->execute([':id' => $freshRunId]);
$freshRun = $oldFreshStmt->fetch(PDO::FETCH_ASSOC) ?: [];

t('retention-window redaction affects old runs', $redactedCount >= ($baselineOld + 1) && array_key_exists('payload_json', $oldRun) && $oldRun['payload_json'] === null && ($oldRun['payload_redacted_at'] ?? null) !== null && ($oldRun['payload_hash'] ?? '') !== '', json_encode(['count' => $redactedCount, 'baseline' => $baselineOld, 'old' => $oldRun]));
t('retention-window redaction leaves fresh run intact', array_key_exists('payload_json', $freshRun) && $freshRun['payload_json'] !== null && ($freshRun['payload_redacted_at'] ?? null) === null, json_encode($freshRun));

$db->prepare('INSERT INTO workflow_instances (workflow_key, module, entity_type, entity_id, state, created_at) VALUES (:wk, :mod, :et, :eid, :state, NOW())')->execute([
    ':wk' => $seed . '.instance',
    ':mod' => $module,
    ':et' => 'test_entity',
    ':eid' => 'inst-1',
    ':state' => 'draft',
]);
$instanceId = (int)$db->lastInsertId();
$transitionId1 = workflowAppendTransition($db, $instanceId, 'submit', 'draft', 'review', 123, ['payload_hash' => $expectedHash]);
$transitionStmt = $db->prepare('SELECT id, action, from_state, to_state, actor_user_id, meta_json FROM workflow_transition_logs WHERE id = :id LIMIT 1');
$transitionStmt->execute([':id' => $transitionId1]);
$transition1 = $transitionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$transitionId2 = workflowAppendTransition($db, $instanceId, 'submit', 'draft', 'review', 123, ['payload_hash' => $expectedHash]);
$transitionStmt->execute([':id' => $transitionId1]);
$transition1Again = $transitionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$transitionCount = (int)$db->query('SELECT COUNT(*) FROM workflow_transition_logs WHERE instance_id = ' . $instanceId)->fetchColumn();
t('append-only transition helper inserts rows', $transitionId1 > 0 && $transitionId2 > $transitionId1 && $transitionCount === 2, json_encode(['ids' => [$transitionId1, $transitionId2], 'count' => $transitionCount]));
t('append-only transition logging never overwrites prior row', $transition1 === $transition1Again && str_contains((string)($transition1['meta_json'] ?? ''), $expectedHash), json_encode(['before' => $transition1, 'after' => $transition1Again]));

$db->prepare('DELETE FROM workflow_transition_logs WHERE instance_id = :id')->execute([':id' => $instanceId]);
$db->prepare('DELETE FROM workflow_instances WHERE id = :id')->execute([':id' => $instanceId]);
$db->prepare('DELETE FROM workflow_run_steps WHERE run_id IN (SELECT id FROM workflow_runs WHERE module = :module)')->execute([':module' => $module]);
$db->prepare('DELETE FROM workflow_runs WHERE module = :module')->execute([':module' => $module]);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('error log stays clean', trim($errorLog) === '', trim($errorLog));
t('app log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
