<?php
declare(strict_types=1);

class AcademicSimilarityProcessingJobRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_processing_jobs WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findPending(string $jobType = ''): array {
        $conditions = ['tenant_id = :tid', 'status = :status'];
        $params = [':tid' => $this->tenantId, ':status' => 'pending'];
        if ($jobType !== '') {
            $conditions[] = 'job_type = :jtype';
            $params[':jtype'] = $jobType;
        }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_processing_jobs WHERE {$where} ORDER BY priority DESC, created_at ASC");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_processing_jobs (tenant_id, submission_id, job_type, status, priority, idempotency_key, retry_count, retry_max) VALUES (:tid, :sid, :jtype, :status, :prio, :ikey, :rcount, :rmax)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => (int)($data['submission_id'] ?? 0),
            ':jtype' => $data['job_type'] ?? '',
            ':status' => $data['status'] ?? 'pending',
            ':prio' => (int)($data['priority'] ?? 0),
            ':ikey' => $data['idempotency_key'] ?? '',
            ':rcount' => (int)($data['retry_count'] ?? 0),
            ':rmax' => (int)($data['retry_max'] ?? 3),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, string $failureReason = ''): void {
        $stmt = $this->db->prepare("UPDATE ac_similarity_processing_jobs SET status = :status, failure_reason = :reason, started_at = IF(:status_started = 'running' AND started_at IS NULL, NOW(), started_at), completed_at = IF(:status_completed IN ('completed','failed','skipped'), NOW(), completed_at) WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':status' => $status, ':status_started' => $status, ':status_completed' => $status, ':reason' => $failureReason, ':id' => $id, ':tid' => $this->tenantId]);
    }

    public function findBySubmissionId(int $submissionId): array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_processing_jobs WHERE submission_id = :sid AND tenant_id = :tid ORDER BY created_at ASC");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
