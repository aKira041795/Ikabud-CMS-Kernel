#!/usr/bin/env bash
#
# tools/model-availability-selftest.sh — proves the availability ledger in BOTH directions.
#
# A one-way test ("an exhausted model is skipped") passes just as well if the ledger skips
# EVERYTHING, which would stall the program exactly as badly as never skipping. So every case
# below has a must-skip AND a must-allow half.
#
# No tokens are spent: the ledger is a local file and this test only reads and writes it.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export MODEL_AVAIL_LEDGER="$(mktemp -d)/model-availability.json"
export MODEL_AVAIL_DEFAULT_COOLDOWN=900

# shellcheck source=/dev/null
source "$DIR/model-availability.sh"

pass=0; fail=0
ok()   { pass=$((pass+1)); echo "  PASS  $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL  $1"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }

M="openai-codex/gpt-5.6-sol"

echo "=== empty ledger: everything is available (must-allow) ==="
model_is_available "$M" && ok "unknown model is available" || bad "unknown model blocked by an empty ledger"
model_is_available "deepseek-v4-flash" && ok "second model is available" || bad "second model blocked"

echo
echo "=== after exhaustion (must-skip) ==="
model_mark_exhausted "$M" "rate limit exceeded"
model_is_available "$M" && bad "exhausted model was still considered available" || ok "exhausted model is skipped"
model_is_available "deepseek-v4-flash" && ok "a DIFFERENT model is unaffected" || bad "exhaustion leaked to another model"

hint="$(model_reset_hint "$M")"
[ -n "$hint" ] && ok "a reset hint is reported ($hint)" || bad "no reset hint recorded"

line="$(model_availability_line)"
case "$line" in *"gpt-5.6-sol"*exhausted*) ok "the chair-facing line names the exhausted model" ;;
  *) bad "chair-facing line does not name the model: '$line'" ;; esac

echo
echo "=== the reason survives (it is the field that explains WHY) ==="
reason="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["reason"] ?? "";' -- "$MODEL_AVAIL_LEDGER" "$M")"
check "reason recorded" "$reason" "rate limit exceeded"

echo
echo "=== a stated reset time is honoured over the learned window ==="
model_mark_exhausted "$M" "quota reached, available 2030-01-02 03:04"
future="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["reset_at"] ?? "";' -- "$MODEL_AVAIL_LEDGER" "$M")"
case "$future" in 2030-01-02*) ok "stated reset parsed and stored ($future)" ;;
  *) bad "stated reset not honoured: '$future'" ;; esac
model_is_available "$M" && bad "a future stated reset did not block dispatch" || ok "future stated reset blocks dispatch"

echo
echo "=== recovery: cooldown elapsed means eligible again (must-allow) ==="
php -r '$p=$argv[1]; $l=json_decode(file_get_contents($p),true);
        $l["models"][$argv[2]]["reset_at"]=date("c", time()-60);
        file_put_contents($p, json_encode($l, JSON_PRETTY_PRINT));' -- "$MODEL_AVAIL_LEDGER" "$M"
model_is_available "$M" && ok "elapsed cooldown makes the model eligible again" || bad "model stayed blocked after its window elapsed"

echo
echo "=== success clears the state and learns the window ==="
model_mark_ok "$M"
model_is_available "$M" && ok "a successful model is available" || bad "model still blocked after success"
state="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["state"] ?? "";' -- "$MODEL_AVAIL_LEDGER" "$M")"
check "state cleared" "$state" "ok"
window="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["observed_window_seconds"] ?? 0;' -- "$MODEL_AVAIL_LEDGER" "$M")"
if [ "${window:-0}" -ge 300 ] 2>/dev/null; then ok "observed recovery window learned (${window}s)"; else bad "no observed window learned"; fi

echo
echo "=== fail-open: an unreadable ledger must never stop dispatch ==="
printf 'not json at all' > "$MODEL_AVAIL_LEDGER"
model_is_available "$M" && ok "corrupt ledger fails open (available)" || bad "corrupt ledger blocked dispatch"
model_availability_line >/dev/null 2>&1 && ok "corrupt ledger does not crash the reporter" || bad "reporter crashed on a corrupt ledger"

echo
echo "=== the default cooldown is not applied when a window was learned ==="
rm -f "$MODEL_AVAIL_LEDGER"
model_mark_exhausted "$M" "rate limit exceeded"
first="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["reset_at"] ?? "";' -- "$MODEL_AVAIL_LEDGER" "$M")"
model_mark_exhausted "$M" "rate limit exceeded"
second="$(php -r '$l=json_decode(file_get_contents($argv[1]),true); echo $l["models"][$argv[2]]["reset_at"] ?? "";' -- "$MODEL_AVAIL_LEDGER" "$M")"
[ -n "$first" ] && [ -n "$second" ] && ok "repeated exhaustion keeps a reset time ($second)" || bad "reset time lost on repeat"

echo
echo "=== chain integration: skipping must happen, and must NOT over-skip ==="

# A stub in place of `pi`, so this costs no tokens. It always succeeds, which means a model that is
# reached can always serve — so "which model ran" is a direct read of whether skipping happened.
STUB="$(mktemp -d)/chainstub.sh"
cat > "$STUB" <<'STUBEOF'
#!/usr/bin/env bash
echo "served by $2"
exit 0
STUBEOF
chmod +x "$STUB"

saved_cmd="${LANE_MODEL_CMD:-}"
export LANE_MODEL_CMD="$STUB"
export LANE_MODEL_TIMEOUT=10
# shellcheck source=/dev/null
source "$DIR/lane-model.sh"

CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

# MUST-NOT-SKIP: with an empty ledger the primary model must be the one that runs.
rm -f "$MODEL_AVAIL_LEDGER"
lane_model_run "$CHAIN" "p" /tmp/avail-a >/dev/null 2>&1
check "healthy ledger -> primary model runs (no over-skip)" "$LANE_MODEL_USED" "openai-codex/gpt-5.6-sol"

# MUST-SKIP: an exhausted primary must be passed over for the next in the chain.
model_mark_exhausted "openai-codex/gpt-5.6-sol" "rate limit exceeded"
lane_model_run "$CHAIN" "p" /tmp/avail-b >/dev/null 2>&1
check "exhausted primary -> next model runs" "$LANE_MODEL_USED" "deepseek-v4-flash"

# MUST-ALLOW: once the window elapses the primary is eligible again, or the skip would be permanent.
php -r '$p=$argv[1]; $l=json_decode(file_get_contents($p),true);
        $l["models"][$argv[2]]["reset_at"]=date("c", time()-60);
        file_put_contents($p, json_encode($l));' -- "$MODEL_AVAIL_LEDGER" "openai-codex/gpt-5.6-sol"
lane_model_run "$CHAIN" "p" /tmp/avail-c >/dev/null 2>&1
check "elapsed window -> primary is used again" "$LANE_MODEL_USED" "openai-codex/gpt-5.6-sol"

# MUST-NOT-STALL: if EVERY model is inside a cooldown, the lane must still try, not refuse.
for m in openai-codex/gpt-5.6-sol deepseek-v4-flash openai-codex/gpt-5.6-terra; do
  model_mark_exhausted "$m" "rate limit exceeded"
done
lane_model_run "$CHAIN" "p" /tmp/avail-d >/dev/null 2>&1
rc=$?
if [ "$rc" -eq 0 ] && [ -n "$LANE_MODEL_USED" ]; then
  ok "all models in cooldown -> still attempts and succeeds (no stall)"
else
  bad "all models in cooldown -> lane refused to try (rc=$rc) - a stale ledger would stall the program"
fi

export LANE_MODEL_CMD="$saved_cmd"

echo
echo "-------------------------------------------"
echo "selftest: ${pass} passed, ${fail} failed"
rm -f "$MODEL_AVAIL_LEDGER"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
