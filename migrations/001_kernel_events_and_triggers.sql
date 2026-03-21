-- Kernel migration: kernel_events + kernel_event_triggers

CREATE TABLE IF NOT EXISTS `kernel_events` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`         VARCHAR(100) NOT NULL,
    `event_key`      VARCHAR(255) NOT NULL,
    `description`    TEXT DEFAULT NULL,
    `available_vars` JSON DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_event` (`module`, `event_key`),
    KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_event_triggers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`          VARCHAR(100) NOT NULL,
    `event_key`       VARCHAR(255) NOT NULL,
    `capability_id`   VARCHAR(255) NOT NULL,
    `provider`        VARCHAR(100) DEFAULT NULL,
    `is_enabled`      TINYINT(1) NOT NULL DEFAULT 1,
    `priority`        INT NOT NULL DEFAULT 100,
    `template`        TEXT DEFAULT NULL,
    `max_per_minute`  SMALLINT UNSIGNED DEFAULT NULL,
    `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `timeout_ms`      INT UNSIGNED NOT NULL DEFAULT 5000,
    `meta`            JSON DEFAULT NULL,
    `updated_by`      INT DEFAULT NULL,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_event_cap` (`module`, `event_key`, `capability_id`),
    KEY `idx_module` (`module`),
    KEY `idx_event_key` (`event_key`),
    KEY `idx_enabled_priority` (`is_enabled`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Guidance events (baseline)
INSERT INTO kernel_events (module, event_key, description, available_vars) VALUES
    ('guidance', 'guidance.booking.created',
     'Fired when a student submits a public booking request.',
     '["appointment_id","student_name","student_email","student_mobile"]'),
    ('guidance', 'guidance.appointment.created',
     'Fired when an admin creates a new appointment for a case.',
     '["appointment_id","date","time","student_name","student_mobile"]')
ON DUPLICATE KEY UPDATE description = VALUES(description), available_vars = VALUES(available_vars);

-- Seed Guidance triggers (baseline)
INSERT INTO kernel_event_triggers
    (module, event_key, capability_id, is_enabled, priority, max_per_minute, template, created_at)
VALUES
    ('guidance', 'guidance.booking.created', 'sms.send@1', 1, 100, 10,
     'Guidance: booking request received. Ref #{appointment_id}', NOW()),
    ('guidance', 'guidance.appointment.created', 'sms.send@1', 1, 100, 10,
     'Guidance: appointment scheduled on {date} {time}. Ref #{appointment_id}', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
