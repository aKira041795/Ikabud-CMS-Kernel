-- 083: Per-consignee product visibility assignments.
--
-- MySQL 5.7 compatibility is INSPECTION-ONLY (no local 5.7 server). The FK
-- columns are INT UNSIGNED, exactly matching dl_consignees.id/dl_products.id.

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_consignee_products'),
    'SELECT 1',
    'CREATE TABLE dl_consignee_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        consignee_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dl_consignee_product (consignee_id, product_id),
        CONSTRAINT fk_dl_cp_consignee FOREIGN KEY (consignee_id) REFERENCES dl_consignees(id) ON DELETE CASCADE,
        CONSTRAINT fk_dl_cp_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
