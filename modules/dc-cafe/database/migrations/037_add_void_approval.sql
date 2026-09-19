-- 037_add_void_approval.sql
-- Supervisor approval for voiding a sale.
--
-- A void returns stock and removes revenue, so it is a classic way to take cash
-- out of a drawer. The cashier may start a void, but it only completes when an
-- administrator or supervisor supplies their own void PIN.
--
-- The PIN is stored as a bcrypt hash, matching dc_users.password_hash. It is
-- deliberately NOT a module setting: settings are stored as plain text, so a
-- password kept there would be readable by anyone with database access.
--
-- dc_void_attempts exists to stop a short PIN being brute-forced. Without a
-- durable counter a 4-digit PIN is only 10,000 guesses away.
--
-- @mysql57-compat: ALTER guarded via information_schema, InnoDB + utf8mb4.

-- ── Per-user void PIN ──────────────────────────────────────────────────────
SET @void_pin_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_users'
      AND column_name = 'void_pin_hash'
);

SET @sql := IF(@void_pin_exists = 0,
    'ALTER TABLE `dc_users`
     ADD COLUMN `void_pin_hash` VARCHAR(255) DEFAULT NULL AFTER `password_hash`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Failed-approval throttle ───────────────────────────────────────────────
-- store_id is a plain INT (no FK) on purpose: the column type of
-- dc_orders.store_id would have to match exactly, and a mismatch fails silently
-- on MyISAM. The throttle only ever reads recent rows by store and time.
CREATE TABLE IF NOT EXISTS `dc_void_attempts` (
  `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL DEFAULT 0,
  `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_dc_void_attempts_window` (`store_id`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
