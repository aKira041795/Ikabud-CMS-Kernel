# HARPP Experience Repository — Phase 1 design

Status: **approved and started** 2026-11-17
Origin: ChatGPT research-grounded target architecture, critiqued by Claude as second discussant,
then resolved by measurement against this repository.
Inputs (readable in the workspace):

- `.ai/consult/harpp-experience-repo-2026-11-17.chatgpt.md` — processed ChatGPT thread
- `.ai/consult/harpp-experience-repo-2026-11-17.claude.md` — Claude's chair reply
- Probes below were run on 2026-11-17 and their raw output is the basis of every claim marked
  **measured**.

---

## 1. What the research proposed, and what this repository already has

The target architecture (AHE arXiv:2604.25850, Self-Harness arXiv:2606.09498, Meta-Harness
arXiv:2603.28052) asks for three observability layers — component, experience, decision — feeding
two separately governed systems (a Cognitive Runtime that does the work, an Evolution Laboratory
that improves the method). It is explicitly research-grounded, not repository-grounded, and warns
that duplicating existing harness governance would be a mistake.

So the first question was not "what do we build" but **"what already exists here?"** Measured:

| Layer | Already present | Where |
|---|---|---|
| Component (identity) | `head.before` (git HEAD) and `policy.before` (file → **git blob hash** snapshot) per run | `.ai/runs/<lane>.head.before`, `.policy.before` |
| Experience (what happened) | `run_id`, `state`, `reason`, `verdict`, `completion`, `acceptance_cmd`, `acceptance_exit`, `changed_paths`, `dirty_before` — 79 of 263 landing records carry the full set, 90 the basic set, 4 legacy none | `.ai/runs/*.landed.json` |
| Experience (per attempt) | `run_started` (acceptance, pass_looks_like, touches), `attempt_started`/`attempt_finished` (**model**, cap, exit, log, bytes, duration) | `.ai/runs/*.events.jsonl` |
| Decision (per task) | `objective`, `acceptance`, `pass_looks_like`, `acceptance_fails_now`, `probe_exit`, `class`, `touches` — a genuinely falsifiable per-task prediction | `.ai/autonomy/contracts/*.json` |
| Decision (outcome) | `attempts`, `acceptance`, `gate`, `merged`, `rolled_back`, `owner_interventions`, `cost_usd` | `.ai/autonomy/ledger.jsonl` |

**Conclusion: the experience data largely exists. The missing pieces are two small ones, not a
new subsystem.**

## 2. The two real gaps (measured)

1. **No foreign key.** Ledger rows carry no `run_id` and no component versions, so an outcome
   cannot be attributed to a run or to a harness version. Measured: an exact join of ledger `task`
   to run-artifact lane name matched **0 of 6**. The naming convention `<task>-a<attempt>` makes a
   *normalised* join work (strip `-aN` → **6 of 6**), but that is a convention, not a contract, and
   nothing survives a rename.
2. **No change-level hypothesis.** A contract's prediction is per-task. "Component change X will
   improve metric Y" spans many tasks and compares a baseline against a candidate; it has no home.

Also measured: `cost_usd` is `0.0` on **all 12** ledger rows, and `lane-model.sh` emits no token
usage, so any cost-based metric is uncomputable. That is recorded honestly rather than papered over.

Two further measurements made while building the reporter changed the priority order:

- **No model output is retained.** `lane-model.sh` wrote the model's reply only to
  `/tmp/<prefix>-<model>.log`. Measured 2026-11-17: **zero** historical model logs existed, so the
  **40 of 263 landings (15%)** that ended in `timeout`/`no_report` could not be diagnosed at all —
  the evidence needed to tell "the lane failed" from "the harness lost the result" had been
  deleted by the OS. Retention is now capped and archived into `.ai/runs` (see §7, item 1).
- **The failure labels themselves are ~8% wrong.** 7 landing records carry a failure reason
  (`no_report`/`crash`) while their acceptance and completion both passed. Counting those as
  failures inflated the repeat rate; they are now reported as `mislabelled` and excluded.

## 3. Decisions (each with the evidence that would reverse it)

| # | Decision | Why | Falsifier / revisit trigger |
|---|---|---|---|
| D1 | **No database in Phase 1.** Scan the JSONL directly. | 12 ledger rows and 263 landing records scan instantly. A store would be infrastructure without a customer. | A full scan exceeds ~2 s, or the ledger passes ~5000 rows. Then add SQLite as a *derived* index. |
| D2 | **JSONL stays the source of truth**, append-only, fsync + flock (already how `autonomy-ledger.py` writes). | Crash-safe and rebuildable; any future index is disposable. | If a rebuild ever produces different query output, the index is lying and must be dropped. |
| D3 | **One store, constant `project_scope` column.** No shared cross-project layer. | The harness serves one repository today; a split has no second customer. A column is nearly free insurance. | A second project onboards, or a lesson is genuinely reused across repositories. |
| D4 | **Store lives in `.ai/harpp/`** (owner-visible), **untracked**, with `git_head` stamped on every record. | `.gitignore:119` already ignores `.ai/*` wholesale and 7 files are force-tracked; adding a single tracked exception while the rest of `.ai/` is untracked is a confusing split. `git_head` + `created_at` makes ordering provable without tracking. | Portability across machines becomes a requirement. Then track `trials.jsonl` + `ledger.jsonl` explicitly. |
| D5 | **New record type `trial`**, not "decision", and not fields on the contract. | The owner already has `DEC-*` decisions in `~/.config/harpp/decisions.json`; reusing the word collides. Per-task contracts cannot carry a change-level prediction without duplicating it per task. | A contract is shown to legitimately carry a change-level prediction without duplication. |
| D6 | **Stamps are additive fields on the existing ledger row.** | `autonomy-ledger.py record` accepts arbitrary `key=value` pairs (`_parse_value`), so no schema or writer change is needed. | If `record` ever validates a closed field set. |
| D7 | **Not built yet:** promotion pipeline, held-out evaluation set, skill compilation, evolution laboratory, standalone component registry, `promote` command. | Git already provides component identity (blob hashes) and rollback (`git revert`). With this evidence volume a promotion gate would be ceremony. | Enough trials exist that more than one candidate competes for promotion. |

## 4. Phase 1 — the smallest slice that makes a future claim falsifiable

Delivered as:

1. **`tools/harpp-experience.py`** — one read-only CLI plus append-only trial registration:
   - `join` — how many ledger rows resolve to a run artifact, a `run_id`, and a component stamp
     (honest counts; a partial number is a valid answer).
   - `report [--window N]` — acceptance/pass rate, attempts, owner interventions, and either the
     wasted-cost share (when cost is captured) or the honest "cost not captured" plus the
     **repeat-failure rate** fallback computed from landing records.
   - `compare` — pass rate and cost per accepted task per component stamp; **exits non-zero with
     `insufficient evidence`** rather than printing a verdict below `--min-n` or with one stamp.
   - `trial new|list|evaluate` — append-only pre-registration of a change-level hypothesis
     (`hypothesis`, `component`, `metric`, `minimum_delta`, `min_n`, `git_head`) and its later
     evaluation.
   - `stamp` — emits the `key=value` fields the ledger writer should persist (used by
     `lane-autonomy.py`).
2. **`tools/lane-autonomy.py`** stamps every new ledger row with `run_id`, `git_head`,
   `component_model_chain`, `component_context_pack`, `component_harness` (git blob hashes,
   computed with stdlib `hashlib` — identical to `git hash-object`). Fail-open: a missing run id
   never blocks the ledger write.
3. **Tests** (`tools/tests/test_harpp_experience.py`) that fail on the pre-change tree.

Explicitly **not** in Phase 1: the evolution loop, SQLite, FTS, skill compilation, promotion,
shared cross-project knowledge.

## 5. The single number to watch for four weeks

`harpp-experience.py report --window 28d`

- If cost ever becomes capturable: **wasted_cost_share** = cost of rows with
  `acceptance != PASS` or `rolled_back` or `owner_interventions > 0`, over total cost.
- Until then (today's case): **repeat-failure rate** — the share of landing-record failures whose
  `reason` + normalised `status_line` already appeared earlier. Baseline measured 2026-11-17:
  **84.6%** (77 repeats in 91 genuine failures; 14 distinct signatures in total).
- Supporting counter: **model logs retained**, currently **0/263** and rising from the next lane.

Falsifier: if either number is flat or rising after four weeks of use, the investment is not paying
back and the next four weeks should go to the actual bottleneck instead.

## 6. Why skill compilation is the CPU-side payoff (kept, deferred)

Research and ChatGPT's thread both point at "experience → skill": a validated method becomes a rule,
query, script or skill that runs on CPU, and the model is invoked only for the unfamiliar case. The
owner's hardware (i3 / ~20 GB, no GPU) makes this the natural centre of gravity. It is deferred to a
later phase because **zero validated methods exist yet to compile** — the pre-registration mechanism
in Phase 1 is what produces them.

## 7. Phase 2 — what to pursue, in the order the evidence supports

Ranked by measured pain, not by research elegance. Each item names what makes it doable now.

| # | Item | Why now (measured) | Cost | Status |
|---|---|---|---|---|
| 1 | **Retain the model output** — archive each attempt's reply to `.ai/runs/<lane>.model-a<N>-<model>.log` (capped 1 MiB, both ends kept), pointer in `events.jsonl` as `archived` | 0 of 263 landings had diagnostic evidence; 40 non-completions were unexplainable | ~20 lines, fail-open | **delivered 2026-11-17** |
| 2 | **Token and cost capture** — `pi --mode json` exposes `totalTokens` plus a cost block and the SERVED model; `tools/lane-usage.py` splits text back into the log | cost was `0.0` on every row | ~150 lines | **delivered 2026-11-17** |
| 3 | **Signature → hint injection** — `signatures`, `hints`, `hint add`; the block reaches every autonomous lane prompt ahead of the rules | 15 signatures, 83.5% repetition; the largest is "claimed PASS while acceptance failed" | small | **delivered 2026-11-17** |
| 4 | **Honest outcome classification** — `pass \| failure \| contradictory \| unknown`, voting instead of field precedence | 10 contradictory and 86 unclassifiable legacy records were being resolved silently | small | **delivered 2026-11-17** |
| 5 | **Backfill `run_id`/component stamps for history** — `join` resolves 12/12 through the naming convention | enables historical attribution | small | optional |
| 6 | SQLite / FTS index | 12 rows scan instantly | — | trigger: >5000 rows or >2s scan |
| 7 | Promotion pipeline, held-out set, Evolution Laboratory, skill compilation | 0 validated methods exist to promote or compile | large | trigger: competing candidates / ≥1 validated method |

Rule for reading this table: items 1–5 make the *existing* system cheaper to operate. Items 6–7 make
it cleverer. The measurements say the current cost is lost evidence and repeated failures, not
insufficient cleverness.

## 8. The usage-capture contract (why it is not "pick whichever number looks bigger")

`lane-usage.py` deliberately refuses to guess:

- Usage is summed from **`turn_end`** only. A complete pi stream emits `message_end` *and*
  `turn_end` for the same response (verified against real output), so summing both would bill
  every turn twice.
- `message_end` is the fallback **only** when the stream has no `turn_end` at all.
- A stream is complete only if its **last** event is `turn_end`/`agent_end`/`agent_settled`. An
  earlier completion does not certify a truncated later turn.
- An **incomplete** stream returns all-zero totals with `captured: false`. A killed turn is never
  charged a guessed figure, and `lane-model.sh` stores usage only after a successful extraction.
- Named `responseId`s are de-duplicated so a retried response is billed once.

This contract was forced by four rounds of adversarial review (`.ai/reviews/sol-harness-review-2026-11-17.md`);
each earlier version was simpler and silently wrong in one direction.

## 9. Known limits, stated rather than hidden

- **86 of 263 landing records are unclassifiable** — legacy rows predating the outcome fields.
  They are excluded from the rate and counted in the report, not assumed successful.
- **10 records are contradictory** (fields disagree). They are reported, never resolved by
  precedence.
- Cost figures exist only for lanes dispatched after 2026-11-17, and only for complete streams.
- Hints are owner-authored text. Counts are always shown; model-authored prose is never fed back
  into a prompt.
- **`trial --component` only accepts the three auto-stamped harness components.** A *product*
  change (for example `daily-ledger.consignee-return`) therefore cannot pre-register a trial yet;
  its falsifier is the acceptance test. Widening the component vocabulary is the prerequisite
  before trials can cover product work — found by using the tool on the 2026-11-17 concerns.
- A test that writes to a live store must set `HARPP_EXPERIENCE_HINTS` /
  `HARPP_EXPERIENCE_TRIALS` in `setUp`, not per call: four test fixtures reached the live hint
  store before that was fixed, and a dry run surfaced them in a real prompt.
