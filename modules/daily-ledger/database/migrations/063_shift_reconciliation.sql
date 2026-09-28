-- 063: shift reconciliation — record what the paper sheet and the cash remittance say
--
-- The ledger's sales is derived from counts: beg_bal + addtl - withdraw - bal_end, times
-- the price snapshot. That number is arithmetically sound and internally verifiable (a
-- shift's closing count must equal the next shift's opening), but it can only ever be an
-- INTERNAL check. It cannot say whether the money was collected:
--
--   * stock that leaves the shelf without a recorded `withdraw` reads as a SALE, so the
--     ledger overstates and the cash comes up short;
--   * `withdraw` is also the catch-all for spoilage, so a legitimate write-off and a
--     disappearance look identical in the data.
--
-- Nothing in the ledger can separate those. Only cash can. This branch is fully manual —
-- zero POS rows, zero `dl_sales_day_modes` rows — so the owner's control is external and
-- happens at remittance: the admin compares the digital ledger against the paper sheet
-- and against the cash actually handed over. Until now the system had nowhere to record
-- that comparison, so it lived in the reviewer's head and left no trail.
--
-- This table stores ONLY the two external figures and the reviewer's note.
--
-- Ledger sales is deliberately NOT stored. `sales` is derived, never authoritative (see
-- 061_recompute_stale_sales.sql) — a stored copy would silently go stale the moment any
-- count on the day is corrected, which is precisely the failure 061 had to repair. The
-- page joins this table to the live ledger and computes the difference at read time.
--
-- A row exists only once someone has actually checked that shift. No row means "not yet
-- reconciled", which is different from "reconciled and everything matched", so the page
-- must not treat a missing row as zero-variance.
--
-- paper_sales and cash_remitted are nullable on purpose: a reviewer may have the paper
-- total but not yet the cash, or vice versa. NULL means "not recorded", and the page
-- reports a variance only against a figure that was actually entered.
--
-- @mysql57-compat: InnoDB + utf8mb4 explicit. branch_id is `int unsigned` to match
-- dl_branches.id exactly (a mismatched signedness silently fails the FK on MySQL 5.7).

CREATE TABLE IF NOT EXISTS dl_shift_reconciliation (
  id             bigint unsigned NOT NULL AUTO_INCREMENT,
  branch_id      int unsigned NOT NULL,
  ledger_date    date NOT NULL,
  shift          enum('AM','PM') NOT NULL,
  paper_sales    decimal(12,2) DEFAULT NULL,
  cash_remitted  decimal(12,2) DEFAULT NULL,
  review_note    text,
  recorded_by    int unsigned DEFAULT NULL,
  recorded_at    datetime DEFAULT NULL,
  created_at     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_shift_recon (branch_id, ledger_date, shift),
  KEY idx_dl_shift_recon_date (ledger_date, branch_id),
  CONSTRAINT fk_dl_shift_recon_branch FOREIGN KEY (branch_id) REFERENCES dl_branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
