-- Academic Thesis Evaluation — Evidence Review Decisions
-- Human interpretation of AISS evidence. Preserves machine output alongside reviewer decisions.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_evidence_review_decisions (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               VARCHAR(64) NOT NULL,
    evaluation_case_id      INT UNSIGNED NOT NULL,
    evidence_snapshot_id    INT UNSIGNED NOT NULL,
    match_id                INT UNSIGNED DEFAULT NULL COMMENT 'AISS match ID this decision applies to',
    machine_relationship    VARCHAR(255) DEFAULT NULL COMMENT 'Machine candidate from AISS, e.g. shared_method_description',
    reviewer_relationship   VARCHAR(255) DEFAULT NULL COMMENT 'Reviewer classification, e.g. common_knowledge',
    reviewer_action         VARCHAR(100) NOT NULL DEFAULT 'acknowledged' COMMENT 'acknowledged, confirmed, rejected, excluded, flagged',
    reviewer_reason         TEXT,
    reviewer_id             INT UNSIGNED NOT NULL,
    confirmed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_decision_case (evaluation_case_id),
    KEY idx_decision_snapshot (evidence_snapshot_id),
    KEY idx_decision_reviewer (reviewer_id),
    CONSTRAINT fk_decision_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_decision_snapshot FOREIGN KEY (evidence_snapshot_id) REFERENCES ate_aiss_evidence_snapshots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
