-- Academic Thesis Evaluation — AISS Evidence Snapshots
-- Immutable snapshot of AISS output for a given manuscript version.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_aiss_evidence_snapshots (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               VARCHAR(64) NOT NULL,
    evaluation_case_id      INT UNSIGNED NOT NULL,
    manuscript_version_id   INT UNSIGNED NOT NULL,
    aiss_submission_id      INT UNSIGNED DEFAULT NULL COMMENT 'AISS submission ID for traceability',
    capability_version      VARCHAR(50) DEFAULT NULL COMMENT 'Version of AISS capability that produced this',
    evidence_version        VARCHAR(20) NOT NULL DEFAULT '1.0',
    textual_result          JSON DEFAULT NULL COMMENT 'Textual matching output',
    citation_result         JSON DEFAULT NULL COMMENT 'Citation detection output',
    semantic_result         JSON DEFAULT NULL COMMENT 'Semantic resemblance output',
    context_result          JSON DEFAULT NULL COMMENT 'Context analysis output',
    scholarship_result      JSON DEFAULT NULL COMMENT 'Scholarship evidence distribution',
    lineage_result          JSON DEFAULT NULL COMMENT 'Knowledge lineage graph',
    maturity_metadata       JSON DEFAULT NULL COMMENT 'Per-feature maturity flags from AISS',
    capability_warnings      JSON DEFAULT NULL COMMENT 'Warnings/limitations from AISS capabilities',
    generated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by            INT UNSIGNED NOT NULL,
    source_hash             VARCHAR(128) DEFAULT NULL COMMENT 'Hash of raw AISS payload for integrity',
    UNIQUE KEY uq_snapshot_manuscript (manuscript_version_id, evidence_version),
    KEY idx_snapshot_case (evaluation_case_id),
    KEY idx_snapshot_tenant (tenant_id),
    CONSTRAINT fk_snapshot_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshot_manuscript FOREIGN KEY (manuscript_version_id) REFERENCES ate_manuscript_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
