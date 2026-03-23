-- ═══════════════════════════════════════════════════════════════
-- CMS Page Builder — Canonical Builder Document Foundation
-- Introduces dedicated builder documents alongside the existing
-- transitional meta/blocks-based builder implementation.
-- ═══════════════════════════════════════════════════════════════

SET @cms_has_content_mode_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_content'
      AND column_name = 'content_mode'
);
SET @cms_add_content_mode_col_sql := IF(
    @cms_has_content_mode_col = 0,
    'ALTER TABLE cms_content ADD COLUMN content_mode VARCHAR(20) NOT NULL DEFAULT ''standard'' AFTER type',
    'SELECT 1'
);
PREPARE cms_add_content_mode_col_stmt FROM @cms_add_content_mode_col_sql;
EXECUTE cms_add_content_mode_col_stmt;
DEALLOCATE PREPARE cms_add_content_mode_col_stmt;

SET @cms_has_builder_document_id_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_content'
      AND column_name = 'builder_document_id'
);
SET @cms_add_builder_document_id_col_sql := IF(
    @cms_has_builder_document_id_col = 0,
    'ALTER TABLE cms_content ADD COLUMN builder_document_id INT UNSIGNED DEFAULT NULL AFTER body',
    'SELECT 1'
);
PREPARE cms_add_builder_document_id_col_stmt FROM @cms_add_builder_document_id_col_sql;
EXECUTE cms_add_builder_document_id_col_stmt;
DEALLOCATE PREPARE cms_add_builder_document_id_col_stmt;

SET @cms_has_idx_content_mode := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_content'
      AND index_name = 'idx_cms_content_mode'
);
SET @cms_add_idx_content_mode_sql := IF(
    @cms_has_idx_content_mode = 0,
    'ALTER TABLE cms_content ADD KEY idx_cms_content_mode (content_mode)',
    'SELECT 1'
);
PREPARE cms_add_idx_content_mode_stmt FROM @cms_add_idx_content_mode_sql;
EXECUTE cms_add_idx_content_mode_stmt;
DEALLOCATE PREPARE cms_add_idx_content_mode_stmt;

SET @cms_has_idx_builder_document_id := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_content'
      AND index_name = 'idx_cms_builder_document_id'
);
SET @cms_add_idx_builder_document_id_sql := IF(
    @cms_has_idx_builder_document_id = 0,
    'ALTER TABLE cms_content ADD KEY idx_cms_builder_document_id (builder_document_id)',
    'SELECT 1'
);
PREPARE cms_add_idx_builder_document_id_stmt FROM @cms_add_idx_builder_document_id_sql;
EXECUTE cms_add_idx_builder_document_id_stmt;
DEALLOCATE PREPARE cms_add_idx_builder_document_id_stmt;

CREATE TABLE IF NOT EXISTS cms_builder_documents (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id       INT UNSIGNED NOT NULL,
    schema_version   VARCHAR(20) NOT NULL DEFAULT '1.0',
    document_version INT UNSIGNED NOT NULL DEFAULT 1,
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    title            VARCHAR(255) NOT NULL,
    document_json    LONGTEXT NOT NULL,
    render_hash      CHAR(64) DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    updated_by       INT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_builder_doc_content_status (content_id, status),
    INDEX idx_cms_builder_doc_render_hash (render_hash),
    CONSTRAINT fk_cms_builder_doc_content FOREIGN KEY (content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_builder_revisions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    builder_document_id INT UNSIGNED NOT NULL,
    revision_number     INT UNSIGNED NOT NULL,
    snapshot_json       LONGTEXT NOT NULL,
    note                VARCHAR(255) DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_builder_doc_revision (builder_document_id, revision_number),
    CONSTRAINT fk_cms_builder_revision_doc FOREIGN KEY (builder_document_id)
        REFERENCES cms_builder_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_builder_reusable_sections (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(255) NOT NULL,
    scope         ENUM('personal','shared','global') NOT NULL DEFAULT 'shared',
    fragment_json LONGTEXT NOT NULL,
    created_by    INT UNSIGNED DEFAULT NULL,
    updated_by    INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_builder_reusable_slug (slug),
    INDEX idx_cms_builder_reusable_scope (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_builder_templates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(255) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    category      VARCHAR(100) NOT NULL DEFAULT 'page',
    preview_image VARCHAR(255) DEFAULT NULL,
    template_json LONGTEXT NOT NULL,
    is_system     TINYINT(1) NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_builder_template_slug (slug),
    INDEX idx_cms_builder_template_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cms_builder_templates (slug, name, category, preview_image, template_json, is_system, created_by, created_at, updated_at)
VALUES
(
    'starter-hero',
    'Starter Hero',
    'page',
    NULL,
    '{"schema_version":"1.0","document":{"id":"doc_root","type":"document","kind":"document","version":1,"props":{"title":"Starter Hero"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[{"id":"hero_heading","type":"heading","kind":"widget","version":1,"props":{"text":"Freshly baked every day","level":"h1"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]},{"id":"hero_text","type":"text","kind":"widget","version":1,"props":{"html":"<p>Use this starter hero to launch a high-impact landing page.</p>"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]},{"id":"hero_button","type":"button","kind":"widget","version":1,"props":{"text":"Shop Now","url":"/cms","style":"primary"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]}]}}',
    1,
    NULL,
    NOW(),
    NOW()
),
(
    'starter-cta',
    'Starter CTA',
    'page',
    NULL,
    '{"schema_version":"1.0","document":{"id":"doc_root","type":"document","kind":"document","version":1,"props":{"title":"Starter CTA"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[{"id":"cta_heading","type":"heading","kind":"widget","version":1,"props":{"text":"Order your favorites today","level":"h2"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]},{"id":"cta_text","type":"text","kind":"widget","version":1,"props":{"html":"<p>Simple call-to-action layout for pages that need a clear next step.</p>"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]},{"id":"cta_button","type":"button","kind":"widget","version":1,"props":{"text":"Contact Us","url":"/cms/search","style":"secondary"},"style":{},"responsive":{},"visibility":{},"meta":{},"children":[]}]}}',
    1,
    NULL,
    NOW(),
    NOW()
);
