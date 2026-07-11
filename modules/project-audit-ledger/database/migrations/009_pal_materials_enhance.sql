SET FOREIGN_KEY_CHECKS = 0;

-- Add ZAP-ARTS specific fields to pal_materials
ALTER TABLE pal_materials
    ADD COLUMN brand VARCHAR(100) DEFAULT NULL AFTER name,
    ADD COLUMN color VARCHAR(50) DEFAULT NULL AFTER brand,
    ADD COLUMN default_width DECIMAL(10,2) DEFAULT NULL AFTER unit_id,
    ADD COLUMN default_height DECIMAL(10,2) DEFAULT NULL AFTER default_width,
    ADD COLUMN price_per_unit DECIMAL(18,2) DEFAULT NULL AFTER current_avg_cost,
    ADD COLUMN price_per_sqft DECIMAL(18,2) DEFAULT NULL AFTER price_per_unit;

SET FOREIGN_KEY_CHECKS = 1;
