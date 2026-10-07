#!/usr/bin/env bash
#
# Lane: harness-changed-files
#
# Makes the landing record describe THIS lane's contribution instead of the whole tree's dirty
# state, which is what the scope check needs and what the record currently gets wrong.
#
# WHY: changed_files is `git status --porcelain | wc -l` over the whole tree. Measured 2026-10-02:
# a lane that lived 9 seconds reported "changed_files": 11. Every landing report in this repository
# carries a number that cannot answer the question it exists for.
#
# CONTRACT (AUTHORITATIVE): .ai/harness-changed-files-contract.md
# CRITERION (DO NOT EDIT):  tools/harness-changed-files-probe.sh

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a change to the GOVERNED LANE HARNESS in the Ikabud repo at
/var/www/html/applicationostest.

READ FIRST, and treat as authoritative:
  .ai/harness-changed-files-contract.md      (the contract: interface, the ambiguous case, scope)
  tools/harness-changed-files-probe.sh       (the criterion - READ IT, DO NOT EDIT IT)
  tools/lane.sh                              (cmd_run captures the baseline, cmd_record diffs it)

The objective in one sentence: .ai/runs/<name>.landed.json must report what THIS lane changed, not
the whole tree's dirty state, so the scope check can actually be performed.

The probe is the spec and it is measured to FAIL on the tree you are starting from - the chair ran
it before dispatching you. Make it 4 passed, 0 failed WITHOUT touching it. D4 is a PIN: it already
passes and must stay passing.

## WHAT MATTERS MOST

- The delta is a SET DIFFERENCE of two sorted snapshots: paths dirty now MINUS paths already dirty
  at dispatch. Plus `git diff --name-only <head.before>` when HEAD moved, so a lane that committed
  its work is not credited with nothing.
- `tree_dirty` and `dirty_before` are KEPT and reported, so the reader can see the difference
  rather than trusting a number.
- THE AMBIGUOUS CASE (contract R4) is DEFINED: a file already dirty at dispatch and modified again
  by the lane must NOT be claimed as the lane's work, and must NOT be hidden either. Name the
  ambiguity in the printed report. Do NOT build mtime fingerprinting, content hashing or any other
  second mechanism to "solve" it - naming it is the correct answer.
- BACKWARD COMPATIBILITY (contract R5): the runner that records YOUR OWN landing was generated
  before your edit and calls `record` with the legacy 4-argument form. With no baseline file that
  must degrade to changed_files_basis="tree", exit 0, and VALID JSON. Never exit 2, never
  "unbound variable". Prove it early - if you break this, your own landing record is lost and only
  the deadline watchdog will record it as `unverified`.
- A SIBLING LANE has just changed the same file (tools/lane.sh) to add post-landing acceptance
  verification: the marker may now carry acceptance/acceptance_exit/acceptance_cmd/acceptance_log/
  verdict. Do NOT remove, rename or reorder those. Run
  `bash tools/harness-acceptance-verify-probe.sh` at the end and report its result - it must still
  be 4 passed, 0 failed.

## RULES

- Smallest correct change. Do not restructure lane.sh, do not rename existing functions, do not
  reformat the file.
- Do NOT touch: tools/harness-changed-files-probe.sh, tools/lane-model.sh, tools/lane-watch.sh,
  tools/harness-acceptance-verify-probe.sh.
- Do NOT weaken, delete or re-label an existing selftest case to reach green. A case that passed
  before your change is a PIN - keep it passing and say so.
- Sort `git status --porcelain` before differencing, or you will see spurious changes.
- `bash -n tools/lane.sh` after every edit.
- Before running `bash tools/lane.sh selftest`, check `pgrep -af '[l]ane-harness-'` is empty - the
  selftest takes the fixture names st1..st13b and would collide with a running lane.

## VERIFY (report observed numbers, not claims)

  bash -n tools/lane.sh
  bash tools/harness-changed-files-probe.sh        # want: probe: 4 passed, 0 failed
  bash tools/harness-acceptance-verify-probe.sh    # want: 4 passed, 0 failed (the sibling's spec)
  bash tools/lane.sh selftest                      # want: all cases, 0 failed

Then RUN THE MUTATION and report what you saw:
  make changed_files the whole-tree count again -> probe D1 must go RED.
If D1 does not go red, the guard is decorative - say so plainly instead of reporting a pass.

Also check that neither storage/logs/app.log nor storage/logs/error.log gained a line.

## REPORT

status: PASS | PARTIAL | BLOCKED; files changed; per-direction result for BOTH probes; the selftest
count; the mutation result; anything unverified; and any place THE CONTRACT is wrong. Reporting
BLOCKED with a precise reason is a valid, valued outcome - never weaken the criterion to pass it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harness-changed-files
rc=$?
echo "lane: harness-changed-files - completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
