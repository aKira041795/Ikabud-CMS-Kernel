-- ============================================================
-- Ecommerce Module — CMS Categories Taxonomy Column
-- Adds taxonomy column to cms_categories to distinguish product
-- categories from blog/content categories.
-- Products use taxonomy = 'product'; default is 'default'.
-- This migration is idempotent and safe to re-run.
-- ============================================================

SET @has_taxonomy_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cms_categories'
      AND COLUMN_NAME = 'taxonomy'
);

SET @add_taxonomy_column_sql := IF(
    @has_taxonomy_column = 0,
    'ALTER TABLE cms_categories ADD COLUMN taxonomy VARCHAR(50) NOT NULL DEFAULT ''default'' COMMENT ''Category taxonomy namespace: default, product, etc.'' AFTER slug',
    'SELECT 1'
);

PREPARE add_taxonomy_column_stmt FROM @add_taxonomy_column_sql;
EXECUTE add_taxonomy_column_stmt;
DEALLOCATE PREPARE add_taxonomy_column_stmt;

SET @has_taxonomy_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cms_categories'
      AND INDEX_NAME = 'idx_cms_categories_taxonomy'
);

SET @add_taxonomy_index_sql := IF(
    @has_taxonomy_index = 0,
    'ALTER TABLE cms_categories ADD INDEX idx_cms_categories_taxonomy (taxonomy)',
    'SELECT 1'
);

PREPARE add_taxonomy_index_stmt FROM @add_taxonomy_index_sql;
EXECUTE add_taxonomy_index_stmt;
DEALLOCATE PREPARE add_taxonomy_index_stmt;
