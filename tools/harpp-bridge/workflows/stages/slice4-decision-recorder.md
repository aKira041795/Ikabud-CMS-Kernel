You are gpt-5.4, executing SLICE 4 of the HARPP hardening roadmap: **DEC-xxxx decision recorder**.

Repo root (workspace): {{WORKSPACE}}  (= /var/www/html/applicationostest)
Previous stage output: {{PREV_OUTPUT}}  (slice 3 added message-type escalation)

Scope: `tools/harpp-bridge` ONLY (harpp_wake.py, harpp, harpp_client.py, wake/task-contract.md, tests/test_harpp_wake.py). Do NOT touch modules/harpp, kernel/, or /var/www/html/harpp. Do NOT commit/push. Do NOT spawn nested agents. Never print secrets. Python stdlib only.

# Mission
The owner directs HARPP from their phone. Those directives must become DURABLE repository/project knowledge (DEC-xxxx records), not transient chat — so future gpt-sol / gpt-5.4 / deepseek sessions act on them WITHOUT needing the conversation history.

# Deliverables
1. **Local decision ledger**: `~/.config/harpp/decisions.json` (append-only array of DEC-xxxx records) with fields: id (DEC-0001...), task (task_id / workflow), decision, constraints, additional_requirements, source (human), applied_to (contract_revision / stage), created_at. CLI: `harpp decision record --task T --decision D [--constraint C] [--additional A] [--source human] [--applied-to X]` and `harpp decision list`.
2. **Auto-record directives**: the deterministic messenger router recognizes owner messages that are directives/instructions (not status questions / not workflow commands / not simple acknowledgements) and records them as DEC-xxxx (append-only; idempotent by message id so it never double-records).
3. **Surface to agents**: the wake task-contract prompt includes recent DEC-xxxx records via a `{{DECISIONS}}` placeholder filled from the ledger (latest N, e.g. 8), so wake agents honor durable directives. Keep the ledger bounded (retain latest, e.g. 200).
4. **Security**: never store secrets; strip anything that looks like a credential/bridge key before recording.

# Tests
Cover: record + list; idempotent auto-record from a directive message (same message id not re-recorded); status/question messages are NOT recorded; {{DECISIONS}} injected into the task prompt; ledger bounded.

# Verify
- python3 -m py_compile on every touched file.
- python3 tools/harpp-bridge/harpp self-test must pass (>= 68 tests OK; keep prior tests green).

# Output
Concise report. End with EXACTLY one line, nothing after it:
SOL_SLICE4 status=PASS
only if compile + self-test pass and the deliverables are implemented. Otherwise: SOL_SLICE4 status=FAIL followed by the specific gap.
