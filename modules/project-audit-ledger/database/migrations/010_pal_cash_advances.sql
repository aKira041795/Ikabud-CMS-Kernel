SET FOREIGN_KEY_CHECKS = 0;

-- 34. Cash advances (per team lead)
CREATE TABLE IF NOT EXISTS pal_cash_advances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    team_lead_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    advance_date DATE NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','settled','voided') NOT NULL DEFAULT 'pending',
    settled_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pal_ca_tenant (tenant_id),
    INDEX idx_pal_ca_teamlead (team_lead_id),
    INDEX idx_pal_ca_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
