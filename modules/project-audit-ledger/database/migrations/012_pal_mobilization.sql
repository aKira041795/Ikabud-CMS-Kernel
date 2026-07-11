SET FOREIGN_KEY_CHECKS = 0;

-- 35. Mobilization requests (team lead requests for project mobilization funds)
CREATE TABLE IF NOT EXISTS pal_mobilization_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    team_lead_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    request_date DATE NOT NULL,
    purpose VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected','disbursed','voided') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    disbursed_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    approval_id INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_mob_tenant (tenant_id),
    INDEX idx_pal_mob_teamlead (team_lead_id),
    INDEX idx_pal_mob_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
