-- Ikabud Kernel Basic Database Dump
-- Created: 2025-11-10 13:55:10
-- Database: ikabud-kernel
-- Contains: Complete schema + User data only
--
-- This dump includes:
--   - All table structures
--   - User data (for initial admin setup)
--
-- This dump excludes:
--   - Instance data
--   - Process data
--   - Cache data
--   - Log data

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- --------------------------------------------------------
-- Table structure for table `admin_sessions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_sessions`;
CREATE TABLE `admin_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `admin_users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','manager','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `permissions` json DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `api_tokens`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE `api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `abilities` json DEFAULT NULL COMMENT 'Permissions/scopes',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `dsl_cache`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dsl_cache`;
CREATE TABLE `dsl_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `query_string` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `compiled_ast` json NOT NULL,
  `execution_plan` json DEFAULT NULL,
  `hit_count` int unsigned DEFAULT '0',
  `last_hit_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cache_key` (`cache_key`),
  KEY `idx_cache_key` (`cache_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `dsl_snippets`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `dsl_snippets`;
CREATE TABLE `dsl_snippets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `snippet_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `snippet_code` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `usage_count` int unsigned DEFAULT '0',
  `is_system` tinyint(1) DEFAULT '0',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`snippet_name`),
  KEY `idx_category` (`category`),
  KEY `idx_system` (`is_system`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `instance_routes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `instance_routes`;
CREATE TABLE `instance_routes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_pattern` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_type` enum('exact','prefix','regex') COLLATE utf8mb4_unicode_ci DEFAULT 'prefix',
  `priority` tinyint DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_pattern` (`route_pattern`),
  KEY `idx_priority` (`priority`),
  CONSTRAINT `instance_routes_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `instances`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `instances`;
CREATE TABLE `instances` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `instance_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cms_type` enum('wordpress','joomla','drupal','native') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cms_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path_prefix` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL path prefix for routing',
  `database_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `database_prefix` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `status` enum('active','inactive','maintenance','error') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `config` json DEFAULT NULL COMMENT 'Instance-specific configuration',
  `resources` json DEFAULT NULL COMMENT 'Resource quotas and limits',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `activated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`),
  KEY `idx_instance_id` (`instance_id`),
  KEY `idx_cms_type` (`cms_type`),
  KEY `idx_status` (`status`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `kernel_boot_log`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `kernel_boot_log`;
CREATE TABLE `kernel_boot_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boot_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phase` tinyint NOT NULL COMMENT '1-5 for boot phases',
  `phase_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','completed','failed','skipped') COLLATE utf8mb4_unicode_ci DEFAULT 'started',
  `duration_ms` decimal(10,3) DEFAULT NULL,
  `memory_before` int unsigned DEFAULT NULL,
  `memory_after` int unsigned DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_boot_id` (`boot_id`),
  KEY `idx_phase` (`phase`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=150781 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `kernel_config`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `kernel_config`;
CREATE TABLE `kernel_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `type` enum('string','integer','boolean','json','array') COLLATE utf8mb4_unicode_ci DEFAULT 'string',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `idx_key` (`key`),
  KEY `idx_is_system` (`is_system`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `kernel_processes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `kernel_processes`;
CREATE TABLE `kernel_processes` (
  `pid` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `process_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `process_type` enum('cms','plugin','module','service') COLLATE utf8mb4_unicode_ci DEFAULT 'cms',
  `cms_type` enum('wordpress','joomla','drupal','native') COLLATE utf8mb4_unicode_ci DEFAULT 'native',
  `status` enum('booting','running','paused','stopped','crashed') COLLATE utf8mb4_unicode_ci DEFAULT 'booting',
  `priority` tinyint DEFAULT '0',
  `memory_limit` int unsigned DEFAULT NULL COMMENT 'Memory limit in MB',
  `memory_usage` int unsigned DEFAULT NULL COMMENT 'Current memory usage in MB',
  `cpu_time` decimal(10,2) DEFAULT '0.00' COMMENT 'CPU time in seconds',
  `boot_time` decimal(10,3) DEFAULT NULL COMMENT 'Boot time in milliseconds',
  `started_at` timestamp NULL DEFAULT NULL,
  `stopped_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pid`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`process_type`,`cms_type`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `kernel_resources`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `kernel_resources`;
CREATE TABLE `kernel_resources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned DEFAULT NULL,
  `resource_type` enum('memory','cpu','disk','network','database') COLLATE utf8mb4_unicode_ci NOT NULL,
  `usage_value` decimal(15,2) NOT NULL,
  `usage_unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'MB, %, seconds, queries, etc',
  `limit_value` decimal(15,2) DEFAULT NULL,
  `is_exceeded` tinyint(1) DEFAULT '0',
  `measured_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_type` (`resource_type`),
  KEY `idx_measured` (`measured_at`),
  CONSTRAINT `kernel_resources_ibfk_1` FOREIGN KEY (`pid`) REFERENCES `kernel_processes` (`pid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `kernel_syscalls`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `kernel_syscalls`;
CREATE TABLE `kernel_syscalls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned DEFAULT NULL,
  `syscall_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `syscall_args` json DEFAULT NULL,
  `syscall_result` json DEFAULT NULL,
  `execution_time` decimal(10,3) DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `memory_delta` int DEFAULT NULL COMMENT 'Memory change in bytes',
  `status` enum('success','error','timeout') COLLATE utf8mb4_unicode_ci DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_syscall` (`syscall_name`),
  KEY `idx_created` (`created_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `kernel_syscalls_ibfk_1` FOREIGN KEY (`pid`) REFERENCES `kernel_processes` (`pid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `login_attempts`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `theme_files`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `theme_files`;
CREATE TABLE `theme_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `theme_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` enum('template','style','script','dsl','config','asset') COLLATE utf8mb4_unicode_ci DEFAULT 'template',
  `file_language` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'html, css, js, ikb, php',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `compiled_content` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Compiled/cached version',
  `is_compiled` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_theme_file` (`theme_id`,`file_path`),
  KEY `idx_theme` (`theme_id`),
  KEY `idx_type` (`file_type`),
  CONSTRAINT `theme_files_ibfk_1` FOREIGN KEY (`theme_id`) REFERENCES `themes` (`theme_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `themes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `themes`;
CREATE TABLE `themes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `theme_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `theme_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `theme_type` enum('ikabud','wordpress','joomla','custom') COLLATE utf8mb4_unicode_ci DEFAULT 'ikabud',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `screenshot` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supports` json DEFAULT NULL COMMENT 'Features supported by theme',
  `is_active` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `theme_id` (`theme_id`),
  KEY `idx_theme_id` (`theme_id`),
  KEY `idx_type` (`theme_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','developer','editor','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `is_active` tinyint(1) DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Dumping data for table `users`
-- --------------------------------------------------------

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `display_name`, `role`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('1', 'admin', 'admin@ikabud-kernel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', '1', NULL, '2025-11-08 12:03:13', '2025-11-08 12:03:13');

-- --------------------------------------------------------
-- Table structure for table `virtual_processes`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `virtual_processes`;
CREATE TABLE `virtual_processes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `virtual_pid` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('running','stopped','error') COLLATE utf8mb4_unicode_ci DEFAULT 'running',
  `started_at` timestamp NULL DEFAULT NULL,
  `stopped_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`),
  KEY `idx_instance_id` (`instance_id`),
  KEY `idx_status` (`status`),
  KEY `idx_virtual_pid` (`virtual_pid`),
  CONSTRAINT `virtual_processes_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- End of dump
