-- PAL — Mobilization attendance/wage evidence snapshot
-- Stores an immutable compact snapshot of the AW attendance/wage evidence
-- used at request time, without duplicating AW records.
-- Fields are nullable because existing mobilization records predate this migration.
-- MySQL 5.7: no IF NOT EXISTS for ALTER TABLE ADD COLUMN — migration runner
-- handles idempotency by checking _migrations table.

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_group_id INT UNSIGNED DEFAULT NULL AFTER project_id;

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_date_from DATE DEFAULT NULL AFTER attendance_group_id;

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_date_to DATE DEFAULT NULL AFTER attendance_date_from;

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_summary_json LONGTEXT DEFAULT NULL AFTER attendance_date_to;

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_evidence_hash VARCHAR(128) DEFAULT NULL AFTER attendance_summary_json;

ALTER TABLE pal_mobilization_requests
  ADD COLUMN attendance_capability_provider VARCHAR(128) DEFAULT NULL AFTER attendance_evidence_hash;

-- Index on attendance_group_id for admin filtering
ALTER TABLE pal_mobilization_requests
  ADD INDEX idx_pal_mob_att_group (attendance_group_id);
