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
echo "=== exit 0 BUT the log proves unavailability -> must still fall back ==="
lane_model_run "sneaky,ok" "p" /tmp/lm-c >/dev/null 2>&1; rc=$?
chk "exit-0-with-quota-text falls back (no false green)" "ok" 0 "$LANE_MODEL_USED" "$rc"

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
echo "-------------------------------------------"
echo "selftest: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ] || exit 1
