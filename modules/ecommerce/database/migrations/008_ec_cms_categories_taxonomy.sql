-- ============================================================
-- Ecommerce Module — CMS Categories Taxonomy Column
-- Adds taxonomy column to cms_categories to distinguish product
-- categories from blog/content categories.
-- Products use taxonomy = 'product'; default is 'default'.
-- This migration is idempotent and safe to re-run.
-- ============================================================

-- Add taxonomy column if not present
ALTER TABLE cms_categories
    ADD COLUMN IF NOT EXISTS taxonomy VARCHAR(50) NOT NULL DEFAULT 'default'
        COMMENT 'Category taxonomy namespace: default, product, etc.' AFTER slug;

-- Add index for filtering by taxonomy efficiently
ALTER TABLE cms_categories
    ADD INDEX IF NOT EXISTS idx_cms_categories_taxonomy (taxonomy);
