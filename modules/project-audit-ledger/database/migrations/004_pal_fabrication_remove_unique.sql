-- Remove UNIQUE constraint on pal_fabrication_allocations.project_id
-- Allows multiple CA dispenses per project (each dispense = one row)
ALTER TABLE pal_fabrication_allocations DROP INDEX uq_pal_fa_project;
