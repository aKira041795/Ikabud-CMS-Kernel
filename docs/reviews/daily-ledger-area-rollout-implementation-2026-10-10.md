# Daily Ledger area rollout — implementation report

Date: 2026-10-10  
Tenant verified: `#207 baron-001` / `baronledger.test`

## Outcome

Implemented all four parts in the required order. No commit was created and `TemplateEngine::MAX_OUTPUT_BYTES` was not changed.

## Baseline actually measured

The unchanged-tree daily-ledger run completed as:

- **77 files: 49 passed / 28 failed** (`313363ms`)
- command: `php scripts/run-tests.php --dir=tests/daily-ledger`
- log: `/tmp/dl-round2-baseline.log`

This differs by one from both the owner's current-tree note (48/29) and the older 25-failure measurement. I am reporting the run actually observed rather than normalising it to either prior figure. The unchanged snapshot reported:

- `assigned commissary but mode=self_managed = 11`
- no near-duplicate area spellings
- five non-null stored spellings: `Dapitan`, `Dipolog`, `Mahayag`, `MOLAVE`, `Pagadian City`
- three cross-area supply links (the Dapitan branches supplied from Dipolog)

The unchanged DiSyL conformance check passed. The production HTTP 500 was already discriminated by the supplied production log (`Template output exceeds maximum size (5242880 bytes)`) and by the unchanged handler's unbounded branch-column construction.

## 1. Production 500 and bounded Daily Sheet

`handleAdminCommissary()` now caps the rendered Daily Sheet at **10 destination branch columns**. The construction:

- always retains the source commissary;
- always retains an explicitly selected destination, even when it lies beyond the normal first ten;
- preserves configured paper order when projecting the bounded set;
- computes and displays the number of omitted branches;
- tells the administrator to narrow with branch, commissary, or area scope.

The cap is on the branch-per-product Cartesian rendering that caused the failure; the engine's 5 MiB safety limit remains unchanged.

Proof:

- real HTTP request to `http://baronledger.test/daily-ledger/admin/commissary?scope=ALL`: **200**, `3,190,285` bytes, visible `daily-sheet-branch-limit-notice`;
- `daily_ledger_area_rollout_test.php` inserted **45 temporary active branches**, selected the last one, rendered the real handler, asserted HTTP 200, notice presence, at most ten headings, and selected-branch inclusion, then deleted all temporary rows;
- focused rollout suite: **16/16 passed**.

## 2. Eleven supply modes

Added and applied migration `090_correct_hybrid_supply_modes.sql`. It changes only the owner-confirmed eleven branch codes, and only where an assigned commissary still exists, to `hybrid`.

After migration:

- topology counter `assigned commissary but mode=self_managed`: **0**;
- resolver proof on `DAP-POLO1`: `source=commissary`, `mode=hybrid`.

Falsifier recorded: the current production tables contain zero `dl_production_movements` and zero `dl_production_runs` for each of the eleven. That absence does not prove the branches never produce locally, while the owner's statement says some do. If the owner confirms that any specific branch has no local production at all, that branch should be relabelled `commissary_supplied`; delivery resolution remains `commissary` either way.

## 3. Canonical areas

Added and applied migration `091_canonical_areas.sql`:

- creates `dl_areas(id, code, name, is_active, sort_order)`;
- adds nullable `area_id` foreign keys to `dl_branches` and `dl_consignees`;
- retains both legacy text columns for compatibility;
- leaves NULL/empty legacy areas as NULL;
- creates canonical codes `DAPITAN`, `DIPOLOG`, `MAHAYAG`, `MOLAVE`, and `PAGADIAN`;
- preserves display names, including stored `MOLAVE` and `Pagadian City`;
- aborts rather than auto-merging when distinct spellings normalise identically;
- aborts if any nonempty historical value remains unmapped.

The branch and consignee handlers now validate `area_id`, write `area_id`, and mirror the canonical display name into the legacy text column. Reads join `dl_areas`. All three old free-text controls are now canonical selectors:

- `add-area-id`
- `edit-area-id`
- `consignee-area-id`

The topology snapshot was extended to verify canonical mappings. Final result: **0 nonempty legacy values without a canonical id**.

## 4. Area-scoped admin views

Added one resolver, `AdminAreaScope::resolve(request, session, user)`, with:

- `ALL`, `AREA(id)`, and `COMMISSARY(id)` states;
- precedence: explicit validated `scope`, then persisted session state, then `ALL`;
- explicit `scope=ALL` reset;
- active area/commissary validation;
- prominent shared-layout scope banner, picker, and one-click All reset.

`COMMISSARY(id)` resolves to the commissary plus active branches assigned to it, deliberately not the geographic area's branch set. Branches, consignees, the Daily Sheet, standard governed reports/exports, and POS report/export paths consume the shared scope. A final shared render guard also prevents branch-keyed list context from escaping a selected admin scope.

Authorization remains separate:

- `dl_accessibleBranchIds()` was not modified;
- the resolver returns `ALL` and does not persist scope for cashier/production/supervisor roles;
- the acceptance test activates an admin area scope and proves a cashier's authorized branch IDs are byte-for-byte unchanged before and after;
- report display/export criteria are produced by the same `dl_reportFilters()` call and are asserted equal to the selected area's branch set.

## Naming slips deliberately not changed

As required, no silent cleanup was made:

- branch 27 `PAG-MOLAVE1` remains in canonical area `MOLAVE`;
- branch 28 `MOLAVE1` remains in area `Mahayag`;
- `PAG-COMMISARY1` retains its existing misspelling;
- display casing `MOLAVE` remains unchanged.

These require a separate human data decision.

## Verification

| Check | Result |
|---|---|
| Focused rollout acceptance | **16/16 passed** |
| Temporary-branch ceiling proof and cleanup | **passed** |
| Real admin HTTP request | **200** |
| Supply integrity counter | **0** |
| Canonical nonempty unmapped counter | **0** |
| Delivery resolver (`DAP-POLO1`) | **commissary** |
| Cashier authorization unchanged under admin scope | **passed** |
| Governed report display/export criteria parity | **passed** |
| `php tools/disyl-conformance-check.php` | **passed** |
| `php ikabud module:validate daily-ledger` | **passed** (existing fallback-context warning only) |
| PHP syntax checks on changed PHP | **passed** |
| `daily_ledger_production_sheet_test.php` standalone | **53/53 passed** |

Final full daily-ledger run:

- **78 files: 49 passed / 29 failed** (`340547ms`)
- the additional file is the new rollout suite;
- all 16 new rollout assertions passed;
- the sole apparent delta from the measured baseline failure set was `daily_ledger_production_sheet_test.php`, whose only failure was an unrelated transient `kernel_state_cache: module_registry rebuilt` log line; rerunning it alone immediately passed **53/53**. No production-sheet behavioural assertion failed.

`php ikabud module:check-boundaries` remains red on pre-existing violations in unrelated modules (for example WMS and CMS Akira); no daily-ledger boundary finding was introduced.

## Files and operational state

Migrations 090 and 091 are registered in `module.json` and are recorded as applied for tenant 207. Temporary ceiling-test branches were removed. The unrelated pre-existing untracked file `docs/reviews/windows-desktop-client-feasibility-2026-10-07.md` was not touched.
