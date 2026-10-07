#!/usr/bin/env bash
#
# tools/harness-acceptance-verify-probe.sh
#
# THE CRITERION for "the harness verifies the outcome; it does not take the lane's word for it".
# This is the spec. It asserts OBSERVABLE OUTCOMES through the published interface (the landing
# record's fields), never the implementation's internals, so it stays valid across a redesign.
#
# WHY IT EXISTS. `tools/lane.sh run` already refuses to dispatch unless the acceptance command
# FAILS on this tree. Nothing ever re-ran it afterwards, so the only post-landing signal was the
# lane's own "Status:" line - and this repository has a standing, measured lesson that a lane's
# own PASS is not evidence (2026-09-26). The pre-dispatch gate is the natural half-contract; this
# probe demands the other half.
#
# THREE DIRECTIONS. All are required. A one-directional check passes just as well against an
# implementation that reports NOT_VERIFIED unconditionally, which is a wrong guard - and a wrong
# guard is worse than none, because it is trusted.
#
#   A  a lane that CLAIMS success ("status: PASS", exit 0) without satisfying the criterion
#      -> acceptance=FAIL, verdict=NOT_VERIFIED.
#      Mutation that must kill the implementation: delete the post-landing re-run.
#
#   B  a lane that SATISFIES the criterion but REPORTS failure ("status: FAIL")
#      -> acceptance=PASS, verdict=VERIFIED. The self-report may not override the verifier.
#      Mutation: let the Status line participate in the governed verdict.
#
#   C  a dispatch with no criterion at all (the recorded override)
#      -> acceptance=SKIPPED, verdict=EXECUTION_ONLY. Never VERIFIED: nothing was checked.
#      Mutation: default `acceptance` to PASS when the command is absent.
#
# Interface held (published in .ai/harness-acceptance-verify-contract.md):
#   .ai/runs/<name>.landed.json gains  acceptance, acceptance_exit, acceptance_cmd,
#   acceptance_log, verdict.
#
# Exit 0 = the harness verifies. Non-zero = it still takes the lane's word for it.
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

RUNS="$ROOT/.ai/runs"
P="$RUNS/probe-acceptance"
mkdir -p "$P"
FLAG_A="$RUNS/probe-claims-pass.flag"
FLAG_B="$RUNS/probe-satisfies.flag"
rm -f "$FLAG_A" "$FLAG_B"

mklane() { # mklane <path> <status-line>
  { echo '#!/usr/bin/env bash'
    echo "echo \"status: $2\""
  } > "$1"
  chmod +x "$1"
}

pass=0; fail=0
ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
bad() { echo "   FAIL  $1"; fail=$((fail+1)); }

# Read one field out of a landing marker without parsing the whole JSON.
mfield() { grep -o "\"$2\": \"[^\"]*\"" "$1" 2>/dev/null | head -1 | cut -d'"' -f4; }

echo "== does the harness verify the outcome, or take the report for it? =="

# ── A. claims success, does not deliver ────────────────────────────────────────
mklane "$P/lane-claims-pass.sh" PASS
rm -f "$RUNS/probe-claims-pass.landed.json" "$RUNS/probe-claims-pass.log"
rmdir "$RUNS/probe-claims-pass.commit.lock" 2>/dev/null || true
bash tools/lane.sh run probe-claims-pass "$P/lane-claims-pass.sh" \
  --timeout=60 --wait-grace=2 --slice=20 \
  --acceptance="test -f $FLAG_A" \
  --pass-looks-like="the flag $(basename "$FLAG_A") exists" > "$P/A.mon.log" 2>&1
mA="$RUNS/probe-claims-pass.landed.json"
accA=$(mfield "$mA" acceptance); verA=$(mfield "$mA" verdict)
if [ "$accA" = "FAIL" ] && [ "$verA" = "NOT_VERIFIED" ]; then
  ok "A a lane that claims PASS without delivering is NOT_VERIFIED (acceptance=$accA)"
else
  bad "A a lane that claimed PASS without delivering was not refused (acceptance='$accA' verdict='$verA')"
fi

# ── B. delivers, but reports failure ──────────────────────────────────────────
{ echo '#!/usr/bin/env bash'
  echo ": > \"$FLAG_B\""
  echo 'echo "status: FAIL"'
} > "$P/lane-satisfies.sh"
chmod +x "$P/lane-satisfies.sh"
rm -f "$RUNS/probe-satisfies.landed.json" "$RUNS/probe-satisfies.log"
rmdir "$RUNS/probe-satisfies.commit.lock" 2>/dev/null || true
bash tools/lane.sh run probe-satisfies "$P/lane-satisfies.sh" \
  --timeout=60 --wait-grace=2 --slice=20 \
  --acceptance="test -f $FLAG_B" \
  --pass-looks-like="the flag $(basename "$FLAG_B") exists" > "$P/B.mon.log" 2>&1
mB="$RUNS/probe-satisfies.landed.json"
accB=$(mfield "$mB" acceptance); verB=$(mfield "$mB" verdict)
if [ "$accB" = "PASS" ] && [ "$verB" = "VERIFIED" ]; then
  ok "B a lane that delivers but reports FAIL is still VERIFIED (acceptance=$accB)"
else
  bad "B the self-report overrode the verifier (acceptance='$accB' verdict='$verB')"
fi

# ── C. nothing was checked, so nothing may be called verified ─────────────────
mklane "$P/lane-no-criterion.sh" PASS
rm -f "$RUNS/probe-no-criterion.landed.json" "$RUNS/probe-no-criterion.log"
rmdir "$RUNS/probe-no-criterion.commit.lock" 2>/dev/null || true
bash tools/lane.sh run probe-no-criterion "$P/lane-no-criterion.sh" \
  --timeout=60 --wait-grace=2 --slice=20 \
  --no-acceptance-gate="probe: a criterion the gate would refuse on its own fixture" \
  > "$P/C.mon.log" 2>&1
mC="$RUNS/probe-no-criterion.landed.json"
accC=$(mfield "$mC" acceptance); verC=$(mfield "$mC" verdict)
if [ "$accC" = "SKIPPED" ] && [ "$verC" = "EXECUTION_ONLY" ]; then
  ok "C an unchecked dispatch is EXECUTION_ONLY, never VERIFIED (acceptance=$accC)"
else
  bad "C an unchecked dispatch claimed more than it checked (acceptance='$accC' verdict='$verC')"
fi

# ── D. the pre-existing contract must not regress ─────────────────────────────
# The exit code still reports the LANE's process status (S3/S4b/S9c depend on it). If the
# governed verdict started driving the exit code, those cases would break - so the probe pins
# the boundary explicitly rather than assuming it.
{ echo '#!/usr/bin/env bash'
  echo 'echo "status: PASS"'
  echo 'exit 0'
} > "$P/lane-exit-contract.sh"
chmod +x "$P/lane-exit-contract.sh"
rm -f "$RUNS/probe-exit-contract.landed.json" "$RUNS/probe-exit-contract.log"
rmdir "$RUNS/probe-exit-contract.commit.lock" 2>/dev/null || true
bash tools/lane.sh run probe-exit-contract "$P/lane-exit-contract.sh" \
  --timeout=60 --wait-grace=2 --slice=20 \
  --acceptance="test -f $RUNS/probe-never-created.flag" \
  --pass-looks-like="a flag that this fixture deliberately never creates" \
  > "$P/D.mon.log" 2>&1
rcD=$?
accD=$(mfield "$RUNS/probe-exit-contract.landed.json" acceptance)
if [ "$rcD" -eq 0 ] && [ "$accD" = "FAIL" ]; then
  ok "D a clean execution with a failing criterion still returns 0 (verdict is reported, not encoded)"
else
  bad "D the exit-code contract changed (rc=$rcD acceptance='$accD')"
fi

echo
echo "== probe: $pass passed, $fail failed =="
[ "$fail" -eq 0 ] || exit 1
exit 0
