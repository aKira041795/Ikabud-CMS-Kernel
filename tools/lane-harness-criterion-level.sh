#!/usr/bin/env bash
#
# Lane: harness-criterion-level
#
# CONTRACT (AUTHORITATIVE): .ai/harness-criterion-level.contract.md
# Consult that produced the design: .ai/consult/harness-criterion-level-2026-10-07.md
#
# WHY THIS EXISTS: today a lane's criterion asserted the internal routing DECISION
# (rewriteUri('/login') returns a module path). It failed on the unchanged tree, it passed after
# landing, the harness recorded acceptance=PASS verdict=VERIFIED - and THREE OF TWELVE tenant hosts
# were broken (one infinite redirect loop, two serving a public frontpage instead of a login page).
# The verdict was trustworthy and wrong at the same time.
#
# The framing to build on: this is NOT inadequate testing, it is ACCEPTANCE-EVIDENCE MISMATCH.
# "VERIFIED" means "the declared criterion became true", not "the user-visible obligation became true".
# So control the EPISTEMIC BOUNDARY of the criterion; do NOT try to predict risk from code paths.
#
# The chair's own first draft proposed a changed-path -> risk-class inference table. ChatGPT argued it
# out and was right: paths do not reliably tell you where correctness becomes observable ("a router
# change can be unit-safe but HTTP-broken; that incident is the evidence"), and a heuristic guard is
# itself a trusted, wrong guard. The required level belongs in the CONTRACT, authored by the chair.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: "dispatch to Sol"). Small diff, but it sits inside the gate that everything else
# depends on - the failure mode of getting it wrong is a trusted guard, which is worse than none.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the criterion-level gate in tools/lane.sh, in the Ikabud repo at
/var/www/html/applicationostest.

Read .ai/harness-criterion-level.contract.md FIRST — it is the authority, including the prohibited list
and the explicit refusals. Read .ai/consult/harness-criterion-level-2026-10-07.md for the reasoning.

## THE SURFACES (verified by the chair, use these; do not go hunting)
- arg parsing:        tools/lane.sh  ~468-484   (--acceptance, --pass-looks-like, --no-acceptance-gate,
                                                 --touches, --timeout, --wait-grace ... )
- pre-dispatch gate:  tools/lane.sh  ~494-530   (gateOverride branch, then the "no criterion" refusal,
                                                 then run + "ALREADY PASSES" refusal + acceptance-gate.log)
- record/marker:      tools/lane.sh  ~242, ~271, ~362-407  (acceptance / acceptance_exit /
                                                 acceptance_cmd / acceptance_log / verdict fields)
- selftest pattern:   tools/lane.sh  ~1183-1226 (S9 must-refuse, S9b already-passes must-refuse,
                                                 S9c must-allow; mklane + marker_field helpers)

## REQUIRED BEHAVIOUR
R1  `run` gains `--required-level=<unit|http|browser|corpus>` and `--criterion-level=<same>`, ranked
    unit=1 http=2 browser=3 corpus=4.
R2  REFUSE (exit 2) when criterion_rank < required_rank, naming BOTH levels in the message.
    THE LEVEL CHECK MUST RUN BEFORE THE EXISTING ACCEPTANCE GATE. This is not cosmetic: the probe that
    proves this gate has to show the refusal is ATTRIBUTABLE to the level and not to "already passes"
    or "no criterion". Put it first and say why in a comment.
R3  `--required-level` present but `--criterion-level` absent => REFUSE (an undeclared criterion is
    unknown evidence). `--criterion-level` HIGHER than required is ALLOWED.
R4  BACK-COMPATIBILITY: with no `--required-level`, behaviour is EXACTLY as today. Every existing lane
    and every existing selftest case must dispatch unchanged. Do not make the new flags mandatory.
R5  override `--no-level-gate="<reason>"` mirrors `--no-acceptance-gate`: it proceeds, and the override
    plus its reason are appended to .ai/runs/acceptance-gate.log. Never bypass silently.
    IMPORTANT: the level check is INDEPENDENT of --no-acceptance-gate. Bypassing the acceptance gate
    does not opt you out of declaring an admissible criterion level.
R6  record both levels where a reader actually looks: the acceptance-gate.log line, and the landing
    marker / journal beside the existing `acceptance` / `acceptance_cmd` / `verdict` fields.
    (This is the consult's lever B, taken as a free by-product — NOT as a separate feature. Do not
    build any reporting framework around it.)

## EXPLICITLY REFUSED — do not build
1. A changed-path -> risk-class inference engine. No path table, no heuristic mapping. The required
   level is declared in the contract by the chair. This is the single most important refusal here.
2. Browser-level evidence required by default.
3. A large taxonomy of surface types.

## ORACLE
The acceptance criterion for THIS lane is tools/lane-harness-criterion-level-acceptance.sh. The chair
measured it on the unchanged tree: it FAILS (the unknown flags are ignored so the mismatch DISPATCHES,
rc=0), which is what makes it discriminating. It must pass when you are done.
Then extend the selftest with both directions, mirroring S9 / S9b / S9c:
  - must-refuse mismatch: `--required-level=http --criterion-level=unit` => rc=2
  - must-refuse undeclared: `--required-level=http` with no `--criterion-level` => rc=2
  - must-allow matching: `--required-level=http --criterion-level=http` => rc=0 and state=landed
  - must-allow stronger: `--criterion-level=corpus` against `--required-level=unit` => allowed
  - must-allow back-compat: no level flags => unchanged dispatch and landing
Label each case as must-refuse or must-allow. A direction you did not run is not a guard.

## FALSIFICATION AGAINST THE REAL DEFECT (do this, do not substitute a fixture)
With `--required-level=http`, submit the ACTUAL historical unit criterion — the rewriteUri('/login')
probe — and demonstrate the harness REFUSES it. Choose a tree state where that unit criterion PASSES,
so the refusal is provably for the LEVEL and not for its value. Paste the refusal in your report.

## Rules
- Smallest correct change. Never weaken, skip or delete an existing selftest case to reach green.
- Do NOT touch, remove or soften the existing acceptance gate, rc-first classify_log, the two-axis
  VERDICT:/EXECUTION: reporting, or lane-scoped changed_files. Those are load-bearing and pinned by
  S3/S4b/S7/S9c.
- `run`'s exit code must not change for any case those selftests already pin.
- Run: `bash -n tools/lane.sh`; `bash tools/lane.sh selftest` (all existing cases green plus the new
  ones); `bash tools/lane-harness-criterion-level-acceptance.sh`. Check BOTH storage/logs/app.log and
  storage/logs/error.log (error.log must be 0 bytes).
- If your own probe leaves artefacts, clean them yourself — note commit.lock is deliberately persistent
  and has to be removed explicitly.
- KNOWN PRE-EXISTING, NOT YOURS: daily_ledger_shared_account_latest_holder_test.php fails its
  log-observation assertions (functional ones pass), proven pre-existing at 3c93ad6c. Do not fix it and
  do not use it to excuse a failure you introduce.
- Report status PASS | PARTIAL | BLOCKED; files changed; selftest cases added with their direction;
  the real-criterion refusal output; anything you could not verify; and anywhere this brief or the
  contract is wrong — report it rather than improvising around it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/harness-criterion-level
rc=$?
echo "lane: harness-criterion-level — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
