-- Academic Thesis Evaluation — Audit Events
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_audit_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       VARCHAR(64) NOT NULL,
    case_id         INT UNSIGNED DEFAULT NULL,
    actor_id        INT UNSIGNED NOT NULL,
    actor_role      VARCHAR(100) DEFAULT NULL,
    action          VARCHAR(100) NOT NULL COMMENT 'case_created, manuscript_uploaded, stage_transitioned, reviewer_assigned, reviewer_accepted, reviewer_removed, aiss_snapshot_generated, machine_candidate_recorded, evidence_reclassified, rubric_submitted, rubric_changed, revision_requested, revision_resolved, disposition_issued',
    before_state    JSON DEFAULT NULL,
    after_state     JSON DEFAULT NULL,
    reason          TEXT,
    request_id      VARCHAR(64) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_tenant (tenant_id),
    KEY idx_audit_case (case_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
