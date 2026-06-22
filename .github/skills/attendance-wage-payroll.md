---
name: attendance-wage-payroll
description: Payroll computation, benefits deductions, tax, 13th month, and data query patterns for attendance-wage module
applyTo: "**/attendance-wage/**"
---

# Attendance & Wage Payroll Patterns

## Benefits computation
`aw_calculateBenefits($grossPay)` in `helpers.php`:
- Queries `benefits_contribution_rates` table per type (sss, philhealth, pagibig)
- Falls back to `aw_defaultBenefitsRate()` with hardcoded PH statutory rates if no DB row found
- Always wrap in try-catch — DB query can fail if table missing; fall back to defaults

## Payroll computation flow (`aw_computeSalary`)
1. Load employee profile + period
2. Calculate attendance hours → effective hourly rate → base pay by salary type
3. Add premiums (OT, holiday, night diff, rest day)
4. `aw_calculateBenefits($gross)` → zero out disabled benefits by applied flags
5. Add adjustments (additions → gross, deductions → total)
6. Calculate tax via `aw_calculateTax()`
7. Compute net pay = gross - total deductions

## Benefit applicable flags
The `sss_applicable`, `philhealth_applicable`, `pagibig_applicable` columns must be checked in **both** places:
- **Computation**: in `aw_computeSalary()` + `aw_computeSimpleSalary()` — zero out benefit arrays
- **Queries**: in deduction list/detail SQL — add `AND employee_profiles.xxx_applicable = 1` to WHERE

## Tax computation
`aw_calculateTax($grossPay, $deductions, $profile)`:
- Annualizes: `(gross - deductions) * 12`
- Applies PH TRAIN graduated brackets
- **Head of Family**: additional ₱50K exemption per dependent (max 4)
- Single/Married: no additional exemption under TRAIN

## 13th month pay
- Toggle: `thirteenth_month_enabled TINYINT(1) DEFAULT 1` in `employee_profiles`
- Auto-computed per period: `round($regPay / 12, 2)` (basic pay / 12)
- Added to `total_additions` (increases net pay)
- Must register migration in `module.json` `migrations` array

## Module DB query rules
- **No table aliases** — use full table names: `FROM employee_deductions` not `FROM employee_deductions d`
- **No alias prefixes** — use `employee_deductions.amount` not `d.amount`
- All tables must be in `module.json` `owns_tables` or `reads_tables`
- New migrations must be listed in `module.json` `migrations` array

## Employee name consistency
Always use `CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''), NULLIF(suffix,''))` instead of just `CONCAT_WS(' ', first_name, ...)` to avoid trailing/extra spaces from empty fields.

## CSV export columns (itemized deductions)
Single-period and all-periods CSV exports should include individual deduction columns:
`SSS | PhilHealth | Pag-IBIG | Income Tax | Manual Deductions | Cash Advance | Other Deductions | Total Deductions | Net Pay`
