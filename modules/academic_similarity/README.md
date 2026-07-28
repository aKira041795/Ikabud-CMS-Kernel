# Academic Integrity & Scholarship Intelligence System (AISS)

Secure academic document submission and similarity detection system. Provides deterministic exact/near-exact matching via rolling-hash fingerprinting and Smith-Waterman local alignment, with optional semantic comparison and internet-assisted source discovery.

> **AISS does not determine plagiarism or academic misconduct. It provides diagnostic evidence to support qualified human review.**
>
> This principle governs the scoring architecture, terminology, data model, AI prompts, reports, and workflow. No single percentage, score, or automated classification in this system constitutes a finding of misconduct. All evidence requires qualified human interpretation within the institution's academic integrity policy.

## Architecture

- **Pipeline**: extract → normalize → segment → fingerprint → candidate search → exact match → near match → semantic match → score → report
- **Matching**: rolling-hash shingles (multi-layer), Jaccard similarity (≥0.80 threshold), Smith-Waterman affine-gap alignment
- **Scoring**: `matched_unique_eligible_words / total_unique_eligible_words` with reviewer exclusion
- **Tenant isolation**: Every table carries `tenant_id`; all repositories filter by it

## Capabilities

Submit, check, match, report, review, semantic compare, internet discover — all exposed through the kernel capability bus with JSON schema validation.

## Documentation

- [Architecture](docs/architecture.md)
- [Scoring algorithm](docs/scoring.md)
- [Known limitations](docs/known-limitations.md)
- [Threat model](docs/threat-model.md)

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers: [`handlers.php`](handlers.php)
- Pipeline service: [`src/Services/AcademicSimilarityPipelineService.php`](src/Services/AcademicSimilarityPipelineService.php)
- Matching service: [`src/Services/AcademicSimilarityMatchingService.php`](src/Services/AcademicSimilarityMatchingService.php)

## Dependencies

- `cms` — for admin UI integration
- Python semantic service (optional): [`academic-similarity-semantic-service`](../academic-similarity-semantic-service/)

## MySQL 5.7 compatible

All 8 migrations use `ENGINE=InnoDB`, no window functions, no CTEs.
