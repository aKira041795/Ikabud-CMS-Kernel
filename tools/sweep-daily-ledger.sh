#!/usr/bin/env bash
#
# Daily Ledger — complete test sweep.
#
# Why this lives in the repo: the sweeps that ran during the cashier testing
# phase globbed only tests/daily-ledger/*_test.php (one of three trees) and
# parsed only the "N/N passed" format. The root tree prints two other formats,
# and several suites fatal and print nothing parseable. The auto-DR offline
# guard sat red in an unswept suite, so nothing failed loudly when its behaviour
# was deleted.
#
# This script runs all three trees, understands all three output formats, and
# treats FATAL and NO RESULT as their own outcomes — never as passes.
#
# Usage:
#   tools/sweep-daily-ledger.sh                 # human summary
#   tools/sweep-daily-ledger.sh --json          # one JSON object per suite (JSONL)
#   tools/sweep-daily-ledger.sh --baseline=FILE # also write a baseline record
#
# Exit status: 0 only when every suite produced a parseable, fully-passing
# result. Any FAIL, FATAL or NO RESULT exits non-zero so it can gate CI.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 1

JSON=0
BASELINE=""
for arg in "$@"; do
  case "$arg" in
    --json) JSON=1 ;;
    --baseline=*) BASELINE="${arg#--baseline=}" ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

TREES=(
  "tests/daily-ledger/*_test.php"
  "tests/daily_ledger*_test.php"
  "tests/load/daily_ledger_load_test.php"
)

PASS_SUITES=0
FAIL_SUITES=0
FATAL_SUITES=0
NORESULT_SUITES=0
TOTAL_ASSERT=0
PASS_ASSERT=0
PROBLEMS=""

# parse <raw output>  -> "got want" | "FATAL" | "NONE"
#
# Parse the summary FIRST. Several suites print SQLSTATE strings inside
# informational output while passing (daily_ledger_handlers_test.php prints
# "SQLSTATE[42S02]" and passes 229/229). Classifying on a SQLSTATE string
# before looking for a summary produced a false red, so only fall back to FATAL
# when there is no summary at all.
parse() {
  local out="$1" line got want failed total
  line=$(printf '%s' "$out" | grep -oE "[0-9]+/[0-9]+ passed" | tail -1)
  if [ -n "$line" ]; then
    got=${line%%/*}
    want=${line##*/}
    echo "$got ${want% passed}"
    return
  fi
  line=$(printf '%s' "$out" | grep -oE "Result: [0-9]+ passed, [0-9]+ failed" | tail -1)
  if [ -n "$line" ]; then
    got=$(printf '%s' "$line" | grep -oE "[0-9]+" | sed -n 1p)
    failed=$(printf '%s' "$line" | grep -oE "[0-9]+" | sed -n 2p)
    echo "$got $((got + failed))"
    return
  fi
  line=$(printf '%s' "$out" | grep -oE "PASS: [0-9]+[[:space:]]+FAIL: [0-9]+[[:space:]]+TOTAL: [0-9]+" | tail -1)
  if [ -n "$line" ]; then
    got=$(printf '%s' "$line" | grep -oE "[0-9]+" | sed -n 1p)
    total=$(printf '%s' "$line" | grep -oE "[0-9]+" | sed -n 3p)
    echo "$got $total"
    return
  fi
  if printf '%s' "$out" | grep -qE "^Fatal error|^Fatal:|UNCAUGHT EXCEPTION|PHP Fatal error"; then
    echo "FATAL"
    return
  fi
  echo "NONE"
}

run_one() {
  local file="$1" name out res got want rc
  name=$(basename "$file")
  out=$(timeout 300 php "$file" 2>&1)
  rc=$?
  res=$(parse "$out")
  case "$res" in
    FATAL)
      FATAL_SUITES=$((FATAL_SUITES + 1))
      PROBLEMS="$PROBLEMS ${name}:FATAL(exit=$rc)"
      [ "$JSON" = 1 ] && printf '{"suite":"%s","outcome":"FATAL","exit":%s}\n' "$name" "$rc" || printf '  %-58s FATAL (exit %s)\n' "$name" "$rc"
      return
      ;;
    NONE)
      NORESULT_SUITES=$((NORESULT_SUITES + 1))
      PROBLEMS="$PROBLEMS ${name}:NO_RESULT(exit=$rc)"
      [ "$JSON" = 1 ] && printf '{"suite":"%s","outcome":"NO_RESULT","exit":%s}\n' "$name" "$rc" || printf '  %-58s NO RESULT (exit %s)\n' "$name" "$rc"
      return
      ;;
  esac
  got=${res%% *}
  want=${res##* }
  TOTAL_ASSERT=$((TOTAL_ASSERT + want))
  PASS_ASSERT=$((PASS_ASSERT + got))
  # Require BOTH a full passing summary AND a zero process exit. A suite that prints
  # "N/N passed" and then crashes or times out (rc != 0) is a failure, not a pass;
  # the old check accepted the summary alone and certified the crash as green.
  if [ "$got" = "$want" ] && [ "$rc" -eq 0 ]; then
    PASS_SUITES=$((PASS_SUITES + 1))
    [ "$JSON" = 1 ] && printf '{"suite":"%s","outcome":"PASS","got":%s,"want":%s,"exit":%s}\n' "$name" "$got" "$want" "$rc" || printf '  %-58s %s/%s\n' "$name" "$got" "$want"
  else
    FAIL_SUITES=$((FAIL_SUITES + 1))
    if [ "$got" = "$want" ]; then
      PROBLEMS="$PROBLEMS ${name}:passed_summary_but_exit=$rc"
      [ "$JSON" = 1 ] && printf '{"suite":"%s","outcome":"FAIL","got":%s,"want":%s,"exit":%s,"note":"passing summary but non-zero exit"}\n' "$name" "$got" "$want" "$rc" || printf '  %-58s %s/%s  <-- FAIL (exit %s)\n' "$name" "$got" "$want" "$rc"
    else
      PROBLEMS="$PROBLEMS ${name}:${got}/${want}(exit=$rc)"
      [ "$JSON" = 1 ] && printf '{"suite":"%s","outcome":"FAIL","got":%s,"want":%s,"exit":%s}\n' "$name" "$got" "$want" "$rc" || printf '  %-58s %s/%s  <-- FAIL (exit %s)\n' "$name" "$got" "$want" "$rc"
    fi
  fi
}

SUITE_TOTAL=0
for tree in "${TREES[@]}"; do
  for file in $tree; do
    [ -e "$file" ] || continue
    SUITE_TOTAL=$((SUITE_TOTAL + 1))
  done
done

# Refuse to certify an empty sweep. If every glob fails to match (wrong cwd, moved
# trees, a typo in a tree path), the counters all stay zero and the old script exited
# 0 - a green that certified nothing actually ran. That was a false-green produced by
# the instrument itself, so a zero-suite run must fail loudly.
if [ "$SUITE_TOTAL" -eq 0 ]; then
  echo "FATAL: no suites matched any of the three trees - refusing to report a green sweep" >&2
  echo "trees: ${TREES[*]}" >&2
  exit 1
fi

{
  if [ "$JSON" = 0 ]; then
    echo "Daily Ledger — complete sweep"
    echo "commit: $(git rev-parse HEAD 2>/dev/null || echo unknown)"
    echo "date:   $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "trees:  tests/daily-ledger/*_test.php, tests/daily_ledger*_test.php, tests/load/daily_ledger_load_test.php"
    echo "suites: $SUITE_TOTAL"
    echo
  fi

  for tree in "${TREES[@]}"; do
    [ "$JSON" = 0 ] && echo "--- ${tree} ---"
    for file in $tree; do
      [ -e "$file" ] || continue
      run_one "$file"
    done
  done

  if [ "$JSON" = 1 ]; then
    ok=false
    if [ "$FAIL_SUITES" -eq 0 ] && [ "$FATAL_SUITES" -eq 0 ] && [ "$NORESULT_SUITES" -eq 0 ]; then ok=true; fi
    printf '{"summary":true,"suites":{"passed":%s,"failed":%s,"fatal":%s,"no_result":%s,"total":%s},"assertions":{"passed":%s,"total":%s},"problems":%s,"ok":%s}\n' \
      "$PASS_SUITES" "$FAIL_SUITES" "$FATAL_SUITES" "$NORESULT_SUITES" "$((PASS_SUITES + FAIL_SUITES + FATAL_SUITES + NORESULT_SUITES))" \
      "$PASS_ASSERT" "$TOTAL_ASSERT" "$(printf '%s' "$PROBLEMS" | sed 's/\\/\\\\/g; s/"/\\"/g' | sed 's/^/\"/; s/$/\"/')" "$ok"
  else
    echo
    echo "====================================================="
    echo "  suites:     passed=$PASS_SUITES failed=$FAIL_SUITES fatal=$FATAL_SUITES no_result=$NORESULT_SUITES"
    echo "              total=$((PASS_SUITES + FAIL_SUITES + FATAL_SUITES + NORESULT_SUITES))"
    echo "  assertions: $PASS_ASSERT / $TOTAL_ASSERT (where a summary was produced)"
    echo "====================================================="
    if [ -n "$PROBLEMS" ]; then
      echo "  PROBLEMS:"
      for problem in $PROBLEMS; do
        echo "    $problem"
      done
    fi
  fi
} > /tmp/daily-ledger-sweep.$$.out

cat /tmp/daily-ledger-sweep.$$.out
if [ -n "$BASELINE" ]; then
  cp /tmp/daily-ledger-sweep.$$.out "$BASELINE"
fi
rm -f /tmp/daily-ledger-sweep.$$.out

if [ "$FAIL_SUITES" -gt 0 ] || [ "$FATAL_SUITES" -gt 0 ] || [ "$NORESULT_SUITES" -gt 0 ]; then
  exit 1
fi
exit 0
