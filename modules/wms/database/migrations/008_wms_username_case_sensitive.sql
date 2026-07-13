-- WMS — Enforce case-sensitive usernames for authentication integrity.
-- Bluehost-safe ALTER TABLE (no window functions, no CTEs).

ALTER TABLE wms_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
