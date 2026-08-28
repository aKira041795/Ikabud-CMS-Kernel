-- HARPP risk-tiered action + audit gate (S3).
-- A run whose completion result is classified HIGH/CRITICAL risk must NOT silently
-- reach SUCCEEDED. transition() parks such a run in AWAITING_APPROVAL and stores a
-- hashed owner approval token; approveRun() (owner/admin via the bridge) promotes it
-- to SUCCEEDED and auto-builds the reviewable artifact bundle, while rejectRun()
-- revokes it to CANCELLED. Additive, tenant-scoped, MySQL 5.7-safe.
-- Note: sandboxing of agent execution is OUT of scope for this slice (documented as
-- opt-in future work only); this gate is an approval/audit control, not a sandbox.

-- Additive risk-gate columns on harpp_work_runs.
ALTER TABLE `harpp_work_runs`
    ADD COLUMN `risk_level` ENUM('LOW','MEDIUM','HIGH','CRITICAL') NULL DEFAULT NULL AFTER `state`,
    ADD COLUMN `approval_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `risk_level`,
    ADD COLUMN `approval_token_hash` CHAR(64) NULL DEFAULT NULL AFTER `approval_required`,
    ADD COLUMN `approved_by` INT UNSIGNED NULL DEFAULT NULL AFTER `approval_token_hash`,
    ADD COLUMN `approved_at` DATETIME(6) NULL DEFAULT NULL AFTER `approved_by`;

-- Extend the state enum with the non-terminal AWAITING_APPROVAL gate state.
ALTER TABLE `harpp_work_runs`
    MODIFY COLUMN `state` ENUM('QUEUED','WAITING_FOR_RUNNER','CLAIMED','RUNNING','STALLED','AWAITING_APPROVAL','SUCCEEDED','FAILED','CANCELLED') NOT NULL DEFAULT 'QUEUED';
