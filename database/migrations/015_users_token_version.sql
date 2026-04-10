-- Migration 015: Add token_version to kernel users table
-- Used to invalidate JWTs when a user changes their password.
-- On password change, token_version is incremented; existing tokens with
-- an older version are rejected in App::user().

ALTER TABLE users
    ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0;
