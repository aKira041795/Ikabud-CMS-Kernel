CREATE TABLE IF NOT EXISTS ec_product_attributes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ec_product_attributes_slug (slug),
    KEY idx_ec_product_attributes_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_product_attribute_values (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    attribute_id INT UNSIGNED NOT NULL,
    value_slug VARCHAR(120) NOT NULL,
    value_label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ec_product_attribute_value (product_id, attribute_id, value_slug),
    KEY idx_ec_product_attribute_values_product (product_id, attribute_id, sort_order),
    KEY idx_ec_product_attribute_values_lookup (attribute_id, value_slug, product_id),
    CONSTRAINT fk_ec_product_attribute_values_product FOREIGN KEY (product_id) REFERENCES cms_content(id) ON DELETE CASCADE,
    CONSTRAINT fk_ec_product_attribute_values_attribute FOREIGN KEY (attribute_id) REFERENCES ec_product_attributes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;