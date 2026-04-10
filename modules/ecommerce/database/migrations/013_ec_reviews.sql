CREATE TABLE IF NOT EXISTS ec_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL DEFAULT NULL,
    guest_name VARCHAR(120) NOT NULL DEFAULT '',
    guest_email VARCHAR(191) NOT NULL DEFAULT '',
    rating TINYINT UNSIGNED NOT NULL,
    review_body TEXT NOT NULL,
    verified_purchase TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending',
    moderated_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    moderated_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ec_reviews_product_status_created (product_id, status, created_at),
    KEY idx_ec_reviews_customer (customer_id),
    KEY idx_ec_reviews_guest_email (guest_email),
    CONSTRAINT fk_ec_reviews_product FOREIGN KEY (product_id)
        REFERENCES cms_content (id) ON DELETE CASCADE,
    CONSTRAINT fk_ec_reviews_customer FOREIGN KEY (customer_id)
        REFERENCES cms_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ec_reviews_moderated_by FOREIGN KEY (moderated_by_user_id)
        REFERENCES cms_users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ec_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
