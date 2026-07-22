# Academic Similarity — Architecture Overview

## Purpose

The Academic Similarity module provides secure document submission and deterministic similarity detection for academic institutions. It detects exact and near-exact textual overlap between student submissions and an institutional corpus of source documents. The system produces reproducible similarity reports with offset-accurate highlighting and supports reviewer-driven match exclusions. Every operation is tenant-isolated and fully audited.

## System Components

The module is organized into a service layer with clearly separated responsibilities.

**Submission Service** — Accepts uploaded or pasted documents, validates file type and size against configurable limits, extracts raw text, and persists the initial submission record.

**Normalization Service** — Transforms extracted text into a canonical form: lowercasing, whitespace normalization, punctuation stripping, and stop-word filtering. Produces a `NormalizedText` value object that is stored as a snapshot for reproducibility.

**Fingerprint Service** — Splits normalized text into overlapping shingles (configurable size, default 5 words) and computes a rolling hash for each shingle. Fingerprints are stored in `ac_similarity_fingerprints` keyed to their source segment.

**Matching Service** — Runs two passes. The exact-match pass compares submission fingerprints against source fingerprints using direct hash lookup. The near-exact match pass uses a configurable similarity threshold (default 0.80) to detect partial overlaps via Jaccard similarity on fingerprint sets. Results are stored in `ac_similarity_matches` with per-match offset evidence in `ac_similarity_match_evidence`.

**Scoring Service** — Computes similarity scores based on unique eligible word coverage. See scoring.md for methodology.

**Report Service** — Assembles match data into a structured similarity report. Reports are stored in `ac_similarity_reports` and can be downloaded or viewed through the admin UI.

**Review Service** — Allows reviewers to exclude false-positive matches from scoring. Exclusions are recorded in `ac_similarity_exclusions` with a reason and note, and trigger an `academic_similarity.review.excluded` event.

**Quota Service** — Tracks per-institution usage via `ac_similarity_usage_counters` and emits quota warning or exhaustion events.

**Pipeline Service** — Orchestrates the end-to-end processing workflow: normalize, fingerprint, match (exact then near), score, and generate report. Each stage is independently callable via capability handlers.

## Data Flow

A submission moves through six stages:

1. **Submission** — The user uploads or pastes a document. The submission service validates the input (format, size, word count bounds), persists the record with status `pending`, and emits `academic_similarity.submission.created`.

2. **Normalization** — Extracted text is normalized into a canonical form. The normalized version is stored in `ac_similarity_text_versions` for audit reproducibility.

3. **Fingerprinting** — The normalized text is segmented into overlapping shingles. Each shingle is hashed and stored in `ac_similarity_fingerprints`.

4. **Matching** — Fingerprints are compared against all active source fingerprints within the same tenant. Exact matches are identified first; near-exact matches follow. Matches and their offset evidence are persisted.

5. **Scoring** — The scoring service computes the raw and adjusted similarity score. The report is generated from the scored matches.

6. **Reporting** — A structured report is stored in `ac_similarity_reports`. The submission status transitions to `processed`. The event `academic_similarity.report.generated` is emitted.

## Tenant Isolation Model

Every data table in the module carries a `tenant_id` column. All repositories accept a tenant identifier at construction time and filter every query by that identifier. The pipeline services derive the tenant from the authenticated request context via `app()->tenant()->current()`. No cross-tenant data leakage is possible at the query level — a submission in tenant A is never compared against sources in tenant B.

Settings are also tenant-scoped. The `ac_similarity_settings` table uses a composite primary key of `(tenant_id, setting_key)`, and the settings functions in helpers.php merge per-tenant overrides into a global default set.

## Key Design Decisions

**Deterministic matching first.** Exact and near-exact matching are enabled by default and run entirely within the local database. Semantic similarity (via external ML service) is available as an optional capability with lower priority, disabled by default.

**Tenant-scoped comparison corpus.** Sources are managed per-tenant. An institution's similarity repository includes only documents it has explicitly uploaded or provisioned. Cross-tenant source sharing is not supported in the MVP.

**Reproducible pipeline.** Each stage is independently invocable via capability handlers (`academic_similarity.submit@1`, `academic_similarity.match.exact@1`, etc.). Normalized text and fingerprints are persisted so reports can be regenerated without re-extraction.

**Capability-based routing.** All operations are exposed as kernel capabilities with declared JSON schemas. Route handlers (routes.php) delegate to thin HTTP handlers (handlers.php) that validate auth, enforce CSRF, and call the corresponding capability or service.

**Audit trail.** Every processing event is written to `ac_similarity_audit_events` and the kernel audit log. The module depends on the `kernel.audit.record@1` capability for system-wide audit consistency.
