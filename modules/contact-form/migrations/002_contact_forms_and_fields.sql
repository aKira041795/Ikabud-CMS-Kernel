CREATE TABLE IF NOT EXISTS contact_forms (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name             VARCHAR(255)    NOT NULL,
    slug             VARCHAR(100)    NOT NULL,
    success_message  TEXT            DEFAULT NULL,
    captcha_enabled  TINYINT(1)      NOT NULL DEFAULT 1,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_contact_forms_slug (slug),
    KEY idx_contact_forms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_form_fields (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    form_id       INT UNSIGNED    NOT NULL,
    field_type    VARCHAR(50)     NOT NULL DEFAULT 'text',
    label         VARCHAR(255)    NOT NULL,
    name          VARCHAR(100)    NOT NULL,
    placeholder   VARCHAR(255)    NOT NULL DEFAULT '',
    required      TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order    INT             NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_form_fields_form_id (form_id),
    KEY idx_contact_form_fields_sort_order (sort_order),
    UNIQUE KEY uniq_contact_form_fields_form_name (form_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;