<?php
declare(strict_types=1);

class AcademicSimilarityAssessmentRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = academic_similarity_resolve_tenant_id($tenantId);
        $this->db = academic_similarity_db($tenantId);
    }

    public function findRunByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ac_similarity_assessment_runs WHERE tenant_id = :tid AND idempotency_key = :key LIMIT 1');
        $stmt->execute([':tid' => $this->tenantId, ':key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findRunById(int $runId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ac_similarity_assessment_runs WHERE tenant_id = :tid AND id = :id LIMIT 1');
        $stmt->execute([':tid' => $this->tenantId, ':id' => $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findLatestRunBySubmissionId(int $submissionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ac_similarity_assessment_runs WHERE tenant_id = :tid AND submission_id = :sid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createRun(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ac_similarity_assessment_runs
             (tenant_id, submission_id, manuscript_hash, text_version_id, text_hash_sha256, extraction_version,
              assessment_version, search_provider, sanitized_queries_json, coverage_json, settings_json,
              thresholds_json, provider_versions_json, calibration_profile_json, payload_disclosures_json,
              maturity_json, limitations_json, status, idempotency_key)
             VALUES
             (:tid, :sid, :manuscript_hash, :text_version_id, :text_hash, :extraction_version,
              :assessment_version, :search_provider, :queries, :coverage, :settings,
              :thresholds, :provider_versions, :calibration, :payloads, :maturity, :limitations, :status, :idempotency_key)'
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => (int)$data['submission_id'],
            ':manuscript_hash' => (string)($data['manuscript_hash'] ?? ''),
            ':text_version_id' => $data['text_version_id'] ?? null,
            ':text_hash' => (string)($data['text_hash_sha256'] ?? ''),
            ':extraction_version' => (string)($data['extraction_version'] ?? 'deterministic-structure-v1'),
            ':assessment_version' => (string)($data['assessment_version'] ?? 'assessment-bundle-v1.1'),
            ':search_provider' => $data['search_provider'] ?? null,
            ':queries' => json_encode($data['sanitized_queries'] ?? []),
            ':coverage' => json_encode($data['coverage'] ?? []),
            ':settings' => json_encode($data['settings'] ?? []),
            ':thresholds' => json_encode($data['thresholds'] ?? []),
            ':provider_versions' => json_encode($data['provider_versions'] ?? []),
            ':calibration' => json_encode($data['calibration_profile'] ?? []),
            ':payloads' => json_encode($data['payload_disclosures'] ?? []),
            ':maturity' => json_encode($data['maturity'] ?? []),
            ':limitations' => json_encode($data['limitations'] ?? []),
            ':status' => (string)($data['status'] ?? 'completed_partial'),
            ':idempotency_key' => (string)$data['idempotency_key'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function replaceSections(int $runId, int $submissionId, array $sections): void
    {
        $this->db->execute('DELETE FROM ac_similarity_document_sections WHERE tenant_id = :tid AND assessment_run_id = :rid', [':tid' => $this->tenantId, ':rid' => $runId]);
        foreach ($sections as $section) {
            $stmt = $this->db->prepare(
                'INSERT INTO ac_similarity_document_sections
                 (tenant_id, assessment_run_id, submission_id, section_key, heading, section_order, start_offset, end_offset, extraction_confidence, maturity)
                 VALUES (:tid, :rid, :sid, :section_key, :heading, :section_order, :start_offset, :end_offset, :confidence, :maturity)'
            );
            $stmt->execute([
                ':tid' => $this->tenantId,
                ':rid' => $runId,
                ':sid' => $submissionId,
                ':section_key' => (string)$section['section_key'],
                ':heading' => (string)($section['heading'] ?? ''),
                ':section_order' => (int)($section['section_order'] ?? 0),
                ':start_offset' => (int)($section['start_offset'] ?? 0),
                ':end_offset' => (int)($section['end_offset'] ?? 0),
                ':confidence' => (float)($section['extraction_confidence'] ?? 0.5),
                ':maturity' => (string)($section['maturity'] ?? 'beta'),
            ]);
        }
    }

    public function replaceClaims(int $runId, int $submissionId, array $claims): void
    {
        $this->db->execute('DELETE FROM ac_similarity_research_claims WHERE tenant_id = :tid AND assessment_run_id = :rid', [':tid' => $this->tenantId, ':rid' => $runId]);
        foreach ($claims as $claim) {
            $stmt = $this->db->prepare(
                'INSERT INTO ac_similarity_research_claims
                 (tenant_id, assessment_run_id, submission_id, section_id, claim_type, claim_text, start_offset, end_offset, extraction_confidence, machine_payload_json)
                 VALUES (:tid, :rid, :sid, :section_id, :claim_type, :claim_text, :start_offset, :end_offset, :confidence, :payload)'
            );
            $stmt->execute([
                ':tid' => $this->tenantId,
                ':rid' => $runId,
                ':sid' => $submissionId,
                ':section_id' => $claim['section_id'] ?? null,
                ':claim_type' => (string)$claim['claim_type'],
                ':claim_text' => (string)$claim['claim_text'],
                ':start_offset' => (int)($claim['start_offset'] ?? 0),
                ':end_offset' => (int)($claim['end_offset'] ?? 0),
                ':confidence' => (float)($claim['extraction_confidence'] ?? 0.5),
                ':payload' => json_encode($claim['machine_payload'] ?? []),
            ]);
        }
    }

    public function replaceEvidence(int $runId, int $submissionId, array $items): void
    {
        $this->db->execute('DELETE FROM ac_similarity_assessment_evidence WHERE tenant_id = :tid AND assessment_run_id = :rid', [':tid' => $this->tenantId, ':rid' => $runId]);
        foreach ($items as $item) {
            $stmt = $this->db->prepare(
                'INSERT INTO ac_similarity_assessment_evidence
                 (tenant_id, assessment_run_id, submission_id, dimension, evidence_type, status, claim_id, section_id, match_id, source_id, rationale, uncertainty, limitations_json, payload_json)
                 VALUES (:tid, :rid, :sid, :dimension, :evidence_type, :status, :claim_id, :section_id, :match_id, :source_id, :rationale, :uncertainty, :limitations, :payload)'
            );
            $stmt->execute([
                ':tid' => $this->tenantId,
                ':rid' => $runId,
                ':sid' => $submissionId,
                ':dimension' => (string)$item['dimension'],
                ':evidence_type' => (string)$item['evidence_type'],
                ':status' => (string)($item['status'] ?? 'uncertain'),
                ':claim_id' => $item['claim_id'] ?? null,
                ':section_id' => $item['section_id'] ?? null,
                ':match_id' => $item['match_id'] ?? null,
                ':source_id' => $item['source_id'] ?? null,
                ':rationale' => $item['rationale'] ?? null,
                ':uncertainty' => (string)($item['uncertainty'] ?? 'medium'),
                ':limitations' => json_encode($item['limitations'] ?? []),
                ':payload' => json_encode(array_merge(
                    is_array($item['payload'] ?? null) ? $item['payload'] : [],
                    ['evidence_key' => (string)($item['evidence_key'] ?? '')]
                )),
            ]);
        }
    }

    public function replaceSuggestions(int $runId, int $submissionId, array $suggestions): void
    {
        $this->db->execute('DELETE FROM ac_similarity_reviewer_suggestions WHERE tenant_id = :tid AND assessment_run_id = :rid', [':tid' => $this->tenantId, ':rid' => $runId]);
        foreach ($suggestions as $suggestion) {
            $stmt = $this->db->prepare(
                'INSERT INTO ac_similarity_reviewer_suggestions
                 (tenant_id, assessment_run_id, submission_id, suggestion_key, category, priority, reviewer_action, title, rationale, claim_id, section_id, evidence_ids_json, source_context_json, uncertainty, maturity, limitations_json, rule_version)
                 VALUES (:tid, :rid, :sid, :suggestion_key, :category, :priority, :reviewer_action, :title, :rationale, :claim_id, :section_id, :evidence_ids, :source_context, :uncertainty, :maturity, :limitations, :rule_version)'
            );
            $stmt->execute([
                ':tid' => $this->tenantId,
                ':rid' => $runId,
                ':sid' => $submissionId,
                ':suggestion_key' => (string)$suggestion['suggestion_key'],
                ':category' => (string)$suggestion['category'],
                ':priority' => (string)($suggestion['priority'] ?? 'medium'),
                ':reviewer_action' => (string)$suggestion['reviewer_action'],
                ':title' => (string)$suggestion['title'],
                ':rationale' => (string)$suggestion['rationale'],
                ':claim_id' => $suggestion['claim_id'] ?? null,
                ':section_id' => $suggestion['section_id'] ?? null,
                ':evidence_ids' => json_encode($suggestion['evidence_ids'] ?? []),
                ':source_context' => json_encode($suggestion['source_context'] ?? []),
                ':uncertainty' => (string)($suggestion['uncertainty'] ?? 'medium'),
                ':maturity' => (string)($suggestion['maturity'] ?? 'beta'),
                ':limitations' => json_encode($suggestion['limitations'] ?? []),
                ':rule_version' => (string)($suggestion['rule_version'] ?? 'suggestion-rules-v1'),
            ]);
        }
    }

    public function bundleRows(int $runId): array
    {
        $tables = [
            'sections' => 'ac_similarity_document_sections',
            'claims' => 'ac_similarity_research_claims',
            'evidence' => 'ac_similarity_assessment_evidence',
            'suggestions' => 'ac_similarity_reviewer_suggestions',
        ];
        $rows = [];
        foreach ($tables as $key => $table) {
            $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE tenant_id = :tid AND assessment_run_id = :rid ORDER BY id ASC");
            $stmt->execute([':tid' => $this->tenantId, ':rid' => $runId]);
            $rows[$key] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $rows;
    }
}
