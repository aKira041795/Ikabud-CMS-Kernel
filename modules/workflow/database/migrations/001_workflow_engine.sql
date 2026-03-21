-- Workflow Module — Engine Tables

CREATE TABLE IF NOT EXISTS workflow_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_key VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    initial_state VARCHAR(50) NOT NULL,
    states_json JSON NOT NULL,
    transitions_json JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wf_def (workflow_key, module, entity_type),
    KEY idx_wf_module (module),
    KEY idx_wf_entity (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_instances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_key VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(50) NOT NULL,
    state VARCHAR(50) NOT NULL,
    meta_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wf_inst (workflow_key, module, entity_type, entity_id),
    KEY idx_wf_inst_lookup (module, entity_type, entity_id),
    KEY idx_wf_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_transition_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instance_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    from_state VARCHAR(50) NOT NULL,
    to_state VARCHAR(50) NOT NULL,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    meta_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wf_log_instance (instance_id),
    KEY idx_wf_log_actor (actor_user_id),
    CONSTRAINT fk_wf_log_instance FOREIGN KEY (instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
