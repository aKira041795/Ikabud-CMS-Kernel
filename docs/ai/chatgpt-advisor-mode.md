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

> **Currently unusable — measured 2026-10-05:** that API account has **no credits**. A direct probe
> returns `HTTP 429 {"type":"insufficient_quota","code":"credit_balance_exhausted"}`, and the
> failure is silent in practice — the advisor/debate produces an empty reply rather than an error
> you would notice. `pi auth check --provider openai-ideation` still reports `ready`, because the
> credential is present; readiness is not availability. Add credits before selecting this backend,
> or stay on `page` (the owner's ChatGPT subscription), which is what the Messenger toggle and
> `start debate with chatgpt:` both use.

`harpp advisor setup` prints the same steps.

## Usage

1. In the Messenger, flip **Ask ChatGPT** next to the **Archive** button at the top of the
   thread. That switches you to the
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

### Handing an opinion to the chair

The advisor lane is read-only by design: its messages are never queued as development work, and
neither the plan router nor the debate router will claim them. To implement an opinion, use the
one command allowed inside that lane:

```
/to-chair <instruction>
```

It claims the message, posts a brief containing your instruction and the **latest ChatGPT reply in
that thread**, and queues that as a normal run under the risk gate — the chair then implements it
and reports in the same conversation. Notes:

- A bare advisor message still queues nothing; the handoff is always explicit.
- With no ChatGPT reply in the thread there is nothing to hand over, so the handoff is **refused**
  rather than queued blind.
- Outside the advisor thread `/to-chair` is inert: that message already goes to the chair.
- The bridge cannot create conversations (only the owner can), so the handoff lands in the thread
  where you sent it rather than a new work thread.

### What the advisor can see (retrieval, on by default)

The advisor is no longer blind to the repository. Every prompt now carries a
`# RETRIEVED REPOSITORY FACTS` block assembled by `tools/harpp-bridge/context_pack.py`:
ranked `docs/**/*.md`, the relevant `modules/*/module.json` capabilities/routes, `git grep`
hits and recent commit subjects for the matched paths — each rendered as `path:line` so an
opinion can be checked instead of trusted.

- Deterministic (same query + same tree ⇒ byte-identical), stdlib-only, read-only, and it
  never raises: a broken retrieval degrades to no facts rather than a failed run.
- It never emits secrets (git-tracked files only), and it dedupes against the decisions,
  chair ledger and conversation context that are already in the prompt.
- Budget is `advisor.context_pack_chars` (default 6000); oversized packs are truncated with
  an explicit marker rather than cut silently.
- The prompt now instructs the model to use the retrieved facts, to name what is missing
  instead of inventing repository facts, and to cite paths it relies on.

For a chair-initiated discussion (no Messenger round trip):

```
python3 tools/harpp-bridge/chair_consult.py --query "<the idea>" [--instruction "…"] [--dry-run] [--json]
```

It composes the same grounded prompt, drives the existing page lane (`chatgpt_page.js`) and
saves the transcript under `.ai/consult/`. `--dry-run` composes without opening a browser.

A discussion is meant to be steered, not scripted. Add `--session <name>` to keep one going: the
transcript is read back in (previous turns only — old retrieved-facts blocks are stripped, so the
next prompt does not pay again for facts already reasoned over) and the reply is appended, so each
call continues the same conversation instead of restarting it:

```
python3 tools/harpp-bridge/chair_consult.py --session stop-button --query "what breaks if a run is cancelled mid-write?"
python3 tools/harpp-bridge/chair_consult.py --session stop-button --query "and the 409 on cancel?"
```

The debate tool (`start debate with chatgpt:`) remains available for an adversarial two-model
check, but it is a pipeline; for thinking an idea through, the steerable session is the intended
path. Debate rounds and the plan step are grounded the same way.

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

Optionally set the safety ceiling (`max rounds 5`). After every critique, the chair model emits
an explicit `CHAIR_DECISION` with `converge`, `another_round`, or `abort` and a reason. The
critic's verdict is evidence, not approval authority. A missing/malformed chair block is recorded
as `critic_fallback` and can only continue the debate; it never silently approves. The decision
and provenance are in `.ai/debate/chair-decision.json`. `Approve debate` remains available as an
explicit human override.

For a ChatGPT-participant debate, chair approval starts a second page-backend call that turns the
approved brief into `.ai/debate/plan-<timestamp>.md`. Delegation is refused unless that plan has a
real acceptance command and says what passing looks like. A valid plan is offered to Sol first,
then explicit model-chain fallbacks; no available model is reported rather than silently skipped.
Use `--no-plan` to stop after the approved brief or `--no-delegate` to retain the plan without
launching implementation. Ordinary dev-pool debates still stop at the approved draft. No stage
pushes or merges.

Under the hood this is `tools/pi-arch-debate.py` with `DEBATE_MODEL_B=chatgpt/page`, which
resolves to `tools/harpp-bridge/chatgpt_page.js`. The `openai-ideation` API model is **not**
used for this: its credits can be exhausted (measured 2026-10-05: HTTP 429
`credit_balance_exhausted` produced a debate draft with no text). Naming ChatGPT only selects the
partner; a passing mention ("I asked chatgpt about X") launches nothing.

## Claude as a second discussant

`tools/harpp-bridge/consult.py` is a provider-neutral form of the same grounded loop. ChatGPT
remains the default chair; Claude is an **optional second discussant / red team**, not a second
gate. Use it when a second model's disagreement is worth the tokens, not on every patch.

```bash
# Same role and transcript conventions as the chair, but Claude answers.
python3 tools/harpp-bridge/consult.py \
  --provider claude --session arch-2026-10-20 \
  --query "should the host-portability boundary move into the gateway?"

# Attack a recommendation instead of producing a parallel one.
python3 tools/harpp-bridge/consult.py \
  --provider claude --mode red-team \
  --target-file .ai/consult/arch-2026-10-20.md \
  --query "red-team the proposed boundary"

# Run both models on the same prompt and save both replies side by side.
python3 tools/harpp-bridge/consult.py --compare \
  --session arch-2026-10-20 \
  --query "which option has the lower rollback cost?"
```

Modes:

| Mode | What the model is asked to do |
|---|---|
| `chair` (default) | discuss the intent and recommend |
| `red-team` | falsify the recommendation: assumptions, failure modes, alternatives, falsifiers |
| `--compare` | run ChatGPT and Claude on the same grounded prompt; write both replies and a descriptive signals block |

Transports for Claude:

- **Anthropic API** — set `ANTHROPIC_API_KEY` and `CONSULT_CLAUDE_MODEL`. The tool uses the
  standard Messages API; `--max-tokens` caps the reply.
- **Claude CLI** — set `CONSULT_CLAUDE_CMD` (default `claude -p`). The prompt is written to stdin
  and the reply is read from stdout. Verify the CLI's headless quota and terms before automating it.
- **claude.ai page** — `--claude-backend page` drives the web UI with a persistent browser
  profile, using the account's chat quota rather than API credits. Log in once:

  ```bash
  node tools/harpp-bridge/claude_page.js login --profile ~/.config/harpp/claude-profile
  ```

  The browser opens on `https://claude.ai/new`; sign in, wait for the confirmation, and the
  session is saved. The email is never stored by the tool — only the profile's cookies.
  `--headless` is available but the web UI may challenge headless traffic, so headed is the
  default.
- **Do not automate claude.ai web chat through any other path.** The page backend is a
  best-effort adapter for the owner's own account, not a stable API surface. Selectors may need
  updating when the UI changes.

**Free accounts have a message cap.** The adapter reports a usage-limit state explicitly
(`limit: true`) instead of retrying, and the consult tool prefixes the error with
"Claude usage limit reached (free account)". Prefer one focused red-team or chair call over
`--compare`; a comparison spends two messages per run.

Selection: `--claude-backend auto` (default) uses the API when a key **and** model are present,
otherwise the CLI; it fails with a clear message when neither is available.

Transcripts and prompts live under `.ai/consult/` (gitignored). `--dry-run` writes the prompt
without calling a provider, so a discussion can be composed before spending tokens. `--compare`
writes `compare-<session>.md` plus per-provider transcripts; its "deterministic signals" are only
word/citation counts — agreement and disagreement must be read from the replies.

A Claude opinion is **advice, not evidence**, exactly like the chair. The useful output is a
disagreement that survives verification, not a second agreement.

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
