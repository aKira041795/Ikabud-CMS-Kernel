-- 012_add_product_category.sql

ALTER TABLE dl_products ADD COLUMN product_category ENUM('bread', 'cake', 'other') NOT NULL DEFAULT 'bread' AFTER name;

-- Guess categories based on name for existing seed
UPDATE dl_products SET product_category = 'cake' 
WHERE name LIKE '%cake%' 
   OR name LIKE '%roll%' 
   OR name LIKE '%bar%' 
   OR name LIKE '%brazo%'
   OR name LIKE '%cookies%';

