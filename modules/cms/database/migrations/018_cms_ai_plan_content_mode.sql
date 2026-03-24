ALTER TABLE cms_ai_content_plans
    ADD COLUMN content_mode VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER content_type;
