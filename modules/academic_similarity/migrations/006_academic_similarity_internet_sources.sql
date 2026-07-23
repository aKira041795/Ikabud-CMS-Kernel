-- AISS internet-assisted source discovery provenance.
-- Bounded, tenant-scoped metadata for public source searches and retrieval.

ALTER TABLE ac_similarity_processing_jobs
    MODIFY job_type ENUM('extract','normalize','segment','internet_discovery','fingerprint','candidate_search','exact_match','near_match','semantic_match','score','report','reindex') NOT NULL;

CREATE TABLE IF NOT EXISTS ac_similarity_internet_search_runs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    institution_id   INT UNSIGNED NOT NULL DEFAULT 0,
    provider         VARCHAR(80) NOT NULL DEFAULT '',
    status           ENUM('pending','completed','partial','failed','skipped') NOT NULL DEFAULT 'pending',
    query_count      INT UNSIGNED NOT NULL DEFAULT 0,
    candidate_count  INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count   INT UNSIGNED NOT NULL DEFAULT 0,
    payload_policy   VARCHAR(80) NOT NULL DEFAULT 'snippets_only',
    disclosure       TEXT,
    error_message    TEXT,
    metadata_json    JSON DEFAULT NULL,
    started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     DATETIME DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aisr_tenant (tenant_id),
    KEY idx_aisr_submission (submission_id),
    KEY idx_aisr_status (status),
    KEY idx_aisr_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ac_similarity_internet_sources (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    search_run_id    INT UNSIGNED NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    source_id        INT UNSIGNED DEFAULT NULL,
    provider         VARCHAR(80) NOT NULL DEFAULT '',
    query_text       TEXT,
    result_rank      INT UNSIGNED NOT NULL DEFAULT 0,
    source_url       VARCHAR(1000) NOT NULL DEFAULT '',
    title            VARCHAR(500) NOT NULL DEFAULT '',
    author           VARCHAR(255) NOT NULL DEFAULT '',
    publisher        VARCHAR(255) NOT NULL DEFAULT '',
    snippet          TEXT,
    retrieved_text_hash VARCHAR(64) NOT NULL DEFAULT '',
    retrieved_chars  INT UNSIGNED NOT NULL DEFAULT 0,
    retrieval_status ENUM('candidate','retrieved','imported','failed','skipped') NOT NULL DEFAULT 'candidate',
    retrieval_error  TEXT,
    retrieved_at     DATETIME DEFAULT NULL,
    metadata_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ais_tenant (tenant_id),
    KEY idx_ais_run (search_run_id),
    KEY idx_ais_submission (submission_id),
    KEY idx_ais_source (source_id),
    KEY idx_ais_status (retrieval_status),
    KEY idx_ais_hash (retrieved_text_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
