# AISS Architecture Audit — 2026-07-24

## Scope

Academic Integrity and Similarity System — two modules:
- `academic_similarity` (PHP, 17 services, 10 repositories, ~5,500 lines of service code)
- `academic-similarity-semantic-service` (Python 3.9+, 584 lines, HTTP on port 9003)

## Methodology

1. **Surface scan**: syntax check (all PHP files), Python compile, MySQL 5.7 migration audit, execute 30 PHP tests + 1 Python test
2. **Deep review**: read and analyze all 6 high-risk service files identified in the architecture assessment (PipelineService 1228L, MatchingService 951L, HighlightService 626L, InternetCheckService 287L, ReportService, ScoringService)
3. **Security review**: tenant isolation in all 10 repositories, public endpoint IDOR analysis, file validator, text extractor, CSRF coverage, settings encryption
4. **Test gap analysis**: map 30 tests against threat model, identify coverage gaps
5. **Semantic service review**: app.py error handling, token validation, backend correctness

## Critical Severity Findings

### C1. Smith-Waterman Traceback — Wrong Matrix Cell Comparison

**File**: `AcademicSimilarityMatchingService.php` lines 752–795
**Component**: MatchingService  
**Type**: Code correctness bug

**Description**: The Smith-Waterman local alignment traceback logic compares `$currentScore` against `$fromDiag` (`H[i-1][j-1]`), `$fromLeft` (`E[i][j-1]`), and `$fromUp` (`F[i-1][j]`). However, `$H[i][j]` stores the **maximum** of `(0, H[i-1][j-1]+diag, E[i][j], F[i][j])`. Correct traceback must compare `$H[i][j]` against `$H[i-1][j-1] + diagScore`, `$E[$i][$j]`, and `$F[$i][$j]` directly. The current comparison reads adjacent matrix cells instead of the current cell, causing the traceback to frequently take the wrong branch and produce **incorrect sub_start/sub_end/src_start/src_end offsets**, as well as incorrect gap/insertion counts.

**Impact**: Highlighted passages in reports will point to the wrong text. Offset positions in match evidence are unreliable. This undermines the core value proposition of the product.

**Remediation**: Rewrite traceback to compare `$H[i][j]` against the three candidate values that were considered when computing it: `H[i-1][j-1] + diag`, `E[i][j]`, `F[i][j]`.

---

### C2. Python `_compare_tfidf_builtin()` — Undefined Variable `threshold` → NameError

**File**: `app.py` line 166 (`score >= threshold`)
**Component**: Semantic service (Python)  
**Type**: Code correctness bug

**Description**: `_compare_tfidf_builtin()` is a standalone function that references `threshold` but does not accept it as a parameter and does not define it locally. When scikit-learn is unavailable (which is the common case on a minimal Python 3.9 install), `compare_tfidf()` falls back to `_compare_tfidf_builtin()` and this raises a **NameError at runtime**. This code path is guaranteed to crash on any system without scikit-learn installed.

**Impact**: The `tfidf` backend is unusable without scikit-learn. The fallback path always crashes rather than degrading gracefully.

**Remediation**: Add `threshold` as a parameter to `_compare_tfidf_builtin()` and pass it from the caller.

---

### C3. Python `compare_sentence_transformers()` — Missing `threshold` Parameter → TypeError

**File**: `app.py` line 262
**Component**: Semantic service (Python)  
**Type**: Code correctness bug

**Description**: The function signature is `(segments_a, segments_b, model_name=None)` — 3 positional parameters. But the call site (`handle_semantic_compare`) passes 4 arguments: `backend(submission_segments, source_segments, model_name, threshold)`. Calling a 3-parameter function with 4 arguments raises a **TypeError at runtime**.

**Impact**: The `sentence_transformers` backend is completely non-functional. Any request using this backend crashes immediately.

**Remediation**: Add `threshold` parameter to `compare_sentence_transformers()` signature.

---

## High Severity Findings

### H1. MatchingService — Cross-Source Overlap Resolution Causes Under-Reporting

**File**: `AcademicSimilarityMatchingService.php` lines 207–240
**Component**: MatchingService  
**Type**: Scoring correctness bug

**Description**: `resolveOverlaps()` maintains a single `$lastEnd` cursor across all matches from **all sources**. If source A matches words 0–50 and source B matches words 25–75 (an independent source), source B's match is trimmed to 50–75 or fully skipped. These are independent sources and should not be overlap-resolved against each other. Overlap resolution should be per-source, then merged.

**Impact**: Matches from secondary sources are systematically under-reported. The similarity score is lower than it should be when multiple sources match overlapping content.

**Remediation**: Resolve overlaps per-source, then merge the per-source results (deduplicating word positions across sources but not trimming cross-source matches against each other).

---

### H2. MatchingService — Ambiguous Hash Positions Cause Missed Alignments

**File**: `AcademicSimilarityMatchingService.php` lines 511–513
**Component**: MatchingService  
**Type**: Matching completeness bug

**Description**: When building the source fingerprint hash lookup, `$srcByHash[$hash][0]` always takes the **first** fingerprint entry for a given shingle hash. If the source repeats a phrase (same shingle hash at positions 100 and 500), only position 100 is mapped. This misses the second occurrence entirely, preventing Smith-Waterman from finding a match at position 500.

**Impact**: Repeated text in a source document may only match once. For documents with recurring boilerplate or citations, matches are under-reported.

**Remediation**: Iterate over all matching fingerprints at each hash, not just index 0.

---

### H3. PipelineService — Large File, No OOM Guard

**File**: `AcademicSimilarityPipelineService.php` line 140 (extract method)
**Component**: PipelineService  
**Type**: Resource exhaustion

**Description**: The extract stage reads the full extracted text into memory and stores it in a DB TEXT column. For a near-maximum 20 MB DOCX which could contain large embedded images, the internal XML can be significantly larger. There is no streaming or chunking. PHP's memory limit could be exhausted.

**Impact**: Out-of-memory crashes on large or complex documents. No graceful degradation.

**Remediation**: Add a pre-flight memory check (estimate expected text size from file metadata). Set a max extraction size limit (e.g., 500k characters).

---

### H4. Semantic Service — No Timeout for Non-Groq Backends

**File**: `app.py` line 370
**Component**: Semantic service (Python)  
**Type**: Availability

**Description**: Only the Groq backend has a configurable timeout. The `token_overlap`, `tfidf`, and `sentence_transformers` backends can block the HTTP request handler indefinitely. For `sentence_transformers`, the first call downloads and loads a model, which can take minutes. This blocks the entire service for all concurrent requests.

**Impact**: A slow backend call blocks the entire single-threaded HTTP server. All other requests queue behind it.

**Remediation**: Add `signal.alarm()` or `concurrent.futures.timeout` wrappers for all backends. Default timeout: 30 seconds for token_overlap/tfidf, 120 seconds for sentence_transformers.

---

### H5. Semantic Service — No Pair-Count Limit (250K Comparisons)

**File**: `app.py` lines 366–376
**Component**: Semantic service (Python)  
**Type**: Resource exhaustion / Availability

**Description**: Segment limits are capped at 500 each via `SEMANTIC_MAX_SEGMENTS`, but the **product** (250,000 pairs) has no limit. The `token_overlap` and `tfidf` backends iterate all N×M pairs. At 500×500 = 250k iterations, this produces a ~2MB+ response and seconds of CPU time. The Groq backend limits to 20 pairs, but other backends do not.

**Impact**: Slow responses for large submissions, potential DoS vector via crafted large payloads.

**Remediation**: Add a `max_comparisons` cap (e.g., 10,000 pairs). For larger segment sets, sample or partition comparisons.

---

## Medium Severity Findings

### M1. Pre-Existing Test Failures (3 total)

**Files**: 
- `tests/academic_similarity_internet_check_test.php` — 1 failure: "settings defaults disable internet check"
- `tests/academic_similarity_semantic_capability_contract_test.php` — 2 failures: "semantic_match_enabled defaults to 0 in source", "handler map has 7 capabilities — got: 8"

**Description**: Three assertions in the existing test suite fail. Root causes:
1. `internet_check_enabled` default is `'1'` in settings but the test expects `'0'` (disabled by default)
2. `semantic_match_enabled` default is `'1'` in settings but the test expects `'0'` (disabled by default)
3. An 8th capability handler was added (`academic_similarity.internet.discover@1` likely) but the test still expects 7

These appear to be **settings defaults changed** and **capability added** without updating tests. They may reflect intentional design decisions (enable features by default for new tenants) but the tests were not updated.

**Impact**: CI fails on these tests. If these are intentional design choices (features enabled by default), the tests need updating. If not, the settings defaults need reverting.

**Remediation**: Update test expectations to match current defaults, or revert defaults to match test expectations.

---

### M2. Public Endpoint — `submitter_user_id` Leaked in Response

**File**: `handlers.php` line 1076
**Component**: Public API  
**Type**: Information disclosure (minor)

**Description**: `apiPublicSubmit` includes `$result['submitter_user_id'] = $submitterUserId;` in the JSON response. This exposes the internal DB user ID to the client. While the user already knows their own identity, this enables user enumeration (attackers could correlate user IDs across endpoints).

**Impact**: Low — the user ID is their own. But enumerable user IDs are a reconnaissance vector.

**Remediation**: Remove `submitter_user_id` from the public response body. If the client needs identity confirmation, return a boolean `authenticated: true` instead.

---

### M3. Anonymous Submissions Are Orphaned

**File**: `handlers.php` lines 1083–1090
**Component**: Public API  
**Type**: Usability / Data management

**Description**: `apiPublicResults` returns 401 if `$submitterUserId <= 0`. Combined with the `public_results_allow_anonymous` setting which allows unauthenticated submission creation (with `submitter_user_id = 0`), anonymous users can submit documents but can **never retrieve results**. No access token or session link is provided.

**Impact**: Anonymous submissions consume storage and processing resources but the submitter can never access the result. The setting `public_results_allow_anonymous` is dead code — it enables the form but the results endpoint rejects the user.

**Remediation**: Either (a) generate a submission-access token for anonymous submitters and return it in the submit response, or (b) disable the form for unauthenticated users and remove the dead setting.

---

### M4. `apiSaveSettings` Uses Custom CSRF Instead of `app()->csrfEnforce()`

**File**: `handlers.php` lines 1705–1727
**Component**: Admin API  
**Type**: Inconsistent security pattern

**Description**: All other POST handlers call `app()->csrfEnforce()`. The settings save handler implements its own CSRF check using `CsrfManager::token()` directly with a `function_exists` guard. The custom check is functionally equivalent but the inconsistency means middleware-level CSRF enforcement is bypassed for this one endpoint.

**Impact**: Low — the custom check works correctly. The inconsistency is a maintenance risk (future changes to CSRF enforcement won't apply here).

**Remediation**: Replace the custom CSRF check with `app()->csrfEnforce()`.

---

### M5. FileValidator — No Null Byte or Double Extension Check

**File**: `AcademicSimilarityFileValidator.php` lines 94–110
**Component**: File validation  
**Type**: Defense in depth

**Description**: `validateExtension()` uses `pathinfo($filename, PATHINFO_EXTENSION)` which returns only the last extension. A filename like `evil.php%00.docx` would pass extension validation (last ext = `docx`). While PHP 7.2+ handles null bytes in file paths, the filename is stored in DB metadata unfiltered.

**Impact**: Low — PHP 7.2+ prevents actual null byte injection. The risk is cosmetic (corrupted filename in DB metadata).

**Remediation**: Add explicit checks: reject filenames containing null bytes (`\0`), path traversal sequences (`../`), or non-printing characters.

---

## Low Severity Findings

### L1. `buildLegend()` vs `buildSpans()` Legend Inconsistency

**File**: `AcademicSimilarityHighlightService.php` — `buildLegend()` vs `buildSpans()`
**Component**: HighlightService  
**Type**: Rendering inconsistency

**Description**: `buildSpans()` builds a legend that only includes types with `count > 0` (plus `'exact'` always). `buildLegend()` always includes all 6 types even when count is 0. Callers get different legend structures.

**Remediation**: Align behavior — both should either include all types or only non-zero types.

---

### L2. `assembleMatchedPassages()` Missing Source Metadata

**File**: `AcademicSimilarityHighlightService.php` lines 320–370
**Component**: HighlightService  
**Type**: Rendering gap

**Description**: `assembleMatchedPassages()` builds passage arrays with `'source_title' => ''` and `'source_author' => ''` but never fills them. By contrast, `buildSpans()` correctly populates these fields from the source cache.

**Remediation**: Pass/fetch source metadata in `assembleMatchedPassages()`.

---

### L3. InternetCheckService — No Dedup Guard in `dispatchAsync()`

**File**: `AcademicSimilarityInternetCheckService.php` lines 37–49
**Component**: InternetCheckService  
**Type**: Efficiency

**Description**: `dispatchAsync()` does not check `hasPendingRun()` before dispatching. Multiple calls queue multiple redundant jobs.

**Remediation**: Check `hasPendingRun()` before dispatching.

---

### L4. InternetCheckService — No Exponential Backoff

**File**: `AcademicSimilarityInternetCheckService.php` line 44
**Component**: InternetCheckService  
**Type**: Reliability

**Description**: `kernelDispatchJob()` is called with `$delay=0` and `$retries=3`. Immediate retries for transient failures will all fail.

**Remediation**: Set `$delay` to at least 30 seconds or verify kernel job system implements backoff independently.

---

### L5. InternetCheckService — TOCTOU Race Condition in Concurrency Guard

**File**: `AcademicSimilarityInternetCheckService.php` line 98
**Component**: InternetCheckService  
**Type**: Race condition

**Description**: `hasPendingRun()` and `create()` are separate DB operations. Two concurrent requests that both pass the guard before either creates a run will both proceed.

**Remediation**: Use `SELECT ... FOR UPDATE` or a unique constraint on `(submission_id, status)`.

---

### L6. Score Deduplication — Cross-Source Overlap in Unique Coverage

**File**: `AcademicSimilarityScoringService.php` (coverage calculation)
**Component**: ScoringService  
**Type**: Scoring nuance

**Description**: The unique word coverage calculation correctly deduplicates word positions regardless of source. However, it relies on `MatchingService::resolveOverlaps()` which has the cross-source interference bug (H1). After fixing H1, `resolveOverlaps()` per-source + deduplication merge must be verified end-to-end with scoring.

**Remediation**: After fixing H1, run the scoring tests against the corrected overlap resolution to verify scores are correct.

---

### L7. Semantic Service — `_error_count` Never Reset

**File**: `app.py` line 295
**Component**: Semantic service (Python)  
**Type**: Monitoring

**Description**: `_error_count` only increments, never decrements. A brief spike of errors permanently inflates health check's `recent_errors`.

**Remediation**: Use a time-windowed error counter.

---

### L8. Masked Value Detection Uses `***` Prefix

**File**: `helpers.php` line 245
**Component**: Settings
**Type**: Edge case

**Description**: `str_starts_with($secret, '***')` detects masked values. A legitimate API key starting with `***` would be silently dropped.

**Remediation**: Use a sentinel constant (e.g., `'__MASKED__'`) instead of `***` prefix.

---

## Test Coverage Assessment

### Covered threats (from threat-model.md)

| Threat | Test coverage | Status |
|--------|--------------|--------|
| Tenant isolation | Implicit — all repository methods include tenant_id | ✅ Verified in code review |
| IDOR (submission access) | `academic_similarity_security_test` — TenantPolicy methods | ✅ |
| XSS (extracted text) | `academic_similarity_highlight_service_test` — HTML output checking | ✅ |
| Path traversal | `academic_similarity_security_test` — filename validation | ✅ |
| Upload validation | `academic_similarity_security_test` — extension, MIME, size, content | ✅ |
| CSRF | Implicit — all POST routes call `csrfEnforce()` | ✅ (except settings, see M4) |

### Known gaps

| Gap | Impact | Suggested test |
|-----|--------|---------------|
| Concurrent processing race conditions | Two pipeline runs on same submission could corrupt state | `academic_similarity_concurrent_processing_test.php` |
| Quota exhaustion edge cases | Usage counters incremented outside transactions; may allow bypass | Extend `academic_similarity_quota_test.php` |
| Internet-check partial failure | Async job with partial source retrieval — no test for mixed success/failure | Extend `academic_similarity_internet_check_retry_test.php` |
| Semantic service timeout | Python backend has no timeout; could hang indefinitely | `academic_similarity_semantic_timeout_test.php` |
| Public report access with invalid/expired tokens | No test for expired/invalid access | Extend `academic_similarity_public_result_authorization_test.php` |
| Large-document performance (near 50K words) | Memory/CPU impact of large documents untested | `academic_similarity_large_document_benchmark_test.php` |
| Smith-Waterman offset correctness | No direct test of alignment correctness with known offsets | Extend `academic_similarity_local_alignment_test.php` |
| Settings save with masked API keys | Test that `***` values preserve existing keys correctly | Extend settings tests in `academic_similarity_cms_configuration_test.php` |

### Test execution results (30 PHP files, 1 Python file)

| Category | Pass | Fail | Notes |
|----------|------|------|-------|
| Standard integration tests (25) | 22 exit 0 | 2 exit 1, 1 exit 0 with HTML | See M1 for root cause of failures |
| HTML-rendering tests (5) | 5 exit 0 | 0 | Tests render admin pages but still pass |
| Python unit test | Not run | — | Requires sentence_transformers/torch |

**Pre-existing failures (3 total)**:
1. `academic_similarity_internet_check_test`: "settings defaults disable internet check" — internet_check_enabled is '1' but test expects '0'
2. `academic_similarity_semantic_capability_contract_test`: "semantic_match_enabled defaults to 0 in source" — semantic_match_enabled is '1' but test expects '0'
3. `academic_similarity_semantic_capability_contract_test`: "handler map has 7 capabilities — got: 8" — 8th capability handler added without test update

## MySQL 5.7 Compatibility

**Result**: All 7 migration files pass the pre-deployment SQL audit.

- ✅ No window functions (`OVER()`, `ROW_NUMBER`, `RANK`, etc.)
- ✅ No CTEs (`WITH ... AS`)
- ✅ No `JSON_TABLE`
- ✅ No `EXCEPT`/`INTERSECT`
- ✅ All `CREATE TABLE` statements end with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
- ✅ All `ALTER TABLE` statements (no CREATE TABLE in migrations 005, 009)
- ✅ `SET FOREIGN_KEY_CHECKS = 0/1` around schema changes

**Note**: Migration 006 has a column named `result_rank INT UNSIGNED NOT NULL DEFAULT 0`. This is a column name, not a window function — confirmed no issue.

## Static Analysis

- ✅ All 17 PHP files pass `php -l` (no syntax errors)
- ✅ Python app.py passes `python3 -m py_compile` (no syntax errors)

## Product Maturity Assessment

**Current status**: Advanced alpha / controlled pilot — **confirmed**.

The architecture is correct (tenant isolation, capability routing, service decomposition) and the documentation is thorough (architecture, scoring, limitations, threat model). However, three critical correctness bugs in core algorithms (Smith-Waterman, Python tfidf fallback, Python sentence_transformers parameter) mean the product cannot be treated as institution-ready.

### Required before institutional pilot

1. Fix C1 (Smith-Waterman traceback) — alignment offsets must be correct
2. Fix C2 (Python threshold NameError) — tfidf fallback must not crash
3. Fix C3 (Python sentence_transformers TypeError) — 4th parameter must be accepted
4. Fix H1 (cross-source overlap) — scoring must not under-report secondary sources
5. Resolve M1 (test failures) — either update tests or revert defaults
6. Add timeout guards for all Python backends (H4)
7. Fix anonymous submission orphaning (M3)
8. Replace `result_rank` column name with non-reserved-word alternative for forward compatibility
9. Run corpus calibration with known-plagiarism pairs to validate scoring methodology
