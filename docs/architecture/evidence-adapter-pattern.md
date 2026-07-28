# Evidence Adapter Pattern — Reusable AISS Consumer Contract

**Status**: Active (2026-07-28)
**Applies to**: All AISS-consuming modules

## Pattern

Every module that consumes AISS evidence should implement an adapter following this contract:

```
module/src/Services/<Module>AissAdapter.php
    │
    ├── generateSnapshot(caseId, actorId) → array
    │       └── Check aiss_integration_enabled setting
    │       └── Check AISS module availability
    │       └── Call AISS capabilities via bus
    │       └── Store immutable evidence snapshot
    │       └── Return { ok, data, warnings, maturity }
    │
    └── Never: convert AISS output to a decision
```

## Required Behaviors

### 1. Settings Gate

```php
$settings = get_settings($tenantId);
if (($settings['aiss_integration_enabled'] ?? '0') !== '1') {
    // Store empty snapshot with disabled_by_tenant flag
    return snapshot_with_warning('AISS integration is disabled');
}
```

### 2. Graceful Degradation

Every AISS capability call must be wrapped in try/catch. AISS unavailability must not block the parent workflow:

```php
try {
    $result = $caps->call('academic_similarity.textual.match@1', [...]);
} catch (\Throwable $e) {
    $warnings[] = 'Textual matching unavailable: ' . $e->getMessage();
    $maturityMetadata['textual_matching'] = 'error';
}
```

### 3. Immutable Evidence Snapshots

Store a complete, versioned snapshot of AISS output. Never overwrite:

```sql
CREATE TABLE evidence_snapshots (
    id, tenant_id, case_id, manuscript_version_id,
    aiss_submission_id, capability_version, evidence_version,
    textual_result JSON, semantic_result JSON, citation_result JSON,
    context_result JSON, scholarship_result JSON, lineage_result JSON,
    maturity_metadata JSON, capability_warnings JSON,
    generated_at, generated_by, source_hash
);
```

### 4. Machine/Human Separation

Store machine evidence and human interpretation in separate tables/columns:

```sql
CREATE TABLE evidence_review_decisions (
    id, tenant_id, case_id, evidence_snapshot_id, match_id,
    machine_relationship VARCHAR(255),    -- from AISS
    reviewer_relationship VARCHAR(255),   -- human classification
    reviewer_action VARCHAR(100),         -- confirmed, rejected, excluded, flagged
    reviewer_reason TEXT,
    reviewer_id, confirmed_at
);
```

The machine value must never be overwritten by the reviewer value.

### 5. Feature Maturity Display

Every evidence display must show per-feature maturity:

```
Textual Matching    [stable]       ✓ Results available
Citation Detection  [beta]         ⚠ Results may be incomplete
Context Analysis    [experimental] ⚠ Not validated for academic decisions
Semantic Analysis   [unavailable]  — Service not configured
```

## Anti-Patterns

| ❌ Don't | ✅ Do |
|---|---|
| Query AISS tables directly | Call AISS capabilities via bus |
| Convert AISS score → passed/failed | Show evidence; let reviewer decide |
| Overwrite machine values | Store machine + reviewer in separate columns |
| Block workflow on AISS failure | Record warning; continue workflow |
| Hard-code capability IDs | Reference from adapter; version in snapshot |
| Assume AISS is always available | Check settings + module availability first |

## Reference Implementation

`modules/academic_thesis_evaluation/src/Services/AcademicThesisAissAdapter.php` — the first and reference implementation of this pattern. New consumers should model their adapter after it.

## Migrating Existing Consumers

To make an existing module AISS-aware:

1. Add `academic-similarity` to `optional_depends` in `module.json`
2. Add `aiss_integration_enabled` to module settings (default `0`)
3. Create `<Module>AissAdapter` following this pattern
4. Create `evidence_snapshots` and `evidence_review_decisions` tables
5. Add evidence review page showing maturity metadata
6. Test with AISS disabled, unavailable, and fully available
