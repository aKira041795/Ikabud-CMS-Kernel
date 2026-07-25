# Algorithm & Process Analysis — AISS vs Production-Grade Checkers

## Industry landscape

| Checker | Core technique | Key differentiator |
|---|---|---|
| **Turnitin** | Multi-layer: fingerprint + string matching + concept matching + LLM detection | Largest corpus (90B+ pages), iThenticate for publisher content |
| **Grammarly** | Transformer-based semantic matching + pattern DB | Paraphrase detection, authorship signals |
| **Copyscape** | Rolling hash + real-time index | Premium + crowd-sourced corpus, pay-per-scan |
| **Ouriginal/Urkund** | Multi-N-gram + syntactic analysis + mathematical similarity (6-layer) | No thresholds — every match shown; language-agnostic syntax |
| **PlagScan** | Fingerprint + citation-aware NLP | Filters references/bibliography automatically |
| **iThenticate** | Deep fingerprint + cross-publisher DB | Scholarly focus, 200K+ publisher partnerships |

---

## 1. Fingerprinting strategy — current vs best practice

### Current AISS
- Single fixed shingle size (default 5 words)
- SHA-256 hash of raw shingle text
- Near-exact: sorts shingle words alphabetically before hashing
- Same shingle size for both exact and near-exact passes

### Industry standard
| Technique | Used by | Why it's better |
|---|---|---|
| **Multi-size shingling** (3, 5, 7, 10 words) | Turnitin, Ouriginal | Catches both short phrase overlap AND structural copying at different granularities. 3-word catches common phrases; 10-word catches paragraph-level copying. |
| **Winnowing** (selective fingerprint retention) | Turnitin, Moss (Stanford) | Instead of storing ALL shingle hashes, select a subset based on a sliding window minimum hash. Reduces DB size by 80-90% while preserving match accuracy. |
| **Rolling hash (Karp-Rabin)** | Copyscape, Moss | Computes hash incrementally — O(n) instead of O(n×shingleSize). Critical for large documents. |
| **Position-aware fingerprints** | Turnitin | Stores word-offset for each fingerprint, enabling gap/insertion detection within matched runs. |
| **Synonym/lemma normalization before hashing** | Grammarly, Ouriginal | Maps "ran", "running", "runs" → "run" before hashing, catching inflection changes. |

### Recommendation — multi-layer fingerprinting

```
Layer 1: Short shingles (3 words) — high recall, catches short copied phrases
  → hash after stop-word removal + stemming
  → stored with word positions
  → used for initial candidate source discovery

Layer 2: Medium shingles (7 words) — medium recall/precision
  → hash on full normalized text (current approach)
  → used for match confirmation and boundary detection

Layer 3: Long shingles (20+ words) — low recall, high precision
  → hash on full normalized text
  → signature for document-level identity (detects near-identical copies)
```

**Winnowing**: Instead of storing all 3-word shingles (~= word count), keep only the hash with the minimum value in each sliding window of N shingles. Turnitin uses N = 4× shingle size. This reduces storage by ~75% while preserving match quality for the medium+ layers.

---

## 2. Matching algorithms — current vs best practice

### Current AISS
- Exact: hash lookup → position mapping → contiguous run detection
- Near-exact: alphabetically-sorted shingle hash → same pipeline
- Text fallback: sliding window Jaccard (3% threshold, extremely lenient)
- Overlap resolution: greedy longest-match-first, trim partial overlaps

### Industry standard

| Technique | Used by | Why it's better |
|---|---|---|
| **Bipartite graph matching** | Ouriginal | Models submission words vs source words as a bipartite graph, finds maximum-weight matching. Handles reordering, insertions, deletions naturally. |
| **Smith-Waterman local alignment** | Turnitin (internal), bioinfomatics-derived | Dynamic programming: finds optimal local alignments between two sequences. Naturally handles gaps, insertions, and transpositions. O(n×m) but can be approximated. |
| **Greedy string tiling** | Moss (Stanford) | Finds the longest common substring, marks it, repeats on remaining text. Excellent for detecting reordered/collaged text. |
| **Chunk-based comparison** | Copyscape | Splits both texts into fixed chunks, compares chunk-pairs via cosine similarity of TF-IDF vectors. Scales to web-scale corpora. |
| **Running KMP (Knuth-Morris-Pratt)** | PlagScan | After fingerprint hit → extract a window around the hit → run exact string matching. More precise than position-contiguity heuristics. |

### Recommendation — tiered matching pipeline

```
Stage 1: Candidate discovery (current approach — works well)
  → Multi-size shingle hash lookup (3-word for recall, 7-word for precision)
  → Score candidates by overlap at each granularity, rank by combined score

Stage 2: Local alignment (new — replaces position-contiguity heuristic)
  → After a fingerprint hit at (sub_pos, src_pos), extract a ±200 word window
  → Run Smith-Waterman-Gotoh (affine gap penalties) on the window
  → Produces: exact boundaries, gap count, insertion count
  → Enables confidence scoring based on alignment quality

Stage 3: Global reordering detection (new)
  → Apply greedy string tiling to the submission vs each high-confidence source
  → Detects: reordered paragraphs, collaged text from multiple sources
  → Important: Turnitin flags reordered text with lower severity

Stage 4: Overlap resolution (improved)
  → Current approach works but loses partial overlap info
  → Better: store all match fragments with their alignment scores
  → Scoring: each word position belongs to N sources → similarity = fraction of
    positions with at least one match, NOT word count of longest match
```

---

## 3. False-positive reduction — current vs best practice

### Current AISS
- `isBibliographyLine()` exists in NormalizationService but is NOT wired into matching
- `isQuotation()` exists but NOT wired in
- No common-phrase filtering
- No citation-aware exclusion
- No boilerplate detection

### Industry standard

| Technique | Used by | Why it's better |
|---|---|---|
| **Reference/bibliography detection** | Turnitin, PlagScan | Detects "References" / "Works Cited" sections via ML classifier (not just regex). All matches from this section are flagged as "bibliography" and excluded from scoring by default. |
| **Quotation detection** | Turnitin, Turnitin Originality | Detects quoted passages ("..."), quotation marks, block quotes. Excluded from scoring, shown in report as "quoted material". |
| **Template/boilerplate exclusion** | Ouriginal | Compares submission against known assignment templates. Matches in shared boilerplate sections are auto-excluded. |
| **Citation-aware matching** | Turnitin | Detects in-text citations (APA, MLA, Chicago, etc.) and marks matches there as "citation" — lower severity, orange vs red. |
| **Small-match filtering** | All major | Matches under N words (configurable, typically 3-8) are excluded. Prevents trivial overlap from inflating scores. |
| **Common knowledge detection** | Turnitin (experimental) | Highly-matched phrases across many sources → likely common knowledge → lower severity. |

### Recommendation — integrated pre-filter pipeline

```
Pre-filter 1: Bibliography exclusion (NEW — wire into pipeline)
  → After normalization, scan for bibliography/references section header
  → Mark all lines after "References", "Works Cited", "Bibliography" etc.
  → Exclude these word ranges from matching (preserve for report visibility)

Pre-filter 2: Quotation exclusion (NEW — wire into pipeline)  
  → Scan for "..." and block-quote patterns
  → Flag quoted passages — include in report as "quoted" but exclude from scoring
  → Configurable: exclude from scoring (default) or just mark severity

Pre-filter 3: Citation exclusion (NEW)
  → Regex for common in-text citation patterns: (Author, YYYY), [1], etc.
  → Exclude short isolated citation matches (< 8 words) from scoring
  → Longer citation matches are shown as "citation" severity

Pre-filter 4: Common-phrase exclusion (NEW)
  → Built-in list of ~100 common academic phrases ("literature review", "research shows", etc.)
  → Configurable per-tenant via settings
  → Matches on these phrases are excluded from scoring

Match filter: Minimum match length (improve current)
  → Default: 8 words minimum for exact, 15 for near-exact
  → Configurable in settings
```

---

## 4. Scoring methodology — current vs best practice

### Current AISS
- `raw_score = matched_unique_eligible_words / total_unique_eligible_words`
- Deduplication: each word position counted once
- Adjusted score recalculated after reviewer exclusions

### Industry standard

| Method | Used by | Why it's better |
|---|---|---|
| **Weighted word count** | Turnitin | Not all matched words are equal: 10-word exact match ≠ 10 scattered words. Longer contiguous runs get higher weight. |
| **Source diversity factor** | Ouriginal | Matching 100 words from 1 source → lower concern than 10 words from 10 sources. Score includes a "sources per match" factor. |
| **Type-weighted scoring** | Turnitin Originality | Exact matches weighted 1.0x, near-exact 0.8x, paraphrased/synonymized 0.5x. Final score sum of weighted contributions. |
| **Confidence bands** | Grammarly | Instead of a single percentage, shows Low / Medium / High / Very High. Percentages are misleading at extremes (98% vs 100% doesn't matter). |
| **Overlap-adjusted score** | iThenticate | If a student copied from source A which copied from source B, the overlap is counted once. Prevents double-counting from derivative sources. |

### Recommendation — weighted scoring

```
raw_score = Σ(match_weight × match_type_weight × source_diversity_penalty) / total_eligible_words × 100

Where:
  match_weight = word_count × contiguous_bonus
    contiguous_bonus = min(2.0, 1.0 + (run_length / 100))
    A 50-word contiguous run = 50 × 1.5 = 75 weighted words
    A 50-word scattered match = 50 × 1.0 = 50 weighted words

  match_type_weight:
    exact = 1.0
    near-exact (sorted hash) = 0.85
    text-level Jaccard = 0.4

  source_diversity_penalty = min(0.8, 0.5 + (0.3 / max(1, source_count)))
    More sources → slightly lower weight per source (diminishing returns)
    But many sources still signals higher concern in aggregate
```

---

## 5. Pipeline & processing architecture — current vs best practice

### Current AISS
- Synchronous pipeline: extract → normalize → segment → fingerprint → match → score → report
- Optional kernel job queue for batch processing
- No incremental indexing
- No pre-computed corpus statistics

### Industry standard

| Practice | Used by | Why it's better |
|---|---|---|
| **Pre-indexed corpora** | Turnitin, Copyscape | Sources are fingerprinted and indexed at upload time. Matching is just a lookup — no fingerprint generation during comparison. |
| **Incremental indexing** | Google Scholar | New sources are fingerprinted incrementally. Existing submissions can be rechecked against new sources without full reprocessing. |
| **Corpus statistics cache** | Ouriginal | Pre-computes IDF (inverse document frequency) for each shingle across the corpus. Rare shingles get higher match weight — they're more distinctive. |
| **Sub-linear candidate retrieval** | All major | Instead of comparing against ALL sources, use shingle hash as a key in an inverted index → only load sources that share at least N shingles. |
| **Async processing with polling** | Turnitin | Submission created immediately (ID returned). Processing happens asynchronously. Frontend polls for status. User gets a notification when done. |

### Recommendation — incremental indexing + async pipeline

```
PHASE 1 — At source upload time:
  → Generate fingerprints immediately (all 3 layers)
  → Store in fingerprint table with source_id populated
  → Add to inverted index (shingle_hash → [source_ids])
  → This is ALREADY partially done (sources are fingerprinted at upload)
  → Gap: fingerprints flagged as 'submission' type vs 'source' type — need to
    ensure all source fingerprints are available for matching

PHASE 2 — At submission time:
  → Generate submission fingerprints
  → Inverted index lookup: for each submission shingle, get all source_ids
  → Candidate set = sources that share ≥3 shingles at the short layer
  → Compare against candidates only (not all sources)
  → This is ALREADY mostly done — just need to add the "≥3 shingles" threshold

PHASE 3 — Incremental recheck (NEW):
  → After a new source is added, flag existing submissions for recheck
  → AISS can run a "recheck" pipeline that only compares against the new source
  → Shows "new matches found" banner on existing reports
  → Critical for: adding past theses as sources and checking ALL existing submissions
```

---

## 6. Report & interpretation — current vs best practice

### Current AISS
- Shows raw and adjusted percentage
- Shows match list with source, position, type
- Supports reviewer exclusions
- Match evidence includes surrounding text snippets

### Industry standard

| Feature | Used by | Why it's better |
|---|---|---|
| **Color-coded similarity index** | Turnitin | Single percentage shown as color: Blue (0%), Green (1-24%), Yellow (25-49%), Orange (50-74%), Red (75-100%). Instantly scannable. |
| **Match breakdown by source** | Turnitin, Grammarly | "5% from Source A, 12% from Source B, 3% from Source C" — shows breadth of copying, not just total |
| **Match-over-time timeline** | Turnitin | Shows submission date vs source publication date. Copying from future sources? (student submitting a paper from a previous year) |
| **Originality score** | Turnitin | Inverse of similarity. A 75% originality score means 25% matched. Framing matters for academic context. |
| **Excluded matches visible** | Turnitin | Bibliography matches are shown in the report BUT crossed out/grayed. Users can see WHY they were excluded. |
| **PDF download with highlights** | All major | Full report as PDF with inline color-coded highlights |

### Recommendation — enhanced report display

```
Similarity Index: 28% (Moderate)
  ┌─────────────────────────────────────────────────────┐
  │ ████████████░░░░░░░░░░░░░░░░░░░░░░░░ 28%           │
  │ ●●●●●●●●●●○○○○○○○○○○○○○○○○○○○○○○○○               │
  │ 🔴 15% Exact match (3 sources)                     │
  │ 🟡 10% Near-exact (2 sources)                      │
  │ 🟢  3% Text-similar (1 internet source)           │
  └─────────────────────────────────────────────────────┘

Match breakdown:
  Source A (thesis, 2024) — 12%  ████████░░  (exact, 45-word run)
  Source B (article, 2023) — 10%  ██████░░░░  (near-exact, scattered)
  Source C (web, 2025)    —  3%  ██░░░░░░░░  (text-similar)
  Source D (past thesis)  —  3%  ██░░░░░░░░  (bibliography, excluded)

MATCH HIGHLIGHTS:
┌─────────────────────────────────────────────────────┐
│ This study examines the impact of social media on   │
│ ┌──────────────────────────────────────────────┐    │
│ │ academic performance among college students.  │ ← Source A (exact, 95%)
│ └──────────────────────────────────────────────┘    │
│ The research methodology employed a mixed-methods   │
│ approach...                                         │
└─────────────────────────────────────────────────────┘
```

---

## 7. Summary — highest-impact improvements ranked

| Priority | Change | Effort | Impact | Risk |
|---|---|---|---|---|
| **P1** | Multi-size shingling (3/7/20) + winnowing for storage | 2-3 days | High — catches short phrases + long copies | Low — additive, doesn't break existing |
| **P2** | Pre-filter pipeline: bibliography + quotation + citation exclusion | 2 days | High — major false-positive reduction | Low — opt-in via settings |
| **P3** | Incremental recheck: recompare against new sources | 1-2 days | High — critical for thesis repository use case | Medium — needs index on (source_id, submission_id) |
| **P4** | Weighted scoring (contiguous bonus, type weights, source diversity) | 1 day | Medium — more accurate scores | Low — backward-compatible with UI changes only |
| **P5** | Local alignment (Smith-Waterman) on ±200 word windows | 2-3 days | Medium — better boundaries, gap detection | Medium — O(n²) per window, needs window size tuning |
| **P6** | Report: similarity index gauge + breakdown by source | 2 days | Medium — better interpretation | Low — template-only changes |
| **P7** | Global reordering detection (greedy string tiling) | 2-3 days | Medium — catches collaged/reordered text | Medium — high false-positive risk without tuning |
| **P8** | Shingle IDF weighting (rare shingles = higher weight) | 1 day | Low — marginal improvement for small corpora | Low |

**Near-term (P1-P3)**: Multi-size shingling + false-positive filters + incremental recheck → production-grade accuracy for the thesis repository use case.

**Medium-term (P4-P6)**: Weighted scoring + Smith-Waterman alignment + enhanced reports → competitive with PlagScan/Ouriginal for academic use.

**Long-term (P7-P8)**: Reordering detection + IDF weighting → approaching Turnitin feature parity for the core matching path.
