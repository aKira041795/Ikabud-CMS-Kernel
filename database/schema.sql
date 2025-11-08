-- Ikabud Kernel Database Schema
-- Fresh implementation with GNU/Linux-inspired architecture
-- Created: 2025-11-08

-- ============================================================================
-- KERNEL CORE TABLES
-- ============================================================================

-- Kernel configuration and state
CREATE TABLE IF NOT EXISTS `kernel_config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(255) NOT NULL UNIQUE,
  `value` TEXT,
  `type` ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
  `description` TEXT,
  `is_system` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_key` (`key`),
  INDEX `idx_is_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Process table (like Linux ps command)
CREATE TABLE IF NOT EXISTS `kernel_processes` (
  `pid` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `instance_id` VARCHAR(64) NOT NULL,
  `process_name` VARCHAR(255) NOT NULL,
  `process_type` ENUM('cms', 'plugin', 'module', 'service') DEFAULT 'cms',
  `cms_type` ENUM('wordpress', 'joomla', 'drupal', 'native') DEFAULT 'native',
  `status` ENUM('booting', 'running', 'paused', 'stopped', 'crashed') DEFAULT 'booting',
  `priority` TINYINT DEFAULT 0,
  `memory_limit` INT UNSIGNED COMMENT 'Memory limit in MB',
  `memory_usage` INT UNSIGNED COMMENT 'Current memory usage in MB',
  `cpu_time` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'CPU time in seconds',
  `boot_time` DECIMAL(10,3) COMMENT 'Boot time in milliseconds',
  `started_at` TIMESTAMP NULL,
  `stopped_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_instance` (`instance_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_type` (`process_type`, `cms_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Syscall audit log
CREATE TABLE IF NOT EXISTS `kernel_syscalls` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pid` INT UNSIGNED,
  `syscall_name` VARCHAR(255) NOT NULL,
  `syscall_args` JSON,
  `syscall_result` JSON,
  `execution_time` DECIMAL(10,3) COMMENT 'Execution time in milliseconds',
  `memory_delta` INT COMMENT 'Memory change in bytes',
  `status` ENUM('success', 'error', 'timeout') DEFAULT 'success',
  `error_message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pid` (`pid`),
  INDEX `idx_syscall` (`syscall_name`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`pid`) REFERENCES `kernel_processes`(`pid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resource usage tracking
CREATE TABLE IF NOT EXISTS `kernel_resources` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pid` INT UNSIGNED,
  `resource_type` ENUM('memory', 'cpu', 'disk', 'network', 'database') NOT NULL,
  `usage_value` DECIMAL(15,2) NOT NULL,
  `usage_unit` VARCHAR(20) NOT NULL COMMENT 'MB, %, seconds, queries, etc',
  `limit_value` DECIMAL(15,2),
  `is_exceeded` BOOLEAN DEFAULT FALSE,
  `measured_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pid` (`pid`),
  INDEX `idx_type` (`resource_type`),
  INDEX `idx_measured` (`measured_at`),
  FOREIGN KEY (`pid`) REFERENCES `kernel_processes`(`pid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Boot sequence log
CREATE TABLE IF NOT EXISTS `kernel_boot_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `boot_id` VARCHAR(64) NOT NULL,
  `phase` TINYINT NOT NULL COMMENT '1-5 for boot phases',
  `phase_name` VARCHAR(100) NOT NULL,
  `status` ENUM('started', 'completed', 'failed', 'skipped') DEFAULT 'started',
  `duration_ms` DECIMAL(10,3),
  `memory_before` INT UNSIGNED,
  `memory_after` INT UNSIGNED,
  `error_message` TEXT,
  `metadata` JSON,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_boot_id` (`boot_id`),
  INDEX `idx_phase` (`phase`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INSTANCE MANAGEMENT TABLES
-- ============================================================================

-- CMS instances registry
CREATE TABLE IF NOT EXISTS `instances` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `instance_id` VARCHAR(64) NOT NULL UNIQUE,
  `instance_name` VARCHAR(255) NOT NULL,
  `cms_type` ENUM('wordpress', 'joomla', 'drupal', 'native') NOT NULL,
  `cms_version` VARCHAR(50),
  `domain` VARCHAR(255),
  `path_prefix` VARCHAR(100) COMMENT 'URL path prefix for routing',
  `database_name` VARCHAR(64) NOT NULL,
  `database_prefix` VARCHAR(20) DEFAULT '',
  `status` ENUM('active', 'inactive', 'maintenance', 'error') DEFAULT 'active',
  `config` JSON COMMENT 'Instance-specific configuration',
  `resources` JSON COMMENT 'Resource quotas and limits',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `activated_at` TIMESTAMP NULL,
  INDEX `idx_instance_id` (`instance_id`),
  INDEX `idx_cms_type` (`cms_type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instance routing configuration
CREATE TABLE IF NOT EXISTS `instance_routes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `instance_id` VARCHAR(64) NOT NULL,
  `route_pattern` VARCHAR(255) NOT NULL,
  `route_type` ENUM('exact', 'prefix', 'regex') DEFAULT 'prefix',
  `priority` TINYINT DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_instance` (`instance_id`),
  INDEX `idx_pattern` (`route_pattern`),
  INDEX `idx_priority` (`priority`),
  FOREIGN KEY (`instance_id`) REFERENCES `instances`(`instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- THEME MANAGEMENT TABLES
-- ============================================================================

-- Theme registry
CREATE TABLE IF NOT EXISTS `themes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `theme_id` VARCHAR(64) NOT NULL UNIQUE,
  `theme_name` VARCHAR(255) NOT NULL,
  `theme_type` ENUM('ikabud', 'wordpress', 'joomla', 'custom') DEFAULT 'ikabud',
  `version` VARCHAR(50),
  `author` VARCHAR(255),
  `description` TEXT,
  `path` VARCHAR(500) NOT NULL,
  `screenshot` VARCHAR(500),
  `supports` JSON COMMENT 'Features supported by theme',
  `is_active` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_theme_id` (`theme_id`),
  INDEX `idx_type` (`theme_type`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Theme files (for DSL templates)
CREATE TABLE IF NOT EXISTS `theme_files` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `theme_id` VARCHAR(64) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_type` ENUM('template', 'style', 'script', 'dsl', 'config', 'asset') DEFAULT 'template',
  `file_language` VARCHAR(20) COMMENT 'html, css, js, ikb, php',
  `content` LONGTEXT,
  `compiled_content` LONGTEXT COMMENT 'Compiled/cached version',
  `is_compiled` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_theme` (`theme_id`),
  INDEX `idx_type` (`file_type`),
  UNIQUE KEY `unique_theme_file` (`theme_id`, `file_path`),
  FOREIGN KEY (`theme_id`) REFERENCES `themes`(`theme_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DSL SYSTEM TABLES
-- ============================================================================

-- DSL query cache
CREATE TABLE IF NOT EXISTS `dsl_cache` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cache_key` VARCHAR(64) NOT NULL UNIQUE,
  `query_string` TEXT NOT NULL,
  `compiled_ast` JSON NOT NULL,
  `execution_plan` JSON,
  `hit_count` INT UNSIGNED DEFAULT 0,
  `last_hit_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cache_key` (`cache_key`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DSL snippets library
CREATE TABLE IF NOT EXISTS `dsl_snippets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `snippet_name` VARCHAR(255) NOT NULL,
  `snippet_code` TEXT NOT NULL,
  `description` TEXT,
  `category` VARCHAR(100),
  `tags` JSON,
  `usage_count` INT UNSIGNED DEFAULT 0,
  `is_system` BOOLEAN DEFAULT FALSE,
  `created_by` INT UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_name` (`snippet_name`),
  INDEX `idx_category` (`category`),
  INDEX `idx_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- USER & AUTHENTICATION TABLES
-- ============================================================================

-- Users (kernel-level authentication)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(255),
  `role` ENUM('admin', 'developer', 'editor', 'viewer') DEFAULT 'viewer',
  `is_active` BOOLEAN DEFAULT TRUE,
  `last_login_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API tokens (for React admin)
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL UNIQUE,
  `token_name` VARCHAR(255),
  `abilities` JSON COMMENT 'Permissions/scopes',
  `last_used_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_token` (`token_hash`),
  INDEX `idx_expires` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INITIAL DATA
-- ============================================================================

-- Insert default kernel configuration
INSERT INTO `kernel_config` (`key`, `value`, `type`, `description`, `is_system`) VALUES
('kernel.version', '1.0.0', 'string', 'Kernel version', TRUE),
('kernel.boot_mode', 'production', 'string', 'Boot mode: development, production, maintenance', TRUE),
('kernel.max_processes', '100', 'integer', 'Maximum concurrent processes', TRUE),
('kernel.default_memory_limit', '256', 'integer', 'Default memory limit per process (MB)', TRUE),
('kernel.syscall_logging', 'true', 'boolean', 'Enable syscall audit logging', TRUE),
('kernel.debug_mode', 'false', 'boolean', 'Enable debug mode', FALSE),
('dsl.cache_enabled', 'true', 'boolean', 'Enable DSL query caching', FALSE),
('dsl.cache_ttl', '3600', 'integer', 'DSL cache TTL in seconds', FALSE),
('routing.default_cms', 'native', 'string', 'Default CMS for root route', FALSE);

-- Insert default admin user (password: admin123 - CHANGE IN PRODUCTION!)
INSERT INTO `users` (`username`, `email`, `password_hash`, `display_name`, `role`) VALUES
('admin', 'admin@ikabud-kernel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- Insert default DSL snippets
INSERT INTO `dsl_snippets` (`snippet_name`, `snippet_code`, `description`, `category`, `is_system`) VALUES
('Recent Posts', '{ikb_query type=post limit=5 format=card layout=grid-3}', 'Display 5 recent posts in card format', 'content', TRUE),
('Featured Products', '{ikb_query type=product limit=8 format=grid layout=grid-4 if="meta_key=featured"}', 'Display featured products', 'ecommerce', TRUE),
('Category List', '{ikb_query type=category format=list}', 'Display all categories', 'taxonomy', TRUE),
('Hero Section', '{ikb_query type=post limit=1 format=hero}', 'Display latest post as hero', 'layout', TRUE);
