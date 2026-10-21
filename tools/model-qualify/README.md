# Model qualification

Decide **which model may do which task class** — deterministically, and for free.

```
python3 tools/model-qualify.py --list
python3 tools/model-qualify.py --selftest                                   # prove the scorers work
python3 tools/model-qualify.py --dry-run                                    # compose prompts, spend nothing
python3 tools/model-qualify.py --model ollama:qwen2.5-coder:1.5b            # local
python3 tools/model-qualify.py --model deepseek-v4-flash --class mechanical-edit
python3 tools/model-qualify.py --task triage-bakeshop-shell --score-file saved.txt
```

Results land in `.ai/runs/model-qualify-<timestamp>/` as `result.json`, `report.md` and one
`<task>.answer.txt` per task. Exit 0 only when every **gating** task passes.

## Why this exists

`tools/model-chain.txt` answers *"what runs when the first choice is unavailable"*. It does not
answer *"is the next choice any good at this work"*. Conflating the two turns a provider outage into
low-quality autonomous code changes, which is why a local model must never be appended to that chain
without per-class evidence.

This tool produces that evidence. It is the sibling of `tools/lane-harness-bench.sh`, which
benchmarks the **harness**; this benchmarks a **model**.

## The design rule that matters

**Executable checks gate a class. Text rubrics only screen.**

A task with a `check` runs real commands against the model's answer — `php -l`, a behavior probe —
so the verdict is decided by behavior. That is the only kind of evidence strong enough to certify.

A task with a `rubric` matches the answer against fixed regexes. Measured on the first real run,
that is **not** strong enough to certify:

- a **correct** cloud answer was failed because it did not contain the literal word `tabs` (a false
  red: the rubric was testing phrasing, not the diagnosis);
- after the rubric was fixed, an **incorrect** local answer passed it — it named "render cache" while
  inventing a sidebar mechanism and never noticing that an assertion had received a whole
  `<!DOCTYPE html>` document where a fragment was expected (a false green).

One rubric, two failure directions, both observed. So rubric tasks carry `"gating": false`, appear
in the report as `SCREENED`, and never decide a class verdict. Their value is as a cheap first pass,
not as a certificate.

## Classes

| class | shape | gating |
|---|---|---|
| `mechanical-edit` | one function, one defect, executable check | yes |
| `repair-loop` | a file with several defects **plus the failing acceptance report**, several functions, one shot | yes |
| `advisory-triage` | read a failure bundle, name root cause / layer / next check | no — see above |

`repair-loop` is the class closest to real lane work: the model gets a failing report and must turn
every listed case green, not just satisfy a stated spec. It is still single-shot — the *loop* is the
lane's job — so it measures "is this model's first attempt usually good enough to be worth looping
on", not iteration ability.

A `repair-loop` task must include cases the report does **not** mention, so that over-fitting to the
shown failures fails the check. `repair-sheet-helpers` lists 8 failures but the probe runs 18 cases.

## Corpus format

`tools/model-qualify/tasks.json`:

```jsonc
{
  "id": "edit-php-identifier-guard",
  "class": "mechanical-edit",
  "prompt": "tasks/<id>/prompt.md",        // {{INPUT}} is replaced by the input file
  "input":  "tasks/<id>/input.txt",
  "budget": { "max_wall_seconds": 420, "max_output_tokens": 700 },
  "check":  { "extract": "fenced:php", "commands": [ { "run": "php -l {file}", "expect_exit": 0 } ] }
}
```

A task may declare either a `check` (executable, gating) or a `rubric`
(`must_include_all_of` — a list of groups, every group needs one matching alternative;
`must_exclude` — forbidden regexes; `max_words`) — never both. Add `"gating": false` for judgement
work.

### Adding a task — the two required controls

Every task needs `fixtures/<id>.good.txt` and `fixtures/<id>.bad.txt`. `--selftest` scores both and
fails if the good one does not pass or the bad one does not fail. A scorer that has never rejected
anything is decoration, not a guard.

Write the good fixture from a **real** answer where possible. The one fixture written by hand —
knowing the answer — encoded the author's phrasing and became the false red above.

## A single sample is not a verdict

Tasks are run `--runs N` times (default **3**) and judged on the **pass rate**, which must reach
`--min-pass-rate` (default **1.0** for gating classes). This is not ceremony — it changed a real
verdict:

At temperature 0.2 the 1.5 B model returned `/^[A-Za-z_][A-Za-z0-9_]{0,63}$/` (correct) on one run
and `/^[A-Za-z0-9_]{1,64}$/` (still accepts a leading digit) on another. The one-shot verdict flipped
between QUALIFIED and NOT QUALIFIED on identical input.

## Measured results (2026-10-20)

Machine: Intel i3-2100 (2 cores, no AVX2), no GPU, Ollama on CPU.

| model | mechanical-edit | repair-loop | advisory-triage |
|---|---|---|---|
| `ollama:qwen2.5-coder:1.5b` | 11/12 samples — NOT QUALIFIED (1 accepted a leading digit) | **0/3 — 8 of 18 cases failed** | screened, 350 s |
| `ollama:qwen2.5-coder:3b` | **QUALIFIED** 3/3, 79 s (3.4 pre / 1.8 gen tok/s) | **0/1 — 16 of 18 cases** (2 wrong) | not run |
| `ollama:qwen2.5-coder:7b` | **PASS** 1/1, exact fix — 184 s (1.5 pre / 0.9 gen tok/s) | **PASS** 1/1 at `--scale-budget 2.5` — **727 s** | not run |
| `ollama:deepseek-coder:1.3b` | 0/1 — 4 of 13 cases wrong (5.1 pre / 2.7 gen tok/s) | **0/1 — timed out at 600 s** | not run |
| `deepseek-v4-flash` | **QUALIFIED** 3/3, 6.5 s | **QUALIFIED** 3/3, 6.7 s | screened 3/3, 12 s |

### What the ladder shows

Capability rises monotonically with size, and the failure gets *narrower* rather than disappearing:
8 wrong cases at 1.5 B, 2 at 3 B. The 3 B's remaining 2 failures are one real bug, not slowness —
it wrote `sprintf('%0.2f', number_format($amount, 2))`, formatting an already-formatted string,
which coerces back to `1` at the comma and renders `PHP 1.00`. A classic type-interaction error that
no amount of extra time fixes.

**Capability exists locally at 7 B; the constraint is speed.** The 3 B is out on correctness (2 cases,
one real type bug). The 7 B passes `repair-loop` — but only at `--scale-budget 2.5`, at **727 s per
attempt**, roughly **108x** slower than the cloud model's 6.7 s. A bounded three-attempt repair loop
would therefore cost ~36 minutes of CPU for a task the cloud does in ~20 seconds.

That is a hardware ceiling, not a verdict on small models: a GPU moves the 7 B from ~1 tok/s to tens
of tok/s and makes the same run take a minute or two. `--scale-budget` exists precisely so a slow
model is measured on capability instead of being mislabelled wrong.

The local row is the interesting one, and it shows a **capability cliff**, not flakiness. A 1.5 B model
on a two-core CPU with no GPU **does** produce a verified-correct single-function fix (11 of 12
samples). Given three functions and a failing report, it fails all three runs and misses 8 of 18
cases: `[^a-z0-9]` without `+` so runs are not collapsed, `ceil()` left as `float 11.0`, still a
division by zero at `$perPage = 0`, and `number_format($a, 2, ',', '.')` with European separators.

So the honest per-class answer is: **narrow, not useless** — one function with an executable oracle is
inside its range; a multi-function repair is not. A lane needs the second shape.

## A timeout is not a wrong answer

A slow-but-correct model fails a fixed budget exactly like a broken one. When the two need to be
told apart, scale the budget deliberately:

```bash
python3 tools/model-qualify.py --model ollama:qwen2.5-coder:7b \
  --task repair-sheet-helpers --scale-budget 2.5
```

`repair-sheet-helpers` needed more than 600 s on a 7B at **1.5 prefill / 0.9 generation tok/s** —
at those rates its prompt read (~800 tok) plus answer (~400 tok) is roughly 980 s, about 1.6x the
default budget. Use `--scale-budget` to find out whether such a model is *capable* before recording
it as unqualified; a scaling run is a separate experiment and does not rewrite the default budget.

## Prompt shape is part of the verdict

A verdict is a property of **(model, class, prompt size)**, not of the model alone. A model that
passes a 594-token repair has not been shown to pass a 12,000-token lane prompt — and on a CPU,
where prefill dominates, cost scales with prompt length.

Measured on this box with `qwen2.5-coder:3b`, unique prompts so the cache cannot flatter it:

| prompt tok | wall s | s per 1k tok |
|---|---|---|
| 248 | 87.7 | 354 |
| 632 | 289.9 | 459 |
| 1,208 | 606.5 | 502 |

Cost per token **rises** with prompt length (attention is super-linear), so the marginal rate between
the first and last point is ~540 s per 1k tokens and a 12k-token lane prompt extrapolates to roughly
**~110 minutes** of prefill per turn. A 500-token focused task is ~4 minutes. Shortening the prompt is
the single highest-leverage software lever, and it is close to linear in the range that matters.

So each task declares the envelope it was qualified at:

```jsonc
"max_prompt_tokens": 750      // repair-sheet-helpers was measured at ~594 tokens
```

`qualify.py` refuses to produce a verdict for a prompt outside that envelope — it fails closed with
`prompt is ~N tokens but this class was qualified at <= M`, spending nothing. `--max-prompt-tokens`
tightens it further; **the tighter ceiling always wins**, so a CLI limit can never be loosened by a
task's own value (a bug found by testing: the task value initially overrode the CLI and the guard
silently did not fire).

`report.md` also carries a **cost model** table — wall seconds per 1,000 prompt tokens per task —
because that number, not the corpus task's own runtime, is what predicts the cost of real lane work.

### Managing it in dispatch

The intended production use is: qualify a model for a class *at a known prompt size*, then route by
prompt size at dispatch. Anything that does not fit the envelope goes to a model qualified for the
larger shape (in practice, the cloud model), rather than assuming the local verdict transfers.

## Offline mode - working when the link is down

The reason this corpus exists is not cost. Cloud models are cheaper *and* faster. The reason is
**availability**: when the internet drops, cloud lanes stop, and a developer loses the whole session.

```bash
python3 tools/model-qualify.py --offline-check          # run this WHILE still online
python3 tools/model-qualify.py --offline --task edit-php-identifier-guard
```

`--offline-check` proves the local stack works right now: server reachable, model installed, a real
generation round-trip, and the measured tok/s. Discovering a missing model during an outage is the
failure mode it removes. A clean cold round-trip on this box measures **~14 s** (3.95 s of that is
model load); a warm one **~4 s**.

**Trap worth knowing:** killing a client request does **not** always cancel the run server-side, so the
next request queues behind the zombie and looks like a timeout — 600 s for an 8-token prompt was
observed, followed by a healthy 14 s run after `ollama stop`. The check now detects this and prints
the fix (`ollama stop <model>`) instead of a bare timeout, because a confusing error during an outage
costs exactly the productive time this mode exists to protect.

`--offline` sets a degraded contract:

- local model only (default `ollama:qwen2.5-coder:3b`, override with `MODEL_QUALIFY_OFFLINE_MODEL`);
- prompts over `MODEL_QUALIFY_OFFLINE_MAX_PROMPT` (default 750) are **refused**, not guessed at;
- only gating classes run - rubric-only classes are not runnable evidence offline.

What still works offline is the important part: **tests, acceptance gates, `php -l` and git are all
local.** Generation slows down; verification does not. So the offline workflow is: write the failing
gate first, ask the local model for one small thing, verify locally, repeat. You become the tool loop.

## Prompt-cache caveat when reading timings

`report.md` records the prefill rate reported by Ollama. Re-running the **identical** prompt hits
Ollama's prompt cache and can report four figures (676 and 1375 tok/s were observed) — that is a
cache hit, not the model's throughput. Trust the first, cold run, or the standalone benchmark in
`.ai/consult/own-lite-llm-2026-10-20-benchmarks.md`.

The same applies to pass rates: repeats reuse an identical prompt, so 11/12 should be read as an
**optimistic** estimate. A real lane sends a different prompt every turn, where the failure rate is
likely higher.

## Boundary

This tool writes nothing to `tools/model-chain.txt`. Qualification is evidence for a decision a
human records in a commit message, not an automatic promotion. Fallback exhaustion must fail closed.

## Models in a second Ollama store

`ollama list` only shows what the **running server** can see. On this machine there are two stores:

| store | owner | visible to |
|---|---|---|
| `/usr/share/ollama/.ollama` | the `ollama.service` systemd unit | `ollama list`, port 11434 |
| `~/.ollama/models` | the user | nothing, unless a second server is started |

A model pulled into the user store is invisible — no error, it simply does not appear. To test one,
start a second server on another port pointed at that store:

```bash
setsid nohup env OLLAMA_MODELS="$HOME/.ollama/models" OLLAMA_HOST=127.0.0.1:11435 \
  ollama serve > /tmp/ollama-11435.log 2>&1 < /dev/null &

OLLAMA_HOST=http://127.0.0.1:11435 python3 tools/model-qualify.py --model ollama:<name>
```

`setsid nohup ... &` matters: a server started in the foreground of a tool-invoked shell is killed
when that shell exits (observed as exit 137), and the harness reports
`ollama unavailable: Remote end closed connection without response`, which reads like a model fault
and is not one.

`OLLAMA_HOST` accepts `host:port` or a full URL.

## Known limits

- A `check` certifies one function in isolation, not integration into a module.
- Rubric scoring cannot separate a correct diagnosis from a plausible wrong one (proven above).
- Timing includes model load; a cold 7B load dominates the first task.
