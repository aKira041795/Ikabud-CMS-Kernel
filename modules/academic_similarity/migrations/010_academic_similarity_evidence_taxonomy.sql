-- AISS — Evidence Taxonomy (Phase 1)
--
-- Adds canonical classification columns to ac_similarity_matches for the
-- evidence taxonomy: context relationship, scholarly relationship,
-- attribution status, and machine-vs-reviewer classification separation.
--
-- @mysql57-compat: ENGINE=InnoDB, no window functions, no CTEs

-- ===================================================================
-- 1. Extend ac_similarity_matches with taxonomy columns
-- ===================================================================
-- These columns store the machine-generated classification alongside
-- reviewer overrides. Machine evidence is NEVER overwritten when the
-- reviewer changes a classification — both are preserved for audit.

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_similarity_matches' AND COLUMN_NAME='context_relationship'),
    'SELECT 1',
    'ALTER TABLE ac_similarity_matches
        ADD COLUMN context_relationship VARCHAR(50) DEFAULT NULL
        AFTER match_type,
        ADD COLUMN context_confidence DECIMAL(5,4) DEFAULT NULL
        AFTER context_relationship,
        ADD COLUMN context_explanation TEXT DEFAULT NULL
        AFTER context_confidence,
        ADD COLUMN scholarly_relationship VARCHAR(50) DEFAULT NULL
        AFTER context_explanation,
        ADD COLUMN attribution_status VARCHAR(50) DEFAULT NULL
        AFTER scholarly_relationship,
        ADD COLUMN machine_context_relationship VARCHAR(50) DEFAULT NULL
        AFTER attribution_status,
        ADD COLUMN machine_context_confidence DECIMAL(5,4) DEFAULT NULL
        AFTER machine_context_relationship,
        ADD COLUMN machine_context_explanation TEXT DEFAULT NULL
        AFTER machine_context_confidence,
        ADD COLUMN machine_scholarly_relationship VARCHAR(50) DEFAULT NULL
        AFTER machine_context_explanation,
        ADD COLUMN machine_attribution_status VARCHAR(50) DEFAULT NULL
        AFTER machine_scholarly_relationship,
        ADD COLUMN reviewer_classification VARCHAR(50) DEFAULT NULL
        AFTER machine_attribution_status,
        ADD COLUMN reviewer_decision VARCHAR(50) DEFAULT NULL
        AFTER reviewer_classification,
        ADD COLUMN reviewer_reason TEXT DEFAULT NULL
        AFTER reviewer_decision,
        ADD COLUMN reviewed_by INT UNSIGNED DEFAULT NULL
        AFTER reviewer_reason,
        ADD COLUMN reviewed_at DATETIME DEFAULT NULL
        AFTER reviewed_by,
        ADD INDEX idx_matches_context (context_relationship),
        ADD INDEX idx_matches_scholarly (scholarly_relationship),
        ADD INDEX idx_matches_reviewer_decision (reviewer_decision)'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ===================================================================
-- 2. Create evidence_classifications view for read-model queries
-- ===================================================================
-- Provides a denormalized view combining machine and reviewer classifications.

SELECT IF(
    NOT EXISTS(SELECT * FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_ac_evidence_classifications'),
    'CREATE VIEW v_ac_evidence_classifications AS
     SELECT
         m.id AS match_id,
         m.submission_id,
         m.source_id,
         m.tenant_id,
         m.match_type AS evidence_type,
         m.match_confidence,
         COALESCE(m.context_relationship, m.machine_context_relationship) AS effective_context_relationship,
         COALESCE(m.context_confidence, m.machine_context_confidence) AS effective_context_confidence,
         COALESCE(m.context_explanation, m.machine_context_explanation) AS effective_context_explanation,
         COALESCE(m.scholarly_relationship, m.machine_scholarly_relationship) AS effective_scholarly_relationship,
         COALESCE(m.attribution_status, m.machine_attribution_status) AS effective_attribution_status,
         m.machine_context_relationship,
         m.machine_context_confidence,
         m.machine_context_explanation,
         m.machine_scholarly_relationship,
         m.machine_attribution_status,
         m.reviewer_classification,
         m.reviewer_decision,
         m.reviewer_reason,
         m.reviewed_by,
         m.reviewed_at,
         m.matched_word_count,
         m.submission_word_range_start,
         m.submission_word_range_end,
         m.is_excluded,
         m.created_at
     FROM ac_similarity_matches m',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ===================================================================
-- 3. Update match_type ENUM to include new evidence types
-- ===================================================================
-- Note: MySQL 5.7 cannot ALTER ENUM directly in all configurations.
-- This is a best-effort ALTER; if it fails, the existing ENUM values
-- still work and the new types are stored in context_relationship instead.

SELECT IF(
    EXISTS(SELECT * FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_similarity_matches'
        AND COLUMN_NAME='match_type' AND COLUMN_TYPE LIKE '%exact%'
        AND COLUMN_TYPE NOT LIKE '%quotation%'),
    'ALTER TABLE ac_similarity_matches
     MODIFY COLUMN match_type ENUM(''exact'',''near-exact'',''sematic'',''quotation'',''template'',''bibliography'',''self_overlap'',''internet_reference'') NOT NULL DEFAULT ''exact''',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ===================================================================
-- 4. Add model profile columns for AI provenance on semantic matches
-- ===================================================================
SELECT IF(
    NOT EXISTS(SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_similarity_matches' AND COLUMN_NAME='model_provider'),
    'ALTER TABLE ac_similarity_matches
        ADD COLUMN model_provider VARCHAR(100) DEFAULT NULL
        AFTER match_confidence,
        ADD COLUMN model_name VARCHAR(100) DEFAULT NULL
        AFTER model_provider,
        ADD COLUMN prompt_version VARCHAR(50) DEFAULT NULL
        AFTER model_name,
        ADD COLUMN model_config_json TEXT DEFAULT NULL
        AFTER prompt_version',
    'SELECT 1'
) INTO @stmt; PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
