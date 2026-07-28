-- AISS settings reconciliation — ensures every tenant has all current defaults.
-- This migration is idempotent and safe to run repeatedly.
-- It inserts missing settings for each tenant and sets a defaults_version marker
-- so future code changes can detect stale tenants.

-- Step 1: Create a helper stored procedure is not possible in MySQL 5.7 reliably,
-- so we use INSERT ... ON DUPLICATE KEY UPDATE for each known tenant.

-- First, ensure the _defaults_version key exists for every tenant that already
-- has AISS settings. This marks them as "reconciled" as of this migration.
INSERT INTO ac_similarity_settings (tenant_id, setting_key, setting_value, updated_at)
SELECT DISTINCT tenant_id, '_defaults_version', '008', NOW()
FROM ac_similarity_settings
WHERE tenant_id IS NOT NULL
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW();

-- Step 2: Ensure every tenant has all current default keys.
-- The full list of current defaults (must match helpers.php academic_similarity_get_settings).
-- We insert each key for each tenant that doesn't already have it.

-- We iterate over known tenants by selecting distinct tenant_ids from any existing row.
-- For each tenant, we insert any missing keys with their current defaults.

INSERT INTO ac_similarity_settings (tenant_id, setting_key, setting_value, updated_at)
SELECT t.tenant_id, d.setting_key, d.setting_value, NOW()
FROM (SELECT DISTINCT tenant_id FROM ac_similarity_settings WHERE tenant_id IS NOT NULL) t
CROSS JOIN (
  SELECT 'enabled' AS setting_key, '1' AS setting_value
  UNION ALL SELECT 'exact_match_enabled', '1'
  UNION ALL SELECT 'near_match_enabled', '1'
  UNION ALL SELECT 'semantic_match_enabled', '1'
  UNION ALL SELECT 'semantic_provider', 'token_overlap'
  UNION ALL SELECT 'semantic_model_name', 'token_overlap'
  UNION ALL SELECT 'semantic_service_endpoint', 'http://127.0.0.1:9003'
  UNION ALL SELECT 'semantic_service_token_env', 'SEMANTIC_SERVICE_TOKEN'
  UNION ALL SELECT 'semantic_external_api_key_env', 'SEMANTIC_API_KEY'
  UNION ALL SELECT 'semantic_external_api_key', ''
  UNION ALL SELECT 'semantic_similarity_threshold', '0.25'
  UNION ALL SELECT 'semantic_report_threshold', '0.70'
  UNION ALL SELECT 'semantic_max_segments', '500'
  UNION ALL SELECT 'semantic_payload_policy', 'segments_only'
  UNION ALL SELECT 'semantic_health_visible', '1'
  UNION ALL SELECT 'cms_public_submission_enabled', '1'
  UNION ALL SELECT 'cms_submission_shortcode', 'academic_similarity_submission'
  UNION ALL SELECT 'cms_builder_block_enabled', '1'
  UNION ALL SELECT 'cms_default_submission_title', 'Submit Document for Similarity Check'
  UNION ALL SELECT 'similarity_threshold', '70'
  UNION ALL SELECT 'min_match_length', '5'
  UNION ALL SELECT 'processing_batch_size', '10'
  UNION ALL SELECT 'max_sources_per_comparison', '100'
  UNION ALL SELECT 'report_include_highlights', '1'
  UNION ALL SELECT 'report_include_source_breakdown', '1'
  UNION ALL SELECT 'auto_generate_reports', '1'
  UNION ALL SELECT 'notify_on_completion', '0'
  UNION ALL SELECT 'min_word_count', '20'
  UNION ALL SELECT 'max_word_count', '50000'
  UNION ALL SELECT 'max_file_size_mb', '20'
  UNION ALL SELECT 'fingerprint_shingle_size', '5'
  UNION ALL SELECT 'near_match_threshold', '0.8'
  UNION ALL SELECT 'retention_days', '365'
  UNION ALL SELECT 'allowed_extensions', 'docx,pdf,txt'
  UNION ALL SELECT 'public_results_enabled', '1'
  UNION ALL SELECT 'public_results_recent_limit', '10'
  UNION ALL SELECT 'public_results_show_scores', '1'
  UNION ALL SELECT 'public_results_show_match_count', '1'
  UNION ALL SELECT 'public_results_show_report_links', '1'
  UNION ALL SELECT 'public_results_allow_anonymous', '0'
  UNION ALL SELECT 'public_report_workspace_enabled', '1'
  UNION ALL SELECT 'public_report_download_enabled', '1'
  UNION ALL SELECT 'public_report_show_raw_score', '1'
  UNION ALL SELECT 'public_report_show_source_names', '1'
  UNION ALL SELECT 'public_report_show_full_document', '1'
  UNION ALL SELECT 'public_report_default_mode', 'workspace'
  UNION ALL SELECT 'internet_check_enabled', '1'
  UNION ALL SELECT 'internet_check_provider', 'seed_urls'
  UNION ALL SELECT 'internet_check_api_key_env', 'AISS_INTERNET_API_KEY'
  UNION ALL SELECT 'internet_check_api_key', ''
  UNION ALL SELECT 'internet_check_max_queries', '3'
  UNION ALL SELECT 'internet_check_max_sources', '5'
  UNION ALL SELECT 'internet_check_max_chars_per_source', '12000'
  UNION ALL SELECT 'internet_check_payload_policy', 'snippets_only'
  UNION ALL SELECT 'internet_check_auto_run_when_no_sources', '1'
  UNION ALL SELECT 'internet_check_allow_full_document_query', '1'
  UNION ALL SELECT 'internet_check_store_retrieved_text', '1'
  UNION ALL SELECT 'internet_check_seed_urls', ''
  UNION ALL SELECT 'internet_check_disclosure_visible', '1'
  UNION ALL SELECT 'report_ai_narrative_enabled', '1'
) d
WHERE NOT EXISTS (
  SELECT 1 FROM ac_similarity_settings s
  WHERE s.tenant_id = t.tenant_id AND s.setting_key = d.setting_key
);
