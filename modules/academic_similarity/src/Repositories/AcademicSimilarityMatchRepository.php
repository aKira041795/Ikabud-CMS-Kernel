<?php
declare(strict_types=1);

class AcademicSimilarityMatchRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = academic_similarity_resolve_tenant_id($tenantId);
        $this->db = academic_similarity_db($tenantId);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_matches WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySubmissionId(int $submissionId): array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_matches WHERE submission_id = :sid AND tenant_id = :tid ORDER BY match_confidence DESC, matched_word_count DESC");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findExcluded(int $submissionId): array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_matches WHERE submission_id = :sid AND tenant_id = :tid AND is_excluded = 1 ORDER BY excluded_at DESC");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findActive(int $submissionId): array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_matches WHERE submission_id = :sid AND tenant_id = :tid AND is_excluded = 0 ORDER BY match_confidence DESC, matched_word_count DESC");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_matches (tenant_id, submission_id, source_id, match_type, match_confidence, submission_segment_id, source_segment_id, matched_word_count, submission_word_range_start, submission_word_range_end, source_word_range_start, source_word_range_end, segment_match_count) VALUES (:tid, :sid, :src_id, :mtype, :conf, :ssid, :src_seg_id, :mwcount, :sw_start, :sw_end, :srcw_start, :srcw_end, :seg_count)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => (int)($data['submission_id'] ?? 0),
            ':src_id' => (int)($data['source_id'] ?? 0),
            ':mtype' => $data['match_type'] ?? 'exact',
            ':conf' => (float)($data['match_confidence'] ?? 1.0),
            ':ssid' => isset($data['submission_segment_id']) && $data['submission_segment_id'] > 0 ? (int)$data['submission_segment_id'] : null,
            ':src_seg_id' => isset($data['source_segment_id']) && $data['source_segment_id'] > 0 ? (int)$data['source_segment_id'] : null,
            ':mwcount' => (int)($data['matched_word_count'] ?? 0),
            ':sw_start' => (int)($data['submission_word_range_start'] ?? 0),
            ':sw_end' => (int)($data['submission_word_range_end'] ?? 0),
            ':srcw_start' => (int)($data['source_word_range_start'] ?? 0),
            ':srcw_end' => (int)($data['source_word_range_end'] ?? 0),
            ':seg_count' => (int)($data['segment_match_count'] ?? 0),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function createEvidence(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_match_evidence (tenant_id, match_id, submission_segment_text, source_segment_text, submission_start_offset, submission_end_offset, source_start_offset, source_end_offset, overlap_resolution_order) VALUES (:tid, :mid, :sst, :srcst, :sso, :seo, :srcso, :srceo, :oro)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':mid' => (int)($data['match_id'] ?? 0),
            ':sst' => $data['submission_segment_text'] ?? '',
            ':srcst' => $data['source_segment_text'] ?? '',
            ':sso' => (int)($data['submission_start_offset'] ?? 0),
            ':seo' => (int)($data['submission_end_offset'] ?? 0),
            ':srcso' => (int)($data['source_start_offset'] ?? 0),
            ':srceo' => (int)($data['source_end_offset'] ?? 0),
            ':oro' => (int)($data['overlap_resolution_order'] ?? 0),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getEvidence(int $matchId): array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_match_evidence WHERE match_id = :mid AND tenant_id = :tid ORDER BY overlap_resolution_order ASC");
        $stmt->execute([':mid' => $matchId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
