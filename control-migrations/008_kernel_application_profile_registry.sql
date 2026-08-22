-- Kernel Application Profile Registry (global, base/control DB).
--
-- Kernel-owned registry of application profiles (e.g. ark-workbench). This is
-- GLOBAL registration (one row per artifact identity), distinct from tenant
-- selection (settings-based). Mirrors cms_theme_registry in shape so a single
-- registration contract can serve both.
--
-- MySQL 5.7 compatible:
--   - ENGINE=InnoDB + utf8mb4 (Bluehost gate)
--   - No 8.0+ features (no CREATE INDEX IF NOT EXISTS, no window funcs/CTEs)
--   - Full (artifact_type, name, version) identity — NO prefix uniqueness
--   - Index bytes: 1 (enum) + 190*4 (name) + 32*4 (version) ≈ 889 < 3072 limit

CREATE TABLE IF NOT EXISTS kernel_application_profile_registry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL COMMENT 'Artifact name (e.g. ark-workbench)',
    version VARCHAR(32) NOT NULL COMMENT 'Semantic version (e.g. 0.1.0)',
    artifact_type ENUM('theme','profile') NOT NULL DEFAULT 'profile',
    canonical_digest CHAR(64) NOT NULL COMMENT 'sha256 of canonical manifest JSON',
    manifest_path VARCHAR(500) NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_registry_identity (artifact_type, name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
