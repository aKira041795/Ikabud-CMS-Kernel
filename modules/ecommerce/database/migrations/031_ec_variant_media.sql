-- ============================================================
-- Ecommerce Module — Variant-Aware Merchandising
-- Creates ec_variant_media (maps cms_media images to product variants).
-- Safe to re-run (idempotent).
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_variant_media (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    variant_id INT UNSIGNED   NOT NULL COMMENT 'ec_product_variants.id',
    media_id   INT UNSIGNED   NOT NULL COMMENT 'cms_media.id',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_ec_vm_variant_media (variant_id, media_id),
    KEY idx_ec_vm_variant_sort (variant_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
