-- HARPP durable conversation-aware memory read-model (chair-approved debate flywheel).
-- Per-conversation bounded summary derived from the canonical server-authoritative
-- tables (harpp_messages + harpp_decisions + harpp_work_runs). Additive; versioned by
-- the conversation's latest message aggregate sequence so a new message advances the
-- version and invalidates the bounded client context cache. MySQL 5.7-safe.

CREATE TABLE IF NOT EXISTS `harpp_context_summary` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` INT UNSIGNED NOT NULL,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL,
    `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `summary_json` JSON NULL,
    `decisions_json` JSON NULL,
    `active_run_json` JSON NULL,
    `token_budget` INT UNSIGNED NOT NULL DEFAULT 0,
    `built_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_harpp_context_summary_conversation` (`conversation_id`),
    CONSTRAINT `fk_harpp_context_summary_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `harpp_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
