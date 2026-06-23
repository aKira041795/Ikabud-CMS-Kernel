-- Add unit conversion support for smart costing
-- Allows materials to define a conversion to a smaller usage unit
-- e.g. tarp roll (144 sq ft/roll) → cost/sq.ft, ink gallon (3785 ml/gal) → cost/ml
ALTER TABLE pal_materials
    ADD COLUMN conversion_unit_id INT UNSIGNED DEFAULT NULL AFTER unit_id,
    ADD COLUMN conversion_factor DECIMAL(18,6) DEFAULT NULL AFTER conversion_unit_id,
    ADD INDEX idx_pal_mat_conv_unit (conversion_unit_id);
