# Attendance & Wage Module

## Kiosk Flow (No Login Required)

```
Login Page → "📋 Get Attendance"
  → Step 1: Search employee (instant, no GPS yet, @keyup search)
  → Step 2: GPS verifies location, shows result
  → Step 3: Take photo (required), Clock In/Out
  → Step 4: Success → View My Attendance (auto-returns after 5s)
```

### GPS Location Rules
| Location | Onsite Toggle | Result |
|----------|--------------|--------|
| Inside geo-fence | Any | ✅ Clock in with office name |
| Outside geo-fence | ON | 🏗️ Clock in with street, city place name |
| Outside geo-fence | OFF | ❌ Advised to go to office |

### Location Display
Reverse geocode prioritizes: **street, city/municipality** (OSM Nominatim or Google Maps).
Falls back to village/barangay only when city is unavailable.

## Employee Management

### View vs Edit
| Action | Route | Purpose |
|--------|-------|---------|
| View | `/admin/wage/employees/{id}/view` | Profile with photo, compensation, gov't numbers, print |
| Edit | `/admin/wage/employees/{id}` | Full edit form with all fields |
| Create | `/admin/wage/employees/create` | New employee form |
| List | `/admin/wage/employees` | Entity list with row-click to view |

### Employee View Features
- **Photo** — shows uploaded photo or initial-letter placeholder
- **Quick Info** — employee #, position, department, salary type, hire date, status
- **Compensation Card** — basic salary, hourly/daily/monthly rates, overtime/night diff/holiday pay toggles
- **Government Numbers Card** — SSS, PhilHealth, Pag-IBIG, TIN, tax status (normalized), dependents
- **Print** — 🖨️ button with `@media print` CSS that hides sidebar + chrome

### Photo Upload
- Saved to `public/uploads/employee-photos/` (web-accessible)
- Supports file upload and camera capture (base64)
- Accepted formats: JPEG, PNG, WebP (max 5MB)
- Form must have `enctype="multipart/form-data"`

## Database Migrations

| # | File | Purpose |
|---|------|---------|
| 001-020 | `database/migrations/` | Core schema (employees, attendance, payroll, etc.) |
| 021 | `021_add_lat_lng_to_attendance_records.sql` | Latitude/longitude columns (idempotent) |
| 022 | `022_add_employee_photo_url.sql` | Employee photo URL column (idempotent) |

All migrations are declared in `module.json`. Run via:
- Primary DB: `php ikabud migrate`
- Tenant DB: `php ikabud tenant:migrate <tenant_id> <module_id>`

The CLI bypasses the module plan check when a specific module is requested.

## API Endpoints

### Public (no auth)
| Method | Route | Handler |
|--------|-------|---------|
| GET/POST | `/api/v1/kiosk/search` | Search employees by name |
| POST | `/api/v1/kiosk/clock` | Clock in/out with photo + geo-fence |
| GET | `/api/v1/kiosk/reverse-geocode` | Coordinates → street, city (Google Maps or OSM) |
| POST | `/api/v1/kiosk/verify-location` | Check if within office geo-fence |
| GET | `/api/v1/kiosk/status` | Check clock-in status for employee |
| GET | `/api/v1/kiosk/my-records` | Recent attendance records |

### Admin (auth required — JWT cookie `attendance_wage_token`)
| Method | Route | Handler |
|--------|-------|---------|
| GET | `/admin/attendance` | Attendance records with hours |
| GET | `/admin/attendance?employee_id=X&export=csv` | CSV export |
| GET | `/api/v1/attendance/photo/{file}` | View attendance photo |
| GET | `/api/v1/wage/logo/{file}` | View uploaded logo |
| POST | `/api/v1/wage/computations/{id}/approve` | Approve computation |
| POST | `/api/v1/wage/computations/{id}/pay` | Pay computation |
| POST | `/api/v1/wage/computations/batch/approve` | Batch approve |
| POST | `/api/v1/wage/computations/batch/pay` | Batch pay |

### CSRF Handling
- API routes (`/api/`) skip session-based CSRF — rely on JWT cookie auth
- Non-API POST routes enforce `csrfEnforce()` via `attendanceWageGuard()`
- Set `APP_COOKIE_SAMESITE` in `.env` to control cookie SameSite (default: `Strict`)

## File Storage
| Type | Path | URL |
|------|------|-----|
| Employee photos | `public/uploads/employee-photos/` | `/uploads/employee-photos/{filename}` |
| Attendance photos | `storage/uploads/attendance/` | Served via API |
| Logo | `storage/uploads/logos/` | Served via API |
| Reports | `storage/report-archive/` | CLI-generated CSVs/JSON |

## Payroll Integration
Clock-in triggers `kioskAutoRecompute()` → auto-updates salary for current active payroll period. Admin then approves/pays via Wage → Computations.

### Approval Flow
1. Compute salaries via 🧮 Compute button
2. Click ✅ Approve on individual computations (or batch approve)
3. System checks: pay date must be today or past
4. Single computation status → `approved`
5. When all computations in a period are approved, period status → `completed`
6. Pay date constraint prevents approval before scheduled pay date

## Entity View System
Entity lists use `{ikb_entity_list}` with rich action support:

| Layer | File | Purpose |
|-------|------|---------|
| Resolver | `kernel/EntityContext/EntityViewResolver.php` | Source parsing, capability dispatch, key_field support |
| Renderer | `kernel/DiSyL/EntityRenderingTrait.php` | Table/card/compact rendering, POST forms with CSRF |
| Contract | `helpers/entity-views.php` | Field lists, action URLs, badge label maps, key_field |
| Data | `helpers.php` (`aw_cap_entity_list_*`) | SQL queries via `EntityListQuery::run()` |

### Key Features
- **key_field** — Contract property that auto-includes ID fields in query results without displaying them as columns
- **POST actions** — Approve, pay, delete actions render as inline forms with CSRF token
- **Badge renderers** — All enum fields have explicit label maps (e.g., `"part_time":"Part-Time|gray"`)
- **display_fields** — Resolver strips key_field from display, renderer uses `display_fields` for column headers

### Critical Rule
SQL column aliases in helper functions MUST match view contract field names. No auto-mapping.

## Enum Normalization
All underscored database values are normalized for display:

| Field | Raw DB Value | Display |
|-------|-------------|---------|
| `salary_type` | `hourly`, `daily`, `monthly`, `fixed` | Hourly, Daily, Monthly, Fixed |
| `employment_status` | `part_time`, `terminated` | Part-Time, Terminated |
| `period_type` | `semi_monthly`, `bi_weekly` | Semi-Monthly, Bi-Weekly |
| `repayment_type` | `full_next_payroll`, `lumpsum_date` | Full (Next Payroll), Lump Sum by Date |
| `holiday_type` | `special_non_working` | Special Non-Working |
| `shift_type` | `rotating`, `flexible` | Rotating, Flexible |
| `adjustment_type` | `thirteenth_month`, `holiday_bonus` | 13th Month, Holiday Bonus |
| `tax_exemption_status` | `head_of_family` | Head of Family |

Use `aw_formatLookup($field, $value)` in PHP or badge label maps in entity views.

## Key Files
```
handlers/
  00-bootstrap.php       — Auth guard (attendanceWageGuard), CSRF skip for API
  05-auth.php            — Login, forgot/reset password, rate limiting
  10-pages-attendance.php — Admin records view + CSV export
  20-api-attendance.php  — Photo/logo serving
  30-pages-wage.php      — Dashboard, employees (list/view/edit), periods, computations, reports
  40-api-employees.php   — Employee CRUD, photo upload (public/uploads/employee-photos/)
  50-api-periods.php     — Payroll period CRUD
  60-api-computations.php — Compute, approve (single + batch), pay, tracing logs
  70-api-adjustments.php — Salary adjustment CRUD + approve
  80-api-deductions.php  — Employee deductions CRUD
  90-api-cash-advances.php — Cash advance CRUD + approve
  100-api-holidays.php   — Holiday calendar CRUD
  110-api-reports.php    — Payroll reports, payslips, summary
  130-api-kiosk.php      — Kiosk clock-in/out, search, geo-fence, reverse-geocode

templates/
  attendance/kiosk.disyl — Alpine.js kiosk widget (full flow, @keyup search)
  attendance/records.disyl — Admin records table with hours, photos, CSV export
  auth/login.disyl       — Login page with logo
  wage/employees/view.disyl    — Employee profile view with photo + print
  wage/employees/form.disyl    — Employee create/edit form
  wage/employees/index.disyl   — Employee list with entity view
  wage/computations/index.disyl — Computations list with approve/pay actions
  wage/periods/index.disyl     — Payroll periods list
  wage/dashboard.disyl         — Dashboard with stats
  wage/payslip.disyl           — Printable payslip (standalone HTML)

helpers/
  helpers.php            — Entity list handlers, aw_formatLookup(), aw_formatTaxStatus(),
                           geo-fence (Haversine), entity_csrf_token()
  entity-views.php       — View contracts with key_field, badge maps, action URLs

database/migrations/
  021_add_lat_lng_to_attendance_records.sql  — Idempotent lat/lng columns
  022_add_employee_photo_url.sql             — Idempotent photo_url column
```
