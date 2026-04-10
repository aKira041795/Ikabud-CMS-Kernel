-- ============================================================
-- Ecommerce Module — Grouped Products
-- Stores child products and default quantities for grouped product pages.
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_product_group_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    child_product_id INT NOT NULL,
    child_qty INT NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_group_child (product_id, child_product_id),
    KEY idx_group_product (product_id),
    KEY idx_group_child (child_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;