-- Webhook event deduplication table.
-- Prevents double-processing of payment gateway webhooks (Stripe retries, PayPal IPN replays).
CREATE TABLE IF NOT EXISTS ec_webhook_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway         VARCHAR(30)  NOT NULL,
    event_id        VARCHAR(255) NOT NULL,
    event_type      VARCHAR(100) NOT NULL DEFAULT '',
    processed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ec_webhook_event (gateway, event_id),
    INDEX idx_ec_webhook_events_processed (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
