-- CMS service tokens (HARPP CMS Assistant) — scoped Bearer auth for headless agents.
-- Each token maps to a virtual CMS editor with a capability allowlist; publish/schedule
-- capabilities are never granted, so agents can only produce drafts for human review.
-- Token storage: only the sha256 hash is persisted; the raw token is shown once at mint time.
CREATE TABLE IF NOT EXISTS `cms_service_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `capabilities` TEXT NOT NULL COMMENT 'JSON array of allowed capability ids',
  `role` VARCHAR(32) NOT NULL DEFAULT 'editor' COMMENT 'virtual role for roleAtLeast checks',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `last_used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cms_service_tokens_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
