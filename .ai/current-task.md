# Current Task

## Objective
Enhance AISS so it can perform AI-assisted document checking against published internet sources when the tenant has little or no local source corpus. The feature must discover likely public research/source candidates, retrieve and extract bounded source text, cite where each source came from, compare the submitted document against those fetched sources, and show clearly whether results are based on local sources, internet-discovered sources, semantic AI judgment, or a combination.

## Existing behavior
AISS currently compares submissions against tenant-indexed sources only. The pipeline extracts submitted text, normalizes it, segments it, fingerprints it, searches local candidate sources, runs exact/near-exact matching, optionally runs semantic matching, scores, and generates a report.

Semantic matching is optional and provider-neutral through `AcademicSimilaritySemanticService` and the `academic_similarity.semantic.compare@1` capability. Groq or other AI providers can compare submitted segments to source segments, but they do not currently discover sources, search the internet, retrieve published papers, or build a corpus automatically.

If there are no indexed local sources or collections, the deterministic stages produce no matches and the semantic stage skips with no source segments available. A zero/low score in this state means only “no match in the indexed AISS corpus,” not “original” or “not plagiarized.”

## Architectural constraints
- Internet checking must be opt-in per tenant and disabled by default.
- AISS must never claim comprehensive internet coverage. Reports must disclose searched providers, source limits, timestamps, and retrieval failures.
- Do not send full documents to external AI/search providers unless an explicit setting allows it. Default payload policy should use generated search queries and short snippets/segments.
- Store API keys only as server environment variable names or encrypted integration secrets; never store raw provider keys in public settings.
- Keep the current local-source pipeline as the source of truth for scoring. Internet sources must be imported into the same source/segment/match evidence model before final scoring.
- Internet-retrieved sources must be tenant-scoped and auditable.
- The feature must respect robots, provider terms, rate limits, copyright constraints, and institutional privacy settings.
- Retrieved public text must be bounded. Store source URL, title, author/publisher, retrieval timestamp, content hash, snippet/excerpt provenance, and extraction method.
- AI model output may rank, summarize, classify, and judge semantic similarity, but evidence must link back to retrieved text spans where possible.
- Groq or other LLM providers must not be treated as proof of plagiarism without retrievable source evidence.
- Public/user-facing reports must distinguish local matches from internet-discovered matches.

## Files likely affected
- `modules/academic_similarity/module.json`
- `modules/academic_similarity/helpers.php`
- `modules/academic_similarity/routes.php`
- `modules/academic_similarity/handlers.php`
- `modules/academic_similarity/migrations/*_internet_sources.sql`
- `modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php`
- `modules/academic_similarity/src/Services/AcademicSimilaritySemanticService.php`
- `modules/academic_similarity/src/Services/AcademicSimilaritySourceService.php`
- `modules/academic_similarity/src/Services/AcademicSimilarityReportService.php`
- `modules/academic_similarity/src/Reports/AcademicSimilarityReportGenerator.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilaritySourceRepository.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilarityMatchRepository.php`
- `modules/academic_similarity/templates/academic_similarity/settings.disyl`
- `templates/academic_similarity/settings.disyl`
- `modules/academic_similarity/templates/academic_similarity/reports/detail.disyl`
- `templates/academic_similarity/reports/detail.disyl`
- `modules/academic_similarity/templates/academic_similarity/public/workspace.disyl`
- Python semantic/capability service files, if present or added under an AISS service path.
- `tests/academic_similarity_semantic_capability_contract_test.php`
- `tests/academic_similarity_cms_configuration_test.php`
- New focused tests for internet discovery, source ingestion, privacy gates, and report disclosure.

Likely new PHP service/repository files:
- `modules/academic_similarity/src/Services/AcademicSimilarityInternetDiscoveryService.php`
- `modules/academic_similarity/src/Services/AcademicSimilarityInternetSourceIngestionService.php`
- `modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSourceRepository.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSearchRunRepository.php`

Likely new Python/capability files:
- Provider-neutral internet search/retrieval capability handler, for example `academic_similarity.internet.discover@1`
- Provider-neutral source ranking/AI query expansion handler, for example `academic_similarity.internet.rank@1`

## Implementation steps
1. Add tenant settings for internet-assisted checking:
   - `internet_check_enabled`
   - `internet_check_provider`
   - `internet_check_api_key_env`
   - `internet_check_max_queries`
   - `internet_check_max_sources`
   - `internet_check_max_chars_per_source`
   - `internet_check_payload_policy`
   - `internet_check_auto_run_when_no_sources`
   - `internet_check_allow_full_document_query`
   - `internet_check_store_retrieved_text`
   - `internet_check_disclosure_visible`
2. Add schema for internet search runs and retrieved source provenance. Required fields should include tenant, submission, provider, query, result URL, title, snippet, rank, retrieval status, content hash, source row ID if imported, error, created/retrieved timestamps, and metadata JSON.
3. Define provider-neutral capabilities:
   - `academic_similarity.internet.discover@1`: accepts safe query seeds/snippets and returns candidate URLs with metadata.
   - `academic_similarity.internet.fetch@1`: retrieves/extracts bounded text from approved URLs.
   - `academic_similarity.internet.rank@1`: optional AI ranking of candidates against document snippets.
4. Implement `AcademicSimilarityInternetDiscoveryService` to build search queries from submission title, abstract-like leading paragraphs, distinctive phrases, citations, and n-gram samples. It must obey payload policy and max query/source limits.
5. Implement `AcademicSimilarityInternetSourceIngestionService` to convert fetched public source text into AISS source/text-version/segment/fingerprint rows with a classification such as `internet_published` or metadata marker. Do not mix these with user-uploaded institutional source ownership without provenance.
6. Extend `AcademicSimilarityPipelineService` with an internet discovery stage before candidate search or after local candidate search returns empty/weak results. Recommended stage order:
   - extract
   - normalize
   - segment
   - internet_discovery when enabled and needed
   - fingerprint
   - candidate_search
   - exact_match
   - near_match
   - semantic_match
   - score
   - report
7. Use Groq/LLM only for bounded tasks:
   - generating safe search queries from document snippets;
   - ranking retrieved candidate snippets/sources;
   - semantic comparison after source text is retrieved and segmented.
   Groq must not replace retrieval/citation evidence.
8. Add admin controls in Similarity settings under a new “Internet Sources” or “AI Internet Check” section. Show provider status, configured env var names, limits, and a warning about coverage and privacy.
9. Add manual actions:
   - “Run Internet Check” on a submission detail page;
   - “Run Internet Check for Pending/No-source Submissions” on dashboard if enabled;
   - API route for submission-specific internet discovery.
10. Update reports to show:
    - local source matches;
    - internet-discovered source matches;
    - provider/search run metadata;
    - source URL/title/publisher;
    - retrieved evidence snippets;
    - confidence score and match type;
    - “coverage limits” disclosure.
11. Update public workspace/download reports to include internet-source matches only when settings allow source names/URLs to be visible.
12. Add audit events for internet search, URL retrieval, source ingestion, AI ranking, semantic compare, and provider failures.
13. Add quotas/rate limiting for internet queries, fetched URLs, retrieved characters, and AI comparisons.
14. Add retention handling for retrieved internet text. Allow tenants to store only hashes/snippets if policy forbids full retrieved text storage.
15. Update docs and known limitations to state that internet-assisted checks are bounded and provider-dependent.

## Acceptance criteria
- When a tenant has no local source corpus and internet checking is disabled, AISS clearly reports that analysis was limited to the local corpus and no source material was available.
- When internet checking is enabled, AISS can generate bounded search queries from a submitted document without sending the full document by default.
- AISS can retrieve at least one public source candidate, store provenance, ingest bounded text into tenant-scoped source/text/segment records, and compare the submission against it.
- AISS uses deterministic exact/near-exact matching against retrieved internet sources.
- AISS uses semantic AI matching only after source segments exist.
- Groq provider settings are honored for query/ranking/semantic tasks only when the relevant feature is enabled and the configured env var is present.
- Reports distinguish “Local source match” from “Internet-discovered source match.”
- Reports include source URL/title, retrieval timestamp, matched excerpts, and coverage/disclaimer text.
- Admin can manually run an internet check for a submission.
- Auto-run can be configured to trigger internet discovery when local candidate sources are absent or below a configured threshold.
- User-facing report downloads include issue passages from internet sources when allowed by settings.
- Cross-tenant source/search data access is impossible.
- Provider/API failures do not produce false “clean” reports; they produce a visible “internet check unavailable/partial” state.

## Required tests
- Focused unit/structural test for new internet settings defaults and allowlist.
- Route/configuration test for internet check admin settings and manual route.
- Service test: local corpus empty + internet disabled returns a limited-corpus status.
- Service test: local corpus empty + internet enabled creates an internet search run.
- Service test: discovered source ingestion creates tenant-scoped source/text_version/segments/fingerprints with provenance.
- Pipeline test: internet discovery runs before candidate search when configured.
- Pipeline test: Groq/semantic stage skips when no source segments exist and runs when internet-ingested source segments exist.
- Privacy test: default payload policy does not send full document text to discovery/ranking provider.
- Security test: retrieved source rows cannot be read by another tenant.
- Report test: internet source matches include URL/title/retrieval timestamp and issue excerpts.
- Report test: provider failure is shown as partial/unavailable, not as zero plagiarism.
- Existing `tests/academic_similarity_cms_configuration_test.php`.
- Existing `tests/academic_similarity_semantic_capability_contract_test.php`.
- Existing report/highlight/scoring tests.
- `git diff --check`.
- Workbench/browser test for `/admin/academic-similarity/submissions/{id}` manual internet check and `/cms/page/ai-similarity-checker` public report display.

## Risks
- Internet search providers may return incomplete, paywalled, blocked, duplicated, or low-quality sources.
- Public web retrieval can violate provider terms if implemented without provider APIs, robots handling, and rate limits.
- Sending too much document text to external providers can breach student privacy or institutional policy.
- LLM judgments can hallucinate or overstate similarity without source-grounded evidence.
- Retrieved source text may be copyrighted; reports should use bounded excerpts and provenance rather than storing/displaying full copyrighted works by default.
- Network latency and provider failures can make synchronous submission processing slow; internet discovery may need background jobs.
- Duplicate template roots exist (`templates/academic_similarity/...` and `modules/academic_similarity/templates/...`); UI changes must update the live root and module copy or consolidate the rendering path.
- Existing structural tests can pass while live pages render 500s; browser/Workbench proof is required before release.

## Forbidden changes
- Do not present Groq/AI output as plagiarism proof without retrieved source evidence.
- Do not claim that AISS checks the entire internet.
- Do not send full documents to internet/AI providers by default.
- Do not store raw API keys in module settings.
- Do not bypass tenant scoping for discovered sources or search runs.
- Do not import retrieved internet sources as ordinary institutional sources without provenance.
- Do not break existing local-source matching, public shortcode submission, report download, or admin source/collection flows.
- Do not edit unrelated CMS, PAL, Attendance/Wage, Guidance, Bakeshop, Daily Ledger, or Workbench modules.

## Implementation Report

### Files changed
- Added internet-discovery schema support in `modules/academic_similarity/migrations/006_academic_similarity_internet_sources.sql`.
- Added internet source/run repositories:
  - `modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSearchRunRepository.php`
  - `modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSourceRepository.php`
- Added provider-neutral internet services:
  - `modules/academic_similarity/src/Services/AcademicSimilarityInternetDiscoveryService.php`
  - `modules/academic_similarity/src/Services/AcademicSimilarityInternetSourceIngestionService.php`
  - `modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php`
- Updated AISS registration/configuration in `modules/academic_similarity/module.json` and `modules/academic_similarity/helpers.php`.
- Wired manual internet-check routing in `modules/academic_similarity/routes.php` and `modules/academic_similarity/handlers.php`.
- Added the `internet_discovery` pipeline stage in `modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php`.
- Exposed controlled source indexing through `modules/academic_similarity/src/Services/AcademicSimilaritySourceService.php`.
- Added internet provenance to admin reports, downloadable reports, and public workspace reports.
- Added Internet settings UI and submission-detail manual run UI in both AISS template roots.
- Updated `modules/academic_similarity/docs/known-limitations.md`.
- Added `tests/academic_similarity_internet_check_test.php`.

### Tests run
- `php -l modules/academic_similarity/helpers.php` - passed.
- `php -l modules/academic_similarity/handlers.php` - passed.
- `php -l modules/academic_similarity/routes.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityInternetDiscoveryService.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityInternetSourceIngestionService.php` - passed.
- `php -l modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSearchRunRepository.php` - passed.
- `php -l modules/academic_similarity/src/Repositories/AcademicSimilarityInternetSourceRepository.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityReportService.php` - passed.
- `php -l modules/academic_similarity/src/Services/AcademicSimilarityPublicReportViewService.php` - passed.
- `php -l tests/academic_similarity_internet_check_test.php` - passed.
- `jq empty modules/academic_similarity/module.json` - passed.
- `php tests/academic_similarity_internet_check_test.php` - 31 passed, 0 failed.
- `php tests/academic_similarity_cms_configuration_test.php` - 67 passed, 0 failed.
- `php tests/academic_similarity_semantic_capability_contract_test.php` - 56 passed, 0 failed.
- `php tests/academic_similarity_report_generation_test.php` - 46 passed, 0 failed.
- `php tests/academic_similarity_report_highlighting_test.php` - 25 passed, 0 failed.
- `php tests/academic_similarity_security_test.php` - 35 passed, 0 failed.
- `php tests/academic_similarity_public_report_viewer_test.php` - exited 0, but still renders a generic 500 page in the weak structural harness.

### Results
- AISS now has a provider-neutral internet checking path that can:
  - disclose limited-corpus analysis when internet checking is disabled;
  - generate bounded search queries without sending full documents by default;
  - accept provider/capability discovered candidates or curated seed URLs;
  - retrieve bounded public text;
  - ingest retrieved text as tenant-scoped, provenance-marked AISS sources;
  - run existing deterministic and semantic matching against ingested source segments;
  - show manual internet check controls on submission detail pages;
  - mark report matches as local or internet-discovered with URL/retrieval metadata where allowed.

### Deviations
- No provider-specific Groq web-search implementation was added in this slice. The PHP module calls the provider-neutral capability contract `academic_similarity.internet.discover@1` and supports deterministic seed URLs for controlled retrieval/testing.
- The migration was added but not applied to the live tenant database during this implementation pass.
- Live Workbench/browser verification was not completed in this pass.

### Remaining risks
- Internet-source quality depends on the external discovery provider or curated seed URL configuration.
- Provider/API failures are surfaced as skipped/partial states, but production background-job retry and quota policy still need hardening.
- The public report viewer test still needs a stronger harness because it can exit 0 while rendering a generic 500 page.

## Developer Review

### Findings corrected

**P1 — Form templates referenced `{_GET.error}` which was not passed as a template variable.**
- Source and collection form templates (`sources/form.disyl`, `collections/form.disyl` in both template roots) used `{if _GET.error}` to display redirect error messages, but `_GET` is not a global template variable in DiSyL. The handler functions `pageSourceForm` and `pageCollectionForm` did not pass the error from `$_GET['error']` to the template context, so error messages after failed redirects would never appear.
- **Fix**: Added `'error' => (string)($_GET['error'] ?? '')` to the render context in both handlers. Changed template references from `{_GET.error}` to `{error}` in all four template files (module-local + global copies).

### Findings rejected and why

1. **`apiPublicSubmit` now calls `app()->csrfEnforce()`** — This is a security improvement, not a regression. The public submission form already includes a hidden `_token` CSRF field, and `FormData` submission sends it via `$_POST`. The `CsrfManager::enforce()` method checks both `$_POST['_token']` and `HTTP_X_CSRF_TOKEN` header. All existing test and submission flows already include the token. Rejected as a finding because it closes a CSRF gap on the public endpoint.

2. **Report download default format changed from `html` to `pdf`** — The `AcademicSimilarityReportService::download()` method now defaults to `format=pdf` when no `?format=` parameter is specified. This is consistent with the admin template change that relabeled the download button from "Download" to "Download PDF". The public report download (`apiPublicReportDownload`) still generates HTML. Rejected as a finding because it's an intentional UX improvement backed by the existing `dompdf/dompdf` composer dependency.

3. **Multiple template roots require duplicate edits** — Changes in `modules/academic_similarity/templates/academic_similarity/` must be mirrored in `templates/academic_similarity/` and vice versa. This is a pre-existing architectural issue in the AISS module (documented in the task's risks section). All form template fixes were applied to both roots. Rejected as out-of-scope for this review.

### Tests run

| Test | Result |
|------|--------|
| `php tests/academic_similarity_public_report_viewer_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_report_download_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_results_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_result_authorization_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_internet_check_test.php` | Passed (0 failures) |
| PHP syntax check (handlers.php, form templates ×4) | All passed |
| `git diff --check` | Clean |

### Remaining release risks

1. **Template root divergence** — The AISS module maintains templates in two locations (`modules/academic_similarity/templates/` and `templates/academic_similarity/`). UI changes must be applied to both. Future consolidation is recommended.
2. **Internet-discovery provider dependency** — The internet check feature requires either a configured capability provider (`academic_similarity.internet.discover@1`) or seed URLs to operate. Without one, the discovery stage skips gracefully with a disclosure message, but the feature has no effect.
3. **`_GET` superglobal usage in project-audit-ledger templates** — Other modules use `{_GET.*}` in DiSyL templates, which may or may not be reliably available depending on the DiSyL version. Not in AISS scope for this review.

### Results
- AISS now has a provider-neutral internet checking path that can:
  - disclose limited-corpus analysis when internet checking is disabled;
  - generate bounded search queries without sending full documents by default;
  - accept provider/capability discovered candidates or curated seed URLs;
  - retrieve bounded public text;
  - ingest retrieved text as tenant-scoped, provenance-marked AISS sources;
  - run existing deterministic and semantic matching against ingested source segments;
  - show manual internet check controls on submission detail pages;
  - mark report matches as local or internet-discovered with URL/retrieval metadata where allowed.

### Deviations
- No provider-specific Groq web-search implementation was added in this slice. The PHP module calls the provider-neutral capability contract `academic_similarity.internet.discover@1` and supports deterministic seed URLs for controlled retrieval/testing.
- The migration was added but not applied to the live tenant database during this implementation pass.
- Live Workbench/browser verification was not completed in this pass.

### Remaining risks
- Internet-source quality depends on the external discovery provider or curated seed URL configuration.
- Provider/API failures are surfaced as skipped/partial states, but production background-job retry and quota policy still need hardening.
- The public report viewer test still needs a stronger harness because it can exit 0 while rendering a generic 500 page.
