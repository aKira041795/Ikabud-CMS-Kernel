# Hybrid Semantic Comprehension Engine

**Engine version:** 2.0  
**Location:** `kernel/Workbench/Comprehension/`  
**Entry point:** `php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [--evidence=file.json]`

## Overview

The Comprehension Engine is a **layered hybrid reasoning system** that analyzes module behavior by combining six complementary algorithms. It takes declared contracts (what a module should do) and runtime evidence (what actually happened) and produces a structured diagnosis with root cause hypothesis, confidence scoring, and temporal analysis.

Unlike traditional test frameworks that only report pass/fail, this engine can:
- Pinpoint the **exact first failure** in a causal chain
- Detect **semantic anomalies** (e.g., "HTTP 200 OK" response body says "CSRF token expired")
- Compute **historical failure probability** per action step using Bayesian inference
- Validate **temporal ordering** — catch causality violations and cache-serving-stale-data
- Classify error patterns into 9 categories (csrf, permission, validation, etc.)
- Detect **cross-module cascades** — when module A fails because module B's capability is degraded

## Architecture

```
kernel/Workbench/Comprehension/
├── ModuleComprehensionEngine.php          # Layer 1: Deterministic causal chain probe
├── SemanticComprehensionEngine.php        # Orchestrator — runs all 6 layers
├── PalComprehensionProvider.php           # PAL module's declared contracts
├── run.php                                # CLI entry point
├── Contracts/                             # Contract interfaces
│   ├── ModuleComprehensionProvider.php    # Interface all modules implement
│   ├── EntityContract.php
│   ├── WorkflowContract.php
│   ├── ActionContract.php                 # Actions with ChainLink arrays
│   ├── ChainLink                          # One step in a causal chain
│   ├── EffectContract.php
│   └── SupportContracts.php              # InvariantContract, ScenarioContract
└── Analyzers/                             # Individual reasoning layers
    ├── SemanticScorer.php                 # Layer 2: Jaccard + TF-IDF + regex
    ├── BayesianReasoner.php               # Layer 3: Beta-Binomial conjugate prior
    ├── TemporalValidator.php              # Layer 4: Topological ordering
    ├── PatternClassifier.php              # Layer 5a: 9-category error classifier
    ├── AnomalyDetector.php                # Layer 5b: Outlier + missing-link detection
    └── CrossModuleAnalyzer.php            # Layer 6: Dependency graph traversal
```

## The 6 Reasoning Layers

### Layer 1: Deterministic Causal Chain

The foundation. Each action declares an ordered list of `ChainLink` steps. The engine probes each step against runtime evidence — if evidence is present and truthy, the step passes. The **first failing step** is the breakpoint.

```php
// Example chain for "Submit Job Order for Approval"
chain: [
    button.visible      (ui)      → Submit button is visible
    button.clicked      (ui)      → User clicks Submit
    http.request        (http)    → POST to /status API
    http.response_ok    (http)    → API returns {ok: true}
    workflow.transition (service) → Workflow::apply() executes
    db.status_change    (db)      → status = 'pending' in DB
    approval.created    (db)      → pal_approvals record created
    audit.created       (audit)   → Audit log entry written
    ui.status_updated   (verify)  → Detail page shows Pending badge
    approval_queue.updated (verify) → Queue shows the project
]
```

**Algorithm:** Sequential probe — `O(n)` where n = chain length.  
**Output:** Binary pass/fail per link, first-failure breakpoint.

### Layer 2: Semantic Similarity Scorer

Scores each chain link on a 0.0–1.0 scale by comparing evidence text against expected patterns using lightweight NLP. This catches cases where the deterministic layer says "passed" (truthy value present) but the content reveals a failure.

**Techniques:**
- **Success pattern matching** — regex patterns like `/200|ok|success/i` add up to +0.4
- **Failure pattern matching** — patterns like `/419|csrf|expired|invalid/i` subtract up to −0.5
- **Jaccard similarity** — word-token overlap between evidence and link description (up to +0.3)
- **Step-name proximity** — does evidence key contain the step name? (+0.1)
- **Boolean short-circuit** — `true` = 1.0, `false` = 0.0

**Example:** `http.response_ok` gets evidence `"CSRF token mismatch: Status 419"`. The deterministic layer passes (truthy string). The semantic scorer matches the failure pattern `/419|csrf|expired|invalid/i` and gives `score=0`.

### Layer 3: Bayesian Failure History Reasoner

Tracks per-action per-link success/failure counts across runs. Uses Beta-Binomial conjugate prior to compute posterior failure probability.

**Formula:** `P(fail) = (1 + failures) / (2 + successes + failures)`  
**Prior:** `Beta(1, 1)` (uniform — assumes nothing initially)  
**Posterior:** `Beta(1 + successes, 1 + failures)` (updated after each run)

**Persistence:** JSON files in `storage/private/comprehension/history/{module}/{action}.json`

**Example:** After 3 runs where `workflow.transition` failed 2 times and succeeded 1 time:
- `P(fail) = (1 + 2) / (2 + 1 + 2) = 3/5 = 0.6`
- The engine knows this link is historically unreliable before even checking evidence

### Layer 4: Temporal Ordering Validator

Checks that observed evidence respects the expected causal order. Categories have a fixed partial order:

```
UI (0) → HTTP (1) → Service (2) → DB (3) → Event (4) → Audit (5) → Verify (6)
```

**Detects:**
- **Category regressions** — later step with earlier category (e.g., UI step after Audit)
- **Timestamp reversals** — evidence timestamp earlier than previous step (impossible causality)
- **Chain gaps** — consecutive failures that break continuity
- **Suspiciously fast** — sub-millisecond gaps suggest cache serving stale data
- **Suspiciously slow** — >5s gaps suggest timeouts or background jobs

### Layer 5a: Pattern Classifier

Classifies error text into 9 categories using weighted keyword and regex scoring:

| Category | Keywords | Weight |
|----------|----------|--------|
| `csrf` | csrf, token mismatch, 419, expired token | 1.0 |
| `permission` | forbidden, access denied, 403, unauthorized | 1.0 |
| `validation` | validation, required, invalid, 422 | 0.9 |
| `missing_record` | not found, 404, null, does not exist | 0.9 |
| `network` | timeout, connection refused, unreachable | 0.8 |
| `db` | sql, constraint, deadlock, duplicate, drift | 0.9 |
| `session` | session expired, login required | 0.8 |
| `capability` | capability not registered, no provider | 0.9 |
| `template` | template not found, render error, disyl | 0.7 |

Each category is scored independently; the best match wins. Confidence levels: `high` (≥0.7), `medium` (≥0.4), `low` (≥0.1), `none`.

### Layer 5b: Anomaly Detector

Flags evidence that doesn't match any declared chain link:
- **Unknown evidence keys** — runtime observations not covered by any chain step
- **Error indicators** — evidence values containing `error`, `failed`, `exception`, etc.
- **Unusually large values** — >5KB suggests debug/verbose mode left on
- **Missing link suggestions** — if evidence contains audit/event/email patterns but no corresponding chain link exists, suggests adding one

### Layer 6: Cross-Module Cascade Analyzer

When module A's action fails at a capability-dependent step, checks if the capability provider module is healthy. Reads `module.json` for:
- `reads_tables` — cross-module data dependencies
- `capabilities.exposes` / `capabilities.depends` — declared capability contracts

If PAL calls `entity.list.employee@1` (provided by attendance-wage) and it fails, the engine flags attendance-wage as the likely root cause, not PAL.

## Usage

### CLI

```bash
# Analyze a specific action
php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit

# Analyze with evidence file
php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit \
    --evidence=test_results/ai/evidence.json

# Analyze all actions
php kernel/Workbench/Comprehension/run.php project-audit-ledger
```

### Evidence File Format

Flat format (keys = step names):
```json
{
    "button.visible": true,
    "button.clicked": true,
    "http.response_ok": "CSRF token mismatch. Status 419.",
    "workflow.transition": false
}
```

Structured format (ActionObserver output):
```json
{
    "steps": [
        {"step": "button.visible", "value": true, "timestamp": 1234567890.123},
        {"step": "http.response_ok", "value": "CSRF error", "timestamp": 1234567890.456}
    ],
    "summary": {
        "button.visible": {"ok": true},
        "http.response_ok": {"ok": false}
    }
}
```

### Programmatic

```php
$provider = new PalComprehensionProvider();
$engine = new SemanticComprehensionEngine('project-audit-ledger', $provider);

$engine->feedEvidence([
    'button.visible' => true,
    'http.response_ok' => 'CSRF token expired',
    'workflow.transition' => false,
]);

$result = $engine->analyze('pal.job-order.submit');

echo $result['breakpoint'];           // 'workflow.transition'
echo $result['diagnosis']['primary_classification']['category']; // 'csrf'
echo $result['confidence']['score'];  // 0.54
print_r($result['root_cause_hypothesis']);
```

## Adding a New Module Provider

1. Create a class implementing `ModuleComprehensionProvider`
2. Declare `entities()`, `workflows()`, `actions()`, `capabilities()`, `invariants()`, `expectedEffects()`, `testScenarios()`
3. Each action declares its causal chain as `ChainLink` objects
4. Register in `run.php` under the match expression
5. Register with `CrossModuleAnalyzer` for cascade detection

```php
class MyModuleProvider implements ModuleComprehensionProvider
{
    public function actions(): array
    {
        return [
            new ActionContract(
                id: 'my-module.my-action',
                label: 'Do Something',
                entityType: 'my.entity',
                route: '/api/v1/my-module/do-something',
                chain: [
                    new ChainLink('button.visible', 'Button visible', 'ui'),
                    new ChainLink('http.request', 'API called', 'http'),
                    new ChainLink('db.record_created', 'Record created', 'db',
                        probe: "SELECT id FROM my_table ORDER BY id DESC LIMIT 1"),
                    new ChainLink('audit.logged', 'Audit logged', 'audit'),
                ],
            ),
        ];
    }
}
```

## Output Format

The engine produces a JSON report at `test_results/ai/comprehension-report.json`:

```json
{
    "engine_version": "2.0-semantic",
    "module": { "entities": [...], "workflows": [...], "actions": [...] },
    "analysis": {
        "breakpoint": "workflow.transition",
        "break_category": "service",
        "deterministic": { "chain": [...], "breakpoint": "..." },
        "semantic": { "per_link_scores": {...} },
        "bayesian": {
            "per_link": {...},
            "action_history": [...]
        },
        "temporal": {
            "order_score": 0.9,
            "violations": [...],
            "anomalies": [...]
        },
        "diagnosis": {
            "primary_classification": { "category": "csrf", "score": 0.5, "confidence": "low" },
            "full_classification": { "categories": [...], "dominant": "csrf", "diagnosis": "..." }
        },
        "anomalies": {
            "unexpected_evidence": [...],
            "missing_links": [...]
        },
        "cross_module": {
            "cross_module": false,
            "cascade": [...],
            "recommendations": [...]
        },
        "root_cause_hypothesis": {
            "summary": "Break at step 'workflow.transition' (service)\nCSRF token mismatch...",
            "severity": "warning",
            "action": "Check service layer logic and parameters"
        },
        "confidence": { "score": 0.54, "factors": {...}, "label": "medium" }
    },
    "runtime": { "button.visible": true, ... },
    "bayesian_history": [...]
}
```
