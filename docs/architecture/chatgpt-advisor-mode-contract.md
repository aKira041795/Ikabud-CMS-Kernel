# Architecture/Task Contract — HARPP ChatGPT Advisor Mode (Ideation)

- **Role:** `/architect`
- **Status:** `READY_FOR_IMPLEMENTATION`
- **Owner decisions recorded:**
  - ChatGPT ideation runs on a **separate quota** from Codex/API usage; ideation is second-opinion
    only, read-only, isolated from HARPP dev work.
  - **Backend = Option B (dedicated OpenAI API key for ideation)** — new `openai-ideation`
    provider with its own auth entry and billing, never `openai-codex`.
  - Playwright + ChatGPT-page automation **deferred** as an optional later "mirror" add-on, not
    the primary path.

---

## task

Add a **ChatGPT Advisor mode** to the existing HARPP harness: a separate, read-only ideation
lane where the owner can submit a plan/proposal and receive a structured second opinion from
their **ChatGPT Plus/Pro** account — without consuming Codex/API usage limits and without
touching HARPP dev state.

## objective

- Turn the existing durable harness loop (`harpp watch` daemon → wake engine → bridge) into the
  entry point for ideation, so **no per-session setup in Copilot chat is ever required**.
- Keep ChatGPT discussions **fully separated** from HARPP code edits / dev runs.

## scope

### allowed

- New durable provider config for the ideation backend (ChatGPT Plus/Pro), stored once under
  `~/.config/harpp` (survives reboot; no per-session setup).
- A **separate conversation channel** for ChatGPT Advisor (distinct from HARPP dev threads).
- A **new read-only advisor task contract** (e.g. `tools/harpp-bridge/wake/task-contract-advisor.md`)
  used for ideation messages.
- Model routing: messages in the Advisor channel are bound **only** to the ChatGPT ideation
  provider. `openai-codex/*` must never be invoked from the Advisor channel.
- A **separate ideation quota ledger** (distinct from the existing Codex model-route ledger in
  `watch-state.json`) proving ideation and coding never share a pool.
- The "submit plan for second opinion" flow: owner drops a plan into the Advisor channel →
  advisor reads it (+ optional read-only workspace context) → returns a structured critique /
  restructure → replies over the bridge.
- Reuse existing harness machinery (daemon, wake engine, bridge, messenger, idempotency keys).
- Durable config only, no new daemon; autostart remains the existing `harpp-watch.service`.

### prohibited

- **No Codex/API usage for ideation.** The Advisor lane must never draw on Codex usage limits.
- **No HARPP dev mutation from the advisor:** no code edits, no workflows/debates, no decisions,
  no dispatcher actions, no release-gate changes. Advisor is read-only.
- No cross-lane model fallback from Advisor → `openai-codex/*` (fallback must stay within the
  ideation pool or degrade to stage+notify; nothing is dropped).
- No changes to existing dev/Codex routing, runner work-queue semantics, or decision lifecycle.
- No weakening of bridge auth / idempotency / audit.
- No printing or logging of bridge keys or provider credentials.
- The **Assistant CMS** idea (content editing) is **out of scope** for this contract — separate
  future contract (P2).

## constraints

- **Quota isolation (hard):** ideation uses ChatGPT Plus/Pro subscription quota only. Verified
  not to route to `openai-codex/*`.
- **Separation (hard):** Advisor channel + contract + route + ledger are isolated from HARPP dev
  state. HARPP code edits remain entirely on the dev lane.
- **Read-only (hard):** advisor produces opinions; it cannot mutate the repo, runs, or decisions.
- **Durability:** config persists across reboots; the flow works from the HARPP messenger/PWA
  without Copilot chat.
- **Platform:** Linux for now (see `harpp-watch-autostart-linux-only` note).
- **Security:** credentials encrypted at rest; never surfaced in logs or replies.

## delegation

- **Scaffolding** (new files, task-contract, provider config, ledger): **GPT 5.4**
  (`openai-codex/gpt-5.4`).
- **`/review`:** **GPT 5.4** (`openai-codex/gpt-5.4`).
- **Implementation execution** (mechanical edits, tests, repair loops): **DeepSeek**.

## acceptance

1. A message in the ChatGPT Advisor channel spawns a Pi advisor agent using the ChatGPT Plus/Pro
   provider and returns a structured second opinion over the bridge.
2. No `openai-codex/*` invocation occurs for any Advisor-channel message; ideation usage is
   recorded in the **separate** ideation ledger.
3. No HARPP dev state is mutated by the advisor (no runs, decisions, code edits, workflows).
4. Config is durable: survives reboot; no per-session setup; works from messenger/PWA.
5. Failure degrades to stage + notify (nothing dropped) and is logged.

## e2e_acceptance

- Owner opens **ChatGPT Advisor** in the messenger, pastes a plan, receives a structured
  critique/restructure from ChatGPT Plus/Pro.
- Codex usage ledger unchanged after the exchange; ideation ledger increments.
- HARPP dev conversations/runs are untouched during the exchange.
- Reboot → daemon auto-starts (`harpp-watch.service` enabled + linger) → same flow works again.

## verification

- Unit/integration tests for: channel→contract→provider routing isolation, quota-ledger
  separation, read-only boundary enforcement (advisor cannot mutate), idempotency.
- A test asserting `openai-codex/*` is never selected for Advisor-channel input.
- Log checks: `storage/logs/app.log`, `storage/logs/error.log`, `.ai/harpp-wake.log`, ideation
  ledger. `php -l` / `node --check` on touched files.

## backend decision (UPDATED 2026-09-01 — Option A selected)

The owner has a **ChatGPT Pro/Plus subscription** and chose to use its included ChatGPT quota
for ideation (no extra API spend). The primary backend is therefore:

- **Backend `page` (Option A):** a Playwright adapter driving the real **ChatGPT web** in a
  persistent logged-in profile. Uses the subscription's **ChatGPT chat quota** (separate from
  Codex, separate from API billing). Built at `tools/harpp-bridge/chatgpt_page.js`.
- **Backend `api` (Option B, retained as fallback):** `openai-ideation` dedicated OpenAI API key
  (still wired in `~/.pi/agent`). Selectable via `harpp advisor set backend api|page`.
- **Never** routed to `openai-codex/*` under either backend.
- **Interface:** ideation lives in the HARPP messenger (durable, autonomous, no manual
  copy/paste). Read-only advisor contract + separate ideation ledger apply to both backends.
- **Page-backend caveats (accepted):** ChatGPT web UI is not a stable API surface — selectors can
  break on product changes; a one-time interactive login is required (`harpp advisor login`);
  runs fail closed to stage + notify (never dropped, never Codex).

## risk

- **Backend durability (depends on choice):** (A) is less stable than a plain REST API and may
  need occasional maintenance; (B) is stable but is OpenAI API rather than the ChatGPT product.
  Both use a separate provider/auth entry so ideation never draws on Codex limits. Mitigation in
  both cases: adapter abstraction + degrade-to-stage/notify; nothing dropped.
- **Unofficial integration surface (A only):** flagged for owner awareness (interface changes,
  possibly ToS). Do not proceed silently; confirm before wiring production traffic.
- **Scope creep:** keep this contract to the Advisor/ideation lane; CMS assistant stays a separate
  future contract.

## recommended_next_state

`/implement` — start P1: durable provider config → Advisor channel → read-only advisor contract →
second-opinion flow → separate quota ledger. Delegate scaffolding + `/review` to GPT 5.4.
