<?php

declare(strict_types=1);

/**
 * Workflow retention/provenance policy contract.
 *
 * Immutable operational provenance retained across payload redaction:
 * - workflow_runs/workflow_run_steps metadata (workflow_key, module, entity ids,
 *   definition_id, status, timestamps, step ordinal/key/status/attempt/max_attempts,
 *   idempotency_key, payload_hash when recorded)
 * - workflow_transition_logs append-only transition trail
 * - payload_hash stubs for run/step payloads when recorded
 *
 * Redactable payload-bearing fields:
 * - workflow_runs.payload_json
 * - workflow_runs.context_json
 * - workflow_run_steps.args_json
 * - workflow_run_steps.result_json
 *
 * After retain-provenance redaction, metadata rows remain and payload_hash stays
 * as the immutable provenance stub while redactable payload columns are NULLed.
 * The retention window is configurable by caller via workflowRedactPayloadOlderThan().
 *
 * Full purge in this increment deletes workflow_runs + workflow_run_steps only.
 * Instance-scoped workflow_transition_logs are not removed here; full provenance
 * purge would require instance-level handling and is out of scope.
 */

function workflowCanonicalizeValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        $out = [];
        foreach ($value as $item) {
            $out[] = workflowCanonicalizeValue($item);
        }
        return $out;
    }

    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    $out = [];
    foreach ($keys as $key) {
        $out[(string)$key] = workflowCanonicalizeValue($value[$key]);
    }
    return $out;
}

function workflowCanonicalJson(string $json): string
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('Invalid workflow payload JSON: ' . json_last_error_msg());
    }

    $canonical = workflowCanonicalizeValue($decoded);
    $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new \RuntimeException('Failed to canonicalize workflow payload JSON');
    }

    return $encoded;
}

function workflowPayloadHash(string $canonicalJson): string
{
    return hash('sha256', $canonicalJson);
}

function workflowRecordRunPayloadHash(\PDO $db, int $runId, string $payloadJson): string
{
    $hash = workflowPayloadHash(workflowCanonicalJson($payloadJson));

    $stmt = $db->prepare('UPDATE workflow_runs SET payload_hash = :hash WHERE id = :id AND payload_hash IS NULL');
    $stmt->execute([
        ':hash' => $hash,
        ':id' => $runId,
    ]);

    return $hash;
}

function workflowRecordStepPayloadHashes(\PDO $db, int $runId): void
{
    $stmt = $db->prepare('SELECT id, args_json, result_json, payload_hash FROM workflow_run_steps WHERE run_id = :id');
    $stmt->execute([':id' => $runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $update = $db->prepare('UPDATE workflow_run_steps SET payload_hash = :hash WHERE id = :id AND payload_hash IS NULL');
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['payload_hash'] ?? null) !== null) {
            continue;
        }

        $argsJson = $row['args_json'] ?? null;
        $resultJson = $row['result_json'] ?? null;
        if ($argsJson === null && $resultJson === null) {
            continue;
        }

        $stepJson = json_encode([
            'args' => $argsJson !== null ? json_decode((string)$argsJson, true) : null,
            'result' => $resultJson !== null ? json_decode((string)$resultJson, true) : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($stepJson)) {
            continue;
        }

        $update->execute([
            ':hash' => workflowPayloadHash(workflowCanonicalJson($stepJson)),
            ':id' => (int)$row['id'],
        ]);
    }
}

function workflowPurgeRunPayload(\PDO $db, int $runId, bool $purgeSteps = true): array
{
    workflowRecordStepPayloadHashes($db, $runId);

    $db->prepare('UPDATE workflow_runs SET payload_json = NULL, context_json = NULL, payload_redacted_at = NOW() WHERE id = :id')
        ->execute([':id' => $runId]);

    if ($purgeSteps) {
        $db->prepare('UPDATE workflow_run_steps SET args_json = NULL, result_json = NULL WHERE run_id = :id')
            ->execute([':id' => $runId]);
    }

    return [
        'purged' => [
            'run_payload' => true,
            'steps_payload' => $purgeSteps,
        ],
        'kept' => [
            'metadata' => true,
            'payload_hash' => true,
        ],
        'mode' => 'retain_provenance',
    ];
}

function workflowPurgeRun(\PDO $db, int $runId): array
{
    $db->prepare('DELETE FROM workflow_run_steps WHERE run_id = :id')->execute([':id' => $runId]);
    $db->prepare('DELETE FROM workflow_runs WHERE id = :id')->execute([':id' => $runId]);

    return [
        'mode' => 'purge_all',
        'purged' => [
            'run' => true,
            'steps' => true,
        ],
        'kept' => [
            'transition_logs' => 'instance_scoped_out_of_scope',
        ],
    ];
}

function workflowRetentionNormalizeInterval(string $interval): string
{
    $interval = strtoupper(trim(preg_replace('/\s+/', ' ', $interval) ?? ''));
    if (preg_match('/^[1-9][0-9]* (SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)S?$/', $interval) !== 1) {
        throw new \RuntimeException('Invalid workflow retention interval');
    }

    return $interval;
}

function workflowRedactPayloadOlderThan(\PDO $db, string $interval): int
{
    $interval = workflowRetentionNormalizeInterval($interval);
    $sql = 'SELECT id FROM workflow_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $interval . ') AND payload_json IS NOT NULL';
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $count = 0;

    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['id'])) {
            continue;
        }
        workflowPurgeRunPayload($db, (int)$row['id']);
        $count++;
    }

    return $count;
}

function workflowAppendTransition(\PDO $db, int $instanceId, string $action, string $fromState, string $toState, ?int $actorUserId, array $meta = []): int
{
    if (function_exists('request_id') && !isset($meta['request_id'])) {
        $meta['request_id'] = request_id();
    }

    $metaJson = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    if ($metaJson === false) {
        throw new \RuntimeException('Failed to encode workflow transition metadata');
    }

    $stmt = $db->prepare('INSERT INTO workflow_transition_logs (instance_id, action, from_state, to_state, actor_user_id, meta_json, created_at) VALUES (:iid, :action, :from, :to, :actor, :meta, NOW())');
    $stmt->execute([
        ':iid' => $instanceId,
        ':action' => $action,
        ':from' => $fromState,
        ':to' => $toState,
        ':actor' => $actorUserId,
        ':meta' => $metaJson,
    ]);

    return (int)$db->lastInsertId();
}
