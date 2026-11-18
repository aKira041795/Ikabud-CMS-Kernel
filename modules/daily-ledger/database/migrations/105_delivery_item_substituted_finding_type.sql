-- 105: `delivery_item_substituted` is a finding type.
--
-- The receiving branch may correct a wrongly dispatched item (wrong product -> correct product).
-- Production's dispatched figures follow automatically, and production needs to be TOLD, so the
-- correction raises an integrity notification against the commissary branch.
--
-- Reusing an existing type was rejected: `variance` would put a stock correction into the variance
-- review list and distort its counts, and every other value names a different failure. So the ENUM
-- gains one value.
--
-- Pattern: migration 093 / 058 - a plain idempotent MODIFY COLUMN re-declaring the full list.
-- Existing rows keep their values, no backfill. MySQL 5.7 safe: single ALTER, no index touched.
--
-- NOTE the full list MUST repeat every value that exists today, or the ALTER will silently drop
-- them (measured 2026-11-17 against the live column: variance, unresolved_origin, uncounted_receipt,
-- historical_digest, receipt_mismatch, closed_without_pm_finalize, product_branch_link_revived,
-- ledger_backdated_edit).

ALTER TABLE dl_integrity_notifications
    MODIFY COLUMN finding_type ENUM(
        'variance',
        'unresolved_origin',
        'uncounted_receipt',
        'historical_digest',
        'receipt_mismatch',
        'closed_without_pm_finalize',
        'product_branch_link_revived',
        'ledger_backdated_edit',
        'delivery_item_substituted'
    ) NOT NULL;
