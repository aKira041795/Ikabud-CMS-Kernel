# ARK Workbench Comprehension Engine and Hybrid Algorithm

**System architecture review and implementation plan**  
**Date:** 2026-07-16  
**Status:** Implemented through Phase 6  
**Scope:** ARK Workbench, comprehension engine, issue learning, process maps, and hybrid test selection

## Executive decision

Yes, ARK Workbench can be improved to closely leverage the AI provider and model configured by a kernel superadmin. The current implementation does not yet do so: the Workbench saves provider credentials and model names, while `AiHypothesisGenerator` explicitly accepts only `heuristic`. The visible “AI Steward” reads a pre-generated JSON file instead of invoking the configured provider.

The right target is an **evidence-first adaptive testing system**, not an AI-controlled test runner:

1. Deterministic contracts, runtime observations, database probes, and test assertions remain authoritative.
2. AI receives a bounded, redacted evidence packet and returns schema-validated hypotheses, graph suggestions, and test recommendations with evidence references.
3. Every issue is recorded, fingerprinted, and linked to affected graph nodes.
4. Only verified diagnoses and fixes become durable case memory or modify learned weights.
5. A canonical, provenance-aware graph replaces the current overlapping and partly inferred process maps.
6. Dijkstra-style weighted traversal prioritizes high-value test paths and finds the most probable causal explanation; k-shortest paths and coverage optimization prevent tunnel vision.

This is feasible, but it should be delivered in phases. AI wiring should not be added before the evidence schema, graph identity rules, issue lifecycle, security controls, and evaluation baseline are fixed.

## Direct answers

### Can Workbench closely leverage AI configured by the superadmin?

**Yes, with an adapter and explicit Workbench policy settings.**

The AI module already exposes `ai.text.generate@1`, resolves the active provider from AI module settings, supports JSON output, and dispatches to OpenAI, Groq, Ollama, Gemini, Cerebras, OpenRouter, or Mistral. Workbench should call that capability rather than implement provider-specific HTTP clients.

However, the current Workbench screen configures credentials and free/paid/custom models per provider but does not clearly select the active Workbench provider, tier, fallback, budget, or data policy. Add Workbench-specific controls:

- AI analysis enabled/disabled.
- Active provider, inherited from AI settings or explicitly selected for Workbench.
- Model tier: free, paid, or custom.
- Heuristic-only fallback behavior.
- Timeout, maximum tokens, per-run call limit, and daily budget.
- Source/log inclusion and redaction policy.
- Prompt version and response schema version.
- Minimum evidence quality and confidence required before an AI call.
- Human approval requirement for promoting a diagnosis or graph suggestion.

### Can it learn every new issue found?

**It should capture every issue, but it must not learn every issue as truth.**

Learning directly from all findings would amplify flaky tests, duplicate reports, environmental failures, incorrect AI diagnoses, and possibly poisoned evidence. Use two levels:

- **Observation memory:** persist every normalized issue and occurrence.
- **Validated knowledge:** promote only issues with a verified outcome, such as reproduced, fixed and regression-tested, accepted as known, or explicitly dismissed with a reason.

Bayesian link history already learns pass/fail counts, and Case Memory already stores resolved cases. The missing piece is an automatic, governed issue lifecycle that connects findings, diagnoses, fixes, verification runs, and graph weights.

### Can process maps become clearer and nodes more accurate?

**Yes, by replacing guesses with a canonical graph and explicit provenance.**

Today, declared contracts, regex template analysis, manifest route parsing, a PHP process-map script, and `GraphBuilder` produce different representations. Several inferred mappings are presented too confidently. The new graph must label facts as declared, statically discovered, runtime observed, AI inferred, or human verified.

### Can Dijkstra's idea improve the hybrid testing algorithm?

**Yes, if edge weights express the correct objective.**

Plain shortest path is insufficient. ARK needs two weighted traversals:

- **Test planning:** minimize execution cost relative to expected information gain, risk, novelty, and coverage deficit.
- **Causal diagnosis:** minimize negative log likelihood, so the shortest explanation is the most probable evidence-supported causal path.

Use Dijkstra only with non-negative weights, then add k-shortest paths and a coverage-budget selector so one cheap or historically failing route does not monopolize the suite.

## Current implementation assessment

### What is strong

- The deterministic engine separates expected contracts from runtime evidence and reports the first causal breakpoint.
- The semantic engine has layered analysis: deterministic, lexical/embedding-proxy scoring, Bayesian history, temporal checks, pattern/anomaly detection, cross-module analysis, heuristic diagnosis, and provider coverage.
- Bayesian history uses a sensible Beta-Binomial starting model and records run metadata.
- Case Memory supports persistence, locking, deduplication tags, and similar-case retrieval.
- The hybrid browser run combines static inspection, dynamic rendering, behavioral interaction, and the PHP engine.
- Test execution is restricted to an authorized registry and protected by a production environment gate.
- Superadmin APIs consistently require both the kernel source and superadmin role.
- AI provider settings are treated as global infrastructure rather than accidentally tenant-local.

### Main findings

| Priority | Finding | Evidence and impact |
|---|---|---|
| Critical | Configured AI is disconnected from comprehension. | `run.php` documents “heuristic only”; `AiHypothesisGenerator` supports only `heuristic`; the unit tests require OpenAI to be rejected. Superadmin provider/model settings therefore do not affect engine diagnosis. |
| Critical | Missing evidence is treated as failure. | `ModuleComprehensionEngine::probeLink()` returns `false` when a step is unobserved. This conflates **failed**, **not observed**, **not applicable**, and **probe error**, corrupting breakpoints and Bayesian history. |
| Critical | Hybrid evidence loses action identity. | `run.php` flattens structured steps into a map keyed only by step name. Repeated steps from different actions overwrite each other, while the engine may analyze every declared action against the same flattened evidence. |
| High | Learning is manual and incomplete. | Case Memory is stored only through explicit `--store-case`; the issue API aggregates JSON but does not fingerprint, deduplicate, resolve, verify, or promote cases. |
| High | The visible AI Steward is file-backed, not an AI service. | The Workbench issue endpoint reads `test_results/ai/steward-diagnosis.json`; the UI merely renders that content. No on-demand provider call, model trace, prompt version, or fallback status is shown. |
| High | Graph adjacency can become inconsistent. | `GraphBuilder` creates placeholder action nodes while building workflows, then `addNode()` replaces them during action building. Replacement loses the node's accumulated adjacency arrays even though edge records remain. |
| High | Chain nodes are not a chain. | Each step has an `action -> step` edge, but consecutive `step -> step` edges are absent. `computePaths()` returns ordered arrays with empty edge lists instead of traversing the graph. |
| High | Generated lifecycle paths can be false. | Workflow states are collected in declaration order and labeled first-to-last without following transition edges. Branches, loops, invalid transitions, and unreachable states are not represented accurately. |
| High | Static process comprehension guesses entity ownership. | `_resolveEntityColumn()` maps `*_id` using naming convention and maps other fields to the first owned table. `_resolveCreatableTarget()` pluralizes target names heuristically. These guesses can create confidently wrong nodes and destinations. |
| High | Multiple graph/map implementations can drift. | `GraphBuilder`, `ModuleComprehensionEngine::buildGraph()`, `ProcessComprehension`, and `tests/ai/comprehend-process.php` have separate extraction and output logic with no canonical merge contract. |
| High | Provider scope is effectively one module. | The CLI hard-codes `project-audit-ledger`; the hybrid spec separately hard-codes the same provider availability. Other modules skip the engine or throw “No comprehension provider.” |
| High | AI credential handling needs correction before wider use. | The Workbench supports seven providers and claims all API keys are encrypted, but `aiSensitiveKeyNames()` lists only OpenAI, Groq, and search grounding keys. Other provider credentials are not covered by that encryption list. |
| Medium | No explicit active Workbench provider is configured. | The Workbench table saves per-provider credentials/models but does not set `provider`, tier, or Workbench-specific model policy. AI dispatch depends on the AI module's separate resolved `provider` setting. |
| Medium | The “embedding” scorer is local lexical similarity. | It uses regex, TF-IDF-like overlap, character n-grams, word-frequency vectors, and history—not provider embeddings. The name and confidence presentation can overstate semantic strength. |
| Medium | Confidence is partly synthetic. | Overall confidence hard-codes `ai_confidence = 0.5`; it does not use the generated hypothesis confidence, evidence independence, sample size, or calibration error. |
| Medium | Bayesian samples can be biased and duplicated. | Every analyzed chain result is recorded independently; there is no idempotency key, flaky/environmental classification, recency decay, or distinction between observation coverage and outcome. |
| Medium | Case similarity is narrow. | It only searches within the same module and uses top-level key Jaccard plus simple word overlap. It cannot learn a reusable cross-module pattern such as CSRF, schema drift, or capability failure well. |
| Medium | Run discovery does not fully model run-scoped artifacts. | Several APIs scan flat result directories while hybrid artifacts can be stored below run-specific directories. This can make the cockpit incomplete or inconsistent. |
| Medium | Tests affirm current limitations. | Tests verify heuristic-only behavior and basic persistence, but do not evaluate diagnosis accuracy, graph correctness, confidence calibration, provider fallback, learning promotion, or weighted traversal. |

## Target architecture

```text
Test/static/runtime sources
        |
        v
Evidence Normalizer -----> Immutable Run Store
        |                         |
        v                         v
Canonical Provenance Graph <-> Issue Ledger
        |                         |
        +---- Deterministic Engine+
        |                         |
        +---- Bayesian Weights ---+
        |                         |
        +---- AI Diagnosis Adapter (configured provider, schema validation)
        |                         |
        v                         v
Weighted Path Planner       Verification & Human Verdict
        |                         |
        v                         v
Hybrid Test Plan            Validated Case Memory / learned weights
```

### 1. Evidence model

Replace the flat boolean map with a versioned event schema. Each observation should include:

- `run_id`, `observation_id`, timestamp, module, tenant-safe context.
- `action_id`, `step_id`, attempt number, source, and source version.
- Outcome enum: `passed`, `failed`, `unobserved`, `not_applicable`, `probe_error`, `skipped`.
- Expected and actual values with type information.
- Error category, severity, duration, environment, commit, and source fingerprint.
- References to route, handler, entity, field, state, capability, test, and evidence artifact.
- Redaction classification and content hash.

The deterministic engine must report the first **observed failure**, not the first absent key. Unobserved steps reduce coverage confidence but must not train failure probability.

### 2. Canonical process graph

Use stable namespaced IDs such as:

```text
pal:route:POST:/api/v1/pal/projects/{id}/status
pal:handler:palApiProjectStatus
pal:action:pal.job-order.transition
pal:step:pal.job-order.transition:http.request
pal:entity:pal.project
pal:field:pal.project.status
pal:state:pal.project:draft
pal:test:pal-lifecycle-interactive
pal:issue:<fingerprint>
```

Minimum node types:

- module, route, handler, action, chain step;
- capability and policy guard;
- entity, field, table, relationship;
- workflow and workflow state;
- template, component, event, side effect;
- test, observation, issue, diagnosis, remediation, and verification.

Every node and edge must carry:

- provenance: `declared`, `static`, `runtime`, `ai_inferred`, `human_verified`;
- confidence and verification status;
- source file and line or runtime trace reference;
- first seen, last seen, source fingerprint, and schema version;
- module and tenant-scope classification;
- evidence IDs supporting or contradicting it.

Graph invariants:

- An edge cannot exist unless both endpoints exist.
- Re-adding a node merges metadata and preserves adjacency.
- Chain steps have explicit ordered edges.
- Workflow paths are derived from transition edges, never array order.
- Inferred nodes are visually and programmatically distinct from verified nodes.
- Route normalization is shared across discovery, graph, tests, and UI.
- Contradictory sources create a reconciliation finding rather than silently overwriting data.

### 3. AI diagnosis adapter

Create a Workbench-facing interface, for example `WorkbenchAiAnalyzer`, backed by the capability bus:

```php
app()->cap()->call('ai.text.generate@1', [
    'messages' => $messages,
    'json' => true,
    'preferred_tier' => $policy->tier,
    'timeout_ms' => $policy->timeoutMs,
    'max_tokens' => $policy->maxTokens,
], ['caller_module' => 'kernel.workbench']);
```

AI input should contain only:

- canonical graph neighborhood around the failure;
- normalized observations and deterministic breakpoint candidates;
- temporal/Bayesian summaries with sample sizes;
- retrieved source snippets with file/line references;
- similar verified cases;
- explicit uncertainty and missing evidence.

Require a JSON schema response containing:

- hypotheses ranked by confidence;
- evidence IDs for and against each hypothesis;
- suspected nodes/files, violated invariant, and missing evidence;
- proposed next tests and expected information gain;
- graph additions as suggestions, never direct mutations;
- remediation outline, risk, and boundaries;
- provider, model, prompt version, latency, and usage metadata.

If the AI module is disabled, provider configuration is incomplete, the budget is exhausted, the call times out, or schema validation fails, return the existing heuristic result with an explicit `fallback_reason`.

### 4. Issue learning loop

Introduce an append-only issue ledger with these states:

```text
observed -> clustered -> triaged -> reproduced -> diagnosed
         -> fixed -> verified -> promoted_to_case
         -> dismissed / accepted / flaky / environment_only
```

Fingerprint issues from normalized dimensions rather than raw messages:

```text
hash(module + action + failing_node + error_category + normalized_signature + source_fingerprint)
```

Each new occurrence updates frequency, affected environments, recency, and graph links. It does not create a duplicate issue.

Promote knowledge only when:

- a subsequent run verifies the fix or a human explicitly validates the outcome;
- changed files and graph nodes are known;
- the resolving test is recorded;
- the case has no unresolved contradiction;
- sensitive content has been removed.

Store negative feedback too: incorrect AI hypothesis, ineffective remediation, flaky test, and false graph suggestion. Retrieval should favor verified cases and penalize rejected hypotheses.

### 5. Dijkstra-inspired hybrid algorithm

#### A. Test-path planning

For each edge `e`, maintain:

```text
risk(e)       = Bayesian failure probability with recency and sample confidence
novelty(e)    = time/source changes since last successful traversal
gap(e)        = coverage deficit
impact(e)     = downstream business and cross-module impact
uncertainty(e)= missing or conflicting evidence
cost(e)       = measured execution time and setup cost
```

Define expected test value:

```text
value(e) = wr*risk + wn*novelty + wg*gap + wi*impact + wu*uncertainty
planning_weight(e) = cost(e) / max(epsilon, value(e))
```

All terms must be normalized and weights must be non-negative. Dijkstra then finds the cheapest high-value route from an entry point to an observable effect. Use measured setup/cleanup costs and include state preconditions.

Do not stop at one shortest path:

1. Generate the top `k` paths with a k-shortest-path algorithm such as Yen's algorithm.
2. Select a suite under the time budget using greedy weighted set cover over nodes, edges, risks, and invariants.
3. Reserve exploration budget for low-history and newly changed edges.
4. Apply diversity penalties so near-identical routes are not repeatedly selected.
5. Always include mandatory critical invariants regardless of learned weight.

#### B. Causal diagnosis

Build a failure subgraph from observed evidence. For a candidate causal edge:

```text
diagnostic_weight(e) = -log(P(e explains evidence | history, observation, source change))
```

Dijkstra finds the minimum-weight, maximum-likelihood explanation path from an observed symptom toward plausible causes. AI may propose probabilities, but deterministic evidence and calibrated historical statistics constrain them.

Return multiple explanations when scores are close. A single “shortest” explanation must not be shown as certain.

#### C. Online update

After each run:

- Update only observed edge outcomes.
- Deduplicate by run and observation ID.
- Separate product failures, test failures, environment failures, and probe failures.
- Apply optional recency decay so obsolete behavior loses influence.
- Recompute weights after verified outcomes, not midway through a run.
- Record the policy version so a test plan is reproducible.

## Delivery plan

### Phase 0 — Baseline and contracts (1 sprint)

**Goal:** Make current behavior measurable before changing it.

- Freeze evidence, issue, graph, AI-response, and run-manifest schemas as versioned JSON Schema files.
- Build a 30–50 case golden dataset covering CSRF, permission, validation, missing record, schema drift, UI selector, timeout, event, audit, flaky, and unobserved cases.
- Measure breakpoint precision, root-cause top-1/top-3 accuracy, false-positive rate, graph precision/recall, duplicate rate, calibration error, runtime, and AI cost.
- Add explicit tests proving unobserved is not failed and repeated structured steps retain action identity.
- Correct the provider credential encryption list and test encrypt/decrypt for every supported provider before enabling AI analysis.
- Define Workbench data retention and source/log redaction policy.

**Exit criteria:** schemas approved; baseline report stored; credential tests pass; no outcome ambiguity in the new schema.

### Phase 1 — Evidence and graph foundation (1–2 sprints)

**Goal:** Establish one accurate process model.

- Implement `EvidenceNormalizer` and preserve observations by action and step.
- Change deterministic outcomes to the explicit enum.
- Implement a canonical graph repository and graph validator.
- Fix node merge/adjacency behavior and reject dangling edges.
- Add ordered chain edges and derive workflow paths from transitions.
- Merge declared, manifest, static, and runtime sources with provenance.
- Replace regex-only DiSyL extraction with the existing parser/AST where available; keep regex only as low-confidence fallback.
- Deprecate separate map outputs or make them views of the canonical graph.
- Add graph snapshot and invariant tests, including branches, loops, cross-module edges, and conflicting sources.

**Exit criteria:** one graph API/output; zero dangling edges; all displayed nodes expose provenance; golden graph precision at least 95% for declared/runtime facts.

### Phase 2 — Configured AI integration (1 sprint)

**Goal:** Use superadmin policy safely and observably.

- Add Workbench active-provider/tier/policy settings.
- Implement `WorkbenchAiAnalyzer` via `ai.text.generate@1`.
- Create schema-constrained prompts and response validation.
- Add redaction, evidence-size limits, timeouts, budgets, caching by evidence/prompt/model hash, and circuit-breaker behavior.
- Preserve heuristic diagnosis as fallback.
- Show provider, model, prompt version, confidence, evidence citations, latency, token/usage data, and fallback reason in AI Steward.
- Do not allow AI to run SQL, execute tests, edit contracts, or mutate graph truth directly.

**Exit criteria:** all seven configured providers satisfy adapter contract tests or are marked unsupported; fallback works; no secret or prohibited evidence appears in captured prompts.

### Phase 3 — Issue ledger and verified learning (1–2 sprints)

**Goal:** Capture every issue and learn safely.

- Implement issue fingerprinting, clustering, lifecycle, and occurrence history.
- Ingest PHP, browser, comprehension, graph reconciliation, performance, and environment findings.
- Link each issue to graph nodes, evidence, runs, commits, tests, diagnoses, and remediations.
- Add human verdicts and automatic fix-verification matching.
- Promote verified resolutions to Case Memory automatically.
- Upgrade case retrieval with normalized signatures, categories, graph neighborhoods, and optional provider embeddings; allow cross-module reusable patterns.
- Store rejected hypotheses and failed fixes as negative knowledge.
- Add retention, export, purge, and access-control tests.

**Exit criteria:** 100% of golden issues recorded; duplicates clustered above 95%; no unverified issue promoted; verified cases are retrieved in top 3 for at least 85% of matching golden cases.

### Phase 4 — Weighted graph testing (2 sprints)

**Goal:** Make hybrid testing adaptive and efficient.

- Implement learned edge statistics with sample size, recency, and failure class.
- Implement non-negative planning weights and Dijkstra traversal.
- Add Yen k-shortest paths and budgeted weighted set-cover suite selection.
- Add mandatory invariant paths, exploration quota, diversity penalties, and deterministic tie-breaking.
- Implement diagnostic `-log(probability)` traversal with top-k explanations.
- Persist the chosen plan, graph version, weights, and policy for reproducibility.
- Run shadow mode against the existing suite before allowing adaptive selection to gate CI.

**Exit criteria:** same or better critical defect detection with at least 25% lower median test time in the benchmark; no critical invariant omitted; identical inputs produce identical plans.

### Phase 5 — Clear process-map experience (1 sprint)

**Goal:** Make the model understandable and correctable.

- Display typed nodes with a compact legend and distinct shapes/colors by type.
- Visually distinguish declared, observed, inferred, stale, conflicting, and verified facts.
- Support workflow, request-to-effect, data lineage, test coverage, and issue-impact views.
- Show evidence and source location on node/edge selection.
- Collapse low-level chain steps by default and expand on demand.
- Show confidence and “why this edge exists.”
- Provide approve/reject/correct actions for inferred graph elements.
- Make the selected test path and causal explanation path separately visible.

**Exit criteria:** every visible node has a stable ID, type, provenance, and source/evidence; no inferred edge is visually presented as verified.

### Phase 6 — Rollout and governance (continuous)

- Roll out by module: PAL first, two contrasting modules next, then general provider registration.
- Run heuristic and configured-AI diagnoses in shadow comparison.
- Calibrate confidence against actual verified outcomes.
- Track provider cost, latency, schema-failure rate, fallback rate, false positives, and accepted recommendations.
- Require human approval for high-risk remediation and contract/graph promotion.
- Add kill switches at global, provider, module, and run levels.

## Recommended implementation order

1. Fix credential coverage and outcome ambiguity.
2. Preserve structured action/step evidence.
3. Unify and validate the graph.
4. Add the issue ledger and verified promotion rules.
5. Wire configured AI through the capability bus.
6. Introduce weighted traversal in shadow mode.
7. Upgrade the Workbench visualization and enable adaptive gating gradually.

AI should be integrated after steps 1–4 because better models cannot compensate for ambiguous outcomes, flattened evidence, incorrect node identity, or missing feedback labels.

## Success metrics

| Area | Metric | Initial target |
|---|---|---|
| Evidence | Observations with explicit outcome/action/step identity | 100% |
| Diagnosis | Root cause top-3 accuracy on verified cases | >= 85% |
| Calibration | Expected calibration error | <= 0.10 |
| Issues | Duplicate clustering precision | >= 95% |
| Learning | Unverified cases promoted | 0 |
| Graph | Verified node/edge precision | >= 95% |
| Graph | Dangling edges | 0 |
| Testing | Critical invariant path coverage | 100% |
| Efficiency | Median runtime reduction at equal critical detection | >= 25% |
| AI | Schema-valid responses | >= 99% including retry/fallback |
| AI | Prompt secret leakage | 0 |
| Operations | Reproducible plans from recorded inputs | 100% |

## Key code reviewed

- `kernel/Workbench/Comprehension/ModuleComprehensionEngine.php`
- `kernel/Workbench/Comprehension/SemanticComprehensionEngine.php`
- `kernel/Workbench/Comprehension/Analyzers/AiHypothesisGenerator.php`
- `kernel/Workbench/Comprehension/Analyzers/BayesianReasoner.php`
- `kernel/Workbench/Comprehension/Analyzers/CaseMemory.php`
- `kernel/Workbench/Comprehension/Analyzers/EmbeddingScorer.php`
- `kernel/Workbench/Comprehension/Analyzers/ProviderCoverageScorer.php`
- `kernel/Workbench/Comprehension/run.php`
- `kernel/Workbench/Graph/GraphBuilder.php`
- `kernel/Workbench/Graph/ModuleGraph.php`
- `kernel/Workbench/Graph/SpecGenerator.php`
- `tests/browser/ProcessComprehension.js`
- `tests/browser/ModuleDiagnostic.js`
- `tests/browser/BehaviorFlow.js`
- `tests/browser/comprehension/EvidenceBridge.js`
- `tests/browser/hybrid-analysis.spec.js`
- `tests/ai/comprehend-process.php`
- `modules/ai/helpers.php` and provider helpers
- `src/http/superadmin-handlers.php`
- `templates/pages/superadmin-workbench.disyl`
- Workbench comprehension unit tests and sample run artifacts

## Final architectural position

ARK Workbench already contains useful pieces of an adaptive comprehension system, but it is currently a collection of partially connected analyzers rather than one learning architecture. Its most important next step is not “add an LLM.” It is to establish trustworthy evidence identity, a canonical provenance graph, and a verified issue lifecycle. Once those are in place, the configured AI provider can materially improve hypothesis generation, graph reconciliation, and next-test selection while the deterministic and statistical layers retain control.

## Implementation completion record

All approved phases were implemented on 2026-07-16 with a focused test-and-gap loop after each phase:

- Phase 0: five versioned schemas, 30-case golden baseline, credential coverage, and data governance.
- Phase 1: action-scoped normalized observations, censored outcomes, canonical graph validation, ordered chains, and canonical scanner paths.
- Phase 2: configured capability-bus AI adapter, scoped provider/model overrides, redaction, schema validation, caching, policy limits, provider trace, and heuristic fallback.
- Phase 3: issue fingerprinting, clustering, governed lifecycle, diagnosis feedback, regression verification, and verified Case Memory promotion.
- Phase 4: non-negative weighted Dijkstra traversal, Yen alternatives, budgeted suite selection, diagnostic likelihood paths, and persisted shadow plans.
- Phase 5: process-map API and Workbench view with typed nodes, provenance, confidence, evidence rationale, AI policy controls, and Steward trace fallback.
- Phase 6: provider registry, global/module/provider kill switches, deterministic rollout modes, metrics, repeatability checks, and final regression audit.

Focused verification: Phase suites 0–6 passed 59/59 assertions; the existing comprehension suite passed 18/18; ARK Steward passed 5/5; 40 Workbench browser tests were discovered successfully; all changed PHP and JavaScript files passed syntax checks; and `git diff --check` passed. The repository-wide standalone runner reported unrelated environment-dependent failures because database and `palsystem.test` HTTP access are unavailable in the execution sandbox.
