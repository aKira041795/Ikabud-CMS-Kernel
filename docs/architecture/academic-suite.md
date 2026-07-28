# Academic Suite — Architecture & Module Map

**Status**: Active (2026-07-28)
**Scope**: Ikabud Kernel OS ecosystem

## Overview

The Academic Suite is a family of Kernel OS modules that compose AISS (Academic Intelligence & Scholarship Services) as a shared evidence engine. Each module defines its own domain workflow — rubric, reviewer, decision, revision — without duplicating similarity, citation, or scholarship analysis logic.

```
Kernel OS
    │
    ├── AISS (Evidence Engine)
    │       academic_similarity
    │       └── textual matching, semantic resemblance, citation detection,
    │           context analysis, scholarship profiling, knowledge lineage,
    │           reviewer attention signals
    │
    ├── ATE (Academic Thesis Evaluation)
    │       academic_thesis_evaluation
    │       └── thesis/dissertation workflow: submission → admin validation →
    │           evidence review → rubric evaluation → revision → disposition
    │
    ├── ARR (Academic Research Repository) — planned
    │       └── institutional research output catalog with AISS-powered
    │           metadata enrichment and similarity-based deduplication
    │
    ├── APR (Academic Peer Review) — planned
    │       └── journal/conference manuscript peer review with AISS
    │           evidence as reviewer assist (not automated decision)
    │
    ├── AER (Academic Ethics Review) — planned
    │       └── research ethics board workflow with AISS evidence
    │           for prior-art, methodology overlap, informed consent patterns
    │
    └── AQA (Academic Quality Assurance) — planned
            └── program accreditation / institutional review with
                AISS-powered evidence aggregation and audit trails
```

## Architectural Principle

> **Engines provide stable capabilities. Modules compose those capabilities into domain-specific workflows.**

Every module in the Academic Suite:
1. Declares `academic_similarity` as an optional dependency
2. Accesses AISS exclusively through the capability bus
3. Never queries AISS tables directly
4. Stores AISS output as immutable evidence snapshots
5. Separates machine evidence from human decisions
6. Treats AISS unavailability as a non-blocking condition

## AISS as Infrastructure

AISS is not a "plagiarism checker." It is an **evidence engine** that produces:

| Capability Family | What It Provides |
|---|---|
| Textual Matching | Exact and near-exact overlap detection with offset-accurate highlights |
| Semantic Resemblance | Conceptual similarity without lexical overlap |
| Citation Analysis | Attribution signal detection in academic text |
| Context Analysis | Scholarly relationship classification between passages |
| Scholarship Profiling | Multi-dimensional evidence distribution across a document |
| Knowledge Lineage | Source relationship graphs (JSON + Mermaid) |
| Reviewer Attention | Machine-candidate flags for human reviewer prioritization |

Any module can consume any subset of these capabilities. AISS itself never determines academic outcomes — it produces evidence for human reviewers.

## Beyond Academia

The evidence engine pattern is reusable across domains:

| Domain | Example Module | AISS Capabilities Used |
|---|---|---|
| Government Procurement | Bid Integrity Review | Textual matching, semantic resemblance |
| Legal | Contract Clause Analysis | Textual matching, context analysis |
| Patents | Prior Art Search | Semantic resemblance, knowledge lineage |
| Healthcare | Clinical Guideline Comparison | Context analysis, scholarship profiling |
| Research Funding | Grant Proposal Review | Citation analysis, knowledge lineage |

## Related Documents

- [AISS Capability Contracts](aiss-capability-contracts.md) — formal capability interface reference
- [AISS Scope Redefinition](../reviews/aiss-scope-redefinition-2026-07-28.md) — from similarity system to evidence engine
- [Evidence Adapter Pattern](evidence-adapter-pattern.md) — reusable pattern for AISS-consuming modules
