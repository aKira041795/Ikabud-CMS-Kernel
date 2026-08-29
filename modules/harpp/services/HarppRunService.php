<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use Closure;
use PDO;
use Throwable;

final class HarppRunService
{
    private const STATES = ['QUEUED','WAITING_FOR_RUNNER','CLAIMED','RUNNING','STALLED','AWAITING_APPROVAL','SUCCEEDED','FAILED','CANCELLED'];
    private const TERMINAL = ['SUCCEEDED','FAILED','CANCELLED'];
    private const MAX_REPORT_DELIVERY_ATTEMPTS = 5;
    private ?Closure $reportDispatcher;

    public function __construct(private ModuleDB $db, ?callable $reportDispatcher = null)
    {
        $this->reportDispatcher = $reportDispatcher === null ? null : Closure::fromCallable($reportDispatcher);
    }

    public function ensureForMessage(array $actor, int $messageId, array $required = ['desktop']): HarppServiceResult
    {
        if (!$this->bridgeActor($actor) || $messageId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        $required = $this->normalizeCapabilities($required);
        try {
            $this->db->beginTransaction();
            $m = $this->db->prepare("SELECT id,conversation_id FROM harpp_messages WHERE id=:id AND sender_type='user' FOR UPDATE");
            $m->execute([':id' => $messageId]);
            $message = $m->fetch(PDO::FETCH_ASSOC);
            if (!is_array($message)) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Owner message not found.', 404);
            }
            $existing = $this->loadByMessage($messageId, true);
            if ($existing) {
                $this->db->commit();
                return HarppServiceResult::success(['run' => $this->publicRun($existing)]);
            }
            $state = $this->hasOnlineRunner($required) ? 'QUEUED' : 'WAITING_FOR_RUNNER';
            $insert = $this->db->prepare("INSERT INTO harpp_work_runs (source_message_id,conversation_id,state,required_capabilities_json,last_status) VALUES (:message,:conversation,:state,:capabilities,:status)");
            $insert->execute([
                ':message' => $messageId,
                ':conversation' => (int)$message['conversation_id'],
                ':state' => $state,
                ':capabilities' => $this->json($required),
                ':status' => $state === 'WAITING_FOR_RUNNER' ? 'Queued; no compatible runner is online.' : 'Queued for runner claim.',
            ]);
            $run = $this->loadByMessage($messageId, true);
            $this->effect($actor, 'harpp.work_run.queued', 'work_run.queued', (int)$run['id'], null, ['state' => $run['state'], 'source_message_id' => $messageId]);
            $this->db->commit();
            return HarppServiceResult::success(['run' => $this->publicRun($run)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to queue work run.', 500);
        }
    }

    public function registerRunner(array $actor, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $key = trim((string)($input['runner_key'] ?? ''));
        $name = trim((string)($input['display_name'] ?? $key));
        $capabilities = $this->normalizeCapabilities((array)($input['capabilities'] ?? ['desktop']));
        if (!preg_match('/^[A-Za-z0-9._:-]{2,191}$/', $key) || $name === '' || strlen($name) > 255) {
            return HarppServiceResult::failure('Valid runner_key and display_name are required.', 422);
        }
        $s = $this->db->prepare("INSERT INTO harpp_runners (runner_key,display_name,status,capabilities_json,last_heartbeat_at) VALUES (:key,:name,'online',:capabilities,NOW(6)) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),status='online',capabilities_json=VALUES(capabilities_json),last_heartbeat_at=NOW(6)");
        $s->execute([':key' => $key, ':name' => $name, ':capabilities' => $this->json($capabilities)]);
        return HarppServiceResult::success(['runner_key' => $key, 'status' => 'online', 'capabilities' => $capabilities]);
    }

    public function requestWake(array $actor, string $runnerKey, array $input): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if (!preg_match('/^[A-Za-z0-9._:-]{2,191}$/', $runnerKey)) return HarppServiceResult::failure('Valid runner_key is required.', 422);
        // Migration 019 must be applied to the tenant first; degrade with a clear
        // code instead of a raw 500 when it is missing (consistent with the status page).
        if (!$this->tableExists('harpp_runner_wake_requests')) {
            return HarppServiceResult::failure('Wake requests are not migrated; run tenant:migrate for harpp.', 503, 'wake_unavailable');
        }
        $s = $this->db->prepare('SELECT id FROM harpp_runners WHERE runner_key=:k');
        $s->execute([':k' => $runnerKey]);
        if (!$s->fetchColumn()) return HarppServiceResult::failure('Runner not found.', 404);
        $idempotencyKey = substr(trim((string)($input['idempotency_key'] ?? '')), 0, 191);
        if ($idempotencyKey === '') $idempotencyKey = $this->uuid();
        // Serialize per-runner with an advisory lock so two concurrent nudges (e.g. a
        // double-click) cannot both pass the coalesce and insert duplicate pending rows.
        $lockName = 'harpp_wake:' . $runnerKey;
        $this->db->prepare('SELECT GET_LOCK(:lk, 3)')->execute([':lk' => $lockName]);
        try {
            $s = $this->db->prepare("SELECT id FROM harpp_runner_wake_requests WHERE runner_key=:k AND status IN ('pending','claimed') AND requested_at > DATE_SUB(NOW(6), INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1");
            $s->execute([':k' => $runnerKey]);
            $existingId = (int)$s->fetchColumn();
            if ($existingId > 0) {
                $existing = $this->wakeRequestPublic($this->loadWakeRequest($existingId));
                $existing['duplicate'] = true;
                return HarppServiceResult::success(['request' => $existing, 'note' => 'Wake requested. If the machine stays offline, wake it manually.']);
            }
            $this->db->beginTransaction();
            try {
                $s = $this->db->prepare("INSERT INTO harpp_runner_wake_requests (runner_key,status,requested_by,idempotency_key) VALUES (:k,'pending',:requested_by,:idempotency_key)");
                $s->execute([':k' => $runnerKey, ':requested_by' => isset($actor['id']) ? (int)$actor['id'] : null, ':idempotency_key' => $idempotencyKey]);
                $request = $this->loadWakeRequest((int)$this->db->lastInsertId());
                (new \Harpp\Services\HarppFoundationService($this->db))->recordEffect('harpp.runner_wake_requested', 'runner_wake_requested', $actor, 'harpp_runner_wake_request', (int)$request['id'], null, ['runner_key' => $runnerKey, 'status' => 'pending']);
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(:lk)')->execute([':lk' => $lockName]);
        }
        try {
            $owner = $this->ownerId();
            if ($owner > 0) (new HarppNotificationService($this->db))->create($owner, 'system', ['event' => 'runner.wake_requested', 'runner_key' => $runnerKey, 'request_id' => (int)$request['id']], null, null, null, false);
        } catch (\Throwable) {
        }
        return HarppServiceResult::success(['request' => $this->wakeRequestPublic($request), 'note' => 'Wake requested. If the machine stays offline, wake it manually.']);
    }

    public function claimWake(array $actor, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runnerKey = trim((string)($input['runner_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{2,191}$/', $runnerKey)) return HarppServiceResult::failure('Valid runner_key is required.', 422);
        // Reap wake claims that were never acknowledged (relay/daemon died mid-claim)
        // so they surface in wake history and cannot accumulate as stuck 'claimed' rows.
        $this->db->prepare("UPDATE harpp_runner_wake_requests SET status='failed',last_error='Wake claim expired without acknowledgement.',claim_token=NULL WHERE status='claimed' AND claimed_at < DATE_SUB(NOW(6), INTERVAL 10 MINUTE)")->execute();
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare("SELECT * FROM harpp_runner_wake_requests WHERE status='pending' AND runner_key=:k ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $s->execute([':k' => $runnerKey]);
            $request = $s->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request)) {
                $this->db->commit();
                return HarppServiceResult::success(['request' => null]);
            }
            $token = $this->uuid();
            $s = $this->db->prepare("UPDATE harpp_runner_wake_requests SET status='claimed',claim_token=:t,claimed_at=NOW(6),attempts=attempts+1 WHERE id=:id AND status='pending'");
            $s->execute([':t' => $token, ':id' => (int)$request['id']]);
            if ($s->rowCount() !== 1) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Wake request conflict.', 409);
            }
            $this->db->commit();
            return HarppServiceResult::success(['request' => $this->wakeRequestPublic($this->loadWakeRequest((int)$request['id'])), 'claim_token' => $token]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deliverWake(array $actor, int $id, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $token = trim((string)($input['claim_token'] ?? ''));
        if ($token === '') return HarppServiceResult::failure('claim_token is required.', 422);
        $s = $this->db->prepare("UPDATE harpp_runner_wake_requests SET status='delivered',delivered_at=NOW(6),claim_token=NULL WHERE id=:id AND claim_token=:t AND status='claimed'");
        $s->execute([':id' => $id, ':t' => $token]);
        if ($s->rowCount() !== 1) return HarppServiceResult::failure('claim_invalid', 409, 'claim_invalid');
        return HarppServiceResult::success(['request' => $this->wakeRequestPublic($this->loadWakeRequest($id))]);
    }

    public function failWake(array $actor, int $id, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $token = trim((string)($input['claim_token'] ?? ''));
        if ($token === '') return HarppServiceResult::failure('claim_token is required.', 422);
        $error = substr(trim((string)($input['error'] ?? '')), 0, 2000);
        $s = $this->db->prepare("UPDATE harpp_runner_wake_requests SET status='failed',last_error=:e,claim_token=NULL WHERE id=:id AND claim_token=:t AND status='claimed'");
        $s->execute([':e' => $error, ':id' => $id, ':t' => $token]);
        if ($s->rowCount() !== 1) return HarppServiceResult::failure('claim_invalid', 409, 'claim_invalid');
        return HarppServiceResult::success(['request' => $this->wakeRequestPublic($this->loadWakeRequest($id))]);
    }

    public function listWakeRequests(array $actor, string $runnerKey, int $limit = 10): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $limit = max(1, min(50, $limit));
        $s = $this->db->prepare('SELECT * FROM harpp_runner_wake_requests WHERE runner_key=:k ORDER BY id DESC LIMIT :l');
        $s->bindValue(':k', $runnerKey);
        $s->bindValue(':l', $limit, PDO::PARAM_INT);
        $s->execute();
        return HarppServiceResult::success(['requests' => array_map(fn(array $row): array => $this->wakeRequestPublic($row), $s->fetchAll(PDO::FETCH_ASSOC))]);
    }

    /**
     * S4: owner-visible runner-fleet status. Returns every registered runner with
     * live/stale health (a stale heartbeat is marked offline), decoded
     * capabilities, and timestamps. Requires an authenticated owner/admin (module
     * owner/admin, bridge owner/admin, or kernel superadmin). Parameterized and
     * MySQL 5.7-safe (no window functions / CTEs).
     */
    public function listRunnersForOwner(array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Owner access is required.', 403, 'forbidden');
        // Mark runners with a stale heartbeat offline (idempotent; MySQL 5.7-safe).
        $this->db->prepare("UPDATE harpp_runners SET status='offline' WHERE last_heartbeat_at<DATE_SUB(NOW(6),INTERVAL 2 MINUTE)")->execute();
        $rows = $this->db->query("SELECT runner_key,display_name,status,capabilities_json,last_heartbeat_at,created_at FROM harpp_runners ORDER BY runner_key")->fetchAll(PDO::FETCH_ASSOC);
        $runners = [];
        foreach ($rows as $row) {
            $runners[] = [
                'runner_key' => (string)$row['runner_key'],
                'display_name' => (string)$row['display_name'],
                'status' => (string)$row['status'],
                'capabilities' => (array)json_decode((string)$row['capabilities_json'], true),
                'last_heartbeat_at' => $row['last_heartbeat_at'],
                'created_at' => $row['created_at'],
            ];
        }
        return HarppServiceResult::success(['runners' => $runners]);
    }

    public function claim(array $actor, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runnerKey = trim((string)($input['runner_key'] ?? ''));
        if ($runnerKey === '') return HarppServiceResult::failure('runner_key is required.', 422);
        $runner = $this->runner($runnerKey);
        if (!$runner) return HarppServiceResult::failure('Runner is not online.', 409, 'runner_offline');
        $capabilities = (array)json_decode((string)$runner['capabilities_json'], true);
        $leaseSeconds = max(30, min(3600, (int)($input['lease_seconds'] ?? 300)));
        $this->recoverExpiredLeases();
        // Priority by conversation recency so new work is never starved by old orphaned
        // runs. Bounded tradeoff: with heterogeneous capability fleets, a burst of
        // non-matching recent runs can occupy the candidate slice for one pass; the
        // duplicate guard (wake cancels answered runs) and reconcile keep it bounded.
        $rows = $this->db->query("SELECT wr.id,wr.required_capabilities_json FROM harpp_work_runs wr JOIN harpp_conversations c ON c.id=wr.conversation_id WHERE wr.state IN ('QUEUED','WAITING_FOR_RUNNER') ORDER BY c.updated_at DESC, wr.id ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!$this->hasCapabilities($capabilities, (array)json_decode((string)$row['required_capabilities_json'], true))) continue;
            $token = $this->uuid();
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='CLAIMED',runner_key=:runner,claim_token=:token,lease_expires_at=DATE_ADD(NOW(6),INTERVAL :lease SECOND),last_status='Claimed by runner.' WHERE id=:id AND state IN ('QUEUED','WAITING_FOR_RUNNER')");
            $u->execute([':runner' => $runnerKey, ':token' => $token, ':lease' => $leaseSeconds, ':id' => (int)$row['id']]);
            if ($u->rowCount() === 1) return HarppServiceResult::success(['run' => $this->publicRun($this->load((int)$row['id'])), 'claim_token' => $token]);
        }
        return HarppServiceResult::success(['run' => null, 'state' => 'WAITING_FOR_RUNNER']);
    }

    public function cancelRun(array $actor, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runId = (int)($input['run_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        $claimToken = trim((string)($input['claim_token'] ?? ''));
        if ($runId <= 0 && $messageId <= 0) return HarppServiceResult::failure('run_id or message_id is required.', 422);
        if ($runId <= 0) {
            $m = $this->db->prepare("SELECT id FROM harpp_work_runs WHERE source_message_id=:m");
            $m->execute([':m' => $messageId]);
            $found = (int)$m->fetchColumn();
            if ($found <= 0) return HarppServiceResult::success(['run' => null]);
            $runId = $found;
        }
        try {
            $this->db->beginTransaction();
            // Locking read: the 409-vs-idempotent classification below must reflect the
            // latest committed state even under the claim-vs-cancel race (REPEATABLE READ).
            $before = $this->load($runId, true);
            if (!is_array($before)) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run not found.', 404);
            }
            $state = (string)($before['state'] ?? '');
            // Claimable/stalled runs cancel unconditionally. A runner may also retire its
            // own CLAIMED/RUNNING run (e.g. the wake agent already answered the source
            // message) by presenting the matching claim token, so no STALLED artifact is
            // left behind by the duplicate guard.
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='CANCELLED',last_status='Cancelled before a runner claimed it (already answered or owner-cancelled).',claim_token=NULL,runner_key=NULL,lease_expires_at=NULL,finished_at=NOW(6) WHERE id=:id AND ((state IN ('QUEUED','WAITING_FOR_RUNNER','STALLED')) OR (state IN ('CLAIMED','RUNNING') AND claim_token=:token))");
            $u->execute([':id' => $runId, ':token' => $claimToken]);
            if ($u->rowCount() === 1) {
                $run = $this->load($runId);
                $this->effect($actor, 'harpp.work_run.cancelled', 'work_run.cancelled', $runId, $before, ['state' => 'CANCELLED']);
                $this->db->commit();
                return HarppServiceResult::success(['run' => $this->publicRun($run)]);
            }
            $this->db->commit();
            if ($state === 'AWAITING_APPROVAL') {
                return HarppServiceResult::failure('Run is awaiting owner approval and cannot be cancelled here; use the reject flow.', 409, 'not_cancellable');
            }
            if (in_array($state, ['CLAIMED', 'RUNNING'], true)) {
                return HarppServiceResult::failure('Run is already in progress.', 409, 'run_in_progress');
            }
            return HarppServiceResult::success(['run' => $this->publicRun($before)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to cancel work run.', 500);
        }
    }

    public function renew(array $actor, int $runId, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $token = trim((string)($input['claim_token'] ?? ''));
        $leaseSeconds = max(30, min(3600, (int)($input['lease_seconds'] ?? 300)));
        if ($runId <= 0 || $token === '') return HarppServiceResult::failure('run_id and claim_token are required.', 422);
        $s = $this->db->prepare("UPDATE harpp_work_runs SET lease_expires_at=DATE_ADD(NOW(6),INTERVAL :lease SECOND),last_status='Lease renewed.' WHERE id=:id AND claim_token=:token AND state IN ('CLAIMED','RUNNING')");
        $s->execute([':lease' => $leaseSeconds, ':id' => $runId, ':token' => $token]);
        if ($s->rowCount() !== 1) return HarppServiceResult::failure('Run claim is no longer valid.', 409, 'claim_invalid');
        return HarppServiceResult::success(['run' => $this->publicRun($this->load($runId))]);
    }

    public function transition(array $actor, int $runId, array $input, string $target): HarppServiceResult
    {
        if (!$this->bridgeActor($actor) || !in_array($target, self::STATES, true)) return HarppServiceResult::failure('Forbidden.', 403);
        $token = trim((string)($input['claim_token'] ?? ''));
        if ($runId <= 0 || $token === '') return HarppServiceResult::failure('run_id and claim_token are required.', 422);
        try {
            $this->db->beginTransaction();
            $before = $this->load($runId);
            $result = isset($input['result']) && is_array($input['result']) ? $input['result'] : null;
            $status = trim((string)($input['status'] ?? $target));

            // S3 risk gate: a HIGH/CRITICAL completion must not silently reach
            // SUCCEEDED. Park the run in AWAITING_APPROVAL with a hashed owner
            // approval token so approveRun()/rejectRun() (owner/admin bridge) are
            // the only promotion/revocation path. No artifact bundle is built here.
            if ($target === 'SUCCEEDED') {
                $tier = (new HarppRiskService())->classifyResult($result ?? []);
                $alreadyApproved = is_array($before) && (($before['approval_token_hash'] ?? null) !== null || ($before['approved_at'] ?? null) !== null);
                if ((new HarppRiskService())->requiresApproval($tier) && !$alreadyApproved) {
                    $approval = (new HarppRiskService())->newApprovalToken();
                    $s = $this->db->prepare("UPDATE harpp_work_runs SET state='AWAITING_APPROVAL',last_status=:status,result_json=:result,risk_level=:tier,approval_required=1,approval_token_hash=:hash WHERE id=:id AND claim_token=:token AND state IN ('CLAIMED','RUNNING','STALLED')");
                    $s->execute([
                        ':status' => substr('Run completed but requires owner approval before it can be marked SUCCEEDED.', 0, 2000),
                        ':result' => $result === null ? null : $this->json($result),
                        ':tier' => $tier,
                        ':hash' => $approval['hash'],
                        ':id' => $runId,
                        ':token' => $token,
                    ]);
                    if ($s->rowCount() !== 1) {
                        $this->db->rollBack();
                        return HarppServiceResult::failure('Run claim is no longer valid.', 409, 'claim_invalid');
                    }
                    $run = $this->load($runId);
                    $this->effect($actor, 'harpp.work_run.pending_approval', 'work_run.pending_approval', $runId, $before, ['state' => 'AWAITING_APPROVAL', 'risk_level' => $tier]);
                    $this->db->commit();
                    return HarppServiceResult::success(['run' => $this->publicRun($run), 'approval_required' => true, 'approval_token' => $approval['token'], 'risk_level' => $tier]);
                }
            }

            $finished = in_array($target, self::TERMINAL, true) ? ',finished_at=NOW(6),report_state=\'PENDING\',delivery_attempts=0,last_delivery_error=NULL' : '';
            $started = $target === 'RUNNING' ? ',started_at=COALESCE(started_at,NOW(6)),attempt_count=attempt_count+1' : '';
            // On SUCCEEDED always record the classified risk tier; approval_required
            // is cleared (already-approved runs were approved via the risk gate).
            $riskCols = $target === 'SUCCEEDED' ? ',risk_level=:tier,approval_required=0' : '';
            $params = [':state' => $target, ':status' => substr($status, 0, 2000), ':result' => $result === null ? null : $this->json($result), ':id' => $runId, ':token' => $token];
            if ($target === 'SUCCEEDED') $params[':tier'] = $tier;
            $s = $this->db->prepare("UPDATE harpp_work_runs SET state=:state,last_status=:status,result_json=:result{$riskCols}{$started}{$finished} WHERE id=:id AND claim_token=:token AND state IN ('CLAIMED','RUNNING','STALLED','AWAITING_APPROVAL')");
            $s->execute($params);
            if ($s->rowCount() !== 1) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run claim is no longer valid.', 409, 'claim_invalid');
            }
            $run = $this->load($runId);
            $this->effect($actor, 'harpp.work_run.'.strtolower($target), 'work_run.'.strtolower($target), $runId, $before, ['state' => $target]);
            $this->db->commit();
            if ($target === 'SUCCEEDED') {
                try { (new HarppArtifactService($this->db))->buildForRun($runId, $actor, null); } catch (\Throwable $e) {}
            }
            return HarppServiceResult::success(['run' => $this->publicRun($run)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to update work run.', 500);
        }
    }

    /**
     * S3: owner/admin (bridge) approves a risk-gated run, promoting it from
     * AWAITING_APPROVAL to SUCCEEDED, then auto-builds the artifact bundle.
     */
    public function approveRun(array $actor, int $runId, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if ($runId <= 0) return HarppServiceResult::failure('run_id is required.', 422);
        $token = trim((string)($input['approval_token'] ?? ''));
        if ($token === '') return HarppServiceResult::failure('approval_token is required.', 422);
        try {
            $this->db->beginTransaction();
            $before = $this->load($runId);
            if ($before === null) { $this->db->rollBack(); return HarppServiceResult::failure('Run not found.', 404); }
            if (($before['state'] ?? '') !== 'AWAITING_APPROVAL') {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run is not awaiting approval.', 409, 'not_awaiting_approval');
            }
            $hash = (string)($before['approval_token_hash'] ?? '');
            if ($hash === '' || !hash_equals($hash, hash('sha256', $token))) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Approval token is invalid.', 409, 'claim_invalid');
            }
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='SUCCEEDED',approved_by=:by,approved_at=NOW(6),approval_token_hash=NULL,approval_required=0,finished_at=NOW(6),report_state='PENDING',delivery_attempts=0,last_delivery_error=NULL,last_status='Run approved by owner.' WHERE id=:id AND state='AWAITING_APPROVAL'");
            $u->execute([':by' => (int)$actor['id'], ':id' => $runId]);
            if ($u->rowCount() !== 1) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run is no longer awaiting approval.', 409, 'claim_invalid');
            }
            $run = $this->load($runId);
            $this->effect($actor, 'harpp.work_run.approved', 'work_run.approved', $runId, $before, ['state' => 'SUCCEEDED']);
            $this->db->commit();
            try { (new HarppArtifactService($this->db))->buildForRun($runId, $actor, $tenantId); } catch (\Throwable $e) {}
            return HarppServiceResult::success(['run' => $this->publicRun($run)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to approve work run.', 500);
        }
    }

    /**
     * S3: owner/admin (bridge) rejects a risk-gated run, revoking it from
     * AWAITING_APPROVAL to CANCELLED (with the owner's rationale recorded).
     */
    public function rejectRun(array $actor, int $runId, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if ($runId <= 0) return HarppServiceResult::failure('run_id is required.', 422);
        $rationale = substr(trim((string)($input['rationale'] ?? '')), 0, 2000);
        if ($rationale === '') return HarppServiceResult::failure('A rejection rationale is required.', 422);
        try {
            $this->db->beginTransaction();
            $before = $this->load($runId);
            if ($before === null) { $this->db->rollBack(); return HarppServiceResult::failure('Run not found.', 404); }
            if (($before['state'] ?? '') !== 'AWAITING_APPROVAL') {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run is not awaiting approval.', 409, 'not_awaiting_approval');
            }
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='CANCELLED',approval_token_hash=NULL,approval_required=0,finished_at=NOW(6),report_state='PENDING',delivery_attempts=0,last_delivery_error=NULL,last_status=:status WHERE id=:id AND state='AWAITING_APPROVAL'");
            $u->execute([':status' => substr('Run rejected by owner: '.$rationale, 0, 2000), ':id' => $runId]);
            if ($u->rowCount() !== 1) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run is no longer awaiting approval.', 409, 'claim_invalid');
            }
            $run = $this->load($runId);
            $this->effect($actor, 'harpp.work_run.rejected', 'work_run.rejected', $runId, $before, ['state' => 'CANCELLED', 'rationale' => $rationale]);
            $this->db->commit();
            return HarppServiceResult::success(['run' => $this->publicRun($run)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to reject work run.', 500);
        }
    }

    public function status(array $actor, int $runId): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $run = $this->load($runId);
        return $run ? HarppServiceResult::success(['run' => $this->publicRun($run)]) : HarppServiceResult::failure('Run not found.', 404);
    }

    public function context(array $actor, int $conversationId, int $limit = 20): HarppServiceResult
    {
        if (!$this->bridgeActor($actor) || $conversationId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        $limit = max(1, min(50, $limit));
        $c = $this->db->prepare(
            'SELECT c.id,c.title,c.harness_session_id,c.status,c.version,c.updated_at,c.workspace_id,c.project_id,c.visibility,
                    w.workspace_key,w.name AS workspace_name,
                    p.project_key,p.name AS project_name
             FROM harpp_conversations c
             LEFT JOIN harpp_workspaces w ON w.id=c.workspace_id
             LEFT JOIN harpp_projects p ON p.id=c.project_id
             WHERE c.id=:id'
        );
        $c->execute([':id' => $conversationId]);
        $conversation = $c->fetch(PDO::FETCH_ASSOC);
        if (!is_array($conversation)) return HarppServiceResult::failure('Conversation not found.', 404);
        $m = $this->db->prepare("SELECT id,conversation_id,aggregate_sequence,sender_type,sender_user_id,body,payload,created_at FROM harpp_messages WHERE conversation_id=:id ORDER BY aggregate_sequence DESC,id DESC LIMIT {$limit}");
        $m->execute([':id' => $conversationId]);
        $messages = array_reverse($m->fetchAll(PDO::FETCH_ASSOC));
        $r = $this->db->prepare("SELECT id,source_message_id,state,report_state,runner_key,last_status,created_at,updated_at FROM harpp_work_runs WHERE conversation_id=:id ORDER BY id DESC LIMIT 5");
        $r->execute([':id' => $conversationId]);
        // Durable, bounded, versioned conversation summary (memory flywheel): recent
        // turns + active/latest run + applicable durable decisions. Version advances
        // with the conversation's latest message aggregate sequence, which is what
        // the bounded client context cache uses to invalidate.
        $summary = (new HarppContextSummaryService($this->db))->build($conversationId);
        return HarppServiceResult::success(['conversation' => $conversation, 'messages' => $messages, 'runs' => $r->fetchAll(PDO::FETCH_ASSOC), 'summary' => $summary, 'cache' => ['version' => (int)($summary['version'] ?? 0), 'message_limit' => $limit]]);
    }

    private function runner(string $runnerKey): ?array
    {
        $this->db->prepare("UPDATE harpp_runners SET status='offline' WHERE last_heartbeat_at<DATE_SUB(NOW(6),INTERVAL 2 MINUTE)")->execute();
        $s = $this->db->prepare("SELECT * FROM harpp_runners WHERE runner_key=:key AND status='online'");
        $s->execute([':key' => $runnerKey]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function recoverExpiredLeases(): void
    {
        // A claim that never started (CLAIMED) is safe to requeue, as is a RUNNING
        // run that still has an explicit retry budget. Never silently drop work.
        $this->db->prepare("UPDATE harpp_work_runs SET state='QUEUED',claim_token=NULL,runner_key=NULL,lease_expires_at=NULL,last_status='Lease expired; queued for retry.' WHERE state IN ('CLAIMED','RUNNING') AND lease_expires_at<NOW(6) AND (state='CLAIMED' OR attempt_count<max_attempts)")->execute();
        // A RUNNING run whose lease expired with the retry budget exhausted means a
        // child process stopped heartbeating without a terminal report. Reconcile it
        // to STALLED so a dead process is never left represented as RUNNING.
        $this->db->prepare("UPDATE harpp_work_runs SET state='STALLED',claim_token=NULL,runner_key=NULL,lease_expires_at=NULL,stalled_at=NOW(6),last_status='No healthy child-process heartbeat; run stalled.' WHERE state='RUNNING' AND lease_expires_at<NOW(6) AND attempt_count>=max_attempts")->execute();
    }

    /**
     * Runner-side reconciliation: the supervising runner reports the set of run ids
     * it is actively keeping alive. Any run claimed by this runner that is not in
     * the healthy set is independently repaired to STALLED, proving a dead child
     * (whose terminal report may never have been delivered) is never left RUNNING.
     */
    public function reconcileRuns(array $actor, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runnerKey = trim((string)($input['runner_key'] ?? ''));
        if ($runnerKey === '') return HarppServiceResult::failure('runner_key is required.', 422);
        $healthy = [];
        foreach ((array)($input['healthy'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) $healthy[$id] = true;
        }
        $s = $this->db->prepare("SELECT id FROM harpp_work_runs WHERE runner_key=:runner AND state IN ('CLAIMED','RUNNING')");
        $s->execute([':runner' => $runnerKey]);
        $stalled = 0;
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $runId) {
            $runId = (int)$runId;
            if (isset($healthy[$runId])) continue;
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='STALLED',stalled_at=COALESCE(stalled_at,NOW(6)),claim_token=NULL,lease_expires_at=NULL,last_status='Runner reconciliation: child process no longer healthy.' WHERE id=:id AND state IN ('CLAIMED','RUNNING')");
            $u->execute([':id' => $runId]);
            if ($u->rowCount() === 1) $stalled++;
        }
        return HarppServiceResult::success(['stalled' => $stalled, 'runner_key' => $runnerKey]);
    }

    /** Report delivery: mark a terminal run's report DELIVERED (idempotent). */
    public function reportDelivered(array $actor, int $runId, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $u = $this->db->prepare("UPDATE harpp_work_runs SET report_state='DELIVERED',delivery_attempts=delivery_attempts+1,last_delivery_error=NULL WHERE id=:id AND state IN ('SUCCEEDED','FAILED','CANCELLED')");
        $u->execute([':id' => $runId]);
        if ($u->rowCount() !== 1) return HarppServiceResult::failure('Run is not terminal or report already delivered.', 409, 'not_deliverable');
        return HarppServiceResult::success(['run' => $this->publicRun($this->load($runId))]);
    }

    /** Report delivery: dead-letter a terminal run's report with an inspectable error. */
    public function reportDeadLetter(array $actor, int $runId, array $input): HarppServiceResult
    {
        if (!$this->bridgeActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $error = substr(trim((string)($input['error'] ?? '')), 0, 2000);
        $u = $this->db->prepare("UPDATE harpp_work_runs SET report_state='DEAD_LETTER',delivery_attempts=delivery_attempts+1,last_delivery_error=:error WHERE id=:id AND state IN ('SUCCEEDED','FAILED','CANCELLED')");
        $u->execute([':id' => $runId, ':error' => $error !== '' ? $error : null]);
        if ($u->rowCount() !== 1) return HarppServiceResult::failure('Run is not terminal.', 409, 'not_deliverable');
        return HarppServiceResult::success(['run' => $this->publicRun($this->load($runId))]);
    }

    /**
     * Drive report delivery for terminal runs whose reports are still PENDING.
     * A configured dispatcher performs the actual delivery; on success the run is
     * marked DELIVERED, on failure attempts increment and the report stays PENDING
     * (retained + visible), and after the attempt ceiling it is dead-lettered.
     * Without a dispatcher, PENDING reports remain visible with attempts tracked.
     */
    public function dispatchRunReports(?callable $dispatcher = null): HarppServiceResult
    {
        $dispatcher = $dispatcher ?? $this->reportDispatcher;
        $rows = $this->db->query("SELECT id,conversation_id,state,delivery_attempts FROM harpp_work_runs WHERE state IN ('SUCCEEDED','FAILED','CANCELLED') AND report_state='PENDING' LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $delivered = 0;
        $failed = 0;
        $dead = 0;
        foreach ($rows as $row) {
            $runId = (int)$row['id'];
            if ($dispatcher !== null) {
                try {
                    $ok = (bool)$dispatcher($runId, (int)$row['conversation_id'], (string)$row['state']);
                } catch (Throwable $e) {
                    $ok = false;
                }
                if ($ok) {
                    $this->db->prepare("UPDATE harpp_work_runs SET report_state='DELIVERED',delivery_attempts=delivery_attempts+1,last_delivery_error=NULL WHERE id=:id AND report_state='PENDING'")->execute([':id' => $runId]);
                    $delivered++;
                    continue;
                }
                $attempt = (int)$row['delivery_attempts'] + 1;
                if ($attempt >= self::MAX_REPORT_DELIVERY_ATTEMPTS) {
                    $this->db->prepare("UPDATE harpp_work_runs SET report_state='DEAD_LETTER',delivery_attempts=:a,last_delivery_error='Report delivery attempts exhausted.' WHERE id=:id AND report_state='PENDING'")->execute([':a' => $attempt, ':id' => $runId]);
                    $dead++;
                } else {
                    $this->db->prepare("UPDATE harpp_work_runs SET delivery_attempts=:a,last_delivery_error='Report delivery failed; scheduled for retry.' WHERE id=:id AND report_state='PENDING'")->execute([':a' => $attempt, ':id' => $runId]);
                    $failed++;
                }
                continue;
            }
            // No dispatcher wired: keep the report retained and visible (PENDING)
            // and record the attempted pass so diagnostics show it was inspected.
            $this->db->prepare("UPDATE harpp_work_runs SET delivery_attempts=delivery_attempts+1 WHERE id=:id AND report_state='PENDING'")->execute([':id' => $runId]);
        }
        return HarppServiceResult::success(['delivered' => $delivered, 'failed' => $failed, 'dead' => $dead]);
    }

    private function hasOnlineRunner(array $required): bool
    {
        $this->db->prepare("UPDATE harpp_runners SET status='offline' WHERE last_heartbeat_at<DATE_SUB(NOW(6),INTERVAL 2 MINUTE)")->execute();
        foreach ($this->db->query("SELECT capabilities_json FROM harpp_runners WHERE status='online'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            if ($this->hasCapabilities((array)json_decode((string)$json, true), $required)) return true;
        }
        return false;
    }

    private function hasCapabilities(array $runner, array $required): bool
    {
        return count(array_diff($required, $this->normalizeCapabilities($runner))) === 0;
    }

    private function normalizeCapabilities(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            $capability = strtolower(trim((string)$capability));
            if ($capability !== '' && preg_match('/^[a-z0-9._:-]{1,64}$/', $capability)) $out[$capability] = true;
        }
        return array_keys($out ?: ['desktop' => true]);
    }

    private function loadByMessage(int $messageId, bool $locked = false): ?array
    {
        $sql = 'SELECT * FROM harpp_work_runs WHERE source_message_id=:id'.($locked ? ' FOR UPDATE' : '');
        $s = $this->db->prepare($sql);
        $s->execute([':id' => $messageId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function load(int $runId, bool $locked = false): ?array
    {
        $sql = 'SELECT * FROM harpp_work_runs WHERE id=:id'.($locked ? ' FOR UPDATE' : '');
        $s = $this->db->prepare($sql);
        $s->execute([':id' => $runId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function publicRun(?array $run): ?array
    {
        if ($run === null) return null;
        unset($run['claim_token']);
        return $run;
    }

    private function loadWakeRequest(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM harpp_runner_wake_requests WHERE id=:id');
        $s->execute([':id' => $id]);
        return (array)$s->fetch(PDO::FETCH_ASSOC);
    }

    private function wakeRequestPublic(array $row): array
    {
        unset($row['claim_token']);
        return $row;
    }

    private function ownerId(): int
    {
        return (int)$this->db->query("SELECT id FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1")->fetchColumn();
    }

    private function effect(array $actor, string $event, string $action, int $runId, ?array $before, array $after): void
    {
        (new HarppFoundationService($this->db))->recordEffect($event, $action, $actor, 'harpp_work_run', $runId, $before, $after);
    }

    private function bridgeActor(array $actor): bool
    {
        return ($actor['source'] ?? '') === 'harpp_bridge' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true);
    }

    /** S4: owner/admin (module, bridge, or kernel superadmin) gate for owner-visible reads. */
    private function ownerActor(array $actor): bool
    {
        return (($actor['source'] ?? 'harpp') === 'harpp' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true))
            || (($actor['source'] ?? '') === 'harpp_bridge' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true))
            || (($actor['source'] ?? '') === 'kernel' && ($actor['role'] ?? '') === 'superadmin');
    }

    /** MySQL 5.7-safe existence check (used to degrade gracefully when migration 019
     *  has not yet been applied to a tenant). */
    private function tableExists(string $table): bool
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t');
        $s->execute([':t' => $table]);
        return (int)$s->fetchColumn() > 0;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
