CREATE TABLE IF NOT EXISTS `harpp_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_users_email` (`email`),
    KEY `idx_harpp_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_password_resets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_password_resets_token` (`token`),
    KEY `idx_harpp_password_resets_user` (`user_id`),
    KEY `idx_harpp_password_resets_expiry` (`expires_at`),
    CONSTRAINT `fk_harpp_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_conversations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `harness_session_id` VARCHAR(191) NULL,
    `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_harpp_conversations_session` (`harness_session_id`),
    KEY `idx_harpp_conversations_status_updated` (`status`, `updated_at`),
    KEY `idx_harpp_conversations_created_by` (`created_by`),
    CONSTRAINT `fk_harpp_conversations_created_by` FOREIGN KEY (`created_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` INT UNSIGNED NOT NULL,
    `sender_type` ENUM('user', 'harness', 'system') NOT NULL,
    `sender_user_id` INT UNSIGNED NULL,
    `body` TEXT NOT NULL,
    `payload` JSON NULL,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_harpp_messages_conversation_created` (`conversation_id`, `created_at`),
    KEY `idx_harpp_messages_sender_user` (`sender_user_id`),
    KEY `idx_harpp_messages_unread` (`conversation_id`, `read_at`),
    CONSTRAINT `fk_harpp_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_messages_sender_user` FOREIGN KEY (`sender_user_id`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_decisions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_key` VARCHAR(191) NOT NULL,
    `conversation_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `lifecycle_state` ENUM('CREATED', 'PENDING', 'NOTIFIED', 'VIEWED', 'DECIDED', 'ACKNOWLEDGED', 'APPLIED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED') NOT NULL DEFAULT 'CREATED',
    `escalation_class` VARCHAR(100) NULL,
    `risk_level` VARCHAR(50) NULL,
    `options` JSON NULL,
    `payload` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at` DATETIME NULL,
    `decided_at` DATETIME NULL,
    `decision` TEXT NULL,
    `decided_by` INT UNSIGNED NULL,
    `applied_at` DATETIME NULL,
    `closed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_decisions_key` (`decision_key`),
    KEY `idx_harpp_decisions_conversation` (`conversation_id`),
    KEY `idx_harpp_decisions_lifecycle_created` (`lifecycle_state`, `created_at`),
    KEY `idx_harpp_decisions_decided_by` (`decided_by`),
    CONSTRAINT `fk_harpp_decisions_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_decisions_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `harpp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `decision_id` INT UNSIGNED NULL,
    `channel` ENUM('push') NOT NULL DEFAULT 'push',
    `status` ENUM('pending', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    `payload` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_harpp_notifications_user_status` (`user_id`, `status`),
    KEY `idx_harpp_notifications_decision` (`decision_id`),
    CONSTRAINT `fk_harpp_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_notifications_decision` FOREIGN KEY (`decision_id`) REFERENCES `harpp_decisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_push_subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `endpoint` TEXT NOT NULL,
    `keys` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_harpp_push_subscriptions_user` (`user_id`),
    CONSTRAINT `fk_harpp_push_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `harpp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_adrs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `adr_key` VARCHAR(191) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `decision_ref` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_at` DATETIME NOT NULL,
    `superseded_by` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_adrs_key` (`adr_key`),
    UNIQUE KEY `uq_harpp_adrs_decision_ref` (`decision_ref`),
    KEY `idx_harpp_adrs_superseded_by` (`superseded_by`),
    CONSTRAINT `fk_harpp_adrs_decision_ref` FOREIGN KEY (`decision_ref`) REFERENCES `harpp_decisions` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_harpp_adrs_superseded_by` FOREIGN KEY (`superseded_by`) REFERENCES `harpp_adrs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `harpp_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(191) NOT NULL,
    `setting_value` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
