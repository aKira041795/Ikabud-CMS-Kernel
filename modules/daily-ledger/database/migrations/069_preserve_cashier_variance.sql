-- 069: a production correction preserves the cashier's count and raises a resolvable variance
--
-- Two additive changes only. No existing delivery or receiving row is
-- relabelled, and the 118 historical deliveries / 112 historical receipts keep
-- whatever they already stored (all new columns are NULL there).
--
-- 1. dl_variance_flags gains the 'delivery' kind so a sent-vs-received
--    disagreement raised by a production correction enters the existing
--    unreviewe -> investigated -> corrected resolve flow rather than a second
--    lifecycle. It also carries the delivery/receiving evidence, the cashier's
--    original counted value, and the admin's explicit resolution choice.
--
-- 2. dl_deliveries gains receipt_required. NULL is historical/unknown and is
--    treated as "still a dispatch awaiting a count". A Daily Sheet correction is
--    written with an explicit 0 so it can never present as awaiting receipt; a
--    first (dispatch) sheet entry is written with an explicit 1.
--
-- @mysql57-compat: guarded information_schema checks; no CTE/window/CHECK/JSON_TABLE.

-- ── 1. Extend the variance kind vocabulary ──────────────────────────
SET @vf_kind_delivery := (
    SELECT IF(
        LOCATE('delivery', COLUMN_TYPE) > 0,
        'SELECT 1',
        'ALTER TABLE dl_variance_flags MODIFY COLUMN kind ENUM(''overnight'',''handoff'',''ending'',''sales'',''delivery'') NOT NULL DEFAULT ''overnight'''
    )
      FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'kind'
     LIMIT 1
);
PREPARE vf_kind_delivery_st FROM @vf_kind_delivery;
EXECUTE vf_kind_delivery_st;
DEALLOCATE PREPARE vf_kind_delivery_st;

-- ── 2. Delivery-variance evidence columns ───────────────────────────
SET @vf_delivery_id := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'delivery_id'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN delivery_id BIGINT UNSIGNED NULL AFTER shift'
);
PREPARE vf_delivery_id_st FROM @vf_delivery_id; EXECUTE vf_delivery_id_st; DEALLOCATE PREPARE vf_delivery_id_st;

SET @vf_receiving_id := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'receiving_id'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN receiving_id BIGINT UNSIGNED NULL AFTER delivery_id'
);
PREPARE vf_receiving_id_st FROM @vf_receiving_id; EXECUTE vf_receiving_id_st; DEALLOCATE PREPARE vf_receiving_id_st;

SET @vf_sent_qty := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'sent_qty'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN sent_qty INT NULL AFTER receiving_id'
);
PREPARE vf_sent_qty_st FROM @vf_sent_qty; EXECUTE vf_sent_qty_st; DEALLOCATE PREPARE vf_sent_qty_st;

SET @vf_received_qty := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'received_qty'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN received_qty INT NULL AFTER sent_qty'
);
PREPARE vf_received_qty_st FROM @vf_received_qty; EXECUTE vf_received_qty_st; DEALLOCATE PREPARE vf_received_qty_st;

SET @vf_original_counted := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'original_counted_qty'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN original_counted_qty INT NULL AFTER received_qty'
);
PREPARE vf_original_counted_st FROM @vf_original_counted; EXECUTE vf_original_counted_st; DEALLOCATE PREPARE vf_original_counted_st;

SET @vf_counted_by := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'counted_by'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN counted_by INT UNSIGNED NULL AFTER original_counted_qty'
);
PREPARE vf_counted_by_st FROM @vf_counted_by; EXECUTE vf_counted_by_st; DEALLOCATE PREPARE vf_counted_by_st;

SET @vf_choice := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_variance_flags' AND column_name = 'resolution_choice'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN resolution_choice VARCHAR(40) NULL AFTER counted_by'
);
PREPARE vf_choice_st FROM @vf_choice; EXECUTE vf_choice_st; DEALLOCATE PREPARE vf_choice_st;

-- ── 3. Stored receivability classification on the delivery ──────────
SET @dl_receipt_required := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'receipt_required'),
  'SELECT 1',
  'ALTER TABLE dl_deliveries ADD COLUMN receipt_required TINYINT(1) NULL DEFAULT NULL'
);
PREPARE dl_receipt_required_st FROM @dl_receipt_required; EXECUTE dl_receipt_required_st; DEALLOCATE PREPARE dl_receipt_required_st;
