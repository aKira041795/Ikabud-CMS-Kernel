<?php
declare(strict_types=1);

class RubricTemplateRepository
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
            "SELECT * FROM ate_rubric_templates WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_rubric_templates WHERE code = :code AND tenant_id = :tid AND status = 'active' ORDER BY version DESC LIMIT 1"
        );
        $stmt->execute([':code' => $code, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function all(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_rubric_templates WHERE tenant_id = :tid ORDER BY code"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getCriteria(int $templateId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_rubric_criteria WHERE rubric_template_id = :tid ORDER BY sort_order ASC"
        );
        $stmt->execute([':tid' => $templateId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_rubric_templates (tenant_id, code, name, version, degree_level, status, total_weight, created_by, created_at, updated_at)
            VALUES (:tid, :code, :name, :version, :degree_level, :status, :total_weight, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':code' => $data['code'],
            ':name' => $data['name'],
            ':version' => $data['version'] ?? '1.0',
            ':degree_level' => $data['degree_level'] ?? null,
            ':status' => $data['status'] ?? 'active',
            ':total_weight' => $data['total_weight'] ?? 100.00,
            ':created_by' => $data['created_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function createCriterion(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_rubric_criteria (rubric_template_id, parent_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
            VALUES (:template_id, :parent_id, :code, :label, :description, :weight, :score_min, :score_max, :required_comment_below, :sort_order)
        ");
        $stmt->execute([
            ':template_id' => (int)$data['rubric_template_id'],
            ':parent_id' => $data['parent_id'] ?? null,
            ':code' => $data['code'],
            ':label' => $data['label'],
            ':description' => $data['description'] ?? null,
            ':weight' => $data['weight'] ?? 0.00,
            ':score_min' => $data['score_min'] ?? 0.00,
            ':score_max' => $data['score_max'] ?? 100.00,
            ':required_comment_below' => $data['required_comment_below'] ?? null,
            ':sort_order' => $data['sort_order'] ?? 0,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
