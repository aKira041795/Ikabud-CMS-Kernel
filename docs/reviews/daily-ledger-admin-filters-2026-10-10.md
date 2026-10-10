# Daily Ledger admin Area filters — implementation review

Date: 2026-10-10

## Result

Branch-scoped admin pages now use one Area control in the shared admin layout. The first option is **All areas**, options come from `AdminAreaScope::resolve()` (`dl_areas`), and the selected resolved `branch_ids` constrain each page's data query. The control is rendered only when a handler declares that the page has a branch dimension.

`dl_adminViewBranchIds()` keeps authorization and presentation scope separate: it first reads `dl_accessibleBranchIds()`, applies Area scope only for admins, and returns authorization unchanged for operational roles.

## Inventory

The “existing filters” column starts with the contract's measured inventory rather than a new audit.

| View | Existing filters (contract measurement) | Branch-scoped? | Area dropdown added? | Handler applies resolved scope? | Evidence / deliberate exclusion |
|---|---|---:|---:|---:|---|
| branches | `add-area-id`, `edit-area-id`, consignee selects + search | Yes | Yes | Yes | Existing `handleAdminBranches()` scope query retained; shared control enabled. |
| commissary | `production-addition-product`, `branch-cell-*`, `consignee-cell-*`, `dispatch-dest-branch-id` | Yes | Yes | Yes | Existing `handleAdminCommissary()` consumer retained, including its bounded destination query. |
| consignee-dispatch-report | `cdr-consignee`, `cdr-product`, `cdr-shift` | Yes, through consignee assigned commissary | Yes | Yes | `handleAdminConsigneeDispatchReport()` passes resolved IDs; rows and option queries constrain `c.assigned_commissary_id`. |
| production | `wd-branch-id` | No active standalone view | No | N/A | Deliberately excluded: `admin/production.disyl` is a legacy partial with no handler/route; `/admin/production-output` redirects to the scoped commissary Daily Sheet. |
| products | `picker-branch`, `picker-state`, add/edit category + 2 searches | Yes, branch assignment dimension | Yes | Yes | `handleAdminProducts()` scopes branch pickers, consignee networks, assignment IDs, and assignment counts. |
| reconciliation | `recon-branch`, `recon-only` | Yes | Yes | Yes | `handleAdminReconciliation()` uses `dl_adminViewBranchIds()` for picker and ledger aggregate. |
| usage | `commissary-branch` | Yes | Yes | Yes | `dl_buildUsagePageData()` scopes destination branches, runs, paper captures, and output maps. |
| users | add/edit role, add/edit shift, add/edit branch(es) + search | Yes, user-to-branch assignments | Yes | Yes | `handleAdminUsers()` scopes branch pickers, rows with assignments, and tab counts; global users with no branch assignment remain visible. |
| price-groups | search; no selects | No | No | N/A | Deliberately excluded as required by the contract: price-group definitions/prices are global. Branch usage links are management metadata, not the row dimension. |
| withdrawals | no selects (2 incidental “area” mentions) | Yes | Yes | Yes | `handleAdminWithdrawals()` constrains rows and branch/commissary options to resolved IDs. |
| activity | no filter controls | Yes for branch-bearing audit events | Yes | Yes | `handleAdminActivity()` adds the resolved branch predicate to list and count; global `branch_id IS NULL` events remain visible. |
| branch-summary | no filter controls | No standalone data | No | N/A | Deliberately excluded: route immediately redirects to scoped Sales; it has no independent query or rendered view. |
| dashboard | no filter controls | Yes | Yes | Yes | Branch cards, sales, statuses, variance count, activity, and unsynced-device rows use the resolved set. |
| deliveries | no filter controls | Yes | Yes | Yes | Page picker and `apiListDeliveries()` constrain origin/destination branches with the resolved set. |
| forecast | no filter controls | Yes | Yes | Yes | Existing reporting architecture consumes `dl_reportFilters()` and resolved `accessible_branch_ids`. |
| overview | no filter controls | Yes | Yes | Yes | `handleAdminOverview()` passes resolved IDs to cards, product analytics, sales, and forecast. |
| pos-sales | no filter controls | Yes | Yes | Yes | Existing POS consumer consolidated on `dl_adminViewBranchIds()`; page, API query, and CSV export share it. |
| reports | no filter controls | Detail reports only | Conditional | Yes | Landing page deliberately has no Area control because it lists report packs/archives, not branch rows. Report detail/export paths retain `dl_reportFilters()` scope and show the control. |
| sales | no filter controls | Yes | Yes | Yes | `handleAdminSales()` uses one resolved set for both union arms, totals, rows, coverage, and branch options. |
| settings | no filter controls | No | No | N/A | Deliberately excluded: tenant/module settings have no branch dimension. Runtime test proves Area A and Area B render the same nonempty row count and no dropdown. |
| trace | no filter controls | Yes | Yes | Yes | Authorization validation still uses `dl_accessibleBranchIds()`; `dl_buildAdminTraceData()` receives the separately resolved view set. |
| variances | no filter controls | Yes | Yes | Yes | `handleAdminVariances()` constrains refresh, aggregate, summary, list, and picker queries. Out-of-view selection yields an empty view, not an authorization denial. |

## Query and UI architecture

- `templates/modules/daily-ledger/layouts/app.disyl` owns the single consistently placed `#admin-area-filter` control.
- The label is **Area** and **All areas** is first; canonical area options are supplied by the resolver. Existing commissary-network scope remains available after the area options.
- `dlRender()` injects/resolves scope only when `admin_area_filter` is true, preventing decorative controls on global views.
- `dl_adminViewBranchIds()` is the common view-query boundary. It does not mutate or replace `dl_accessibleBranchIds()`.
- Existing consumers in Branches, Commissary, POS Sales, and reporting remain active.

## Acceptance evidence

### Before changes (unchanged tree)

| Command | Result |
|---|---:|
| `php tests/daily-ledger/daily_ledger_area_rollout_test.php` | 16/17 assertions passed; only failure was unrelated concurrent `app.log` output (`kernel.export`) |
| `php tests/disyl_conformance_test.php` | 229 passed, 0 failed |
| `php tests/daily-ledger/daily_ledger_handlers_test.php` | 229/229 passed |
| `php tests/daily-ledger/daily_ledger_reporting_test.php` | 76/77 passed; only failure was unrelated `app.log` output (`disyl.compile.phases`) |

No warning was allow-listed to alter those results.

### After changes

| Command | Result |
|---|---:|
| `php tests/daily-ledger/daily_ledger_area_rollout_test.php` | **22/22 passed** |
| `php tests/disyl_conformance_test.php` | **229 passed, 0 failed** |
| `php tests/daily-ledger/daily_ledger_handlers_test.php` | **229/229 passed** |
| `php tests/daily-ledger/daily_ledger_reporting_test.php` | **76/76 passed** |

The area rollout suite uses real handler renders and two canonical areas with distinct fixture branches. It proves different row sets in four views:

1. Branches
2. Dashboard
3. Overview
4. Sales

It also proves the false-positive direction with Settings: both selections retain the same nonzero row count and neither renders an Area dropdown.

The authorization test proves the cashier's `dl_accessibleBranchIds()` is identical before and after an admin Area scope is active; the non-admin resolver result remains `ALL`. Trace and Deliveries also retain authorization checks separately from their view-query constraints.

The Commissary acceptance remains green: `handleAdminCommissary()` returns HTTP 200, reports omitted columns, renders at most 10 destinations, and retains an explicitly selected destination beyond the normal first page.

## Explicitly unchanged / excluded

- No Area dropdown was added to Settings or Price Groups because their row sets have no branch dimension.
- No standalone dropdown was added to the legacy Production partial or Branch Summary redirect because neither owns a data query.
- Reports landing remains unfiltered; report detail and export are scoped together.
- Cashier/production authorization code was not changed to consume admin Area scope.
- The Commissary Daily Sheet 10-destination bound was not changed.
- `TemplateEngine::MAX_OUTPUT_BYTES` was not changed.
- No assertion was removed, no `skip()` was added, and no `allowLogLines()` entry was added.
- The pre-existing untracked `docs/reviews/windows-desktop-client-feasibility-2026-10-07.md` was not touched.

## Full daily-ledger sweep

Pending completion of the detached post-change sweep; final counts are appended below before review.
