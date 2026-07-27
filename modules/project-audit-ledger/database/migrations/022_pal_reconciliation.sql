-- PAL — Schema reconciliation migration
--
-- Fixes verified schema issues discovered during the architecture realignment:
--
-- 1. Adds tenant_id to pal_purchase_items and pal_material_issuance_items
--    (approval queries reference these columns but they don't exist in core schema)
--
-- 2. Adds 'pending_approval' to ENUMs for pal_cash_advances, pal_fabrication_payments,
--    and pal_material_issuances (services write this value but schemas don't declare it)
--
-- 3. Normalizes pal_inventory_balances unique key to handle nullable location_id
--    (MySQL allows duplicate (material_id, NULL) rows under the current unique key)
--
-- 4. Adds unique constraint for (tenant_id, email) on pal_team_leads
--    (prevents duplicate team lead records from AW auto-provisioning)
--
-- 5. Adds version column and updated_by to pal_cash_advances and pal_mobilization_requests
--    for compare-and-set concurrency safety
--
-- MySQL 5.7 compatible: uses separate ALTER TABLE statements, no window functions/CTEs.
-- Migration runner handles idempotency via _migrations table.

-- ===================================================================
-- 1. Add tenant_id to child tables (for tenant-scoped approval queries)
-- ===================================================================
-- pal_purchase_items: backfill from parent pal_purchases
ALTER TABLE pal_purchase_items
  ADD COLUMN tenant_id INT UNSIGNED DEFAULT NULL AFTER purchase_id;

UPDATE pal_purchase_items pi
  JOIN pal_purchases p ON pi.purchase_id = p.id
  SET pi.tenant_id = p.tenant_id
  WHERE pi.tenant_id IS NULL;

ALTER TABLE pal_purchase_items
  MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;

ALTER TABLE pal_purchase_items
  ADD INDEX idx_pal_pi_tenant (tenant_id);

-- pal_material_issuance_items: backfill from parent pal_material_issuances
ALTER TABLE pal_material_issuance_items
  ADD COLUMN tenant_id INT UNSIGNED DEFAULT NULL AFTER issuance_id;

UPDATE pal_material_issuance_items mii
  JOIN pal_material_issuances mi ON mii.issuance_id = mi.id
  SET mii.tenant_id = mi.tenant_id
  WHERE mii.tenant_id IS NULL;

ALTER TABLE pal_material_issuance_items
  MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL;

ALTER TABLE pal_material_issuance_items
  ADD INDEX idx_pal_mii_tenant (tenant_id);

-- ===================================================================
-- 2. Add 'pending_approval' to ENUMs that are missing it
-- ===================================================================
-- pal_cash_advances: pending→pending,approved,settled,voided → +pending_approval
ALTER TABLE pal_cash_advances
  MODIFY COLUMN status ENUM('pending','pending_approval','approved','settled','voided')
  NOT NULL DEFAULT 'pending';

-- pal_fabrication_payments: pending→pending,approved,rejected,voided → +pending_approval
ALTER TABLE pal_fabrication_payments
  MODIFY COLUMN status ENUM('pending','pending_approval','approved','rejected','voided')
  NOT NULL DEFAULT 'pending';

-- pal_material_issuances: draft→...,rejected,cancelled → +pending_approval after requested
ALTER TABLE pal_material_issuances
  MODIFY COLUMN status ENUM('draft','requested','pending_approval','approved','partially_issued','fully_issued','rejected','cancelled')
  NOT NULL DEFAULT 'draft';

-- pal_collections: pending→pending,approved,rejected,voided → +pending_approval
ALTER TABLE pal_collections
  MODIFY COLUMN status ENUM('pending','pending_approval','approved','rejected','voided')
  NOT NULL DEFAULT 'pending';

-- ===================================================================
-- 3. Normalize pal_inventory_balances unique key for nullable location
-- ===================================================================
-- MySQL allows duplicate (material_id, NULL) rows under a unique key because
-- NULL != NULL in index comparisons. This causes silent balance duplication.
--
-- Fix: drop old key, add a generated column that coalesces location_id to 0,
-- and create a unique key on (tenant_id, material_id, location_key).
ALTER TABLE pal_inventory_balances
  DROP INDEX uq_pal_ib_mat_loc;

ALTER TABLE pal_inventory_balances
  ADD COLUMN location_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(location_id, 0)) STORED
  AFTER location_id;

ALTER TABLE pal_inventory_balances
  ADD UNIQUE KEY uq_pal_ib_mat_loc_key (tenant_id, material_id, location_key);

-- ===================================================================
-- 4. Add unique constraint for team lead email per tenant
-- ===================================================================
-- Prevents duplicate team lead records from AW auto-provisioning
DELETE t1 FROM pal_team_leads t1
  INNER JOIN pal_team_leads t2
  WHERE t1.id > t2.id
    AND t1.tenant_id = t2.tenant_id
    AND t1.email = t2.email
    AND t1.email IS NOT NULL
    AND t1.email != '';

ALTER TABLE pal_team_leads
  ADD UNIQUE KEY uq_pal_tl_tenant_email (tenant_id, email);

-- ===================================================================
-- 5. Add version + concurrency fields for compare-and-set
-- ===================================================================
-- pal_cash_advances (uses 'description' not 'notes')
-- NOTE: updated_by already exists on pal_cash_advances from migration 010
ALTER TABLE pal_cash_advances
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER description;

-- pal_mobilization_requests (has 'notes' column)
ALTER TABLE pal_mobilization_requests
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER notes,
  ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL AFTER version;
