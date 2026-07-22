<?php
declare(strict_types=1);

class AcademicSimilarityCollectionRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_collections WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function search(string $search = '', int $page = 1, int $perPage = 50): array {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($search !== '') {
            $conditions[] = '(name LIKE :search OR description LIKE :search2)';
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        $where = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_collections WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(string $search = ''): int {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($search !== '') {
            $conditions[] = '(name LIKE :search OR description LIKE :search2)';
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ac_similarity_collections WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function create(string $name, string $description, int $institutionId): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_collections (tenant_id, institution_id, name, description) VALUES (:tid, :iid, :name, :desc)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => $institutionId,
            ':name' => $name,
            ':desc' => $description,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM ac_similarity_collections WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
    }
}
