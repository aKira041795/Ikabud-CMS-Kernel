# Academic Similarity — Scoring Methodology

> **AISS does not determine plagiarism or academic misconduct. It provides diagnostic evidence to support qualified human review.**
>
> This principle governs the scoring architecture. No single percentage, score, or automated classification constitutes a finding of misconduct.

## Overview

The scoring service computes similarity across two independent families:

1. **Textual Overlap Score** — exact and near-exact word matches only. This is the primary institutional metric.
2. **Semantic Resemblance** — topic-level similarity from AI-based comparison. This is a separate signal and never enters the textual score.

A third categorical indicator, the **Reviewer Attention Level**, replaces any additive combined percentage. It is a triage indicator (None/Low/Moderate/High) derived from transparent evidence rules, not a similarity metric.

## Score Families

### Textual Overlap Score

```
textual_overlap = unique_eligible_words_covered_by_exact_and_near_exact / total_eligible_words
```

This is the primary institutional metric. It measures unique word coverage from deterministic exact and near-exact matches only. Semantic matches, regardless of confidence, never alter this score.

**Deduplication is critical.** If a five-word phrase appears three times in the same submission but matches a source only once, it contributes exactly five words to the numerator — not fifteen. This prevents artificially high scores from repeated self-citations or boilerplate headings.

### Semantic Resemblance

```
semantic_resemblance = Σ(match_word_count × 0.2) / total_words × 100
```

A standalone score representing topic-level similarity. Uses a fixed 0.2 weight with no contiguous bonus or diversity factor. Displayed separately from the textual score — it is NOT textual copying evidence.

### Reviewer Attention Level

Categorical triage indicator (not a percentage):

| Level | Triggers |
|---|---|
| **High** | Textual overlap >25%, OR textual overlap >0% with ≥3 strong semantic matches |
| **Moderate** | Textual overlap >10%, OR ≥5 strong semantic matches, OR textual overlap >0% with ≥2 strong semantic matches |
| **Low** | Any textual overlap or semantic relationships below higher thresholds |
| **None** | No reportable evidence |

The attention level is derived from transparent rules, not from an opaque model. Reasons are shown alongside the level in reports.

## Overlap Resolution

When multiple sources match the same word position, the matched word is counted only once in the numerator. Overlapping matches do not compound. This ensures that a passage matching multiple sources does not inflate the score beyond 100%.

## Denominator Exclusions

The following word categories are excluded from the denominator:

- Words shorter than the configurable minimum word length (default 2 characters).
- Words appearing on the tenant-defined exclusion list (common boilerplate: headers, footers, instructions, institution name).
- Words within passages explicitly excluded by a reviewer via the Review Service.

## Raw vs. Adjusted Scores

The report presents two scores:

**Raw score** — The direct proportion of unique eligible matched words (exact + near-exact) to total unique eligible words. This is the primary metric.

**Adjusted score** — The raw score recalculated after applying all reviewer exclusions. Matches that a reviewer has excluded (via the `academic_similarity.review.exclude@1` capability) are removed from the numerator, and their unique words are removed from the denominator. The adjusted score is the final score shown in the similarity report; it reflects the institution's accepted matches only.

## Disclosure

**Important**: The textual overlap score measures word-level correspondence, not academic misconduct. A low score does not guarantee original work; a high score does not confirm plagiarism. Semantic resemblance measures topic-level similarity only — it is not evidence of copying. All results require qualified human review within the institution's academic integrity policy.

## Configuration Parameters

| Parameter | Default | Effect |
|---|---|---|
| `min_word_count` | 20 | Submissions below this count are not processed |
| `fingerprint_shingle_size` | 5 | Words per shingle for fingerprinting |
| `near_match_threshold` | 0.8 | Minimum Jaccard similarity for near-exact matches |
| `semantic_similarity_threshold` | 0.25 | Candidate discovery threshold for semantic API |
| `semantic_report_threshold` | 0.70 | Only matches at or above this enter calculated scores |

Scores are expressed as a percentage between 0 and 100, rounded to two decimal places in the stored report. The Reviewer Attention Level is categorical and not a percentage.
