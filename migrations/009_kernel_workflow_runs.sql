-- workflow_runs depends on workflow_definitions which lives in the workflow
-- module.  The module migration runs after kernel migrations, so we
-- temporarily disable FK checks during CREATE.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS workflow_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_key VARCHAR(100) NOT NULL,
    module VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(191) DEFAULT NULL,
    definition_id BIGINT UNSIGNED DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    payload_json JSON DEFAULT NULL,
    context_json JSON DEFAULT NULL,
    started_at DATETIME NULL DEFAULT NULL,
    finished_at DATETIME NULL DEFAULT NULL,
    cancelled_at DATETIME NULL DEFAULT NULL,
    cancel_reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    KEY idx_workflow_run_status (status),
    KEY idx_workflow_run_key (workflow_key, entity_type, entity_id),
    KEY idx_workflow_run_module (module),
    CONSTRAINT fk_workflow_run_definition FOREIGN KEY (definition_id) REFERENCES workflow_definitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_run_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    ordinal INT UNSIGNED NOT NULL,
    step_key VARCHAR(100) NOT NULL,
    label VARCHAR(200) DEFAULT NULL,
    capability_id VARCHAR(200) NOT NULL,
    args_json JSON DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    attempt INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(100) DEFAULT NULL,
    result_json JSON DEFAULT NULL,
    last_error VARCHAR(1000) DEFAULT NULL,
    started_at DATETIME NULL DEFAULT NULL,
    finished_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    KEY idx_workflow_step_run (run_id),
    KEY idx_workflow_step_status (status),
    KEY idx_workflow_step_idempotency (idempotency_key),
    CONSTRAINT fk_workflow_step_run FOREIGN KEY (run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(100) NOT NULL,
    event_id VARCHAR(200) NOT NULL,
    workflow_key VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) DEFAULT NULL,
    definition_id BIGINT UNSIGNED DEFAULT NULL,
    filter_json JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_workflow_subscription (module, event_id, workflow_key),
    KEY idx_workflow_sub_event (event_id),
    KEY idx_workflow_sub_active (is_active),
    CONSTRAINT fk_workflow_sub_definition FOREIGN KEY (definition_id) REFERENCES workflow_definitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
