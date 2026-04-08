ALTER TABLE kernel_integration_logs
    ADD COLUMN request_id VARCHAR(64) DEFAULT NULL AFTER error_message;

ALTER TABLE kernel_integration_logs
    ADD COLUMN correlation_id VARCHAR(64) DEFAULT NULL AFTER request_id;

ALTER TABLE kernel_integration_logs
    ADD COLUMN duration_ms INT UNSIGNED DEFAULT NULL AFTER correlation_id;

ALTER TABLE kernel_integration_logs
    ADD KEY idx_kernel_integration_logs_created (created_at);