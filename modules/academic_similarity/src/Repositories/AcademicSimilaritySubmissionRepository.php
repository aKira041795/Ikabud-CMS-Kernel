<?php
declare(strict_types=1);

class AcademicSimilaritySubmissionRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_submissions WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function search(int $institutionId = 0, string $status = '', string $search = '', int $page = 1, int $perPage = 25): array {
        $conditions = ['s.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($institutionId > 0) { $conditions[] = 's.institution_id = :iid'; $params[':iid'] = $institutionId; }
        if ($status !== '') { $conditions[] = 's.status = :status'; $params[':status'] = $status; }
        if ($search !== '') { $conditions[] = '(s.submission_title LIKE :search OR s.author_name LIKE :search2)'; $params[':search'] = "%{$search}%"; $params[':search2'] = "%{$search}%"; }
        $where = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT s.* FROM ac_similarity_submissions s WHERE {$where} ORDER BY s.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(int $institutionId = 0, string $status = '', string $search = ''): int {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($institutionId > 0) { $conditions[] = 'institution_id = :iid'; $params[':iid'] = $institutionId; }
        if ($status !== '') { $conditions[] = 'status = :status'; $params[':status'] = $status; }
        if ($search !== '') { $conditions[] = '(submission_title LIKE :search OR author_name LIKE :search2)'; $params[':search'] = "%{$search}%"; $params[':search2'] = "%{$search}%"; }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ac_similarity_submissions WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_submissions (tenant_id, institution_id, submission_title, author_name, author_identifier, source_type, original_filename, storage_path, storage_name, mime_type, file_size_bytes, word_count, page_count, checksum_sha256, text_hash_sha256, idempotency_key) VALUES (:tid, :iid, :title, :author, :author_id, :src_type, :filename, :stg_path, :stg_name, :mime, :size, :wcount, :pcount, :csum, :thash, :ikey)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => (int)($data['institution_id'] ?? 0),
            ':title' => $data['submission_title'] ?? '',
            ':author' => $data['author_name'] ?? '',
            ':author_id' => $data['author_identifier'] ?? '',
            ':src_type' => $data['source_type'] ?? 'upload',
            ':filename' => $data['original_filename'] ?? '',
            ':stg_path' => $data['storage_path'] ?? '',
            ':stg_name' => $data['storage_name'] ?? '',
            ':mime' => $data['mime_type'] ?? '',
            ':size' => (int)($data['file_size_bytes'] ?? 0),
            ':wcount' => (int)($data['word_count'] ?? 0),
            ':pcount' => (int)($data['page_count'] ?? 0),
            ':csum' => $data['checksum_sha256'] ?? '',
            ':thash' => $data['text_hash_sha256'] ?? '',
            ':ikey' => $data['idempotency_key'] ?? '',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, string $error = ''): void {
        $stmt = $this->db->prepare("UPDATE ac_similarity_submissions SET status = :status, processing_error = :err, processed_at = IF(:status_processed IN ('processed','failed'), NOW(), processed_at) WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([
            ':status' => $status,
            ':status_processed' => $status,
            ':err' => $error,
            ':id' => $id,
            ':tid' => $this->tenantId,
        ]);
    }

    public function updateScore(int $id, float $rawScore, float $adjustedScore, int $matchedWords, int $totalEligible): void {
        $stmt = $this->db->prepare("UPDATE ac_similarity_submissions SET raw_similarity_score = :raw, adjusted_similarity_score = :adj, matched_word_count = :mw, total_eligible_words = :tew WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':raw' => $rawScore, ':adj' => $adjustedScore, ':mw' => $matchedWords, ':tew' => $totalEligible, ':id' => $id, ':tid' => $this->tenantId]);
    }

    public function delete(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM ac_similarity_submissions WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
    }
}
