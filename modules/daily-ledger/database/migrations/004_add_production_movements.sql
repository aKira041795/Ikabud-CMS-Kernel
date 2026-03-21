-- ============================================================
-- Daily Ledger Module — Production Movements
--
-- Adds canonical event storage for production withdrawal/output/reverse
-- flows with idempotency support for offline sync.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_production_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_uuid CHAR(36) NOT NULL,
    client_op_id VARCHAR(120) DEFAULT NULL,
    movement_type ENUM('withdrawal','output','reverse') NOT NULL,
    flow_mode ENUM('legacy','production') NOT NULL DEFAULT 'production',
    destination_branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    override_reason VARCHAR(255) DEFAULT NULL,
    reference_movement_id BIGINT UNSIGNED DEFAULT NULL,
    source_payload JSON DEFAULT NULL,
    created_by_id INT UNSIGNED DEFAULT NULL,
    created_by_role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_prod_move_uuid (movement_uuid),
    UNIQUE KEY uq_dl_prod_move_client_op (client_op_id),
    INDEX idx_dl_prod_move_branch_date (destination_branch_id, ledger_date),
    INDEX idx_dl_prod_move_product_date (product_id, ledger_date),
    INDEX idx_dl_prod_move_type_date (movement_type, ledger_date),
    INDEX idx_dl_prod_move_reference (reference_movement_id),
    CONSTRAINT fk_dl_prod_move_branch FOREIGN KEY (destination_branch_id) REFERENCES dl_branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dl_prod_move_product FOREIGN KEY (product_id) REFERENCES dl_products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dl_prod_move_reference FOREIGN KEY (reference_movement_id) REFERENCES dl_production_movements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
