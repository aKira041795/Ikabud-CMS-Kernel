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
        return (new HarppDecisionService($this->db))->transition($actor, $decisionId, 'DECIDED', trim((string)($input['rationale'] ?? 'Owner decision recorded via harness.')), ['decision' => $decision], $tenantId);
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
        $messaging = new HarppMessagingService($this->db);
        $conversationId = (int)($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            $created = $messaging->createConversation($actor, [
                'title' => $input['title'] ?? 'Harness session',
                'harness_session_id' => $input['harness_session_id'] ?? '',
            ], $tenantId);
            if (empty($created['ok'])) return $created;
            $conversationId = (int)$created['data']['conversation_id'];
        }
        $input['sender_type'] = 'harness';
        return $messaging->sendMessage($actor, $conversationId, $input, $tenantId);
    }

    public function pollMessages(array $actor, array $filters, int $tenantId)
    {
        return (new HarppMessagingService($this->db))->listOwnerMessagesForHarness($actor, $filters, $tenantId);
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
            $recipient = $this->ownerId();
            if ($recipient <= 0) throw new \InvalidArgumentException('No active owner is available.');
            $notifications = new HarppNotificationService($this->db);
            $notice = $notifications->create($recipient, 'system', [
                'event' => 'bridge.status', 'status' => $status, 'harness_session_id' => $session,
                'message' => $message, 'workbench_state' => trim((string)($input['workbench_state'] ?? '')),
            ], null, null, null, false);
            if (empty($notice['ok'])) throw new \RuntimeException((string)$notice['error']);
            $notificationId = (int)($notice['data']['notification_id'] ?? 0);
            $this->effects($actor, $session, ['status'=>$status,'message'=>$message,'notification_id'=>$notificationId]);
            if ($ownsTransaction) $this->db->commit();
            if ($notificationId > 0) $notifications->dispatch($notificationId, $recipient);
            return $notice;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            if (function_exists('write_log')) \write_log('HARPP bridge status failed', 'error', ['module'=>'harpp','error'=>$e->getMessage()]);
            return HarppServiceResult::failure($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to record bridge status.', $e instanceof \InvalidArgumentException ? 422 : 500);
        }
    }

    private function effects(array $actor, string $session, array $after): void
    {
        $payload = ['harness_session_id'=>$session,'actor_user_id'=>(int)($actor['id']??0),'after'=>$after];
        \app()->events()->fire('harpp.bridge.status_updated', $payload, 'harpp');
        $audit = \app()->cap()->call('kernel.audit.record@1', ['module'=>'harpp','action'=>'bridge.status','entity_type'=>'harpp_bridge_session','entity_id'=>$session,'new_data'=>$after], ['mode'=>'first','caller_module'=>'harpp']);
        if (!is_array($audit) || empty($audit['ok'])) throw new \RuntimeException('Kernel audit recording failed.');
    }

    private function ownerId(): int
    {
        $stmt = $this->db->query("SELECT id FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
