-- Academic Similarity Module v1.0.0 — Core Schema
-- All tables are tenant-scoped via tenant_id.
-- Institution-scoped tables also carry institution_id.
-- Text content is stored outside the public web root.

SET FOREIGN_KEY_CHECKS = 0;

-- ── Institutions ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_institutions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    name             VARCHAR(255) NOT NULL,
    code             VARCHAR(50) NOT NULL DEFAULT '',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    settings_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_institution_tenant_code (tenant_id, code),
    KEY idx_institutions_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Subscription Plans ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_plans (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    code             VARCHAR(50) NOT NULL UNIQUE,
    description      TEXT,
    monthly_submissions_limit   INT UNSIGNED NOT NULL DEFAULT 0,
    daily_submissions_limit     INT UNSIGNED NOT NULL DEFAULT 0,
    max_file_size_mb            INT UNSIGNED NOT NULL DEFAULT 20,
    max_word_count              INT UNSIGNED NOT NULL DEFAULT 50000,
    source_repository_limit     INT UNSIGNED NOT NULL DEFAULT 0,
    semantic_enabled            TINYINT(1) NOT NULL DEFAULT 0,
    retention_days              INT UNSIGNED NOT NULL DEFAULT 365,
    price_monthly               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    price_yearly                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active                   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order                  INT NOT NULL DEFAULT 0,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Subscriptions (per-institution) ──────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_subscriptions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL,
    plan_id          INT UNSIGNED NOT NULL,
    status           ENUM('active','trial','grace','suspended','cancelled','expired') NOT NULL DEFAULT 'trial',
    start_date       DATE NOT NULL,
    end_date         DATE DEFAULT NULL,
    trial_end_date   DATE DEFAULT NULL,
    billing_cycle    ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    auto_renew       TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_subscriptions_tenant (tenant_id),
    KEY idx_subscriptions_institution (institution_id),
    KEY idx_subscriptions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Usage Counters ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_usage_counters (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL,
    metric           VARCHAR(80) NOT NULL,
    period_date      DATE NOT NULL,
    count_value      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usage_metric_period (tenant_id, institution_id, metric, period_date),
    KEY idx_usage_tenant (tenant_id),
    KEY idx_usage_institution (institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Collections (source grouping) ────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_collections (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL,
    name             VARCHAR(255) NOT NULL,
    description      TEXT,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_collections_tenant (tenant_id),
    KEY idx_collections_institution (institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sources (reference documents for comparison) ─────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_sources (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL DEFAULT 0,
    collection_id    INT UNSIGNED DEFAULT NULL,
    title            VARCHAR(255) NOT NULL DEFAULT '',
    author           VARCHAR(255) NOT NULL DEFAULT '',
    source_type      ENUM('upload','pasted','submission') NOT NULL DEFAULT 'upload',
    classification   ENUM('published','student','institutional','other') NOT NULL DEFAULT 'published',
    original_filename VARCHAR(255) NOT NULL DEFAULT '',
    storage_path     VARCHAR(500) NOT NULL DEFAULT '',
    storage_name     VARCHAR(128) NOT NULL DEFAULT '',
    mime_type        VARCHAR(100) NOT NULL DEFAULT '',
    file_size_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    word_count       INT UNSIGNED NOT NULL DEFAULT 0,
    page_count       INT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256  VARCHAR(64) NOT NULL DEFAULT '',
    text_hash_sha256 VARCHAR(64) NOT NULL DEFAULT '',
    is_indexed       TINYINT(1) NOT NULL DEFAULT 0,
    indexed_at       DATETIME DEFAULT NULL,
    indexing_status  ENUM('pending','processing','indexed','failed') NOT NULL DEFAULT 'pending',
    indexing_error   TEXT,
    metadata_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sources_tenant (tenant_id),
    KEY idx_sources_institution (institution_id),
    KEY idx_sources_collection (collection_id),
    KEY idx_sources_checksum (checksum_sha256),
    KEY idx_sources_index_status (indexing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Submissions (student documents for similarity checking) ──────
CREATE TABLE IF NOT EXISTS ac_similarity_submissions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL,
    submission_title VARCHAR(255) NOT NULL DEFAULT '',
    author_name      VARCHAR(255) NOT NULL DEFAULT '',
    author_identifier VARCHAR(100) NOT NULL DEFAULT '',
    source_type      ENUM('upload','pasted') NOT NULL DEFAULT 'upload',
    status           ENUM('pending','processing','processed','failed') NOT NULL DEFAULT 'pending',
    original_filename VARCHAR(255) NOT NULL DEFAULT '',
    storage_path     VARCHAR(500) NOT NULL DEFAULT '',
    storage_name     VARCHAR(128) NOT NULL DEFAULT '',
    mime_type        VARCHAR(100) NOT NULL DEFAULT '',
    file_size_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    word_count       INT UNSIGNED NOT NULL DEFAULT 0,
    page_count       INT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256  VARCHAR(64) NOT NULL DEFAULT '',
    text_hash_sha256 VARCHAR(64) NOT NULL DEFAULT '',
    raw_similarity_score    DECIMAL(5,2) DEFAULT NULL,
    adjusted_similarity_score DECIMAL(5,2) DEFAULT NULL,
    matched_word_count       INT UNSIGNED NOT NULL DEFAULT 0,
    total_eligible_words     INT UNSIGNED NOT NULL DEFAULT 0,
    processing_error         TEXT,
    retry_count              INT UNSIGNED NOT NULL DEFAULT 0,
    retry_max                INT UNSIGNED NOT NULL DEFAULT 3,
    idempotency_key          VARCHAR(128) NOT NULL DEFAULT '',
    submitted_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at             DATETIME DEFAULT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_submissions_tenant (tenant_id),
    KEY idx_submissions_institution (institution_id),
    KEY idx_submissions_status (status),
    KEY idx_submissions_idempotency (idempotency_key),
    KEY idx_submissions_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Files (upload versions for both sources and submissions) ─────
CREATE TABLE IF NOT EXISTS ac_similarity_files (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    source_id        INT UNSIGNED DEFAULT NULL,
    submission_id    INT UNSIGNED DEFAULT NULL,
    file_type        ENUM('source','submission') NOT NULL,
    original_filename VARCHAR(255) NOT NULL DEFAULT '',
    storage_path     VARCHAR(500) NOT NULL DEFAULT '',
    storage_name     VARCHAR(128) NOT NULL DEFAULT '',
    mime_type        VARCHAR(100) NOT NULL DEFAULT '',
    file_size_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256  VARCHAR(64) NOT NULL DEFAULT '',
    is_original      TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_files_tenant (tenant_id),
    KEY idx_files_source (source_id),
    KEY idx_files_submission (submission_id),
    KEY idx_files_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Text Versions (extracted text with metadata) ─────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_text_versions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    source_id        INT UNSIGNED DEFAULT NULL,
    submission_id    INT UNSIGNED DEFAULT NULL,
    text_type        ENUM('source','submission') NOT NULL,
    extracted_text   LONGTEXT,
    normalized_text  LONGTEXT,
    word_count       INT UNSIGNED NOT NULL DEFAULT 0,
    normalized_word_count INT UNSIGNED NOT NULL DEFAULT 0,
    page_count       INT UNSIGNED NOT NULL DEFAULT 0,
    text_hash_sha256 VARCHAR(64) NOT NULL DEFAULT '',
    normalized_hash_sha256 VARCHAR(64) NOT NULL DEFAULT '',
    offset_map_json  LONGTEXT COMMENT 'Maps normalized offsets to original text offsets',
    extraction_method VARCHAR(50) NOT NULL DEFAULT '',
    extraction_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tv_tenant (tenant_id),
    KEY idx_tv_source (source_id),
    KEY idx_tv_submission (submission_id),
    KEY idx_tv_text_hash (text_hash_sha256),
    KEY idx_tv_norm_hash (normalized_hash_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Segments (sentences/paragraphs from normalized text) ─────────
CREATE TABLE IF NOT EXISTS ac_similarity_segments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    text_version_id  INT UNSIGNED NOT NULL,
    source_id        INT UNSIGNED DEFAULT NULL,
    submission_id    INT UNSIGNED DEFAULT NULL,
    segment_type     ENUM('sentence','paragraph','page') NOT NULL DEFAULT 'sentence',
    segment_index    INT UNSIGNED NOT NULL DEFAULT 0,
    content          TEXT NOT NULL,
    normalized_content TEXT NOT NULL,
    word_count       INT UNSIGNED NOT NULL DEFAULT 0,
    char_count       INT UNSIGNED NOT NULL DEFAULT 0,
    original_start_offset INT UNSIGNED NOT NULL DEFAULT 0,
    original_end_offset   INT UNSIGNED NOT NULL DEFAULT 0,
    normalized_start_offset INT UNSIGNED NOT NULL DEFAULT 0,
    normalized_end_offset   INT UNSIGNED NOT NULL DEFAULT 0,
    page_id          INT UNSIGNED DEFAULT NULL,
    is_quotation     TINYINT(1) NOT NULL DEFAULT 0,
    is_bibliography  TINYINT(1) NOT NULL DEFAULT 0,
    metadata_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seg_tenant (tenant_id),
    KEY idx_seg_text_version (text_version_id),
    KEY idx_seg_source (source_id),
    KEY idx_seg_submission (submission_id),
    KEY idx_seg_type (segment_type),
    KEY idx_seg_norm_content (normalized_content(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fingerprints (shingle hash sets for matching) ────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_fingerprints (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    source_id        INT UNSIGNED DEFAULT NULL,
    submission_id    INT UNSIGNED DEFAULT NULL,
    segment_id       INT UNSIGNED DEFAULT NULL,
    text_version_id  INT UNSIGNED NOT NULL,
    fingerprint_type ENUM('exact','near') NOT NULL DEFAULT 'exact',
    shingle_size     INT UNSIGNED NOT NULL DEFAULT 5,
    shingle_hash     VARCHAR(64) NOT NULL,
    shingle_text     TEXT NOT NULL COMMENT 'Original shingle text for diagnostic',
    segment_index    INT UNSIGNED NOT NULL DEFAULT 0,
    word_position    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fp_tenant (tenant_id),
    KEY idx_fp_source (source_id),
    KEY idx_fp_submission (submission_id),
    KEY idx_fp_segment (segment_id),
    KEY idx_fp_hash_type (fingerprint_type, shingle_hash),
    KEY idx_fp_hash (shingle_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Candidate Sources (sources selected for comparison) ──────────
CREATE TABLE IF NOT EXISTS ac_similarity_candidate_sources (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    source_id        INT UNSIGNED NOT NULL,
    match_confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    fingerprint_hits INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('pending','compared','skipped') NOT NULL DEFAULT 'pending',
    compared_at      DATETIME DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cs_tenant (tenant_id),
    KEY idx_cs_submission (submission_id),
    KEY idx_cs_source (source_id),
    KEY idx_cs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Matches (between submission and source segments) ─────────────
CREATE TABLE IF NOT EXISTS ac_similarity_matches (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    source_id        INT UNSIGNED NOT NULL,
    match_type       ENUM('exact','near-exact','semantic') NOT NULL DEFAULT 'exact',
    match_confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    submission_segment_id  INT UNSIGNED DEFAULT NULL,
    source_segment_id      INT UNSIGNED DEFAULT NULL,
    matched_word_count     INT UNSIGNED NOT NULL DEFAULT 0,
    submission_word_range_start INT UNSIGNED NOT NULL DEFAULT 0,
    submission_word_range_end   INT UNSIGNED NOT NULL DEFAULT 0,
    source_word_range_start     INT UNSIGNED NOT NULL DEFAULT 0,
    source_word_range_end       INT UNSIGNED NOT NULL DEFAULT 0,
    segment_match_count         INT UNSIGNED NOT NULL DEFAULT 0,
    is_excluded          TINYINT(1) NOT NULL DEFAULT 0,
    excluded_at          DATETIME DEFAULT NULL,
    excluded_by          VARCHAR(100) NOT NULL DEFAULT '',
    exclusion_reason     VARCHAR(255) NOT NULL DEFAULT '',
    exclusion_note       TEXT,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_matches_tenant (tenant_id),
    KEY idx_matches_submission (submission_id),
    KEY idx_matches_source (source_id),
    KEY idx_matches_type (match_type),
    KEY idx_matches_excluded (is_excluded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Match Evidence (immutable raw matching data) ─────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_match_evidence (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    match_id         INT UNSIGNED NOT NULL,
    submission_segment_text TEXT NOT NULL,
    source_segment_text     TEXT NOT NULL,
    submission_start_offset  INT UNSIGNED NOT NULL DEFAULT 0,
    submission_end_offset    INT UNSIGNED NOT NULL DEFAULT 0,
    source_start_offset      INT UNSIGNED NOT NULL DEFAULT 0,
    source_end_offset        INT UNSIGNED NOT NULL DEFAULT 0,
    overlap_resolution_order INT NOT NULL DEFAULT 0,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_me_tenant (tenant_id),
    KEY idx_me_match (match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Exclusions (audited reviewer exclusions) ─────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_exclusions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    match_id         INT UNSIGNED NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    excluded_by      VARCHAR(100) NOT NULL,
    excluded_by_id   INT UNSIGNED NOT NULL DEFAULT 0,
    reason           VARCHAR(255) NOT NULL DEFAULT '',
    note             TEXT,
    previous_score   DECIMAL(5,2) DEFAULT NULL,
    resulting_score  DECIMAL(5,2) DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_excl_tenant (tenant_id),
    KEY idx_excl_match (match_id),
    KEY idx_excl_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Reviews (reviewer workflow state) ────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_reviews (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    reviewer_id      INT UNSIGNED NOT NULL DEFAULT 0,
    reviewer_name    VARCHAR(255) NOT NULL DEFAULT '',
    status           ENUM('assigned','in_progress','completed') NOT NULL DEFAULT 'assigned',
    determination    ENUM('no_concern','minor_concern','major_concern','insufficient') DEFAULT NULL,
    notes            TEXT,
    assigned_at      DATETIME DEFAULT NULL,
    completed_at     DATETIME DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_reviews_tenant (tenant_id),
    KEY idx_reviews_submission (submission_id),
    KEY idx_reviews_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Reports (generated similarity reports) ───────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_reports (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    report_version   VARCHAR(20) NOT NULL DEFAULT '1.0.0',
    match_engine_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
    semantic_model_version VARCHAR(50) DEFAULT NULL,
    raw_score        DECIMAL(5,2) DEFAULT NULL,
    adjusted_score   DECIMAL(5,2) DEFAULT NULL,
    total_matches    INT UNSIGNED NOT NULL DEFAULT 0,
    total_excluded   INT UNSIGNED NOT NULL DEFAULT 0,
    matched_word_count     INT UNSIGNED NOT NULL DEFAULT 0,
    total_eligible_words   INT UNSIGNED NOT NULL DEFAULT 0,
    exclusion_word_deduction INT UNSIGNED NOT NULL DEFAULT 0,
    report_checksum   VARCHAR(64) NOT NULL DEFAULT '',
    report_format     ENUM('html','json','pdf') NOT NULL DEFAULT 'html',
    report_data_json  LONGTEXT,
    generated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reports_tenant (tenant_id),
    KEY idx_reports_submission (submission_id),
    KEY idx_reports_checksum (report_checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Processing Jobs (pipeline stage tracking) ────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_processing_jobs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    submission_id    INT UNSIGNED NOT NULL,
    job_type         ENUM('extract','normalize','segment','fingerprint','candidate_search','exact_match','near_match','semantic_match','score','report','reindex') NOT NULL,
    status           ENUM('pending','running','completed','failed','skipped') NOT NULL DEFAULT 'pending',
    priority         INT NOT NULL DEFAULT 0,
    idempotency_key  VARCHAR(128) NOT NULL DEFAULT '',
    started_at       DATETIME DEFAULT NULL,
    completed_at     DATETIME DEFAULT NULL,
    failure_reason   TEXT,
    retry_count      INT UNSIGNED NOT NULL DEFAULT 0,
    retry_max        INT UNSIGNED NOT NULL DEFAULT 3,
    next_retry_at    DATETIME DEFAULT NULL,
    diagnostics_json JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pj_tenant (tenant_id),
    KEY idx_pj_submission (submission_id),
    KEY idx_pj_status (status),
    KEY idx_pj_idempotency (idempotency_key),
    KEY idx_pj_type_status (job_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Model Profiles (semantic matching model settings) ────────────
CREATE TABLE IF NOT EXISTS ac_similarity_model_profiles (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    name             VARCHAR(255) NOT NULL,
    provider         VARCHAR(100) NOT NULL DEFAULT '',
    model_name       VARCHAR(255) NOT NULL DEFAULT '',
    model_version    VARCHAR(50) NOT NULL DEFAULT '',
    embedding_dimensions INT UNSIGNED NOT NULL DEFAULT 0,
    max_tokens       INT UNSIGNED NOT NULL DEFAULT 0,
    cost_per_1k_tokens DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json    JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mp_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Integrations (LMS/external system connections) ───────────────
CREATE TABLE IF NOT EXISTS ac_similarity_integrations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL,
    name             VARCHAR(255) NOT NULL,
    integration_type ENUM('moodle','canvas','blackboard','api') NOT NULL DEFAULT 'api',
    endpoint_url     VARCHAR(500) NOT NULL DEFAULT '',
    api_key_encrypted VARCHAR(500) NOT NULL DEFAULT '',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    config_json      JSON DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_int_tenant (tenant_id),
    KEY idx_int_institution (institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Retention Policies ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_retention_policies (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    institution_id   INT UNSIGNED NOT NULL DEFAULT 0,
    data_category    ENUM('submissions','sources','reports','audit') NOT NULL DEFAULT 'submissions',
    retention_days   INT UNSIGNED NOT NULL DEFAULT 365,
    purge_after_days INT UNSIGNED NOT NULL DEFAULT 0,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rp_tenant (tenant_id),
    KEY idx_rp_institution (institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings Table ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_settings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    setting_key      VARCHAR(80) NOT NULL,
    setting_value    TEXT NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_setting (tenant_id, setting_key),
    KEY idx_settings_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit Events ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_similarity_audit_events (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        VARCHAR(64) NOT NULL,
    event_type       VARCHAR(80) NOT NULL,
    actor_id         INT UNSIGNED NOT NULL DEFAULT 0,
    actor_name       VARCHAR(255) NOT NULL DEFAULT '',
    target_type      VARCHAR(80) NOT NULL DEFAULT '',
    target_id        INT UNSIGNED NOT NULL DEFAULT 0,
    description      TEXT,
    details_json     JSON DEFAULT NULL,
    ip_address       VARCHAR(45) NOT NULL DEFAULT '',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ae_tenant (tenant_id),
    KEY idx_ae_event_type (event_type),
    KEY idx_ae_target (target_type, target_id),
    KEY idx_ae_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
