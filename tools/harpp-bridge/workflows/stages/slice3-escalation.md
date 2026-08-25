You are gpt-5.4, executing SLICE 3 of the HARPP hardening roadmap: **message-type escalation**.

Repo root (workspace): {{WORKSPACE}}  (= /var/www/html/applicationostest)
Previous stage output: {{PREV_OUTPUT}}  (slice 2 added authority levels + ESCALATED)

Scope: `tools/harpp-bridge` ONLY (harpp_wake.py, harpp, harpp_client.py, tests/test_harpp_wake.py). Do NOT touch modules/harpp, kernel/, or /var/www/html/harpp. Do NOT commit/push. Do NOT spawn nested agents. Never print secrets. Python stdlib only.

# Mission
Distinguish ordinary status messages from ACTUAL decisions, so the owner is not fatigued by notifications and only a real decision/blocked/release-ready item demands attention.

# Message types (add to notifications the harness sends the owner)
- `INFO` — informational (no response)
- `PROGRESS` — stage/job activity (no response)
- `WARNING` — non-blocking concern (no response)
- `DECISION_REQUIRED` — ACTIONABLE: owner must choose (created by slice-2 escalation / authority gating)
- `BLOCKED` — ACTIONABLE: workflow stopped with a reason (BLOCKED_BUDGET_EXHAUSTED, ESCALATED, FAILED)
- `RELEASE_READY` — ACTIONABLE: gate passed, awaiting owner go/no-go for L4 release
- `FAILED` — unrecoverable failure

# Deliverables
1. **Type tagging**: every owner-facing message the harness sends carries a compact type prefix (e.g. `[DECISION_REQUIRED]`, `[BLOCKED]`, `[PROGRESS]`) — helper `harpp_notify(conv, type, body)` in the client/harness.
2. **DECISION_REQUIRED → real decision**: a DECISION_REQUIRED notification must ALSO create a decision-request through the existing bridge decision flow (the `harpp decision submit` equivalent) with a structured body: what, why, options (A/B/C), recommendation, risk — so it appears in the Decision Center, gets a push, and is recorded as an ADR when decided. Do NOT invent a second messenger.
3. **Correct classification in the engine**: job/stage DONE -> PROGRESS/INFO; workflow BLOCKED (budget/authority/failed) -> BLOCKED with the precise reason; gate stage PASS -> RELEASE_READY; unrecoverable -> FAILED; slice-2 escalation -> DECISION_REQUIRED.
4. **No-response vs response**: INFO/PROGRESS/WARNING are fire-and-forget; DECISION_REQUIRED/BLOCKED/RELEASE_READY are the only ones that create decision-requests / expect owner action.

# Tests
Cover: type prefix on messages; DECISION_REQUIRED creates a decision-request (mock the bridge submit); classification of DONE/BLOCKED/RELEASE_READY/FAILED/ESCALATED; no decision-request for INFO/PROGRESS.

# Verify
- python3 -m py_compile on every touched file.
- python3 tools/harpp-bridge/harpp self-test must pass (>= 66 tests OK; keep prior tests green).

# Output
Concise report. End with EXACTLY one line, nothing after it:
SOL_SLICE3 status=PASS
only if compile + self-test pass and the deliverables are implemented. Otherwise: SOL_SLICE3 status=FAIL followed by the specific gap.
