-- ============================================================
-- Migration 085: per-product consignee assignment intent.
--
-- Slice 7: the product modal's assignment control covers consignees as
-- well as branches. dl_products.assignment_mode already stores the branch
-- intent (all_active|specific); this twin column stores the consignee
-- intent so a product set to "specific" is NEVER auto-assigned to a
-- consignee created later, and so "all active branches and consignees"
-- remains additively materialised for later consignees.
--
-- The ENUM matches dl_products.assignment_mode exactly and is the last
-- line of defence: a normalizer bypass cannot store an unrecognised value.
-- Existing rows inherit the DEFAULT ('all_active') from ADD COLUMN, which
-- preserves today's behaviour and needs no backfill.
--
-- MySQL 5.7 compatibility is INSPECTION-ONLY (no local 5.7 server). The
-- guarded ADD COLUMN uses the SET @sql=IF(...)+PREPARE pattern of 081-084,
-- no window functions, no CTEs, no JSON_TABLE, InnoDB, re-run safe.
-- ============================================================

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_products' AND column_name = 'consignee_assignment_mode'),
    'SELECT 1',
    'ALTER TABLE dl_products ADD COLUMN consignee_assignment_mode ENUM(''all_active'',''specific'') NOT NULL DEFAULT ''all_active'' AFTER assignment_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
