<?php
declare(strict_types=1);

class AcademicSimilarityInternetSourceRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function createCandidate(int $runId, int $submissionId, array $candidate): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ac_similarity_internet_sources
                (tenant_id, search_run_id, submission_id, provider, query_text, result_rank,
                 source_url, title, author, publisher, snippet, retrieval_status, metadata_json)
             VALUES
                (:tid, :rid, :sid, :provider, :query, :rank,
                 :url, :title, :author, :publisher, :snippet, 'candidate', :meta)"
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':rid' => $runId,
            ':sid' => $submissionId,
            ':provider' => (string)($candidate['provider'] ?? ''),
            ':query' => (string)($candidate['query'] ?? ''),
            ':rank' => (int)($candidate['rank'] ?? 0),
            ':url' => (string)($candidate['url'] ?? ''),
            ':title' => (string)($candidate['title'] ?? ''),
            ':author' => (string)($candidate['author'] ?? ''),
            ':publisher' => (string)($candidate['publisher'] ?? ''),
            ':snippet' => (string)($candidate['snippet'] ?? ''),
            ':meta' => json_encode($candidate['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function markRetrieved(int $id, string $text, int $chars): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ac_similarity_internet_sources
             SET retrieval_status = 'retrieved',
                 retrieved_text_hash = :hash,
                 retrieved_chars = :chars,
                 retrieved_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([
            ':hash' => hash('sha256', $text),
            ':chars' => $chars,
            ':id' => $id,
            ':tid' => $this->tenantId,
        ]);
    }

    public function markImported(int $id, int $sourceId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ac_similarity_internet_sources
             SET retrieval_status = 'imported',
                 source_id = :source_id,
                 retrieved_at = IF(retrieved_at IS NULL, NOW(), retrieved_at)
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':source_id' => $sourceId, ':id' => $id, ':tid' => $this->tenantId]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ac_similarity_internet_sources
             SET retrieval_status = 'failed',
                 retrieval_error = :error,
                 retrieved_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':error' => $error, ':id' => $id, ':tid' => $this->tenantId]);
    }

    public function findBySubmission(int $submissionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ac_similarity_internet_sources
             WHERE tenant_id = :tid AND submission_id = :sid
             ORDER BY search_run_id DESC, result_rank ASC, id ASC"
        );
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findBySourceIds(array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
        if ($sourceIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [':tid' => $this->tenantId];
        foreach ($sourceIds as $idx => $sourceId) {
            $key = ':sid' . $idx;
            $placeholders[] = $key;
            $params[$key] = $sourceId;
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM ac_similarity_internet_sources
             WHERE tenant_id = :tid AND source_id IN (" . implode(',', $placeholders) . ")"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(int)($row['source_id'] ?? 0)] = $row;
        }
        return $bySource;
    }
}
