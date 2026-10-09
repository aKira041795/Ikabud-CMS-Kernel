# Daily Ledger suite triage — 2026-10-09

## Result

I ran all 77 `tests/daily-ledger/*_test.php` suites before changing the tree and again after the two fixes below. Each suite was run as a separate `timeout 120 php …` process with output captured to a file.

- **Before:** 50 passing, **27 failing**
- **After:** 52 passing, **25 failing**
- Acceptance result: the failing-suite total fell from 27 to 25.

No assertion was deleted or skipped, no expected value was changed to match buggy output, and no `allowLogLines()` entry was added. In particular, the `ModuleDB DENIED` warning was **not** allow-listed: DDL during a module request is not expected behavior. Functional assertions in the one-failure cluster passed independently; their sole failure was the harness's appended-log check.

## Classification and evidence

`SUITE ABORTED` means the process reached no result count. “Environment” includes mutable local-test-fixture state; “defect” means a repository defect. Rows marked **not fixed** remain red intentionally.

| Suite | Before | After | Classification | Cause | Fix or remedy |
|---|---:|---:|---|---|---|
| `daily_ledger_admin_refusal_visibility_test` | 26/27 | 26/27 | environment | All 26 functional assertions pass. Missing control table causes request-time `CREATE TABLE IF NOT EXISTS kernel_module_catalog`, which ModuleDB correctly denies and logs. | **Not fixed:** migrate the control DB; do not allow-list the warning. |
| `daily_ledger_admin_sales_full_sheet_test` | 58/59 | 58/59 | environment | Same missing `kernel_module_catalog`; every functional assertion passes. | **Not fixed:** migrate the control DB. |
| `daily_ledger_admin_trace_test` | 57/67 | 57/67 | environment | Rendered HTTP probes use the unwritable/stale compiled-template cache; the run also emits the missing-catalog DDL warning. | **Not fixed:** repair cache writability and migrate the control DB. |
| `daily_ledger_branch_cell_entry_test` | 29/30 | 29/30 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_branch_order_test` | 13/14 | 13/14 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_branch_vs_commissary_listing_test` | 6/8 | **8/8** | environment | The test assumed live product 13 had no actionable activity. The newer unassignment guard legitimately refused the commissary hide, but the test ignored its return value and misreported that as listing coupling. | **Fixed:** use an isolated high-ID product with no ledger activity, exercise the same production functions, and remove its pairs/product/audit rows in `finally`. Functional expectations were not weakened. |
| `daily_ledger_c1_derived_ending_test` | 7/8 | 7/8 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_consignee_activity_test` | 9/10 | 9/10 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_consignee_admin_test` | 11/12 | 11/12 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_consignee_audit_test` | 7/8 | 7/8 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_daily_sheet_log_evidence_test` | 17/18 | 17/18 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_defect_fixes_s13_test` | 45/48 | 45/48 | environment | The current commissary template contains the posted-only total and wastage-aware balance, but the local compiled cache cannot be refreshed. The run renders stale output and also logs missing-catalog DDL. | **Not fixed:** repair cache writability, clear/rebuild compiled cache through the normal owner workflow, and migrate the control DB. |
| `daily_ledger_delivery_variance_visibility_test` | 19/20 | 19/20 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_dispatch_enforcement_test` | 12/15 | 12/15 | environment | Unwritable/stale rendered templates hide the expected audit links. Rendering against this mutable tenant also runs the stale-day sweep, adding unrelated tenant notifications, so whole-tenant before/after counts change. Missing-catalog DDL dirties the log too. | **Not fixed:** repair cache, migrate control DB, and run against a clean test tenant (or resolve its overdue ledger state). Assertions were not narrowed to hide the writes. |
| `daily_ledger_finding_type_enum_test` | 26/27 | 26/27 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_g2_destination_visibility_test` | 13/14 | 13/14 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_login_name_and_withdrawal_filter_test` | 29/30 | 29/30 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_overview_test` | SUITE ABORTED | SUITE ABORTED | environment | Confirmed cache-permission failure: `Failed to write compiled template cache` for `overview.disyl`. | **Not fixed:** owner must make the cache group-writable. |
| `daily_ledger_preserve_cashier_variance_test` | 27/29 | 27/29 | environment | Mutable tenant overdue data makes the render-triggered stale-day sweep add unrelated notifications/recipients; missing-catalog DDL also dirties the log. | **Not fixed:** migrate control DB and use a clean test tenant or resolve its overdue ledger state. The tenant-wide invariant was not weakened. |
| `daily_ledger_production_beg_carry_test` | 12/13 | 12/13 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_production_controls_test` | 70/71 | 70/71 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_production_shift_access_test` | 14/15 | 14/15 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_received_vs_sent_test` | 26/27 | 26/27 | environment | Same missing-catalog DDL warning; all functional assertions pass. | **Not fixed:** migrate the control DB. |
| `daily_ledger_routes_test` | 80/82 | **82/82** | defect | `routes.php` had three active, handler-backed routes absent from the ownership contract: GET `/products`, POST `/products/toggle`, and POST `/deliveries/consignee-return`. The route table—not the test—was the runtime source of truth, and other feature suites exercise these endpoints. | **Fixed:** added all three routes to `modules/daily-ledger/workbench-contract.json`; no assertion changed. |
| `daily_ledger_shared_account_latest_holder_test` | 22/28 | 22/28 | environment | All HTTP login/profile behavior passes, but the web user cannot append the expected audit lines to developer-owned `storage/logs/app.log`; only the six log-evidence assertions fail. | **Not fixed:** restore shared developer/web-server log writability; do not weaken log assertions. |
| `daily_ledger_shell_drift_guard_test` | 91/95 | 91/95 | defect | Genuine shell-copy drift: the inlined nav in `commissary.disyl` and `variances.disyl` lacks the layout's `feature_consignee` wrapper, failing sidebar and nav byte comparisons. | **BLOCKED / ARCHITECTURE_DECISION_REQUIRED:** correct files are under `templates/**`, outside authorised edit scope. HARPP decision 152 was opened. Assertions remain intact. |
| `daily_ledger_shift_reconciliation_test` | 64/81 | 64/81 | environment | Confirmed cache-permission failure for `reconciliation.disyl`; downstream markup assertions then fail because both renders threw. | **Not fixed:** owner must make the compiled cache writable. |

## Owner remedies (not attempted)

I did **not** run any elevation or ownership/permission command.

1. The developer is already in group `www-data`, while `storage/cache` and the named subdirectories are `www-data:www-data` mode 755. The exact owner command is:

   ```bash
   sudo chmod -R g+rwX /var/www/html/applicationostest/storage/cache
   ```

2. Provision the missing control-plane table through the migration runner (not through module request code):

   ```bash
   cd /var/www/html/applicationostest && php ikabud migrate:control
   ```

3. Restore app-log sharing between the developer harness and web worker (current file is `kajagogoo:kajagogoo` mode 664):

   ```bash
   sudo chown kajagogoo:www-data /var/www/html/applicationostest/storage/logs/app.log
   sudo chmod 664 /var/www/html/applicationostest/storage/logs/app.log
   ```

No storage permission or ownership was modified during this triage; `git diff` contains no storage path.

## Why the two edits are fixes rather than assertion weakening

- **Routes:** before rerun was 80/82 and both failures directly reported contract/route disagreement. Runtime `routes.php` had three additive, handler-backed endpoints while the ownership manifest omitted them. Updating the manifest produced 82/82. No test code changed.
- **Branch vs commissary:** before rerun was 6/8. Product 13 was mutable tenant data and the product-unassignment safety guard could now legitimately refuse its hide. The suite now creates a no-activity product solely for this behavior and still requires both removals and both independence properties. It produced 8/8. The final cleanup assertion now verifies complete fixture removal.

## Explicitly not fixed

The remaining 25 red suites were not made cosmetically green. Twenty-four require local DB/cache/log/tenant remediation, and the one genuine template drift defect is outside the authorised paths. No assertion was removed, skipped, relaxed, or allow-listed to conceal those causes.
