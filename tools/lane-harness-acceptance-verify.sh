#!/usr/bin/env bash
#
# Lane: harness-acceptance-verify
#
# Converts tools/lane.sh from a DISPATCHER into a VERIFIER: the dispatch's acceptance command is
# re-run after the lane lands, so the harness reports whether the criterion is now TRUE instead of
# taking the lane's own "Status:" line for it.
#
# WHY: the pre-dispatch gate refuses to dispatch unless the criterion FAILS on the starting tree,
# and then nothing ever re-runs it. The only post-landing evidence is the lane's self-report, and
# this repository has a measured lesson that a lane's own PASS is not evidence (2026-09-26).
#
# CONTRACT (AUTHORITATIVE): .ai/harness-acceptance-verify-contract.md
# CRITERION (DO NOT EDIT):  tools/harness-acceptance-verify-probe.sh
#
# Sol leads because this lane edits the very tool that records its own landing: the failure modes
# are subtle (legacy 4-arg `record`, exit-status ordering in the EXIT trap, JSON validity) and a
# mistake loses this lane's own record.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing a change to the GOVERNED LANE HARNESS in the Ikabud repo at
/var/www/html/applicationostest.

READ FIRST, and treat as authoritative:
  .ai/harness-acceptance-verify-contract.md      (the contract - scope, interface, requirements)
  tools/harness-acceptance-verify-probe.sh       (the criterion - READ IT, DO NOT EDIT IT)
  tools/lane.sh                                  (18/20-case selftest lives in cmd_selftest)

The objective in one sentence: tools/lane.sh already refuses to dispatch unless the acceptance
command FAILS on this tree; make it ALSO re-run that same command after the lane lands, record the
result in the marker and the journal, and report a governed verdict derived from it.

The probe is the spec and it is measured to FAIL on the tree you are starting from (0 passed,
4 failed) - the chair ran it before dispatching you. Your job is to make it 4 passed, 0 failed
WITHOUT touching it.

## THE THING THAT WILL BITE YOU (read twice)

You are editing the tool that records YOUR OWN landing. The generated runner
(.ai/runs/<name>.runner.sh) was created BEFORE your edit and calls
`bash <abs>/tools/lane.sh record <name> <log> <rc> <run-id>` from its EXIT trap - so at YOUR exit
time it resolves YOUR EDITED file with the OLD 4-argument call. Therefore:
  - `record` MUST tolerate the 4-argument (legacy) form: missing criterion means acceptance=SKIPPED,
    NOT an error, NOT exit 2, NOT "unbound variable".
  - `write_marker`/`commit_landing` must tolerate absent new arguments and still emit VALID JSON.
  - Do NOT add a required positional argument anywhere on that path.
Prove it early with:
  bash tools/lane.sh record probe-legacy .ai/runs/probe-legacy.log 0; echo rc=$?
  php -r 'exit(json_decode(file_get_contents(".ai/runs/probe-legacy.landed.json"))===null?1:0);'
If you break this, your own landing is lost and only the deadline watchdog will record it as
`unverified`.

## THE OTHER BOUNDARY

`run`'s EXIT CODE must keep meaning the LANE's process status (0 landed clean, <n> landed with that
code, 3 still running, 1 unverified). The governed verdict is REPORTED, never encoded in the return
code. Selftest cases S3/S4/S4b/S7/S9c assert this and must stay green. Direction D of the probe
pins it.

## RULES

- Smallest correct change. Do not restructure lane.sh, do not rename existing functions, do not
  reformat the file, do not "tidy" unrelated comments.
- Do NOT touch: tools/lane-model.sh, tools/lane-watch.sh, tools/model-chain.txt, and above all
  tools/harness-acceptance-verify-probe.sh. Another lane owns the first three.
- Do NOT weaken, delete or re-label an assertion to reach green. A case that passed before your
  change is a PIN - keep it passing and say so.
- Existing journal/marker keys keep their names and meanings. ADD keys; do not rename or reorder.
- `bash -n tools/lane.sh` after every edit; a syntax error here destroys your own landing record.
- There is a `Status:` line convention in lane.sh (status_line()) - it is ADVISORY ONLY. It must not
  enter the governed verdict. That is the second mutation the probe will hold you to.

## VERIFY (all of it - report the observed numbers, not a claim)

  bash -n tools/lane.sh
  bash tools/harness-acceptance-verify-probe.sh        # want: probe: 4 passed, 0 failed
  bash tools/lane.sh selftest                          # want: 23 passed, 0 failed
  bash tools/lane-model-selftest.sh                    # want: 19 passed, 0 failed (unchanged)

Then RUN BOTH MUTATIONS and report what you observed:
  1. remove the post-landing re-run  -> probe direction A must go RED (then restore it)
  2. let the Status: line feed the verdict -> probe direction B must go RED (then restore it)
If a mutation does NOT turn the probe red, the guard is decorative - say so plainly instead of
reporting a pass.

Also check that neither storage/logs/app.log nor storage/logs/error.log gained a line.

## REPORT

status: PASS | PARTIAL | BLOCKED; files changed; the probe's observed result; the selftest count;
the two mutation results; anything unverified; and any place THE CONTRACT is wrong. Reporting
BLOCKED with a precise reason is a valid, valued outcome - do not improvise around an unsatisfiable
requirement, and never weaken the criterion to make it satisfiable.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harness-acceptance-verify
rc=$?
echo "lane: harness-acceptance-verify - completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
