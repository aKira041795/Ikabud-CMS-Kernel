-- Owner report 2026-11-14 (translated from the branch): "Rizal is puzzled — something suddenly adds
-- or withdraws on their ledger when they had not touched anything."
--
-- Cause, measured on tenant 207: an admin has no branch assignment, so an edit back-dated onto a
-- past day lands in a branch's ledger with nothing telling the branch. Branch 9's 3 Nov ledger was
-- edited on 9 Nov between 01:07 and 01:25 (28 adjustment_add rows netting -1,828 plus corrections),
-- long after that day was closed and the branch had moved on.
--
-- This finding type is the notice that closes that silence: it is raised on the branch whose PAST
-- day was changed by somebody who does not belong to it.
--
-- Values AFTER 071/076/078 as they exist in the deployed schema, plus the new member. Appending to
-- an ENUM is safe on MySQL 5.7; the order of existing members is preserved so no row is reinterpreted.
ALTER TABLE dl_integrity_notifications
    MODIFY finding_type ENUM(
        'variance',
        'unresolved_origin',
        'uncounted_receipt',
        'historical_digest',
        'receipt_mismatch',
        'closed_without_pm_finalize',
        'product_branch_link_revived',
        'ledger_backdated_edit'
    ) NOT NULL;
