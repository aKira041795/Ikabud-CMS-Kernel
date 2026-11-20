-- 110: allow a PURCHASE RECEIPT to be recorded against a commissary.
--
-- WHY. 56 of the 128 products carrying a negative hub balance on tenant 207 have NEVER had a single
-- production entry: they are bought-in goods (softdrinks, Wilkins, coffee, candle sticks). The hub
-- ledger has exactly one inflow column (produced_qty) and only two writers that credit it —
-- production output and saleable returns — so dispatching a purchased item debits a balance nothing
-- ever credits. Dispatch then drives the balance negative and carry-forward keeps it there forever.
-- `direct_purchase` already exists as a supply CLASSIFICATION but had no writer at all.
--
-- The receipt is recorded as a movement of type 'purchase' in dl_production_movements, which carries
-- dr_number (the supplier document), the actor and the quantity, and credits the hub ledger through
-- the SAME delta primitive every other movement uses. The movement type is what keeps the record
-- honest: the hub arithmetic has one inflow column, and the purchase/receipt distinction lives in
-- the movement, not in a second balance column.
--
-- Idempotent: MODIFY with the full enum list, so re-running is a no-op. Same pattern as 105.
ALTER TABLE dl_production_movements
    MODIFY COLUMN movement_type
        ENUM('withdrawal','output','reverse','purchase') NOT NULL;
