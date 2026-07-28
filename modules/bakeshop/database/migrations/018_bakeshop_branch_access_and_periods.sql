-- Bakeshop — Branch access and operational periods
--
-- Additive, MySQL 5.7 compatible migration.
--
-- 1. bakeshop_user_branches — pivot table granting explicit branch access
-- 2. bakeshop_operational_periods — close/reopen policy for backdated postings

CREATE TABLE IF NOT EXISTS bakeshop_user_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_branch (user_id, branch_id),
    INDEX idx_ub_user (user_id),
    INDEX idx_ub_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bakeshop_operational_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    period_date DATE NOT NULL COMMENT 'The calendar date this period governs',
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_at DATETIME DEFAULT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    reopened_at DATETIME DEFAULT NULL,
    reopened_by INT UNSIGNED DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_branch_date (branch_id, period_date),
    INDEX idx_op_branch (branch_id),
    INDEX idx_op_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
