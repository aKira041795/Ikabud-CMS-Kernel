-- Migration 020: Add nullable email to kernel users table
-- Enables kernel forgot-password email delivery and profile email management.

ALTER TABLE users
    ADD COLUMN email VARCHAR(191) NULL DEFAULT NULL AFTER username;

ALTER TABLE users
    ADD UNIQUE KEY users_email_unique (email);