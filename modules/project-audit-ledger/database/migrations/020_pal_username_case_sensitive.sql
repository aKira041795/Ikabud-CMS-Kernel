-- PAL — Enforce case-sensitive usernames for authentication integrity.
-- utf8mb4_unicode_ci treats 'pAladmin' = 'paladmin', which allows
-- login with any casing variant. utf8mb4_bin enforces exact match.

ALTER TABLE pal_users MODIFY username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
