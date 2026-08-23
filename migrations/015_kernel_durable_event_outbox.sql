-- Durable event outbox foundation.
-- Transaction-aware writes only; worker/claim/retry/dead-letter arrive in a later slice.

CREATE TABLE IF NOT EXISTS `kernel_durable_event_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(64) NOT NULL,
    `event_id` CHAR(36) NOT NULL,
    `event_name` VARCHAR(190) NOT NULL,
    `idempotency_key` VARCHAR(190) DEFAULT NULL,
    `payload` LONGTEXT DEFAULT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `source` VARCHAR(64) NOT NULL,
    `actor_id` VARCHAR(64) DEFAULT NULL,
    `actor_role` VARCHAR(64) DEFAULT NULL,
    `request_id` VARCHAR(64) DEFAULT NULL,
    `status` ENUM('pending','claimed','processed','failed','dead_letter') NOT NULL DEFAULT 'pending',
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME DEFAULT NULL,
    `lease_owner` VARCHAR(64) DEFAULT NULL,
    `lease_token` CHAR(64) DEFAULT NULL,
    `lease_expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_kernel_durable_event_outbox_tenant_event` (`tenant_id`, `event_id`),
    UNIQUE KEY `uq_kernel_durable_event_outbox_tenant_idempotency` (`tenant_id`, `idempotency_key`),
    KEY `idx_kernel_durable_event_outbox_pending` (`tenant_id`, `status`, `available_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
