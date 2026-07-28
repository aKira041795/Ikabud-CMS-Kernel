<?php
declare(strict_types=1);

class EvaluationProfileRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_evaluation_profiles WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_evaluation_profiles WHERE code = :code AND tenant_id = :tid AND status = 'active' ORDER BY version DESC LIMIT 1"
        );
        $stmt->execute([':code' => $code, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function all(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_evaluation_profiles WHERE tenant_id = :tid ORDER BY code, version DESC"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function allActive(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_evaluation_profiles WHERE tenant_id = :tid AND status = 'active' ORDER BY code"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_evaluation_profiles (tenant_id, code, name, degree_level, version, description, status, workflow_definition, rubric_definition, policy_reference, created_by, created_at, updated_at)
            VALUES (:tid, :code, :name, :degree_level, :version, :description, :status, :workflow_definition, :rubric_definition, :policy_reference, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':code' => $data['code'],
            ':name' => $data['name'],
            ':degree_level' => $data['degree_level'] ?? '',
            ':version' => $data['version'] ?? '1.0',
            ':description' => $data['description'] ?? '',
            ':status' => $data['status'] ?? 'active',
            ':workflow_definition' => $data['workflow_definition'] ?? null,
            ':rubric_definition' => $data['rubric_definition'] ?? null,
            ':policy_reference' => $data['policy_reference'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
