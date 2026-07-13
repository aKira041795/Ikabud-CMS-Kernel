-- Kernel — Enforce case-sensitive usernames for authentication integrity.
-- Bluehost-safe ALTER TABLE (no window functions, no CTEs).

ALTER TABLE users MODIFY username VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
