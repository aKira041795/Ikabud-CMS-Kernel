-- Surface branch-to-branch sent-vs-received discrepancies through the existing
-- integrity notification inbox. This is distinct from formal delivery variance
-- flags because informal transfers live in dl_cashier_withdrawals.
ALTER TABLE dl_integrity_notifications
    MODIFY finding_type ENUM(
        'variance',
        'unresolved_origin',
        'uncounted_receipt',
        'historical_digest',
        'receipt_mismatch'
    ) NOT NULL;
