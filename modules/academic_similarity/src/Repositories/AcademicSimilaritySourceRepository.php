<?php
declare(strict_types=1);

class AcademicSimilaritySourceRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_sources WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function search(string $search = '', int $page = 1, int $perPage = 50): array {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($search !== '') {
            $conditions[] = '(title LIKE :search OR author LIKE :search2 OR original_filename LIKE :search3)';
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $where = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_sources WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(string $search = ''): int {
        $conditions = ['tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if ($search !== '') {
            $conditions[] = '(title LIKE :search OR author LIKE :search2 OR original_filename LIKE :search3)';
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ac_similarity_sources WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_sources (tenant_id, institution_id, collection_id, title, author, source_type, classification, original_filename, storage_path, storage_name, mime_type, file_size_bytes, word_count, page_count, checksum_sha256, text_hash_sha256, metadata_json) VALUES (:tid, :iid, :cid, :title, :author, :src_type, :class, :filename, :stg_path, :stg_name, :mime, :size, :wcount, :pcount, :csum, :thash, :meta)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':iid' => (int)($data['institution_id'] ?? 0),
            ':cid' => isset($data['collection_id']) && $data['collection_id'] > 0 ? (int)$data['collection_id'] : null,
            ':title' => $data['title'] ?? '',
            ':author' => $data['author'] ?? '',
            ':src_type' => $data['source_type'] ?? 'upload',
            ':class' => $data['classification'] ?? 'published',
            ':filename' => $data['original_filename'] ?? '',
            ':stg_path' => $data['storage_path'] ?? '',
            ':stg_name' => $data['storage_name'] ?? '',
            ':mime' => $data['mime_type'] ?? '',
            ':size' => (int)($data['file_size_bytes'] ?? 0),
            ':wcount' => (int)($data['word_count'] ?? 0),
            ':pcount' => (int)($data['page_count'] ?? 0),
            ':csum' => $data['checksum_sha256'] ?? '',
            ':thash' => $data['text_hash_sha256'] ?? '',
            ':meta' => isset($data['metadata_json']) ? (is_string($data['metadata_json']) ? $data['metadata_json'] : json_encode($data['metadata_json'])) : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateIndexStatus(int $id, string $status, string $error = ''): void {
        $stmt = $this->db->prepare("UPDATE ac_similarity_sources SET indexing_status = :status, indexing_error = :err, is_indexed = IF(:status_indexed = 'indexed', 1, 0), indexed_at = IF(:status_finished IN ('indexed','failed'), NOW(), indexed_at) WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([
            ':status' => $status,
            ':status_indexed' => $status,
            ':status_finished' => $status,
            ':err' => $error,
            ':id' => $id,
            ':tid' => $this->tenantId,
        ]);
    }

    public function delete(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM ac_similarity_sources WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
    }

    public function findByChecksum(string $checksum): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_sources WHERE checksum_sha256 = :csum AND tenant_id = :tid LIMIT 1");
        $stmt->execute([':csum' => $checksum, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
