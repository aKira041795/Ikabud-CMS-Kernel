-- Tier 4.9: POS Expansion Foundation
-- Adds additional session types and hardware integration support to the POS subsystem.

-- POS terminal configuration (hardware profiles)
CREATE TABLE IF NOT EXISTS ec_pos_terminals (
    id              INTEGER PRIMARY KEY AUTO_INCREMENT,
    store_id        INT DEFAULT NULL,
    terminal_name   VARCHAR(100) NOT NULL,
    terminal_type   ENUM('register','kiosk','mobile','tablet') NOT NULL DEFAULT 'register',
    hardware_config JSON DEFAULT NULL,              -- printer, scanner, display settings
    payment_methods JSON DEFAULT NULL,              -- allowed payment types for this terminal
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pos_term_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- POS cash drawer tracking
CREATE TABLE IF NOT EXISTS ec_pos_cash_drawers (
    id              INTEGER PRIMARY KEY AUTO_INCREMENT,
    session_id      INT NOT NULL,                   -- FK to ec_pos_sessions.id
    terminal_id     INT DEFAULT NULL,               -- FK to ec_pos_terminals.id
    opening_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    closing_amount  DECIMAL(12,2) DEFAULT NULL,
    expected_amount DECIMAL(12,2) DEFAULT NULL,
    variance        DECIMAL(12,2) DEFAULT NULL,
    opened_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at       DATETIME DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    INDEX idx_drawer_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- POS transaction payments (split-tender support)
CREATE TABLE IF NOT EXISTS ec_pos_payments (
    id              INTEGER PRIMARY KEY AUTO_INCREMENT,
    session_id      INT NOT NULL,
    order_id        INT DEFAULT NULL,
    payment_type    ENUM('cash','card','mobile','gift_card','store_credit','other') NOT NULL DEFAULT 'cash',
    amount          DECIMAL(12,2) NOT NULL,
    currency        VARCHAR(3) NOT NULL DEFAULT 'USD',
    reference       VARCHAR(200) DEFAULT NULL,      -- card auth code, mobile txn id, etc.
    status          ENUM('pending','completed','voided','refunded') DEFAULT 'completed',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pp_session (session_id),
    INDEX idx_pp_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
