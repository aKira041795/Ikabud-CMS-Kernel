# Guidance DiSyL v11 POC — Tracking

**Status**: POC complete — entity views and bridges proven. State, manifest consumption, and browser parity gates remain.  
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
| 2.3 | Load each page with `?disyl_nocache=1` | ✅ | Done during browser validation — all affected templates freshly compiled |

## Phase 3: Entity View Integration (P1)

| Step | Action | Status | Notes |
|---|---|---|---|
| 3.1 | Expand view configs with all modes | ✅ | `guidance_case` table view expanded to 8 fields + 3 actions |
| 3.2 | Expand `entity.list.guidance_case` capability handler | ✅ | Includes all fields, college enrichment, sorting, pagination |
| 3.3 | Create entity-view-powered template | ✅ | `partials/cases-table-entity.disyl` uses `{ikb_entity_list source="guidance.case" view="table"}` |
| 3.4 | Wire handler for entity mode | ✅ | `?entity=1` switches to entity-powered template in `20-cases.php` |
| 3.5 | Add entity view tests | ✅ | `tests/guidance_entity_view_test.php` — 44 assertions on contracts, source parsing, component rendering |

## Phase 4: State Manager + Bridge Demo (P2)

| Step | Action | Status | Notes |
|---|---|---|---|
| 4.1 | Implement `{state}` in one template | ⬜ | |
| 4.2 | Wire compiled manifest v11 format | ⬜ | |
| 4.3 | React bridge rendering POC — `ReactBridge.php` + lazy CDN + CasesTable | ✅ | Proves bridge extension seam without parser changes; **not yet Island hydration** |

## Phase 5: Testing & Validation

| Step | Action | Status | Notes |
|---|---|---|---|
| 5.1 | Run `disyl_v11_verify_test.php` | ✅ | 64 passed, 0 failed |
| 5.2 | Check app.log + error.log | ✅ | Both empty (0 lines) |
| 5.3 | Load all guidance pages in browser | ✅ | Entity view confirmed working (styled table) |
| 5.4 | Verify no JS errors, no 404s | ⬜ | Need browser testing |
| 5.5 | Guidance entity view integration tests | ✅ | `tests/guidance_entity_view_test.php` — 44 assertions |
| 5.6 | React bridge renders via `?react=1` | ✅ | Lazy CDN, styled table with React.createElement |

## Phase 6: Remaining Gates (from architectural review)

| Step | Action | Status | Notes |
|---|---|---|---|
| 6.1 | Entity-view functional parity vs legacy table | ⬜ | Fields, sorting, pagination, actions, empty states, permission behavior, mobile |
| 6.2 | Entity-view security parity | ⬜ | Authorization, CSRF, tenant scoping, escaped output, invalid IDs |
| 6.3 | Entity-view performance parity | ⬜ | No N+1 queries, no per-row capability dispatch, comparable render time |
| 6.4 | State POC — case-list filters with neutral bindings | ⬜ | `{state name="caseFilters"}` — Alpine + React bridge outputs, no raw `x-model` |
| 6.5 | Operational manifest consumer | ⬜ | At least one runtime/tooling use: asset loading, variable validation, or bridge detection |
| 6.6 | Production asset strategy | ⬜ | Locally hosted pinned React, integrity checks, CSP-compatible, no public CDN dependency |
| 6.7 | State mutation boundaries | ⬜ | UI-only state vs business mutations through capability calls |
| 6.8 | Promote entity view to default | ⬜ | After parity verified: default = entity, legacy via debug flag, then remove |
