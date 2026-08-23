ALTER TABLE workflow_runs
    ADD COLUMN payload_hash CHAR(64) NULL DEFAULT NULL,
    ADD COLUMN payload_redacted_at DATETIME NULL DEFAULT NULL;

ALTER TABLE workflow_run_steps
    ADD COLUMN payload_hash CHAR(64) NULL DEFAULT NULL;
