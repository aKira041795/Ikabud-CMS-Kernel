<?php
declare(strict_types=1);

class EvaluationCaseRepository
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
        $stmt = $this->db->prepare("
            SELECT c.*, p.code AS profile_code, p.name AS profile_name, p.degree_level
            FROM ate_evaluation_cases c
            JOIN ate_evaluation_profiles p ON c.profile_id = p.id
            WHERE c.id = :id AND c.tenant_id = :tid
        ");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByOwner(int $ownerId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, p.code AS profile_code, p.name AS profile_name
            FROM ate_evaluation_cases c
            JOIN ate_evaluation_profiles p ON c.profile_id = p.id
            WHERE c.submission_owner_id = :owner AND c.tenant_id = :tid
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([':owner' => $ownerId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['c.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['stage'])) {
            $where[] = 'c.current_stage = :stage';
            $params[':stage'] = $filters['stage'];
        }
        if (!empty($filters['profile_id'])) {
            $where[] = 'c.profile_id = :profile_id';
            $params[':profile_id'] = (int)$filters['profile_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.title LIKE :search OR c.student_number LIKE :search2)';
            $params[':search'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT c.*, p.code AS profile_code, p.name AS profile_name
                FROM ate_evaluation_cases c
                JOIN ate_evaluation_profiles p ON c.profile_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.updated_at DESC
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(array $filters): int
    {
        $where = ['c.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['stage'])) {
            $where[] = 'c.current_stage = :stage';
            $params[':stage'] = $filters['stage'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.title LIKE :search OR c.student_number LIKE :search2)';
            $params[':search'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $sql = "SELECT COUNT(*) FROM ate_evaluation_cases c
                JOIN ate_evaluation_profiles p ON c.profile_id = p.id
                WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_evaluation_cases (tenant_id, profile_id, submission_owner_id, student_number, program_id, title, research_category, thesis_type, current_stage, status, adviser_id, panel_chair_id, ethics_approval_ref, submitted_at, created_at, updated_at)
            VALUES (:tid, :profile_id, :submission_owner_id, :student_number, :program_id, :title, :research_category, :thesis_type, :current_stage, :status, :adviser_id, :panel_chair_id, :ethics_approval_ref, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':profile_id' => (int)$data['profile_id'],
            ':submission_owner_id' => (int)$data['submission_owner_id'],
            ':student_number' => $data['student_number'] ?? null,
            ':program_id' => $data['program_id'] ?? null,
            ':title' => $data['title'],
            ':research_category' => $data['research_category'] ?? null,
            ':thesis_type' => $data['thesis_type'] ?? null,
            ':current_stage' => $data['current_stage'] ?? 'submission',
            ':status' => $data['status'] ?? 'submitted',
            ':adviser_id' => $data['adviser_id'] ?? null,
            ':panel_chair_id' => $data['panel_chair_id'] ?? null,
            ':ethics_approval_ref' => $data['ethics_approval_ref'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStage(int $caseId, string $stage, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_evaluation_cases SET current_stage = :stage, status = :status, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([':stage' => $stage, ':status' => $status, ':id' => $caseId, ':tid' => $this->tenantId]);
    }

    public function setActiveManuscript(int $caseId, int $versionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_evaluation_cases SET active_manuscript_version_id = :vid, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([':vid' => $versionId, ':id' => $caseId, ':tid' => $this->tenantId]);
    }

    public function dashboardStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) AS in_review,
                SUM(CASE WHEN status = 'revision' THEN 1 ELSE 0 END) AS in_revision,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn
            FROM ate_evaluation_cases
            WHERE tenant_id = :tid
        ");
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
