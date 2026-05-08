-- ============================================================
-- Daily Ledger Module — Backfill legacy cashier-withdrawal "delivery"
-- rows into the new dl_deliveries / dl_branch_receivings tables.
--
-- Strategy:
--   1) For every dl_cashier_withdrawals row with withdrawal_type='delivery'
--      that has not already been backfilled (no matching legacy_id row in
--      dl_deliveries), create one dl_deliveries header (status='posted',
--      origin=branch, destination=branch) plus one dl_delivery_items row.
--   2) When the legacy row has received_at NOT NULL, also create a
--      dl_branch_receivings + dl_branch_receiving_items (status='posted',
--      origin=branch); else create them as 'draft'.
--
-- Idempotent: guarded by NOT EXISTS sub-selects on legacy_cashier_withdrawal_id.
-- ============================================================

INSERT INTO dl_deliveries (
    origin_type, origin_id, destination_type, destination_id,
    dr_number, delivery_date, status,
    created_by, posted_by, posted_at,
    remarks, legacy_cashier_withdrawal_id, created_at, updated_at
)
SELECT
    'branch', cw.branch_id,
    'branch', cw.target_branch_id,
    cw.dr_number, cw.ledger_date, 'posted',
    cw.encoded_by, cw.encoded_by, cw.created_at,
    'Backfilled from dl_cashier_withdrawals', cw.id, cw.created_at, cw.updated_at
FROM dl_cashier_withdrawals cw
WHERE cw.withdrawal_type = 'delivery'
  AND cw.target_branch_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM dl_deliveries d WHERE d.legacy_cashier_withdrawal_id = cw.id
  );

INSERT INTO dl_delivery_items (
    delivery_id, product_id, quantity, unit, price_snapshot, created_at
)
SELECT
    d.id, cw.product_id, cw.quantity, 'pcs',
    COALESCE((SELECT current_price FROM dl_products WHERE id = cw.product_id), 0.00),
    cw.created_at
FROM dl_cashier_withdrawals cw
INNER JOIN dl_deliveries d ON d.legacy_cashier_withdrawal_id = cw.id
WHERE cw.withdrawal_type = 'delivery'
  AND NOT EXISTS (
    SELECT 1 FROM dl_delivery_items di WHERE di.delivery_id = d.id
  );

INSERT INTO dl_branch_receivings (
    branch_id, origin_type, origin_id, delivery_id, dr_number,
    received_by, received_at, received_ledger_date,
    status, posted_by, posted_at,
    remarks, legacy_cashier_withdrawal_id, created_at, updated_at
)
SELECT
    cw.target_branch_id, 'branch', cw.branch_id, d.id, cw.dr_number,
    cw.received_by, cw.received_at,
    COALESCE(cw.received_ledger_date, cw.ledger_date),
    IF(cw.received_at IS NOT NULL, 'posted', 'draft'),
    IF(cw.received_at IS NOT NULL, cw.received_by, NULL),
    cw.received_at,
    'Backfilled from dl_cashier_withdrawals', cw.id, cw.created_at, cw.updated_at
FROM dl_cashier_withdrawals cw
INNER JOIN dl_deliveries d ON d.legacy_cashier_withdrawal_id = cw.id
WHERE cw.withdrawal_type = 'delivery'
  AND cw.target_branch_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM dl_branch_receivings r WHERE r.legacy_cashier_withdrawal_id = cw.id
  );

INSERT INTO dl_branch_receiving_items (
    receiving_id, delivery_item_id, product_id,
    quantity_received, unit, selling_price_snapshot, created_at
)
SELECT
    r.id,
    (SELECT di.id FROM dl_delivery_items di WHERE di.delivery_id = r.delivery_id AND di.product_id = cw.product_id LIMIT 1),
    cw.product_id, cw.quantity, 'pcs',
    COALESCE((SELECT current_price FROM dl_products WHERE id = cw.product_id), 0.00),
    cw.created_at
FROM dl_cashier_withdrawals cw
INNER JOIN dl_branch_receivings r ON r.legacy_cashier_withdrawal_id = cw.id
WHERE cw.withdrawal_type = 'delivery'
  AND NOT EXISTS (
    SELECT 1 FROM dl_branch_receiving_items ri WHERE ri.receiving_id = r.id
  );
