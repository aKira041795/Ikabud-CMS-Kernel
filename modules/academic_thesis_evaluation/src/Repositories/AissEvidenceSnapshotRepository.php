<?php
declare(strict_types=1);

class AissEvidenceSnapshotRepository
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
            "SELECT * FROM ate_aiss_evidence_snapshots WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_aiss_evidence_snapshots WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY generated_at DESC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByManuscriptVersion(int $versionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_aiss_evidence_snapshots WHERE manuscript_version_id = :vid AND tenant_id = :tid ORDER BY generated_at DESC LIMIT 1"
        );
        $stmt->execute([':vid' => $versionId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_aiss_evidence_snapshots (tenant_id, evaluation_case_id, manuscript_version_id, aiss_submission_id, capability_version, evidence_version, textual_result, citation_result, semantic_result, context_result, scholarship_result, lineage_result, maturity_metadata, capability_warnings, generated_at, generated_by, source_hash)
            VALUES (:tid, :case_id, :manuscript_id, :aiss_id, :cap_version, :ev_version, :textual, :citation, :semantic, :context, :scholarship, :lineage, :maturity, :warnings, NOW(), :by, :hash)
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':manuscript_id' => (int)$data['manuscript_version_id'],
            ':aiss_id' => $data['aiss_submission_id'] ?? null,
            ':cap_version' => $data['capability_version'] ?? null,
            ':ev_version' => $data['evidence_version'] ?? '1.0',
            ':textual' => isset($data['textual_result']) ? json_encode($data['textual_result']) : null,
            ':citation' => isset($data['citation_result']) ? json_encode($data['citation_result']) : null,
            ':semantic' => isset($data['semantic_result']) ? json_encode($data['semantic_result']) : null,
            ':context' => isset($data['context_result']) ? json_encode($data['context_result']) : null,
            ':scholarship' => isset($data['scholarship_result']) ? json_encode($data['scholarship_result']) : null,
            ':lineage' => isset($data['lineage_result']) ? json_encode($data['lineage_result']) : null,
            ':maturity' => isset($data['maturity_metadata']) ? json_encode($data['maturity_metadata']) : null,
            ':warnings' => isset($data['capability_warnings']) ? json_encode($data['capability_warnings']) : null,
            ':by' => (int)$data['generated_by'],
            ':hash' => $data['source_hash'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
