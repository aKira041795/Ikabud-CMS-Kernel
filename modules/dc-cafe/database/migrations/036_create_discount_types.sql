-- 036_create_discount_types.sql
-- Configurable discount types for the till.
--
-- Discounts used to be a hard-coded list in the POS template (Senior, Promo,
-- Sale). Statutory and partner-card discounts differ per branch and per
-- establishment, so the list and the rate attached to each entry belong in
-- data, not in the template.
--
-- The rate here is the SINGLE source of truth for a discount type. The
-- `senior_discount_pct` module setting was removed so a branch edits its
-- Senior rate in exactly one place.
--
-- @mysql57-compat: InnoDB + utf8mb4 required (Bluehost defaults to MyISAM).

CREATE TABLE IF NOT EXISTS `dc_discount_types` (
  `discount_type_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `default_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`discount_type_id`),
  UNIQUE KEY `uk_dc_discount_types_code` (`code`),
  KEY `idx_dc_discount_types_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the types the branches already use, plus the partner-card discount.
-- `default_pct` is editable per branch; the seeded PAG-IBIG rate is a starting
-- value only, since partner rates vary by establishment.
INSERT INTO `dc_discount_types` (`code`, `name`, `default_pct`, `is_active`, `sort_order`)
VALUES
  ('senior',   'Senior Citizen', 20.00, 1, 10),
  ('pagibig',  'PAG-IBIG',        5.00, 1, 20),
  ('pwd',      'PWD',            20.00, 1, 30),
  ('promo',    'Promo',           0.00, 1, 40),
  ('sale',     'Sale',            0.00, 1, 50),
  ('employee', 'Employee',        0.00, 1, 60)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `sort_order` = VALUES(`sort_order`);
