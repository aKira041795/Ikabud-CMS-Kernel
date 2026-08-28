<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppBridgeService
{
    public function __construct(private ModuleDB $db) {}

    public function createDecision(array $actor, array $input, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->create($actor, $input, $tenantId);
    }

    public function listDecisions(array $actor, array $filters, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->list($actor, $filters, $tenantId);
    }

    public function view(array $actor, int $decisionId, array $input, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->transition($actor, $decisionId, 'VIEWED', trim((string)($input['rationale'] ?? 'Owner viewed the decision via messenger.')), $input, $tenantId);
    }

    public function decide(array $actor, int $decisionId, array $input, int $tenantId)
    {
        $decision = trim((string)($input['decision'] ?? ''));
        if ($decision === '') return HarppServiceResult::failure('Decision text is required.', 422);
        $input['decision']=$decision;
        return (new HarppDecisionService($this->db))->transition($actor, $decisionId, 'DECIDED', trim((string)($input['rationale'] ?? 'Owner decision recorded via harness.')), $input, $tenantId);
    }

    public function acknowledge(array $actor, int $decisionId, array $input, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->transition($actor, $decisionId, 'ACKNOWLEDGED', trim((string)($input['rationale'] ?? 'Harness acknowledged the owner decision.')), $input, $tenantId);
    }

    public function cancel(array $actor, int $decisionId, array $input, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->transition($actor, $decisionId, 'CANCELLED', trim((string)($input['rationale'] ?? 'Owner cancelled the decision.')), $input, $tenantId);
    }

    public function applied(array $actor, int $decisionId, array $input, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->applyAndClose(
            $actor,
            $decisionId,
            trim((string)($input['rationale'] ?? 'Harness applied the owner decision.')),
            trim((string)($input['close_rationale'] ?? 'Applied decision closed by harness.')),
            $input,
            $tenantId
        );
    }

    public function sendMessage(array $actor, array $input, int $tenantId)
    {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            // Only the owner can start conversations. Autocreation here flooded the
            // inbox with useless conversations whenever the bridge sent a message
            // without a conversation id, so the harness must reply inside an
            // existing conversation instead of creating one.
            return HarppServiceResult::failure('A conversation_id is required. Only the owner can start new conversations.', 422, 'conversation_required');
        }
        $input['sender_type'] = 'harness';
        return (new HarppMessagingService($this->db))->sendMessage($actor, $conversationId, $input, $tenantId);
    }

    public function pollMessages(array $actor, array $filters, int $tenantId)
    {
        $result = (new HarppMessagingService($this->db))->listOwnerMessagesForHarness($actor, $filters, $tenantId);
        if (empty($result['ok'])) return $result;
        $runner = new HarppRunService($this->db);
        foreach ((array)($result['data']['messages'] ?? []) as $message) {
            $runner->ensureForMessage($actor, (int)$message['id'], (array)($filters['required_capabilities'] ?? ['desktop']));
        }
        return (new HarppMessagingService($this->db))->listOwnerMessagesForHarness($actor, $filters, $tenantId);
    }

    public function queueMessageRun(array $actor, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->ensureForMessage($actor, (int)($input['message_id'] ?? 0), (array)($input['required_capabilities'] ?? ['desktop']));
    }

    public function registerRunner(array $actor, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->registerRunner($actor, $input);
    }

    public function claimRun(array $actor, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->claim($actor, $input);
    }

    public function runRunning(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->transition($actor, $runId, $input, 'RUNNING');
    }

    public function renewRun(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->renew($actor, $runId, $input);
    }

    public function completeRun(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->transition($actor, $runId, $input, 'SUCCEEDED');
    }

    public function failRun(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->transition($actor, $runId, $input, 'FAILED');
    }

    public function stallRun(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->transition($actor, $runId, $input, 'STALLED');
    }

    public function reconcileRuns(array $actor, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->reconcileRuns($actor, $input);
    }

    public function reportDelivered(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->reportDelivered($actor, $runId, $input);
    }

    public function reportDeadLetter(array $actor, int $runId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->reportDeadLetter($actor, $runId, $input);
    }

    public function runStatus(array $actor, int $runId, int $tenantId)
    {
        return (new HarppRunService($this->db))->status($actor, $runId);
    }

    /**
     * S1 MCP spine: return the approved decision's artifact bundle (ADR + decision
     * + files) as an authorized owner/admin bridge read. Builds the bundle if the
     * decision is terminal and a bundle is not yet present, then views it with
     * artifact payloads included (the bridge actor is always an owner/admin).
     */
    public function artifactBundleForDecision(array $actor, int $decisionId, int $tenantId)
    {
        $bridgeActor = $actor;
        $bridgeActor['source'] = 'harpp_bridge';
        $bridgeActor['role'] = 'owner';
        $service = new \Harpp\Services\HarppArtifactService($this->db);
        $built = $service->buildForDecision($decisionId, $bridgeActor, $tenantId);
        if (empty($built['ok'])) return $built;
        $bundleId = (int)(($built['data'] ?? [])['bundle_id'] ?? 0);
        if ($bundleId <= 0) return HarppServiceResult::failure('Artifact bundle not found.', 404);
        return $service->view($bundleId, $bridgeActor, $tenantId);
    }

    /** S1 MCP spine: list registered runners with live/stale heartbeat status. */
    public function listRunners(array $actor, int $tenantId)
    {
        // Mark runners with a stale heartbeat offline (idempotent; MySQL 5.7-safe).
        $this->db->prepare("UPDATE harpp_runners SET status='offline' WHERE last_heartbeat_at<DATE_SUB(NOW(6),INTERVAL 2 MINUTE)")->execute();
        $rows = $this->db->query("SELECT runner_key,display_name,status,capabilities_json,last_heartbeat_at FROM harpp_runners ORDER BY runner_key")->fetchAll(PDO::FETCH_ASSOC);
        $runners = [];
        foreach ($rows as $row) {
            $runners[] = [
                'runner_key' => (string)$row['runner_key'],
                'display_name' => (string)$row['display_name'],
                'status' => (string)$row['status'],
                'capabilities' => (array)json_decode((string)$row['capabilities_json'], true),
                'last_heartbeat_at' => $row['last_heartbeat_at'],
            ];
        }
        return HarppServiceResult::success(['runners' => $runners]);
    }

    /** S1 MCP spine: get a single decision's full detail (reuses the decision service). */
    public function getDecision(array $actor, int $decisionId, int $tenantId)
    {
        return (new HarppDecisionService($this->db))->get($actor, $decisionId, $tenantId);
    }

    public function memorySearch(array $actor, array $input, int $tenantId)
    {
        return (new HarppMemoryService($this->db))->search($actor, $input, $tenantId);
    }

    public function conversationContext(array $actor, int $conversationId, array $input, int $tenantId)
    {
        return (new HarppRunService($this->db))->context($actor, $conversationId, (int)($input['limit'] ?? 20));
    }

    public function listConversations(array $actor, array $filters, int $tenantId)
    {
        return (new HarppMessagingService($this->db))->listConversations($actor, $filters, $tenantId);
    }

    public function archiveConversation(array $actor, int $conversationId, array $input, int $tenantId)
    {
        return (new HarppMessagingService($this->db))->archiveConversation(
            $actor,
            $conversationId,
            filter_var($input['archived'] ?? true, FILTER_VALIDATE_BOOLEAN),
            $tenantId
        );
    }

    public function listNotifications(array $actor, array $filters, int $tenantId)
    {
        return (new HarppNotificationService($this->db))->list($actor, $filters, $tenantId);
    }

    public function markNotificationRead(array $actor, int $notificationId, int $tenantId)
    {
        return (new HarppNotificationService($this->db))->markRead($actor, $notificationId, $tenantId);
    }

    public function notificationUnreadCount(array $actor, int $tenantId)
    {
        return (new HarppNotificationService($this->db))->unreadCount($actor, $tenantId);
    }

    public function status(array $actor, array $input, int $tenantId)
    {
        $status = strtolower(trim((string)($input['status'] ?? '')));
        $session = trim((string)($input['harness_session_id'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $status) || $session === '' || strlen($session) > 191 || !preg_match('/^[A-Za-z0-9._:-]+$/', $session) || strlen($message) > 2000) {
            return HarppServiceResult::failure('Valid status and harness_session_id are required.');
        }
        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $foundation=new HarppFoundationService($this->db);$recipient=$this->ownerId();if($recipient<=0)throw new \InvalidArgumentException('No active owner is available.');$recipients=[$recipient];
            if($foundation->enabled('notification_fanout')){$workspace=(int)($this->db->query("SELECT id FROM harpp_workspaces WHERE workspace_key='legacy' LIMIT 1")->fetchColumn()?:0);if($workspace>0)$recipients=(new HarppCollaborationService($this->db))->notificationRecipients($workspace,null,'bridge.status',0);}
            $notifications = new HarppNotificationService($this->db);
            $deliveries=[];$notice=null;$actorNotice=null;foreach($recipients as $recipient){$notice=$notifications->create($recipient,'system',['event'=>'bridge.status','status'=>$status,'harness_session_id'=>$session,'message'=>$message,'workbench_state'=>trim((string)($input['workbench_state']??''))],null,null,null,false);if(empty($notice['ok']))throw new \RuntimeException((string)$notice['error']);$deliveries[]=['id'=>(int)($notice['data']['notification_id']??0),'user_id'=>$recipient];if((int)$recipient===(int)($actor['id']??0))$actorNotice=$notice;}
            $this->effects($actor, $session, ['status'=>$status,'message'=>$message,'notification_deliveries'=>$deliveries]);
            if ($ownsTransaction) $this->db->commit();
            return $actorNotice??$notice??HarppServiceResult::success(['notification_ids'=>[]]);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            if (function_exists('write_log')) \write_log('HARPP bridge status failed', 'error', ['module'=>'harpp','error'=>$e->getMessage()]);
            return HarppServiceResult::failure($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to record bridge status.', $e instanceof \InvalidArgumentException ? 422 : 500);
        }
    }

    /**
     * Delete a stuck idempotency key so the scope can be re-claimed. Owner/admin
     * only (bridge actor is always an owner/admin). Used to unblock a message
     * whose key was completed under a different request hash (permanent 409).
     */
    public function releaseIdempotency(array $actor, array $input, int $tenantId)
    {
        $scope = trim((string)($input['scope'] ?? 'harpp_message'));
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if ($scope === '' || $key === '' || strlen($key) > 191) {
            return HarppServiceResult::failure('A valid scope and idempotency_key are required.', 422);
        }
        if (!in_array((string)($actor['source'] ?? ''), ['harpp_bridge', 'harpp'], true)
            || !in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true)) {
            return HarppServiceResult::failure('Owner or admin access is required.', 403);
        }
        $removed = (new HarppFoundationService($this->db))->releaseIdempotency($scope, $key);
        return HarppServiceResult::success(['idempotency_key' => $key, 'scope' => $scope, 'released' => $removed]);
    }

    private function effects(array $actor, string $session, array $after): void
    {
        (new HarppFoundationService($this->db))->recordEffect('harpp.bridge.status_updated','bridge.status',$actor,'harpp_bridge_session',$session,null,$after);
    }

    private function ownerId(): int
    {
        $stmt = $this->db->query("SELECT id FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
