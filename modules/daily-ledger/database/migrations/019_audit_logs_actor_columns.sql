-- Daily Ledger migration 019 — RETIRED (tracked no-op)
--
-- This migration previously ALTERed the kernel-owned `audit_logs` table to add
-- `actor_module_user_id` and `actor_source` columns. Module migrations must not
-- modify kernel-owned tables (migration ownership gate), and kernel artifact
-- 018_audit_logs_actor_columns_ensure.sql is now the authoritative source of
-- those columns (applied via tenantSyncKernelMigrations before module
-- migrations).
--
-- The filename is preserved as a tracked no-op so:
--   * fresh installs record this migration as applied without doing anything;
--   * already-tracked tenants keep their _migrations history intact (the row is
--     already present, so this file never re-runs for them).
--
-- Kernel artifact 018 guarantees the columns exist on every tenant DB.
SELECT 1;

