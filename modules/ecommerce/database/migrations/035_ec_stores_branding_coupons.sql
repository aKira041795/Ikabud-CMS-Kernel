-- ============================================================
-- Ecommerce Module — Store Branding
-- Adds banner_image_id, logo_image_id, announcement to ec_stores.
-- Safe to re-run (idempotent via SET + PREPARE pattern).
-- ============================================================

SET @has_banner = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_stores'
      AND COLUMN_NAME  = 'banner_image_id'
);

SET @sql = IF(@has_banner = 0,
    'ALTER TABLE ec_stores
     ADD COLUMN banner_image_id  INT UNSIGNED NULL DEFAULT NULL AFTER is_default,
     ADD COLUMN logo_image_id    INT UNSIGNED NULL DEFAULT NULL AFTER banner_image_id,
     ADD COLUMN announcement     TEXT         NULL              AFTER logo_image_id',
    'SELECT "branding columns already exist on ec_stores"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Store-scoped coupons ───────────────────────────────────

SET @has_coupon_store = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_coupons'
      AND COLUMN_NAME  = 'store_id'
);

SET @sql2 = IF(@has_coupon_store = 0,
    'ALTER TABLE ec_coupons
     ADD COLUMN store_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
     ADD KEY idx_ec_coupons_store_id (store_id)',
    'SELECT "store_id column already exists on ec_coupons"'
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
