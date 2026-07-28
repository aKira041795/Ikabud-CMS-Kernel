# AISS Scope Redefinition — From Similarity System to Evidence Engine

**Date**: 2026-07-28
**Author**: Architecture Review
**Status**: Active

## Background

AISS was originally conceived and named as an "Academic Integrity & Similarity System" — a document similarity checker. Its implementation has outgrown that scope.

The module now provides:
- Exact and near-exact textual matching with offset-accurate highlights
- Semantic resemblance detection (conceptual similarity)
- Citation and attribution signal analysis
- Contextual scholarly relationship classification
- Multi-dimensional scholarship evidence profiling
- Knowledge lineage graph construction
- Internet source discovery
- Reviewer workflow integration

Similarity checking is now **one capability among many**.

## Redefinition

> **AISS = Academic Intelligence & Scholarship Services**

AISS is an **evidence engine** that produces structured, versioned, maturity-labeled evidence about academic documents. It does not determine academic outcomes — it provides evidence for human reviewers and domain-specific workflow modules.

## Scope Boundaries

### AISS Owns

- Document ingestion and text extraction
- Fingerprint generation (exact and near-exact)
- Matching algorithms (deterministic and semantic)
- Citation and attribution signal detection
- Contextual passage relationship classification
- Scholarship evidence distribution profiling
- Knowledge lineage graph construction
- Internet source discovery
- Match exclusion and reviewer annotation
- Evidence taxonomy and classification
- Feature maturity metadata
- Processing pipeline orchestration

### AISS Does NOT Own

- Thesis/dissertation evaluation workflows (→ ATE)
- Journal peer review processes (→ planned APR)
- Research ethics board workflows (→ planned AER)
- Institutional accreditation (→ planned AQA)
- Plagiarism verdicts or academic misconduct determinations
- Rubric scoring or grade assignment
- Final disposition or approval authority

## Consumer Pattern

Every AISS consumer follows the same adapter pattern:

```
Consumer Module
    │
    ├── Adapter (AcademicThesisAissAdapter, etc.)
    │       └── Calls AISS capabilities via bus
    │       └── Normalizes capability versions
    │       └── Captures maturity metadata
    │       └── Records capability failures
    │       └── Prevents AISS failure from blocking workflow
    │
    ├── Evidence Snapshot (immutable, versioned)
    │       └── Raw AISS output
    │       └── Capability ID + version
    │       └── Feature maturity flags
    │       └── Warnings and limitations
    │
    └── Reviewer Decision (human interpretation)
            └── Machine relationship (from AISS)
            └── Reviewer relationship (human classification)
            └── Never overwrites machine values
```

## Non-Academic Applications

Because AISS is a general-purpose evidence engine, it can serve non-academic modules:

| Domain | Module Concept | AISS Capabilities |
|---|---|---|
| Government | Bid Integrity Review | Textual matching, semantic resemblance |
| Legal | Contract Clause Analysis | Textual matching, context analysis |
| Intellectual Property | Prior Art Search | Semantic resemblance, knowledge lineage |
| Healthcare | Guideline Comparison | Context analysis, scholarship profiling |
| Research Funding | Grant Proposal Review | Citation analysis, lineage graph |
| Publishing | Manuscript Screening | Textual matching, citation detection |
| Corporate | Policy Document Audit | Textual matching, semantic resemblance |

Each domain module implements its own workflow, rubric, and disposition logic — AISS provides only the evidence layer.

## Related

- [Academic Suite Architecture](architecture/academic-suite.md)
- [AISS Capability Contracts](architecture/aiss-capability-contracts.md)
- [Evidence Adapter Pattern](architecture/evidence-adapter-pattern.md)
- [AISS Architecture Audit 2026-07-24](aiss-architecture-audit-2026-07-24.md)
