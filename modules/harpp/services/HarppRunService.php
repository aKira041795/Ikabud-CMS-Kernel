<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppRunService
{
    private const STATES = ['QUEUED','WAITING_FOR_RUNNER','CLAIMED','RUNNING','STALLED','SUCCEEDED','FAILED','CANCELLED'];
    private const TERMINAL = ['SUCCEEDED','FAILED','CANCELLED'];

    public function __construct(private ModuleDB $db) {}

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
        $rows = $this->db->query("SELECT id,required_capabilities_json FROM harpp_work_runs WHERE state IN ('QUEUED','WAITING_FOR_RUNNER') ORDER BY id ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!$this->hasCapabilities($capabilities, (array)json_decode((string)$row['required_capabilities_json'], true))) continue;
            $token = $this->uuid();
            $u = $this->db->prepare("UPDATE harpp_work_runs SET state='CLAIMED',runner_key=:runner,claim_token=:token,lease_expires_at=DATE_ADD(NOW(6),INTERVAL :lease SECOND),last_status='Claimed by runner.' WHERE id=:id AND state IN ('QUEUED','WAITING_FOR_RUNNER')");
            $u->execute([':runner' => $runnerKey, ':token' => $token, ':lease' => $leaseSeconds, ':id' => (int)$row['id']]);
            if ($u->rowCount() === 1) return HarppServiceResult::success(['run' => $this->publicRun($this->load((int)$row['id'])), 'claim_token' => $token]);
        }
        return HarppServiceResult::success(['run' => null, 'state' => 'WAITING_FOR_RUNNER']);
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
            $finished = in_array($target, self::TERMINAL, true) ? ',finished_at=NOW(6),report_state=\'PENDING\'' : '';
            $started = $target === 'RUNNING' ? ',started_at=COALESCE(started_at,NOW(6))' : '';
            $s = $this->db->prepare("UPDATE harpp_work_runs SET state=:state,last_status=:status,result_json=:result{$started}{$finished} WHERE id=:id AND claim_token=:token AND state IN ('CLAIMED','RUNNING','STALLED')");
            $s->execute([':state' => $target, ':status' => substr($status, 0, 2000), ':result' => $result === null ? null : $this->json($result), ':id' => $runId, ':token' => $token]);
            if ($s->rowCount() !== 1) {
                $this->db->rollBack();
                return HarppServiceResult::failure('Run claim is no longer valid.', 409, 'claim_invalid');
            }
            $run = $this->load($runId);
            $this->effect($actor, 'harpp.work_run.'.strtolower($target), 'work_run.'.strtolower($target), $runId, $before, ['state' => $target]);
            $this->db->commit();
            return HarppServiceResult::success(['run' => $this->publicRun($run)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to update work run.', 500);
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
        $c = $this->db->prepare('SELECT id,title,harness_session_id,status,version,updated_at FROM harpp_conversations WHERE id=:id');
        $c->execute([':id' => $conversationId]);
        $conversation = $c->fetch(PDO::FETCH_ASSOC);
        if (!is_array($conversation)) return HarppServiceResult::failure('Conversation not found.', 404);
        $m = $this->db->prepare("SELECT id,conversation_id,aggregate_sequence,sender_type,sender_user_id,body,payload,created_at FROM harpp_messages WHERE conversation_id=:id ORDER BY aggregate_sequence DESC,id DESC LIMIT {$limit}");
        $m->execute([':id' => $conversationId]);
        $messages = array_reverse($m->fetchAll(PDO::FETCH_ASSOC));
        $r = $this->db->prepare("SELECT id,source_message_id,state,report_state,runner_key,last_status,created_at,updated_at FROM harpp_work_runs WHERE conversation_id=:id ORDER BY id DESC LIMIT 5");
        $r->execute([':id' => $conversationId]);
        return HarppServiceResult::success(['conversation' => $conversation, 'messages' => $messages, 'runs' => $r->fetchAll(PDO::FETCH_ASSOC), 'cache' => ['version' => (int)$conversation['version'], 'message_limit' => $limit]]);
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
        $this->db->prepare("UPDATE harpp_work_runs SET state='QUEUED',claim_token=NULL,runner_key=NULL,lease_expires_at=NULL,last_status='Lease expired; queued for retry.' WHERE state IN ('CLAIMED','RUNNING') AND lease_expires_at<NOW(6)")->execute();
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

    private function load(int $runId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM harpp_work_runs WHERE id=:id');
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

    private function effect(array $actor, string $event, string $action, int $runId, ?array $before, array $after): void
    {
        (new HarppFoundationService($this->db))->recordEffect($event, $action, $actor, 'harpp_work_run', $runId, $before, $after);
    }

    private function bridgeActor(array $actor): bool
    {
        return ($actor['source'] ?? '') === 'harpp_bridge' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true);
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
