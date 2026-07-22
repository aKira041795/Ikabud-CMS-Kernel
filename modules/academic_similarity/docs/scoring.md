# Academic Similarity — Scoring Methodology

## Overview

The scoring service computes similarity as the proportion of a submission's unique eligible words that overlap with matched source documents. The formula is designed to produce conservative, reproducible scores that are not inflated by duplicate matches or boilerplate.

## Unique Eligible Word Coverage

The raw score is calculated as:

```
raw_score = matched_unique_eligible_words / total_unique_eligible_words
```

Where *matched_unique_eligible_words* is the count of deduplicated words that appear in at least one matched passage, and *total_unique_eligible_words* is the count of deduplicated words in the submission after normalization and exclusion.

**Deduplication is critical.** If a five-word phrase appears three times in the same submission but matches a source only once, it contributes exactly five words to the numerator — not fifteen. This prevents artificially high scores from repeated self-citations or boilerplate headings.

## Overlap Resolution

When multiple sources match the same word position, the matched word is counted only once in the numerator. Overlapping matches do not compound. This ensures that a passage matching multiple sources does not inflate the score beyond 100%.

## Denominator Exclusions

The following word categories are excluded from the denominator:

- Words shorter than the configurable minimum word length (default 2 characters).
- Words appearing on the tenant-defined exclusion list (common boilerplate: headers, footers, instructions, institution name).
- Words within passages explicitly excluded by a reviewer via the Review Service.

## Raw vs. Adjusted Scores

The report presents two scores:

**Raw score** — The direct proportion of unique eligible matched words to total unique eligible words. This is the primary metric.

**Adjusted score** — The raw score recalculated after applying all reviewer exclusions. Matches that a reviewer has excluded (via the `academic_similarity.review.exclude@1` capability) are removed from the numerator, and their unique words are removed from the denominator. The adjusted score is the final score shown in the similarity report; it reflects the institution's accepted matches only.

## Configuration Parameters

| Parameter | Default | Effect |
|---|---|---|
| `min_word_count` | 20 | Submissions below this count are not processed |
| `fingerprint_shingle_size` | 5 | Words per shingle for fingerprinting |
| `near_match_threshold` | 0.8 | Minimum Jaccard similarity for near-exact matches |

Scores are expressed as a percentage between 0 and 100, rounded to two decimal places in the stored report.
