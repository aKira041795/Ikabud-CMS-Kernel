-- Migration 040: Add liable_user_id to dl_cashier_withdrawals for variance-resolution liability tracking.
-- When a dispatch/receive variance is resolved and the production incharge is found liable,
-- the stock adjustment records who is financially responsible for the missing items.

ALTER TABLE dl_cashier_withdrawals
ADD COLUMN liable_user_id INT UNSIGNED NULL DEFAULT NULL AFTER encoded_by,
ADD INDEX idx_dl_cw_liable (liable_user_id);
