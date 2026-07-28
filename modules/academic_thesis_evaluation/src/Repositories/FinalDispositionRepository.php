<?php
declare(strict_types=1);

class FinalDispositionRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function findByCaseId(int $caseId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_final_dispositions WHERE evaluation_case_id = :cid AND tenant_id = :tid"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_final_dispositions (tenant_id, evaluation_case_id, status, decision_summary, conditions, effective_date, decided_by, authority_role, created_at)
            VALUES (:tid, :case_id, :status, :summary, :conditions, :effective_date, :decided_by, :authority_role, NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':status' => $data['status'],
            ':summary' => $data['decision_summary'] ?? null,
            ':conditions' => $data['conditions'] ?? null,
            ':effective_date' => $data['effective_date'] ?? date('Y-m-d'),
            ':decided_by' => (int)$data['decided_by'],
            ':authority_role' => $data['authority_role'] ?? 'admin',
        ]);
        return (int)$this->db->lastInsertId();
    }
}
