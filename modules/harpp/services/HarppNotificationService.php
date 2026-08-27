<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppNotificationService
{
    public function __construct(private ?ModuleDB $database = null, private ?HarppPushService $push = null)
    {
    }

    private function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) { return $this->database; }
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) { throw new \RuntimeException('HARPP module database is unavailable.'); }
        return $this->database = $db;
    }

    public function create(int $userId, string $type, array $payload, ?int $decisionId = null, ?int $conversationId = null, ?int $messageId = null, bool $dispatch = true)
    {
        if ($userId <= 0 || !in_array($type, ['decision', 'message', 'system'], true)) {
            return HarppServiceResult::failure('Invalid notification recipient or type.');
        }
        if (!$this->channelEnabled('push') || !$this->typeEnabled($type)) {
            return HarppServiceResult::success(['notification_id' => 0, 'skipped' => true, 'reason' => 'notification_disabled']);
        }
        $ownsTransaction = !$this->db()->inTransaction();
        try {
            if ($ownsTransaction) $this->db()->beginTransaction();
            $payload = $this->presentablePayload($type, $payload, $decisionId, $conversationId, $messageId);
            $payloadJson=json_encode($this->canonical($payload),JSON_THROW_ON_ERROR);$dedup=hash('sha256',json_encode(['user_id'=>$userId,'type'=>$type,'decision_id'=>$decisionId,'conversation_id'=>$conversationId,'message_id'=>$messageId,'payload'=>$this->canonical($payload)],JSON_THROW_ON_ERROR));
            $stmt = $this->db()->prepare('INSERT IGNORE INTO harpp_notifications (user_id, decision_id, conversation_id, message_id, notification_type, channel, status, payload, dedup_key, created_at) VALUES (:user, :decision, :conversation, :message, :type, \'push\', \'pending\', :payload, :dedup, NOW())');
            $stmt->execute([':user' => $userId, ':decision' => $decisionId, ':conversation' => $conversationId, ':message' => $messageId, ':type' => $type, ':payload' => $payloadJson, ':dedup'=>$dedup]);$created=$stmt->rowCount()===1;
            if($created){$id=(int)$this->db()->lastInsertId();}else{$existing=$this->db()->prepare('SELECT id FROM harpp_notifications WHERE dedup_key=:dedup LIMIT 1');$existing->execute([':dedup'=>$dedup]);$id=(int)$existing->fetchColumn();if($id<=0)throw new \RuntimeException('Notification dedup replay could not be resolved.');}
            $after=['status'=>'pending','type'=>$type,'channel'=>'push','decision_id'=>$decisionId,'conversation_id'=>$conversationId,'message_id'=>$messageId];if($created&&$dispatch)$after['notification_deliveries']=[['id'=>$id,'user_id'=>$userId]];$event = $created?$this->effects('harpp.notification.created', 'notification.created', $id, $userId, null, $after):null;
            if ($ownsTransaction) $this->db()->commit();
            return HarppServiceResult::success(['notification_id' => $id,'idempotent_replay'=>!$created], '', array_values(array_filter([$event])), 'harpp_notification', $id);
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('notification create failed', $e);
            return HarppServiceResult::failure('Unable to create notification.', 500);
        }
    }

    /**
     * Non-actionable message types never warrant an OS-level push when the
     * "important only" toggle is on. Decisions are always actionable.
     */
    private const IMPORTANT_MESSAGE_TYPES = ['WARNING', 'DECISION_REQUIRED', 'BLOCKED', 'RELEASE_READY', 'FAILED'];

    private function isImportant(array $notice, array $payload): bool
    {
        $type = (string)($notice['notification_type'] ?? 'system');
        if ($type === 'decision') return true;
        if ($type !== 'message') return true; // system notifications stay on
        if ($this->setting('push_important_only', '0') !== '1') return true;
        $messageType = strtoupper(trim((string)($payload['message_type'] ?? 'INFO'))) ?: 'INFO';
        return in_array($messageType, self::IMPORTANT_MESSAGE_TYPES, true);
    }

    public function dispatch(int $notificationId, int $userId)
    {
        $check = $this->db()->prepare('SELECT id, decision_id, conversation_id, message_id, notification_type, channel, status, payload, created_at FROM harpp_notifications WHERE id = :id AND user_id = :user');
        $check->execute([':id' => $notificationId, ':user' => $userId]);
        $notice = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($notice) || !$this->channelEnabled((string)$notice['channel']) || !$this->typeEnabled((string)$notice['notification_type'])) {
            return HarppServiceResult::success(['attempted' => 0, 'sent' => 0, 'skipped' => true]);
        }
        $payload = json_decode((string)($notice['payload'] ?? '{}'), true);
        // "Important messages only": keep the in-app notification but skip the
        // OS push for conversational INFO/PROGRESS messages (the common chatter
        // that used to flood the phone).
        if (!$this->isImportant($notice, is_array($payload) ? $payload : [])) {
            return HarppServiceResult::success(['attempted' => 0, 'sent' => 0, 'skipped' => true, 'reason' => 'not_important']);
        }
        $result = ($this->push ??= new HarppPushService($this->db()))->dispatchToUser($userId, $this->pushPayload($notice, is_array($payload) ? $payload : []));
        $sent = !empty($result['ok']) && (int)($result['data']['sent'] ?? 0) > 0;
        $ownsTransaction = !$this->db()->inTransaction();
        try {
            if ($ownsTransaction) $this->db()->beginTransaction();
            $status = $sent ? 'sent' : 'pending';
            $stmt = $this->db()->prepare('UPDATE harpp_notifications SET status = :status, sent_at = IF(:sent = 1, NOW(), sent_at) WHERE id = :id AND user_id = :user');
            $stmt->execute([':status' => $status, ':sent' => $sent ? 1 : 0, ':id' => $notificationId, ':user' => $userId]);
            $this->effects('harpp.notification.status_changed', 'notification.status_changed', $notificationId, $userId, ['status'=>(string)$notice['status']], ['status'=>$status,'sent'=>$sent]);
            if ($ownsTransaction) $this->db()->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('notification status update failed', $e);
            return HarppServiceResult::failure('Unable to update notification status.', 500);
        }
        return $result;
    }

    public function list(array $actor, array $filters = [], ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId)) { return HarppServiceResult::failure('Forbidden.', 403); }
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql = 'SELECT id, decision_id, conversation_id, message_id, notification_type, channel, status, payload, created_at, sent_at, read_at FROM harpp_notifications WHERE user_id = :user';
        $params = [':user' => (int)$actor['id']];
        $sql .= " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.in_app_visible')), 'true') <> 'false' AND NOT (notification_type = 'message' AND message_id IS NOT NULL AND EXISTS (SELECT 1 FROM harpp_messages hm WHERE hm.id = harpp_notifications.message_id AND hm.sender_type IN ('harness', 'system')))";
        $includeRead = array_key_exists('include_read', $filters) && filter_var($filters['include_read'], FILTER_VALIDATE_BOOLEAN);
        $unreadOnly = !$includeRead || (array_key_exists('unread', $filters) && filter_var($filters['unread'], FILTER_VALIDATE_BOOLEAN));
        if ($unreadOnly) { $sql .= ' AND read_at IS NULL'; }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->db()->prepare($sql); $stmt->execute($params);
        return HarppServiceResult::success(['notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset]);
    }

    public function markRead(array $actor, int $notificationId, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId) || $notificationId <= 0) { return HarppServiceResult::failure('Forbidden or invalid notification.', 403); }
        $stmt = $this->db()->prepare('UPDATE harpp_notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :user');
        $stmt->execute([':id' => $notificationId, ':user' => (int)$actor['id']]);
        return $stmt->rowCount() > 0 ? HarppServiceResult::success(['notification_id' => $notificationId]) : HarppServiceResult::failure('Notification not found.', 404);
    }

    public function unreadCount(array $actor, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId)) { return HarppServiceResult::failure('Forbidden.', 403); }
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM harpp_notifications WHERE user_id = :user AND read_at IS NULL AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.in_app_visible')), 'true') <> 'false' AND NOT (notification_type = 'message' AND message_id IS NOT NULL AND EXISTS (SELECT 1 FROM harpp_messages hm WHERE hm.id = harpp_notifications.message_id AND hm.sender_type IN ('harness', 'system')))");
        $stmt->execute([':user' => (int)$actor['id']]);
        return HarppServiceResult::success(['unread' => (int)$stmt->fetchColumn()]);
    }

    public function delete(array $actor, int $notificationId, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId) || $notificationId <= 0) { return HarppServiceResult::failure('Forbidden or invalid notification.', 403); }
        try {
            $this->db()->beginTransaction();
            $stmt = $this->db()->prepare('SELECT id FROM harpp_notifications WHERE id = :id AND user_id = :user FOR UPDATE');
            $stmt->execute([':id' => $notificationId, ':user' => (int)$actor['id']]);
            if ($stmt->fetchColumn() === false) { $this->db()->rollBack(); return HarppServiceResult::failure('Notification not found.', 404); }
            $this->db()->prepare('DELETE FROM harpp_notifications WHERE id = :id AND user_id = :user')->execute([':id' => $notificationId, ':user' => (int)$actor['id']]);
            $event = $this->effects('harpp.notification.deleted', 'notification.deleted', $notificationId, (int)$actor['id'], null, ['deleted' => true]);
            $this->db()->commit();
            return HarppServiceResult::success(['notification_id' => $notificationId, 'deleted' => true], 'Notification deleted.', [$event], 'harpp_notification', $notificationId);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('notification delete failed', $e);
            return HarppServiceResult::failure('Unable to delete notification.', 500);
        }
    }

    public function deleteAllMessages(array $actor, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId)) { return HarppServiceResult::failure('Forbidden.', 403); }
        try {
            $this->db()->beginTransaction();
            $s = $this->db()->prepare("DELETE FROM harpp_notifications WHERE user_id = :user AND notification_type = 'message' AND read_at IS NOT NULL");
            $s->execute([':user' => (int)$actor['id']]);
            $deleted = $s->rowCount();
            (new HarppFoundationService($this->db()))->recordEffect('harpp.notifications.messages_deleted','notifications.messages_deleted',$actor,'harpp_notification_collection',(int)$actor['id'],null,['deleted'=>$deleted,'scope'=>'read']);
            if (function_exists('write_log')) { \write_log('HARPP audit', 'HARPP', ['module' => 'harpp', 'action' => 'notifications.messages_deleted', 'actor_user_id' => (int)$actor['id'], 'deleted' => $deleted, 'scope' => 'read']); }
            $this->db()->commit();
            return HarppServiceResult::success(['deleted' => $deleted], 'All read message notifications deleted.');
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) { $this->db()->rollBack(); }
            $this->log('notification messages delete failed', $e);
            return HarppServiceResult::failure('Unable to delete message notifications.', 500);
        }
    }

    private function effects(string $event, string $action, int $id, int $userId, ?array $before, array $after): array
    {
        return (new HarppFoundationService($this->db()))->recordEffect($event,$action,['id'=>$userId,'source'=>'system'],'harpp_notification',$id,$before,$after);
    }

    private function canonical(array $value): array
    {
        if(!array_is_list($value))ksort($value);foreach($value as$key=>$child)if(is_array($child))$value[$key]=$this->canonical($child);return$value;
    }

    /** Add a concise subject and preview for the service worker's visible notification. */
    private function presentablePayload(string $type, array $payload, ?int $decisionId, ?int $conversationId, ?int $messageId): array
    {
        if ($type === 'decision' && $decisionId !== null && trim((string)($payload['title'] ?? '')) === '') {
            $stmt = $this->db()->prepare('SELECT title FROM harpp_decisions WHERE id = :id');
            $stmt->execute([':id' => $decisionId]);
            $payload['title'] = trim((string)($stmt->fetchColumn() ?: 'Decision requires review'));
        }
        if ($type === 'message' && $conversationId !== null) {
            $stmt = $this->db()->prepare('SELECT title FROM harpp_conversations WHERE id = :id');
            $stmt->execute([':id' => $conversationId]);
            $subject = trim((string)($stmt->fetchColumn() ?: 'HARPP message'));
            $payload['title'] = $subject;
            $payload['subject'] = $subject;
            if ($messageId !== null && trim((string)($payload['body'] ?? '')) === '') {
                $message = $this->db()->prepare('SELECT body FROM harpp_messages WHERE id = :id AND conversation_id = :conversation');
                $message->execute([':id' => $messageId, ':conversation' => $conversationId]);
                $payload['body'] = $this->preview((string)($message->fetchColumn() ?: 'Open HARPP to read the message.'));
            }
        }
        if ($type === 'system') {
            $status = strtolower(trim((string)($payload['status'] ?? '')));
            $payload['title'] ??= match (true) {
                str_contains($status, 'block'), str_contains($status, 'fail'), str_contains($status, 'decision') => 'HARPP — Action required',
                str_contains($status, 'done'), str_contains($status, 'complete'), str_contains($status, 'pass') => 'HARPP — Work complete',
                default => 'HARPP — Update',
            };
            if (trim((string)($payload['body'] ?? '')) === '' && trim((string)($payload['message'] ?? '')) !== '') {
                $payload['body'] = $this->preview((string)$payload['message']);
            }
        }
        return $payload;
    }

    /** Exact OS notification data; the service worker must never guess from an unread row. */
    private function pushPayload(array $notice, array $payload): array
    {
        $type = (string)($notice['notification_type'] ?? 'system');
        $conversationId = (int)($notice['conversation_id'] ?? 0);
        $decisionId = (int)($notice['decision_id'] ?? 0);
        $subject = trim((string)($payload['subject'] ?? $payload['title'] ?? ''));
        $body = $this->preview((string)($payload['body'] ?? $payload['message'] ?? $payload['event'] ?? 'Open HARPP for details.'));
        if ($subject === '') $subject = $type === 'decision' ? 'HARPP — Action required' : 'HARPP — Update';
        return [
            'id' => (int)$notice['id'],
            'notification_id' => (int)$notice['id'],
            'notification_type' => $type,
            'subject' => $subject,
            'title' => $subject,
            'body' => $body,
            'conversation_id' => $conversationId ?: null,
            'decision_id' => $decisionId ?: null,
            'message_id' => (int)($notice['message_id'] ?? 0) ?: null,
            'url' => $decisionId > 0 ? '/harpp/decisions/' . $decisionId : ($conversationId > 0 ? '/harpp?conversation=' . $conversationId : '/harpp/notifications'),
            'tag' => $conversationId > 0 ? 'harpp-conversation-' . $conversationId : ($decisionId > 0 ? 'harpp-decision-' . $decisionId : 'harpp-notification-' . (int)$notice['id']),
            'urgency' => $type === 'decision' ? 'high' : 'normal',
            'ttl' => $type === 'decision' ? 86400 : 14400,
            'created_at' => (string)($notice['created_at'] ?? ''),
        ];
    }

    private function preview(string $value): string
    {
        $value = trim((string)preg_replace('/\s+/', ' ', strip_tags($value)));
        return strlen($value) > 180 ? substr($value, 0, 177) . '...' : $value;
    }

    private function typeEnabled(string $type): bool
    {
        if ($type === 'system') return true;
        $key = $type === 'decision' ? 'notify_decisions' : 'notify_messages';
        return $this->setting($key, '1') === '1';
    }
    private function channelEnabled(string $channel): bool
    {
        if ($channel !== 'push' || $this->setting('push_enabled', '1') !== '1') return false;
        $channels = array_filter(array_map('trim', explode(',', strtolower($this->setting('notification_channels', 'push')))));
        return in_array('push', $channels, true);
    }
    private function setting(string $key, string $default): string
    {
        $stmt = $this->db()->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }
    private function access(array $actor, ?int $tenantId): bool
    {
        $current = (int)(\app()->tenant()->current() ?? 0);
        return $current > 0 && ($tenantId === null || $tenantId === $current) && (int)($actor['id'] ?? 0) > 0 && ($actor['source'] ?? 'harpp') === 'harpp' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin', 'member'], true);
    }
    private function log(string $message, Throwable $e): void { if (function_exists('write_log')) { \write_log('HARPP ' . $message, 'error', ['module' => 'harpp', 'error' => $e->getMessage()]); } }
}
