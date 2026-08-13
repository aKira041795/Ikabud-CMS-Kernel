-- ============================================================
-- Migration 044: Shift-period stock ledger (AM/PM)
--
-- Business need: a branch runs two cashier shifts (AM and PM); each
-- shift encodes its OWN ledger process (its own beginning/ending
-- physical counts, its own adjustments, its own derived sales) and
-- does NOT continue the other shift's encoding.
--
-- This migration adds the `shift` dimension to dl_daily_ledger.
-- The unique-key rebuild (so one product-day can hold an AM and a PM
-- row) lives in migration 045, which handles legacy index names.
--
-- Existing rows are backfilled to 'AM' (column default) so pre-shift
-- data keeps the exact same single-row semantics and all prior
-- queries (which default to shift='AM') behave identically.
--
-- Bluehost MySQL 5.7 compatible (plain ALTER TABLE ADD COLUMN).
-- Rerun-safe via the migration runner (applied once per tenant by name).
-- ============================================================

ALTER TABLE dl_daily_ledger
  ADD COLUMN shift ENUM('AM','PM') NOT NULL DEFAULT 'AM' AFTER ledger_date;
