-- 098: Product categories are tenant data. Keep product_category as a rollback-safe mirror.
-- MySQL 5.7 / rerun-safe.

CREATE TABLE IF NOT EXISTS dl_product_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    `grouping` ENUM('bread','non_bread') NOT NULL DEFAULT 'non_bread',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dl_prod_cat_code (code),
    KEY idx_dl_prod_cat_group (`grouping`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dl_product_categories (code, label, `grouping`, sort_order, is_active)
VALUES ('bread','Bread','bread',10,1), ('cake','Cake','non_bread',20,1), ('other','Other','non_bread',30,1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label), `grouping` = VALUES(`grouping`), sort_order = VALUES(sort_order);

-- The mirror must be able to hold newly-created category codes (it was ENUM), and it must
-- carry the SAME collation as dl_product_categories.code. An ENUM converted without an
-- explicit collation inherits the table's, which on a MySQL 8 server is utf8mb4_0900_ai_ci
-- while this module declares utf8mb4_unicode_ci - any SQL comparing the mirror to a
-- category code then dies with 1267 (Illegal mix of collations). Measured on tenant 207.
SET @dl_product_category_needs_collation := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_products'
       AND column_name = 'product_category'
       AND NOT (data_type = 'varchar' AND collation_name = 'utf8mb4_unicode_ci')
);
SET @dl_product_category_mirror_ddl := IF(
    @dl_product_category_needs_collation > 0,
    'ALTER TABLE dl_products MODIFY COLUMN product_category VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''bread''',
    'SELECT 1'
);
PREPARE dl_product_category_mirror_st FROM @dl_product_category_mirror_ddl;
EXECUTE dl_product_category_mirror_st;
DEALLOCATE PREPARE dl_product_category_mirror_st;

SET @dl_category_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_products' AND column_name = 'category_id'
);
SET @dl_category_id_ddl := IF(
    @dl_category_id_exists = 0,
    'ALTER TABLE dl_products ADD COLUMN category_id INT UNSIGNED NULL AFTER product_category',
    'SELECT 1'
);
PREPARE dl_category_id_st FROM @dl_category_id_ddl;
EXECUTE dl_category_id_st;
DEALLOCATE PREPARE dl_category_id_st;

SET @dl_category_id_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'dl_products'
       AND index_name = 'idx_dl_products_category_id'
);
SET @dl_category_id_index_ddl := IF(
    @dl_category_id_index_exists = 0,
    'ALTER TABLE dl_products ADD INDEX idx_dl_products_category_id (category_id)',
    'SELECT 1'
);
PREPARE dl_category_id_idx FROM @dl_category_id_index_ddl;
EXECUTE dl_category_id_idx;
DEALLOCATE PREPARE dl_category_id_idx;

SET @dl_category_id_fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'dl_products'
       AND constraint_name = 'fk_dl_products_category' AND constraint_type = 'FOREIGN KEY'
);
SET @dl_category_id_fk_ddl := IF(
    @dl_category_id_fk_exists = 0,
    'ALTER TABLE dl_products ADD CONSTRAINT fk_dl_products_category FOREIGN KEY (category_id) REFERENCES dl_product_categories(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE dl_category_id_fk FROM @dl_category_id_fk_ddl;
EXECUTE dl_category_id_fk;
DEALLOCATE PREPARE dl_category_id_fk;

UPDATE dl_products p
INNER JOIN dl_product_categories c
        ON c.code = CONVERT(p.product_category USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET p.category_id = c.id
WHERE p.category_id IS NULL;
