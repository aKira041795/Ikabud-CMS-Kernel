SET FOREIGN_KEY_CHECKS = 0;

-- Add unique constraint to pal_receivable_payments for allocation idempotency.
-- Prevents the same payment from being allocated to the same receivable twice.
ALTER TABLE pal_receivable_payments
    ADD UNIQUE KEY uq_pal_rp_allocation (tenant_id, receivable_id, collection_id);

SET FOREIGN_KEY_CHECKS = 1;
