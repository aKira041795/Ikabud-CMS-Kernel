<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;

/**
 * Durable conversation-aware memory read-model (chair-approved debate flywheel).
 *
 * Derives a bounded, per-conversation summary from the canonical server-authoritative
 * tables that recordEffect writes (harpp_messages + harpp_decisions + harpp_work_runs):
 * title, recent turns, active/latest run, and applicable durable decisions. It is
 * versioned by the conversation's latest message aggregate sequence, so a new message
 * advances the version and invalidates the bounded client context cache. Derivation is
 * read-only over tenant/workspace-scoped rows only — never secrets or cross-conversation
 * content — and every list is bounded with a token budget.
 */
final class HarppContextSummaryService
{
    private const SUMMARY_MESSAGE_LIMIT = 20;
    private const SUMMARY_DECISION_LIMIT = 8;
    private const SUMMARY_CHAR_BUDGET = 16000; // ~4k tokens of digest content
    private const APPLICABLE_DECISION_STATES = ['DECIDED', 'ACKNOWLEDGED', 'APPLIED', 'CLOSED'];

    public function __construct(private ModuleDB $db) {}

    /** Rebuild (if stale) and return the durable summary for a conversation, or null. */
    public function build(int $conversationId): ?array
    {
        $c = $this->db->prepare('SELECT id,title,version,status FROM harpp_conversations WHERE id=:id');
        $c->execute([':id' => $conversationId]);
        $conv = $c->fetch(PDO::FETCH_ASSOC);
        if (!is_array($conv)) return null;

        $m = $this->db->prepare(
            'SELECT sender_type,sender_user_id,body,aggregate_sequence,created_at FROM harpp_messages '
            . 'WHERE conversation_id=:id ORDER BY aggregate_sequence DESC,id DESC LIMIT ' . self::SUMMARY_MESSAGE_LIMIT
        );
        $m->execute([':id' => $conversationId]);
        $messages = array_reverse($m->fetchAll(PDO::FETCH_ASSOC));

        $version = 0;
        foreach ($messages as $msg) {
            $version = max($version, (int)$msg['aggregate_sequence']);
        }
        if ($version === 0) {
            $version = max(1, (int)$conv['version']);
        }

        $r = $this->db->prepare(
            "SELECT id,state,report_state,last_status,created_at,updated_at FROM harpp_work_runs "
            . 'WHERE conversation_id=:id ORDER BY id DESC LIMIT 1'
        );
        $r->execute([':id' => $conversationId]);
        $run = $r->fetch(PDO::FETCH_ASSOC);
        $run = is_array($run) ? $run : null;

        $d = $this->db->prepare(
            'SELECT id,decision_key,title,decision,lifecycle_state,created_at,decided_at,applied_at,closed_at '
            . 'FROM harpp_decisions WHERE conversation_id=? AND lifecycle_state IN ('
            . implode(',', array_fill(0, count(self::APPLICABLE_DECISION_STATES), '?'))
            . ') ORDER BY COALESCE(decided_at,applied_at,closed_at,created_at) DESC,id DESC LIMIT ' . self::SUMMARY_DECISION_LIMIT
        );
        $d->execute(array_merge([$conversationId], self::APPLICABLE_DECISION_STATES));
        $decisions = $d->fetchAll(PDO::FETCH_ASSOC);

        $current = $this->get($conversationId);
        if ($current !== null && (int)$current['version'] === $version && (int)$current['message_count'] === count($messages)) {
            return $current; // already fresh — avoid write churn on every read
        }

        $recent = [];
        foreach ($messages as $msg) {
            $recent[] = [
                'sender_type' => $msg['sender_type'],
                'body' => self::truncate((string)$msg['body'], 500),
            ];
        }
        $decisionsOut = [];
        foreach ($decisions as $dec) {
            $decisionsOut[] = [
                'decision_key' => $dec['decision_key'],
                'title' => self::truncate((string)$dec['title'], 200),
                'decision' => self::truncate((string)($dec['decision'] ?? ''), 300),
                'state' => $dec['lifecycle_state'],
            ];
        }

        $s = $this->db->prepare(
            'INSERT INTO harpp_context_summary (conversation_id,version,title,message_count,summary_json,decisions_json,active_run_json,token_budget,built_at) '
            . 'VALUES (:cid,:version,:title,:count,:summary,:decisions,:run,:budget,NOW(6)) '
            . 'ON DUPLICATE KEY UPDATE version=VALUES(version),title=VALUES(title),message_count=VALUES(message_count),'
            . 'summary_json=VALUES(summary_json),decisions_json=VALUES(decisions_json),active_run_json=VALUES(active_run_json),'
            . 'token_budget=VALUES(token_budget),built_at=NOW(6)'
        );
        $s->execute([
            ':cid' => $conversationId,
            ':version' => $version,
            ':title' => self::truncate((string)$conv['title'], 255),
            ':count' => count($messages),
            ':summary' => $this->json(['recent' => $recent, 'message_count' => count($messages)]),
            ':decisions' => $this->json($decisionsOut),
            ':run' => $run === null ? null : $this->json($run),
            ':budget' => self::SUMMARY_CHAR_BUDGET,
        ]);
        return $this->get($conversationId);
    }

    /** Return the stored bounded summary for a conversation, or null. */
    public function get(int $conversationId): ?array
    {
        $s = $this->db->prepare(
            'SELECT version,title,message_count,summary_json,decisions_json,active_run_json,token_budget FROM harpp_context_summary WHERE conversation_id=:id'
        );
        $s->execute([':id' => $conversationId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $summary = json_decode((string)$row['summary_json'], true);
        return [
            'version' => (int)$row['version'],
            'title' => (string)$row['title'],
            'message_count' => (int)$row['message_count'],
            'recent' => (array)($summary['recent'] ?? []),
            'decisions' => json_decode((string)$row['decisions_json'], true) ?: [],
            'active_run' => $row['active_run_json'] === null ? null : json_decode((string)$row['active_run_json'], true),
            'token_budget' => (int)$row['token_budget'],
        ];
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) return $value;
        return mb_substr($value, 0, max(0, $max - 1)) . '…';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
