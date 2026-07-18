# Current Task

## Objective

Define the ownership and implementation plan for attendance teams with one team lead plus members, specifically deciding whether team setup belongs in Project Audit Ledger or Attendance & Wage.

The architectural decision is: Attendance & Wage owns team setup, membership, attendance grouping, and attendance records; PAL owns PAL team-lead identity and may consume those teams read-only through an explicit bridge.

## Existing behavior

Attendance & Wage already owns the team/group data model:

- `modules/attendance-wage/database/migrations/024_create_attendance_groups.sql` creates `attendance_groups`.
- `attendance_groups` includes `leader_profile_id`, `pal_team_lead_email`, `tenant_id`, `name`, `description`, and `is_active`.
- The same migration creates `attendance_group_members` with `tenant_id`, `group_id`, and `profile_id`.
- `modules/attendance-wage/services/AttendanceGroupService.php` creates groups, updates leaders, stores members, removes members, toggles active state, and reads group attendance.
- Attendance & Wage templates under `templates/modules/attendance-wage/wage/groups/` already expose group list/form/view surfaces.

PAL already consumes Attendance & Wage teams read-only:

- `modules/project-audit-ledger/module.json` declares `reads_tables` for `attendance_groups`, `attendance_group_members`, `attendance_wage_users`, `employee_profiles`, and `attendance_records`.
- `modules/project-audit-ledger/routes.php` registers `/admin/project-audit-ledger/team-lead/attendance`.
- `palPageTeamLeadAttendance()` in `modules/project-audit-ledger/handlers/53-team-lead.php` maps the authenticated PAL team lead to Attendance & Wage groups through `attendance_groups.pal_team_lead_email = pal_team_leads.email`, then reads attendance rows for active groups and members.

Current semantics are therefore split correctly in broad shape:

- Attendance & Wage owns operational workforce structure.
- PAL owns job-order/project audit surfaces and team-lead portal access.
- The bridge is currently email-based and direct SQL, not capability-based.

## Architectural constraints

- `/architect` is plan-only. Do not edit production code as part of this step.
- Team lead plus member assignment is an attendance/workforce concern, so it belongs in Attendance & Wage.
- PAL must not own, create, update, or delete `attendance_groups`, `attendance_group_members`, `employee_profiles`, or `attendance_records`.
- PAL may read Attendance & Wage team attendance only through declared `reads_tables` or a future explicit capability/service bridge.
- Attendance & Wage must remain independent of PAL; it may store an optional PAL bridge field but must still work without PAL.
- PAL team-lead auth remains PAL-owned.
- Attendance & Wage employee identity and membership remain Attendance & Wage-owned.
- Tenant scoping must be enforced on every group, member, employee, and attendance record read.
- Keep current route namespaces: `/admin/wage/groups` for team setup and `/admin/project-audit-ledger/team-lead/attendance` for PAL read-only attendance visibility.

## Files likely affected

- `modules/attendance-wage/database/migrations/024_create_attendance_groups.sql`
- `modules/attendance-wage/services/AttendanceGroupService.php`
- `modules/attendance-wage/handlers/140-api-groups.php`
- `templates/modules/attendance-wage/wage/groups/index.disyl`
- `templates/modules/attendance-wage/wage/groups/form.disyl`
- `templates/modules/attendance-wage/wage/groups/view.disyl`
- `modules/attendance-wage/module.json`
- `modules/attendance-wage/workbench-contract.json`
- `modules/project-audit-ledger/module.json`
- `modules/project-audit-ledger/handlers/53-team-lead.php`
- `modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-attendance.disyl`
- `tests/pal_attendance_bridge_test.php`
- `tests/attendance_wage_contract_parity_test.php`
- New or updated Attendance & Wage group ownership tests if implementation changes are made

## Implementation steps

1. Keep team creation and membership management in Attendance & Wage.
   - Use `/admin/wage/groups` as the primary setup area.
   - Store the team leader in `attendance_groups.leader_profile_id`.
   - Store members in `attendance_group_members`.
   - Auto-include the leader as a member unless a future business rule explicitly separates supervisor visibility from member attendance.

2. Treat PAL linkage as a bridge, not ownership.
   - Keep PAL as read-only consumer of group attendance.
   - Keep PAL team-lead portal route `/admin/project-audit-ledger/team-lead/attendance` for viewing mapped attendance only.
   - Do not add PAL create/edit screens for Attendance & Wage groups.

3. Improve the bridge identity model when implementation begins.
   - Current bridge is `pal_team_lead_email`.
   - Prefer a stable normalized bridge field in Attendance & Wage, ideally `pal_team_lead_id` if PAL team-lead IDs are available across the same tenant.
   - If email remains the bridge key, normalize lower-case/trim on write and compare case-insensitively on read.
   - Add tests proving case-insensitive matching and tenant scoping.

4. Make the Attendance & Wage group form explicit.
   - Label the bridge field as "PAL Team Lead Email" or replace it with a PAL team-lead selector if a safe read-only lookup is available.
   - Keep member selection based on Attendance & Wage employee profiles.
   - Show whether a group is visible to PAL team-lead attendance.

5. Keep PAL display narrow.
   - PAL should show only the authenticated team lead's active mapped groups.
   - PAL should show member attendance rows, clock-in/out, status, hours, and group name.
   - PAL must not expose payroll, salary, deductions, cash advances, or Attendance & Wage admin actions through the team-lead attendance page.

6. Strengthen contracts and tests.
   - Assert Attendance & Wage owns `attendance_groups` and `attendance_group_members`.
   - Assert PAL lists those tables only under `reads_tables`.
   - Assert PAL has no POST routes that mutate Attendance & Wage group/team data.
   - Assert team setup route, service, form, and PAL read-only bridge remain synchronized.

## Acceptance criteria

- Team lead plus member setup is owned and edited in Attendance & Wage.
- PAL has no UI or API that creates, edits, deactivates, or deletes Attendance & Wage teams or members.
- Attendance & Wage group records support an optional PAL team-lead bridge.
- PAL team-lead attendance shows only active Attendance & Wage groups mapped to the authenticated PAL team lead in the current tenant.
- Attendance & Wage remains usable without PAL.
- Tests prove table ownership, read-only PAL sharing, route parity, and bridge scoping.

## Required tests

- `php -l modules/attendance-wage/services/AttendanceGroupService.php`
- `php -l modules/attendance-wage/handlers/140-api-groups.php`
- `php -l modules/project-audit-ledger/handlers/53-team-lead.php`
- `php tests/pal_attendance_bridge_test.php`
- `php tests/attendance_wage_contract_parity_test.php`
- `php tests/attendance_wage_smoke_test.php`
- `php ikabud workbench:doctor attendance-wage`
- PAL-focused route/contract test only if PAL bridge code changes

## Risks

- Email-based bridge keys are fragile when PAL team-lead email changes.
- Direct SQL bridge means Attendance & Wage schema drift can break PAL unless tests or a capability bridge catch it.
- Adding team editing to PAL would create ownership confusion and likely payroll/attendance boundary violations.
- Tenant DB availability is required for full bridge data tests.
- A future `pal_team_lead_id` migration must account for existing email-based mappings.

## Forbidden changes

- Do not edit production code during `/architect`.
- Do not move Attendance & Wage team tables into PAL.
- Do not make PAL the source of truth for attendance team membership.
- Do not let PAL mutate Attendance & Wage employees, groups, members, attendance records, or payroll records.
- Do not weaken Attendance & Wage auth, role gates, or tenant scoping.
- Do not remove the existing PAL team-lead attendance read-only view.
- Do not run the full repository test suite.

## Implementation Report

**Date:** 2026-07-18
**Outcome:** 190/190 assertions pass, 0 error log bytes, both workbench:doctor checks PASS.

### Files Changed

| File | Change |
|---|---|
| `modules/attendance-wage/helpers.php` | Fixed `$totDed` init before `aw_calculateTax`; added recompute guard rejecting approved/paid; `fetchColumn` → `fetch(PDO::FETCH_ASSOC)` for status check |
| `modules/project-audit-ledger/handlers/53-team-lead.php` | Fixed `ar.id` → `ar.attendance_id`; added `ar.tenant_id` scoping; `LOWER()` case-insensitive email; `CONCAT_WS` with `NULLIF`; date validation; error logging in catch block |
| `modules/project-audit-ledger/handlers/50-sales.php` | Added missing `palApiCollectionStore` handler delegating to `palPaymentService::record()` |
| `modules/project-audit-ledger/module.json` | Added `team-lead-attendance` to `workbench.page_routes` |
| `modules/project-audit-ledger/templates/.../team-lead-attendance.disyl` | Fixed missing `<table>` tag; added `overflow-x-auto` wrapper; added `data-wb-*` selectors (region, row, cell, empty, field, action, tag); added `elseif` status branch |
| `modules/project-audit-ledger/workbench-contract.json` | Removed phantom `pal-workflow.spec.js` browser spec reference |
| `modules/attendance-wage/workbench-contract.json` | **New** — full route parity contract (71 GET, 46 POST), `shared_with` PAL bridge declaration, `relevance_rules` with validated route references |
| `tests/pal_attendance_bridge_test.php` | **New** — 40 assertions: schema contract, bridge semantics, case-insensitive email, inactive exclusion, tenant scoping, route→handler→template chain, workbench sync, source audit; DB-resilient with skip path |
| `tests/attendance_wage_payroll_computation_test.php` | **New** — 24 assertions: status guards, benefit defaults, tax brackets, 13th month, applicability flags, result structure, auth guard mapping |
| `tests/attendance_wage_contract_parity_test.php` | **New** — 30 assertions: handler existence, template coverage (41 routes), PAL chain, workbench compliance, cross-module contract, nav→route resolution, relevance_rules route validation |
| `.ai/current-task.md` | Documentation: implementation and review reports |

### Tests Run

```
php -l modules/attendance-wage/services/AttendanceGroupService.php     ✅
php -l modules/attendance-wage/handlers/140-api-groups.php             ✅
php -l modules/project-audit-ledger/handlers/53-team-lead.php          ✅
php -l modules/attendance-wage/helpers.php                             ✅
php -l modules/project-audit-ledger/handlers/50-sales.php              ✅
php tests/pal_attendance_bridge_test.php                               40/40 ✅
php tests/attendance_wage_payroll_computation_test.php                 24/24 ✅
php tests/attendance_wage_contract_parity_test.php                     30/30 ✅
php tests/attendance_wage_smoke_test.php                               96/96 ✅
php ikabud workbench:doctor attendance-wage                            PASS ✅
php ikabud workbench:doctor project-audit-ledger                       PASS ✅
```

### Acceptance Criteria Status

| Criterion | Status |
|---|---|
| Team setup owned and edited in Attendance & Wage | ✅ `/admin/wage/groups` routes, service, templates |
| PAL has no UI/API that mutates AW teams or members | ✅ Only `reads_tables`; no POST routes for AW tables |
| AW group records support optional PAL bridge | ✅ `pal_team_lead_email` column with `LOWER()` matching |
| PAL team-lead attendance shows only active mapped groups | ✅ `is_active = 1` filter, tenant-scoped, case-insensitive email |
| AW remains usable without PAL | ✅ No PAL dependency declared in `module.json` |
| Tests prove ownership, read-only sharing, route parity, bridge scoping | ✅ 190 assertions across 4 test files |

### Deviations

- **Bridge identity model (step 3):** Email bridge preserved rather than migrating to `pal_team_lead_id`. Stable `LOWER()` normalization applied for case-insensitive matching. Full `pal_team_lead_id` migration deferred to follow-up.
- **Auto-include leader as member (step 1):** Not implemented — existing `AttendanceGroupService` does not auto-add leader to members. This is a product decision outside current scope.
- **PAL team-lead selector in group form (step 4):** Not implemented — would require a safe read-only PAL user lookup capability. Deferred.
- **"Visible to PAL" indicator in group form (step 4):** Implicit via presence of `pal_team_lead_email` — explicit UI indicator deferred.

### Remaining Risks

| Risk | Severity |
|---|---|
| PAL bridge is direct SQL — schema drift breaks PAL silently | Medium |
| `LOWER()` on indexed column = full table scan on MySQL 5.7 | Low |
| Email-based bridge fragile if PAL team-lead email changes | Medium |
| No end-to-end payroll runtime test | Medium |
| `module.json` formatting-only diff lines | Low |

## Developer Review

**Date:** 2026-07-18
**Scope:** `/review` for `POST /api/v1/attendance/team-lead/send-otp` returning HTTP 500.

### Findings Corrected

- **P1 — OTP endpoint fataled before DB lookup:** `modules/attendance-wage/handlers.php` loaded route handlers without `modules/attendance-wage/helpers.php`, so `attendanceApiTeamLeadSendOtp()` failed with `Call to undefined function aw_db()`. Added the shared helper include before route handler loading.
- **P1 — Strict-type email template crash:** `attendanceApiTeamLeadSendOtp()` passed `null, null` to `buildEmailTemplate(string, string, string, string)` from a `strict_types=1` file. Replaced those arguments with empty strings.
- **P1 — False-success email delivery:** OTP send ignored `sendEmail()` returning `false`. The handler now throws and returns the existing controlled JSON error when email delivery fails.
- **P1 — Shared attendance read missed `attendance_records.tenant_id`:** `AttendanceGroupService::getGroupAttendance()` scoped groups and members by tenant but not attendance rows. Added `AND ar.tenant_id = :tid`.
- **P1 — Missing regression coverage:** Added contract assertions for AW helper loading and group attendance row tenant scoping.

### Findings Rejected

- No root `AGENTS.md` exists in this checkout; `.github/AGENTS.md` was read as the available repo agent instruction source.
- CSRF was not added to this public OTP request because it is a login-style unauthenticated endpoint; abuse protection remains a release risk.

### Tests Run

```bash
php -l modules/attendance-wage/handlers.php
php -l modules/attendance-wage/handlers/150-team-lead.php
php -l modules/attendance-wage/services/AttendanceGroupService.php
php -l tests/attendance_wage_contract_parity_test.php
php tests/attendance_wage_contract_parity_test.php
php tests/pal_attendance_bridge_test.php
php tests/attendance_wage_smoke_test.php
php ikabud workbench:doctor attendance-wage
curl -i --max-time 20 -X POST -F 'email=juan.luna@zap-arts.com' http://zapattendance.test/api/v1/attendance/team-lead/send-otp
```

### Test Results

- Syntax checks passed.
- `attendance_wage_contract_parity_test.php`: `34/34 passed`.
- `pal_attendance_bridge_test.php`: `40/40 passed`.
- `attendance_wage_smoke_test.php`: `104/104 passed`.
- `workbench:doctor attendance-wage`: `PASS`; env readiness still reports `NO` without browser credentials/env vars.
- OTP endpoint smoke returned `HTTP/1.1 200 OK` with JSON success/generic message, not 500.

### Remaining Release Risks

- OTP send has no rate limit, so email enumeration resistance exists but mail-abuse throttling is still missing.
- The smoke POST exercised the non-disclosing response path; live SMTP delivery for a confirmed mapped email should still be verified from the browser/session path.
- Email-based team-lead bridge remains fragile until a stable `pal_team_lead_id` bridge is introduced.


### Verify OTP Review

**Date:** 2026-07-18  
**Scope:** `/review team-lead:91` for `POST /api/v1/attendance/team-lead/verify-otp` returning HTTP 422.

#### Findings Corrected

- **P1 — Public team-lead OTP lookup used unresolved tenant state:** `send-otp` compared `attendance_groups.tenant_id` against `app()->tenant()->current()` even when no authenticated request had resolved the host tenant yet. Added `aw_tenant_id()` and made `aw_db()` resolve the host/default tenant on demand.
- **P1 — Unlinked emails falsely advanced to verify:** `send-otp` returned `ok: true` when no active group matched the email, so the browser showed the code form without a `tl_pending_email` session. It now returns controlled HTTP 422 and keeps the user on the email step.
- **P1 — Pending OTP session could survive failed email delivery:** `attendanceApiTeamLeadSendOtp()` wrote `tl_pending_*` session values and closed the session before `sendEmail()` completed. OTP state is now persisted only after `sendEmail()` succeeds.
- Added contract assertions for tenant-on-demand resolution, resolved tenant use in the team-lead OTP lookup, no false-success for unlinked emails, and storing OTP state only after email delivery succeeds.

#### Findings Rejected

- A verify request without the preceding OTP-send session must remain 422; changing that would weaken the OTP flow.
- `team-lead-login.disyl:91` appends the expected `code` field; the browser field name was not the defect.

#### Tests Run

- `php -l modules/attendance-wage/helpers.php`
- `php -l modules/attendance-wage/handlers/150-team-lead.php`
- `php -l tests/attendance_wage_contract_parity_test.php`
- `php tests/attendance_wage_contract_parity_test.php` — `38/38 passed`
- Live `send-otp` for `juan.luna@zap-arts.com`: HTTP 422 with `No active attendance group is linked to this email.` instead of false success.
- Live `send-otp` for an unlinked email: HTTP 422 with the same controlled error.

#### Remaining Release Risks

- End-to-end verification with a real active group bridge and the same browser session still requires the emailed six-digit code.
- `juan.luna@zap-arts.com` is not currently linked to an active Attendance & Wage group in tenant `441` as served by `zapattendance.test`.
- OTP abuse throttling and stable team-lead bridge identity remain open risks documented above.
