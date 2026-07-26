-- 027_add_store_coordinates.sql
-- Add latitude/longitude columns to dc_stores for Google Maps integration.
-- @mysql57-compat: InnoDB, ALTER TABLE, DECIMAL(10,7) allows ~1m precision.

ALTER TABLE `dc_stores`
  ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL AFTER `contact_number`,
  ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`;
