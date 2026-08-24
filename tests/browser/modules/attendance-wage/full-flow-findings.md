# Attendance & Wage Full-Flow Findings

## AW-FF-001 — Attendance records render contract used an uninitialized value
- **Severity:** P1
- **Journey/step:** PW-1, records render / all-employees and unknown-employee paths
- **Reproduction:** Open `/admin/attendance` after a full reset, or open `/admin/attendance?employee_id=999999999`.
- **Expected:** The records template always receives a string for `rendered_records_table`.
- **Actual:** `$renderedRecordsTable` was initialized only inside the selected employee/history branch, causing `Undefined variable $renderedRecordsTable` on other paths.
- **Affected:** `modules/attendance-wage/handlers/10-pages-attendance.php`, `/admin/attendance`
- **Evidence:** Existing `storage/logs/error.log` warning; source regression assertions in `tests/attendance_hours_inline_edit_test.php`; PW-1 normal-URL render assertions.
- **Disposition:** Fixed at the render-contract initialization root; no warning suppression.
- **Fix diff:** Initialize the value before all selection/database branches and remove branch-local initialization.
- **Verification:** `php tests/attendance_hours_inline_edit_test.php`; `APP_URL=http://zapattendance.test npx playwright test tests/browser/modules/attendance-wage/full-flow-targeted.spec.js --workers=1`

## AW-FF-002 — Employee create omitted mandatory user identity
- **Severity:** P1 / data integrity
- **Journey/step:** 2, employee create
- **Reproduction:** Submit `/admin/wage/employees/create` on schema migration 002 where `employee_profiles.user_id` is `NOT NULL`.
- **Expected:** Product employee creation atomically creates a least-privilege employee identity and linked profile.
- **Actual:** The profile insert omitted `user_id`, producing a database error and preventing all UI employee creation.
- **Affected:** `modules/attendance-wage/handlers/40-api-employees.php`, `POST /api/v1/wage/employees`
- **Evidence:** Migration 002 schema versus handler insert contract; PW-2 creates and reads back four salary types.
- **Disposition:** Fixed atomically. Generated employee credentials are random and not logged or exposed; identity/profile rollback together.
- **Fix diff:** Transactional employee-role identity creation, profile `user_id`, rollback on failure.
- **Verification:** PW-2 employee CRUD step; PHP lint and AW suites.

## AW-FF-003 — Contribution lookup was not tenant scoped
- **Severity:** P1 / security / financial integrity
- **Journey/step:** 9, benefit calculator and payroll contribution lookup
- **Reproduction:** Insert overlapping active bands for two tenant IDs, then calculate the same salary.
- **Expected:** Only the active tenant's dated band is eligible.
- **Actual:** Lookup filtered type/date/salary but not `tenant_id`, allowing another tenant's band to determine payroll deductions.
- **Affected:** `modules/attendance-wage/helpers.php::aw_calculateBenefits`, `POST /api/v1/wage/benefits/calculate`
- **Evidence:** Source query; guarded tenant-441 bands and independent PW-1 constants.
- **Disposition:** Fixed by binding resolved tenant ID in the rate query.
- **Fix diff:** Add `tenant_id=:tid` and `aw_tenant_id()` binding.
- **Verification:** `php tests/attendance_wage_payroll_computation_test.php`; fresh normal-URL PW-1 at 2026-08-24 09:53 passed and returned the tenant-441 low Pag-IBIG band (₱50/₱50), total employee ₱345 and employer ₱395.

## AW-FF-004 — Full financial contract journey and cardinality
- **Severity:** P1 / financial integrity
- **Journey/step:** 5–11 (attendance ledger through paid outputs)
- **Reproduction:** Run PW-2 after guarded prep with one worker.
- **Expected:** UI/API transitions reconcile to independent cents constants and exact module-table cardinalities.
- **Actual:** Closed. PW-2 inserts regular, rounded overtime/double-overtime, rest-day, night, and holiday attendance; edits hours through the rendered inline control; computes hourly singly and daily/monthly/fixed in bulk; and reconciles every component. It creates and applies two adjustments, one manual deduction, one policy-valid cash advance, rejects an over-limit request, and proves repeated compute/approve/pay/advance approval cannot duplicate or change paid state.
- **Affected:** `full-flow-journey.spec.js`; module-local attendance, deduction, cash-advance, computation, and report handlers.
- **Evidence IDs:** `test_results/browser/runs/20260824050655-8cc1a605/`; `test_results/browser/attendance-wage-full-flow/financial-ledger-cardinality.json`; `output-reconciliation.json`; `payroll-period.csv`.
- **Ledger evidence:** Hourly paid ledger: regular 24.00h/₱2,400.00, OT 2.00h/₱250.00, double OT 0.25h/₱37.50, holiday ₱800.00, night ₱80.00, rest day ₱1,040.00, additions ₱323.45, deductions ₱630.79, net ₱4,300.16. Daily net ₱10,438.67; monthly net ₱21,833.33 including ₱250.00 tax; fixed net ₱14,700.00 including disabled Pag-IBIG and one ₱500.00 cash-advance repayment.
- **Cardinality evidence:** Exactly four stable computation IDs before/after recompute and after repeated final transitions; exactly two adjustments, one employee deduction, one repayment, and one advance. The one repayment transitions pending→deducted→paid, and the advance transitions to completed with ₱0.00 balance and one paid installment. Paid gross/deduction/net tuples remain byte-for-byte equal after repeated pay/compute and batch-pay no-op.
- **Disposition:** Closed with root fixes and guarded read-only DB evidence.
- **Verification:** `APP_URL=http://zapattendance.test npx playwright test tests/browser/modules/attendance-wage/full-flow-journey.spec.js --workers=1`.

## AW-FF-005 — Test host login limiter exhausted during verification
- **Severity:** P1 environment blocker
- **Journey/step:** Prep before PW-1/PW-2
- **Reproduction:** Run the prep after prior aborted preparation attempts from `127.0.0.1`.
- **Expected:** A fresh run has capacity for exactly two password logins.
- **Actual:** The least-privilege login failed closed with HTTP 429 metadata (`retry_after=214`); no retry or limiter wait was performed.
- **Affected:** Verified shared login limiter; no product file changed.
- **Evidence:** Prep stderr and `storage/logs/app.log` event `auth.login_rate_limited`.
- **Disposition:** Closed. Fresh prep completed with exactly two password logins, no retries, and no 429.
- **Verification:** Prep output at `test-results/attendance-wage-full-flow/prep.json`, prepared `2026-08-24T01:50:23.782Z`.

## AW-FF-006 — Host lacks APP_SECRET and unrelated AI helper emits a deprecation
- **Severity:** P2 environment / out-of-scope
- **Journey/step:** Prep and PW-1 anonymous guard
- **Reproduction:** Render authenticated AW settings; execute anonymous guard checks.
- **Expected:** Both logs contain no warnings.
- **Actual:** `app.log` warns that the default app secret is in use; `error.log` reports a PHP deprecation from `modules/ai/helpers.php:22`.
- **Affected:** Environment configuration and prohibited non-AW module.
- **Evidence:** Both run logs after the respective tiers.
- **Disposition:** Deferred as explicitly out of scope. Owners: environment operator (APP_SECRET) and AI module owner. AW workaround: none; warnings remain visible and acceptance remains blocked.

## AW-FF-007 — Pag-IBIG fixed bands fell through to statutory fallback
- **Severity:** P1 financial correctness
- **Journey/step:** PW-1 benefit constants
- **Reproduction:** Seed tenant-441 fixed Pag-IBIG low band (₱50/₱50), then calculate benefits for ₱1,000 through the normal HTTP route.
- **Expected:** The dated tenant band returns ₱50 employee and ₱50 employer.
- **Actual:** The compact nullable-band calculation returned fallback ₱20/₱20 even though the tenant-scoped query selected the ₱50 row. This reproduced in fresh CLI and HTTP requests, disproving the earlier stale-worker diagnosis.
- **Affected:** `modules/attendance-wage/helpers.php::aw_calculateBenefits`, `POST /api/v1/wage/benefits/calculate`.
- **Evidence:** Initial PW-1 trace `test-results/modules-attendance-wage-fu-54155-ependent-boundary-constants-chromium/trace.zip`; fresh passing run `test_results/browser/runs/20260824015334-05f84f5a/`.
- **Disposition:** Fixed by explicit nullable min/max and fixed/percentage normalization while retaining tenant/date/salary scoping and statutory fallback.
- **Verification:** Fresh normal-URL request returned Pag-IBIG ₱50/₱50; PW-1 4/4 passed; payroll PHP suite 24/24 passed.

## AW-FF-008 — Anonymous form-encoded API calls redirected with HTTP 200
- **Severity:** P1 / security contract
- **Journey/step:** 12, anonymous API guard
- **Reproduction:** Anonymous `POST /api/v1/wage/settings/data-reset` with form encoding.
- **Expected:** JSON HTTP 401 and no mutation.
- **Actual:** The guard classified every form POST as a browser form, followed a login redirect, and surfaced HTTP 200 HTML.
- **Affected:** `modules/attendance-wage/handlers/00-bootstrap.php::attendanceWageGuard`.
- **Evidence:** Initial PW-1 failure trace; response was login HTML and no reset event was logged.
- **Disposition:** Fixed by classifying `/api/` before form/page redirect handling.
- **Verification:** PW-1 anonymous guard passed with HTTP 401 in run `20260824015334-05f84f5a`.

## AW-FF-009 — Payroll settings upsert reused native PDO placeholders
- **Severity:** P1 / setup blocker
- **Journey/step:** 1, baseline settings
- **Reproduction:** POST all payroll defaults to `/api/v1/wage/settings` when a tenant settings row exists.
- **Expected:** Insert/update succeeds atomically.
- **Actual:** The same named placeholders appeared in VALUES and ON DUPLICATE KEY UPDATE, causing `SQLSTATE[HY093]: Invalid parameter number`.
- **Affected:** `modules/attendance-wage/handlers/95-api-settings.php`.
- **Evidence:** `app.log` request `078bd4af6c8d366d`; first PW-2 trace.
- **Disposition:** Fixed with distinct `:update_*` placeholders.
- **Verification:** Subsequent PW-2 baseline save passed; final PW-2 run passed.

## AW-FF-010 — Payroll period create UI has no create form contract
- **Severity:** P1 / acceptance blocker
- **Journey/step:** 6, bounded period creation
- **Reproduction:** Open `/admin/wage/periods/create`.
- **Expected:** A create form posting to `/api/v1/wage/periods`.
- **Actual:** The route renders the edit-only template titled “Edit Payroll Period” with action `/api/v1/wage/periods/{id}` and no valid create action. PW-2 had to use the registered product API.
- **Affected:** `templates/modules/attendance-wage/wage/periods/form.disyl`, page handler/route.
- **Evidence:** Failed PW-2 runs `20260824021053-ddf22e31` and `20260824021222-c2efff6c`.
- **Disposition:** Closed. The page handler now supplies an explicit create/edit contract and the shared template posts create to `/api/v1/wage/periods` and edit to `/api/v1/wage/periods/{id}`.
- **Verification:** PW-2 created the bounded period through the rendered create form, found it in the API-backed list, opened it for edit, and verified the edit action/readback in run `20260824042025-dfe5f625`.

## AW-FF-011 — Kiosk smart toggle cannot reject duplicate explicit transitions
- **Severity:** P1 / attendance integrity blocker
- **Journey/step:** 3, duplicate clock-in/out
- **Reproduction:** POST `/api/v1/kiosk/clock` repeatedly for one employee.
- **Expected:** Explicit duplicate clock-in and duplicate clock-out are rejected.
- **Actual:** The endpoint infers the next action from the current open record, so repeated requests alternate in/out and can create another record; clients cannot express an explicit transition to reject.
- **Affected:** `modules/attendance-wage/handlers/130-api-kiosk.php::kioskApiClock`.
- **Evidence:** Source transition review during final gate; PW-2 only proves search eligibility because duplicate rejection cannot be asserted against this contract.
- **Disposition:** Closed. `kioskApiClock` accepts `clock_in|clock_out`, rejects both duplicate states with HTTP 409, tenant-scopes the open-record lookup, and retains smart toggle when omitted.
- **Verification:** PW-2 explicit in → duplicate-in rejection → explicit out → duplicate-out rejection → one completed-record cardinality passed in run `20260824042025-dfe5f625`.

## AW-FF-012 — No deterministic OTP mail-capture transport is configured
- **Severity:** P1 acceptance blocker
- **Journey/step:** 4, team-lead OTP
- **Reproduction:** Inspect configured AW OTP dispatch and test environment.
- **Expected:** Exactly one OTP reaches deterministic test mail capture without plaintext logs or a bypass.
- **Actual:** OTP uses shared SMTP; log/null modes discard the body, and no mailbox API/capture transport is available to PW-2. Production SMTP credentials cannot be used as deterministic evidence.
- **Affected:** `modules/attendance-wage/handlers/150-team-lead.php` and environment mail transport.
- **Disposition:** Closed. Migration 026 adds encrypted, tenant/email/group-scoped, expiring single-use OTP records. Verification uses the stored hash and atomically consumes the record. The guarded E2E reader decrypts only the scoped latest record in process memory and then completes the real verify API; it does not bypass verification or log/store plaintext.
- **Verification:** Exactly one send, guarded storage read, real verify, dashboard access, and reuse rejection passed in PW-2 run `20260824042025-dfe5f625`.

## AW-FF-013 — Final-gate logs contain new AW warnings/deprecations
- **Severity:** P1 acceptance blocker
- **Journey/step:** log gate after PW-2
- **Reproduction:** Run PW-2 and inspect both logs.
- **Expected:** No new warning/error.
- **Actual:** AW emits strict-template undefined variables, missing entity fields, undeclared `stores` access, and invalid-transition warning events. PHP 8.4 CSV deprecations were observed and fixed with an explicit escape argument. The out-of-scope AI deprecation and missing APP_SECRET warning also occurred during the browser tier.
- **Affected:** AW employee/group/adjustment templates, entity contracts and report query; environment/AI module for the previously deferred warnings.
- **Evidence:** Browser-tier `storage/logs/app.log`/`error.log` inspection from the 2026-08-24 final gate; PW-2 traces listed above.
- **Disposition:** Closed. Fixes cover employee JavaScript tokenization, strict group/adjustment/period contracts, period/report entity fields, undeclared attendance stores, unsupported filters, expected transition log levels, explicit PHP 8.4 CSV escapes, and the final employee-deduction aggregate entity-view mismatch found by the crawl.
- **Final evidence:** Fresh prep (two password logins, no 429) → PW-1 `20260824050517-32a377de` → PW-2 `20260824050655-8cc1a605` → crawl/sidebar `20260824050850-e14af13e`, all on normal URLs with one worker. Both logs contain no AW strict-template, missing-field, undeclared-store, invalid-transition warning-level, SQL/render, warning, notice, or error entry. Granular keep-users cleanup succeeded and backup `attendance-wage-db-backup-20260824-130446.sql` remains retained.
- **Deferred labels:** AI helper PHP deprecation and missing APP_SECRET remain AW-FF-006. Kernel warnings for unrelated missing EHR/patient/results providers and generic host `slow_request` telemetry are out-of-scope operational events; no warning was suppressed.
