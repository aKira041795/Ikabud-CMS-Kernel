CREATE TABLE IF NOT EXISTS ec_product_relations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    related_product_id INT UNSIGNED NOT NULL,
    relation_type ENUM('related', 'upsell', 'cross_sell') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ec_product_relation (product_id, related_product_id, relation_type),
    KEY idx_ec_product_relation_product (product_id, relation_type, sort_order),
    KEY idx_ec_product_relation_related (related_product_id),
    CONSTRAINT fk_ec_product_relations_product FOREIGN KEY (product_id) REFERENCES cms_content(id) ON DELETE CASCADE,
    CONSTRAINT fk_ec_product_relations_related_product FOREIGN KEY (related_product_id) REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;