-- Contact Form: submissions table
CREATE TABLE IF NOT EXISTS contact_form_submissions (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255)    NOT NULL,
    email        VARCHAR(255)    NOT NULL,
    message      TEXT            NOT NULL,
    ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
    status       ENUM('new','read','archived') NOT NULL DEFAULT 'new',
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_status  (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
