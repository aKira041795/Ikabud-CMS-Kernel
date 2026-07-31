-- AISS — Document Assessment Bundle
--
-- Additive storage for non-verdict document assessment evidence.
-- @mysql57-compat: ENGINE=InnoDB, no window functions, no CTEs

CREATE TABLE IF NOT EXISTS ac_similarity_assessment_runs (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   VARCHAR(64) NOT NULL,
    submission_id               INT UNSIGNED NOT NULL,
    manuscript_hash             VARCHAR(64) NOT NULL DEFAULT '',
    text_version_id             INT UNSIGNED DEFAULT NULL,
    text_hash_sha256            VARCHAR(64) NOT NULL DEFAULT '',
    extraction_version          VARCHAR(50) NOT NULL DEFAULT 'deterministic-structure-v1',
    assessment_version          VARCHAR(50) NOT NULL DEFAULT 'assessment-bundle-v1.1',
    corpus_cutoff_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    search_provider             VARCHAR(100) DEFAULT NULL,
    sanitized_queries_json      JSON DEFAULT NULL,
    coverage_json               JSON DEFAULT NULL,
    settings_json               JSON DEFAULT NULL,
    thresholds_json             JSON DEFAULT NULL,
    provider_versions_json      JSON DEFAULT NULL,
    calibration_profile_json    JSON DEFAULT NULL,
    payload_disclosures_json    JSON DEFAULT NULL,
    maturity_json               JSON DEFAULT NULL,
    limitations_json            JSON DEFAULT NULL,
    status                      ENUM('completed','completed_partial','failed') NOT NULL DEFAULT 'completed_partial',
    idempotency_key             VARCHAR(128) NOT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assessment_idempotency (tenant_id, idempotency_key),
    KEY idx_assessment_submission (tenant_id, submission_id),
    KEY idx_assessment_hash (tenant_id, manuscript_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ac_similarity_document_sections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    assessment_run_id   INT UNSIGNED NOT NULL,
    submission_id        INT UNSIGNED NOT NULL,
    section_key          VARCHAR(100) NOT NULL,
    heading              VARCHAR(255) NOT NULL DEFAULT '',
    section_order        INT UNSIGNED NOT NULL DEFAULT 0,
    start_offset         INT UNSIGNED NOT NULL DEFAULT 0,
    end_offset           INT UNSIGNED NOT NULL DEFAULT 0,
    extraction_confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
    maturity             VARCHAR(50) NOT NULL DEFAULT 'beta',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sections_run (tenant_id, assessment_run_id),
    KEY idx_sections_submission (tenant_id, submission_id),
    KEY idx_sections_key (section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ac_similarity_research_claims (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    assessment_run_id   INT UNSIGNED NOT NULL,
    submission_id        INT UNSIGNED NOT NULL,
    section_id           INT UNSIGNED DEFAULT NULL,
    claim_type           VARCHAR(80) NOT NULL,
    claim_text           TEXT NOT NULL,
    start_offset         INT UNSIGNED NOT NULL DEFAULT 0,
    end_offset           INT UNSIGNED NOT NULL DEFAULT 0,
    extraction_confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
    reviewer_version     INT UNSIGNED NOT NULL DEFAULT 0,
    machine_payload_json JSON DEFAULT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_claims_run (tenant_id, assessment_run_id),
    KEY idx_claims_submission (tenant_id, submission_id),
    KEY idx_claims_type (claim_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ac_similarity_assessment_evidence (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    assessment_run_id   INT UNSIGNED NOT NULL,
    submission_id        INT UNSIGNED NOT NULL,
    dimension            VARCHAR(80) NOT NULL,
    evidence_type        VARCHAR(100) NOT NULL,
    status               VARCHAR(80) NOT NULL DEFAULT 'uncertain',
    claim_id             INT UNSIGNED DEFAULT NULL,
    section_id           INT UNSIGNED DEFAULT NULL,
    match_id             INT UNSIGNED DEFAULT NULL,
    source_id            INT UNSIGNED DEFAULT NULL,
    rationale            TEXT,
    uncertainty          VARCHAR(50) NOT NULL DEFAULT 'medium',
    limitations_json     JSON DEFAULT NULL,
    payload_json         JSON DEFAULT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_evidence_run (tenant_id, assessment_run_id),
    KEY idx_evidence_dimension (dimension),
    KEY idx_evidence_status (status),
    KEY idx_evidence_claim (claim_id),
    KEY idx_evidence_match (match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ac_similarity_reviewer_suggestions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    assessment_run_id   INT UNSIGNED NOT NULL,
    submission_id        INT UNSIGNED NOT NULL,
    suggestion_key       VARCHAR(128) NOT NULL,
    category             VARCHAR(80) NOT NULL,
    priority             ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    reviewer_action      VARCHAR(80) NOT NULL,
    title                VARCHAR(255) NOT NULL,
    rationale            TEXT NOT NULL,
    claim_id             INT UNSIGNED DEFAULT NULL,
    section_id           INT UNSIGNED DEFAULT NULL,
    evidence_ids_json    JSON DEFAULT NULL,
    source_context_json  JSON DEFAULT NULL,
    uncertainty          VARCHAR(50) NOT NULL DEFAULT 'medium',
    maturity             VARCHAR(50) NOT NULL DEFAULT 'beta',
    limitations_json     JSON DEFAULT NULL,
    rule_version         VARCHAR(50) NOT NULL DEFAULT 'suggestion-rules-v1.1',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suggestion_key (tenant_id, assessment_run_id, suggestion_key),
    KEY idx_suggestions_run (tenant_id, assessment_run_id),
    KEY idx_suggestions_category (category),
    KEY idx_suggestions_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
