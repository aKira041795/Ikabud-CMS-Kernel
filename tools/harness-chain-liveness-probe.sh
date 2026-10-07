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
#
# Cost: the stub sleeps 12s, so the unfixed tree takes ~50s and the fixed tree ~15s. That is
# deliberately under the gate's 300s ceiling.
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
  hang) echo "starting $model"; sleep 12; echo "worked: $model"; exit 0;;
  *)    echo "worked: $model"; exit 0;;
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

# L2 - the discriminating case. Three models, two of which hang: the invariant is that no attempt
# may take more than the remaining budget divided by the attempts left, so model 3 stays reachable.
out2=$(LANE_MODEL_CMD="$STUB" LANE_MODEL_BUDGET=9 LANE_MODEL_TIMEOUT_MIN=2 \
       LANE_MODEL_TIMEOUT="" LANE_MODEL_CHAIN="hang,hang,ok" \
       bash -c 'source tools/lane-model.sh; lane_model_run "$LANE_MODEL_CHAIN" "p" /tmp/lm-live-l2; echo "used=${LANE_MODEL_USED} rc=$?"' 2>&1)
case "$out2" in
  *"used=ok rc=0"*) ok "L2 model 3 is still reachable after two hangs";;
  *) bad "L2 two hangs starved the third model: $(printf '%s' "$out2" | tail -2 | tr '\n' ' ')";;
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
