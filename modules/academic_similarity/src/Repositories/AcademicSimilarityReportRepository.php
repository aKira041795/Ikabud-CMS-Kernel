<?php
declare(strict_types=1);

class AcademicSimilarityReportRepository
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_reports WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySubmissionId(int $submissionId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ac_similarity_reports WHERE submission_id = :sid AND tenant_id = :tid ORDER BY generated_at DESC LIMIT 1");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO ac_similarity_reports (tenant_id, submission_id, report_version, match_engine_version, semantic_model_version, raw_score, adjusted_score, total_matches, total_excluded, matched_word_count, total_eligible_words, exclusion_word_deduction, report_checksum, report_format, report_data_json) VALUES (:tid, :sid, :rver, :mever, :smver, :raw, :adj, :tm, :te, :mw, :tew, :ewd, :rcsum, :rfmt, :rdjson)");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => (int)($data['submission_id'] ?? 0),
            ':rver' => $data['report_version'] ?? '1.0.0',
            ':mever' => $data['match_engine_version'] ?? '1.0.0',
            ':smver' => $data['semantic_model_version'] ?? null,
            ':raw' => isset($data['raw_score']) ? (float)$data['raw_score'] : null,
            ':adj' => isset($data['adjusted_score']) ? (float)$data['adjusted_score'] : null,
            ':tm' => (int)($data['total_matches'] ?? 0),
            ':te' => (int)($data['total_excluded'] ?? 0),
            ':mw' => (int)($data['matched_word_count'] ?? 0),
            ':tew' => (int)($data['total_eligible_words'] ?? 0),
            ':ewd' => (int)($data['exclusion_word_deduction'] ?? 0),
            ':rcsum' => $data['report_checksum'] ?? '',
            ':rfmt' => $data['report_format'] ?? 'html',
            ':rdjson' => isset($data['report_data_json']) ? (is_string($data['report_data_json']) ? $data['report_data_json'] : json_encode($data['report_data_json'])) : null,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
