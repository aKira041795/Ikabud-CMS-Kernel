task: HARPP wake bridge — bounded single-flight headless-Pi agent wake on owner input
objective: >
  Extend the existing local `harpp watch` daemon (tools/harpp-bridge/harpp) with a guarded
  WAKE stage so that when a new owner message or newly-decided decision arrives above the
  watch cursor, exactly one headless Pi agent (provider-qualified model, e.g.
  deepseek/deepseek-v4-pro) is spawned with a structured, bounded task contract; it replies
  through the bridge, acknowledges and applies decided decisions, posts status, and exits.
  On cooldown/rate-limit/lock/agent-unavailable it falls back to the current staging +
  notify-send behavior. Chosen mechanism: E (watch→Pi agent with cooldown, rate limit,
  single-flight lock, and graceful fallback). Rejected: B (no public VS Code Copilot Chat
  API to trigger an autonomous prompt; reopen does not run the agent), C (MCP polling is
  on-demand and never wakes), D (systemd user timer adds a redundant scheduler; the existing
  watch daemon already polls/stages, keep it user-space).

scope:
  allowed:
    - New local files under tools/harpp-bridge/ only (zero PHP/DB changes):
      `harpp_wake.py` (wake scheduler), `wake/task-contract.md` (agent prompt template),
      `wake/` tests, and the single-flight lock + processed-id ledger under
      ~/.config/harpp/ (e.g. wake.lock, wake-processed.json).
    - Extend the `harpp watch` subcommand with wake flags: --wake (enable), --wake-command
      (override agent invocation; default from HARPP_WAKE_CMD), --cooldown (default 300s),
      --max-per-hour (default 6), --timeout (default 900s), --model.
    - Config via existing ~/.config/harpp/config.json + env overrides: HARPP_WAKE_ENABLED,
      HARPP_WAKE_COOLDOWN, HARPP_WAKE_MAX_PER_HOUR, HARPP_WAKE_MODEL, HARPP_WAKE_CMD.
    - Invoke Pi headless through the configured command; the task contract passed to the
      agent points at .ai/harpp-inbox.jsonl and instructs: read new items since the processed
      ledger, reply via `harpp msg send`, `harpp decision ack` then `harpp decision apply`
      for decided decisions, `harpp status`, then EXIT (single pass, no loop, no self-wake).
    - Full timestamped logging to ~/.config/harpp/wake.log (spawn, cooldown-skip, fallback,
      agent exit code, bridge results).
    - Unit tests for the wake scheduler with a mocked wake command (no network/model).
  prohibited:
    - No reliance on VS Code / Copilot Chat autostart, reopen, or programmatic prompt (B).
    - No systemd unit or root; no MCP-polling-only wake (C, D).
    - No changes to modules/harpp/, other modules, kernel, routes, or any tenant DB table;
      the bridge/CLI already exists and is used as-is (no new capabilities or routes).
    - No infinite agent loops, self-triggering, or agent re-entry into the watch daemon.
    - No bypass of architect→implement→review→release-gate: the agent is a governed worker
      that processes input and reports; it must not self-approve architecture or release.
    - No secrets in code; bridge_key via env/config only. No cross-module DB access.

constraints:
  - Ubuntu dev machine, user-space only (no root), Python3 stdlib only (matches harpp_client.py).
  - MySQL 5.7 compatibility and tenant separation are untouched — this is purely local
    client-side; no DB, no PHP, no cross-tenant reads.
  - Single-flight: at most one agent at a time via an atomic lock (O_CREAT|O_EXCL with stale
    TTL = 2× timeout so a crashed run cannot wedge wake forever).
  - Bounded repair: agent runs one pass and exits; enforced by cooldown, max-per-hour, and a
    hard timeout kill. No retry loops inside the agent.
  - Cursor integrity: watch-state.json keeps advancing (staging) independently; the wake
    agent dedupes by message/decision id via the processed ledger so ack/apply/reply are
    idempotent across cooldown-skipped or overlapped runs.
  - Graceful degradation: if `pi`/wake command is missing, model unavailable, lock held, or
    cooldown active → keep staging + notify-send and continue the watch loop; never crash,
    never drop input.
  - Capabilities are already designed and exposed (harpp.bridge@1, harpp.notify@1,
    harpp.decision.review@1) — capabilities before routes is satisfied; no new routes.

acceptance:
  - Owner message or decided decision arrives → within one watch interval + cooldown exactly
    one Pi agent spawns, reads new inbox items, replies via bridge, acks then applies decided
    decisions, posts status, and exits.
  - A second input during cooldown spawns no second agent (single-flight + rate limit) and is
    still staged + notify-sent.
  - `pi` missing, model error, or lock held → graceful fallback (staging + notify-send), watch
    loop keeps running, event logged.
  - No infinite loop; every agent run bounded by --timeout. Processed ids never re-trigger
    duplicate replies/acks within tolerance of idempotency.
  - `harpp self-test` and `harpp check` still pass; no HARPP module/DB/route changes; tenant
    boundaries intact.

e2e_acceptance:
  - Live bridge (harpp.ikabudkernel.com): owner sends "wake up and confirm" from phone →
    exactly one agent replies "confirmed" via bridge; no manual VS Code interaction.
  - Owner decides a PENDING decision → agent acknowledges then applies → decision reaches
    CLOSED and ADR is recorded, observable in HARPP.
  - Temporarily rename `pi` → owner sends a message → no crash, item staged + notify-send,
    watch loop continues; restore `pi`, next input wakes normally.
  - Two rapid owner messages → only one agent run within the cooldown window; both messages
    staged and processed in that single run.

verification:
  - New unit tests for harpp_wake.py with a mocked wake command (dry-run, no network/model):
    cooldown skip, max-per-hour skip, lock contention, stale-lock recovery, fallback path.
  - `harpp watch --once --wake --wake-command 'echo dry'` demonstrates a spawn decision
    without a real model; wake.log shows the decision and fallback behavior.
  - Manual live e2e against the bridge (above). Review against this contract and
    modules/harpp/module.json capabilities; confirm no cross-module DB access and no route
    changes.

risk:
  - Exact Pi headless invocation flag surface is UNVERIFIED — confirm the real `pi` CLI flags
    (e.g. -m/--model/--prompt) and encode them via HARPP_WAKE_CMD before implementation.
  - Over-aggressive cooldown/rate-limit delays replies; tune defaults from logs.
  - Stale lock after crash could block wake — mitigated by TTL stale detection.
  - Autonomous agent scope creep — mitigated by the bounded contract, timeout kill, and the
    no-self-release rule.
  - Model cost/rate limits (deepseek) — mitigated by max-per-hour and fallback to staging.

status: READY_FOR_IMPLEMENTATION
    → draft len=6794

==============================================================
