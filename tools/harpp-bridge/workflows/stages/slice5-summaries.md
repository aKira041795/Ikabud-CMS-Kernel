You are gpt-5.4, executing SLICE 5 (final) of the HARPP hardening roadmap: **phone-first summaries**.

Repo root (workspace): {{WORKSPACE}}  (= /var/www/html/applicationostest)
Previous stage output: {{PREV_OUTPUT}}  (slice 4 added the DEC-xxxx decision recorder)

Scope: `tools/harpp-bridge` ONLY (harpp_wake.py, harpp, tests/test_harpp_wake.py). Do NOT touch modules/harpp, kernel/, or /var/www/html/harpp. Do NOT commit/push. Do NOT spawn nested agents. Never print secrets. Python stdlib only.

# Mission
Do not send desktop-sized agent output to the phone. Summarize upward: a compact, one-screen status that answers three questions — **What is HARPP doing? / Does HARPP need me? / Is the result safe to move forward?** Detailed reports stay available via `harpp workflow show <id>` and the job logs.

# Deliverables
1. **Summary formatter**: a `summarize(stage_report) -> compact text` used whenever a job/workflow stage completes and the harness notifies the owner. Compact shape (informational types only — never suppress DECISION_REQUIRED/BLOCKED/RELEASE_READY details):
   ```
   <TASK> — <STATE e.g. IMPLEMENTATION COMPLETE>
   Changed      <n> files
   Unit         <x>/<y> ✓
   Integration  <x>/<y> ✓
   Playwright   <x>/<y> ✓
   Repairs      <r>/<max>
   Scope        ✓
   Review       ✓ / remediation…  (or PENDING)
   Next: <release gate / awaiting decision / blocked(reason)>
   [Details]
   ```
   Values that are unknown are omitted, not fabricated; no secrets; no huge log tails (cap at ~600 chars, prefer counts + the actionable line).
2. **Wire into the report/notify path**: owner-facing completion/status messages use the summary for INFO/PROGRESS/WARNING; DECISION_REQUIRED/BLOCKED/RELEASE_READY keep the structured detail (from slice 3) but still lead with the one-line status.
3. **Answer the three questions explicitly**: the summary must always make clear (a) what stage is running/next, (b) whether the owner must act (and the single action), (c) whether results are verified/safe to proceed.

# Tests
Cover: summary shape (counts/next line), unknown values omitted, no secret leak, summary for INFO/PROGRESS vs full detail for DECISION_REQUIRED/BLOCKED.

# Verify
- python3 -m py_compile on every touched file.
- python3 tools/harpp-bridge/harpp self-test must pass (>= 70 tests OK; keep prior tests green).

# Output
Concise report. End with EXACTLY one line, nothing after it:
SOL_SLICE5 status=PASS
only if compile + self-test pass and the deliverables are implemented. Otherwise: SOL_SLICE5 status=FAIL followed by the specific gap.
