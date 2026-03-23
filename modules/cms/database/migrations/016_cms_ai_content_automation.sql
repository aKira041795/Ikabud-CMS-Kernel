CREATE TABLE IF NOT EXISTS cms_ai_content_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic VARCHAR(255) NOT NULL,
    content_type VARCHAR(50) NOT NULL DEFAULT 'post',
    prompt_template MEDIUMTEXT NOT NULL,
    writing_style VARCHAR(255) NOT NULL DEFAULT '',
    target_audience VARCHAR(255) DEFAULT NULL,
    keywords_json JSON DEFAULT NULL,
    summary_enabled TINYINT(1) NOT NULL DEFAULT 1,
    seo_enabled TINYINT(1) NOT NULL DEFAULT 1,
    visual_mode VARCHAR(32) NOT NULL DEFAULT 'suggest_media',
    cadence VARCHAR(32) NOT NULL DEFAULT 'manual',
    cadence_interval INT UNSIGNED NOT NULL DEFAULT 1,
    publish_offset_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME DEFAULT NULL,
    last_run_at DATETIME DEFAULT NULL,
    last_generated_content_id INT UNSIGNED DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cms_ai_content_plans_active_next_run (is_active, next_run_at),
    KEY idx_cms_ai_content_plans_content_type (content_type),
    KEY idx_cms_ai_content_plans_created_by (created_by),
    CONSTRAINT fk_cms_ai_content_plans_created_by
        FOREIGN KEY (created_by) REFERENCES cms_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_cms_ai_content_plans_updated_by
        FOREIGN KEY (updated_by) REFERENCES cms_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_cms_ai_content_plans_last_content
        FOREIGN KEY (last_generated_content_id) REFERENCES cms_content(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_ai_content_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id BIGINT UNSIGNED NOT NULL,
    content_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    topic_snapshot VARCHAR(255) NOT NULL,
    prompt_snapshot MEDIUMTEXT NOT NULL,
    keywords_json JSON DEFAULT NULL,
    response_json LONGTEXT DEFAULT NULL,
    summary_text TEXT DEFAULT NULL,
    seo_title VARCHAR(255) DEFAULT NULL,
    seo_description VARCHAR(500) DEFAULT NULL,
    visual_suggestions_json JSON DEFAULT NULL,
    desired_publish_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cms_ai_content_runs_plan_id (plan_id),
    KEY idx_cms_ai_content_runs_status (status),
    KEY idx_cms_ai_content_runs_started_at (started_at),
    KEY idx_cms_ai_content_runs_content_id (content_id),
    CONSTRAINT fk_cms_ai_content_runs_plan
        FOREIGN KEY (plan_id) REFERENCES cms_ai_content_plans(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_cms_ai_content_runs_content
        FOREIGN KEY (content_id) REFERENCES cms_content(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;