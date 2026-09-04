-- Correction entries may REDUCE an over-recorded amount using a negative
-- quantity (the cashier prefixes the amount with a minus, e.g. -3). The
-- ledger withdraw total is a SUM of row quantities, so a negative correction
-- naturally nets the total down. The quantity column must therefore be SIGNED.
--
-- Existing rows are all non-negative, so unsigned -> signed converts in place.
-- Plain ALTER (migration-033 style); the runner treats 1060/1061 as success.

ALTER TABLE dl_cashier_withdrawals
    MODIFY quantity INT NOT NULL DEFAULT 0;
