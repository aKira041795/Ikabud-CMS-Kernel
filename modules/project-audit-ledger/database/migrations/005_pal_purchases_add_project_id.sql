ALTER TABLE pal_purchases ADD COLUMN project_id INT UNSIGNED DEFAULT NULL AFTER supplier_id;
ALTER TABLE pal_purchases ADD INDEX idx_pal_purch_project (project_id);
