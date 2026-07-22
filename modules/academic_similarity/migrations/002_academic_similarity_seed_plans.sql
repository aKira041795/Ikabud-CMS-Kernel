-- Academic Similarity Module v1.0.0 — Seed Subscription Plans

INSERT IGNORE INTO ac_similarity_plans (code, name, description, monthly_submissions_limit, daily_submissions_limit, max_file_size_mb, max_word_count, source_repository_limit, semantic_enabled, retention_days, price_monthly, price_yearly, sort_order) VALUES
('starter', 'Starter', 'For small institutions getting started with similarity checking', 100, 10, 10, 10000, 500, 0, 90, 0.00, 0.00, 0),
('academic', 'Academic', 'Standard plan for most institutions', 500, 25, 20, 25000, 2000, 0, 365, 99.00, 990.00, 1),
('professional', 'Professional', 'For institutions with higher submission volumes', 2000, 100, 50, 50000, 10000, 1, 730, 299.00, 2990.00, 2),
('enterprise', 'Enterprise', 'Unlimited submissions with full feature access', 999999, 9999, 100, 100000, 999999, 1, 1095, 999.00, 9990.00, 3);

-- Seed default retention policies
INSERT IGNORE INTO ac_similarity_retention_policies (tenant_id, institution_id, data_category, retention_days, purge_after_days) VALUES
('system', 0, 'submissions', 365, 730),
('system', 0, 'sources', 365, 730),
('system', 0, 'reports', 365, 730),
('system', 0, 'audit', 1095, 1825);

-- Seed default model profile (placeholder for future semantic matching)
INSERT IGNORE INTO ac_similarity_model_profiles (tenant_id, name, provider, model_name, model_version, embedding_dimensions, max_tokens, is_active) VALUES
('system', 'Default Embedding Model', 'openai', 'text-embedding-3-small', '1', 1536, 8191, 0);
