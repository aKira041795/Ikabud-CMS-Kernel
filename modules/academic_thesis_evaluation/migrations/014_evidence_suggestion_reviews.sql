-- Academic Thesis Evaluation — AISS Suggestion Review Lifecycle
-- Human disposition of machine suggestions. Machine evidence remains immutable.

CREATE TABLE IF NOT EXISTS ate_evidence_suggestion_reviews (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               VARCHAR(64) NOT NULL,
    evaluation_case_id      INT UNSIGNED NOT NULL,
    evidence_snapshot_id    INT UNSIGNED NOT NULL,
    machine_suggestion_id   INT UNSIGNED DEFAULT NULL,
    suggestion_key          VARCHAR(128) NOT NULL,
    machine_category        VARCHAR(80) NOT NULL,
    machine_priority        VARCHAR(20) NOT NULL DEFAULT 'medium',
    machine_action          VARCHAR(80) NOT NULL,
    machine_title           VARCHAR(255) NOT NULL,
    machine_rationale       TEXT NOT NULL,
    reviewer_status         ENUM('pending','accepted','edited','dismissed','converted_to_revision') NOT NULL DEFAULT 'pending',
    reviewer_title          VARCHAR(255) DEFAULT NULL,
    reviewer_rationale      TEXT DEFAULT NULL,
    reviewer_reason         TEXT DEFAULT NULL,
    rubric_criterion_id     INT UNSIGNED DEFAULT NULL,
    revision_request_id     INT UNSIGNED DEFAULT NULL,
    reviewer_id             INT UNSIGNED NOT NULL,
    version                 INT UNSIGNED NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_suggestion_case (tenant_id, evaluation_case_id),
    KEY idx_suggestion_snapshot (tenant_id, evidence_snapshot_id),
    KEY idx_suggestion_key (suggestion_key),
    KEY idx_suggestion_status (reviewer_status),
    CONSTRAINT fk_suggestion_snapshot FOREIGN KEY (evidence_snapshot_id) REFERENCES ate_aiss_evidence_snapshots(id) ON DELETE CASCADE,
    CONSTRAINT fk_suggestion_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
