-- Ticketing module — property management extensions
-- Adds public submission fields, image attachments, settings, and rate-limit IP tracking.

ALTER TABLE tickets
    ADD COLUMN contact_name  VARCHAR(120) NULL DEFAULT NULL AFTER closed_at,
    ADD COLUMN contact_email VARCHAR(150) NULL DEFAULT NULL AFTER contact_name,
    ADD COLUMN contact_phone VARCHAR(30)  NULL DEFAULT NULL AFTER contact_email,
    ADD COLUMN unit_no       VARCHAR(40)  NULL DEFAULT NULL AFTER contact_phone,
    ADD COLUMN category      ENUM('plumbing','electrical','pest_control','common_area','security','other') NOT NULL DEFAULT 'other' AFTER unit_no,
    ADD COLUMN source        ENUM('internal','public') NOT NULL DEFAULT 'internal' AFTER category,
    ADD COLUMN ip_address    VARCHAR(45)  NULL DEFAULT NULL AFTER source,
    ADD KEY idx_source   (source),
    ADD KEY idx_category (category);

CREATE TABLE IF NOT EXISTS ticket_attachments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT UNSIGNED     NOT NULL,
    media_id   INT UNSIGNED     NULL DEFAULT NULL,
    file_url   VARCHAR(500)     NOT NULL DEFAULT '',
    filename   VARCHAR(255)     NOT NULL DEFAULT '',
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_id (ticket_id),
    CONSTRAINT fk_attach_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticketing_settings (
    setting_key   VARCHAR(80) NOT NULL,
    setting_value TEXT        NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ticketing_settings (setting_key, setting_value) VALUES
    ('admin_phone',         ''),
    ('admin_email',         ''),
    ('notify_sms',          '0'),
    ('notify_email',        '0'),
    ('public_form_enabled', '1');
