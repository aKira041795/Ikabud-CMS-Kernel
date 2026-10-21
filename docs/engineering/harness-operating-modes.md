# Harness operating modes — online and offline

Companion to `.github/instructions/ai-development-execution-handoff.instructions.md` (which defines
the lifecycle, roles and gates). That file assumes the network is up. This one defines what changes
when it isn't.

The reason this document exists: **cost is not the reason to run models locally.** Cloud models are
cheaper and faster. The reason is availability — when the link drops, cloud lanes stop and the
session dies. On this repository's current hardware the offline mode is real but narrow, and the
narrowness is a measured property, not an opinion.

---

## 1. What changes between modes

| capability | online | offline |
|---|---|---|
| implementation lane (`pi` + cloud model) | yes | **no** — needs network |
| craft a single function from a small prompt | yes | yes — 3B, ~80–130 s |
| multi-function repair from a failing report | yes | yes — 7B, ~12 min |
| tests, acceptance gates, `php -l`, `git` | yes | **yes — fully** |
| release gate / architecture check | yes | yes — deterministic, local |
| `/chair-consult` decision gate | yes | **compose only, then defer** |
| deploy (FTP/SSH), `composer install`, `npm install` | yes | no |

The load-bearing asymmetry: **generation needs the network; verification does not.** Offline you
lose authoring speed, not the ability to know whether something is correct.

---

## 2. ONLINE — the default flow (unchanged)

```
/architect  →  /implement  →  /review  →  /release-gate
                (DeepSeek)     (Codex/Sol)  (deterministic)
                     ↑              ↓
                repair loop ← CHANGES_REQUIRED
```

Chair (`/chair-consult`) is consulted at the decision gate, not on every patch. Model dispatch runs
through `tools/model-chain.txt` with automatic fallback. Nothing in this section changes.

---

## 3. OFFLINE — the rule that decides what you are allowed to do

> **Offline, defer decisions that create a new contract or carry high consequence. Bounded,
> reversible work under an already-approved contract continues.**

(Revised after chair consult 2026-10-21. The first version — "no decision that would have required
the chair" — was too strict: it blocked ordinary work under contracts that were already settled, and
during a multi-day outage that is a self-inflicted stop.)

1. **verification** — run the gates, the suite, the architecture check;
2. **mechanical work inside a contract that is already settled**;
3. **composing** the decision briefs you will consult on when the link returns.

If a task is blocked on a decision, it is *blocked*, not guessed. Record the brief (§4) and move to
work that does not need it. Doing this well is what keeps an outage from becoming an incident.

---

## 4. What replaces the chair when it is unavailable

There is no offline substitute with the chair's authority. There are four weaker things, and it
matters which one you are using:

**a. Compose now, send later — the primary answer.** The chair's tooling works without a network:

```bash
python3 tools/harpp-bridge/chair_consult.py --dry-run --session <name> \
  --query "<the decision>" --instruction "<what you need it to weigh>"
```

Verified: this writes the fully grounded prompt (context pack included) to
`.ai/consult/<session>.turn.md` with no browser and no network. When the link returns, run the same
command without `--dry-run`. **Nothing is lost by an outage — the decision is simply queued.**

**b. Defer.** A queued decision is not a failure. Keep it in `.ai/consult/` with the session name
and continue with §5.

**c. A local model as devil's advocate — advice only, never authority.** A 3B/7B reading a plan and
listing objections is cheap and occasionally useful. It cannot approve anything, because measured
behaviour shows it invents plausible mechanisms confidently (it blamed CSS for a render-cache bug,
and invented a sidebar cache that did not exist). Treat its output as a prompt for your own
thinking, never as a verdict.

**d. The deterministic gates.** Note what the chair never was: *advice, not evidence*. Verification
has always come from acceptance commands, the release gate and the architecture check — all local.
So the chair's absence removes a discussion, not a proof.

---

## 5. The offline lane workflow — you are the tool loop

A local model has no file-edit or test-run loop, so the loop becomes manual. That is fine when the
loop is short:

1. **Write the failing acceptance command first.** Local, instant, and it defines "done".
2. **Ask the local model for one thing.** One function, short prompt, no context dump.
3. **Verify with the gate you just wrote.**
4. **Repeat.** Commit at each green step so an outage never costs uncommitted work.

```bash
python3 tools/model-qualify.py --offline-check          # is local AI working right now?
python3 tools/model-qualify.py --offline --task edit-php-identifier-guard
```

`--offline` refuses prompts over 750 tokens rather than guessing, and runs gating classes only.

---

## 6. The operating limit is prompt size, measured

Measured on the current box (`qwen2.5-coder:3b`, unique prompts so the cache cannot flatter it):

| prompt tokens | wall seconds | s per 1k tokens |
|---|---|---|
| 248 | 87.7 | 354 |
| 632 | 289.9 | 459 |
| 1,208 | 606.5 | 502 |

Cost per token **rises** with length, so the marginal rate is ~540 s per 1k. That puts a 500-token
focused task at ~4 minutes and a 12,000-token lane prompt at **~110 minutes**. Hence the offline
ceiling of 750 tokens: above it, the wait exceeds the value.

This is why `--offline` refuses rather than runs. A verdict measured at 600 tokens is not evidence
for a 12,000-token prompt, and on a CPU the time penalty makes the attempt pointless anyway.

**Operating principle: slow is not stopped.** A 727-second repair that passes its acceptance gate is
productive work; a 7-second cloud call you cannot make is not. The measurements above bound *how much*
work fits into an outage, not *whether* work happens — and `--dry-run` now prints the predicted wall
time per task (`~594 tokens (~5.0 min on CPU at 502 s/1k)`) so a slow run is planned rather than
discovered.

---

## 7. When upgraded hardware changes the calculus

The current constraint is **prefill throughput**: ~2–3 tok/s. Everything above follows from it.

| prefill rate | 12k-token prompt | verdict on local lanes |
|---|---|---|
| ~2–3 tok/s (now, CPU) | ~60–110 min | unusable |
| ~100 tok/s | ~2 min | borderline |
| ~500 tok/s (entry discrete GPU) | ~25 s | **viable** |
| ~2,000+ tok/s (mid/high GPU) | ~6 s | competitive with cloud |

**Expectations to verify on the new hardware, not assume.** Chair ruling (2026-10-21): a **local GPU is
conditionally approved** — an RTX A2000 12 GB is technically preferable (12 GB, low profile, 70 W); a
Tesla P4 8 GB is a low-cost experiment with real integration caveats (no fan, Pascal, passive cooling
needs a shroud). **A rented cloud GPU fails this requirement outright** — it needs the internet, which
is the exact thing being defended against. Any remote-GPU option is therefore the wrong answer for
availability, however cheap it looks, and remains valid only for batch work or fine-tuning.

Recorded caveat: the chair would not widen offline capability on inference speed alone — *"no amount
of faster inference should promote a model that cannot pass the actual task's executable acceptance."*
Speed decides what is *tolerable*; the executable gate decides what is *allowed*.

**The falsifier that would change the architecture.** Re-run the corpus on the new hardware:

```bash
python3 tools/model-qualify.py --model ollama:qwen2.5-coder:7b --runs 3
```

If `repair-loop` qualifies **within the default budget** (no `--scale-budget`), then local inference
is no longer a degraded mode: it becomes the offline default *and* a legitimate online fallback, and
§3's restriction can be relaxed for classes it holds. Until that measurement exists, the restriction
stands on evidence rather than caution.

Note that cost is the wrong axis for this decision at any hardware level: even an unlimited-speed
local model does not beat a cloud model on quality. What a GPU buys is *availability with acceptable
latency*, which is the actual requirement.

---

## 8. Next step: the offline rehearsal (chair-recommended)

The limiting question is not inference speed, it is **whether useful accepted work actually gets done
offline**. Speed only predicts that; it does not measure it. So the next step is a timed rehearsal,
run with the network physically down, recording outcomes rather than impressions:

| # | task attempted | prompt tok | wall | gate result | accepted? |
|---|---|---|---|---|---|
| 1 | | | | pass/fail | yes/no |
| 2 | | | | | |

Two hours is enough. Success is **accepted work per hour**, not tokens per second. If the limiting
factor turns out to be CPU latency rather than task shape, that is what converts a GPU from a nice-to-
have into an availability investment — and it is an evidence-based conversion, not a preference.

Also worth measuring in that session: **`qwen2.5-coder:1.5b` is now the offline default**, on measurement
rather than preference. On `mechanical-edit`, 1.5b scores 3/3 in **46.3 s** and 3b 3/3 in **79.4 s** —
identical correctness, 1.7× the speed (6.6/3.0 tok/s versus 3.4/1.8). Offline, a model that
occasionally misses can beat a slower one that rarely does, because the acceptance gate catches the
miss and a retry costs seconds. The online calculus is the opposite, where an unverified answer is the
expensive thing.

Caveat carried forward: 1.5b failed 1 of 12 earlier samples (it accepted a leading digit), so it is not
certified at a 1.0 pass rate. It is the offline *speed* choice, used behind a gate; 3b remains the more
reliable option via `MODEL_QUALIFY_OFFLINE_MODEL=ollama:qwen2.5-coder:3b`.

## 9. Pre-outage checklist

- [ ] models pulled: `qwen2.5-coder:3b` (fast), `qwen2.5-coder:7b` (capable)
- [ ] `--offline-check` returns READY
- [ ] Composer/npm dependencies installed (these die with the link)
- [ ] work committed — an outage must not cost uncommitted changes
- [ ] AI provider setting location known (`ollama` is already selectable; see
      `modules/ai/helpers/ollama.php`)
- [ ] any open decision already composed with `chair_consult.py --dry-run`
