<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;

final class HarppStatusService
{
    public function __construct(private ModuleDB $db) {}

    public function reportDaemonStatus(array $actor, array $input, int $tenantId): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runnerKey = trim((string)($input['runner_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{2,191}$/', $runnerKey)) return HarppServiceResult::failure('Valid runner_key is required.', 422);

        $workflowCounts = $input['workflow_counts'] ?? [];
        if (!is_array($workflowCounts) || count($workflowCounts) > 20) return HarppServiceResult::failure('Valid workflow_counts are required.', 422);
        foreach ($workflowCounts as $state => $count) {
            $count = (int)$count;
            if ($count < 0) return HarppServiceResult::failure('Workflow counts must be non-negative.', 422);
            $workflowCounts[$state] = $count;
        }

        $recentInput = $input['recent_workflows'] ?? [];
        if (!is_array($recentInput) || count($recentInput) > 10) return HarppServiceResult::failure('Valid recent_workflows are required.', 422);
        $recent = [];
        foreach ($recentInput as $item) {
            if (!is_array($item) || !array_key_exists('id', $item) || !array_key_exists('title', $item) || !array_key_exists('status', $item) || !array_key_exists('updated_at', $item)) {
                return HarppServiceResult::failure('Each recent workflow must include id, title, status, and updated_at.', 422);
            }
            $workflow = [
                'id' => trim((string)$item['id']),
                'title' => trim((string)$item['title']),
                'status' => trim((string)$item['status']),
                'updated_at' => trim((string)$item['updated_at']),
            ];
            if (strlen($workflow['id']) > 191 || strlen($workflow['title']) > 255 || strlen($workflow['status']) > 40 || strlen($workflow['updated_at']) > 32) {
                return HarppServiceResult::failure('Recent workflow fields exceed their maximum length.', 422);
            }
            $recent[] = $workflow;
        }

        $version = trim((string)($input['daemon_version'] ?? ''));
        $version = $version === '' ? null : substr($version, 0, 64);
        // Migration 018 must be applied to the tenant before reporting; degrade
        // with a clear code instead of a raw 500 (the daemon client treats any
        // failure as non-fatal and retries on the next throttle window).
        if (!$this->tableExists('harpp_daemon_status')) {
            return HarppServiceResult::failure('Daemon status table is not migrated; run tenant:migrate for harpp.', 503, 'daemon_status_unavailable');
        }
        $statement = $this->db->prepare('INSERT INTO harpp_daemon_status (runner_key,last_seen_at,daemon_version,workflow_counts_json,recent_workflows_json) VALUES (:key,NOW(6),:ver,:counts,:recent) ON DUPLICATE KEY UPDATE last_seen_at=NOW(6),daemon_version=VALUES(daemon_version),workflow_counts_json=VALUES(workflow_counts_json),recent_workflows_json=VALUES(recent_workflows_json)');
        $statement->execute([':key' => $runnerKey, ':ver' => $version, ':counts' => $this->json($workflowCounts), ':recent' => $this->json($recent)]);
        return HarppServiceResult::success(['runner_key' => $runnerKey, 'last_seen_at' => date('Y-m-d H:i:s')]);
    }

    public function overview(array $actor, int $tenantId): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $runnerResult = (new HarppRunService($this->db))->listRunnersForOwner($actor, $tenantId);
        $runners = (array)($runnerResult['data']['runners'] ?? []);

        $queueRows = $this->db->query('SELECT state, COUNT(*) c FROM harpp_work_runs GROUP BY state')->fetchAll(PDO::FETCH_ASSOC);
        $byState = [];
        $claimable = 0;
        $total = 0;
        foreach ($queueRows as $row) {
            $state = (string)$row['state'];
            $count = (int)$row['c'];
            $byState[$state] = $count;
            $total += $count;
            if (in_array($state, ['QUEUED', 'WAITING_FOR_RUNNER'], true)) $claimable += $count;
        }

        $recentRuns = $this->db->query('SELECT id,state,report_state,source_message_id,conversation_id,runner_key,last_status,created_at FROM harpp_work_runs ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
        $decisionResult = (new HarppDecisionService($this->db))->list($actor, ['limit' => 8], $tenantId);
        $recentDecisions = (array)($decisionResult['data']['decisions'] ?? []);
        // Daemon panel is optional: if migration 018 is not yet applied the rest
        // of the status page still works and daemon renders as unavailable.
        $daemon = null;
        if ($this->tableExists('harpp_daemon_status')) {
            $daemonRow = $this->db->query('SELECT runner_key,last_seen_at,daemon_version,workflow_counts_json,recent_workflows_json FROM harpp_daemon_status ORDER BY updated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($daemonRow)) {
                $lastSeen = strtotime((string)$daemonRow['last_seen_at']);
                $age = $lastSeen === false ? 0 : max(0, time() - $lastSeen);
                $daemon = [
                    'runner_key' => (string)$daemonRow['runner_key'],
                    'last_seen_at' => $daemonRow['last_seen_at'],
                    'daemon_version' => $daemonRow['daemon_version'],
                    'online' => $lastSeen !== false && $lastSeen > time() - 300,
                    'age_seconds' => $age,
                    'workflow_counts' => (array)json_decode((string)$daemonRow['workflow_counts_json'], true),
                    'recent_workflows' => (array)json_decode((string)$daemonRow['recent_workflows_json'], true),
                ];
            }
        }

        $recentMessages = $this->db->query('SELECT m.id,m.conversation_id,m.sender_type,m.body,m.created_at FROM harpp_messages m JOIN harpp_conversations c ON c.id=m.conversation_id ORDER BY m.id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
        $openConversations = (int)$this->db->query("SELECT COUNT(*) FROM harpp_conversations WHERE status='open'")->fetchColumn();
        return HarppServiceResult::success([
            'runners' => $runners,
            'run_queue' => ['by_state' => $byState, 'claimable' => $claimable, 'total' => $total],
            'recent_runs' => $recentRuns,
            'recent_decisions' => $recentDecisions,
            'daemon' => $daemon,
            'recent_messages' => $recentMessages,
            'conversations' => ['open' => $openConversations],
        ]);
    }

    private function ownerActor(array $actor): bool
    {
        return (($actor['source'] ?? 'harpp') === 'harpp' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true))
            || (($actor['source'] ?? '') === 'harpp_bridge' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true))
            || (($actor['source'] ?? '') === 'kernel' && ($actor['role'] ?? '') === 'superadmin');
    }

    /** MySQL 5.7-safe existence check (used to degrade gracefully when migration
     *  018 has not yet been applied to a tenant). */
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
}