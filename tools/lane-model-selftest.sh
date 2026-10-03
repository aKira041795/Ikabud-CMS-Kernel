#!/usr/bin/env bash
# Self-test for tools/lane-model.sh. Uses a stub in place of `pi`, so it costs no tokens.
#
# The stub receives: <stub> --model <name> <prompt>
set -uo pipefail
cd /var/www/html/applicationostest

STUB=/tmp/lane-model-stub.sh
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
echo "-------------------------------------------"
echo "selftest: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ] || exit 1
