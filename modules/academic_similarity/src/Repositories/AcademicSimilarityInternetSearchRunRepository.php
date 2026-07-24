<?php
declare(strict_types=1);

class AcademicSimilarityInternetSearchRunRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function create(int $submissionId, int $institutionId, string $provider, string $payloadPolicy, array $metadata = []): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ac_similarity_internet_search_runs
                (tenant_id, submission_id, institution_id, provider, status, payload_policy, metadata_json)
             VALUES
                (:tid, :sid, :iid, :provider, 'pending', :policy, :meta)"
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => $submissionId,
            ':iid' => $institutionId,
            ':provider' => $provider,
            ':policy' => $payloadPolicy,
            ':meta' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateSummary(int $id, string $status, int $queryCount, int $candidateCount, int $importedCount, string $disclosure = '', string $error = ''): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ac_similarity_internet_search_runs
             SET status = :status,
                 query_count = :qcount,
                 candidate_count = :ccount,
                 imported_count = :icount,
                 disclosure = :disclosure,
                 error_message = :error,
                 completed_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([
            ':status' => $status,
            ':qcount' => $queryCount,
            ':ccount' => $candidateCount,
            ':icount' => $importedCount,
            ':disclosure' => $disclosure,
            ':error' => $error,
            ':id' => $id,
            ':tid' => $this->tenantId,
        ]);
    }

    public function latestForSubmission(int $submissionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ac_similarity_internet_search_runs
             WHERE tenant_id = :tid AND submission_id = :sid
             ORDER BY started_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Check if there is a pending (in-progress) run for a submission.
     */
    public function hasPendingRun(int $submissionId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ac_similarity_internet_search_runs
             WHERE tenant_id = :tid AND submission_id = :sid
               AND status = 'pending'
               AND completed_at IS NULL"
        );
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
