<?php
declare(strict_types=1);

class ManuscriptVersionRepository
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
            "SELECT * FROM ate_manuscript_versions WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_manuscript_versions WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY version_number ASC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLatestVersionNumber(int $caseId): int
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(version_number) FROM ate_manuscript_versions WHERE evaluation_case_id = :cid AND tenant_id = :tid"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_manuscript_versions (tenant_id, evaluation_case_id, version_number, file_reference, file_hash, word_count, submitted_by, submission_note, is_revision, created_at)
            VALUES (:tid, :case_id, :version_number, :file_reference, :file_hash, :word_count, :submitted_by, :submission_note, :is_revision, NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':version_number' => (int)$data['version_number'],
            ':file_reference' => $data['file_reference'],
            ':file_hash' => $data['file_hash'] ?? '',
            ':word_count' => $data['word_count'] ?? null,
            ':submitted_by' => (int)$data['submitted_by'],
            ':submission_note' => $data['submission_note'] ?? null,
            ':is_revision' => (int)($data['is_revision'] ?? 0),
        ]);
        return (int)$this->db->lastInsertId();
    }
}
