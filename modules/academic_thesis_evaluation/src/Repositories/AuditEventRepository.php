<?php
declare(strict_types=1);

class AuditEventRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function record(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_audit_events (tenant_id, case_id, actor_id, actor_role, action, before_state, after_state, reason, request_id, created_at)
            VALUES (:tid, :case_id, :actor_id, :actor_role, :action, :before, :after, :reason, :request_id, NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => $data['case_id'] ?? null,
            ':actor_id' => (int)$data['actor_id'],
            ':actor_role' => $data['actor_role'] ?? null,
            ':action' => $data['action'],
            ':before' => isset($data['before_state']) ? json_encode($data['before_state']) : null,
            ':after' => isset($data['after_state']) ? json_encode($data['after_state']) : null,
            ':reason' => $data['reason'] ?? null,
            ':request_id' => $data['request_id'] ?? null,
        ]);
    }

    public function findByCase(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_audit_events WHERE case_id = :cid AND tenant_id = :tid ORDER BY created_at DESC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
