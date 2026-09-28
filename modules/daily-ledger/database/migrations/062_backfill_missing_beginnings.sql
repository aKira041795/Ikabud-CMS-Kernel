-- 062: repair ledger rows whose opening count was never carried forward
--
-- A ledger row's sales is DERIVED, never authoritative:
--     sales = beg_bal + addtl - withdraw - bal_end   (NULL while no ending is recorded)
--
-- `beg_bal` is the opening count, and the application's rule is that it equals the
-- preceding shift's ending:
--     AM -> preceding day's PM ending   (falling back to that day's AM ending)
--     PM -> the same day's AM ending
-- (see `dl_autoCarryBeginnings()` in handlers.php).
--
-- When that carry never happens the row keeps beg_bal = 0 while a real ending is later
-- recorded, so `beg_bal + addtl - withdraw - bal_end` goes NEGATIVE and `GREATEST(0, ...)`
-- flattens it to 0. The row then reports no sales at all instead of the sales that
-- actually occurred. Because the clamp is silent, a broken shift looks like a quiet day.
--
-- Observed on live data (baronledger, branch 8 "Miputak", 2026-09-21):
--   * 09-21 AM rows were created 2026-09-20 21:13 -> 09-21 01:00 and PM at 07:38 -> 08:06,
--     i.e. the day was worked in real time.
--   * The preceding day (09-20) was not entered until 2026-09-22 (its endings all carry a
--     single 21:02 timestamp, the signature of a bulk write). At the moment 09-21 was
--     worked there was therefore NOTHING to carry.
--   * 143 rows clamped to 0 sales; the day reported PHP 4,464.55 instead of ~PHP 24,517.
--   * On 2026-09-22 the operator back-filled 09-17..09-22 through `apiSaveLedgerBatch`
--     (the `row_update` audit trail shows 09-21 PM covered but 09-21 AM skipped), so the
--     miss survived. The variance engine had flagged all of it: 84 `overnight` + 88
--     `handoff` flags on the day, all left `unreviewed`.
--
-- THE DEFECT THIS REPAIRS
--     A row where ALL of these hold:
--         beg_bal = 0                 the opening was never carried
--         bal_end IS NOT NULL         the shift is finished, so the ending is authoritative
--         carrying source > 0         the preceding shift closed holding stock
-- Stock does not evaporate: if the preceding shift closed with stock, this shift opened
-- with it. A zero opening against a positive preceding closing can only be overridden
-- legitimately by recording where the stock went (`withdraw`), which is a different column
-- and is left untouched here.
--
-- Rows whose preceding shift closed at 0 are NOT touched - that is the ordinary case for a
-- product receiving stock from a delivery during the shift (beg_bal 0, stock arrives as
-- `addtl`), and it is the large majority of zero-opening rows.
--
-- SCOPE, MEASURED
-- 186 rows across the whole tenant (baronledger, 2026-08-15..2026-09-28):
--     2026-09-21 AM  84      2026-08-28 AM  1      2026-09-22 PM  2
--     2026-09-21 PM  87      2026-08-29 AM  7      2026-09-24 AM  1
--     2026-09-02 AM   1      2026-09-13 PM  1      2026-09-25 AM  1
-- So 171 of them are the single broken shift, and the remaining 15 are one or two rows on
-- seven other days - the same defect at low volume rather than a different one.
--
-- An earlier draft of this migration gated on the whole shift having lost its beginnings
-- (a >50% majority test with a 20-row floor). That gate was a proxy, not the rule, and it
-- was wrong in both directions: its majority was measured against ALL ended rows rather
-- than those with a positive carrying source, so it fired on almost every shift, yet it
-- still missed 8 genuine rows on shifts that failed the gate. The per-row test above is the
-- actual definition and has no such edges.
--
-- WHAT IT DOES NOT DO
-- It invents nothing: beg_bal is re-pointed at an ending that already exists, so every
-- repaired row's sales is still derived entirely from counts an operator recorded. It never
-- touches a row with no ending (a shift still in progress), a row already carrying a
-- non-zero opening, or any `addtl`/`withdraw` value. It does NOT resolve the variance flags
-- raised on these rows - those stay `unreviewed` so the original detection is preserved.
--
-- Idempotent: after the repair beg_bal is non-zero, so no row matches again. Re-running
-- changes nothing.
--
-- updated_by is deliberately left alone, so the repair is not attributed to whichever
-- operator last edited the row (same precedent as 061). updated_at IS advanced - the
-- column carries ON UPDATE CURRENT_TIMESTAMP, so the SET below bumps it whether or not we
-- ask; 061 documents the same effect rather than working around it.
--
-- To reverse: every row this changes had beg_bal = 0 beforehand, so restoring means setting
-- beg_bal = 0 on the rows whose updated_at matches the run and recomputing sales.
--
-- @mysql57-compat: GREATEST / COALESCE / DATE_SUB only - no window functions, no CTEs.

-- Step 1 of 2 - PM shifts: the beginning is the same day's AM ending.
UPDATE dl_daily_ledger dl
  JOIN dl_daily_ledger am
    ON am.branch_id = dl.branch_id
   AND am.product_id = dl.product_id
   AND am.shift = 'AM'
   AND am.ledger_date = dl.ledger_date
   SET dl.beg_bal = am.bal_end,
       dl.sales = GREATEST(0,
                      am.bal_end
                    + COALESCE(dl.addtl, 0)
                    - COALESCE(dl.withdraw, 0)
                    - COALESCE(dl.bal_end, 0))
 WHERE dl.shift = 'PM'
   AND dl.beg_bal = 0
   AND dl.bal_end IS NOT NULL
   AND am.bal_end > 0;

-- Step 2 of 2 - AM shifts: the opening is the preceding day's PM ending, falling back to
-- that day's AM ending when the PM shift has no ending of its own. Mirrors the CASE in
-- dl_autoCarryBeginnings(). Order does not matter: step 1 only writes PM rows while reading
-- AM endings, and no statement here ever modifies a bal_end.
UPDATE dl_daily_ledger dl
  LEFT JOIN dl_daily_ledger p_pm
    ON p_pm.branch_id = dl.branch_id
   AND p_pm.product_id = dl.product_id
   AND p_pm.shift = 'PM'
   AND p_pm.ledger_date = DATE_SUB(dl.ledger_date, INTERVAL 1 DAY)
  LEFT JOIN dl_daily_ledger p_am
    ON p_am.branch_id = dl.branch_id
   AND p_am.product_id = dl.product_id
   AND p_am.shift = 'AM'
   AND p_am.ledger_date = DATE_SUB(dl.ledger_date, INTERVAL 1 DAY)
   SET dl.beg_bal = COALESCE(p_pm.bal_end, p_am.bal_end),
       dl.sales = GREATEST(0,
                      COALESCE(p_pm.bal_end, p_am.bal_end)
                    + COALESCE(dl.addtl, 0)
                    - COALESCE(dl.withdraw, 0)
                    - COALESCE(dl.bal_end, 0))
 WHERE dl.shift = 'AM'
   AND dl.beg_bal = 0
   AND dl.bal_end IS NOT NULL
   AND COALESCE(p_pm.bal_end, p_am.bal_end) > 0;
