# Attendance & Wage Module

**Module ID:** `attendance-wage`
**Version:** 0.1.0
**Author:** Ikabud Kernel Team
**Depends on capabilities:** None (standalone)
**Standalone:** yes — does not depend on any other application module.

---

## Overview

The Attendance & Wage module is a comprehensive workforce management system: employee time tracking, payroll computation, government-mandated benefits, cash advances, holiday management, office location geo-fencing, and multi-format reporting. It runs inside the Ikabud Kernel as a tenant-scoped module — all `attendance_*` tables live in the tenant's own database.

It is **module-owned auth**: the module has its own `attendance_wage_users` table, its own JWT cookie (`attendance_wage_token`), and registers itself as a `kernel.auth.authenticate@1` provider.

---

## Manifest Summary

Source: [modules/attendance-wage/module.json](../../modules/attendance-wage/module.json)

| Field | Value |
|---|---|
| `auth_cookie` | `attendance_wage_token` |
| `owns_tables` | `attendance_records`, `attendance_wage_users`, `attendance_wage_password_resets`, `employee_profiles`, `employee_schedules`, `payroll_periods`, `salary_computations`, `salary_adjustments`, `employee_deductions`, `benefits_contribution_rates`, `holidays`, `cash_advances`, `cash_advance_repayments`, `payroll_settings`, `office_locations` |
| `reads_tables` | `audit_logs` |
| `events` | None declared |
| `migrations` | 23 files (see §Database Architecture) |

### Capabilities

Exposed (see [modules/attendance-wage/helpers.php](../../modules/attendance-wage/helpers.php)):

| Capability | Mode | Purpose |
|---|---|---|
| `kernel.auth.authenticate@1` | `pipeline` (priority 560) | Authenticates `@attendance-wage:<username>` and bare module credentials. |
| `attendance_wage.clock@1` | `first` | Clock in/out and view own attendance history. |
| `attendance_wage.read@1` | `first` | View own payslips, cash advance status, and salary details. |
| `attendance_wage.manage@1` | `first` | Manage team attendance, approve overtime, create deductions. |
| `attendance_wage.approve@1` | `first` | Approve salary computations, adjustments, and cash advances. |
| `attendance_wage.admin@1` | `first` | Full administrative access: settings, benefits rates, holidays, all data. |
| `attendance.record.hours.update@1` | `first` | Inline edit attendance hours. |

**Entity view providers (20 — 10 list + 10 get):**

List providers:
- `entity.list.attendance_record@1`
- `entity.list.employee_profile@1`
- `entity.list.payroll_period@1`
- `entity.list.salary_computation@1`
- `entity.list.salary_adjustment@1`
- `entity.list.employee_deduction@1`
- `entity.list.holiday@1`
- `entity.list.cash_advance@1`
- `entity.list.employee_schedule@1`
- `entity.list.office_location@1`

Get providers:
- `entity.get.attendance_record@1`
- `entity.get.employee_profile@1`
- `entity.get.payroll_period@1`
- `entity.get.salary_computation@1`
- `entity.get.salary_adjustment@1`
- `entity.get.employee_deduction@1`
- `entity.get.holiday@1`
- `entity.get.cash_advance@1`
- `entity.get.employee_schedule@1`
- `entity.get.office_location@1`

---

## Database Architecture

Schema is shipped as 23 migrations under [modules/attendance-wage/database/migrations/](../../modules/attendance-wage/database/migrations) and applied per-tenant via `php ikabud tenant:migrate <tenant>`:

| Migration | Purpose |
|---|---|
| `001_create_attendance_records.sql` | Clock in/out records with timestamps, type, location data. |
| `002_create_employee_profiles.sql` | Employee master data: name, position, salary, schedule. |
| `003_create_employee_schedules.sql` | Per-employee weekly schedule configuration. |
| `004_create_payroll_periods.sql` | Payroll period definitions (semi-monthly, monthly, etc.). |
| `005_create_salary_computations.sql` | Computed salary per employee per period. |
| `006_create_salary_adjustments.sql` | One-time adjustments (bonus, deduction, overtime pay). |
| `007_create_employee_deductions.sql` | Recurring deductions (SSS, PhilHealth, Pag-IBIG, loans). |
| `008_create_benefits_contribution_rates.sql` | Government-mandated contribution rate tables. |
| `009_create_holidays.sql` | Holiday calendar with type (regular/special) and pay rate. |
| `010_create_cash_advances.sql` | Cash advance requests with approval workflow. |
| `011_create_cash_advance_repayments.sql` | Repayment schedule for approved cash advances. |
| `012_create_payroll_settings.sql` | Module-level payroll configuration. |
| `013_create_attendance_wage_users.sql` | Module user accounts. |
| `014_bootstrap_attendance_wage_admin.sql` | Bootstrap admin user seed. |
| `015_create_attendance_wage_password_resets.sql` | Password reset tokens. |
| `016_add_employee_name_columns.sql` | First/middle/last name columns on employee_profiles. |
| `017_fix_salary_computations_user_id.sql` | Schema fix for user_id reference. |
| `018_add_cash_advances_profile_id.sql` | Profile FK on cash_advances. |
| `019_create_office_locations.sql` | Office locations with geo-fence coordinates. |
| `020_add_onsite_attendance.sql` | On-site attendance verification fields. |
| `021_add_lat_lng_to_attendance_records.sql` | GPS coordinates on clock records. |
| `022_add_employee_photo_url.sql` | Employee photo URL field. |
| `023_add_thirteenth_month_enabled.sql` | 13th month pay toggle. |

---

## Routes

Source: [modules/attendance-wage/routes.php](../../modules/attendance-wage/routes.php)

### Pages (GET)
- `/attendance-wage`, `/attendance-wage/login`, `/attendance-wage/forgot-password`, `/attendance-wage/reset-password` — public auth
- `/attendance-wage/kiosk` — shared kiosk clock-in terminal
- `/admin/attendance`, `/admin/attendance/history`, `/admin/attendance/report` — attendance views
- `/admin/wage` — dashboard
- `/admin/wage/employees`, `/admin/wage/employees/create`, `/admin/wage/employees/{id}`, `/admin/wage/employees/{id}/view` — employee CRUD
- `/admin/wage/periods`, `/admin/wage/periods/create`, `/admin/wage/periods/{id}` — payroll periods
- `/admin/wage/computations` — salary computations list
- `/admin/wage/adjustments`, `/admin/wage/adjustments/create`, `/admin/wage/adjustments/{id}` — adjustments
- `/admin/wage/deductions`, `/admin/wage/deductions/create`, `/admin/wage/deductions/{employeeName}` — deductions
- `/admin/wage/cash-advances`, `/admin/wage/cash-advances/create` — cash advances
- `/admin/wage/holidays` — holiday management
- `/admin/wage/schedules` — employee schedules
- `/admin/wage/reports`, `/admin/wage/reports/{periodId}`, `/admin/wage/reports/summary` — reporting
- `/admin/wage/locations`, `/admin/wage/locations/create`, `/admin/wage/locations/{id}` — office locations
- `/admin/wage/benefits-calculator` — benefits contribution calculator
- `/admin/wage/migration` — data migration wizard
- `/admin/wage/settings`, `/admin/wage/profile` — settings & profile
- `/admin/wage/payslip/{computationId}` — printable payslip

### API (GET)
- Entity reads: `/api/v1/wage/employees`, `/api/v1/wage/employees/{id}`, `/api/v1/wage/periods`, `/api/v1/wage/periods/{id}`
- Computations: `/api/v1/wage/computations`, `/api/v1/wage/computations/{id}`
- Lists: `/api/v1/wage/adjustments`, `/api/v1/wage/deductions`, `/api/v1/wage/cash-advances`, `/api/v1/wage/holidays`
- Schedules: `/api/v1/wage/schedules`, `/api/v1/wage/schedules/employee/{profileId}`, `/api/v1/wage/schedules/{id}`
- Reports: `/api/v1/wage/reports/export`, `/api/v1/wage/reports/{periodId}/export`, `/api/v1/wage/reports/summary`
- Payslip: `/api/v1/wage/payslip/{computationId}`
- Locations: `/api/v1/wage/locations`, `/api/v1/wage/locations/{id}`
- Kiosk: `/api/v1/kiosk/search`, `/api/v1/kiosk/reverse-geocode`, `/api/v1/kiosk/verify-location`, `/api/v1/kiosk/status`, `/api/v1/kiosk/my-records`

### API (POST)
- Auth: `/attendance-wage/auth/login`, `/attendance-wage/auth/forgot-password`, `/api/v1/attendance-wage/auth/forgot-password`, `/api/v1/attendance-wage/auth/reset-password`
- Clock: `/api/v1/attendance/clock-in`, `/api/v1/attendance/clock-out`
- Entity CRUD: employees create/update, periods create/update/delete, adjustments create/approve, deductions create/status, cash-advances create/approve, holidays create/update/delete
- Compute: `/api/v1/wage/compute` (single), `/api/v1/wage/compute/bulk` (batch)
- Batch actions: computation approve/pay in batch
- Schedules: `/api/v1/wage/schedules` create
- Locations: create/update/delete
- Settings: `/api/v1/wage/settings`, `/api/v1/wage/settings/change-password`, `/api/v1/wage/settings/add-user`, `/api/v1/wage/settings/update-role`, `/api/v1/wage/settings/toggle-user`
- Profile: `/api/v1/wage/profile/change-password`, `/api/v1/wage/profile/update`
- Benefits: `/api/v1/wage/benefits/calculate`
- Migration: `/api/v1/wage/migration/bulk`
- Kiosk: `/api/v1/kiosk/search`, `/api/v1/kiosk/clock`, `/api/v1/kiosk/verify-location`, `/api/v1/kiosk/status`

---

## Authentication & Authorization

### Provider

The module registers `kernel.auth.authenticate@1` at pipeline priority 560:

- Accepts `username = "@attendance-wage:<username-or-email>"` (preferred) or bare module credentials.
- Looks up `attendance_wage_users` by username or email, verifies with `password_verify`.
- Returns identity with `id`, `username`, `email`, `full_name`, `role`, `source = 'attendance-wage'`.

Login issues a JWT into the `attendance_wage_token` cookie (HttpOnly, `SameSite=Strict`, `Secure` when HTTPS).

### Authorization

Roles: `admin`, `supervisor`. Kernel `superadmin` users (`source === 'kernel'`) bypass the module-user check for support flows.

### Self-service password reset

Public pages at `/attendance-wage/forgot-password` and `/attendance-wage/reset-password` with canonical browser APIs. Uses generic success messaging to avoid account enumeration, 30-minute reset-link expiry, and rate limiting on both issue and reset attempts.

### Trusted provisioning

The module requires `--admin-user` and `--admin-pass` during `php ikabud tenant:provision`. The bootstrap password hash (`!attendance-wage-bootstrap-password-reset-required!`) cannot match any input — the only way to obtain a usable first admin is the trusted CLI provisioning path.

---

## Admin Navigation

The module registers 11 admin navigation entries (plus 1 separator):

| Label | URL | Roles | Notes |
|---|---|---|---|
| Dashboard | `/admin/wage` | `*` | Overview with key metrics |
| Attendance | `/admin/attendance` | `*` | Clock in/out, history |
| Employees | `/admin/wage/employees` | `admin` | Employee master list |
| Payroll Periods | `/admin/wage/periods` | `admin` | Period management |
| Computations | `/admin/wage/computations` | `admin` | Salary computation results |
| Adjustments | `/admin/wage/adjustments` | `admin` | One-time pay adjustments |
| Deductions | `/admin/wage/deductions` | `admin` | Recurring deductions |
| Cash Advances | `/admin/wage/cash-advances` | `*` | Cash advance requests |
| Holidays | `/admin/wage/holidays` | `admin` | Holiday calendar |
| Locations | `/admin/wage/locations` | `admin` | Office geo-fence locations |
| Reports | `/admin/wage/reports` | `admin` | Payroll reports & exports |

---

## Settings

| Key | Type | Default | Notes |
|---|---|---|---|
| `default_rest_day` | select | `sunday` | Default weekly rest day for employees without an individual schedule. Options: Sunday–Saturday. |
| `working_days_per_month` | number | `22` | Used for monthly salary pro-rating. |
| `working_hours_per_day` | number | `8` | Standard working hours per day for daily rate calculations. |
| `overtime_calculation` | select | `both` | Overtime limit enforcement mode: `daily`, `weekly`, `both`. |
| `round_hours_to` | select | `0.25` | Round worked hours to nearest increment: `none`, `0.25` (15 min), `0.5` (30 min), `1.0` (hour). |
| `pay_frequency` | select | `semi_monthly` | Default payroll period type: `semi_monthly`, `monthly`, `weekly`, `bi_weekly`. |
| `max_cash_advance_pct` | number | `50` | Maximum cash advance as percentage of monthly basic salary. |
| `max_active_advances` | number | `2` | Maximum number of active (unpaid) cash advances per employee. |
| `google_maps_api_key` | text | `""` | API key for Google Maps (Maps JavaScript API + Geocoding API). Required for the office locations map picker. |

---

## Handlers Layout

[modules/attendance-wage/handlers/](../../modules/attendance-wage/handlers) is split for clarity and load order (17 files):

| File | Responsibility |
|---|---|
| `00-bootstrap.php` | Module bootstrap, response guards, helper loading. |
| `05-auth.php` | Login page, login/logout endpoints, JWT issuance, password reset. |
| `10-pages-attendance.php` | Attendance record pages: list, history, report. |
| `20-api-attendance.php` | Clock-in/out API, record queries. |
| `30-pages-wage.php` | Wage dashboard, employee/period/computation/adjustment/deduction pages. |
| `40-api-employees.php` | Employee CRUD API. |
| `50-api-periods.php` | Payroll period CRUD API. |
| `60-api-computations.php` | Salary computation API (single + bulk compute, approve, pay). |
| `70-api-adjustments.php` | Salary adjustment CRUD + approval API. |
| `80-api-deductions.php` | Deduction CRUD + status API. |
| `90-api-cash-advances.php` | Cash advance CRUD + approval + repayment API. |
| `95-api-settings.php` | Settings save, password change, user/role management API. |
| `100-api-holidays.php` | Holiday CRUD API. |
| `110-api-reports.php` | Report generation and export API. |
| `110-api-schedules.php` | Employee schedule CRUD API. |
| `120-api-locations.php` | Office location CRUD API with geo-fence. |
| `130-api-kiosk.php` | Shared kiosk terminal: search, clock, location verification. |

---

## Templates

Disyl templates under [templates/modules/attendance-wage/](../../templates/modules/attendance-wage):

- `layouts/app.disyl` — application layout.
- Pages for dashboard, attendance, employees, periods, computations, adjustments, deductions, cash advances, holidays, schedules, reports, locations, settings, profile, payslip, kiosk, login.

---

## Key Features

### Clock In/Out with Geo-Fencing
- Employees clock in/out via the kiosk interface (`/attendance-wage/kiosk`).
- GPS coordinates captured on each record.
- Office locations define geo-fence boundaries for on-site verification.
- Reverse geocoding via Google Maps API.

### Payroll Computation Engine
- Bulk or single-employee computation per payroll period.
- Supports semi-monthly, monthly, weekly, bi-weekly frequencies.
- Automatic overtime calculation based on daily/weekly thresholds.
- Government-mandated benefits (SSS, PhilHealth, Pag-IBIG) with configurable contribution rates.
- 13th month pay support.

### Cash Advance Workflow
- Employees request cash advances up to configurable percentage of salary.
- Multi-level approval workflow.
- Automatic repayment scheduling.

### Reporting
- Period-based payroll summaries with exports.
- Attendance history with filtering.
- Printable payslips per computation.
