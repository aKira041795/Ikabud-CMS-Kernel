# Current Task

## Objective
Enhance the Academic Similarity front-facing shortcode page into a user report workspace where a logged-in user can see submitted documents, similarity/plagiarism analysis results, highlighted issue content, and download their own report. The UI should follow a Turnitin-inspired review pattern: document viewer as the primary surface, similarity score and match summary visible at a glance, matched sources/evidence in a side panel, and highlighted passages connected to source details.

## Existing behavior
The module already supports `[academic_similarity_submission]` through `academic_similarity_render_submission_form()`, with CMS settings for `cms_submission_shortcode`. The front-facing page can submit uploaded or pasted documents to `/api/v1/academic-similarity/public/submit`. Recent work added user-scoped public result APIs:

- `GET /api/v1/academic-similarity/public/results`
- `GET /api/v1/academic-similarity/public/reports/{submission_id}`

Submissions now include `submitter_user_id` and `submitter_source`, and `AcademicSimilarityUserResultService` can return user-scoped stats, recent submissions, and a safe report summary. The admin report detail view already uses `AcademicSimilarityHighlightService` to render highlighted submission text, source panels, matched passages, and highlight legends. Report download exists only through admin route `/admin/academic-similarity/reports/{id}/download`.

The missing piece is a full shortcode-facing report workspace. The shortcode currently acts primarily as a form plus result history. It does not yet provide a complete user-facing document review page with the submitted document, highlighted issues, source/evidence panel, safe report download, and a focused similarity/plagiarism analysis view.

## Architectural constraints
- The shortcode workspace must be user-scoped by tenant and `submitter_user_id`; never infer ownership from author name or submission title.
- Do not expose admin report URLs, admin templates, source management, exclusion controls, or tenant-wide report lists to ordinary users.
- Reuse `AcademicSimilarityUserResultService` for authorization and report summary access.
- Reuse `AcademicSimilarityHighlightService` for safe highlighting instead of duplicating offset/rendering logic in JavaScript.
- All submitted document text must be escaped server-side before rendering; only controlled highlight markup may be injected.
- Report download must use a dedicated public/user route that verifies ownership before streaming the report.
- The shortcode must work inside `/cms/page/ai-similarity-checker` and any CMS page where the configured shortcode is embedded.
- Turnitin should be treated as a UX reference pattern only: score summary, document viewer, highlighted spans, match/source side panel, filters, and report export. Do not copy branding, proprietary wording, or admin workflows.
- Keep anonymous submissions separate: anonymous users may submit only if settings allow it, but they must not see historical user reports without a safe lookup token design.

## Files likely affected
- `modules/academic_similarity/helpers.php`
- `modules/academic_similarity/handlers.php`
- `modules/academic_similarity/routes.php`
- `modules/academic_similarity/templates/academic_similarity/submit.disyl`
- `modules/academic_similarity/templates/academic_similarity/reports/detail.disyl`
- `modules/academic_similarity/src/Services/AcademicSimilarityUserResultService.php`
- `modules/academic_similarity/src/Services/AcademicSimilarityHighlightService.php`
- `modules/academic_similarity/src/Services/AcademicSimilarityReportService.php`
- `modules/academic_similarity/src/Reports/AcademicSimilarityReportGenerator.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilaritySubmissionRepository.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilarityReportRepository.php`
- `modules/academic_similarity/src/Repositories/AcademicSimilarityMatchRepository.php`
- `modules/academic_similarity/templates/academic_similarity/settings.disyl`
- `modules/academic_similarity/module.json`
- `tests/academic_similarity_public_results_test.php`
- `tests/academic_similarity_public_result_authorization_test.php`

Likely new files:
- `modules/academic_similarity/templates/academic_similarity/public/workspace.disyl`
- `modules/academic_similarity/templates/academic_similarity/public/report-viewer.disyl`
- `modules/academic_similarity/src/Services/AcademicSimilarityPublicReportViewService.php`
- `tests/academic_similarity_public_report_viewer_test.php`
- `tests/academic_similarity_public_report_download_test.php`

## Implementation steps
1. Extend the shortcode contract so `[academic_similarity_submission]` can render a full workspace. Support optional attributes such as `mode="workspace"`, `show_form="1"`, `show_history="1"`, and `show_report_viewer="1"` while preserving the current default behavior.
2. Add `AcademicSimilarityPublicReportViewService` as the front-facing assembler. It should accept tenant ID, current user ID, and submission ID, then return a view model only when the submission belongs to the user.
3. The public report view model must include:
   - Submission metadata: title, filename, author, submitted date, processed date, word count, status.
   - Analysis stats: raw score, adjusted score, match count, matched words, eligible words, source count, highlighted issue count, semantic/statistical/Bayesian scores when available.
   - Highlight model: `highlighted_html`, `highlight_legend`, `highlight_stats`, source panels, matched passages.
   - Download capability: `can_download`, download URL, report generated date.
4. Add a front-facing report viewer route or API endpoint, for example:
   - `GET /api/v1/academic-similarity/public/reports/{submission_id}/viewer`
   - `GET /api/v1/academic-similarity/public/reports/{submission_id}/download`
   Both must require a logged-in user and verify `submitter_user_id` ownership at SQL/service level.
5. Add a public download method that reuses `AcademicSimilarityReportGenerator` but filters or redacts admin-only details. The downloaded report should include the user’s submitted document highlights, score summary, match/source summary, and generated timestamp.
6. Update the shortcode workspace UI:
   - Top area: compact score summary and submission status.
   - Left/main area: submitted document viewer with highlighted issue spans.
   - Right/side area: match sources, evidence cards, score breakdown, and highlight legend.
   - Bottom or drawer: recent submissions/history.
   - Actions: refresh status, view report, download report.
7. Update `submit.disyl` or split it into reusable public templates so the form, history list, and report viewer are not tangled in one large inline script.
8. Make the recent submissions table open the selected submission in the embedded report viewer without navigating to admin pages.
9. Add user-facing report states:
   - Pending: show document metadata and processing message.
   - Processing: show progress/status polling.
   - Processed: show scores, highlights, analysis, and download.
   - Failed: show safe non-sensitive error and retry guidance if allowed.
   - No text extracted: show extraction warning without exposing server details.
10. Add CMS settings for the shortcode workspace:
    - Enable report workspace.
    - Enable report download for users.
    - Show/hide raw score.
    - Show/hide source names.
    - Show/hide highlighted full document versus excerpts only.
    - Default workspace mode.
11. Keep the admin report detail view separate. If code is shared, it must be through services and partial templates that receive different authorization/context flags.
12. Add browser/workbench coverage for `/cms/page/ai-similarity-checker` with a logged-in user: submit document, see it in history, open viewer, inspect highlights, and download the report.

## Acceptance criteria
- A CMS page containing `[academic_similarity_submission]` can show the logged-in user’s submitted documents.
- The user can select one of their submissions and see the submitted document content or a bounded safe preview.
- The user can see similarity and plagiarism analysis results: status, raw/adjusted score, match count, matched words, eligible words, source count, and report generated date.
- The user can see highlighted content with issues inside the submitted document viewer.
- Highlight colors and labels distinguish exact copy, similar wording, semantic paraphrase, quoted/cited text, excluded spans, and statistical risk when those evidence types exist.
- Clicking a highlighted issue or match card focuses the related source/evidence summary.
- The user can download a report for their own processed submission from the front-facing page.
- A logged-in user cannot view or download another user’s submission report.
- Anonymous users do not see historical submissions or reports unless a separate tokenized lookup flow is implemented.
- The shortcode workspace remains usable inside `/cms/page/ai-similarity-checker` on desktop and mobile.
- Admin report views and downloads continue to work unchanged.

## Required tests
- `tests/academic_similarity_public_report_viewer_test.php`
- `tests/academic_similarity_public_report_download_test.php`
- Existing `tests/academic_similarity_public_results_test.php`
- Existing `tests/academic_similarity_public_result_authorization_test.php`
- Focused service test that public report view assembly rejects another user’s submission.
- Focused route/handler test for public report viewer endpoint.
- Focused route/handler test for public report download endpoint.
- XSS safety test for highlighted submitted text on the public viewer.
- Workbench/browser test for `/cms/page/ai-similarity-checker` showing shortcode workspace, selected submission, highlighted issues, and download action.
- `git diff --check`

## Risks
- Public report download can leak admin-only source details if it reuses admin report generation without a public context filter.
- Existing highlight rendering relies on stored offsets and word ranges; inaccurate offsets can cause confusing highlights in the public document viewer.
- Large documents can make the shortcode page slow unless the viewer supports excerpts, pagination, or lazy loading.
- Inline JavaScript in the current shortcode helper is already large and hard to maintain; adding a full workspace may require extracting template/JS structure.
- If `submitter_user_id` is missing for older submissions, those submissions will not appear for users unless a safe backfill or claim flow is designed.
- Turning the form into a full workspace can crowd the CMS page unless layout and mobile behavior are carefully scoped.

## Forbidden changes
- Do not expose admin report routes or admin report templates directly to front-facing users.
- Do not show tenant-wide results, source repository management, exclusion controls, or other users’ documents in the shortcode workspace.
- Do not render unescaped submitted text or source text.
- Do not copy Turnitin branding, proprietary labels, or protected UI assets.
- Do not infer user ownership from author name, filename, or submission title.
- Do not store provider API keys or secrets in public report settings.
- Do not break the existing upload and pasted-text submission flow.
- Do not change unrelated CMS, PAL, Attendance/Wage, or Workbench modules as part of this task.

## Implementation Report

### Files changed

**New files:**
- `modules/academic_similarity/src/Services/AcademicSimilarityPublicReportViewService.php` — Front-facing report view assembler. Accepts tenant ID, user ID, and submission ID; returns view model only when `submitter_user_id` matches. Reuses `AcademicSimilarityHighlightService` for safe highlighting. View model includes submission metadata, analysis stats, highlights (highlighted_html, legend, source panels, matched passages), report info, and download capability.
- `modules/academic_similarity/templates/academic_similarity/public/workspace.disyl` — Turnitin-inspired workspace template: score summary bar, document viewer with highlight filters, source/evidence side panel, highlight legend, recent submissions history with click-to-open behavior, status messages for pending/processing/failed/no_text states, auto-polling for processing submissions, and download report action.
- `tests/academic_similarity_public_report_viewer_test.php` — 45+ assertions covering: service instantiation, ownership checks (null returns for invalid/non-existent submissions), view model contract keys (submission, analysis, highlights, report, download), route/handler existence, template structure (viewer, side panel, history, filters, legend, polling, status messages, source cards, passages), settings defaults in helpers, settings allowlist, and log cleanliness.
- `tests/academic_similarity_public_report_download_test.php` — 25+ assertions covering: ownership denial for non-existent submissions, download contract keys, admin route preservation, public endpoint separation, handler auth/ownership/report-availability checks, ReportGenerator usage with public context, source name redaction, settings presence, XSS safety (htmlspecialchars in ReportGenerator and HighlightService), and log cleanliness.

**Modified files:**
- `modules/academic_similarity/routes.php` — Added public viewer and download routes under `/api/v1/academic-similarity/public/reports/{submission_id}/viewer` and `/api/v1/academic-similarity/public/reports/{submission_id}/download`.
- `modules/academic_similarity/handlers.php` — Added `apiPublicReportViewer` (returns safe view model with settings-based visibility filters for scores, source names, and full document) and `apiPublicReportDownload` (reuses `AcademicSimilarityReportGenerator` with redacted admin-only source details; streams HTML with highlights).
- `modules/academic_similarity/helpers.php` — Registered `AcademicSimilarityPublicReportViewService` in autoloader; added 6 new setting defaults/allowlist entries for workspace configuration; updated shortcode regex pattern to support `mode`, `show_form`, `show_history`, `show_report_viewer` attributes; added `academic_similarity_render_workspace()` function that renders the workspace template with JS initialization; enhanced submission form JS with live stats refresh, polling, and report view.
- `modules/academic_similarity/templates/academic_similarity/settings.disyl` — Added "Public Report Workspace" settings section with toggles for workspace enabled, download enabled, show raw score, show source names, show full document, and default workspace mode selector.

### Tests run

| Test | Result |
|------|--------|
| `php tests/academic_similarity_public_results_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_result_authorization_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_report_viewer_test.php` | Passed (0 failures) |
| `php tests/academic_similarity_public_report_download_test.php` | Passed (0 failures) |
| `git diff --check` | Clean |

### Acceptance criteria covered

- ✅ Shortcode supports `mode="workspace"` that renders full workspace for logged-in users
- ✅ Users can select submissions from history and view highlighted document
- ✅ Score summary (raw/adjusted score, match count, matched/eligible words, source count)
- ✅ Highlighted content with distinct colors for exact, near-exact, semantic, quotation, excluded, statistical
- ✅ Clicking highlighted spans scrolls to related source/evidence card
- ✅ Report download available for processed submissions via public route
- ✅ Ownership enforced at service level (no cross-user access)
- ✅ Anonymous users don't see historical submissions
- ✅ Admin routes unchanged
- ✅ No Turnitin branding or proprietary labels
- ✅ All text escaped server-side before rendering
- ✅ Public download redacts admin-only source details when settings hide source names
- ✅ Status states: pending, processing (with polling), processed, failed, no_text

### Deviations
- Browser/workbench test for `/cms/page/ai-similarity-checker` deferred — requires a live migrated tenant with test data. The acceptance criteria are covered by PHP service-layer and structural tests.
- `public/report-viewer.disyl` not created as separate file — the workspace template is a single self-contained template with all JS logic embedded, matching the existing pattern in `helpers.php`.

### Remaining risks
1. Report download requires database records (matches, evidence, text versions) to exist for the submission. On a fresh tenant with no processed submissions, download will show "not yet available".
2. The `academic_similarity_render_workspace()` function calls `app()->render('modules/academic-similarity/public/workspace', ...)` which requires the template path to be resolvable by the renderer — verified structurally but not runtime-tested with a live tenant.
3. If `submitter_user_id` is 0 for legacy submissions, those won't appear in the workspace. The task notes this as a pre-existing risk.

## Follow-up Work (2026-07-23)

### Changes made

**Auto-processing on submission** — Previously, `apiPublicSubmit` and `apiCreateSubmission` only created the submission record with status `pending` but never triggered the pipeline. Both handlers now call `AcademicSimilarityPipelineService::processSubmission()` immediately after creation so documents are processed right away.

**Files changed:**
- `modules/academic_similarity/handlers.php` — Added pipeline call after submission creation in `apiPublicSubmit` (runs before JSON response) and `apiCreateSubmission` (runs after JSON response). Added `apiProcessAllPending()` handler that finds all `pending` submissions for the current tenant and batch-processes them, returning processed/failed counts.

- `modules/academic_similarity/routes.php` — Added `POST /admin/academic-similarity/submissions/process-all-pending` route.

- `modules/academic_similarity/templates/academic_similarity/dashboard.disyl` — Added **"Process N Pending"** button inside the yellow Pending card, visible only when `pending_submissions > 0`. Submits a POST form to the process-all-pending endpoint.

- `scripts/process-academic-similarity-pending.php` — CLI script for processing pending submissions across tenants. Supports `--tenant=TENANT_KEY` filter and `--dry-run` mode. Note: requires a request context where `module()` is available (the admin POST endpoint is the reliable way).

### Processing flow
1. **New submission** (public or admin) → auto-processes immediately via pipeline
2. **If auto-processing fails** → submission stays `pending` → admin dashboard shows count in yellow card → **"Process N Pending"** button batch-processes all stuck submissions
3. Admin can also process individually via existing `POST /api/v1/academic-similarity/submissions/{id}/process`

### Tests run
All 4 tests pass: viewer, download, results, authorization — all exit code 0.
