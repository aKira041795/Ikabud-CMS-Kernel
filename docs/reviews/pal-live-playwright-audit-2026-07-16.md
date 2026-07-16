# PAL Live Playwright Audit — 2026-07-16

## Scope and environment

- Target: `http://palsystem.test`
- Module: Project Audit Ledger (PAL)
- Browser: Playwright Chromium, desktop viewport 1280 × 720
- Authentication: superadmin credentials supplied for this audit
- Database: the configured `.env` database path was exercised successfully by the PAL seed, create, transition, verification, and cleanup operations. No secret values are reproduced here.
- Related architecture plan: [ARK Workbench Comprehension Architecture Review and Plan](./ark-workbench-comprehension-architecture-review-and-plan-2026-07-16.md)

## Executive result

PAL's primary Job Order lifecycle is operational and semantically coherent. The browser successfully created a client and Job Order, added and renumbered line items, saved a draft, submitted it for approval, approved it, started it, confirmed the ongoing state, found it on the dashboard, and cleaned up the test records.

The audit also exposed a significant testing-observability gap: several PAL views only emit `data-wb-*` semantic component markers when `wb_inspect=1` is present. Consequently, contract tests using inspection mode pass, while relationship and deep-navigation tests using normal URLs report missing components even though screenshots show the UI rendered correctly. This is a harness/semantic-contract inconsistency, not nine independent product failures.

## Evidence summary

### Hybrid analysis

- Static comprehension: 54 pages, 210 fields, 29 creatable views, 239 data-flow edges.
- Dynamic diagnostic: 313 checks passed, 34 informational findings.
- Behavioral flow: Job Order creation completed and redirected to project `568`.
- Issue normalization: 10 raw issues—five console 404s and their five matching HTTP 404 events.
- Comprehension engine: no chain breakpoint; low-confidence latent anomaly hypotheses were generated for `pal.job-order.create` and `pal.job-order.submit`.

The five 404 pairs came from diagnostic visits to sample-dependent routes: audit trail, inventory detail `1`, issuance creation, material-return creation, and project detail `1`. These must be rechecked with discovered valid IDs and prerequisites before being treated as application defects.

### Focused PAL verification

- 39 of 43 focused tests passed.
- All 31 route-coverage checks passed.
- Dashboard visual baseline passed.
- Dashboard summary-card contract passed in inspection mode.
- Project-list entity contract passed in inspection mode.
- Status badges exposed visible text.
- JO form semantic test passed with no gaps discovered.
- Full draft → pending → approved → ongoing lifecycle passed.
- Four context-preservation tests failed because normal URLs did not expose inspection-only component markers.

### Broader navigation sample

The broader 108-test run was stopped after 13 cases because repeated 10–15 second timeouts had already established the common marker mismatch. Four cases passed and nine failed before termination. Screenshots confirmed that dashboard cards, Job Order tables, inventory, purchases, sales, and other views rendered; the assertions were waiting for absent `data-wb-component` markers. Two additional failures used the ambiguous selector `h1`, which correctly matched both the shell title and page title. Those are test-selector defects.

## Logic flow and semantic coherence

### What is coherent

- The main operational state sequence is consistent across form, API, detail view, approval queue, and dashboard.
- Draft-only actions include save and submit; pending and later states alter available actions as expected.
- Line-item insertion, sequential numbering, deletion, renumbering, persistence, and detail rendering agree.
- Sidebar active state persists across direct navigation and reload.
- Financial-health terminology and contract/expense/collection/profit groupings are understandable.

### Logic and meaning gaps

1. The hybrid flow reported “Add Item clicked but no row appeared,” while the dedicated semantic form test added three rows successfully. The generic flow used a broad `button:has-text("Add")` selector and observed the wrong target or row contract. Generic behavioral actions need manifest-bound selectors and before/after node evidence.
2. Diagnostic routes use hard-coded entity ID `1` and do not seed prerequisites for issuance/return pages. Route traversal must discover valid entities and prerequisite states from the process graph.
3. Normal rendered pages and inspection-mode pages expose different semantic surfaces. This prevents reliable relationship testing and makes process-map nodes appear missing outside Workbench inspection mode.
4. Duplicate console and HTTP reports represent one network failure but are counted as two issues. Issue learning should correlate them by request URL, status, time window, and page.
5. The engine assigned low-confidence template/unknown diagnoses despite a successful lifecycle. Successful downstream transitions should reduce the probability of upstream functional failure and classify remaining anomalies as observability or test-contract hypotheses.

## UI function and relationship gaps

- Semantic component IDs are conditional. Either make stable `data-wb-*` attributes part of production-safe HTML or ensure every Workbench traversal consistently enables inspection mode.
- Shell and content both use `h1`; this is valid HTML but makes automated and assistive interpretation less precise. Use one page-level `h1`, with the product/shell name represented by a non-page heading or labelled landmark.
- Job Order table headers wrap aggressively (`CLIENT NAME`, `CONTRACT AMOUNT`, `START DATE`) at 1280 px while the ID column is duplicated as both project code and numeric ID. Clarify which identifier users act on and hide the internal numeric ID unless needed.
- Negative cash flow is visually clear, but the supporting expression (“₱0.00 ops + ₱5,000.00 fab”) does not explain the subtraction. Use a labelled formula or tooltip.
- Emoji icons vary in visual weight and platform rendering. A consistent icon set would improve scanability and accessibility.
- The sidebar is information-dense and extends below the viewport. Preserve section state, add a compact mode, and ensure keyboard focus and scroll position are visible.
- Empty and prerequisite-dependent routes need explicit guidance (for example, “select a project before issuing materials”) rather than allowing generic diagnostics to encounter a 404.

## Approved-plan execution mapping

The approved ARK Workbench plan remains the implementation authority. Live PAL evidence refines its phased work as follows:

1. **Phase 1 — Evidence integrity:** deduplicate console/HTTP twins; attach screenshots, request IDs, selector provenance, and inspection-mode state. Re-run until no duplicate issue inflation remains.
2. **Phase 2 — Semantic surface:** normalize stable page, component, entity, action, and relationship markers across normal and inspection rendering. Repair ambiguous browser selectors. Re-run contract plus context suites until both agree.
3. **Phase 3 — Graph accuracy:** replace sample IDs with entity discovery and prerequisite-aware traversal. Model client → JO → line item → approval → inventory/issuance → finance edges explicitly. Re-run and inspect every unresolved node.
4. **Phase 4 — Learning loop:** store confirmed defect, false positive, test defect, and prerequisite-missing outcomes as cases. Correlate recurrence by fingerprint and require human confirmation before durable promotion. Re-run known cases after every rule change.
5. **Phase 5 — Dijkstra-inspired hybrid search:** weight graph edges using failure probability, business criticality, state-transition risk, evidence freshness, execution cost, and prior coverage. Prefer the lowest-cost path to high-risk unverified nodes, while reserving exploration budget for new paths. Re-run against seeded and production-shaped datasets.
6. **Phase 6 — AI policy integration:** read the superadmin Workbench provider/model settings at run start, record the effective provider decision, use AI for hypothesis ranking and semantic explanation, and retain deterministic gates for pass/fail. Test configured, unavailable, degraded, and disabled provider states.
7. **Phase 7 — PAL UX corrections:** address identifiers, headings, financial explanations, icon consistency, sidebar density, and prerequisite guidance. Run visual, keyboard, responsive, and lifecycle regression loops before completion.

Each phase exits only after its focused tests pass, its new findings are triaged, gaps are corrected, and the phase is rerun. Findings discovered in later phases are routed back to the earliest responsible phase, then the forward sequence resumes.

## Recommended acceptance gates

- Normal-mode and inspection-mode semantic manifests describe the same visible components and relationships.
- No hard-coded sample entity IDs in module diagnostics.
- One underlying request failure produces one correlated issue with multiple evidence sources.
- JO lifecycle and cleanup pass repeatedly with unique test data.
- Process graph contains no unresolved route node without a documented prerequisite or intentional terminal state.
- AI provider/model selection exactly matches effective superadmin settings and is visible in the run report.
- Learned cases distinguish confirmed defects, false positives, environment failures, and test defects.
- PAL desktop and responsive screenshots pass approved visual baselines; keyboard navigation has no blocked actions.

## Conclusion

PAL is functionally stronger than the raw navigation failure count suggests. Its central workflow passed, route coverage is broad, and the UI is usable. The highest-priority correction is the mismatch between normal-page semantics and Workbench inspection semantics. Fixing that contract will make relationship maps more accurate, eliminate many false failures, and give the comprehension engine cleaner evidence for learning and AI-assisted path selection.
