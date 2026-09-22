-- 061: repair stored `sales` values written before the withdrawal recompute fix
--
-- `sales` is DERIVED, never authoritative:
--     sales = beg_bal + addtl - withdraw - bal_end
-- and NULL while no ending is recorded (pending — never substitute 0, an uncounted day
-- must not read as "no sales"). Every report and dashboard derives it from those four
-- columns, so reporting was never wrong. Only the stored column lagged.
--
-- The cashier withdrawal handlers updated `withdraw`/`addtl` and then recomputed only the
-- variance flags, so this column kept the value it held when the ending was set — computed
-- while withdraw was still 0. On live data, 2026-09-18 AM at branch 8, 7 of 173 rows were
-- stale by exactly the withdrawal amount: BBS-0110 stored 310 (= addtl 340 - bal_end 30,
-- withdrawal of 71 omitted) where the invariant gives 239.
--
-- The handlers now recompute on every write, including the offline device replay. This
-- repairs the rows already written. It changes no reporting semantics — the reports were
-- already computing this same expression.
--
-- Idempotent: only rows that actually disagree are touched, so re-running is a no-op.
-- updated_at is deliberately NOT bumped: this is a derived column being brought back into
-- agreement, not an operator edit, and stamping it would pair today's timestamp with the
-- original updated_by and misattribute the change.
--
-- @mysql57-compat: CASE / GREATEST / COALESCE / <=> only — no window functions, no CTEs.

UPDATE dl_daily_ledger
SET sales = CASE
        WHEN bal_end IS NULL THEN NULL
        ELSE GREATEST(0, COALESCE(beg_bal, 0) + COALESCE(addtl, 0) - COALESCE(withdraw, 0) - COALESCE(bal_end, 0))
    END
WHERE NOT (
    sales <=> CASE
        WHEN bal_end IS NULL THEN NULL
        ELSE GREATEST(0, COALESCE(beg_bal, 0) + COALESCE(addtl, 0) - COALESCE(withdraw, 0) - COALESCE(bal_end, 0))
    END
);
