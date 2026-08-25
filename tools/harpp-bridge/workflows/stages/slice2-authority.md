You are gpt-5.4, executing SLICE 2 of the HARPP hardening roadmap: **authority levels + automatic escalation**.

Repo root (workspace): {{WORKSPACE}}  (= /var/www/html/applicationostest)
Previous stage output: {{PREV_OUTPUT}}

Scope: `tools/harpp-bridge` ONLY (harpp_wake.py, harpp, harpp_client.py, tests/test_harpp_wake.py). Do NOT touch modules/harpp, kernel/, or /var/www/html/harpp. Do NOT commit/push (the harness reviews + commits). Do NOT spawn nested agents. Never print secrets. Python stdlib only; no new deps.

# Context
Slice 1 added durable run identity (run_id/task_id/contract_revision/state/git shas/human_decisions), global budgets (max_* -> BLOCKED_BUDGET_EXHAUSTED), and deterministic resume. This slice adds the governance layer.

# Model (from the owner's roadmap review)
- L0 OBSERVE (read/search/report)
- L1 EXECUTE (edit/test/Playwright/repair)
- L2 REVIEW_CYCLE (automatic implement <-> review loop)   <- autonomous default
- L3 DELIVERY (prepare commit / release candidate)
- L4 RELEASE (production-affecting action)                <- human approval required

# Deliverables
1. **Authority on the run record**: add `authority_level` to jobs/workflows (default L2). Config: `harpp_authority` (default "L2") + `authority_policy` map (which levels are autonomous vs human-approval) in harpp_client config defaults.
2. **Automatic escalation overrides (fail-closed)**: when a stage/action would (a) change architecture/contract, (b) break a contract, (c) risk data loss, (d) require a security exception, or (e) expand scope beyond the contract — the engine MUST NOT act. Detect an escalation signal (a stage FAILED with code `escalation_required`, or a stage/action whose required authority exceeds the configured authority) and set the workflow state = `ESCALATED` with `escalation_reason`, NOTIFY the owner with a structured DECISION_REQUIRED message (what, why, options, recommendation, risk), and STOP — never auto-repair past an escalation.
3. **L3/L4 gating**: actions requiring L3/L4 (delivery/release) only proceed when the configured `harpp_authority` >= required; otherwise escalate for human approval (default: L4 always human-approval).
4. **Wire into advance_workflows/launch**: check required authority before launching a stage; treat `escalation_required` outcomes as ESCALATED (not repairable).

# Tests
Cover: authority default present on records; each override condition (architecture change / contract break / data loss / security exception / scope expansion) yields ESCALATED with no auto-repair; a required authority above the configured level escalates; `escalation_required` outcome never auto-repairs.

# Verify
- python3 -m py_compile on every touched file.
- python3 tools/harpp-bridge/harpp self-test must pass (>= 65 tests OK).

# Output
Concise report (what changed, file:line; how escalation works). End with EXACTLY one line, nothing after it:
SOL_SLICE2 status=PASS
only if compile + self-test pass and the deliverables are implemented. Otherwise: SOL_SLICE2 status=FAIL followed by the specific gap.
