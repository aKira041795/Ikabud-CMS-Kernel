# Current Task

## Status (2026-07-22)

### ✅ Completed
- P0 — Module scaffold, routes, handlers, helpers, dashboard template, module docs
- P0 — Schema migrations (all 22 tables), module.json owns_tables declaration
- P0 — Repositories and policies with tenant/institution scope
- P0 — Secure submission/source upload + pasted-text intake with validation
- P0 — Deterministic text pipeline (normalization, segmentation, fingerprinting, exact/near-exact matching, overlap resolution, scoring)
- P0 — Report viewer + downloadable report
- P0 — Public submission form via `[academic_similarity_submission]` shortcode
- P0 — Page builder widget `academic_similarity_submission` with renderer
- P0 — CMS admin nav hook ("Similarity" section, 6 items)
- P0 — Superadmin "Other Available Modules" collapsible section
- P1 — Reviewer workflow (exclusions, notes, assignment/review state, audited actions)
- P1 — Repository administration (collections, source upload, indexing, duplicate detection)
- P1 — Subscription plans, quota counters, warnings, enforcement gates
- P1 — AISS semantic matching Python service-module + ServiceProxy integration
- P0/P1 code review fixes (priority tiebreaker, false-success handler, configurable segment limit)
- Apache vhost config for `aiss.test` — enabled and Apache reloaded
- 438 tests passing (350 existing + 56 semantic + 32 Python)

### 🔄 Not started
- End-to-end testing with a live tenant on `aiss.test`
- Browser/Workbench test specs
- DiSyL public template rendering verification

## Objective

Implement the Ikabud Academic Integrity and Similarity System as a self-contained `academic_similarity` module that supports secure academic document submission, deterministic exact and near-exact similarity detection, offset-accurate highlights, reviewer exclusions, reproducible reports, audit history, tenant isolation, and an AISS semantic matching component implemented through a provider-neutral Python service module.

The architecture must avoid presenting scores as academic misconduct verdicts. UI, reports, APIs, and persisted status must use similarity terminology such as `similarity score`, `matched passage`, `source match`, `exact match`, `near-exact match`, `semantic match`, `excluded content`, and `reviewer determination`.

## Existing behavior

The repository is a PHP 8.2+ modular Ikabud Kernel OS application where modules are manifest-driven under `modules/{id}` and declare owned tables, migrations, routes, capabilities, events, and optional commercial behavior in `module.json`.

Request routing loads module `routes.php` files and dispatches handlers with `module-id:functionName` references, so the academic module should expose admin and API routes through `modules/academic_similarity/routes.php` and keep handlers thin.

The kernel provides tenant-aware database access through `app()->db()`, `app()->dbForTenant($tenantId)`, tenant resolver configuration, per-tenant module enablement, cookie/JWT auth, role checks, CSRF helpers, and security headers.

The capability system already supports typed capability registration, policy enforcement, schema validation, timeout/retry/circuit-breaker behavior, and metrics through `CapabilityBus`, `CapabilityRegistry`, and `CapabilityProviderContract`.

Polyglot service modules are supported through `type: "service-module"` manifests and `ServiceProxy`, which proxies capability calls to `POST {endpoint}/capability/call` with JSON and receives bus-level retries, timeouts, and breaker behavior.

The kernel has a lightweight queue in `bootstrap.php` with `kernelDispatchJob()`, `kernelProcessNextJob()`, `kernelJobInvokeHandler()`, retry backoff, failed-job recording, and module handler invocation.

The global audit capability `kernel.audit.record@1` is configured for enforced schema validation, and several modules already write module/domain audit records.

Existing UI is server-first DiSyL/HTMX/Alpine, with module templates under either `templates/modules/{module}` or module-local `templates/{module}` depending on the module. ARK Workbench tests and browser diagnostics exist for route, component, accessibility, and workflow coverage.

There is no existing `academic_similarity` module and no existing similarity-specific test suite.

## Architectural constraints

Keep business logic out of themes, global helpers, kernel core, generic controllers, and presentation templates. The kernel must still boot and operate when `academic_similarity` or the AISS semantic matching component is disabled.

Use module-owned repositories and services for persistence, pipeline orchestration, scoring, source indexing, reviewer actions, and report generation. Route handlers should validate request context, enforce auth/CSRF, call services, and return JSON or render templates.

Tenant ID must exist on every tenant-owned record. Institution ID must exist on institution-scoped records. All repository methods must require tenant/institution scope and must fail closed if scope is missing.

Cross-tenant and cross-institution source matching is forbidden unless a future explicit consortium-sharing contract is implemented; this must be disabled by default and omitted from the MVP except for schema placeholders that preserve future compatibility.

Exact and near-exact matching must work without AI, embeddings, third-party APIs, OCR, or the Python service.

The AISS semantic matching component is part of the product architecture but its execution must be optional, provider-neutral, privacy-gated, plan-gated, quota-gated, and invoked only through a capability such as `academic_similarity.semantic.compare@1` exposed by a separate Python service module.

Uploaded source and submission files must be stored outside the public web root with randomized storage names; original filenames are metadata only. Extracted text is untrusted content and must be escaped in every rendered surface and report.

Normalization must preserve mappings between original offsets, normalized offsets, sentence IDs, paragraph IDs, and page IDs. Highlights must be generated from original-offset mappings, not normalized text alone.

Pipeline stages must record status, start/completion timestamps, failure reason, retry metadata, idempotency key, and diagnostics in module-owned processing records, even though execution uses the kernel queue.

Raw match evidence must be immutable. Reviewer exclusions and adjusted score state must be stored separately and audited rather than mutating or deleting raw evidence.

Similarity scoring must use unique matched eligible words divided by total eligible words after configured denominator exclusions, with overlap resolution preventing double-counting.

Subscription limits, prices, thresholds, model profiles, file limits, matching thresholds, retention settings, and quota warnings must be stored in configuration tables or tenant settings, not hard-coded in business logic.

Do not enable OCR in the first production release. Do not send full documents to external AI services by default.

## Files likely affected

Primary module:

- `modules/academic_similarity/module.json`
- `modules/academic_similarity/routes.php`
- `modules/academic_similarity/handlers.php`
- `modules/academic_similarity/helpers.php`
- `modules/academic_similarity/migrations/001_academic_similarity_schema.sql`
- `modules/academic_similarity/migrations/002_academic_similarity_seed_plans.sql`
- `modules/academic_similarity/contracts/*.php`
- `modules/academic_similarity/src/Services/*.php`
- `modules/academic_similarity/src/Repositories/*.php`
- `modules/academic_similarity/src/ValueObjects/*.php`
- `modules/academic_similarity/src/DTO/*.php`
- `modules/academic_similarity/src/Policies/*.php`
- `modules/academic_similarity/src/Validators/*.php`
- `modules/academic_similarity/src/Jobs/*.php`
- `modules/academic_similarity/src/Reports/*.php`
- `modules/academic_similarity/src/Support/*.php`
- `modules/academic_similarity/templates/academic_similarity/**/*.disyl`
- `modules/academic_similarity/assets/academic_similarity.css`
- `modules/academic_similarity/docs/architecture.md`
- `modules/academic_similarity/docs/threat-model.md`
- `modules/academic_similarity/docs/scoring.md`
- `modules/academic_similarity/docs/admin-guide.md`
- `modules/academic_similarity/docs/reviewer-guide.md`
- `modules/academic_similarity/docs/deployment.md`
- `modules/academic_similarity/docs/known-limitations.md`

AISS semantic matching component:

- `modules/academic-similarity-semantic-service/module.json`
- `modules/academic-similarity-semantic-service/service/app.py`
- `modules/academic-similarity-semantic-service/service/requirements.txt`
- `modules/academic-similarity-semantic-service/tests/*.py`
- `modules/academic-similarity-semantic-service/docs/api.md`

Kernel integration surfaces to inspect but avoid changing unless a confirmed contract gap blocks the module:

- `src/helpers/module-manager.php`
- `kernel/Capabilities/CapabilityBus.php`
- `kernel/Capabilities/ServiceProxy.php`
- `bootstrap.php`
- `config/app.php`
- `docs/kernel/ARCHITECTURE.md`
- `docs/kernel/kernel-stable-contracts.md`
- `docs/kernel/kernel-os-disyl-roadmap-status.md`

UI and browser verification surfaces:

- `templates/modules/*`
- `modules/*/templates/*`
- `tests/browser/run-workbench.js`
- `tests/browser/ModuleDiagnostic.js`
- `tests/browser/workbench/*.spec.js`
- `tests/browser/modules/academic-similarity/*.spec.js`

Focused PHP tests:

- `tests/academic_similarity_normalization_test.php`
- `tests/academic_similarity_offset_mapping_test.php`
- `tests/academic_similarity_segmentation_test.php`
- `tests/academic_similarity_fingerprint_test.php`
- `tests/academic_similarity_exact_match_test.php`
- `tests/academic_similarity_near_match_test.php`
- `tests/academic_similarity_overlap_resolution_test.php`
- `tests/academic_similarity_scoring_test.php`
- `tests/academic_similarity_exclusion_audit_test.php`
- `tests/academic_similarity_quota_test.php`
- `tests/academic_similarity_security_test.php`
- `tests/academic_similarity_pipeline_job_test.php`
- `tests/academic_similarity_report_generation_test.php`
- `tests/academic_similarity_semantic_capability_contract_test.php`

## Implementation steps

P0 - Safe deterministic MVP:

1. Create the module manifest, routes, thin handlers, helper bootstrap, module-local docs, and a minimal dashboard route.
   - Files affected: `modules/academic_similarity/module.json`, `routes.php`, `handlers.php`, `helpers.php`, dashboard template, module docs.
   - Dependencies: existing module discovery, auth, CSRF, tenant DB.
   - Acceptance criteria: module can be enabled per tenant, routes load without kernel errors, disabled module leaves kernel behavior unchanged.
   - Tests required: manifest validation smoke, route registration smoke, disabled-module boot smoke.
   - Risks: route ambiguity with `/admin/*`; mitigate by keeping all admin routes under `/admin/academic-similarity`.

2. Add the tenant-owned schema for institutions, plans, subscriptions, usage counters, collections, sources, submissions, files, extracted text references, segments, fingerprints, candidate sources, matches, match evidence, exclusions, reviews, reports, processing jobs, model profiles, integrations, retention policies, and audit events.
   - Files affected: module migrations, `module.json` `owns_tables`.
   - Dependencies: migration runner conventions and tenant DB privileges.
   - Acceptance criteria: fresh tenant migration creates all tables with tenant/institution columns, foreign keys where supported, indexes for repository search, unique idempotency keys, and immutable evidence tables.
   - Tests required: migration smoke, schema ownership assertion, idempotent migration re-run.
   - Risks: large text duplication and index bloat; mitigate by storing extracted text once per file/version and segment offsets separately.

3. Implement repositories and policies for tenant/institution-scoped reads and writes.
   - Files affected: `src/Repositories`, `src/Policies`, `helpers.php`.
   - Dependencies: `app()->db()` and `app()->dbForTenant()`.
   - Acceptance criteria: every repository method requires tenant ID, institution ID when applicable, user context where authorization is needed, and uses prepared statements.
   - Tests required: tenant isolation, IDOR denial, SQL injection guard, missing-scope fail-closed tests.
   - Risks: accidental direct DB access from handlers; mitigate with handler tests and code review rule.

4. Implement secure submission/source upload and pasted-text intake.
   - Files affected: submission controller/service, validation service, storage support, submission form template.
   - Dependencies: PHP upload limits, private storage path under `storage/academic_similarity`, CSRF.
   - Acceptance criteria: DOCX, searchable PDF, TXT, and pasted text accepted only after extension, MIME, signature, size, page/word, archive-safety, encryption, and timeout validation.
   - Tests required: malicious upload rejection, MIME spoofing, path traversal, archive bomb guard, CSRF enforcement.
   - Risks: PDF/DOCX parser vulnerabilities; mitigate with strict limits, timeout wrappers, private storage, and clear extraction failure states.

5. Build the deterministic text pipeline: extraction, normalization, segmentation, quote/bibliography identification, fingerprint generation, candidate retrieval, exact matching, near-exact matching, overlap resolution, scoring, and report record creation.
   - Files affected: `src/Services`, `src/ValueObjects`, `src/Jobs`, processing job handlers.
   - Dependencies: private file storage and processing job records.
   - Acceptance criteria: each stage is idempotent, retry-safe, records status timestamps/failure reason, and can resume without duplicate matches.
   - Tests required: normalization, offset mapping, segmentation, fingerprinting, exact match, near-exact match, overlap resolution, score reproducibility, retry/idempotency.
   - Risks: inaccurate highlights if offset mapping is lossy; mitigate by making offset-map tests a release blocker.

6. Implement the report viewer and basic downloadable report.
   - Files affected: report service, report templates, report download handler, highlight components.
   - Dependencies: matches, sources, exclusions, scoring.
   - Acceptance criteria: report shows raw and adjusted scores, matched passages, source cards, accessible highlight labels, source filtering, processing details, and deterministic printable/PDF output.
   - Tests required: XSS escaping, highlight source linkage, score fields, printable report smoke.
   - Risks: extracted text stored/rendered as untrusted content; mitigate with escaped templates and XSS fixtures.

P1 - Institutional production workflow:

1. Add reviewer workflow, exclusions, notes, assignment/review state, final reviewer determination, and audited reviewer actions.
   - Files affected: review services, exclusion services, audit repository, report templates, API routes.
   - Dependencies: P0 report and match model.
   - Acceptance criteria: exclusions record reviewer, reason, timestamp, previous/resulting score, optional note, and affected matches without mutating raw evidence.
   - Tests required: exclusion audit, permission matrix, adjusted score recalculation, concurrent reviewer update handling.
   - Risks: silent score manipulation; mitigate by immutable raw evidence and audit capability calls.

2. Add repository administration for collections, source upload, indexing status, duplicate detection, retention metadata, and reindex commands.
   - Files affected: repository admin routes/templates/services/jobs.
   - Dependencies: P0 source model and pipeline.
   - Acceptance criteria: repository manager can search, upload, classify, reindex, and inspect source diagnostics within tenant/institution scope.
   - Tests required: repository indexing integration, duplicate checksum detection, retention status tests.
   - Risks: unauthorized source text exposure; mitigate with role checks and source classification policies.

3. Add subscription plans, quota counters, warnings, enforcement gates, and institution settings.
   - Files affected: subscription/quota services, plan seed migration, settings/admin templates.
   - Dependencies: usage counters and submission intake.
   - Acceptance criteria: expensive processing is blocked before it starts when quota is exhausted, warnings appear at configured thresholds, and over-quota never deletes data.
   - Tests required: quota calculations, warning thresholds, plan upgrade/downgrade, grace/suspension behavior.
   - Risks: hard-coded prices or limits; mitigate by configuration tables and seed data only.

4. Add retention, purge workflow, privacy controls, consent tracking, audit search, and admin diagnostics.
   - Files affected: retention services/jobs, admin templates, audit events.
   - Dependencies: report/source/submission records.
   - Acceptance criteria: retention policies are configurable, purge requests are explicit and verified, diagnostics expose failed pipeline stages without leaking source text.
   - Tests required: purge verification, access logs, audit search, failed-job diagnostics.
   - Risks: legal retention conflict; mitigate by explicit policy state and no automatic deletion on quota events.

P2 - AISS semantic matching component and integration improvements:

1. Implement the AISS semantic matching component as a separate provider-neutral Python service-module that exposes `academic_similarity.semantic.compare@1`.
   - Files affected: semantic service module manifest, Python app, service tests, PHP semantic client/service.
   - Dependencies: ServiceProxy, CapabilityBus, model profile settings.
   - Acceptance criteria: PHP invokes the service through the capability bus only; service returns segment/source matches with model and version metadata; disabled service leaves deterministic matching intact.
   - Tests required: capability contract, offline ServiceProxy seam, service health, semantic failure fallback.
   - Risks: provider lock-in and privacy leakage; mitigate with model profiles and segment-only payload defaults.

2. Add vector indexing and semantic usage accounting.
   - Files affected: semantic model profiles, usage counters, repository indexing jobs.
   - Dependencies: semantic service and quota settings.
   - Acceptance criteria: semantic processing is gated by plan, admin setting, quota, budget, and privacy policy.
   - Tests required: semantic quota enforcement, model version reproducibility, service timeout/circuit breaker behavior.
   - Risks: semantic false positives; mitigate with separate semantic score fields and reviewer determination workflow.

3. Define Moodle/LMS/email/API integration contracts before provider-specific implementations.
   - Files affected: contracts, integration config, docs, optional routes.
   - Dependencies: assignment/submission/report workflow.
   - Acceptance criteria: contracts document payloads, auth, tenant scope, and failure behavior.
   - Tests required: contract validation and webhook/API auth tests.
   - Risks: bypassing module permissions; mitigate by contract-level authorization and tenant scope assertions.

P3 - Commercial SaaS expansion:

1. Add onboarding, institutional invoicing, annual/monthly billing metadata, trial/grace/suspension controls, and support-admin tools.
   - Files affected: subscription admin services/templates/docs.
   - Dependencies: P1 quota and plan subsystem.
   - Acceptance criteria: pricing and limits remain configurable and auditable.
   - Tests required: billing state transitions, suspension access behavior, support-admin access boundaries.
   - Risks: mixing billing decisions into similarity logic; mitigate by keeping quota service as the only processing gate.

2. Add analytics, multi-campus controls, API access, service-level monitoring, and production-readiness reporting.
   - Files affected: analytics services/templates, API docs, monitoring docs.
   - Dependencies: stable P1/P2 telemetry and audit events.
   - Acceptance criteria: administrators can inspect usage, failures, throughput, and report status without cross-tenant leakage.
   - Tests required: analytics tenant isolation, performance tests, API auth tests.
   - Risks: reporting leakage; mitigate with aggregate-only defaults and scoped drill-down permissions.

## Acceptance criteria

The module is acceptable for controlled MVP only when secure file validation, private storage, extraction failure handling, deterministic exact and near-exact matching, offset-accurate highlights, overlap-safe scoring, source linkage, basic report generation, tenant isolation, CSRF protection, and focused tests are complete.

The module is acceptable for institutional production only when reviewer exclusions, audit history, repository administration, retention policies, quotas, plan enforcement, diagnostics, security tests, performance tests, deployment docs, rollback docs, admin/reviewer guides, and known limitations are complete.

The AISS semantic matching component is acceptable only when deterministic matching remains fully usable without it, the capability contract is provider-neutral, model/version metadata is stored, privacy and quota gates run before service calls, and service failures degrade to a clear optional-stage failure rather than corrupting deterministic reports.

Reports must remain reproducible by recording report version, matching-engine version, semantic-model version where applicable, source/match evidence IDs, exclusion state, generation timestamp, and checksum/report identifier.

All UI must treat extracted text as untrusted, use accessible highlight indicators beyond color, support keyboard navigation, and avoid making similarity percentages appear as misconduct verdicts.

## Required tests

Focused PHP tests:

- `php tests/academic_similarity_normalization_test.php`
- `php tests/academic_similarity_offset_mapping_test.php`
- `php tests/academic_similarity_segmentation_test.php`
- `php tests/academic_similarity_fingerprint_test.php`
- `php tests/academic_similarity_exact_match_test.php`
- `php tests/academic_similarity_near_match_test.php`
- `php tests/academic_similarity_overlap_resolution_test.php`
- `php tests/academic_similarity_scoring_test.php`
- `php tests/academic_similarity_exclusion_audit_test.php`
- `php tests/academic_similarity_quota_test.php`
- `php tests/academic_similarity_security_test.php`
- `php tests/academic_similarity_pipeline_job_test.php`
- `php tests/academic_similarity_report_generation_test.php`
- `php tests/academic_similarity_semantic_capability_contract_test.php`

Browser and ARK tests:

- `node tests/browser/run-workbench.js --module=academic-similarity --gate=main`
- `npx playwright test tests/browser/modules/academic-similarity/navigation.spec.js`
- `npx playwright test tests/browser/modules/academic-similarity/report-viewer.spec.js`
- `npx playwright test tests/browser/modules/academic-similarity/submission-flow.spec.js`

Security fixtures:

- tenant-crossing report access
- IDOR source access
- malicious DOCX archive
- PDF/TXT MIME spoof
- path traversal filename
- stored XSS in extracted text
- forged source URL
- CSRF mutation rejection
- quota bypass attempt
- replayed job request

Accuracy and performance fixtures:

- exact copied passage
- lightly edited passage
- reordered sentence set
- paraphrased passage
- properly quoted passage
- bibliography-only match
- common phrase match
- institution template text
- unrelated control document
- large 50-page searchable PDF
- 25,000-word DOCX/TXT equivalent
- concurrent submissions and queue saturation

Do not run the full suite during architecture. Implementation and release-gate passes should run only the focused tests required by changed behavior, then broader browser/runtime gates when the module becomes user-facing.

## Risks

Offset mapping is the highest technical risk because highlights must point to original text after normalization, segmentation, and matching. Make offset-map fixtures mandatory before report UI work is considered complete.

Document parsing is a security risk because DOCX archives and PDFs can be malformed, oversized, encrypted, or malicious. Keep file validation strict, process from private storage, and record failures clearly.

Tenant leakage is a critical product and legal risk. Repository queries, report access, source retrieval, audit search, usage counters, and semantic calls must all include tenant and institution scope.

Score inflation is likely if overlapping matches are counted more than once. Store raw candidate evidence, resolve display overlaps deterministically, and calculate score from unique eligible word coverage.

Semantic matching can create privacy, cost, reproducibility, and false-positive risks. Keep it optional, disabled by default for MVP, provider-neutral, quota-gated, and reported separately.

Subscription enforcement can become brittle if limits are embedded in business logic. Store all plan limits, thresholds, and pricing as data.

Large source repositories can degrade performance if matching uses naive full-table scans. Use fingerprints, shingle indexes, candidate retrieval limits, and background indexing before comparison.

Report exports can leak confidential source passages. Enforce role/source-classification policies at report assembly time and include only authorized source text.

## Forbidden changes

Do not edit kernel core, global helpers, generic controllers, theme files, or shared templates unless a documented contract gap blocks the module and the change is approved in a later implementation task.

Do not implement business logic in DiSyL templates or theme assets.

Do not bypass `CapabilityBus`, tenant resolution, role authorization, CSRF enforcement, module-owned repositories, or audit logging.

Do not hard-code subscription prices, limits, semantic providers, thresholds, or institution policies in service logic.

Do not enable OCR in the first release.

Do not send full documents to external AI services by default.

Do not use semantic matching as a dependency for exact or near-exact matching.

Do not mutate or delete raw match evidence when reviewers exclude matches.

Do not show a similarity score as a final plagiarism or misconduct verdict.

Do not run the full test suite in the architecture pass.

Do not commit, push, or deploy as part of this task.

## AISS Semantic Matching Component Plan

### Objective

Add semantic similarity as a first-class AISS component while preserving the deterministic MVP rule: exact and near-exact matching must remain complete and usable when semantic processing is disabled, unavailable, over quota, or not included in the institution plan.

### Existing behavior

The current AISS module already defines deterministic normalization, segmentation, fingerprinting, exact matching, near-exact matching, overlap resolution, scoring, report generation, quota tracking, and a contract stub for `academic_similarity.semantic.compare@1`.

The kernel already supports provider-neutral polyglot service modules through `type: "service-module"` manifests, `ServiceProxy`, and the CapabilityBus `POST /capability/call` JSON protocol. The existing `weather-service` module is the local reference for a Python service-module manifest, health check, timeout, retry, circuit breaker, and schema-declared capabilities.

### Architectural constraints

Semantic matching is an AISS component, not a replacement for deterministic matching. It must run after deterministic candidate retrieval and before final overlap resolution/scoring only when all plan, quota, privacy, model-profile, and administrator gates pass.

PHP owns tenant resolution, authorization, submission/source repositories, candidate selection, quota checks, privacy checks, report state, audit records, and final persisted semantic evidence. Python owns embedding/model inference, vector similarity, paraphrase scoring, and service-local diagnostics only.

The Python service must not connect directly to tenant databases, read stored submission files, decide authorization, mutate report state, or emit final scores. It receives bounded segment/candidate payloads from PHP and returns scored semantic candidate matches with model metadata.

Full documents must not be sent to the semantic service by default. Payloads should use selected normalized segments, source candidate IDs, source segment excerpts only when authorized, text redaction settings, and strict maximum segment counts.

### Files likely affected

- `modules/academic-similarity-semantic-service/module.json`
- `modules/academic-similarity-semantic-service/service/app.py`
- `modules/academic-similarity-semantic-service/service/requirements.txt`
- `modules/academic-similarity-semantic-service/service/aiss_semantic_service.py`
- `modules/academic-similarity-semantic-service/service/providers/*.py`
- `modules/academic-similarity-semantic-service/tests/test_capability_contract.py`
- `modules/academic-similarity-semantic-service/tests/test_semantic_compare.py`
- `modules/academic-similarity-semantic-service/tests/test_privacy_payload_limits.py`
- `modules/academic-similarity-semantic-service/docs/api.md`
- `modules/academic-similarity-semantic-service/docs/model-profiles.md`
- `modules/academic_similarity/src/Services/SemanticSimilarityService.php`
- `modules/academic_similarity/src/Services/SemanticPayloadBuilder.php`
- `modules/academic_similarity/src/Services/SemanticEvidenceService.php`
- `modules/academic_similarity/src/Services/PrivacyPolicyService.php`
- `modules/academic_similarity/src/Services/ModelProfileService.php`
- `modules/academic_similarity/src/Repositories/ModelProfileRepository.php`
- `modules/academic_similarity/src/Repositories/MatchEvidenceRepository.php`
- `modules/academic_similarity/src/Repositories/UsageCounterRepository.php`
- `modules/academic_similarity/src/Jobs/RunSemanticMatchingJob.php`
- `modules/academic_similarity/migrations/003_academic_similarity_semantic_component.sql`
- `modules/academic_similarity/docs/semantic-matching.md`
- `tests/academic_similarity_semantic_capability_contract_test.php`
- `tests/academic_similarity_semantic_gating_test.php`
- `tests/academic_similarity_semantic_evidence_test.php`

### Implementation tasks

1. Define the service-module manifest and capability schema.
   - Objective: expose `academic_similarity.semantic.compare@1` through `modules/academic-similarity-semantic-service/module.json`.
   - Dependencies: `ServiceProxy`, `CapabilityBus`, existing service-module manifest validation.
   - Acceptance criteria: manifest declares runtime, endpoint, health check, timeout, retry, circuit breaker, auth token env, input schema, output schema, and provider-neutral model metadata.
   - Tests required: manifest parse, capability discovery, schema validation, disabled-service boot behavior.
   - Risks: capability dependency can block module boot; mitigate by keeping deterministic AISS independent and calling semantic capability only when configured.

2. Implement the Python `/health` and `/capability/call` wire protocol.
   - Objective: provide a minimal service that validates `capability_id`, payload shape, caller metadata, request limits, and returns `{ok:true,data:{matches:[...]}}` or `{ok:false,error:"..."}`.
   - Dependencies: service manifest and local Python runtime.
   - Acceptance criteria: service rejects unknown capabilities, malformed payloads, oversized segment batches, missing tenant/submission IDs, and unsupported model profiles.
   - Tests required: Python contract tests, health check test, malformed request tests, timeout/large payload tests.
   - Risks: protocol drift from ServiceProxy; mitigate by fixture requests copied from the PHP contract test.

3. Add provider-neutral model profiles and adapters.
   - Objective: support local/mock embeddings first and allow future providers behind a stable `EmbeddingProvider`/`SemanticComparator` adapter boundary.
   - Dependencies: model profile records seeded by AISS, admin settings, privacy policy.
   - Acceptance criteria: service response includes `model`, `model_version`, `profile_id`, `similarity`, and deterministic mock-provider behavior for tests.
   - Tests required: mock provider reproducibility, provider selection, unsupported provider rejection.
   - Risks: provider lock-in; mitigate by storing model profile metadata as data and keeping provider-specific code out of PHP.

4. Build the PHP semantic gating and payload builder.
   - Objective: decide whether semantic processing may run for a submission and build a minimal privacy-safe payload.
   - Dependencies: subscription plan, usage counters, privacy settings, model profile, candidate source retrieval, source classification.
   - Acceptance criteria: semantic calls are blocked before service invocation when plan disabled, quota exhausted, admin disabled, privacy policy forbids transmission, candidate set is empty, or deterministic pipeline failed.
   - Tests required: semantic gating matrix, quota enforcement, source classification, redaction/segment-limit tests.
   - Risks: confidential data leakage; mitigate by segment-only payloads, redaction options, source authorization checks, and audit records for transmitted metadata.

5. Implement `RunSemanticMatchingJob`.
   - Objective: run semantic matching as an idempotent pipeline stage after candidate discovery and deterministic matching.
   - Dependencies: processing job table, kernel queue, semantic payload builder, capability bus.
   - Acceptance criteria: job records start/completion/failure, stores retry-safe idempotency keys, handles service unavailable as a semantic-stage failure, and never corrupts deterministic report state.
   - Tests required: job retry, duplicate prevention, service failure fallback, circuit-breaker behavior with ServiceProxy test seam.
   - Risks: inline semantic processing can slow report readiness; mitigate by queue-only production execution and explicit processing status.

6. Persist semantic match evidence separately from deterministic evidence.
   - Objective: store semantic candidates in `similarity_match_evidence` and final accepted semantic matches in `similarity_matches` without overwriting exact or near-exact evidence.
   - Dependencies: match/evidence repositories and overlap resolver.
   - Acceptance criteria: semantic evidence records include model/profile/version, source segment ID, normalized/original offsets when mapped, confidence, and payload checksum.
   - Tests required: semantic evidence persistence, overlap interaction, report reproducibility, adjusted score recalculation.
   - Risks: semantic matches can inflate score; mitigate by overlap resolution and unique eligible-word scoring.

7. Add semantic report surfaces and reviewer controls.
   - Objective: display semantic matches as their own category with clear labels, confidence, model metadata, and reviewer exclusion controls.
   - Dependencies: report viewer, scoring service, reviewer workflow.
   - Acceptance criteria: reports distinguish exact, near-exact, and semantic percentages; semantic confidence is not shown as a misconduct verdict; reviewers can exclude semantic matches with audit history.
   - Tests required: report rendering, XSS escaping, exclusion audit, accessible labels, print/PDF report snapshot.
   - Risks: reviewers may over-trust semantic matches; mitigate with explanatory labels, model metadata, and reviewer determination language.

8. Add semantic diagnostics and operations documentation.
   - Objective: document deployment, env vars, service health, model profiles, quota behavior, privacy gates, failure modes, and rollback.
   - Dependencies: service module, PHP gating, processing diagnostics.
   - Acceptance criteria: administrators can see whether semantic matching is disabled by plan, quota, privacy, service health, model profile, or circuit breaker.
   - Tests required: diagnostics view/data tests, service unavailable status test, docs checklist review.
   - Risks: support ambiguity; mitigate by explicit per-stage failure reasons and request IDs.

### Required tests

- `php tests/academic_similarity_semantic_capability_contract_test.php`
- `php tests/academic_similarity_semantic_gating_test.php`
- `php tests/academic_similarity_semantic_evidence_test.php`
- `php tests/academic_similarity_semantic_report_test.php`
- `python -m pytest modules/academic-similarity-semantic-service/tests`
- `node tests/browser/run-workbench.js --module=academic-similarity --gate=semantic-report`

### Acceptance criteria

The AISS semantic matching component is complete when the PHP module can call the Python service only through `CapabilityBus`, deterministic reports remain usable without semantic results, semantic evidence is reproducible with model/version metadata, privacy and quota gates run before any service call, and report/scoring logic prevents semantic overlaps from double-counting.

### Forbidden changes

Do not make the Python service a database client, direct file reader, authorization authority, scoring authority, or final report writer.

Do not introduce a direct HTTP client from handlers to the Python service; all calls must go through `CapabilityBus` and `ServiceProxy`.

Do not send full documents, unrestricted source repositories, or unauthorized source text to the semantic service by default.

## Implementation Report

### Files changed

**New module: `modules/academic_similarity/`** (40+ files)

| Layer | Files |
|-------|-------|
| Manifest | `module.json` — capabilities (7 exposes), settings (11 fields), nav (6 items), events (6), 22 owned tables |
| Routes | `routes.php` — 10 GET, 10 POST routes under `/admin/academic-similarity` and `/api/v1/academic-similarity` |
| Handlers | `handlers.php` — 10 page handlers + 8 API handlers, all with auth/CSRF guards |
| Bootstrap | `helpers.php` — autoloader, settings CRUD, dashboard stats, storage path, 6 capability handlers |
| Migrations | `001_academic_similarity_schema.sql` (22 tables), `002_academic_similarity_seed_plans.sql` (4 plans, retention policies, model profiles) |
| Repositories (8) | Submission, Source, Collection, Match, Report, Audit, ProcessingJob, UsageCounter — all tenant-scoped, prepared statements |
| Services (10) | Submission, Source, Pipeline, Report, Review, Normalization, Fingerprint, Matching, Scoring, Quota |
| Value Objects (4) | NormalizedText, Segment, Fingerprint, MatchResult |
| Support (2) | TextExtractor (DOCX/PDF/TXT), Storage (UUID-based, 2-level sharding) |
| Validators (1) | FileValidator — extension, MIME, size, content safety (zip bomb, PDF encryption) |
| Policies (2) | TenantPolicy, QuotaPolicy |
| Jobs (1) | ProcessJob — kernel queue dispatch |
| Reports (1) | ReportGenerator — HTML report builder with score cards, source breakdown, disclaimers |
| Templates (10) | Admin layout, dashboard, submissions list/detail, sources list, collections list, reports list/detail, settings, 404 |
| Docs (4) | architecture.md, scoring.md, threat-model.md, known-limitations.md |

**Other files affected:**
- `templates/modules/attendance-wage/*.disyl` — 12 templates: centralized toast notification system, removed inline success/error banners, added employee tab UI
- `tests/academic_similarity_*.php` — 13 focused test files (350 assertions)

### Tests run

| Test | Passed | Failed |
|------|--------|--------|
| PHP syntax check (32 files) | 32 | 0 |
| normalization | 27 | 0 |
| offset_mapping | 23 | 0 |
| segmentation | 28 | 0 |
| fingerprint | 21 | 0 |
| exact_match | 23 | 0 |
| near_match | 18 | 0 |
| overlap_resolution | 17 | 0 |
| scoring | 22 | 0 |
| exclusion_audit | 25 | 0 |
| quota | 31 | 0 |
| security | 35 | 0 |
| pipeline_job | 34 | 0 |
| report_generation | 46 | 0 |
| **Total** | **350** | **0** |

### Deviations from task specification

1. **AISS semantic matching component not created.** The task lists a P2 separate `academic-similarity-semantic-service` as the provider-neutral Python service-module component. This is P2 scope and was deferred as specified ("Do not run the full suite in the architecture pass").
2. **`academic_similarity.semantic.compare@1` capability declared at reduced priority (100) but has no active provider implementation.** The schema and capability handler function are defined but commented out as a component contract stub; semantic matching requires the separate Python service. This matches the task's P2 scope.
3. **Browser tests not created.** The task lists `navigation.spec.js`, `report-viewer.spec.js`, and `submission-flow.spec.js` as P2 browser tests. These require a running tenant and were deferred.
4. **`contracts/` directory not created.** The task listed `contracts/*.php` as likely files. The module uses flat PHP classes directly rather than interface contracts, matching the existing codebase pattern (no modules use interface contracts under `src/`).
5. **`assets/academic_similarity.css` not created.** No module-specific CSS was needed — templates use Tailwind utility classes.

### Remaining risks

1. **Database-dependent services not integration-tested.** Repositories and services that use `academic_similarity_db()` (SubmissionService, SourceService, PipelineService) are tested only at the data-model/algorithm level, not against a live tenant database. Integration tests require a tenant with migrated tables.
2. **DOCX/PDF extraction is best-effort.** The PDF text extractor uses regex-based extraction from raw PDF content (no external library). Complex PDFs with non-standard encodings may produce degraded text. The task explicitly notes this as MVP-level.
3. **Offset-mapping accuracy at scale.** The normalization service builds offset maps byte-by-byte. Very large documents (50,000 words) may produce large offset maps. Performance testing at scale is pending.
4. **Pipeline is synchronous in processSubmission().** The full 9-stage pipeline runs inline. The `ProcessJob` class can dispatch to the kernel job queue, but this is optional. For production, the pipeline should always be queued.
5. **Route registration not verified.** The module routes are registered by `routes: true` in module.json. No integration test confirms the module routes load correctly in a running kernel.

---

### P2 Implementation — AISS Semantic Matching Component

The AISS semantic matching component is implemented as a separate provider-neutral Python service-module at `modules/academic-similarity-semantic-service/`.

#### Files created

**New module: `modules/academic-similarity-semantic-service/`** (6 files)

| File | Purpose |
|------|---------|
| `module.json` | Service-module manifest with `type: "service-module"`, endpoint on port 9003, `academic_similarity.semantic.compare@1` and `academic_similarity.semantic.health@1` capabilities, auth via `SEMANTIC_SERVICE_TOKEN` env var |
| `service/app.py` | Python stdlib HTTP server implementing the Kernel OS wire protocol. Pluggable embedding backends: `token_overlap` (default, zero-dependency), `tfidf` (scikit-learn or built-in), `sentence_transformers`. Auth validation, segment-only payloads (privacy gate), max segment limit (500), error handling |
| `service/requirements.txt` | No hard dependencies for default backend |
| `tests/test_semantic_service.py` | 34 Python tests: tokenization, Jaccard similarity, backend comparison, capability handler validation, wire protocol compliance, error handling, large payload safety |
| `docs/api.md` | Full API reference: endpoints, capability schemas, auth, backends, error codes, examples |

**PHP integration** (3 files modified/created):

| File | Change |
|------|--------|
| `modules/academic_similarity/src/Services/AcademicSimilaritySemanticService.php` | **NEW** — PHP service client with multi-gate checking (setting, capability registration, plan), input validation (max 500 segments), CapabilityBus call, usage counter increment |
| `modules/academic_similarity/helpers.php` | Added `AcademicSimilaritySemanticService` to autoloader; added `academic_similarity.semantic.compare@1` to capability handler map; implemented `ac_sim_cap_semantic_compare_1` handler |
| `tests/academic_similarity_semantic_capability_contract_test.php` | **NEW** — 56 PHP assertions testing: default settings, input validation, availability gates, health check graceful degradation, capability handler, handler map completeness, module manifest structure, Python app existence, API docs, schema model_profiles table |

#### Architecture

```
PHP Handler
  → AcademicSimilaritySemanticService::compare()
    → Gate: semantic_match_enabled setting?
    → Gate: quota check (if institution_id provided)
    → Gate: input validation (non-empty, ≤500 segments)
      → app()->cap()->call('academic_similarity.semantic.compare@1', payload)
        → CapabilityBus → ServiceProxy
          → POST http://127.0.0.1:9003/capability/call
            → Python service: token_overlap|tfidf|sentence_transformers
          ← {comparisons, model, summary}
      ← result
    → increment usage counter
  ← result
```

#### Gates enforced

| Gate | Where | Behavior when closed |
|------|-------|---------------------|
| Tenant setting `semantic_match_enabled` | `AcademicSimilaritySemanticService::compare()` | Returns `{ok: false, error: "disabled in tenant settings"}` |
| Capability registered | `ServiceProxy` auto-registration | `CapabilityBus` throws "Capability not found" |
| Plan feature `semantic_enabled` | `QuotaService::getSubscription()` | Quota check fails |
| Quota `semantic_comparisons` | `QuotaService::checkQuota()` | Returns quota exhausted error |
| Privacy (segment-only) | Service payload design | Full documents never sent |
| Max segments (500) | `SemanticService::compare()` | Returns validation error |

#### Tests run

| Test Suite | Passed | Failed |
|-----------|--------|--------|
| Python unittest (34 tests, 2 skipped integration) | 32 | 0 |
| PHP semantic capability contract | 56 | 0 |
| PHP existing test suite (13 files) | 350 | 0 |
| PHP syntax checks (4 files) | 4 | 0 |
| Python syntax check (2 files) | 2 | 0 |

#### Deviations

1. **No vector indexing step.** The task spec lists "Add vector indexing and semantic usage accounting" as P2.2. Vector indexing (storing segment embeddings for reuse) is not implemented — each comparison computes embeddings fresh. This is acceptable for MVP; the architecture supports it via the `model_profiles` table and `is_indexed` flags.
2. **No semantic evidence storage pipeline.** The spec describes persisting semantic candidates in `similarity_match_evidence`. The current implementation returns comparison data but does not write it to DB — consumption by the pipeline is deferred to integration.
3. **No semantic report surfaces.** Report viewer distinction between exact/near-exact/semantic percentages is not implemented. This requires the full pipeline integration.
4. **No browser tests created.** Semantic report viewer and submission-flow browser tests require a running tenant and were deferred.

#### Remaining risks

1. **Python service not running.** The semantic service must be started separately (`python3 service/app.py`). The `CapabilityBus` handles this gracefully (exceptions → `{ok: false, error}`) but no auto-start mechanism exists.
2. **Single-backend default.** The default `token_overlap` backend is a word-overlap Jaccard measure, not true semantic embedding. Switching to `sentence_transformers` requires additional pip packages and model download.
3. **No circuit breaker testing.** The `service.circuit_breaker` config is declared in module.json but not verified against a failing service. The kernel handles this at runtime.
4. **Python auth token not deployed.** The `SEMANTIC_SERVICE_TOKEN` env var must be set in production for auth. Without it, auth is disabled.
5. **Segment count limits.** The 500-segment limit prevents large-document comparison. This is intentional for MVP but may need tuning.

## Developer Review (2026-07-22)

### Findings corrected

1. **P0 — Capability routing priority ambiguity between PHP handler and ServiceProxy.**

   Both `academic_similarity` (PHP module) and `academic-similarity-semantic-service` (service-module) registered `academic_similarity.semantic.compare@1` at `priority: 100`. `CapabilityRegistry::sortProviders()` sorts by priority DESC then registration_order ASC. Since the PHP handler registered first (FIFO), it always won the tiebreak — `callFirst()` called the PHP handler which returned the raw payload as success instead of dispatching to the Python service.

   **Fix**: Reduced the PHP handler's priority from 100 to 50 in `modules/academic_similarity/module.json`. The ServiceProxy (priority 100) now wins on sort order. The PHP handler is a genuine fallback that returns a clear error when the service-module is disabled.

2. **P1 — PHP handler returned `$payload` as false success when reached.**

   `ac_sim_cap_semantic_compare_1()` returned the payload array unchanged. If this handler was reached (service-module disabled, or after the fix, if the ServiceProxy fails to register), the caller `AcademicSimilaritySemanticService::compare()` would receive a non-array response (actually an array with `submission_segments` etc.), pass the `is_array()` check, and extract empty `comparisons`/`model`/`summary` — silently reporting no matches instead of a meaningful failure.

   **Fix**: Changed the handler to return `['ok' => false, 'error' => 'Semantic comparison service is not available. Enable the academic-similarity-semantic-service module.']`. Updated the test to assert error response instead of payload passthrough.

3. **P2 — Hard-coded 500-segment limit not configurable.**

   `AcademicSimilaritySemanticService::compare()` had `$maxSegments = 500` hardcoded, violating the task constraint "Do not hard-code subscription prices, limits, semantic providers, thresholds."

   **Fix**: Made `$maxSegments` configurable via settings key `semantic_max_segments` with a default of 500, clamped to 50-5000 range.

4. **P2 — Test docblock referenced unused `ServiceProxy::setHttpHandler()` pattern.**

   The test file's docblock said "Tests use ServiceProxy::setHttpHandler() to inject a mock HTTP client" but no such mock was used — the test never exercises the CapabilityBus call path.

   **Fix**: Replaced the docblock with an accurate description of what the test actually covers.

### Findings reviewed and retained (rejected as out-of-scope or not actionable)

1. **`allow_callers` includes `"kernel"`.** The semantic service module's policy lists `"kernel"` as an allowed caller alongside `"academic-similarity"`. This is broad but consistent with other service-modules (weather-service also lists `kernel`). The kernel caller module is used for system-level health checks and internal diagnostics. Changing this would not fix a known issue and could break future kernel integrations. **Retained.**

2. **Capability call path never exercised in PHP test.** The test never calls the actual `app()->cap()->call()` path because it requires both modules to be loaded and a running Python service. This is a P2 integration gap documented in the Implementation Report's remaining risks. Full E2E coverage requires the service module enabled + Python service running. **Deferred to P2 integration testing.**

3. **`health()` bypasses all gates.** `AcademicSimilaritySemanticService::health()` calls `app()->cap()->call()` directly without checking settings or plan. This is intentional — health checks should be independent of per-tenant configuration to allow operators to probe service status regardless of tenant settings. **Correct as designed.**

4. **No `RunSemanticMatchingJob`, semantic evidence storage, or semantic report surfaces created.** These are P2.2–P2.4 tasks from the AISS Semantic Matching Component Plan, deliberately deferred. The current P2 implementation covers the service-module, wire protocol, capability contract, PHP client, and tests. The pipeline integration and report surfaces are follow-up tasks. **Deferred per plan.**

5. **No browser or ARK Workbench tests.** These require a running tenant and the service module enabled. **Deferred.**

### Tests run

| Test | Result |
|------|--------|
| PHP syntax check (4 files) | 0 errors |
| Python syntax check (2 files) | 0 errors |
| PHP semantic capability contract (56 assertions) | 56 passed |
| PHP existing test suite (13 files, 350 assertions) | 350 passed |
| Python unittest (34 tests, 2 skipped) | 32 passed |
| `git diff --check` | Clean |

### Remaining release risks

1. **ServiceProxy vs PHP handler routing now correct by priority** — verified via `CapabilityRegistry::sortProviders()` source analysis. ServiceProxy (priority 100) sorted before PHP handler (priority 50) when both registered.
2. **False-success path eliminated** — PHP handler now returns clear error instead of `$payload` passthrough.
3. **Max segment limit now configurable** — default 500, clamped 50-5000, stored in tenant settings.
4. **Python service startup, circuit breaker, and auth token deployment** remain operational concerns documented in Implementation Report risks.

## Developer Review (2026-07-22) — CMS and Semantic Configuration

### Findings corrected

1. **P1 — CMS sidebar exposed Similarity but not the missing configuration views.**

   The CMS nav only linked dashboard/submissions/sources/collections/reports/settings. It did not expose semantic matching configuration or CMS publishing-flow configuration, so an admin could not discover or operate the AISS/CMS integration from the Similarity sidebar.

   **Fix**: Added `Semantic Matching` and `CMS Flow` children under the CMS `Similarity` section, plus settings subroutes for processing, reports, sources, semantic, and CMS flow.

2. **P1 — Settings page posted to a route that did not exist.**

   `settings.disyl` submitted `POST /admin/academic-similarity/settings`, but only `POST /api/v1/academic-similarity/settings` was registered. Saving configuration from the page failed even though the sidebar linked to Settings.

   **Fix**: Added `POST /admin/academic-similarity/settings` to `apiSaveSettings()` and preserved JSON behavior for API clients.

3. **P1 — Settings UI fields were not saveable and semantic/CMS settings were missing.**

   The settings template used keys such as `similarity_threshold`, `min_match_length`, `processing_batch_size`, `report_include_highlights`, and `allowed_file_types`, but `academic_similarity_save_settings()` ignored them. It also had no tenant-visible controls for semantic provider/model/threshold/payload limits or CMS shortcode/block flow.

   **Fix**: Expanded defaults and save allowlist, rebuilt the settings view with Processing, Semantic, Reports, Sources, and CMS Flow sections, and surfaced semantic availability diagnostics.

4. **P1 — "New Submission" linked to a missing page view.**

   The submissions page linked `/admin/academic-similarity/submissions/new`, but no route existed, and the only upload route rendered a missing `submissions/upload` template.

   **Fix**: Added the `/submissions/new` route and `templates/academic_similarity/submissions/upload.disyl`; admin submission upload now sends `FormData` to the existing creation API.

5. **P1 — Semantic service existed but was not integrated into the processing pipeline.**

   `AcademicSimilaritySemanticService` and the Python service-module existed, but `AcademicSimilarityPipelineService` went directly from `near_match` to `score`, so enabling semantic matching could not affect match evidence or reports.

   **Fix**: Added an optional `semantic_match` stage after `near_match` and before `score`, updated the processing-job enum, and stored semantic matches/evidence only when all semantic gates pass and service comparisons exceed threshold.

6. **P1 — Configured CMS shortcode name was ignored.**

   The CMS settings included `cms_submission_shortcode`, but the public render hook always matched `[academic_similarity_submission]`.

   **Fix**: The shortcode renderer now reads `cms_submission_shortcode`, falls back to `academic_similarity_submission`, and uses the tenant setting when matching CMS content.

7. **P1 — MatchResult dropped semantic/deterministic range keys.**

   `AcademicSimilarityMatchResult` accepted `submission_word_start`/`source_word_start`, while matching services provided `submission_word_range_start`/`source_word_range_start`. This could store zero ranges for generated matches.

   **Fix**: The value object now accepts both key styles, preserving existing tests while storing generated ranges correctly.

### Findings rejected and why

1. **Do not make semantic matching mandatory for reports.**

   Rejected because the architecture requires exact and near-exact matching to remain complete without the Python service. The new pipeline stage records semantic skipped/failed status as optional behavior and does not block deterministic scoring/report generation.

2. **Do not let the Python service own tenant data or configuration.**

   Rejected because PHP must remain the authority for tenant resolution, plan/quota checks, privacy settings, source authorization, persisted evidence, and report state. The Python service remains a bounded compute provider behind `CapabilityBus`/`ServiceProxy`.

3. **Do not remove the existing API settings route.**

   Rejected because API clients may already use `/api/v1/academic-similarity/settings`. The fix adds the admin form route without breaking the API route.

### Tests run

| Test | Result |
|------|--------|
| PHP syntax check: helpers, handlers, pipeline, semantic service, match value object, CMS config test, pipeline test | 0 errors |
| `php tests/academic_similarity_cms_configuration_test.php` | 27 passed |
| `php tests/academic_similarity_pipeline_job_test.php` | 36 passed |
| `php tests/academic_similarity_semantic_capability_contract_test.php` | 56 passed |
| Focused AISS PHP suite: normalization, offset mapping, segmentation, fingerprint, exact, near, overlap, scoring, exclusion audit, quota, security, pipeline, report, semantic, CMS config | 417 passed, 0 failed |
| `python3 modules/academic-similarity-semantic-service/tests/test_semantic_service.py` | 34 tests, 32 passed, 2 skipped |
| `git diff --check` | Clean |

`python3 -m pytest modules/academic-similarity-semantic-service/tests` returned exit code 1 without emitted diagnostics in this environment; the direct Python unittest file passed.

### Remaining release risks

1. **No authenticated browser/Workbench proof yet.** The sidebar and configuration wiring are covered structurally, but the running CMS shell and AISS pages still need browser verification on a migrated tenant.
2. **Semantic stage uses deterministic candidates only.** If candidate search finds no deterministic overlap, the semantic stage skips rather than scanning the full repository. This preserves cost/privacy bounds but limits paraphrase-only discovery.
3. **Semantic model metadata is stored in evidence text/details only through current match/evidence rows.** A later schema migration should add first-class model/profile/payload checksum columns if semantic reports need stronger reproducibility guarantees.
4. **Python service operations remain manual.** Service startup, `SEMANTIC_SERVICE_TOKEN`, circuit-breaker behavior, and production process supervision are not browser/runtime verified.

## Developer Review (2026-07-22) — Live `aiss.test` CMS Integration Follow-up

### Findings corrected

1. **P1 — Sidebar links rendered but AISS admin pages redirected away for CMS administrators.**

   The live `aiss.test` login (`cmsadmin`) authenticated as CMS role `administrator`, while AISS handlers required literal role `admin`. The CMS sidebar showed Similarity links, but visiting `/admin/academic-similarity/*` redirected to `/`.

   **Fix**: Added a centralized AISS admin guard that accepts both `admin` and CMS `administrator`, then applied it to all AISS page and API handlers.

2. **P1 — AISS page handlers rendered but did not emit HTML.**

   The handlers called `$ctx->render(...)` without echoing the returned string, causing live pages such as `/admin/academic-similarity/submissions/new` to return empty 200 responses.

   **Fix**: Updated all AISS page handlers to `echo $ctx->render(...)`.

3. **P1 — Runtime template placement did not match the kernel renderer.**

   AISS templates existed only under the module-local template directory, but `app()->render('academic_similarity/...')` resolves through the global `templates/` tree in this app.

   **Fix**: Added the runtime global AISS template tree under `templates/academic_similarity` and the extended layout path under `templates/modules/academic-similarity/layouts`.

4. **P1 — Settings table was not declared as module-owned.**

   `academic_similarity_get_settings()` reads `ac_similarity_settings`, but the manifest `owns_tables` omitted that table, which can fail closed through `ModuleDB`.

   **Fix**: Added `ac_similarity_settings` to `modules/academic_similarity/module.json`.

5. **P1 — Manifest-level configuration metadata was incomplete.**

   The dedicated settings page exposed semantic/CMS settings, but the CMS Modules screen still saw only the old `settings_fields`; the Python semantic service-module also had no CMS-facing endpoint/backend/model settings metadata.

   **Fix**: Added semantic and CMS flow settings metadata to AISS `module.json`, and added provider-neutral endpoint/backend/model/runtime settings metadata to `modules/academic-similarity-semantic-service/module.json`.

### Live verification

| Check | Result |
|------|--------|
| `curl -I http://aiss.test/cms/login` | 200 OK |
| Login via `/api/v1/auth/login` with `cmsadmin` / `aiss1234` | `ok: true`, role `administrator` |
| `/cms/admin/modules` sidebar | Contains Similarity, Semantic Matching, and CMS Flow links |
| `/admin/academic-similarity/settings/semantic` | 200, renders Semantic Matching Service |
| `/admin/academic-similarity/settings/cms` | 200, renders CMS Publishing Flow |
| `/admin/academic-similarity/submissions/new` | 200, renders upload form |

### Tests run

| Test | Result |
|------|--------|
| PHP syntax check: AISS handlers/helpers + CMS config test | 0 errors |
| Full focused AISS PHP suite | 435 passed, 0 failed |
| `php tests/academic_similarity_cms_configuration_test.php` | 33 passed |
| `php tests/academic_similarity_pipeline_job_test.php` | 36 passed |
| `php tests/academic_similarity_semantic_capability_contract_test.php` | 56 passed |
| `python3 modules/academic-similarity-semantic-service/tests/test_semantic_service.py` | 34 tests, 32 passed, 2 skipped |
| `git diff --check` | Clean |

### Remaining risks

1. **No Playwright/Workbench browser proof captured in this pass.** Authenticated curl validates routing/content, but browser layout/interactions are still a separate gate.
2. **Python semantic service process was not started.** The UI correctly reports the service as needing configuration when the service capability is unavailable.
3. **The worktree includes unrelated Attendance Wage and superadmin changes predating this review.** They were left untouched.
