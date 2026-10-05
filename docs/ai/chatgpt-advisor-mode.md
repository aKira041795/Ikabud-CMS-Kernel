# ChatGPT Advisor Mode (Ideation) — HARPP

A **separate, read-only** ideation lane in the HARPP harness. You submit a plan/proposal in a
dedicated conversation and get a **second opinion** (structured critique + restructuring) from
your ideation model before committing to `/architect`.

It is fully isolated from HARPP dev work and **never** consumes Codex usage limits.

- **Architecture contract:** `docs/architecture/chatgpt-advisor-mode-contract.md`
- **Advisor task contract:** `tools/harpp-bridge/wake/task-contract-advisor.md`
- **Backends:** `page` (primary — ChatGPT web via your Pro/Plus subscription) and
  `api` (fallback — `openai-ideation/gpt-5.4` dedicated API key)

## Why a separate lane

The normal wake lane routes coding/review work to `openai-codex/*`, which draws on **Codex
usage limits**. Ideation is second-opinion only, so it must never burn that quota. The advisor
lane therefore:

1. Runs in a **dedicated conversation channel** (default title `ChatGPT Advisor`).
2. Uses a **dedicated read-only task contract** (no code edits, no workflows, no decisions).
3. Uses a **dedicated backend**: `page` drives your **ChatGPT web** (Pro/Plus subscription chat
   quota, separate from Codex and API billing); `api` uses a dedicated `openai-ideation/*` key.
4. Records usage in a **separate ideation ledger** (`ideation_usage` in `watch-processed.json`).

## Lane isolation (hard guarantees)

| Concern | Guarantee |
|---|---|
| Codex quota | Ideation is **never** routed to `openai-codex/*`, even if the owner body says "use gpt sol". The chain is the configured ideation model only. |
| HARPP dev state | Advisor messages never enter the dev quick/agent tiers; dev runs, decisions, and workflows are untouched. |
| Mutability | Advisor is **read-only**: API agents are launched with Pi's `read,grep,find,ls` tool allowlist (no shell/edit/write), and the page backend has no repository access. Neither can edit code, run workflows, or mutate decisions. |
| Failure | On model/contract failure the items stay staged for bounded retry (stage + notify by the caller) — nothing is dropped, nothing is re-routed to the dev pool. |

A dedicated lane owns its bound conversation: title matching establishes the binding, and the
conversation id preserves it across later renames. Its messages are never queued as dev runs,
even while the lane is disabled. For the `page` backend, the watch daemon environment must expose
both `DISPLAY` and `XAUTHORITY` so Playwright can launch headed Chrome.

## Setup (one time)

The advisor lane is **Linux-only** for now and reuses the always-on `harpp watch` daemon
(auto-started via `harpp-watch.service` + `enable-linger`).

### Backend `page` (primary — uses your ChatGPT Pro/Plus subscription, no API spend)

1. **Log in to ChatGPT once** in the persistent advisor profile (opens a headed browser):
   ```bash
   harpp advisor set backend page
   harpp advisor login
   ```
   The session persists in `~/.config/harpp/chatgpt-profile` for headless runs. This is the
   only interactive step; `harpp watch` handles everything after.

2. **No conversation to create by hand** — the Messenger's **Ask ChatGPT** toggle creates the
   conversation for you, marked with `harness_session_id = chatgpt-advisor`.

3. **Enable + verify:**
   ```bash
   harpp advisor enable
   harpp advisor status
   ```

### Backend `api` (fallback — dedicated OpenAI API key, needs API credits)

```bash
harpp advisor set backend api
harpp advisor set model openai-ideation/gpt-5.4
```
Requires an `openai-ideation` auth entry in `~/.pi/agent/auth.json` + provider in
`~/.pi/agent/models.json` (dedicated API key with its own billing; never the Codex token).

`harpp advisor setup` prints the same steps.

## Usage

1. In the Messenger, flip **Ask ChatGPT** on the composer. That switches you to the
   advisor conversation (creating it once). The thread title gains a `· ChatGPT` marker so it
   is always clear where your next message goes; flipping the toggle off returns you to a work
   thread.
2. Paste your plan/proposal (or reference the workspace path).
3. The daemon spawns the advisor agent on the ideation model; it replies with:
   - **What is strong**
   - **Gaps and risks**
   - **Restructuring suggestion**
   - **Recommendation** (go / go-with-changes / rethink)
4. Reconcile the opinion with `/architect` and continue the normal pipeline.

### How the lane is chosen

The **conversation's `harness_session_id`** is the routing key (`chatgpt-advisor`), not the
conversation title. The marker travels on every polled message, so renaming a thread cannot
change which lane answers it, and a marked thread is never queued as development work. Threads
created before the toggle existed are still recognised by their `ChatGPT Advisor` title.

There is no separate Advisor page: the lane is picked where the message is written.
`/harpp/advisor` redirects to the Messenger so old links keep working.

### Asking the chair to discuss it with ChatGPT

Send either of these from the Messenger and the chair will run a chaired discussion with
**your ChatGPT subscription** (the same backend the toggle uses — no API credit involved):

```
start debate with chatgpt: <your idea>
discuss with chatgpt: <your idea>
```

Optionally pin the rounds (`max rounds 5`). The chair picks who opens, argues the rounds, and
critiques the other side — the discussion ends in `verdict: APPROVED` or `REVISIONS`, and
`Approve debate` in the Messenger accepts the last draft as the chair. Naming ChatGPT only
ever *selects the partner*: a passing mention ("I asked chatgpt about X") launches nothing.

Under the hood this is `tools/pi-arch-debate.py` with `DEBATE_MODEL_B=chatgpt/page`, which
resolves to `tools/harpp-bridge/chatgpt_page.js`. The `openai-ideation` API model is **not**
used for this: its credits can be exhausted (measured 2026-10-05: HTTP 429
`credit_balance_exhausted` produced a debate draft with no text).

## Operations

```bash
harpp advisor status          # config + ideation ledger
harpp advisor disable         # turn the lane off (items stay staged)
harpp advisor enable          # turn it back on
harpp advisor login           # one-time interactive ChatGPT login (backend=page)
harpp advisor set <key> <value>   # conversation_title | model | backend | profile |
                                  # enabled | timeout | cooldown | max_per_hour
```

## Backend `page` — how it works and its limits

- The lane invokes `tools/harpp-bridge/chatgpt_page.js` (Playwright) with the persistent
  logged-in profile: starts a fresh chat, pastes the bounded plan plus conversation context,
  durable decisions, chair ledger, and the read-only advisor persona, waits for the reply to
  settle, and posts the opinion back over the bridge. The prompt states that the page model
  cannot inspect the repository and must identify facts it cannot verify.
- Browser-pasted state is bounded: plan 12,000 characters, decisions 6,000, conversation context
  6,000 (the helper currently caps it at 4,000), and chair ledger 6,000.
- **Uses the subscription's ChatGPT chat quota** — separate from Codex and from API billing.
- **Fragile by design:** ChatGPT web is not a stable API surface; selectors may break when the
  product changes. Every failure fails closed to stage + notify (nothing dropped, never Codex).
- If the web UI breaks, fall back to `backend api` for a stable path.

For the API backend, the agent only emits the four-section opinion. The daemon extracts final
text from Pi's JSON output and performs bridge delivery using `wake-message-<source-id>`; the
agent has no shell merely for delivery.

The ideation ledger (`ideation_usage` in `~/.config/harpp/watch-processed.json`) records
`count`, `last`, `hour`, `messages`, and `models` — independent of the dev `wake_hour` and
`model_routes`, so ideation vs coding usage can be audited separately.
