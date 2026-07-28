# AISS Capability Contracts — Stable Interface Reference

**Status**: Proposed (2026-07-28)
**Module**: `academic_similarity`
**Version**: Contracts target `@1` stability

## Contract Design Rule

> Capability IDs are immutable once declared stable. New behavior requires a version bump (`@2`, `@3`). Consumers declare which version they depend on. The capability bus routes to the highest-priority provider matching the requested version.

## Evidence Acquisition Contracts

### `academic_similarity.textual.match@1`

Submit a document for textual similarity analysis. Returns exact and near-exact match results with offset-accurate highlight spans.

```
Input:  { institution_id, submission_title, file_content (base64), filename, source_type }
Output: { submission_id, match_count, matches: [{ source, overlap_pct, highlights }] }
Maturity: stable
```

### `academic_similarity.semantic.resemblance@1`

Compare a submission against sources for conceptual similarity (meaning overlap without lexical matching).

```
Input:  { submission_id, threshold?, max_segments? }
Output: { segments_compared, resemblance_matches: [{ source, score, matched_passage }] }
Maturity: experimental
```

### `academic_similarity.citation.analysis@1`

Analyze passages for citation and attribution signals — detects presence/absence of citations, quotation marks, paraphrasing markers.

```
Input:  { submission_id, passage_ids? }
Output: { passages: [{ id, attribution_status, signals_detected, confidence }] }
Maturity: beta
```

## Scholarship Analysis Contracts

### `academic_similarity.context.analysis@1`

Classify the scholarly relationship between two passages (shared method, common knowledge, etc.).

```
Input:  { submission_id, match_id? }
Output: { relationships: [{ match_id, classification, confidence, evidence_summary }] }
Maturity: experimental
```

### `academic_similarity.scholarship.profile@1`

Generate a multi-dimensional evidence distribution profile across a document.

```
Input:  { submission_id }
Output: { observed_evidence_distribution: { textual_matches, citations, paraphrases, ... },
          maturity: { textual_matching: "stable", ... } }
Maturity: experimental
```

### `academic_similarity.lineage.graph@1`

Build a knowledge lineage graph showing source relationships.

```
Input:  { submission_id, format?: "json"|"mermaid" }
Output: { nodes: [...], edges: [...], mermaid?: string }
Maturity: experimental
```

## Reviewer Assistance Contracts

### `academic_similarity.reviewer.attention@1`

Generate prioritized reviewer attention flags based on machine evidence.

```
Input:  { submission_id, threshold? }
Output: { attention_items: [{ match_id, level, reason, suggested_action }] }
Maturity: experimental
```

## Internals (not for external consumption)

These contracts are internal to AISS and not part of the stable public API:

| Capability | Purpose | Stability |
|---|---|---|
| `academic_similarity.submit@1` | Internal document ingestion | Internal |
| `academic_similarity.check@1` | Trigger processing pipeline | Internal |
| `academic_similarity.match.exact@1` | Raw exact matching | Internal |
| `academic_similarity.match.near@1` | Raw near-exact matching | Internal |
| `academic_similarity.report.view@1` | Internal report retrieval | Internal |
| `academic_similarity.review.exclude@1` | Match exclusion | Internal |
| `academic_similarity.internet.discover@1` | Internet source discovery | Internal |
| `academic_similarity.review.workflow.action@1` | Workflow state change | Internal |

## Consumer Contract

Every AISS consumer module must:

1. Call capabilities through the capability bus (`app()->capabilities()->call(...)`)
2. Record the capability ID and version in evidence snapshots
3. Never interpret AISS output as a decision (passed/failed/plagiarism)
4. Store machine output and human interpretation in separate columns
5. Handle AISS unavailability gracefully (non-blocking)
6. Display feature maturity metadata to reviewers

## ATE Capability Usage

ATE (`academic_thesis_evaluation`) currently consumes:

| AISS Capability | ATE Adapter Call | Status |
|---|---|---|
| `academic_similarity.submit@1` | Submission + check pipeline | Active |
| `academic_similarity.report.view@1` | Textual matching results | Active |
| `academic_similarity.context.analyze@1` | Passage relationship classification | Active |
| `academic_similarity.semantic.compare@1` | Semantic resemblance | Active |
| `academic_similarity.citation.analyze@1` | Citation detection | Pending registration fix |
| `academic_similarity.scholarship.profile@1` | Evidence distribution | Pending registration fix |
| `academic_similarity.lineage.graph@1` | Knowledge lineage | Pending registration fix |

Once stable contracts are declared, the ATE adapter should migrate from the internal capability IDs to the stable public contracts.
