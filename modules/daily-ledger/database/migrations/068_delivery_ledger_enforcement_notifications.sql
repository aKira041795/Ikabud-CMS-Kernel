-- 068: enforce commissary delivery ledger effects and record integrity notifications
-- MySQL 5.7 compatible. Existing delivery/receiving rows are intentionally not changed.

CREATE TABLE IF NOT EXISTS dl_delivery_ledger_effects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    delivery_item_id BIGINT UNSIGNED NOT NULL,
    commissary_branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    quantity INT NOT NULL,
    effect_status ENUM('applied','reversed') NOT NULL,
    applied_by INT UNSIGNED NULL,
    applied_at DATETIME NOT NULL,
    reversed_by INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    before_dispatched_qty INT NOT NULL,
    after_dispatched_qty INT NOT NULL,
    before_remaining_qty INT NOT NULL,
    after_remaining_qty INT NOT NULL,
    reverse_before_dispatched_qty INT NULL,
    reverse_after_dispatched_qty INT NULL,
    reverse_before_remaining_qty INT NULL,
    reverse_after_remaining_qty INT NULL,
    UNIQUE KEY uq_dl_delivery_ledger_effect_item (delivery_item_id),
    KEY idx_dl_delivery_ledger_effect_delivery (delivery_id, effect_status),
    CONSTRAINT fk_dl_dle_delivery FOREIGN KEY (delivery_id) REFERENCES dl_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_dle_item FOREIGN KEY (delivery_item_id) REFERENCES dl_delivery_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_dle_commissary FOREIGN KEY (commissary_branch_id) REFERENCES dl_branches(id),
    CONSTRAINT fk_dl_dle_product FOREIGN KEY (product_id) REFERENCES dl_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dl_integrity_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aggregate_key VARCHAR(190) NOT NULL,
    finding_type ENUM('variance','unresolved_origin','uncounted_receipt','historical_digest') NOT NULL,
    branch_id INT UNSIGNED NULL,
    entity_type VARCHAR(64) NULL,
    entity_id BIGINT UNSIGNED NULL,
    finding_count INT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(190) NOT NULL,
    detail TEXT NULL,
    raised_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_integrity_notification_key (aggregate_key),
    KEY idx_dl_integrity_notification_branch (branch_id, raised_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dl_integrity_notification_recipients (
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seen_at DATETIME NULL,
    PRIMARY KEY (notification_id, user_id),
    KEY idx_dl_integrity_recipient_unseen (user_id, seen_at),
    CONSTRAINT fk_dl_inr_notification FOREIGN KEY (notification_id) REFERENCES dl_integrity_notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_inr_user FOREIGN KEY (user_id) REFERENCES dl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One digest, never one row per historical receipt. No source record is relabelled.
INSERT IGNORE INTO dl_integrity_notifications
    (aggregate_key, finding_type, branch_id, entity_type, entity_id, finding_count, title, detail)
SELECT 'historical-count-basis-null', 'historical_digest', NULL, 'dl_branch_receivings', NULL, COUNT(*),
       'Historical receipts need count-basis review',
       'Pre-migration receipts retain unknown count provenance. No quantities were changed.'
  FROM dl_branch_receivings
 WHERE count_basis IS NULL
HAVING COUNT(*) > 0;

INSERT IGNORE INTO dl_integrity_notification_recipients (notification_id, user_id)
SELECT n.id, u.id
  FROM dl_integrity_notifications n
  JOIN dl_users u ON u.id = (
       SELECT MIN(u2.id) FROM dl_users u2
        WHERE u2.role = 'admin' AND u2.is_active = 1 AND u2.deleted_at IS NULL
  )
 WHERE n.aggregate_key = 'historical-count-basis-null';
