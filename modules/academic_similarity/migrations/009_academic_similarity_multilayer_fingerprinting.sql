-- Academic Similarity v1.1.0 — Multi-layer fingerprinting + progress tracking
-- Adds shingle_level column to support short/medium/long shingle layers
-- Adds progress columns to processing_jobs for async status feedback
-- Adds shingle_level index for filtered queries

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE ac_similarity_fingerprints
    ADD COLUMN shingle_level VARCHAR(10) NOT NULL DEFAULT 'medium' AFTER fingerprint_type,
    ADD INDEX idx_fp_shingle_level (shingle_level),
    ADD INDEX idx_fp_level_hash (shingle_level, shingle_hash);

ALTER TABLE ac_similarity_processing_jobs
    ADD COLUMN progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN progress_label VARCHAR(255) NOT NULL DEFAULT '' AFTER progress_pct;

SET FOREIGN_KEY_CHECKS = 1;
