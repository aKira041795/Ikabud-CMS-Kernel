-- Conversation attachments are private module storage objects. The client filename is
-- metadata only; storage_path always contains a server-generated opaque name.
CREATE TABLE IF NOT EXISTS `harpp_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `conversation_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `client_filename` VARCHAR(255) NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `claimed_mime` VARCHAR(191) NULL,
    `detected_mime` VARCHAR(191) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `sha256` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_attachment_storage` (`storage_path`),
    KEY `idx_harpp_attachment_conversation` (`tenant_id`,`conversation_id`,`id`),
    KEY `idx_harpp_attachment_message` (`message_id`),
    KEY `idx_harpp_attachment_uploader` (`uploaded_by`),
    CONSTRAINT `fk_harpp_attachment_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_harpp_attachment_message` FOREIGN KEY (`message_id`) REFERENCES `harpp_messages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_harpp_attachment_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `harpp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
