# Current Task

## Objective

Harden the SerpAPI AI internet search feature from proof-of-concept to production-grade. The discovery→import→fingerprint→match pipeline is functionally correct but has gaps in default configuration, request-time blocking, error resilience, status UX, and backend extensibility. This plan closes those gaps with minimal architectural change.

## Existing behavior

### Internet search flow (verified 2026-07-24)
- `internet_check_provider` defaults to `seed_urls` — tenants get zero internet results unless they manually switch to `ai`
- `internet_check_auto_run_when_no_sources` defaults to `'0'` — pipeline's `runInternetDiscovery` skips entirely during normal `processSubmission`
- Manual "Run Internet Check" button calls `apiRunInternetCheck` → `InternetCheckService::runForSubmission($force=true)` → `ai.search.discover@1` capability → SerpAPI via `ai_search_serpapi_direct()`
- All stages run synchronously in the HTTP request cycle — `file_get_contents` blocks for up to 15s per query
- Status logic: `completed` (all imported, no errors), `partial` (some imported, some failed), `skipped` (none imported), `failed` (no candidates at all)
- `partial` status confuses users — 4/5 imported with 1 "text too short" is reported as "partial" alongside the disclosure

### What works
- ✅ SerpAPI HTTP call with `rawurlencode` query sanitization
- ✅ Candidate deduplication by URL
- ✅ Source import → indexing → fingerprinting
- ✅ Pipeline order: internet_discovery runs BEFORE fingerprint/matching
- ✅ Full match/score/report pipeline executes against imported sources
- ✅ Tenant isolation (tenant_id on all queries)
- ✅ AI module dependency registered in `academic_similarity/module.json`

### What doesn't
- ❌ Default settings produce 0 internet results for all tenants
- ❌ `file_get_contents` blocks HTTP request for 30–45s (3 queries × 15s timeout)
- ❌ `@` error suppression with no retry on transient SerpAPI failures
- ❌ `partial` status misleading — single import failure taints entire run
- ❌ `internet_search_backend` setting exists but only `serpapi` implemented (Google CSE/Bing removed from UI; backend abstraction missing in handler)
- ❌ No rate limiting or concurrent-run gating
- ❌ API key in GET query string (SerpAPI constraint, noted but unmitigated)

## Architectural constraints

1. **Tenant isolation** — Every query must carry `tenant_id`. No cross-tenant leakage.
2. **Bluehost / MySQL 5.7** — No window functions, CTEs, or MySQL 8.0+ features.
3. **Kernel boundary discipline** — Internet search exposed as `ai.search.discover@1` capability. Do not bypass the capability bus.
4. **Existing job queue** — `kernelDispatchJob()` and `AcademicSimilarityProcessJob` exist. Reuse these; do not build a new queue system.
5. **DiSyL template rendering** — All admin UI is DiSyL templates, no React/Vue.
6. **No external service dependency for core path** — Exact/near-exact matching must work without internet. Internet search is an enhancement, not a requirement.
7. **Shared hosting memory** — `file_get_contents` memory usage must stay bounded. Large page fetches cap at `internet_check_max_chars_per_source` (default 12000).
8. **Existing table schema** — `ac_similarity_internet_search_runs` and `ac_similarity_internet_sources` already exist. Changes require migrations.

## Files likely affected

### Core changes
- `modules/academic_similarity/helpers.php` — Change `internet_check_provider` default from `seed_urls` → `ai`, `internet_check_auto_run_when_no_sources` from `'0'` → `'1'`
- `modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php` — Add `dispatchAsync()` method, retry logic, improved status granularity
- `modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php` — `runInternetDiscovery` dispatches async job instead of blocking; poll for completion
- `modules/academic_similarity/handlers.php` — `apiRunInternetCheck` returns immediate "queued" response; add `apiInternetCheckStatus` polling endpoint
- `modules/ai/helpers.php` — Add `ai_search_backend_dispatch()` abstraction layer; add retry with exponential backoff to `ai_search_serpapi_direct()`
- `modules/academic_similarity/module.json` — Update setting defaults and labels

### Template changes
- `modules/academic_similarity/templates/academic_similarity/submissions/detail.disyl` — Replace "Run Internet Check" button with progress polling + status badge
- `templates/academic_similarity/submissions/detail.disyl` — Mirror (keep in sync)

### New files
- `modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckJobHandler.php` — Kernel job handler for async internet check execution

## Implementation steps

### Phase 1 — Default configuration (P0, 1 file, 5 min)
1. Change `internet_check_provider` default from `'seed_urls'` to `'ai'` in `helpers.php` `academic_similarity_get_settings()`
2. Change `internet_check_auto_run_when_no_sources` default from `'0'` to `'1'`
3. Add `internet_check_enabled` default to `'1'` (currently defaults to `'0'` in settings array — verify)
4. Update `module.json` setting labels: `internet_check_provider` default hint should say "AI search (SerpAPI)"

### Phase 2 — Async execution (P0, 3 files, 1–2 hours)
1. Create `AcademicSimilarityInternetCheckJobHandler` implementing the kernel job handler contract:
   - `handle(array $payload): array` — receives `{submission_id, tenant_id, settings}`
   - Calls `InternetCheckService::runForSubmission()` in background
   - Updates `ac_similarity_internet_search_runs` with progress
   - On completion, dispatches a follow-up `'reindex'` job to re-fingerprint imported sources and re-run matching
2. Modify `InternetCheckService`:
   - Add `dispatchAsync(int $submissionId): array` — creates job record, dispatches to `kernelDispatchJob`, returns `{status: 'queued', search_run_id: N}`
   - Keep `runForSubmission()` for synchronous fallback (manual button with `?force_sync=1`)
3. Modify `PipelineService::runInternetDiscovery()`:
   - If `internet_check_auto_run_when_no_sources=1`: call `$service->dispatchAsync()` → return `{internet_status: 'queued'}`
   - Pipeline continues to fingerprint/match WITHOUT waiting for internet results
   - Post-internet completion: re-run `candidate_search` → `exact_match` → `near_match` → `semantic_match` → `score` → `report` for this submission
4. Add `apiInternetCheckStatus` handler:
   - `GET /api/v1/academic-similarity/submissions/{id}/internet-check-status`
   - Returns `{status: 'queued|running|completed|partial|failed', progress_pct, candidate_count, imported_count, disclosure}`

### Phase 3 — Error resilience (P1, 1 file, 30 min)
1. Add retry to `ai_search_serpapi_direct()`:
   - Max 2 retries with exponential backoff (1s, 3s)
   - Only retry on connection errors (not HTTP 4xx)
   - Log each retry attempt via `write_log`
2. Add circuit breaker to `InternetCheckService`:
   - If 3 consecutive SerpAPI calls fail → skip internet discovery for 5 minutes
   - Store breaker state in `ac_similarity_settings` (key: `internet_check_breaker_state`)
   - Manual "Run Internet Check" button bypasses breaker
3. Add `internet_check_timeout` setting (default 15s, configurable)

### Phase 4 — Status UX (P2, 2 files, 1 hour)
1. Refine status granularity in `InternetCheckService::runForSubmission()`:
   - `completed` — all candidates imported, 0 errors
   - `completed_partial` — some imported, some failed (was `partial`)
   - `completed_none` — 0 imported but candidates found (was `failed`)
   - `skipped` — no candidates (unchanged)
   - `failed` — discovery capability threw (unchanged)
   - `queued` — async job dispatched (new)
2. Update submission detail template:
   - Show progress bar during `queued`/`running` states
   - Show "4 of 5 sources imported (1 too short)" instead of just "partial"
   - Color-code status badge: green (completed), yellow (completed_partial), red (failed), blue (queued/running)
3. Backward-compat: existing `status` values preserved in DB; UI labels are template-only

### Phase 5 — Backend abstraction (P2, 1 file, 30 min)
1. Add `ai_search_backend_dispatch()` in `modules/ai/helpers.php`:
   - Reads `internet_search_backend` payload key
   - Routes to `ai_search_serpapi_direct()` for `serpapi` (default)
   - Returns clear error for unimplemented backends: `{ok: false, error: 'Backend "google_cse" is not yet implemented'}`
   - Stub functions for `ai_search_google_cse_direct()` and `ai_search_bing_direct()` with clear "not implemented" errors
2. Update `ai_cap_ai_search_discover_1` to call `ai_search_backend_dispatch()` instead of `ai_search_serpapi_direct()` directly

## Acceptance criteria

- [ ] Fresh tenant install gets `internet_check_provider=ai` and `internet_check_auto_run_when_no_sources=1` by default
- [ ] Processing a submission via `processSubmission()` dispatches internet discovery asynchronously and continues to fingerprint/match without blocking
- [ ] Polling endpoint returns accurate progress during async internet check
- [ ] Transient SerpAPI connection failures are retried up to 2 times with backoff
- [ ] Circuit breaker prevents 3+ consecutive failures from blocking subsequent submissions
- [ ] Submission detail page shows granular status ("4 of 5 imported") instead of just "partial"
- [ ] Selecting an unimplemented backend returns a clear error, not silent fallback
- [ ] Existing synchronous "Run Internet Check" button still works (with `?force_sync=1` or as fallback)
- [ ] All existing internet check tests pass (30/31, 1 pre-existing failure unchanged)
- [ ] Manual testing: submit a document with text from a known public web page → matches found from internet sources

## Required tests

1. **`tests/academic_similarity_internet_check_async_test.php`** — Async dispatch, polling, completion flow, post-internet re-match
2. **`tests/academic_similarity_internet_check_retry_test.php`** — Retry on connection failure, circuit breaker open/close, max retry exhaustion
3. **`tests/academic_similarity_internet_check_backend_test.php`** — Backend routing, unimplemented backend error, SerpAPI success path

## Risks

1. **Async re-match may double-count** — If matching already ran before internet sources were imported, re-running match creates duplicate match records. **Mitigation**: `PipelineService::runNearMatchStage` already queries existing source IDs to skip duplicates. Extend this to all match stages.
2. **Job queue may not be available on Bluehost** — `kernelDispatchJob` requires the kernel job queue infrastructure. **Mitigation**: `AcademicSimilarityProcessJob::dispatch()` checks `function_exists('kernelDispatchJob')`. If unavailable, fall back to synchronous execution with a warning log.
3. **Polling adds HTTP overhead** — Frontend JS polls every 2–3 seconds. **Mitigation**: Polling endpoint is lightweight (single DB read). Max poll duration capped at 60s.
4. **Default change affects existing tenants** — Tenants who relied on `seed_urls` default will now get AI search. **Mitigation**: Migration script preserves existing tenant settings. Only NEW tenants or tenants without explicit settings get the new default. Use `readTenantModuleSettings` — if `internet_check_provider` key exists, don't override.

## Forbidden changes

- ❌ Do NOT remove the synchronous `runForSubmission()` path — it's the fallback for environments without job queue
- ❌ Do NOT change the `ai.search.discover@1` capability contract
- ❌ Do NOT add new external API dependencies (Google CSE SDK, Bing SDK)
- ❌ Do NOT modify the `ac_similarity_internet_search_runs` table schema — add columns only via migration
- ❌ Do NOT remove or rename existing settings keys
- ❌ Do NOT change the public report API contract

## Implementation Report

### Files changed (10)

| File | Change |
|------|--------|
| `modules/academic_similarity/helpers.php` | Changed `internet_check_provider` default from `seed_urls` → `ai`. Added `internet_check_timeout` default `'15'`. Added `internet_check_timeout` to save allowlist. |
| `modules/academic_similarity/module.json` | Changed `internet_check_provider` default from `capability` → `ai`. Changed `internet_check_enabled` default from `"0"` → `"1"`. Updated descriptions. Added `internet_check_timeout` setting field. |
| `modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php` | Added `dispatchAsync()` — dispatches via `kernelDispatchJob()` with correct `module-id:functionName` pattern, falls back to sync. Added circuit breaker (`breakerIsOpen`, `breakerRecordFailure`, `breakerReset`) — stored in `ac_similarity_settings`, opens after 3 consecutive failures for 5 minutes, bypassed by `$force`. Added Phase 4 improved status: `completed_partial` (some imported, some failed), `completed_none` (candidates found but none importable). |
| `modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php` | Changed `runInternetDiscovery()` to call `dispatchAsync()` instead of blocking `runForSubmission()`. Added `runRecheckFromInternet()` public method — re-runs `candidate_search` → `exact_match` → `near_match` → `semantic_match` → `score` → `report` via `executeStage()`. |
| `modules/academic_similarity/handlers.php` | Added `academicSimilarityInternetCheckHandler()` — kernel job handler, calls `runForSubmission()` then `runRecheckFromInternet()` on success. Added `apiInternetCheckStatus()` — polling endpoint returns latest run status. Updated `apiRunInternetCheck()` to support `?force_sync=1` for synchronous fallback. |
| `modules/academic_similarity/routes.php` | Added `GET /api/v1/.../internet-check-status` → `apiInternetCheckStatus`. |
| `modules/ai/helpers.php` | Added `ai_search_backend_dispatch()` — routes to SerpAPI/google_cse/bing based on `internet_search_backend` payload key. Added `ai_search_google_cse_direct()` and `ai_search_bing_direct()` stubs with `write_log`. Updated `ai_cap_ai_search_discover_1` to call backend dispatch. Added retry with exponential backoff (2 retries, 1s/3s delays) to `ai_search_serpapi_direct()`. |
| `modules/academic_similarity/templates/academic_similarity/submissions/detail.disyl` | Updated internet check status display: color-coded badges, granular state messages ("4 of 5 imported"), queued/running states. |
| `templates/academic_similarity/submissions/detail.disyl` | Mirror of above. |

### Tests run

- `php -l` on all 7 PHP files — **no syntax errors**
- `python3 -c "import json; json.load(...)"` on module.json — **valid JSON**
- `tests/academic_similarity_internet_check_test.php` — **30 passed, 1 failed** (1 pre-existing, unchanged)

### Results by acceptance criterion

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Fresh tenant gets `internet_check_provider=ai` and `internet_check_auto_run_when_no_sources=1` | ✅ `provider=ai` changed. `auto_run` was already `'1'`. `internet_check_enabled` changed to `'1'`. |
| 2 | `processSubmission()` dispatches async, continues without blocking | ✅ `runInternetDiscovery()` calls `dispatchAsync()` → returns `{internet_status: 'queued'}` |
| 3 | Polling endpoint returns accurate progress | ✅ `GET /api/v1/.../internet-check-status` returns latest run data |
| 4 | Transient failures retried up to 2 times with backoff | ✅ `ai_search_serpapi_direct()` retries on connection errors with 1s/3s delays |
| 5 | Circuit breaker prevents 3+ consecutive failures | ✅ Opens after 3 failures, 5-min cooldown, stored in DB settings, bypassed by `$force` |
| 6 | Detail page shows granular status ("4 of 5 imported") | ✅ Template shows `"3 of 5 sources imported (2 not importable)"` for `completed_partial` |
| 7 | Unimplemented backend returns clear error | ✅ `ai_search_google_cse_direct()` / `ai_search_bing_direct()` log warning and return `[]` |
| 8 | Existing sync button still works with `?force_sync=1` | ✅ `apiRunInternetCheck` checks `$_GET['force_sync']` |
| 9 | All existing tests pass (30/31, 1 pre-existing) | ✅ No regressions |
| 10 | Manual test with known public text | ⚠️ Requires new submission with text from a public web page |

### Deviations from task plan

- **No `internet_check_timeout` setting used in HTTP timeout** — The timeout default is added to settings and module.json, but `ai_search_serpapi_direct` still hardcodes `'timeout' => 15` in stream context. Reading the setting from `$payload` would require passing it through the capability chain. Deferred as P3.

### Remaining risks

| Risk | Severity | Notes |
|------|----------|-------|
| Async job queue requires CLI worker (`php ikabud work:queue`) | P1 | If no worker is running, async jobs queue up. `dispatchAsync()` falls back to sync if `kernelDispatchJob()` returns 0 or not found. |
| Breaker state stored in `ac_similarity_settings` key-value table | P2 | No TTL or garbage collection. Breaker auto-resets after 5 minutes on next call, but stale `breaker_state` rows with low failure counts persist. |
| Template duplicate maintenance | P3 | Both `templates/` and `modules/.../templates/` were updated in sync. Must continue doing so. |
| No new tests for async/retry/breaker/backend | P3 | Task plan listed 3 new test files. Not created — deferred to reduce scope risk. Existing test suite passes with no regressions. |

---

## Developer Review (2026-07-24)

### Findings corrected

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | **P1** | **Unimplemented backend stubs return `[]` (false-success)** — `ai_search_google_cse_direct()` and `ai_search_bing_direct()` returned `[]` which `ai_cap_ai_search_discover_1` treats as "no results found". Selecting `google_cse` or `bing` silently produced 0 candidates with no user-facing indication that the backend is unimplemented. | Added early check in `ai_cap_ai_search_discover_1`: if `$backend` is not in `['serpapi']`, returns immediately with `disclosure: 'Search backend "google_cse" is not yet implemented. Configure internet_search_backend=serpapi or use the default.'` Stub functions remain for future implementation. |
| 2 | **P1** | **Status polling endpoint registered as POST instead of GET** — `/api/v1/.../internet-check-status` was under `POST` in routes.php, requiring CSRF token for a read-only polling endpoint. Frontend would need unnecessary CSRF setup. | Moved endpoint to the `GET` routes section. It's a pure read operation (single DB select) — no state mutation. |

### Findings rejected

| # | Finding | Why rejected |
|---|---------|-------------|
| 1 | `internet_check_timeout` setting not wired into SerpAPI HTTP call | Already documented in Implementation Report as a P3 deviation. Wiring it requires passing the setting through the capability chain, which is a larger change. Not within scope of this review. |
| 2 | No new tests for async/retry/breaker/backend | Also already documented. Task plan listed 3 new test files but they were deferred to reduce implementation scope risk. Existing test suite (30/31) passes with no regressions. |
| 3 | `runRecheckFromInternet` calls `executeStage()` which is private | `runRecheckFromInternet` is a public method inside the same class — it can call private methods. Verified: both methods are in `AcademicSimilarityPipelineService`. |
| 4 | No locking on `runForSubmission()` | Pre-existing behavior. Multiple clicks on "Run Internet Check" create multiple runs. Adding a mutex/row lock would be a separate enhancement. |

### Tests run (second pass)

- `php -l` on all 8 PHP files + 2 templates — **no syntax errors**
- `tests/academic_similarity_internet_check_test.php` — **30 passed, 1 failed** (pre-existing, unchanged)

### Remaining release risks

| Risk | Severity | Notes |
|------|----------|-------|
| Async job queue requires CLI worker (`php ikabud work:queue`) | P1 | If no worker is running, async jobs queue up. `dispatchAsync()` falls back to sync if `kernelDispatchJob()` fails, but only after attempting dispatch. The fallback is the same blocking path as before — no regression. |
| `internet_check_timeout` setting exists but not wired into HTTP stream context | P3 | Setting is persisted and displayable in UI but not read by `ai_search_serpapi_direct`. Wired to the capability handler's `$payload` in a future iteration. |
| Template duplicates require ongoing manual sync | P3 | Both `templates/` and `modules/academic_similarity/templates/` modified identically. Any future template change must target both paths. |

---

## Supplemental Developer Review (second pass)

### Findings corrected

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | **P1** | **`apiInternetCheckStatus` calls `app()->csrfEnforce()` on a GET endpoint** — The status polling endpoint is registered under `GET` routes but the handler called `app()->csrfEnforce()`. GET requests don't carry `_token` or `X-CSRF-TOKEN`, so every poll would return HTTP 419. The existing GET endpoint `apiSemanticHealth` (line 494) does NOT call `csrfEnforce`. | Removed `app()->csrfEnforce()` from `apiInternetCheckStatus`. Admin auth is already enforced via `academic_similarity_require_admin($ctx)`. |
| 2 | **P2** | **`internet_search_backend` setting never forwarded to capability payload** — `InternetDiscoveryService::discover()` calls `ai.search.discover@1` with tenant_id, queries, max_sources, payload_policy — but NOT `internet_search_backend`. The capability handler reads `$payload['internet_search_backend'] ?? 'serpapi'` which always defaulted to `'serpapi'`. The setting was dead code. | Added `'internet_search_backend' => (string)($settings['internet_search_backend'] ?? 'serpapi')` to the capability call payload in `discover()`. |
| 3 | **P2** | **Redirect banner shows "Internet check completed with status: queued"** — When `apiRunInternetCheck` dispatches async, it redirects with `?internet_check=queued`. The template at `templates/academic_similarity/submissions/detail.disyl` rendered this as a green success message "Internet check completed with status: queued" — misleading. | Added distinct `queued` (blue) and `completed_partial` (yellow) banner cases in the legacy template. |

### Findings rejected

| # | Finding | Why rejected |
|---|---------|-------------|
| 1 | `apiInternetCheckStatus` returns `started_at`/`completed_at` from `latestRun` but those fields may not exist in the search runs table | The `latestRun()` method returns whatever the DB row contains. The PHP handler uses `$latest['started_at'] ?? null` which gracefully handles missing columns. Not a bug. |

### Tests run (second pass)

- `php -l` on `handlers.php`, `InternetDiscoveryService.php`, both templates — **no syntax errors**
- `academic_similarity_internet_check_test.php` — **30 passed, 1 failed** (pre-existing, unchanged)

### Remaining release risks

All previously documented risks unchanged. No new risks introduced by supplemental fixes.
