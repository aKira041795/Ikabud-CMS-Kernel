-- 038_add_order_queue.sql
-- Order queue: park the current sale as `pending` so the next customer can be
-- served, then bring it back and take payment when the customer is ready.
--
-- A parked order must not read as a sale. Every report and the shift ledger
-- already filter `status = 'completed'`, so giving parking its own status keeps
-- parked orders out of the takings without editing a single report query.
--
-- A parked order also has no payment method yet, so dc_orders.payment_method_id
-- has to accept NULL. It is filled in at finalization — which is also when
-- transaction_date is stamped, so a sale parked across midnight lands on the
-- day it was actually paid for rather than the day it was parked.
--
-- The existing `draft` value stays: it is the table default and no row uses it,
-- so dropping it would be DDL for no benefit.
--
-- @mysql57-compat: ALTERs guarded via information_schema, no data rewrite.

-- ── status: add 'pending' ──────────────────────────────────────────────────
SET @status_has_pending := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_orders'
      AND column_name = 'status'
      AND column_type LIKE '%pending%'
);

SET @sql := IF(@status_has_pending = 0,
    'ALTER TABLE `dc_orders`
     MODIFY `status` ENUM(''draft'',''pending'',''completed'',''voided'')
     NOT NULL DEFAULT ''draft''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── payment_method_id: NULL until the order is paid ────────────────────────
-- No FK change is needed: the constraint still holds, because NULL satisfies a
-- foreign key and finalization always sets a real method.
SET @payment_is_nullable := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_orders'
      AND column_name = 'payment_method_id'
      AND is_nullable = 'YES'
);

SET @sql := IF(@payment_is_nullable = 0,
    'ALTER TABLE `dc_orders` MODIFY `payment_method_id` INT DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
