-- CMS Theme Registry (tenant DB, CMS-owned).
--
-- CMS-owned registry of themes (e.g. ark). Mirrors
-- kernel_application_profile_registry in shape so a single registration
-- contract can serve both. Registration is GLOBAL by artifact identity;
-- tenant SELECTION stays settings-based (cms active_theme), never a row here.
--
-- MySQL 5.7 compatible:
--   - ENGINE=InnoDB + utf8mb4 (Bluehost gate)
--   - No 8.0+ features
--   - Full (artifact_type, name, version) identity — NO prefix uniqueness
--   - Index bytes ≈ 889 < 3072 limit

CREATE TABLE IF NOT EXISTS cms_theme_registry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL COMMENT 'Artifact name (e.g. ark)',
    version VARCHAR(32) NOT NULL COMMENT 'Semantic version (e.g. 3.0.0)',
    artifact_type ENUM('theme','profile') NOT NULL DEFAULT 'theme',
    canonical_digest CHAR(64) NOT NULL COMMENT 'sha256 of canonical manifest JSON',
    manifest_path VARCHAR(500) NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_registry_identity (artifact_type, name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
