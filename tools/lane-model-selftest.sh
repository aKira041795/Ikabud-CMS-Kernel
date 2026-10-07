#!/usr/bin/env bash
# Self-test for tools/lane-model.sh. Uses a stub in place of `pi`, so it costs no tokens.
#
# The stub receives: <stub> --model <name> <prompt>
set -uo pipefail
# Work from the repository this script lives in, so the harness is portable and does not depend on
# an absolute checkout path.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

STUB=/tmp/lane-model-stub.sh

# THE LEDGER MUST BE ISOLATED FROM PRODUCTION.
#
# This selftest deliberately fails the REAL model names in the canonical chain with a rate-limit
# message (see CHAINSTUB below). Once lane-model.sh records exhaustion in a ledger, running this
# file against the default ledger would mark `openai-codex/gpt-5.6-sol` and `deepseek-v4-flash`
# exhausted, and the next real dispatch would SKIP them for the whole cooldown. A test that poisons
# the state of the system it tests is worse than no test.
export MODEL_AVAIL_LEDGER="$(mktemp -d)/model-availability.json"
cat > "$STUB" <<'STUBEOF'
#!/usr/bin/env bash
model="$2"
case "$model" in
  ok)          echo "did the work for $model"; exit 0;;
  ratelimited) echo "Error: Rate limit reached for $model"; exit 1;;
  quota)       echo "You have hit your usage limit, try again later"; exit 1;;
  unsupported) echo "Codex error: The '$model' model is not supported when using Codex with a ChatGPT account."; exit 1;;
  sneaky)      echo "status: PASS"; echo "quota exceeded but exit 0"; exit 0;;
  *)           echo "worked: $model"; exit 0;;
esac
STUBEOF
chmod +x "$STUB"
export LANE_MODEL_CMD="$STUB"
export LANE_MODEL_TIMEOUT=10

pass=0; fail=0
chk() { # chk <desc> <expected-model> <expected-rc> <actual-model> <actual-rc>
  if [ "$2" = "$4" ] && [ "$3" = "$5" ]; then
    echo "  PASS  $1"; pass=$((pass+1))
  else
    echo "  FAIL  $1"; echo "        expected model='$2' rc=$3, got model='$4' rc=$5"; fail=$((fail+1))
  fi
}

source tools/lane-model.sh

echo "=== MUST-ALLOW: a healthy primary model must NOT fall through ==="
lane_model_run "ok,ratelimited" "p" /tmp/lm-a >/dev/null 2>&1; rc=$?
chk "primary succeeds -> stops at model 1" "ok" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "=== MUST-REFUSE: each unavailability signature must trigger fallback ==="
for sig in ratelimited quota unsupported; do
  lane_model_run "${sig},ok" "p" /tmp/lm-b >/dev/null 2>&1; rc=$?
  chk "'$sig' falls back to the next model" "ok" 0 "$LANE_MODEL_USED" "$rc"
done

echo
echo "=== exit 0 wins over log content (this case used to assert the opposite) ==="
# An earlier version FAILED an exit-0 run whose log merely mentioned an unavailability
# word, and asserted that here as "no false green". It caused a real false red on first
# production use: a lane implementing the unavailability detector writes about "quota"
# by definition, so its own correct output matched, the work was rejected, a second
# model was spent, and the run reported "none completed".
#
# Success is decided by the exit status. Content classifies a FAILURE; it never overrules
# a success. Note `sneaky` exits 0 while printing a quota phrase - the healthy follow-on
# model must therefore NOT be reached.
lane_model_run "sneaky,ok" "p" /tmp/lm-c >/dev/null 2>&1; rc=$?
chk "exit-0 run mentioning quota is a SUCCESS (no false red)" "sneaky" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "=== but a FAILED run whose log shows unavailability still falls back ==="
lane_model_run "ratelimited,ok" "p" /tmp/lm-h >/dev/null 2>&1; rc=$?
chk "non-zero exit + signature -> fall back" "ok" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "=== all models unavailable -> must report failure, not a silent success ==="
lane_model_run "ratelimited,quota" "p" /tmp/lm-d >/dev/null 2>&1; rc=$?
chk "all unavailable -> rc=1, empty model" "" 1 "$LANE_MODEL_USED" "$rc"

echo
echo "=== three-model chain: only the last can serve ==="
lane_model_run "ratelimited,unsupported,ok" "p" /tmp/lm-e >/dev/null 2>&1; rc=$?
chk "third model serves" "ok" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "=== single healthy model (the common case) ==="
lane_model_run "ok" "p" /tmp/lm-f >/dev/null 2>&1; rc=$?
chk "single model works" "ok" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "=== argument validation: a missing prefix must fail loudly and USEFULLY ==="
# This case was missing when the tool was first written. A lane that omitted the third
# argument died as "$3: unbound variable" under set -u, which names the shell's problem
# instead of the caller's, and the lane author gets a crash with no hint of the fix.
#
# NOTE: the message check and the variable check must be SEPARATE calls. Capturing output
# with $( ) runs the function in a subshell, so its globals cannot reach the parent - an
# earlier version of this test asserted the variable through $( ) and failed for that
# reason rather than because the helper was wrong.
msg=$(lane_model_run "ok" "p" 2>&1); rc=$?
chk "missing prefix -> rc=2" "" 2 "" "$rc"
case "$msg" in
  *"usage: lane_model_run"*) echo "  PASS  error message names the correct usage"; pass=$((pass+1));;
  *) echo "  FAIL  error message is not actionable: $msg"; fail=$((fail+1));;
esac
case "$msg" in
  *"unbound variable"*) echo "  FAIL  leaked a raw shell error to the caller"; fail=$((fail+1));;
  *) echo "  PASS  no raw 'unbound variable' leaked"; pass=$((pass+1));;
esac

# No command substitution here, so the reset of LANE_MODEL_USED IS observable.
LANE_MODEL_USED="sentinel"
lane_model_run "ok" "p" >/dev/null 2>&1; rc=$?
chk "missing prefix clears LANE_MODEL_USED (no stale model reported)" "" 2 "$LANE_MODEL_USED" "$rc"

echo
echo "=== empty model list must not silently 'succeed' ==="
LANE_MODEL_USED="sentinel"
lane_model_run "" "p" /tmp/lm-g >/dev/null 2>&1; rc=$?
chk "empty models -> rc=2" "" 2 "$LANE_MODEL_USED" "$rc"

echo
echo "=== canonical chain: a third model must exist, or one provider ends the work ==="
# Measured 2026-10-03: a lane whose list was Sol+DeepSeek stopped dead when BOTH were
# unavailable. The chain now comes from one file and its minimum length is CHECKED, with
# both directions proven - a chain that is too short, and one that is long enough.
canon="$(lane_model_chain_from "$LANE_MODEL_CHAIN_FILE")"
echo "  chain: $canon"
lane_model_chain_ok "$canon" \
  && { echo "  PASS  canonical chain carries >= ${LANE_MODEL_CHAIN_MIN} models"; pass=$((pass+1)); } \
  || { echo "  FAIL  canonical chain is shorter than ${LANE_MODEL_CHAIN_MIN} models"; fail=$((fail+1)); }
case "$canon" in
  *openai-codex/gpt-5.6-sol*deepseek-v4-flash*gpt-5.6-terra*)
    echo "  PASS  chain order is Sol -> DeepSeek Flash -> Terra"; pass=$((pass+1));;
  *) echo "  FAIL  unexpected chain order: $canon"; fail=$((fail+1));;
esac

# MUST-REFUSE - a two-model chain IS the defect, so it must be rejected.
printf 'openai-codex/gpt-5.6-sol\ndeepseek-v4-flash\n' > /tmp/lane-model-chain-short.txt
short="$(lane_model_chain_from /tmp/lane-model-chain-short.txt)"
if lane_model_chain_ok "$short"; then
  echo "  FAIL  a 2-model chain was accepted"; fail=$((fail+1))
else
  echo "  PASS  a 2-model chain is rejected"; pass=$((pass+1))
fi

# MUST-ALLOW - the same check must not refuse the real chain, or it is a wrong guard.
if lane_model_chain_ok "$canon"; then
  echo "  PASS  the canonical chain passes its own check"; pass=$((pass+1))
else
  echo "  FAIL  the canonical chain fails its own check"; fail=$((fail+1))
fi

# The third model must be REACHABLE when the first two are exhausted - the whole point of
# the chain. A stub that refuses the first two real model names proves the fall-through
# through the actual chain file, not through a synthetic list.
CHAINSTUB=/tmp/lane-model-chainstub.sh
cat > "$CHAINSTUB" <<'CHAINEOF'
#!/usr/bin/env bash
model="$2"
case "$model" in
  openai-codex/gpt-5.6-sol|deepseek-v4-flash)
    echo "Error: You have hit your usage limit, try again later"; exit 1;;
  *) echo "did the work for $model"; exit 0;;
esac
CHAINEOF
chmod +x "$CHAINSTUB"
saved_cmd="$LANE_MODEL_CMD"
export LANE_MODEL_CMD="$CHAINSTUB"
lane_model_run "$canon" "p" /tmp/lm-chain >/dev/null 2>&1; rc=$?
export LANE_MODEL_CMD="$saved_cmd"
chk "first two exhausted -> the third model carries the work" \
    "openai-codex/gpt-5.6-terra" 0 "$LANE_MODEL_USED" "$rc"

echo
echo "-------------------------------------------"
echo "selftest: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ] || exit 1
