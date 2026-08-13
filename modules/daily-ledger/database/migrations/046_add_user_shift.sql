-- ============================================================
-- Migration 046: Per-user shift assignment (AM / PM cashier)
--
-- Business need: each cashier account is bound to ONE shift.
-- The AM cashier encodes ONLY the AM ledger (and may keep editing it
-- even after the PM shift starts); the PM cashier encodes ONLY the PM
-- ledger. This column lets an admin assign a shift at user creation.
--
-- NULL = unassigned -> the cashier follows the time-based active shift
-- (dl_currentShift()). Admin/supervisor roles are typically left NULL so
-- they keep full control of both shifts.
--
-- Bluehost MySQL 5.7 compatible (plain ALTER TABLE ADD COLUMN).
-- Rerun-safe via the migration runner (applied once per tenant by name).
-- ============================================================

ALTER TABLE dl_users
  ADD COLUMN shift ENUM('AM','PM') DEFAULT NULL AFTER role;
