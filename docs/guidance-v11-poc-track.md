# Guidance DiSyL v11 POC — Tracking

**Status**: In Progress  
**Last Updated**: 2026-06-21

---

## Phase 1: Fix Broken `{base_url}` Links (P0)

| Step | Action | Status | Notes |
|---|---|---|---|
| 1.1 | Register contract `modules/guidance/` with `base_url => /admin/guidance` | ✅ | `helpers.php` — `guidance.template.base` contract with prefixes `modules/guidance/partials/` + `modules/guidance/modals/` |
| 1.2 | Switch `app()->render()` → `guidanceRender()` in split handlers | ✅ | 12 calls across 7 files (20-cases, 25-appointments, 30-booking, 35-users, 40-colleges, 50-profile, 60-notes) |
| 1.3 | Remove redundant `base_url` from `55-reports.php` | ✅ | 1 line removed |

## Phase 2: Clear Stale Cache (P0)

| Step | Action | Status | Notes |
|---|---|---|---|
| 2.1 | Delete compiled cache files | ✅ | 9 files deleted from `storage/cache/compiled/` |
| 2.2 | Clear PHP opcache | ✅ | `opcache_reset()` |
| 2.3 | Load each page with `?disyl_nocache=1` | ⬜ | First load per template triggers fresh compile |

## Phase 3: Entity View Integration (P1)

| Step | Action | Status | Notes |
|---|---|---|---|
| 3.1 | Expand view configs with all modes | ✅ | `guidance_case` table view expanded to 8 fields + 3 actions |
| 3.2 | Expand `entity.list.guidance_case` capability handler | ✅ | Includes all fields, college enrichment, sorting, pagination |
| 3.3 | Create entity-view-powered template | ✅ | `partials/cases-table-entity.disyl` uses `{ikb_entity_list source="guidance.case" view="table"}` |
| 3.4 | Wire handler for entity mode | ✅ | `?entity=1` switches to entity-powered template in `20-cases.php` |
| 3.5 | Add entity view tests | ⬜ | |

## Phase 4: State Manager + Bridge Demo (P2)

| Step | Action | Status | Notes |
|---|---|---|---|
| 4.1 | Implement `{state}` in one template | ⬜ | |
| 4.2 | Wire compiled manifest v11 format | ⬜ | |
| 4.3 | React bridge POC — `ReactBridge.php` + lazy CDN + CasesTable | ✅ | `kernel/DiSyL/Bridge/ReactBridge.php`, `?react=1` toggle on cases page |

## Phase 5: Testing & Validation

| Step | Action | Status | Notes |
|---|---|---|---|
| 5.1 | Run `disyl_v11_verify_test.php` | ✅ | 64 passed, 0 failed |
| 5.2 | Check app.log + error.log | ✅ | Both empty (0 lines) |
| 5.3 | Load all guidance pages in browser | ✅ | Entity view confirmed working (styled table) |
| 5.4 | Verify no JS errors, no 404s | ⬜ | Need browser testing |
| 5.5 | Guidance entity view integration tests | ✅ | `tests/guidance_entity_view_test.php` — 44 assertions |
| 5.6 | React bridge renders via `?react=1` | ✅ | Lazy CDN, styled table with React.createElement |
