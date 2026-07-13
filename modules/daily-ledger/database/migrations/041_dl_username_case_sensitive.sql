-- Daily Ledger — Enforce case-sensitive usernames for all DL auth tables.
-- Bluehost-safe ALTER TABLE (no window functions, no CTEs).

ALTER TABLE dl_users    MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_admins   MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_cashiers MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_supervisors MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE dl_production_incharges MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
