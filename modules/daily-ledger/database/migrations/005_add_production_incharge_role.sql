-- ============================================================
-- Daily Ledger Module — Add Production In-charge Role
-- Introduces role table and branch-scoping map for production workflows.
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_production_incharges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_prod_incharges_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_production_incharge_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_incharge_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_prod_incharge_branch (production_incharge_id, branch_id),
    INDEX idx_dl_pib_branch (branch_id),
    CONSTRAINT fk_dl_pib_incharge FOREIGN KEY (production_incharge_id) REFERENCES dl_production_incharges(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_pib_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
