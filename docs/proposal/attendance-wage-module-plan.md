# Attendance & Wage Module — Detailed Design Plan

> **Module ID**: `attendance-wage`  
> **Source**: Ported from CodeIgniter bakeshopapp (`/var/www/bakeshopapp`)  
> **Target**: Ikabud kernel (`/var/www/html/applicationostest`)  
> **Date**: 2026-06-18

---

## Table of Contents
1. [Source Analysis](#1-source-analysis)
2. [Flexibility Review — Attendance Features](#2-flexibility-review--attendance-features)
3. [UI/UX: Flows, Dashboard & Views](#3-uiux-flows-dashboard--views)
4. [Role-Based Access Control](#4-role-based-access-control)
5. [Additional Suggestions](#5-additional-suggestions)
6. [Database Schema](#6-database-schema)
7. [Module Structure & Routes](#7-module-structure--routes)
8. [Implementation Phases](#8-implementation-phases)

---

## 1. Source Analysis

### 1.1 What the CodeIgniter app has (complete inventory)

| # | Feature | CI Implementation | Table(s) |
|---|---|---|---|
| 1 | Clock-in/out + photo | `AttendanceController::clockIn/clockOut` — GD image resize, base64 + file upload, location verification | `user_attendance` |
| 2 | Overnight shift handling | `AttendanceModel::getTodayAttendance` — checks incomplete records from yesterday | `user_attendance` |
| 3 | Attendance history (self) | `AttendanceController::history` — employee views own records with date filter | `user_attendance` |
| 4 | Attendance report (admin) | `AttendanceController::report` — morning/afternoon shift grouping, store/city/prefix/employee filters, supervisor vs admin views | `user_attendance`, `users`, `stores` |
| 5 | Photo serving | `AttendanceController::photo` — serves from multiple fallback paths | `user_attendance` |
| 6 | Employee profiles | `EmployeeProfileModel` — salary type/rates, overtime policy, gov't IDs, tax status, dependents | `employee_profiles` |
| 7 | Payroll periods | `PayrollPeriodModel` — weekly/bi-weekly/semi-monthly/monthly, draft→processing→completed lifecycle, date overlap check, auto-expiry | `payroll_periods` |
| 8 | Salary computation | `SalaryComputationModel::computeSalary` — attendance hours→pay, overtime, holiday, night diff, benefits, tax, net pay | `salary_computations` |
| 9 | Bulk computation | `SalaryComputationModel::bulkComputeSalaries` — all active employees for a period | `salary_computations` |
| 10 | Approval workflow | Status transitions: computed→approved→paid, with timestamps and user tracking | `salary_computations` |
| 11 | Salary adjustments | `SalaryAdjustmentModel` — bonuses, allowances, penalties, deductions, 13th month, holiday bonus, corrections | `salary_adjustments` |
| 12 | Employee deductions | `EmployeeSalaryDeductionModel` — store-level deductions with status tracking, transaction reference | `employee_salary_deductions` |
| 13 | Government benefits | `BenefitsContributionRateModel` — SSS/PhilHealth/Pag-IBIG rate tables, salary-bracket lookup, min/max caps | `benefits_contribution_rates` |
| 14 | Benefits calculator | `Admin\PayrollController::benefitsCalculator` — standalone calculator tool | — |
| 15 | Employee schedules | `EmployeeScheduleModel` — 7-day schedules per employee/store/week, shift types, day-off flag, cashier cap (max 3/day) | `employee_schedules` |
| 16 | Overtime policies | Embedded in `employee_profiles` columns + dedicated `payroll_overtime_policies` view with bulk update | `employee_profiles` |
| 17 | Cash advances | **NOT a dedicated feature.** Handled ad-hoc via `employee_salary_deductions` with status tracking (pending→approved→processed) | `employee_salary_deductions` |
| 18 | Migration wizard | `Admin\PayrollController::migrationWizard` — bulk-create employee profiles from users table | — |
| 19 | Reports | Period reports with employee count, total payroll, net pay summaries, print layout | — |
| 20 | Auth/Roles | Role-based: supervisor (role_id=2), admin (3), admin HR (8); permission gating via `RolePermissions::hasPermission` | — |

### 1.2 What the CI app does NOT have (gaps to fill in Ikabud)

| Gap | Priority | How we address it in Ikabud |
|---|---|---|
| Dedicated cash advance system | **High** | New table `cash_advances` with approval workflow, repayment schedule, balance tracking |
| Holiday calendar | **High** | New table `holidays` — admin-defined dates, pay rate multiplier, per-year configuration |
| Rest day pay premiums | **High** | CI tracks off days in schedules but pays regular rate. Add 130%+ rest day rates per PH labor code, auto-detection from schedule |
| Per-employee holiday pay toggle | **Medium** | Add `holiday_pay_enabled` boolean to `employee_profiles` |
| Leave management (sick/vacation) | **Medium** | New table `employee_leaves` — leave types, balances, approvals |
| Real-time attendance dashboard | **Medium** | Polling-based "who's clocked in right now" view |
| Pay period auto-generation | **Low** | Cron/scheduled job to auto-create next period when current ends |
| Payslip generation (PDF) | **Low** | Generate printable payslips per employee per period |
| Audit log for all payroll mutations | **Low** | Hook into kernel's `audit_logs` table |

---

## 2. Flexibility Review — Attendance Features

### 2.1 Day Shift / Night Shift

**CI Status**: Partially supported. `employee_schedules` has `shift_type` and `start_time`/`end_time`. `SalaryComputationModel` calculates night differential (10% extra for 10PM-6AM).

**Ikabud Enhancement**:

| Setting | Default | Notes |
|---|---|---|
| Shift type | `day` | Per-employee: `day`, `night`, `rotating`. Used for schedule validation and report grouping |
| Night diff rate | 10% | Overridable per employee via `night_diff_rate` |
| Night diff window | 10PM–6AM | Configurable per tenant via `payroll_settings` |
| Night diff start | 22:00 | Time night differential begins |
| Night diff end | 06:00 | Time night differential ends |

**Flexibility**: ✅ Full — supports fixed day/night, rotating shifts, configurable differential rates and windows per employee and per tenant.

### 2.2 Standard 8-Hour Workday + Overtime

**CI Status**: `max_daily_hours` (default 8) and `max_weekly_hours` (default 40) in `employee_profiles`. Overtime = hours beyond daily max, capped at weekly max.

**Ikabud Enhancement**:

| Setting | Default | Description |
|---|---|---|
| `max_daily_hours` | 8.00 | Regular hours per day before overtime kicks in |
| `max_weekly_hours` | 40.00 | Maximum weekly hours including overtime |
| `overtime_allowed` | Yes (1) | Master toggle per employee |
| `overtime_rate` | 1.25× | Standard overtime multiplier |
| `double_overtime_rate` | 1.50× | Rate for hours beyond 10/day or beyond weekly cap |
| `overtime_requires_approval` | No (0) | If on, overtime hours tracked as `unapproved_hours` until approved |

**Overtime computation rules**:

| Scenario | Rate Applied |
|---|---|
| Hours 8–10 in a day (within weekly 40) | 1.25× (standard OT) |
| Hours 10–12 in a day | 1.50× (double OT) |
| Hours beyond weekly 40 | 1.50× (weekly cap exceeded) |
| Overtime not allowed (toggle OFF) | 0× (hours tracked but unpaid) |
| Overtime requires approval | Hours tracked as `unapproved_hours`, paid only after approval |

**Flexibility**: ✅ Full — per-employee toggle, configurable daily/weekly caps, two-tier overtime rates, approval gating.

### 2.3 Payroll Generation & Computation Settings (per hour/day)

**CI Status**: Three salary types — `hourly`, `daily`, `monthly`. Computes effective rates via `getEffectiveSalaryRate()`.

**Ikabud Enhancement**:

| Salary Type | Basic Pay Formula | Hours Tracking |
|---|---|---|
| `hourly` | `hours_worked × hourly_rate` | Raw clock hours |
| `daily` | `days_worked × daily_rate` | Hours / working_hours_per_day = days |
| `monthly` | `monthly_rate × (days_worked / working_days_per_month)` | Pro-rated by working days |
| `fixed` | `basic_salary` (flat per period) | No hour tracking |

**Tenant-level payroll settings** (`payroll_settings` table):

| Setting | Default | Description |
|---|---|---|
| `working_days_per_month` | 22 | Used for monthly pro-rating |
| `working_hours_per_day` | 8.00 | Used for daily rate calculation |
| `overtime_calculation` | `both` | `daily`, `weekly`, or `both` |
| `round_hours_to` | `0.25` | Round hours to nearest 0.25 (15 min) |
| `pay_frequency` | `semi_monthly` | Default period type for new periods |

**Flexibility**: ✅ Full — per-tenant payroll defaults, per-employee salary type, explicit rate fields, configurable rounding.

### 2.4 Holiday Rate (Allowed: Yes/No)

**CI Status**: Holiday pay computed at 2× unconditionally. No holiday calendar. No per-employee toggle.

**Ikabud Enhancement — dedicated `holidays` table**:

```sql
CREATE TABLE holidays (
    holiday_id      INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       VARCHAR(36) NOT NULL,
    holiday_name    VARCHAR(200) NOT NULL,
    holiday_date    DATE NOT NULL,
    holiday_type    ENUM('regular','special_non_working','special_working') DEFAULT 'regular',
    pay_multiplier  DECIMAL(4,2) DEFAULT 2.00,
    is_recurring    TINYINT(1) DEFAULT 0,
    is_active       TINYINT(1) DEFAULT 1,
    UNIQUE KEY (tenant_id, holiday_date)
);
```

**Per-employee toggle**: `employee_profiles.holiday_pay_enabled TINYINT(1) DEFAULT 1`

| Holiday Type | Default Multiplier | Philippine Labor Code Reference |
|---|---|---|
| Regular holiday | 2.00× (200%) | If worked: 200%; if not worked: 100% |
| Special non-working | 1.30× (130%) | If worked: 130%; if not worked: 0% |
| Special working | 1.00× (100%) | Standard rate |

**Flexibility**: ✅ Full — admin-defined holiday calendar, per-type multipliers, per-employee enable/disable, recurring yearly holidays.

### 2.5 Rest Day / Off Day Pay

**CI Status**: ⚠️ Partial. `EmployeeScheduleModel` tracks rest days via `is_dayoff` flag and the schedule view displays "OFF" for rest days. However, `SalaryComputationModel` does **NOT** apply rest day premium rates — work on a rest day is paid at the regular rate with no 130% premium. This is a labor compliance gap.

**Ikabud Enhancement**:

```
employee_profiles:
  + rest_day_schedule       VARCHAR(20) DEFAULT 'sunday'   -- which day is rest day
  + rest_day_pay_enabled    TINYINT(1) DEFAULT 1           -- toggle rest day premium on/off
  + rest_day_rate           DECIMAL(4,2) DEFAULT 1.30      -- 130% per labor code
  + night_diff_enabled      TINYINT(1) DEFAULT 1           -- toggle night differential on/off
  + cash_advance_allowed    TINYINT(1) DEFAULT 1           -- toggle cash advance eligibility on/off

payroll_settings (tenant-level):
  + default_rest_day        VARCHAR(20) DEFAULT 'sunday'   -- company default
  + rest_day_rate           DECIMAL(4,2) DEFAULT 1.30
  + night_diff_rate         DECIMAL(4,2) DEFAULT 0.10      -- 10% default
```

**Rest day detection in salary computation**:
1. Check `employee_schedules` for that date — if `is_dayoff = 1`, it's a rest day
2. Fallback: check `employee_profiles.rest_day_schedule` against the clock-in day of week
3. Fallback: use `payroll_settings.default_rest_day`

**Rest day pay rates (Philippine Labor Code)**:

| Scenario | Rate | Formula |
|---|---|---|
| Work on rest day | 130% | `hourly_rate × 1.30` |
| Rest day + overtime (first 8h) | 169% | `hourly_rate × 1.30 × 1.30` |
| Rest day + overtime (beyond 8h) | 195% | `hourly_rate × 1.30 × 1.50` |
| Special holiday on rest day | 150% | `hourly_rate × 1.50` |
| Special holiday + rest day + OT | 195% | `hourly_rate × 1.50 × 1.30` |
| Regular holiday on rest day | 260% | `hourly_rate × 2.60` (200% + 60%) |
| Regular holiday + rest day + OT | 338% | `hourly_rate × 2.60 × 1.30` |

**Salary computation integration**:

```php
// In helpers.php — calculateAttendanceHours()
$isRestDay = $schedule['is_dayoff'] 
    ?? (date('l', strtotime($date)) === ucfirst($employee['rest_day_schedule']));

if ($isRestDay && $totalHours > 0) {
    $restDayHours = $totalHours;
    $restDayPay = $restDayHours * $hourlyRate * $restDayRate;
    // Regular pay still applies for the base hours if rest day work was required
    $regularPay += $restDayHours * $hourlyRate;  // base pay
    $restDayPremium = $restDayHours * $hourlyRate * ($restDayRate - 1.0); // 30% premium
}
```

**Special case — no work on rest day**: If employee does NOT work on their scheduled rest day, no pay is deducted (rest day is unpaid unless worked, per Philippine labor practice).

**Flexibility**: ✅ Full — per-employee rest day assignment, per-tenant default, auto-detection from schedule, full PH labor code rest day rate matrix including combinations with holidays and overtime.

### 2.6 Cash Advances

**CI Status**: ❌ Not a dedicated feature. Handled manually via `employee_salary_deductions` (generic deduction records).

**Ikabud Enhancement — dedicated `cash_advances` table**:

```sql
CREATE TABLE cash_advances (
    advance_id          INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(36) NOT NULL,
    user_id             INT NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    balance             DECIMAL(12,2) NOT NULL,
    request_date        DATETIME NOT NULL,
    repayment_type      ENUM('full_next_payroll','installment','lumpsum_date') DEFAULT 'full_next_payroll',
    installment_amount  DECIMAL(12,2) DEFAULT NULL,
    total_installments  INT DEFAULT NULL,
    paid_installments   INT DEFAULT 0,
    target_repay_date   DATE DEFAULT NULL,
    status              ENUM('pending','approved','active','completed','denied','cancelled') DEFAULT 'pending',
    requested_by        INT NOT NULL,
    approved_by         INT DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NOT NULL
);

CREATE TABLE cash_advance_repayments (
    repayment_id        INT AUTO_INCREMENT PRIMARY KEY,
    advance_id          INT NOT NULL,
    payroll_period_id   INT NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    deduction_method    ENUM('salary_deduction','manual_payment') DEFAULT 'salary_deduction',
    status              ENUM('pending','deducted','paid') DEFAULT 'pending',
    created_at          DATETIME NOT NULL
);
```

**Cash advance rules** (configurable per tenant):
- Max advance: 50% of monthly basic salary (default, overridable)
- Max active advances per employee: 2 (configurable)
- Repayment: full next payroll, installments (2–6 periods), or lumpsum date
- Auto-deducted from salary computation if status = `active`
- Approval workflow: employee requests → supervisor approves/rejects

**Flexibility**: ✅ Full — dedicated lifecycle, installment plans, auto-deduction integration with payroll, configurable limits.

### 2.7 Social Benefits (SSS, PhilHealth, Pag-IBIG)

**CI Status**: Rate-table driven via `benefits_contribution_rates`. Salary bracket lookup, employee + employer shares, min/max caps.

**Ikabud Enhancement**:

| Setting | Description |
|---|---|
| Rate table per benefit type | SSS, PhilHealth, Pag-IBIG — each with salary brackets, effective dates |
| Employee share | Percentage or fixed amount per bracket |
| Employer share | Percentage or fixed amount per bracket |
| Min/max contribution caps | Per bracket or global |
| Per-employee benefit toggles | `sss_applicable`, `philhealth_applicable`, `pagibig_applicable` |

**Flexibility**: ✅ Full — multi-tenant rate tables, salary brackets with fixed or percentage, per-employee benefit toggles, effective dating for rate changes.

---

## 3. UI/UX: Flows, Dashboard & Views

### 3.1 User Personas & Their Journeys

| Persona | Role | Primary Actions |
|---|---|---|
| **Employee (Worker)** | `attendance-wage.clock` | Clock in/out, view own history, view own payslip, request cash advance |
| **Supervisor** | `attendance-wage.manage` | View team attendance, approve OT, approve cash advances, view team schedule |
| **HR/Admin** | `attendance-wage.manage` + `attendance-wage.approve` | Full payroll processing, employee profiles, adjustments, benefits rates, reports |
| **Owner** | All capabilities | Dashboard overview, financial summaries, audit trail |

### 3.2 Main Dashboard (`/admin/wage`)

A **single-page overview** with KPI cards, quick actions, and alerts:

```
┌─────────────────────────────────────────────────────────────┐
│  Wage & Attendance Dashboard                       [Period] │
├─────────────┬─────────────┬─────────────┬───────────────────┤
│ 👥 Active   │ 🕐 Clocked  │ ⚠️ Pending  │ 💰 Current Period │
│  Employees  │  In Today   │  Approvals  │  Jun 1-15 '26     │
│     42      │     38      │      5      │  Status: Active   │
├─────────────┴─────────────┴─────────────┴───────────────────┤
│  Quick Actions                                              │
│  [Clock In/Out]  [View Team]  [Process Payroll]  [Reports]  │
├──────────────────────────────────────────┬──────────────────┤
│  📊 Today's Attendance by Store          │ 📋 Recent Activity│
│  ┌──────────┬────┬────┬────┐            │ • Nelma P. in    │
│  │ Store    │ In │Out │Late│            │ • Period approved│
│  │ DC Main  │ 12 │  - │  0 │            │ • Juan OT pending│
│  │ Julies A │  8 │  5 │  1 │            │ • Advance #42 ok │
│  │ Julies B │  7 │  - │  2 │            │                  │
│  └──────────┴────┴────┴────┘            │                  │
├──────────────────────────────────────────┴──────────────────┤
│  💵 Payroll Period Summary                                  │
│  Gross: ₱245,000 | Deductions: ₱32,500 | Net: ₱212,500      │
│  [View Computations]  [Generate Report]  [Approve All]       │
└─────────────────────────────────────────────────────────────┘
```

### 3.3 Clock In/Out View (`/admin/attendance`)

Mobile-first, camera-enabled (Alpine.js + getUserMedia):

```
┌──────────────────────────┐
│       🕐 8:42 AM          │
│    Wednesday, Jun 18      │
│                           │
│    ┌─────────────────┐    │
│    │    📷 Camera     │    │
│    │    Preview       │    │
│    │                 │    │
│    └─────────────────┘    │
│                           │
│   Store: DC Main  ✓       │
│   Shift: Day      ✓       │
│                           │
│   ┌───────────────────┐   │
│   │    CLOCK IN        │   │
│   └───────────────────┘   │
│                           │
│   Today: In 8:42 AM       │
│   Status: Working...      │
│   Duration: —             │
└──────────────────────────┘
```

### 3.4 Attendance Report View

Filter bar + grouped table:

```
Filters: [Store ▾] [City ▾] [Date Range ▾] [Employee ▾] [Search...]

┌──────────────────────────────────────────────────────────────┐
│ Date       │ Employee        │ Store   │ Morning    │ Afternoon│
├────────────┼─────────────────┼─────────┼────────────┼──────────┤
│ Jun 18 '26 │ Nelma Pagente   │ DC Main │ 8:00-12:00 │ 1:00-5:00│
│ Jun 18 '26 │ Juan Dela Cruz  │ DC Main │ 6:00-2:00  │    —     │
│ Jun 18 '26 │ Ana Santos      │ Julies A│ 8:15-12:00 │ 1:15-5:30│
└──────────────────────────────────────────────────────────────┘
```

### 3.5 Payroll Computation View

Period selector + computation list with inline detail expansion:

```
Period: [June 1-15, 2026 (Semi-Monthly) ▾]  [Bulk Compute] [Approve All]

┌─────────────────────────────────────────────────────────────────────┐
│ Employee        │ Gross    │ Deduct.  │ Net      │ Status   │ Actions│
├─────────────────┼──────────┼──────────┼──────────┼──────────┼────────┤
│ Nelma Pagente   │ ₱7,500  │ ₱1,250   │ ₱6,250  │ Approved │ [▼][📄]│
│ ├─ Reg: 88h×₱68.18 = ₱6,000                                         │
│ ├─ OT:  12h×₱85.23 = ₱1,023                                         │
│ ├─ Night: 8h×₱6.82  = ₱55                                           │
│ ├─ Holiday: 8h×₱136.36 = ₱1,091 (Jun 12, Independence Day)          │
│ ├─ SSS: ₱337.50 | PhilHealth: ₱150 | Pag-IBIG: ₱100                 │
│ └─ Tax: ₱0 | Net: ₱6,250                                            │
│ Juan Dela Cruz  │ ₱6,200  │ ₱980     │ ₱5,220  │ Computed │[Compute]│
│ Ana Santos      │ ₱5,800  │ ₱890     │ ₱4,910  │ Paid     │ [▼][📄]│
└─────────────────────────────────────────────────────────────────────┘
```

### 3.6 Cash Advance View

```
My Cash Advances                          [Request New Advance]

Active Advances:
┌──────────────────────────────────────────────────────────────┐
│ #45 │ ₱3,000 │ Requested: Jun 15 │ Approved: Jun 16          │
│ Balance: ₱2,000 │ Installments: 2/3 paid                     │
│ Next deduction: Jul 1-15 payroll (₱1,000)                    │
│ [View Schedule]                                              │
├──────────────────────────────────────────────────────────────┤
│ #42 │ ₱1,500 │ Completed Jun 5                               │
└──────────────────────────────────────────────────────────────┘

Request New Advance:
  Amount: [______]  Max allowed: ₱3,750 (50% of monthly)
  Repayment: (•) Full next payroll  ( ) 3 installments  ( ) 6 installments
  Reason: [___________________________]
  [Submit for Approval]
```

### 3.7 Employee Schedule View

Weekly grid with shift assignment:

```
Store: [DC Main ▾]  Week: [Jun 15-21 ▾]

┌──────────┬───────┬───────┬───────┬───────┬───────┬──────┬──────┐
│ Employee │ Mon   │ Tue   │ Wed   │ Thu   │ Fri   │ Sat  │ Sun  │
├──────────┼───────┼───────┼───────┼───────┼───────┼──────┼──────┤
│ Nelma P. │ 6-2   │ 6-2   │ OFF   │ 6-2   │ 6-2   │ 6-2  │ OFF  │
│ Juan D.  │ 2-10  │ 2-10  │ 2-10  │ OFF   │ 2-10  │ 2-10 │ OFF  │
│ Ana S.   │ 6-2   │ OFF   │ 6-2   │ 6-2   │ 6-2   │ OFF  │ 6-2  │
└──────────┴───────┴───────┴───────┴───────┴───────┴──────┴──────┘
Cashiers scheduled today (Mon): 2/3  ⚠️ Max 3 per day enforced
```

---

## 4. Role-Based Access Control

### 4.1 Capability Model

| Capability | Scope | Description |
|---|---|---|
| `attendance-wage.clock@1` | Self | Clock in/out, view own attendance history |
| `attendance-wage.read@1` | Own records | View own payslips, cash advance status |
| `attendance-wage.manage@1` | Team | View team attendance, approve overtime, create employee deductions |
| `attendance-wage.approve@1` | Organization | Approve salary computations, adjustments, cash advances |
| `attendance-wage.admin@1` | Organization | Full CRUD: employees, periods, benefits rates, holidays, settings |

### 4.2 Access Matrix

| Action | Worker | Supervisor | HR/Admin | Owner |
|---|---|---|---|---|
| Clock in/out | ✅ | ✅ | ✅ | ✅ |
| View own history | ✅ | ✅ | ✅ | ✅ |
| View own payslip | ✅ | ✅ | ✅ | ✅ |
| Request cash advance | ✅ | ✅ | ✅ | ✅ |
| View team attendance | ❌ | ✅ | ✅ | ✅ |
| Edit attendance records | ❌ | ✅ | ✅ | ✅ |
| Approve overtime | ❌ | ✅ | ✅ | ✅ |
| Approve cash advances | ❌ | ✅ (own team) | ✅ | ✅ |
| Create employee deductions | ❌ | ✅ (own store) | ✅ | ✅ |
| Manage employee profiles | ❌ | ❌ | ✅ | ✅ |
| Create payroll periods | ❌ | ❌ | ✅ | ✅ |
| Compute salaries | ❌ | ❌ | ✅ | ✅ |
| Approve salary computations | ❌ | ❌ | ✅ | ✅ |
| Manage salary adjustments | ❌ | ❌ | ✅ | ✅ |
| Manage benefits rates | ❌ | ❌ | ✅ | ✅ |
| Manage holidays | ❌ | ❌ | ✅ | ✅ |
| View all reports | ❌ | ❌ | ✅ | ✅ |
| Configure module settings | ❌ | ❌ | ❌ | ✅ |

### 4.3 Store-Scoped Access for Supervisors

Supervisors can only see/manage employees assigned to their stores (`user_stores` table). All queries are scoped by `store_id IN (supervisor's assigned stores)`.

### 4.4 Permission Gating Implementation

```php
// In handlers/00-bootstrap.php
function attendanceWageGuard(string $capability): void {
    $userId = app()->auth()->userId();
    if (!app()->capabilities()->userHas($userId, $capability)) {
        app()->abort(403, 'Insufficient permissions');
    }
}

// Usage in handler:
function attendanceApiClockIn(): void {
    attendanceWageGuard('attendance-wage.clock@1');
    // ...
}
```

---

## 5. Additional Suggestions

### 5.1 Suggested Enhancements Beyond CI Source

| # | Suggestion | Rationale | Priority |
|---|---|---|---|
| 1 | **Real-time "Who's Clocked In" widget** | No CI equivalent. Valuable for supervisors to see floor status instantly. | High |
| 2 | **Holiday calendar with recurring rules** | CI has NO holiday awareness. Critical for Philippine labor compliance (13+ regular holidays/year). | High |
| 3 | **Dedicated cash advance module** | CI handles this as generic deductions. A proper system with balance tracking, installment plans, and auto-deduction is essential. | High |
| 4 | **Leave management (sick/vacation/emergency)** | CI has nothing. Even basic leave tracking with balances would be a major value-add. | Medium |
| 5 | **Audit log for ALL payroll mutations** | CI has none. Kernel already has `audit_logs` — hook into it for every computation, adjustment, approval. | Medium |
| 6 | **Payslip generation (HTML→PDF)** | CI has no payslip. Each employee should see/download their payslip per period. | Medium |
| 7 | **Auto-generate next payroll period** | CI requires manual creation. A cron job or trigger on period completion would reduce admin work. | Low |
| 8 | **Geolocation fence for clock-in** | CI has `location_in`/`location_out` fields but no enforcement. Add store GPS coordinates + radius check. | Low |
| 9 | **Break time tracking** | CI has no break tracking. For labor compliance, add optional break start/end within shifts. | Low |
| 10 | **Multi-approval workflow** | CI has single-approver. For larger orgs, add configurable approval chains (supervisor → HR → finance). | Low |

### 5.2 Philippine-Specific Compliance Notes

- **13th Month Pay**: Already in CI as `salary_adjustments` type `thirteenth_month`. Keep as adjustment type with non-taxable category (up to ₱90,000 threshold).
- **Holiday Pay Rules**: Implement per Philippine Labor Code — regular holidays 200%, special non-working 130%, rest day 130%, rest day + special holiday 150%.
- **Night Shift Differential**: 10% minimum per labor code (already in CI at 10%, good).
- **Service Incentive Leave**: 5 days/year after 1 year of service (can be added to leave management).
- **Overtime**: Must be paid. The "overtime not allowed" toggle should be for scheduling policy, not for denying pay for hours actually worked.

### 5.3 Performance Considerations

- **Bulk salary computation**: For 100+ employees, use batch processing with progress feedback (not a single blocking request).
- **Attendance report**: Index `(user_id, DATE(clock_in))` for fast lookups. Consider a materialized daily summary table for large datasets.
- **Photo storage**: Resize to 800px max, JPEG quality 75. Per-tenant directory structure. Periodic cleanup of photos older than 6 months.
- **Benefits rate lookups**: Cache active rates in memory since they change infrequently (only on government mandate updates).

### 5.4 Testing Strategy

| Test Type | Coverage Target |
|---|---|
| Unit tests | Salary computation formulas, tax brackets, benefits calculation, overtime rules |
| Integration tests | Clock-in→attendance→compute→approve→report pipeline |
| Edge cases | Overnight shifts, multiple clock-ins per day, zero-hour contracts, terminated employees mid-period |
| Data integrity | Foreign key cascades, soft deletes, tenant isolation |
| Role access | Every action in the access matrix (18 actions × 4 roles = 72 assertions minimum) |

---

## 6. Database Schema

### 6.1 Core Tables (9 tables)

```
attendance_records          — clock-in/out, photo, location, store
employee_profiles           — salary config, gov't IDs, overtime policy, toggles
employee_schedules          — weekly shift assignments
payroll_periods             — pay period management
salary_computations         — computed pay per employee per period
salary_adjustments          — bonuses, allowances, deductions, 13th month
employee_deductions         — store-level salary deductions (shortages, advances)
benefits_contribution_rates — SSS/PhilHealth/Pag-IBIG rate brackets
holidays                    — holiday calendar with pay multipliers
```

### 6.2 NEW Tables (not in CI source)

```
cash_advances               — cash advance requests, balances, repayment schedule
cash_advance_repayments     — individual repayment records per payroll period
employee_leaves             — leave types, balances, requests (future phase)
payroll_settings            — tenant-level payroll configuration
```

### 6.3 Key Relationships

```
users ──┬── attendance_records (user_id)
        ├── employee_profiles (user_id)
        ├── employee_schedules (user_id)
        ├── salary_computations (user_id)
        ├── salary_adjustments (user_id)
        ├── employee_deductions (user_id)
        ├── cash_advances (user_id)
        └── employee_leaves (user_id)

stores ──┬── attendance_records (store_id)
         ├── employee_schedules (store_id)
         └── employee_deductions (store_id)

payroll_periods ──┬── salary_computations (payroll_period_id)
                  ├── salary_adjustments (payroll_period_id)
                  └── cash_advance_repayments (payroll_period_id)
```

---

## 7. Module Structure & Routes

### 7.1 Directory Layout

```
modules/attendance-wage/
├── module.json
├── routes.php
├── handlers.php
├── helpers.php                        # Core business logic (computation, benefits, tax)
├── handlers/
│   ├── 00-bootstrap.php               # Auth guards, capability checks
│   ├── 10-pages-attendance.php        # Clock, history, report pages
│   ├── 20-api-attendance.php          # Clock-in/out, record CRUD, photo
│   ├── 30-pages-wage.php              # Dashboard, employees, periods pages
│   ├── 40-api-employees.php           # Employee profile CRUD
│   ├── 50-api-periods.php             # Payroll period CRUD
│   ├── 60-api-computations.php        # Compute, bulk compute, approve, pay
│   ├── 70-api-adjustments.php         # Salary adjustments CRUD, approval
│   ├── 80-api-deductions.php          # Employee deductions CRUD
│   ├── 90-api-cash-advances.php       # Cash advance requests, repayments, approval
│   ├── 100-api-holidays.php           # Holiday calendar CRUD
│   └── 110-api-reports.php            # Payroll reports, payslips
├── database/migrations/
│   ├── 001_create_attendance_records.sql
│   ├── 002_create_employee_profiles.sql
│   ├── 003_create_employee_schedules.sql
│   ├── 004_create_payroll_periods.sql
│   ├── 005_create_salary_computations.sql
│   ├── 006_create_salary_adjustments.sql
│   ├── 007_create_employee_deductions.sql
│   ├── 008_create_benefits_contribution_rates.sql
│   ├── 009_create_holidays.sql
│   ├── 010_create_cash_advances.sql
│   ├── 011_create_cash_advance_repayments.sql
│   └── 012_create_payroll_settings.sql
├── templates/modules/attendance-wage/
│   ├── attendance/
│   │   ├── clock.disy
│   │   ├── history.disy
│   │   └── report.disy
│   └── wage/
│       ├── dashboard.disy
│       ├── employees/index.disy
│       ├── employees/form.disy
│       ├── periods/index.disy
│       ├── periods/form.disy
│       ├── computations/index.disy
│       ├── computations/detail.disy
│       ├── adjustments/index.disy
│       ├── adjustments/form.disy
│       ├── deductions/index.disy
│       ├── deductions/form.disy
│       ├── cash-advances/index.disy
│       ├── cash-advances/form.disy
│       ├── holidays/index.disy
│       ├── schedules/index.disy
│       ├── reports/index.disy
│       └── reports/print.disy
└── tests/
    ├── attendance_clock_test.php
    ├── attendance_report_test.php
    ├── wage_computation_test.php
    ├── wage_benefits_test.php
    ├── wage_tax_test.php
    ├── wage_overtime_test.php
    ├── wage_holiday_test.php
    ├── cash_advance_test.php
    ├── role_access_test.php
    └── tenant_isolation_test.php
```

### 7.2 Route Map

```php
// routes.php
return [
    'GET' => [
        // Attendance
        '/admin/attendance'              => 'attendance-wage:attendancePageClock',
        '/admin/attendance/history'      => 'attendance-wage:attendancePageHistory',
        '/admin/attendance/report'       => 'attendance-wage:attendancePageReport',
        // Wage
        '/admin/wage'                    => 'attendance-wage:wagePageDashboard',
        '/admin/wage/employees'          => 'attendance-wage:wagePageEmployees',
        '/admin/wage/employees/create'   => 'attendance-wage:wagePageEmployeeForm',
        '/admin/wage/employees/{id}'     => 'attendance-wage:wagePageEmployeeForm',
        '/admin/wage/periods'            => 'attendance-wage:wagePagePeriods',
        '/admin/wage/periods/create'     => 'attendance-wage:wagePagePeriodForm',
        '/admin/wage/computations'       => 'attendance-wage:wagePageComputations',
        '/admin/wage/adjustments'        => 'attendance-wage:wagePageAdjustments',
        '/admin/wage/adjustments/create' => 'attendance-wage:wagePageAdjustmentForm',
        '/admin/wage/deductions'         => 'attendance-wage:wagePageDeductions',
        '/admin/wage/deductions/create'  => 'attendance-wage:wagePageDeductionForm',
        '/admin/wage/cash-advances'      => 'attendance-wage:wagePageCashAdvances',
        '/admin/wage/holidays'           => 'attendance-wage:wagePageHolidays',
        '/admin/wage/schedules'          => 'attendance-wage:wagePageSchedules',
        '/admin/wage/reports'            => 'attendance-wage:wagePageReports',
        '/admin/wage/reports/{periodId}' => 'attendance-wage:wagePageReportDetail',
        '/admin/wage/benefits-calculator'=> 'attendance-wage:wagePageBenefitsCalc',
        '/admin/wage/migration'          => 'attendance-wage:wagePageMigrationWizard',
        // API
        '/api/v1/attendance/photo/{file}' => 'attendance-wage:attendanceApiPhoto',
        '/api/v1/attendance/records'      => 'attendance-wage:attendanceApiRecords',
        '/api/v1/wage/employees'          => 'attendance-wage:wageApiEmployeesList',
        '/api/v1/wage/employees/{id}'     => 'attendance-wage:wageApiEmployeeGet',
        '/api/v1/wage/periods'            => 'attendance-wage:wageApiPeriodsList',
        '/api/v1/wage/periods/{id}'       => 'attendance-wage:wageApiPeriodGet',
        '/api/v1/wage/computations'       => 'attendance-wage:wageApiComputationsList',
        '/api/v1/wage/computations/{id}'  => 'attendance-wage:wageApiComputationGet',
        '/api/v1/wage/adjustments'        => 'attendance-wage:wageApiAdjustmentsList',
        '/api/v1/wage/deductions'         => 'attendance-wage:wageApiDeductionsList',
        '/api/v1/wage/cash-advances'      => 'attendance-wage:wageApiCashAdvancesList',
        '/api/v1/wage/holidays'           => 'attendance-wage:wageApiHolidaysList',
        '/api/v1/wage/payslip/{computationId}' => 'attendance-wage:wageApiPayslip',
    ],
    'POST' => [
        '/api/v1/attendance/clock-in'        => 'attendance-wage:attendanceApiClockIn',
        '/api/v1/attendance/clock-out'       => 'attendance-wage:attendanceApiClockOut',
        '/api/v1/wage/employees'             => 'attendance-wage:wageApiEmployeeCreate',
        '/api/v1/wage/employees/{id}'        => 'attendance-wage:wageApiEmployeeUpdate',
        '/api/v1/wage/periods'               => 'attendance-wage:wageApiPeriodCreate',
        '/api/v1/wage/periods/{id}'          => 'attendance-wage:wageApiPeriodUpdate',
        '/api/v1/wage/compute'               => 'attendance-wage:wageApiComputeEmployee',
        '/api/v1/wage/compute/bulk'          => 'attendance-wage:wageApiBulkCompute',
        '/api/v1/wage/computations/{id}/approve' => 'attendance-wage:wageApiApproveComputation',
        '/api/v1/wage/computations/{id}/pay'      => 'attendance-wage:wageApiPayComputation',
        '/api/v1/wage/adjustments'            => 'attendance-wage:wageApiAdjustmentCreate',
        '/api/v1/wage/adjustments/{id}/approve' => 'attendance-wage:wageApiAdjustmentApprove',
        '/api/v1/wage/deductions'             => 'attendance-wage:wageApiDeductionCreate',
        '/api/v1/wage/deductions/{id}/status' => 'attendance-wage:wageApiDeductionStatus',
        '/api/v1/wage/cash-advances'          => 'attendance-wage:wageApiCashAdvanceCreate',
        '/api/v1/wage/cash-advances/{id}/approve' => 'attendance-wage:wageApiCashAdvanceApprove',
        '/api/v1/wage/holidays'               => 'attendance-wage:wageApiHolidayCreate',
        '/api/v1/wage/benefits/calculate'     => 'attendance-wage:wageApiBenefitsCalculate',
        '/api/v1/wage/migration/bulk'         => 'attendance-wage:wageApiMigrationBulk',
    ],
];
```

### 7.3 module.json Capabilities

```json
{
  "id": "attendance-wage",
  "name": "Attendance & Wage",
  "version": "0.1.0",
  "description": "Employee attendance tracking, payroll computation, benefits, cash advances, and reporting.",
  "owns_tables": [
    "attendance_records",
    "employee_profiles",
    "employee_schedules",
    "payroll_periods",
    "salary_computations",
    "salary_adjustments",
    "employee_deductions",
    "benefits_contribution_rates",
    "holidays",
    "cash_advances",
    "cash_advance_repayments",
    "payroll_settings"
  ],
  "migrations": [
    "database/migrations/001_create_attendance_records.sql",
    "database/migrations/002_create_employee_profiles.sql",
    "database/migrations/003_create_employee_schedules.sql",
    "database/migrations/004_create_payroll_periods.sql",
    "database/migrations/005_create_salary_computations.sql",
    "database/migrations/006_create_salary_adjustments.sql",
    "database/migrations/007_create_employee_deductions.sql",
    "database/migrations/008_create_benefits_contribution_rates.sql",
    "database/migrations/009_create_holidays.sql",
    "database/migrations/010_create_cash_advances.sql",
    "database/migrations/011_create_cash_advance_repayments.sql",
    "database/migrations/012_create_payroll_settings.sql"
  ],
  "capabilities": {
    "exposes": [
      {
        "id": "attendance-wage.clock@1",
        "priority": 50,
        "modes": ["first"],
        "description": "Clock in/out and view own attendance history"
      },
      {
        "id": "attendance-wage.read@1",
        "priority": 50,
        "modes": ["first"],
        "description": "View own payslips, cash advance status, and salary details"
      },
      {
        "id": "attendance-wage.manage@1",
        "priority": 50,
        "modes": ["first"],
        "description": "Manage team attendance, approve overtime, create deductions"
      },
      {
        "id": "attendance-wage.approve@1",
        "priority": 50,
        "modes": ["first"],
        "description": "Approve salary computations, adjustments, and cash advances"
      },
      {
        "id": "attendance-wage.admin@1",
        "priority": 50,
        "modes": ["first"],
        "description": "Full administrative access: settings, benefits rates, holidays, all data"
      }
    ],
    "consumes": [
      "kernel.auth.authenticate@1",
      "kernel.auth.authorize@1"
    ]
  }
}
```

---

## 8. Implementation Phases

### Phase 1 — Database Foundation (Days 1-2)
- Scaffold module via `php scripts/scaffold-module.php attendance-wage --name="Attendance & Wage" --with=migration`
- Write all 12 migration SQL files
- Create `module.json`, `routes.php`, `handlers.php`
- Bootstrap auth guards and helpers stub
- Seed default benefits rates (SSS, PhilHealth, Pag-IBIG 2025 tables)

### Phase 2 — Attendance Engine (Days 3-5)
- Clock-in/out API (photo upload, location, overnight handling)
- History + Report APIs with shift grouping
- DiSyL templates: `clock.disy`, `history.disy`, `report.disy`
- Tests: `attendance_clock_test.php`, `attendance_report_test.php`

### Phase 3 — Employee Profiles & Schedules (Days 6-8)
- Employee profile CRUD (salary config, overtime policy, gov't IDs, toggles)
- Employee schedule CRUD (weekly grid, shift types, day-off)
- Migration wizard (bulk create from users)
- Tests: employee profile + schedule tests

### Phase 4 — Payroll Engine (Days 9-13)
- Payroll period CRUD with date overlap validation
- Core salary computation logic (hours→pay, overtime, holiday, night diff)
- Benefits calculator (SSS/PhilHealth/Pag-IBIG with rate tables)
- Income tax calculator (Philippine progressive brackets 2025)
- Bulk compute, approval workflow (computed→approved→paid)
- Tests: `wage_computation_test.php`, `wage_benefits_test.php`, `wage_tax_test.php`, `wage_overtime_test.php`, `wage_holiday_test.php`

### Phase 5 — Adjustments, Deductions & Cash Advances (Days 14-16)
- Salary adjustments CRUD (bonuses, allowances, 13th month, penalties, corrections)
- Employee deductions CRUD (store-level, status tracking, auto-link to payroll)
- Cash advance system (request→approve→repayment schedule→auto-deduct)
- Holiday calendar CRUD (recurring/non-recurring, pay multipliers)
- Tests: `cash_advance_test.php`

### Phase 6 — Reports, Payslips & Dashboard (Days 17-19)
- Payroll reports (period summaries, employee breakdowns, print layout)
- Employee payslip generation (HTML→PDF)
- Main dashboard with KPIs and quick actions
- "Who's clocked in" real-time widget
- Benefits calculator standalone page
- Tests: report + payslip tests

### Phase 7 — Polish & Hardening (Days 20-22)
- Role-based access control tests (`role_access_test.php`)
- Tenant isolation tests (`tenant_isolation_test.php`)
- Audit log integration for all mutations
- Performance optimization (indexes, caching, batch processing)
- Documentation (`docs/attendance-wage/`)

---

## 9. Module Dependency Convention — `depends` in module.json

### 9.1 Rule

`capabilities.depends` should **only** list capabilities that are **provided by other modules** the module genuinely needs at runtime. Kernel-native capabilities (`kernel.auth.*`, `kernel.audit.record@1`) are always available and must **not** be listed.

### 9.2 Why

`tenantProvisionModulePlan()` walks `depends` to build the migration plan. Every capability listed in `depends` causes the plan to include **every module that exposes that capability** — and transitively, every module those modules depend on.

Declaring kernel-native capabilities as `depends` is:
- **Unnecessary**: kernel capabilities are always loaded
- **Harmful if the capability is also exposed by a module**: e.g., `kernel.auth.authenticate@1` is exposed by `bakeshop` and `cms`, so depending on it pulls those modules (and all their dependencies) into the tenant migration plan

### 9.3 Kernel-Native Capabilities (safe to omit from `depends`)

| Capability | Registered in | Also exposed by modules? |
|---|---|---|
| `kernel.auth.authenticate@1` | `kernel/App.php` | ✅ bakeshop, cms (pipeline mode) → **Do NOT depend on this** |
| `kernel.auth.user@1` | `kernel/App.php` | ❌ Kernel only → safe to depend on, but unnecessary |
| `kernel.audit.record@1` | `kernel/App.php` | ❌ Kernel only → safe to depend on, but unnecessary |

### 9.4 Module Manifest Audit (2026-06-18)

| Module | `depends` | Plan size | Verdict |
|---|---|---|---|
| `attendance-wage` | (none) | 2 | ✅ Correct |
| `anti-spam` | (none) | — | ✅ Correct |
| `ai`, `ai-orchestrator`, `contact-form`, `content-ingestion`, `daily-ledger`, `ecommerce`, `guidance-sms`, `gui-settings`, `media`, `search`, `security`, `sms`, `ticketing`, `tinymce`, `users`, `weather-service`, `wms`, `workflow`, `test_*` | (none) | — | ✅ Correct |
| `bakeshop` | `kernel.audit.record@1`, `kernel.auth.user@1` | 2 | ⚠️ Unnecessary (both kernel-native) but **safe** — neither capability is module-exposed, so plan stays at 2 |
| `cms` | `kernel.auth.user@1`, `kernel.audit.record@1`, `ai.text.generate@1`, `workflow.*`, `tinymce.*` | 7 | ✅ Legitimate — CMS genuinely depends on AI, workflow, and tinymce modules |
| `example-notes` | `kernel.auth.user@1`, `kernel.audit.record@1` | 2 | ⚠️ Unnecessary kernel deps (same as bakeshop) |
| `guidance` | `tinymce.assets.get@1`, `tinymce.config.get@1`, `tinymce.html.normalize@1`, `tinymce.html.sanitize@1` | 2 | ✅ Legitimate — guidance depends on tinymce |
| `moodle-integration` | `kernel.auth.user@1`, `kernel.audit.record@1` | 2 | ⚠️ Unnecessary kernel deps (same as bakeshop) |

### 9.5 Recommendations

1. **bakeshop**, **example-notes**, **moodle-integration**: Remove `kernel.auth.user@1` and `kernel.audit.record@1` from `depends` — they're kernel-native and don't need explicit declaration. This is low-priority (plan is already correct at 2 modules) but improves manifest hygiene.

2. **attendance-wage**: Already compliant. Used as the reference implementation.

3. **Future modules**: Follow `attendance-wage` as the canonical example — `depends: []` unless there's a genuine inter-module capability contract.
