# ChatGPT Advisor Mode (Ideation) — HARPP

A **separate, read-only** ideation lane in the HARPP harness. You submit a plan/proposal in a
dedicated conversation and get a **second opinion** (structured critique + restructuring) from
your ideation model before committing to `/architect`.

It is fully isolated from HARPP dev work and **never** consumes Codex usage limits.

- **Architecture contract:** `docs/architecture/chatgpt-advisor-mode-contract.md`
- **Advisor task contract:** `tools/harpp-bridge/wake/task-contract-advisor.md`
- **Lane model default:** `openai-ideation/gpt-5.4`

## Why a separate lane

The normal wake lane routes coding/review work to `openai-codex/*`, which draws on **Codex
usage limits**. Ideation is second-opinion only, so it must never burn that quota. The advisor
lane therefore:

1. Runs in a **dedicated conversation channel** (default title `ChatGPT Advisor`).
2. Uses a **dedicated read-only task contract** (no code edits, no workflows, no decisions).
3. Uses a **dedicated provider/model** (`openai-ideation/*`) with its own auth + billing.
4. Records usage in a **separate ideation ledger** (`ideation_usage` in `watch-processed.json`).

## Lane isolation (hard guarantees)

| Concern | Guarantee |
|---|---|
| Codex quota | Ideation is **never** routed to `openai-codex/*`, even if the owner body says "use gpt sol". The chain is the configured ideation model only. |
| HARPP dev state | Advisor messages never enter the dev quick/agent tiers; dev runs, decisions, and workflows are untouched. |
| Mutability | Advisor is **read-only**: it replies with an opinion and may read workspace context, but cannot edit code, run workflows, or mutate decisions. |
| Failure | On model/contract failure the items stay staged for bounded retry (stage + notify by the caller) — nothing is dropped, nothing is re-routed to the dev pool. |

## Setup (one time)

The advisor lane is **Linux-only** for now and reuses the always-on `harpp watch` daemon
(auto-started via `harpp-watch.service` + `enable-linger`).

1. **Create the conversation** — in the HARPP messenger/PWA, create a conversation titled
   `ChatGPT Advisor`.

2. **Add a dedicated OpenAI API key for ideation** to the Pi harness. This is a **separate**
   billing/account from the Codex token — never reuse the `openai-codex` auth.
   - Add an `openai-ideation` provider + models in `~/.pi/agent/models.json`
   - Add an `openai-ideation` auth entry in `~/.pi/agent/auth.json` (your ideation API key)

3. **Configure the lane** (all durable in `~/.config/harpp/config.json`):
   ```bash
   harpp advisor set conversation_title 'ChatGPT Advisor'
   harpp advisor set model openai-ideation/gpt-5.4
   harpp advisor enable
   harpp advisor status
   ```

`harpp advisor setup` prints the same steps.

## Usage

1. Open the **ChatGPT Advisor** conversation in the messenger (or `harpp msg send --title 'ChatGPT Advisor' ...`).
2. Paste your plan/proposal (or reference the workspace path).
3. The daemon spawns the advisor agent on the ideation model; it replies with:
   - **What is strong**
   - **Gaps and risks**
   - **Restructuring suggestion**
   - **Recommendation** (go / go-with-changes / rethink)
4. Reconcile the opinion with `/architect` and continue the normal pipeline.

## Operations

```bash
harpp advisor status          # config + ideation ledger
harpp advisor disable         # turn the lane off (items stay staged)
harpp advisor enable          # turn it back on
harpp advisor set <key> <value>   # conversation_title | model | enabled |
                                  # timeout | cooldown | max_per_hour
```

The ideation ledger (`ideation_usage` in `~/.config/harpp/watch-processed.json`) records
`count`, `last`, `hour`, `messages`, and `models` — independent of the dev `wake_hour` and
`model_routes`, so ideation vs coding usage can be audited separately.
