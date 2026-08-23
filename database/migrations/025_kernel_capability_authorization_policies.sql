CREATE TABLE IF NOT EXISTS capability_authorization_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_version INT UNSIGNED NOT NULL,
    capability_id VARCHAR(200) NOT NULL,
    capability_version VARCHAR(50) NOT NULL,
    provider VARCHAR(100) NOT NULL,
    caller_module VARCHAR(100) DEFAULT NULL,
    allowed_roles VARCHAR(255) DEFAULT NULL,
    provider_activation_required TINYINT(1) NOT NULL DEFAULT 1,
    requires_protocol VARCHAR(20) DEFAULT 'v1',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_cap_authz_policy (policy_version, capability_id, capability_version, provider),
    KEY idx_cap_authz_active (is_active, policy_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO capability_authorization_policies (
    policy_version,
    capability_id,
    capability_version,
    provider,
    caller_module,
    allowed_roles,
    provider_activation_required,
    requires_protocol,
    is_active,
    updated_at
) VALUES (
    1,
    'proof_lane.ping',
    '1',
    'proof-lane',
    'kernel',
    'admin',
    1,
    'v2',
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE
    caller_module = VALUES(caller_module),
    allowed_roles = VALUES(allowed_roles),
    provider_activation_required = VALUES(provider_activation_required),
    requires_protocol = VALUES(requires_protocol),
    is_active = VALUES(is_active),
    updated_at = NOW();
