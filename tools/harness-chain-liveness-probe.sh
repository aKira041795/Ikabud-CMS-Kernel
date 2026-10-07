#!/usr/bin/env bash
#
# tools/harness-chain-liveness-probe.sh
#
# THE CRITERION for "no single model attempt may consume enough of the budget to make the
# configured fallback impossible".
#
# WHY IT EXISTS. tools/lane-model.sh falls back to the next model only when the attempt EXITS.
# A HANG produces no exit, so the attempt is bounded only by LANE_MODEL_TIMEOUT - which defaults
# to 4500s against a lane budget of 7200s. With three models in the chain, ONE hang can consume
# 62.5% of the budget, and two can exceed it: the chain never reaches model 3 and the lane dies
# with nothing recorded. Measured 2026-10-04: Sol ran the full 40-minute budget, never exited, and
# the lane recorded unverified/timeout with the whole budget gone.
#
# The invariant demanded is ONE rule, not new machinery:
#     no individual attempt may consume enough of the REMAINING budget to starve the fallback.
#
# DIRECTIONS (all required; a one-directional guard passes just as well against an implementation
# that simply refuses everything):
#
#   L1 (must-allow)  a hang on model 1 must not stop the chain reaching model 2
#   L2 (must-hold)   with a 3-model chain, model 3 must remain REACHABLE after two hangs
#                    -> the discriminating case: on the unfixed tree attempt 1 exits normally,
#                       so the chain "succeeds" at model 1 for the wrong reason
#   L3 (PIN)         an EXPLICIT LANE_MODEL_TIMEOUT must still be honoured - the existing
#                    lane-model-selftest sets it to 10 and depends on it. This direction passes
#                    on the base tree too; it is labelled a pin, not a discriminator.
#   L4 (must-refuse) when every attempt is killed by the cap, the run must report NO completed
#                    model and must NAME the timeout, not blame credentials
#   L5 (must-hold)   THE WHOLE RUN must finish inside its budget - this is the direction the
#                    "remove the division" mutation actually falsifies (see the note at L2)
#
# Cost: the stub sleeps 12s (30s for L5), so the unfixed tree takes ~70s and the fixed tree ~25s.
# That is deliberately under the gate's 300s ceiling.
#
# Exit 0 = the chain is hang-resilient. Non-zero = one hang can still eat the budget.
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

STUB=/tmp/lane-chain-liveness-stub.sh
cat > "$STUB" <<'STUBEOF'
#!/usr/bin/env bash
model="$2"
case "$model" in
  hang)  echo "starting $model"; sleep 12; echo "worked: $model"; exit 0;;
  stuck) echo "starting $model"; sleep 30; echo "worked: $model"; exit 0;;
  *)     echo "worked: $model"; exit 0;;
esac
STUBEOF
chmod +x "$STUB"

pass=0; fail=0
ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
bad() { echo "   FAIL  $1"; fail=$((fail+1)); }

echo "== can one hanging model spend the whole budget? =="

# L1 - the chain must survive model 1 hanging.
# Budget 6s across 2 attempts => each attempt may take at most ~3s, so `hang` is cut and `ok` runs.
out1=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_BUDGET=6 LANE_MODEL_TIMEOUT_MIN=2 \
       LANE_MODEL_TIMEOUT="" LANE_MODEL_CHAIN="hang,ok" \
       bash -c 'source tools/lane-model.sh; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l1; echo "used=${LANE_MODEL_USED} rc=$?"' 2>&1)
case "$out1" in
  *"used=ok rc=0"*) ok "L1 a hanging model 1 does not stop the chain (reached model 2)";;
  *) bad "L1 the chain did not survive a hang: $(printf '%s' "$out1" | tail -2 | tr '\n' ' ')";;
esac

# L2 - model 3 stays REACHABLE after two hangs. This discriminates against the unfixed tree, which
# has no budget concept at all (cap 4500s, so the stub simply exits 0 and the chain "succeeds" at
# model 1 for the wrong reason).
#
# IT IS NOT FALSIFIED BY THE 'remove the division' MUTATION, and that is recorded rather than
# quietly reworded. Found by the implementing lane on 2026-10-07: with budget 9 / 3 attempts, a
# mutated implementation that hands each attempt the whole remaining budget still reaches model 3,
# because the MIN floor caps the later attempts (9s, then 2s, then 2s) and the stub only sleeps 12s.
# The lane reported PARTIAL rather than claiming a pass - correct behaviour. L5 is the direction
# that the division mutation actually falsifies. Lesson, which this repo has now paid for five
# times: a named mutation is an ASSUMPTION until it has been measured against the implementation.
# (The criterion is also deliberately NOT asserting "elapsed <= budget" here: a single hung attempt
# legitimately is allowed its share, and only the whole three-attempt run is bounded.)
out2=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_BUDGET=9 LANE_MODEL_TIMEOUT_MIN=2 \
       LANE_MODEL_TIMEOUT="" LANE_MODEL_CHAIN="hang,hang,ok" \
       bash -c 'source tools/lane-model.sh; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l2; echo "used=${LANE_MODEL_USED} rc=$?"' 2>&1)
case "$out2" in
  *"used=ok rc=0"*) ok "L2 model 3 is still reachable after two hangs";;
  *) bad "L2 two hangs starved the third model: $(printf '%s' "$out2" | tail -2 | tr '\n' ' ')";;
esac

# L5 - THE DIRECTION THE DIVISION MUTATION FALSIFIES. The invariant is a bound on the WHOLE run, not
# just on reachability: the chain must finish inside its budget instead of spending the first
# attempt's whole share on a hang.
#   correct  (cap = remaining/attempts_left, floor 2)  15/3=5, then 10/2=5, then instant  ~10s
#   mutated  (cap = the whole remaining budget)        15,  then floor 2,  then floor 2    ~17s
# Measured threshold: elapsed <= LANE_MODEL_BUDGET. The gap is ~5s, so a slow machine cannot blur it.
out5=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_BUDGET=15 LANE_MODEL_TIMEOUT_MIN=2 \
       LANE_MODEL_TIMEOUT="" LANE_MODEL_CHAIN="stuck,stuck,ok" \
       bash -c 'source tools/lane-model.sh; t0=$SECONDS; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l5 >/dev/null 2>&1; echo "used=${LANE_MODEL_USED} elapsed=$((SECONDS-t0))"' 2>&1)
e5=$(printf '%s' "$out5" | grep -o 'elapsed=[0-9]*' | cut -d= -f2)
case "$out5" in
  *"used=ok"*)
    if [ -n "$e5" ] && [ "$e5" -le 15 ]; then
      ok "L5 the whole chain finishes inside its budget (elapsed=${e5}s of 15s)"
    else
      bad "L5 the run overspent its budget: elapsed=${e5:-?}s > 15s"
    fi;;
  *) bad "L5 two hangs starved the third model: $(printf '%s' "$out5" | tail -2 | tr '\n' ' ')";;
esac

# L3 - PIN (passes on the base tree too, by design): an explicit per-attempt cap wins over the
# derived budget, or the existing lane-model-selftest would be silently rewritten.
out3=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_TIMEOUT=2 LANE_MODEL_BUDGET=6000 \
       LANE_MODEL_CHAIN="hang,ok" \
       bash -c 'source tools/lane-model.sh; t0=$SECONDS; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l3 >/dev/null 2>&1; echo "used=${LANE_MODEL_USED} elapsed=$((SECONDS-t0))"' 2>&1)
case "$out3" in
  *"used=ok"*) ok "L3 PIN: an explicit LANE_MODEL_TIMEOUT is still honoured";;
  *) bad "L3 the explicit per-attempt cap was ignored: $out3";;
esac

# L4 - when every attempt is cut by the cap the outcome must be NAMED as a timeout and must not be
# reported as a completed model. On the unfixed tree the stub simply exits 0 after 12s, so a model
# "completes" - which is the false comfort this direction removes.
out4=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_BUDGET=4 LANE_MODEL_TIMEOUT_MIN=2 \
       LANE_MODEL_TIMEOUT="" LANE_MODEL_CHAIN="hang,hang" \
       bash -c 'source tools/lane-model.sh; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l4; echo "used=${LANE_MODEL_USED} rc=$?"' 2>&1)
if printf '%s' "$out4" | grep -q 'used= rc=1'; then
  if printf '%s' "$out4" | grep -qi 'timeout'; then
    ok "L4 every attempt cut by the cap reports no model completed AND names the timeout"
  else
    bad "L4 the failure was not named as a timeout: $(printf '%s' "$out4" | tail -2 | tr '\n' ' ')"
  fi
else
  bad "L4 a hung run reported a completed model: $(printf '%s' "$out4" | tail -2 | tr '\n' ' ')"
fi

echo
echo "== probe: $pass passed, $fail failed =="
[ "$fail" -eq 0 ] || exit 1
exit 0
