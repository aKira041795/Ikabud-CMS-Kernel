#!/usr/bin/env bash
#
# Lane: harness-review
#
# Independent ADVERSARIAL review of the 2026-10-07 harness work. The chair wrote every probe and
# every contract in this batch, so the chair is exactly the author who cannot see their own blind
# spots - and this repository's strongest measured lesson is that a guard which cannot fail is
# worse than none, because it is trusted (2026-10-05: three such guards in one batch, two of them
# found only by an independent review).
#
# The reviewer's job is not to agree. It is to ask "what would make this red?" for every new guard,
# RUN that mutation, and report the observed result - including "it did not go red".
#
# CONTRACT: .ai/harness-review-contract.md
# CRITERION: tools/harness-review-probe.sh   (completeness only - the chair judges correctness)

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are performing an INDEPENDENT, ADVERSARIAL review of the governed lane harness in the Ikabud
repo at /var/www/html/applicationostest. You did not write any of it. Assume it is wrong until a
mutation proves otherwise.

## ARTIFACT (your only deliverable)

Write docs/reviews/harness-v2-adversarial-review-2026-10-07.md . Nothing else may be left modified.
The probe checks this file AND that the harness itself is untouched when you finish.

## WHAT WAS CHANGED TODAY (read the diff, do not trust this summary)

  git --no-pager log --oneline -6
  git --no-pager diff 2c927e08..HEAD -- tools/

Four things landed / are landing:
  1. tools/lane.sh - classify_log now branches on the EXIT STATUS first (content may only classify a
     FAILURE) and a clean exit-0 with no Status line is `no_report`, not `unknown`.  [commit f794350a]
  2. tools/lane.sh - post-landing ACCEPTANCE VERIFICATION: the dispatch's acceptance command is
     re-run when the lane lands, recorded as acceptance/verdict, and the governed verdict is
     VERIFIED / NOT_VERIFIED / EXECUTION_ONLY.
  3. tools/lane-model.sh - budget-aware per-attempt caps so a HANG cannot starve the fallback chain.
  4. tools/lane.sh - lane-scoped changed_files attribution (delta vs whole tree).

## THE GUARDS YOU MUST ATTACK, ONE BY ONE

The two probes ARE the specs; read them first, they are short and they state the requirement:
  tools/harness-acceptance-verify-probe.sh     -> directions probe-A, probe-B, probe-C, probe-D
  tools/harness-changed-files-probe.sh         -> directions D1, D2, D3, D4
  tools/harness-chain-liveness-probe.sh        -> directions L1, L2, L3, L4, L5
and the harness's own selftest cases added today:
  S12, S12b, S12c    (acceptance verification)
  S13, S13b          (exit-status-authoritative classification)
  S14, S14b, S14c    (changed_files attribution)

For EVERY guard, produce a row with: the guard id; THE MUTATION that should turn it red; whether you
RUN it or only READ it; and the OBSERVED result (red/green, with the actual output).

- `READ` is acceptable ONLY when a mutation genuinely cannot be run - and then say why.
- At least three mutations must be RUN. A described mutation is not a measurement.
- If a mutation does NOT turn its guard red, that is a FINDING, and the most valuable one you can
  produce. Say it plainly. Do not soften it, and do not invent a different explanation.

## ALSO ANSWER, WITH EVIDENCE

1. Is there any guard in this batch that CANNOT FAIL - i.e. that passes on the unfixed tree, or whose
   assertion is already true for an unrelated reason? Say `none found` if that is the honest answer.
2. Does any implementation CONTRADICT its own comment? (This file's history is full of verdicts that
   contradicted their own evidence - that is why the batch exists.)
3. Is the exit-code boundary intact? `run` must still return the LANE's process status, not the
   governed verdict. Find the case that would break if it did.
4. Did anything get over-built? The owner's instruction was explicitly "not mechanical, intuitive
   performance", and the contracts forbid a second verification framework, a state machine, NLP
   status parsing, worktrees or ownership registries. Name anything that crosses that line.
5. Does the self-hosting path hold? The generated runner was created BEFORE these edits and calls
   `record` with the legacy 4-argument form. Prove the legacy path still writes VALID JSON, or show
   where it breaks.

## RULES

- You MAY mutate tools/lane.sh and tools/lane-model.sh to run a mutation - but take a copy first
  (`cp tools/lane.sh /tmp/rev-lane.sh`) and restore IMMEDIATELY after each one. Before you finish,
  `git status --porcelain -- tools/lane.sh tools/lane-model.sh tools/lane-watch.sh` MUST be empty.
  A left-behind mutation silently corrupts the thing under review, and the probe fails on it.
- Do NOT edit any probe, contract or lane script. Do NOT "fix" anything. You are reviewing.
- Do NOT run `bash tools/lane.sh selftest` while another lane is running. Check with
  `pgrep -af '[l]ane-harness-'` FIRST - and note that the only match will be YOUR OWN process
  (`bash tools/lane-harness-review.sh`), which you must ignore. A match naming any OTHER
  lane-harness-* script means a lane is running: skip the selftest then and read the code instead.
  The selftest takes fixture names st1..st15b in .ai/runs and would collide.
- Your own opus is not evidence. Every claim needs a command and its output.

## REPORT (in the artifact, and in your final message)

status: PASS | FINDINGS | BLOCKED. List every finding as: guard id, what is wrong, the command that
shows it, and the observed output. State explicitly the total guards attacked, how many mutations
were RUN, and whether any guard cannot fail.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harness-review
rc=$?
echo "lane: harness-review - completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
