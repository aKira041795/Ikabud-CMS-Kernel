-- One scheduled deduction per cash advance and payroll period.
-- This makes repeated/concurrent approval unable to duplicate a repayment row.
ALTER TABLE `cash_advance_repayments`
    ADD UNIQUE INDEX `uq_advance_period` (`advance_id`, `payroll_period_id`);
