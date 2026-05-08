-- Migration 032: Track actual received quantity on cashier withdrawal delivery records
-- Enables variance tracking for informal branch-to-branch transfer receives.
ALTER TABLE dl_cashier_withdrawals
    ADD COLUMN received_qty INT UNSIGNED NULL DEFAULT NULL AFTER quantity;
