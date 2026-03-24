ALTER TABLE cms_ai_content_plans
    ADD COLUMN search_grounding_enabled TINYINT(1) NULL DEFAULT NULL AFTER seo_enabled;
